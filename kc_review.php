<?php
/**
 * KC診断版（別ページ、実験的）のジョブ一覧・レビュー画面。
 *
 * 既存の upload.php / review.php とは完全に独立した系統。同じ qbank_stackgen_jobs の
 * ocr_result（既にOCR済みのもの）を読み取り専用で再利用するが、kc_*列だけを読み書きし、
 * 既存の status/feedback_result/feedback_confirmed/xml_result 等には一切触れない。
 */

require_once(__DIR__ . '/../../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

$PAGE->set_url(new moodle_url('/question/bank/stackgen/kc_review.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('kc_page_title', 'qbank_stackgen'));
$PAGE->set_heading($course->fullname);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = required_param('action', PARAM_ALPHANUMEXT);
    $jobid  = required_param('jobid', PARAM_INT);

    $job = $DB->get_record('qbank_stackgen_jobs',
        ['id' => $jobid, 'courseid' => $courseid, 'userid' => $USER->id], '*', MUST_EXIST);

    if ($action === 'start') {
        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'           => $jobid,
            'kc_status'    => '',
            'kc_error_msg' => null,
            'timemodified' => time(),
        ]);

        $task = new \qbank_stackgen\task\generate_stack_question_kc();
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task, true);

        redirect(
            new moodle_url('/question/bank/stackgen/kc_review.php', ['courseid' => $courseid]),
            get_string('kc_start_success', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'retry') {
        // Stage6だけが失敗した場合はllm_doneから（Stage6のみ再実行）。それ以外で
        // kc_feedback_confirmedがあればfeedback_pendingから（Stage4のXML生成からやり直す）。
        // 未確定ならkc_statusを空に戻し、Stage3（KC起点誤答例生成）からやり直す。
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

        redirect(
            new moodle_url('/question/bank/stackgen/kc_review.php', ['courseid' => $courseid]),
            get_string('kc_retry_success', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$PAGE->requires->js_call_amd('qbank_stackgen/kc_job_review', 'init');
$PAGE->requires->js_call_amd('qbank_stackgen/kc_status_poll', 'init', [$courseid]);

echo $OUTPUT->header();

// 問題バンク共通ツールバー（問題/エクスポート/インポート/カテゴリ/画像から生成）。
$qbankrenderer = $PAGE->get_renderer('core_question', 'bank');
$qbankaction    = new \core_question\output\qbank_action_menu($PAGE->url);
echo $qbankrenderer->render($qbankaction);

// 上のツールバーには「画像から生成」はあるが、このKC診断版ページ自体は
// Moodle側の制約（1プラグイン1項目まで）で載せられないため、簡単なリンクを添える。
echo \html_writer::div(
    \html_writer::link(
        new \moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
        get_string('generate_from_image', 'qbank_stackgen')
    ),
    'mb-3'
);

// OCR済み（ocr_resultがある）ジョブのみ対象。最新20件まで。
$dbjobs = $DB->get_records_select(
    'qbank_stackgen_jobs',
    "courseid = ? AND userid = ? AND ocr_result IS NOT NULL AND ocr_result <> ''",
    [$courseid, $USER->id],
    'id DESC',
    'id, courseid, ocr_result, track_kc, kc_status, kc_feedback_result, kc_feedback_confirmed, ' .
        'kc_xml_result, kc_error_msg, kc_retry_count, kc_question_category_id',
    0, 20
);

$sesskey = sesskey();
$jobs = [];
foreach ($dbjobs as $job) {
    $jobs[] = \qbank_stackgen\local\kc_job_view::build($job, $courseid, $sesskey);
}

if (!empty($jobs)) {
    $PAGE->requires->js_amd_inline('
require(["core/first"], function() {
    document.addEventListener("DOMContentLoaded", function() {
        if (window.MathJax && MathJax.typesetPromise) {
            MathJax.typesetPromise();
        }
    });
    if (window.MathJax && MathJax.typesetPromise) {
        MathJax.typesetPromise();
    }
});
');
}

$templatecontext = [
    'courseid' => $courseid,
    'jobs'     => $jobs,
    'hasjobs'  => !empty($jobs),
];

echo $OUTPUT->render_from_template('qbank_stackgen/kc_job_list', $templatecontext);

echo $OUTPUT->footer();
