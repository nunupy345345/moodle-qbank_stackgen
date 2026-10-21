<?php
/**
 * アンケート用: 人間版PRT（同梱コーパスの良問）を正解として、同じ問題をLLM版・RAG版・KC版で
 * 生成するためのジョブを、OCRを飛ばして登録するCLIスクリプト。
 *
 * data/survey/items.json の各問題（人間版問題を OCR 結果相当の形に起こしたもの）から
 * `ocr_done` 状態のジョブを作り、`rag_exclude` にその問題自身の sourcefile を設定する
 * （leave-one-out: 人間版そのものがRAGで参照されて生成結果に写り込むのを防ぐ）。
 * 続けて、選んだトラックのAdhoc Taskをキューする（KC版・RAGなし版はOCR完了直後にしか
 * 自動起動しないため、ここで明示的にキューする）。
 *
 * 誤答例の教員確定はこのスクリプトでは行わない。Stage 3 完了後に cli/survey_confirm.php で
 * AI提案をそのまま確定する。
 *
 * べき等: 同一コース×同一 sourcefile は再登録しない（sourcefilename列で判定）。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/survey_seed.php --courseid=2
 *   ... --courseid=2 --only=19,21        # 一部のみ（ファイル名の先頭番号）
 *   ... --courseid=2 --dry-run
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['courseid' => 0, 'userid' => 0, 'only' => '', 'tracks' => 'traditional,kc,norag', 'dry-run' => false, 'force' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help']) || empty($options['courseid'])) {
    echo "アンケート用の問題をOCRを飛ばしてジョブ登録し、leave-one-outのRAG除外を設定する。\n\n";
    echo "Usage: php survey_seed.php --courseid=N [--userid=N] [--only=01,19] [--tracks=traditional,kc,norag] [--dry-run] [--force]\n";
    echo "  --courseid=N  登録先のコースID（必須）\n";
    echo "  --userid=N    ジョブの登録者（省略時はサイト管理者）\n";
    echo "  --only=A,B    ファイル名先頭の番号で対象を絞る（例: 19,21）\n";
    echo "  --tracks=...  生成する版（既定: traditional,kc,norag の全部）\n";
    echo "  --dry-run     DBに書き込まず、登録予定だけ表示する\n";
    echo "  --force       登録済みの問題でも別ジョブとして再登録する（検索ロジックを変えた後のやり直し用）\n";
    exit(empty($options['help']) ? 1 : 0);
}

$courseid = (int)$options['courseid'];
$userid   = !empty($options['userid']) ? (int)$options['userid'] : get_admin()->id;
$dryrun   = !empty($options['dry-run']);
$tracks   = array_map('trim', explode(',', (string)$options['tracks']));
$only     = array_filter(array_map('trim', explode(',', (string)$options['only'])));

$DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
if (!$DB->record_exists('user', ['id' => $userid])) {
    cli_error("指定されたuserid={$userid} が見つかりません。");
}
foreach ($tracks as $t) {
    if (!in_array($t, ['traditional', 'kc', 'norag'], true)) {
        cli_error("--tracks に不明な値があります: {$t}");
    }
}
if (!in_array('traditional', $tracks, true)) {
    cli_error('traditional は必須です（KC版・RAGなし版も従来版のOCR結果とRAG検索結果を前提にします）。');
}

$itemsfile = __DIR__ . '/../data/survey/items.json';
$data = json_decode((string)file_get_contents($itemsfile), true);
if (empty($data['items'])) {
    cli_error("アンケート問題定義を読み込めません: {$itemsfile}");
}

$queued = 0;
$skipped = 0;
foreach ($data['items'] as $item) {
    $file = $item['sourcefile'];
    $num  = substr($file, 0, strpos($file, '_'));
    if ($only && !in_array($num, $only, true)) {
        continue;
    }

    if (empty($options['force'])
            && $DB->record_exists('qbank_stackgen_jobs', ['courseid' => $courseid, 'sourcefilename' => $file])) {
        echo "  [skip] {$file}（登録済み）\n";
        $skipped++;
        continue;
    }

    $ocr = ['problems' => [[
        'question_text'        => $item['question_text'],
        'stack_question_text'  => $item['question_text'],
        'question_type'        => $item['question_type'],
        'expected_answer_kind' => 'expression',
        'variables'            => ['x'],
        'problem_expression'   => $item['problem_expression'],
        'answer_expression'    => null,
    ]]];

    echo "  [" . ($dryrun ? 'dry-run' : 'queued') . "] {$file}（{$item['technique']}）\n";
    if ($dryrun) {
        continue;
    }

    $now = time();
    $jobid = $DB->insert_record('qbank_stackgen_jobs', (object)[
        'courseid'          => $courseid,
        'userid'            => $userid,
        'status'            => 'ocr_done',
        'ocr_result'        => json_encode($ocr, JSON_UNESCAPED_UNICODE),
        'rag_exclude'       => json_encode([$file], JSON_UNESCAPED_UNICODE),
        'sourcefilename'    => $file,
        'track_traditional' => 1,
        'track_kc'          => (int)in_array('kc', $tracks, true),
        'track_norag'       => (int)in_array('norag', $tracks, true),
        'retry_count'       => 0,
        'timecreated'       => $now,
        'timemodified'      => $now,
    ]);

    // 従来版（RAG検索を行い rag_examples_used に保存。KC版はこの結果を再利用する）。
    $task = new \qbank_stackgen\task\generate_stack_question();
    $task->set_custom_data(['jobid' => $jobid]);
    \core\task\manager::queue_adhoc_task($task, true);

    // RAGなし版は検索結果を見ないので、従来版と並行してキューしてよい。
    if (in_array('norag', $tracks, true)) {
        $task = new \qbank_stackgen\task\generate_stack_question_norag();
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
    // KC版は従来版のRAG検索結果を読むため、ここではキューしない。
    // survey_confirm.php が従来版のStage 3完了を確認した後でキューする。
    $queued++;
}

echo "完了: {$queued}件登録, {$skipped}件スキップ。\n";
