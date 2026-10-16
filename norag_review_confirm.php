<?php
/**
 * RAGなし比較トラック Stage3 review confirmation endpoint for qbank_stackgen (AJAX only).
 *
 * 教員が upload.php のジョブ一覧上でインライン編集した「RAGなし版」の誤答例/フィードバックを
 * 確定する。確定すると別系統のAdhoc Task（generate_stack_question_norag、Stage4〜6）が
 * 再キューされる。既存の review.php（従来版用）とは完全に独立しており、norag_*列のみを更新する。
 */

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json; charset=utf-8');

function stackgen_norag_review_fail(string $message, int $httpcode = 400): void {
    http_response_code($httpcode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stackgen_norag_review_fail('POST only', 405);
}

$courseid = required_param('courseid', PARAM_INT);
$jobid    = required_param('jobid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

try {
    require_sesskey();
} catch (\moodle_exception $e) {
    stackgen_norag_review_fail(get_string('error_no_capability', 'qbank_stackgen'), 403);
}

$job = $DB->get_record('qbank_stackgen_jobs',
    ['id' => $jobid, 'courseid' => $courseid, 'userid' => $USER->id], '*', MUST_EXIST);

if ($job->norag_status !== 'feedback_pending') {
    stackgen_norag_review_fail(get_string('review_error_wrongstatus', 'qbank_stackgen'));
}

$feedbackdata = required_param('feedbackdata', PARAM_RAW);
$decoded = json_decode($feedbackdata, true);

if (!is_array($decoded) || !isset($decoded['problems']) || !is_array($decoded['problems'])) {
    stackgen_norag_review_fail(get_string('review_error_invalid', 'qbank_stackgen'));
}

$confirmed = ['problems' => []];
foreach ($decoded['problems'] as $p) {
    $name         = trim((string)($p['name'] ?? ''));
    $questiontext = trim((string)($p['questiontext'] ?? ''));

    $wronganswers = [];
    foreach ((array)($p['wronganswers'] ?? []) as $w) {
        $expression = trim((string)($w['expression'] ?? ''));
        if ($expression === '') {
            continue;
        }
        $wronganswers[] = [
            'expression'    => $expression,
            'misconception' => trim((string)($w['misconception'] ?? '')),
            'feedback'      => trim((string)($w['feedback'] ?? '')),
        ];
    }
    $confirmed['problems'][] = ['name' => $name, 'questiontext' => $questiontext, 'wronganswers' => $wronganswers];
}

$DB->update_record('qbank_stackgen_jobs', (object)[
    'id'                      => $jobid,
    'norag_feedback_confirmed' => json_encode($confirmed, JSON_UNESCAPED_UNICODE),
    'timemodified'            => time(),
]);

$task = new \qbank_stackgen\task\generate_stack_question_norag();
$task->set_custom_data(['jobid' => $jobid]);
\core\task\manager::queue_adhoc_task($task, true);

echo json_encode(['success' => true, 'message' => get_string('review_confirm_success', 'qbank_stackgen')]);
