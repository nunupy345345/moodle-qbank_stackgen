<?php
// This file is part of Moodle - http://moodle.org/

/**
 * KC版だけを、検証条件を変えて「別ジョブ」として再生成するためのCLI。
 *
 * 元のジョブ（ocr_done 以降が済んでいるもの）から、OCR結果・RAG検索結果・教員確定済みのKC誤答例を
 * 新しいジョブにコピーし、KC版のStage 4（XML生成→CAS検証→登録）から始める。誤答例は元のジョブと同じなので、
 * 「検証条件を緩めた効果」だけを比べられる。元のジョブには書き込まない。
 *
 * 新しいジョブは sourcefilename に「#kc-relax-<profile>」を付けて、元のジョブと区別する。従来版・RAGなし版は動かさない
 * （track_traditional=0, track_norag=0）。使う検証条件は、タスクのカスタムデータ（kc_rules）としてジョブごとに渡すので、
 * プラグイン全体の設定や、同時に動く他のKCジョブには影響しない。
 *
 * 実行例:
 *   docker exec stack-moodle-moodle-1 php /var/www/html/question/bank/stackgen/cli/clone_kc_jobs.php \
 *     --jobs=125,126 --profile=D --rules=c1=warn,c3=warn,c6=warn --dry-run
 *
 * @package    qbank_stackgen
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['jobs' => '', 'from' => 0, 'to' => 0, 'profile' => '', 'rules' => '', 'source-status' => 'requires_manual_review',
     'dry-run' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help']) || $options['profile'] === '' || $options['rules'] === ''
        || ($options['jobs'] === '' && empty($options['from']))) {
    echo "KC版だけを、検証条件を変えて別ジョブとして再生成する（元のジョブは変更しない）。\n\n";
    echo "Usage: php clone_kc_jobs.php (--jobs=125,126 | --from=123 [--to=140]) --profile=D --rules=c1=warn,c3=warn,c6=warn\n";
    echo "         [--source-status=requires_manual_review|approved|any] [--dry-run]\n\n";
    echo "  --profile  実験条件の名前（sourcefilename に #kc-relax-<profile> を付ける）\n";
    echo "  --rules    条件ごとの扱い。c1=旧①, c3=旧③, c6=旧⑥ を error / warn / ignore から選ぶ\n";
    echo "  --source-status  複製元にするジョブのKC版の状態（既定: requires_manual_review。any で全部）\n";
    exit(empty($options['help']) ? 1 : 0);
}

$profile = trim($options['profile']);
if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $profile)) {
    cli_error('--profile は英数字・_・- の20文字以内にしてください。');
}

$rules = [];
foreach (array_filter(array_map('trim', explode(',', $options['rules']))) as $pair) {
    [$name, $mode] = array_pad(explode('=', $pair, 2), 2, '');
    if (!in_array($name, ['c1', 'c3', 'c6'], true) || !in_array($mode, ['error', 'warn', 'ignore'], true)) {
        cli_error("--rules の指定が正しくありません: {$pair}（c1/c3/c6 = error/warn/ignore）");
    }
    $rules[$name] = $mode;
}
if (!$rules) {
    cli_error('--rules が空です。');
}

if ($options['jobs'] !== '') {
    [$sql, $params] = $DB->get_in_or_equal(array_map('intval', explode(',', $options['jobs'])), SQL_PARAMS_NAMED);
    $select = "id $sql";
} else {
    $select = 'id >= :from' . (!empty($options['to']) ? ' AND id <= :to' : '');
    $params = ['from' => (int)$options['from']] + (!empty($options['to']) ? ['to' => (int)$options['to']] : []);
}
$sources = $DB->get_records_select('qbank_stackgen_jobs', $select, $params, 'id');

$dryrun = !empty($options['dry-run']);
$suffix = ' #kc-relax-' . $profile;
$made = 0;
foreach ($sources as $src) {
    $label = "job #{$src->id} {$src->sourcefilename}";

    // 複製したジョブそのものを、さらに複製しない。
    if (strpos((string)$src->sourcefilename, '#kc-relax-') !== false) {
        echo "  [skip] {$label}: 再検証用に作ったジョブです。\n";
        continue;
    }
    if ($options['source-status'] !== 'any' && $src->kc_status !== $options['source-status']) {
        echo "  [skip] {$label}: kc_status={$src->kc_status}\n";
        continue;
    }
    if (empty($src->ocr_result) || empty($src->kc_feedback_confirmed) || empty($src->rag_examples_used)) {
        echo "  [skip] {$label}: OCR結果・RAG検索結果・確定済みKC誤答例のどれかがありません。\n";
        continue;
    }
    $newname = \core_text::substr((string)$src->sourcefilename, 0, 255 - strlen($suffix)) . $suffix;
    if ($DB->record_exists('qbank_stackgen_jobs', ['courseid' => $src->courseid, 'sourcefilename' => $newname])) {
        echo "  [skip] {$label}: すでに複製済みです（{$newname}）。\n";
        continue;
    }

    echo '  [' . ($dryrun ? '対象' : '作成') . "] {$label} → {$newname}\n";
    $made++;
    if ($dryrun) {
        continue;
    }

    $now = time();
    $newid = $DB->insert_record('qbank_stackgen_jobs', (object)[
        'courseid'              => $src->courseid,
        'userid'                => $src->userid,
        'status'                => 'ocr_done',
        'ocr_result'            => $src->ocr_result,
        'rag_examples_used'     => $src->rag_examples_used,
        'rag_exclude'           => $src->rag_exclude,
        'sourcefilename'        => $newname,
        'track_traditional'     => 0,
        'track_kc'              => 1,
        'track_norag'           => 0,
        'retry_count'           => 0,
        // 元のジョブと同じ、教員確定済みのKC誤答例から始める（Stage 3は飛ばす）。
        'kc_status'             => 'feedback_pending',
        'kc_feedback_result'    => $src->kc_feedback_result,
        'kc_feedback_confirmed' => $src->kc_feedback_confirmed,
        'kc_retry_count'        => 0,
        'timecreated'           => $now,
        'timemodified'          => $now,
    ]);
    \qbank_stackgen\local\event_log::log((int)$newid, '', 'created', $newname);
    \qbank_stackgen\local\event_log::log((int)$newid, 'kc', 'kc_clone', 'src=' . $src->id . ' profile=' . $profile);

    $task = new \qbank_stackgen\task\generate_stack_question_kc();
    $task->set_custom_data(['jobid' => $newid, 'kc_profile' => $profile, 'kc_rules' => $rules]);
    \core\task\manager::queue_adhoc_task($task, true);
}

echo ($dryrun ? '対象（未作成のまま）: ' : '作成してキューしました: ') . "{$made} 件（profile={$profile} "
    . json_encode($rules) . "）\n";
