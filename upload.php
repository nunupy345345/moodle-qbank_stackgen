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

$templatecontext = [
    'actionurl' => (new moodle_url('/question/bank/stackgen/upload.php'))->out(false),
    'sesskey'   => sesskey(),
    'courseid'  => $courseid,
    'formid'    => 'stackgen-upload-form',
];

echo $OUTPUT->render_from_template('qbank_stackgen/upload_form', $templatecontext);

echo $OUTPUT->footer();
