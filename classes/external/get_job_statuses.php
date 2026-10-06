<?php
namespace qbank_stackgen\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * ジョブ一覧のライブ更新用External Function。
 *
 * upload.php が初期表示時に \qbank_stackgen\local\job_view::build() で組み立てるのと
 * 同じデータを、amd/src/status_poll.js からのポーリングに対して返す。
 */
class get_job_statuses extends external_api {

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

        // upload.php の1ページ目（page=0）は最新1件だけを表示・ポーリング対象にする構成なので、
        // ここで返すジョブもそれに合わせて最新1件のみにする。
        $dbjobs = $DB->get_records_select(
            'qbank_stackgen_jobs',
            'courseid = ? AND userid = ?',
            [$courseid, $USER->id],
            'id DESC',
            'id, status, ocr_result, feedback_result, feedback_confirmed, xml_result, error_msg, ' .
                'question_category_id, retry_count, timecreated, rag_examples_used, ' .
                'track_norag, norag_status, norag_feedback_result, norag_feedback_confirmed, ' .
                'norag_xml_result, norag_error_msg, norag_retry_count, norag_question_category_id',
            0, 1
        );

        $sesskey = sesskey();
        $result  = [];
        foreach ($dbjobs as $job) {
            $result[] = \qbank_stackgen\local\job_view::build($job, $courseid, $sesskey);
        }

