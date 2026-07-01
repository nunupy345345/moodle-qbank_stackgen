<?php
/**
 * Image upload page for qbank_stackgen.
 *
 * Accepts a base64-encoded image (preprocessed by upload.js via Canvas API),
 * stores it in the DB, and queues an Adhoc Task.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

$PAGE->set_url(new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('upload_page_title', 'qbank_stackgen'));
$PAGE->set_heading($course->fullname);

// Handle POST submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $image_b64 = optional_param('image_b64', '', PARAM_RAW);

    if (empty($image_b64)) {
        redirect(
            new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
            get_string('upload_error_nofile', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Insert job record.
    $now   = time();
    $jobid = $DB->insert_record('qbank_stackgen_jobs', (object)[
        'courseid'     => $courseid,
        'userid'       => $USER->id,
        'status'       => 'pending',
        'image_b64'    => $image_b64,
        'retry_count'  => 0,
        'timecreated'  => $now,
        'timemodified' => $now,
    ]);

    // Queue Adhoc Task.
    $task = new \qbank_stackgen\task\generate_stack_question();
    $task->set_custom_data(['jobid' => $jobid]);
    \core\task\manager::queue_adhoc_task($task, true);

    redirect(
        new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
        get_string('upload_success', 'qbank_stackgen'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Render upload form.
$PAGE->requires->js_call_amd('qbank_stackgen/upload', 'init', ['stackgen-upload-form']);

echo $OUTPUT->header();

// Fetch recent jobs for this user in this course.
$statusclasses = [
    'pending'                => 'secondary',
    'ocr_done'               => 'info',
    'llm_done'               => 'primary',
    'validated'              => 'success',
    'review_pending'         => 'warning',
    'approved'               => 'success',
    'rejected'               => 'danger',
    'validation_failed'      => 'danger',
    'requires_manual_review' => 'danger',
];
$statuslabels = [
    'pending'                => get_string('status_pending', 'qbank_stackgen'),
    'ocr_done'               => get_string('status_ocr_done', 'qbank_stackgen'),
    'llm_done'               => get_string('status_llm_done', 'qbank_stackgen'),
    'validated'              => get_string('status_validated', 'qbank_stackgen'),
    'review_pending'         => get_string('status_review_pending', 'qbank_stackgen'),
    'approved'               => get_string('status_approved', 'qbank_stackgen'),
    'rejected'               => get_string('status_rejected', 'qbank_stackgen'),
    'validation_failed'      => get_string('status_validation_failed', 'qbank_stackgen'),
    'requires_manual_review' => get_string('status_requires_manual_review', 'qbank_stackgen'),
];

$dbjobs = $DB->get_records_select(
    'qbank_stackgen_jobs',
    'courseid = ? AND userid = ?',
    [$courseid, $USER->id],
    'id DESC',
    'id, status, ocr_result, xml_result, error_msg, timecreated',
    0, 10
);

$jobs = [];
foreach ($dbjobs as $job) {
    $problems = [];
    if (!empty($job->ocr_result)) {
        $ocr = json_decode($job->ocr_result, true);
        // 新形式: {"problems": [...]}、旧形式（単一問題）の後方互換も維持
        $rawproblems = isset($ocr['problems']) ? $ocr['problems'] : [$ocr];
        foreach ($rawproblems as $p) {
            if (empty($p['problem_expression'])) {
                continue;
            }
            $problems[] = [
                'question_text'      => $p['question_text'] ?? '',
                'question_type'      => $p['question_type'] ?? '',
                'problem_expression' => $p['problem_expression'] ?? '',
                'answer_expression'  => $p['answer_expression'] ?? null,
                'has_answer'         => !empty($p['answer_expression']),
            ];
        }
    }
    // XML 結果をパースして問題名一覧を取得
    $xml_questions = [];
    if (!empty($job->xml_result)) {
        $dom = new DOMDocument();
        if (@$dom->loadXML($job->xml_result)) {
            foreach ($dom->getElementsByTagName('question') as $q) {
                $nameNodes = $q->getElementsByTagName('name');
                $name = '';
                if ($nameNodes->length > 0) {
                    $textNodes = $nameNodes->item(0)->getElementsByTagName('text');
                    if ($textNodes->length > 0) {
                        $name = $textNodes->item(0)->textContent;
                    }
                }
                $xml_questions[] = ['qname' => $name ?: '（名前なし）'];
            }
        }
    }

    $jobs[] = [
        'id'            => $job->id,
        'status'        => $job->status,
        'statuslabel'   => $statuslabels[$job->status] ?? $job->status,
        'statusclass'   => $statusclasses[$job->status] ?? 'secondary',
        'timecreated'   => userdate($job->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        'hasocr'        => !empty($problems),
        'problems'      => $problems,
        'hasxml'        => !empty($xml_questions),
        'xml_questions' => $xml_questions,
        'xml_count'     => count($xml_questions),
        'xml_raw'       => $job->xml_result ?? '',
        'haserror'      => !empty($job->error_msg),
        'error_msg'     => $job->error_msg ?? '',
    ];
}

// Trigger MathJax re-typeset after DOM is ready.
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
    'actionurl' => (new moodle_url('/question/bank/stackgen/upload.php'))->out(false),
    'sesskey'   => sesskey(),
    'courseid'  => $courseid,
    'formid'    => 'stackgen-upload-form',
    'jobs'      => $jobs,
    'hasjobs'   => !empty($jobs),
];

echo $OUTPUT->render_from_template('qbank_stackgen/upload_form', $templatecontext);

echo $OUTPUT->footer();
