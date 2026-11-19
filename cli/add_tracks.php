<?php
/**
 * アップロード時に選び忘れた版（RAGなし版・KC版）を、既存ジョブに後から追加する。
 *
 * 画像を入れ直さずに済む（OCR・RAG検索の結果をそのまま使い、同じ画像・同じジョブIDで3版を比べられる）。
 * ジョブの track_norag / track_kc を立て、続きのタスクをキューする。OCR（ocr_result）が済んでいない
 * ジョブは飛ばす。KC版は従来版のRAG検索結果（rag_examples_used）を読むため、それが無いジョブも飛ばす。
 * すでにその版が始まっているジョブも飛ばす（何度実行してもよい）。
 *
 * 追加した時刻は events に 'track_added' として残し、cli/event_report.php はその時刻を
 * 「キューに入った時刻」として待ち時間を数える（アップロードからの経過を待ち時間に入れない）。
 *
 * 実行例:
 *   docker exec stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/add_tracks.php --from=123 --to=140 --tracks=norag,kc --dry-run
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['jobs' => '', 'from' => 0, 'to' => 0, 'tracks' => 'norag,kc', 'dry-run' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help']) || ($options['jobs'] === '' && empty($options['from']))) {
    echo "既存ジョブに、RAGなし版・KC版を後から追加する。\n\n";
    echo "Usage: php add_tracks.php (--jobs=123,124 | --from=123 [--to=140]) [--tracks=norag,kc] [--dry-run]\n";
    exit(empty($options['help']) ? 1 : 0);
}

// [版 => [track列, status列, タスククラス]]
$systems = [
    'norag' => ['track_norag', 'norag_status', \qbank_stackgen\task\generate_stack_question_norag::class],
    'kc'    => ['track_kc', 'kc_status', \qbank_stackgen\task\generate_stack_question_kc::class],
];
$tracks = array_values(array_filter(array_map('trim', explode(',', $options['tracks']))));
$unknown = array_diff($tracks, array_keys($systems));
if ($unknown) {
    cli_error('--tracks は norag / kc のみ指定できます: ' . implode(', ', $unknown));
}

if ($options['jobs'] !== '') {
    [$sql, $params] = $DB->get_in_or_equal(array_map('intval', explode(',', $options['jobs'])), SQL_PARAMS_NAMED);
    $select = "id $sql";
} else {
    $select = 'id >= :from' . (!empty($options['to']) ? ' AND id <= :to' : '');
    $params = ['from' => (int)$options['from']] + (!empty($options['to']) ? ['to' => (int)$options['to']] : []);
}
$jobs = $DB->get_records_select('qbank_stackgen_jobs', $select, $params, 'id');

$dryrun = !empty($options['dry-run']);
$count = array_fill_keys($tracks, 0);
foreach ($jobs as $job) {
    if (empty($job->ocr_result)) {
        echo "  [skip] job #{$job->id}: OCRが済んでいません。\n";
        continue;
    }
    foreach ($tracks as $track) {
        [$trackcol, $statuscol, $taskclass] = $systems[$track];
        if (!empty($job->$trackcol) || ($job->$statuscol !== '' && $job->$statuscol !== null)) {
            continue; // すでに選ばれている、または始まっている
        }
        if ($track === 'kc' && empty($job->rag_examples_used)) {
            echo "  [skip] job #{$job->id} kc: 従来版のRAG検索結果がまだありません。\n";
            continue;
        }
        echo '  [' . ($dryrun ? '対象' : '追加') . "] job #{$job->id} {$track} {$job->sourcefilename}\n";
        $count[$track]++;
        if ($dryrun) {
            continue;
        }
        \qbank_stackgen\local\event_log::update_job((object)[
            'id'         => $job->id,
            $trackcol    => 1,
            'timemodified' => time(),
        ]);
        \qbank_stackgen\local\event_log::log($job->id, $track, 'track_added');
        $task = new $taskclass();
        $task->set_custom_data(['jobid' => $job->id]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}

echo ($dryrun ? '対象（未追加のまま）: ' : '追加してキューしました: ')
    . implode(' / ', array_map(function ($t) use ($count) {
        return "{$t}={$count[$t]}";
    }, $tracks)) . "\n";