        return $result;
    }

    public static function execute_returns(): external_multiple_structure {
        // Step 6 RAG: このジョブの生成時に参照した教員手作りコーパスの類似問題（トレーサビリティ表示用）。
        $ragexamplestructure = [
            'sourcefile'     => new external_value(PARAM_RAW, 'Corpus source filename'),
            'category'       => new external_value(PARAM_RAW, 'Corpus example category'),
            'similarity_pct' => new external_value(PARAM_RAW, 'Cosine similarity, formatted as a percentage'),
        ];

        $problemstructure = [
            'question_text'      => new external_value(PARAM_RAW, 'Question text'),
            'question_type'      => new external_value(PARAM_RAW, 'Question type'),
            'problem_expression' => new external_value(PARAM_RAW, 'Problem expression (LaTeX)'),
            'answer_expression'  => new external_value(PARAM_RAW, 'Answer expression (LaTeX)', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'has_answer'         => new external_value(PARAM_BOOL, 'Whether an answer expression is present'),
            'rag_examples'       => new external_multiple_structure(
                new external_single_structure($ragexamplestructure),
                'RAG: similar corpus examples used for this problem', VALUE_DEFAULT, []
            ),
            'has_rag_examples'   => new external_value(PARAM_BOOL, 'Whether any RAG examples were used', VALUE_DEFAULT, false),
        ];

        $wronganswerstructure = [
            'index'         => new external_value(PARAM_INT, 'Row index'),
            'expression'    => new external_value(PARAM_RAW, 'Wrong-answer expression (Maxima)'),
            'misconception' => new external_value(PARAM_RAW, 'Likely misconception'),
            'feedback'      => new external_value(PARAM_RAW, 'Feedback text'),
        ];

        $reviewproblemstructure = array_merge($problemstructure, [
            'index'        => new external_value(PARAM_INT, 'Problem index'),
            'displaynum'   => new external_value(PARAM_INT, 'Display number (1-based)'),
            'name'         => new external_value(PARAM_TEXT, 'Question name (editable default)', VALUE_DEFAULT, ''),
            'wronganswers' => new external_multiple_structure(
                new external_single_structure($wronganswerstructure),
                'Wrong answers', VALUE_DEFAULT, []
            ),
        ]);

        // RAGなし比較トラック(norag_*)用。従来版と同じ誤答例の形(misconception)を使う点が
        // KC版(kc_id/kc_name)との違い。
        $noragconfirmedwrongansstructure = [
            'expression'    => new external_value(PARAM_RAW, 'Wrong-answer expression (Maxima)'),
            'misconception' => new external_value(PARAM_RAW, 'Likely misconception', VALUE_DEFAULT, ''),
            'feedback'      => new external_value(PARAM_RAW, 'Feedback text', VALUE_DEFAULT, ''),
        ];
        $noragconfirmedproblemstructure = [
            'displaynum'         => new external_value(PARAM_INT, 'Display number (1-based)'),
            'name'               => new external_value(PARAM_TEXT, 'Question name', VALUE_DEFAULT, ''),
            'question_text'      => new external_value(PARAM_RAW, 'Question text', VALUE_DEFAULT, ''),
            'question_type'      => new external_value(PARAM_RAW, 'Question type', VALUE_DEFAULT, ''),
            'problem_expression' => new external_value(PARAM_RAW, 'Problem expression (LaTeX)', VALUE_DEFAULT, ''),
            'answer_expression'  => new external_value(PARAM_RAW, 'Answer expression (LaTeX)', VALUE_OPTIONAL, null, NULL_ALLOWED),
            'has_answer'         => new external_value(PARAM_BOOL, 'Whether an answer expression is present'),
            'wronganswers'       => new external_multiple_structure(
                new external_single_structure($noragconfirmedwrongansstructure),
                'Confirmed wrong answers', VALUE_DEFAULT, []
            ),
        ];
        $noragreviewproblemstructure = array_merge($noragconfirmedproblemstructure, [
            'index'        => new external_value(PARAM_INT, 'Problem index'),
            'wronganswers' => new external_multiple_structure(
                new external_single_structure(array_merge($noragconfirmedwrongansstructure, [
                    'index' => new external_value(PARAM_INT, 'Row index'),
                ])),
                'Wrong answers', VALUE_DEFAULT, []
            ),
        ]);

        return new external_multiple_structure(
            new external_single_structure([
                'id'          => new external_value(PARAM_INT, 'Job id'),
                'status'      => new external_value(PARAM_ALPHANUMEXT, 'Raw status'),
                'statuslabel' => new external_value(PARAM_RAW, 'Translated status label'),
                'statusclass' => new external_value(PARAM_ALPHA, 'Bootstrap badge class suffix'),
                'timecreated' => new external_value(PARAM_RAW, 'Formatted creation date'),
                'hasocr'      => new external_value(PARAM_BOOL, 'Has OCR problems'),
                'problems'    => new external_multiple_structure(
                    new external_single_structure($problemstructure),
                    'OCR problems', VALUE_DEFAULT, []
                ),
                'hasxml'        => new external_value(PARAM_BOOL, 'Has XML output'),
                'xml_questions' => new external_multiple_structure(
                    new external_single_structure(['qname' => new external_value(PARAM_RAW, 'Question name')]),
                    'Generated question names', VALUE_DEFAULT, []
                ),
                'xml_count'         => new external_value(PARAM_INT, 'Number of generated questions'),
                'xml_raw'           => new external_value(PARAM_RAW, 'Raw XML'),
                'haserror'          => new external_value(PARAM_BOOL, 'Has error'),
                'error_msg'         => new external_value(PARAM_RAW, 'Error message'),
                'canretry'          => new external_value(PARAM_BOOL, 'Retry allowed'),
                'retry_count'       => new external_value(PARAM_INT, 'Retry count'),
                'canreview'         => new external_value(PARAM_BOOL, 'Needs teacher review'),
                'reviewproblems'    => new external_multiple_structure(
                    new external_single_structure($reviewproblemstructure),
                    'Problems awaiting review', VALUE_DEFAULT, []
                ),
                'hasreviewproblems' => new external_value(PARAM_BOOL, 'Has reviewable problems'),
                'confirmedproblems'    => new external_multiple_structure(
                    new external_single_structure($noragconfirmedproblemstructure),
                    'Confirmed problems (read-only summary)', VALUE_DEFAULT, []
                ),
                'hasconfirmedproblems' => new external_value(PARAM_BOOL, 'Has confirmed problems'),
                'awaitingxml'       => new external_value(PARAM_BOOL, 'Confirmed, awaiting XML generation'),
                'autoprocessing'    => new external_value(PARAM_BOOL, 'Being auto-processed by cron'),
                'isapproved'        => new external_value(PARAM_BOOL, 'Registered in the question bank'),
                'bankurl'           => new external_value(PARAM_URL, 'Question bank category URL', VALUE_DEFAULT, ''),
                'confirmurl'        => new external_value(PARAM_URL, 'Review confirm endpoint'),
                'sesskey'           => new external_value(PARAM_RAW, 'Session key'),
                'courseid'          => new external_value(PARAM_INT, 'Course id'),
                'actionurl'         => new external_value(PARAM_URL, 'upload.php URL'),
                'polling'           => new external_value(PARAM_BOOL, 'Still changing via background task'),

                // RAGなし比較トラック(norag_*)。同じ画像(ジョブ)について従来版(RAGあり)と並べて
                // 比較表示するための追加フィールド群。track_norag が選択されていないジョブは
                // hasnorag=falseになり、テンプレート側でこのセクション全体を非表示にする。
                'hasnorag'                   => new external_value(PARAM_BOOL, 'Whether the no-RAG comparison track was selected'),
                'norag_status'               => new external_value(PARAM_ALPHANUMEXT, 'Raw no-RAG track status', VALUE_DEFAULT, ''),
                'norag_statuslabel'          => new external_value(PARAM_RAW, 'Translated no-RAG status label'),
                'norag_statusclass'         => new external_value(PARAM_ALPHA, 'Bootstrap badge class suffix'),
                'norag_needsreview'         => new external_value(PARAM_BOOL, 'No-RAG track needs teacher review'),
                'norag_reviewproblems'      => new external_multiple_structure(
                    new external_single_structure($noragreviewproblemstructure),
                    'No-RAG problems awaiting review', VALUE_DEFAULT, []
                ),
                'norag_hasreviewproblems'    => new external_value(PARAM_BOOL, 'Has no-RAG reviewable problems'),
                'norag_confirmedproblems'    => new external_multiple_structure(
                    new external_single_structure($noragconfirmedproblemstructure),
                    'Confirmed no-RAG problems (read-only summary)', VALUE_DEFAULT, []
                ),
                'norag_hasconfirmedproblems' => new external_value(PARAM_BOOL, 'Has confirmed no-RAG problems'),
                'norag_awaitingxml'          => new external_value(PARAM_BOOL, 'No-RAG: confirmed, awaiting XML generation'),
                'norag_processing'           => new external_value(PARAM_BOOL, 'No-RAG: being auto-processed by cron'),
                'norag_haserror'             => new external_value(PARAM_BOOL, 'No-RAG: has error'),
                'norag_error_msg'            => new external_value(PARAM_RAW, 'No-RAG error message', VALUE_DEFAULT, ''),
                'norag_isapproved'           => new external_value(PARAM_BOOL, 'No-RAG: registered in the question bank'),
                'norag_bankurl'              => new external_value(PARAM_URL, 'No-RAG question bank category URL', VALUE_DEFAULT, ''),
                'norag_confirmurl'           => new external_value(PARAM_URL, 'No-RAG review confirm endpoint'),
            ])
        );
    }
}
