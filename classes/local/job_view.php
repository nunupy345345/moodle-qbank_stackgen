<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * ジョブ1件分の表示用データ構築ロジック。
 *
 * upload.php（初期表示）と classes/external/get_job_statuses.php（ポーリング）の
 * 両方から呼ばれる唯一の実装。job_card.mustache が Templates.render() から単独で
 * 呼ばれる場合でも自己完結できるよう、sesskey/courseid/actionurl も含めて返す。
 */
class job_view {

    public static function status_classes(): array {
        return [
            'pending'                => 'secondary',
            'ocr_done'               => 'info',
            'feedback_pending'       => 'warning',
            'llm_done'               => 'primary',
            'validated'              => 'success',
            'review_pending'         => 'warning',
            'approved'               => 'success',
            'rejected'               => 'danger',
            'validation_failed'      => 'danger',
            'requires_manual_review' => 'danger',
        ];
    }

    public static function status_labels(): array {
        return [
            'pending'                => get_string('status_pending', 'qbank_stackgen'),
            'ocr_done'               => get_string('status_ocr_done', 'qbank_stackgen'),
            'feedback_pending'       => get_string('status_feedback_pending', 'qbank_stackgen'),
            'llm_done'               => get_string('status_llm_done', 'qbank_stackgen'),
            'validated'              => get_string('status_validated', 'qbank_stackgen'),
            'review_pending'         => get_string('status_review_pending', 'qbank_stackgen'),
            'approved'               => get_string('status_approved', 'qbank_stackgen'),
            'rejected'               => get_string('status_rejected', 'qbank_stackgen'),
            'validation_failed'      => get_string('status_validation_failed', 'qbank_stackgen'),
            'requires_manual_review' => get_string('status_requires_manual_review', 'qbank_stackgen'),
        ];
    }

    public static function retryable_statuses(): array {
        // llm_done は、pending/ocr_doneと違って「必ずタスクがキューされている」保証がない
        // （例: 本機能の追加前からllm_doneで止まっていたジョブにはタスクが存在しない）ため、
        // 自動実行の表示に加えて手動の再実行ボタンも出せるようにする。
        return ['requires_manual_review', 'validation_failed', 'rejected', 'llm_done'];
    }

    // これらのステータスは、教員の操作を待たずに次のAdhoc Task（OCRや誤答例生成、問題バンク登録）が
    // バックグラウンドの自動cronループによって自動的に処理される（cron.phpの手動実行は不要）。
    public static function autoprocessing_statuses(): array {
        return ['pending', 'ocr_done', 'llm_done'];
    }

    /**
     * job_card.mustache（テンプレート）が必要とする全フィールドを1ジョブ分組み立てる。
     *
     * @param \stdClass $job          qbank_stackgen_jobs の1レコード
     * @param int       $courseid
     * @param string    $sesskey
     * @return array
     */
    public static function build(\stdClass $job, int $courseid, string $sesskey): array {
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
            $dom = new \DOMDocument();
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

        // Stage 3 レビュー用データ（誤答例/フィードバック案）を、feedback_pending のジョブについてのみ組み立てる。
        // feedback_confirmed が既に保存済み（＝確定済みでStage4のXML生成をバックグラウンドで待っている）の場合は
        // 編集フォームを出さず、スピナー付きの無効化ボタンを表示する。これはDB上の状態なのでリロードしても保持される。
        $needsreview    = $job->status === 'feedback_pending' && empty($job->feedback_confirmed);
        $awaitingxml    = $job->status === 'feedback_pending' && !empty($job->feedback_confirmed);
        $reviewproblems = [];
        if ($needsreview) {
            $ocrforreview  = json_decode($job->ocr_result, true) ?: [];
            $ocrproblems   = $ocrforreview['problems'] ?? [];
            $feedbackdata  = json_decode($job->feedback_result, true) ?: [];
            $feedbackprobs = $feedbackdata['problems'] ?? [];

            foreach ($ocrproblems as $i => $p) {
                $wronganswers = [];
                foreach (($feedbackprobs[$i]['wronganswers'] ?? []) as $j => $w) {
                    $wronganswers[] = [
                        'index'         => $j,
                        'expression'    => $w['expression'] ?? '',
                        'misconception' => $w['misconception'] ?? '',
                        'feedback'      => $w['feedback'] ?? '',
                    ];
                }

                $reviewproblems[] = [
                    'index'              => $i,
                    'displaynum'         => $i + 1,
                    'question_text'      => $p['question_text'] ?? '',
                    'question_type'      => $p['question_type'] ?? '',
                    'problem_expression' => $p['problem_expression'] ?? '',
                    'answer_expression'  => $p['answer_expression'] ?? null,
                    'has_answer'         => !empty($p['answer_expression']),
                    'wronganswers'       => $wronganswers,
                ];
            }
        }

        $autoprocessing = in_array($job->status, self::autoprocessing_statuses(), true);

        $isapproved = $job->status === 'approved';
        $bankurl = '';
        if ($isapproved && !empty($job->question_category_id)) {
            $bankurl = (new \moodle_url('/question/edit.php', [
                'courseid' => $courseid,
                'cat'      => $job->question_category_id . ',' . \context_course::instance($courseid)->id,
            ]))->out(false);
        }

        $statuslabels = self::status_labels();
        $statusclasses = self::status_classes();

        return [
            'id'                 => $job->id,
            'status'             => $job->status,
            'statuslabel'        => $statuslabels[$job->status] ?? $job->status,
            'statusclass'        => $statusclasses[$job->status] ?? 'secondary',
            'timecreated'        => userdate($job->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'hasocr'             => !empty($problems),
            'problems'           => $problems,
            'hasxml'             => !empty($xml_questions),
            'xml_questions'      => $xml_questions,
            'xml_count'          => count($xml_questions),
            'xml_raw'            => $job->xml_result ?? '',
            'haserror'           => !empty($job->error_msg),
            'error_msg'          => $job->error_msg ?? '',
            'canretry'           => in_array($job->status, self::retryable_statuses(), true),
            'retry_count'        => $job->retry_count,
            'canreview'          => $needsreview,
            'reviewproblems'     => $reviewproblems,
            'hasreviewproblems'  => !empty($reviewproblems),
            'awaitingxml'        => $awaitingxml,
            'autoprocessing'     => $autoprocessing,
            'isapproved'         => $isapproved,
            'bankurl'            => $bankurl,
            'confirmurl'         => (new \moodle_url('/question/bank/stackgen/review.php'))->out(false),
            'sesskey'            => $sesskey,
            'courseid'           => $courseid,
            'actionurl'          => (new \moodle_url('/question/bank/stackgen/upload.php'))->out(false),
            'polling'            => $autoprocessing || $awaitingxml,
        ];
    }
}
