<?php
/**
 * 所要時間の計測用: 確認画面を開いた時刻などを、画面側（AMD JS）から記録するAJAXエンドポイント。
 * 記録できるイベントは review_open のみ（任意のイベント名は受け付けない）。
 */

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$courseid = required_param('courseid', PARAM_INT);
$jobid    = required_param('jobid', PARAM_INT);
$track    = required_param('track', PARAM_ALPHA);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_login($course);
require_capability('moodle/question:add', $context);
require_sesskey();

if (!in_array($track, ['traditional', 'norag', 'kc'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$DB->get_record('qbank_stackgen_jobs', ['id' => $jobid, 'courseid' => $courseid, 'userid' => $USER->id], 'id', MUST_EXIST);

\qbank_stackgen\local\event_log::log($jobid, $track, 'review_open');

echo json_encode(['success' => true]);
