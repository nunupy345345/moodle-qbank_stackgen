<?php
namespace qbank_stackgen\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * KC診断版（別ページ、kc_review.php）のジョブ一覧ライブ更新用External Function。
 *
 * kc_review.php が初期表示時に \qbank_stackgen\local\kc_job_view::build() で組み立てるのと
 * 同じデータを、amd/src/kc_status_poll.js からのポーリングに対して返す。既存の
 * get_job_statuses（既存ページ用）とは完全に独立しており、kc_*列のみを見る。
 */
class get_kc_job_statuses extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    public static function execute(int $courseid): array {
        global $DB, $USER;

        ['courseid' => $courseid] = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid]
        );

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/question:add', $context);

        // kc_review.php と同じ対象範囲（OCR済み、最新20件）にする。
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
        $result  = [];
        foreach ($dbjobs as $job) {
            $result[] = \qbank_stackgen\local\kc_job_view::build($job, $courseid, $sesskey);
        }

        return $result;
    }

    public static function execute_returns(): external_multiple_structure {
        $kcoptionstructure = [
            'id'       => new external_value(PARAM_RAW, 'KC id (empty = no KC / manual entry)'),
            'label'    => new external_value(PARAM_RAW, 'KC dropdown label'),
            'selected' => new external_value(PARAM_BOOL, 'Whether this option is currently selected'),
        ];

        $wronganswerstructure = [
            'index'      => new external_value(PARAM_INT, 'Row index'),
            'expression' => new external_value(PARAM_RAW, 'Wrong-answer expression (Maxima)'),
            'kc_id'      => new external_value(PARAM_RAW, 'Missing knowledge component id', VALUE_DEFAULT, ''),
            'kc_name'    => new external_value(PARAM_RAW, 'Missing knowledge component name', VALUE_DEFAULT, ''),
            'feedback'   => new external_value(PARAM_RAW, 'Feedback text'),
            'kc_options' => new external_multiple_structure(
                new external_single_structure($kcoptionstructure),
                'KC dropdown options for this row', VALUE_DEFAULT, []
            ),
        ];

        $ocrsummarystructure = [
            'question_text'      => new external_value(PARAM_RAW, 'Question text'),
            'question_type'      => new external_value(PARAM_RAW, 'Question type'),
            'problem_expression' => new external_value(PARAM_RAW, 'Problem expression (LaTeX)'),
            'answer_expression'  => new external_value(PARAM_RAW, 'Answer expression (LaTeX)', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'has_answer'         => new external_value(PARAM_BOOL, 'Whether an answer expression is present'),
        ];

        $confirmedwronganswerstructure = [
            'expression' => new external_value(PARAM_RAW, 'Wrong-answer expression (Maxima)'),
            'kc_name'    => new external_value(PARAM_RAW, 'Missing knowledge component name', VALUE_DEFAULT, ''),
            'feedback'   => new external_value(PARAM_RAW, 'Feedback text', VALUE_DEFAULT, ''),
        ];

        $confirmedproblemstructure = [
            'displaynum'         => new external_value(PARAM_INT, 'Display number (1-based)'),
            'name'               => new external_value(PARAM_TEXT, 'Question name', VALUE_DEFAULT, ''),
            'question_text'      => new external_value(PARAM_RAW, 'Question text', VALUE_DEFAULT, ''),
            'question_type'      => new external_value(PARAM_RAW, 'Question type', VALUE_DEFAULT, ''),
            'problem_expression' => new external_value(PARAM_RAW, 'Problem expression (LaTeX)', VALUE_DEFAULT, ''),
            'answer_expression'  => new external_value(PARAM_RAW, 'Answer expression (LaTeX)', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'has_answer'         => new external_value(PARAM_BOOL, 'Whether an answer expression is present'),
            'wronganswers'       => new external_multiple_structure(
                new external_single_structure($confirmedwronganswerstructure),
                'Confirmed wrong answers', VALUE_DEFAULT, []
            ),
        ];

        $reviewproblemstructure = [
            'index'              => new external_value(PARAM_INT, 'Problem index'),
            'displaynum'         => new external_value(PARAM_INT, 'Display number (1-based)'),
            'name'               => new external_value(PARAM_TEXT, 'Question name (editable default)', VALUE_DEFAULT, ''),
            'question_text'      => new external_value(PARAM_RAW, 'Question text'),
            'problem_expression' => new external_value(PARAM_RAW, 'Problem expression (LaTeX)'),
            'answer_expression'  => new external_value(PARAM_RAW, 'Answer expression (LaTeX)', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'has_answer'         => new external_value(PARAM_BOOL, 'Whether an answer expression is present'),
            'wronganswers'       => new external_multiple_structure(
                new external_single_structure($wronganswerstructure),
                'Wrong answers', VALUE_DEFAULT, []
            ),
            'kc_options'         => new external_multiple_structure(
                new external_single_structure($kcoptionstructure),
                'KC dropdown options for newly-added rows (template)', VALUE_DEFAULT, []
            ),
        ];

        return new external_multiple_structure(
            new external_single_structure([
                'id'                => new external_value(PARAM_INT, 'Job id'),
                'kc_status'         => new external_value(PARAM_RAW, 'Raw KC-track status'),
                'statuslabel'       => new external_value(PARAM_RAW, 'Translated status label'),
                'statusclass'       => new external_value(PARAM_ALPHA, 'Bootstrap badge class suffix'),
                'problemcount'      => new external_value(PARAM_INT, 'Number of OCR problems in this job'),
                'ocrsummary'        => new external_multiple_structure(
                    new external_single_structure($ocrsummarystructure),
                    'Always-visible OCR summary', VALUE_DEFAULT, []
                ),
                'hasocrsummary'     => new external_value(PARAM_BOOL, 'Has OCR summary'),
                'notstarted'        => new external_value(PARAM_BOOL, 'KC generation not started yet (track_kc not selected, no task queued)'),
                'queued'            => new external_value(PARAM_BOOL, 'track_kc selected; auto-queued, waiting for cron to pick it up'),
                'needsreview'       => new external_value(PARAM_BOOL, 'Needs teacher review'),
                'reviewproblems'    => new external_multiple_structure(
                    new external_single_structure($reviewproblemstructure),
                    'Problems awaiting review', VALUE_DEFAULT, []
                ),
                'hasreviewproblems' => new external_value(PARAM_BOOL, 'Has reviewable problems'),
                'confirmedproblems'    => new external_multiple_structure(
                    new external_single_structure($confirmedproblemstructure),
                    'Confirmed problems (read-only summary)', VALUE_DEFAULT, []
                ),
                'hasconfirmedproblems' => new external_value(PARAM_BOOL, 'Has confirmed problems'),
                'awaitingxml'       => new external_value(PARAM_BOOL, 'Confirmed, awaiting XML generation'),
                'processing'        => new external_value(PARAM_BOOL, 'Stage6 import in progress (resumed from llm_done)'),
                'haserror'          => new external_value(PARAM_BOOL, 'Has error'),
                'error_msg'         => new external_value(PARAM_RAW, 'Error message'),
                'isapproved'        => new external_value(PARAM_BOOL, 'Registered in the question bank'),
                'bankurl'           => new external_value(PARAM_URL, 'Question bank category URL', VALUE_DEFAULT, ''),
                'confirmurl'        => new external_value(PARAM_URL, 'Review confirm endpoint'),
                'startactionurl'    => new external_value(PARAM_URL, 'kc_review.php URL'),
                'sesskey'           => new external_value(PARAM_RAW, 'Session key'),
                'courseid'          => new external_value(PARAM_INT, 'Course id'),
                'polling'           => new external_value(PARAM_BOOL, 'Still changing via background task'),
            ])
        );
    }
}
