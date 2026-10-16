<?php
/**
 * kc_status='requires_manual_review' のジョブを指定IDでまとめてリトライするCLIスクリプト。
 *
 * kc_review.php の action=retry と同じ分岐ロジック（Stage6のみ失敗ならllm_doneから、
 * 誤答例確定済みならfeedback_pendingから、未確定ならStage3からやり直す）をそのまま再現し、
 * generate_stack_question_kc タスクを再キューする。kc_retry_count はタスク内のリトライ
 * ループ（$attempt）を一切ゲートしないため、常に max_retry 回のフル再試行になる（単なる
 * 履歴表示用カウンタなので +1 するだけでよい）。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/retry_kc_jobs.php --jobids=49,50,52,55,58,59
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['jobids' => '', 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help']) || empty($options['jobids'])) {
    echo "KC版ジョブをまとめてリトライする。\n\n";
    echo "Usage: php retry_kc_jobs.php --jobids=49,50,52,55,58,59\n";
    exit(0);
}

$jobids = array_filter(array_map('trim', explode(',', (string)$options['jobids'])), 'strlen');

foreach ($jobids as $jobid) {
    $jobid = (int)$jobid;
    $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid]);
    if (!$job) {
        echo "job {$jobid}: 見つかりません。スキップします。\n";
        continue;
    }

    $stage6only = !empty($job->kc_xml_result) && !empty($job->kc_error_msg)
        && strpos($job->kc_error_msg, 'KC Stage6') === 0;

    if ($stage6only) {
        $resumestatus = 'llm_done';
    } else if (!empty($job->kc_feedback_confirmed)) {
        $resumestatus = 'feedback_pending';
    } else {
        $resumestatus = '';
    }

    $DB->update_record('qbank_stackgen_jobs', (object)[
        'id'             => $jobid,
        'kc_status'      => $resumestatus,
        'kc_error_msg'   => null,
        'kc_retry_count' => $job->kc_retry_count + 1,
        'timemodified'   => time(),
    ]);

    $task = new \qbank_stackgen\task\generate_stack_question_kc();
    $task->set_custom_data(['jobid' => $jobid]);
    \core\task\manager::queue_adhoc_task($task, true);

    echo "job {$jobid}: kc_status -> '{$resumestatus}' でリトライキューに投入しました。\n";
}
