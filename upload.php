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

    $action = optional_param('action', 'upload', PARAM_ALPHA);

    if ($action === 'retry') {
        $jobid = required_param('jobid', PARAM_INT);
        $job = $DB->get_record('qbank_stackgen_jobs',
            ['id' => $jobid, 'courseid' => $courseid, 'userid' => $USER->id], '*', MUST_EXIST);

        // xml_result があれば llm_done から（Stage6の問題バンク登録だけ再実行）、
        // feedback_confirmed があれば feedback_pending から（Stage4のみ再実行）、
        // ocr_result のみあれば ocr_done から（Stage3からやり直し）、
        // どちらもなければ pending から（OCRごとやり直し）。
        if (!empty($job->xml_result)) {
            $resumestatus = 'llm_done';
        } else if (!empty($job->feedback_confirmed)) {
            $resumestatus = 'feedback_pending';
        } else if (!empty($job->ocr_result)) {
            $resumestatus = 'ocr_done';
        } else {
            $resumestatus = 'pending';
        }
        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'           => $jobid,
            'status'       => $resumestatus,
            'error_msg'    => null,
            'retry_count'  => $job->retry_count + 1,
            'timemodified' => time(),
        ]);

        $task = new \qbank_stackgen\task\generate_stack_question();
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task, true);

        redirect(
            new moodle_url('/question/bank/stackgen/upload.php', ['courseid' => $courseid]),
            get_string('retry_success', 'qbank_stackgen'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

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
// ページ構成: 1ページ目（page=0）は最新1件のみ、2ページ目以降（page>=1）は残りを10件ずつページングする。
// そのため件数がページによって違い、Moodle標準の paging_bar（全ページ同じperpage前提）はそのまま使えない。
$page    = optional_param('page', 0, PARAM_INT);
$perpage = 10;

$PAGE->requires->js_call_amd('qbank_stackgen/upload', 'init', ['stackgen-upload-form']);
$PAGE->requires->js_call_amd('qbank_stackgen/job_review', 'init');
// ポーリングは1ページ目（最新1件）だけが対象。過去ページの古いジョブはもう状態が変わらないので
// ポーリングさせない（get_job_statuses も常に最新1件しか返さないため、2ページ目以降で回しても無意味）。
if ($page === 0) {
    $PAGE->requires->js_call_amd('qbank_stackgen/status_poll', 'init', [$courseid]);
}

echo $OUTPUT->header();

// Fetch jobs for this user in this course, newest first, paged.
$totalcount = $DB->count_records_select(
    'qbank_stackgen_jobs',
    'courseid = ? AND userid = ?',
    [$courseid, $USER->id]
);

if ($page <= 0) {
    $limitfrom = 0;
    $limitnum  = 1;
} else {
    $limitfrom = 1 + ($page - 1) * $perpage;
    $limitnum  = $perpage;
}

$dbjobs = $DB->get_records_select(
    'qbank_stackgen_jobs',
    'courseid = ? AND userid = ?',
    [$courseid, $USER->id],
    'id DESC',
    'id, status, ocr_result, feedback_result, feedback_confirmed, xml_result, error_msg, ' .
        'question_category_id, retry_count, timecreated',
    $limitfrom, $limitnum
);

$sesskey = sesskey();
$jobs = [];
foreach ($dbjobs as $job) {
    $jobs[] = \qbank_stackgen\local\job_view::build($job, $courseid, $sesskey);
}

// 1ページ目=1件、2ページ目以降=10件という不均等な構成なので、独自の簡易ページャーを組み立てる
// （$OUTPUT->paging_bar は全ページ同じperpage前提のため、この構成には使えない）。
$remaining  = max(0, $totalcount - 1);
$extrapages = (int)ceil($remaining / $perpage);
$totalpages = 1 + $extrapages;

$pagingbar = '';
if ($totalpages > 1) {
    $links = [];
    for ($p = 0; $p < $totalpages; $p++) {
        $label = (string)($p + 1);
        if ($p === $page) {
            $links[] = html_writer::span($label, 'font-weight-bold px-2');
        } else {
            $links[] = html_writer::link(
                new moodle_url($PAGE->url, ['page' => $p]), $label, ['class' => 'px-2']
            );
        }
    }
    $pagingbar = html_writer::tag(
        'nav', implode(' ', $links), ['class' => 'stackgen-paging mt-2', 'aria-label' => 'job list paging']
    );
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
    'pagingbar' => $pagingbar,
];

echo $OUTPUT->render_from_template('qbank_stackgen/upload_form', $templatecontext);

echo $OUTPUT->footer();
