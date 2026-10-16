<?php
/**
 * フォルダ内の教科書画像をまとめて qbank_stackgen_jobs に登録し、Adhoc Taskをキューに積むCLIスクリプト。
 *
 * upload.php がブラウザ経由で1枚ずつやっていること（ジョブレコード作成＋Adhoc Task登録）を、
 * フォルダ内の画像に対してまとめて行う。OCR以降の実処理（Stage 2〜6）はこのスクリプトでは行わず、
 * 登録したジョブはMoodle標準のAdhoc Task/cronが1件ずつ順番に処理する（新たな順次処理の仕組みは
 * 作り込まない）。Stage 3（誤答例・フィードバック確認）は従来通り教員のレビューが必須のまま。
 *
 * べき等: 同一コース×同一ファイル名は再投入しない（sourcefilenameで重複チェック）。処理済み
 * ファイルの移動は行わない（重複チェックのみに任せる）。
 *
 * レート制限・生成失敗時のリトライ間隔調整は行わない。既存の`max_retry`・
 * `requires_manual_review`フォールバックにそのまま任せる。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/bulk_import.php --courseid=2 --dir=/path/to/images
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['courseid' => 0, 'dir' => '', 'userid' => 0, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help']) || empty($options['courseid']) || empty($options['dir'])) {
    echo "フォルダ内の教科書画像をまとめてジョブ登録し、Adhoc Taskをキューに積む。\n\n";
    echo "Usage: php bulk_import.php --courseid=N --dir=PATH [--userid=N]\n";
    echo "  --courseid=N  登録先のコースID（必須）\n";
    echo "  --dir=PATH    画像ファイル（jpg/jpeg/png/webp）を置いたディレクトリ（必須）\n";
    echo "  --userid=N    ジョブの登録者として記録するユーザーID（省略時はサイト管理者）\n";
    exit(empty($options['help']) ? 1 : 0);
}

if (!function_exists('imagejpeg')) {
    cli_error('PHPのGD拡張が有効になっていません（PNG/WebPをJPEGへ正規化するために必要です）。');
}

$courseid = (int)$options['courseid'];
$dir      = rtrim((string)$options['dir'], '/');
$userid   = !empty($options['userid']) ? (int)$options['userid'] : get_admin()->id;

$DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
if (!$DB->record_exists('user', ['id' => $userid])) {
    cli_error("指定されたuserid={$userid} が見つかりません。");
}

if (!is_dir($dir)) {
    cli_error("ディレクトリが見つかりません: {$dir}");
}

/**
 * upload.js のStage 1前処理（Canvas APIによるJPEG再エンコード、品質0.85）と揃うように、
 * 入力画像の拡張子に関わらずJPEGに正規化してbase64を返す。
 * gemini_ocr.phpはリクエストのmime_typeを常に"image/jpeg"固定で送るため、これを揃えておかないと
 * PNG/WebPの生バイトを"image/jpeg"と偽って送ることになりOCRが壊れる。
 *
 * @return string|null 成功時はbase64文字列、読み込み・変換に失敗した場合はnull
 */
function qbank_stackgen_normalize_to_jpeg_b64(string $path): ?string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $image = @imagecreatefromjpeg($path);
            break;
        case 'png':
            $image = @imagecreatefrompng($path);
            break;
        case 'webp':
            $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
            break;
        default:
            return null;
    }
    if (empty($image)) {
        return null;
    }

    ob_start();
    imagejpeg($image, null, 85);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes !== false ? base64_encode($bytes) : null;
}

$files = array_merge(
    glob($dir . '/*.jpg')  ?: [],
    glob($dir . '/*.jpeg') ?: [],
    glob($dir . '/*.png')  ?: [],
    glob($dir . '/*.webp') ?: []
);
sort($files);

if (empty($files)) {
    cli_error("画像ファイル（jpg/jpeg/png/webp）が見つかりません: {$dir}");
}

echo count($files) . " ファイルを処理します（ソース: {$dir}）。\n";

$queued  = 0;
$skipped = 0;
$failed  = 0;

foreach ($files as $path) {
    $filename = basename($path);

    if ($DB->record_exists('qbank_stackgen_jobs', ['courseid' => $courseid, 'sourcefilename' => $filename])) {
        echo "  [skip] {$filename}（このコースで登録済み）\n";
        $skipped++;
        continue;
    }

    $image_b64 = qbank_stackgen_normalize_to_jpeg_b64($path);
    if ($image_b64 === null) {
        echo "  [error] {$filename}: 画像の読み込み/JPEG変換に失敗しました。\n";
        $failed++;
        continue;
    }

    $now = time();
    $jobid = $DB->insert_record('qbank_stackgen_jobs', (object)[
        'courseid'       => $courseid,
        'userid'         => $userid,
        'status'         => 'pending',
        'image_b64'      => $image_b64,
        'sourcefilename' => $filename,
        'retry_count'    => 0,
        'timecreated'    => $now,
        'timemodified'   => $now,
    ]);

    $task = new \qbank_stackgen\task\generate_stack_question();
    $task->set_custom_data(['jobid' => $jobid]);
    \core\task\manager::queue_adhoc_task($task, true);

    echo "  [queued] {$filename} -> job #{$jobid}\n";
    $queued++;
}

echo "完了: {$queued}件キュー登録, {$skipped}件スキップ, {$failed}件失敗。"
    . " cronが順次処理します（動いていなければ手動実行してください）。\n";
