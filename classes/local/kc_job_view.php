<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * KC診断版（別ページ、kc_review.php）のジョブ1件分の表示用データ構築ロジック。
 *
 * 既存の job_view.php（既存ページ upload.php 用）とは完全に独立しており、kc_*列のみを見る。
 * 既存ページのステータス（status列）や既存の誤答例（feedback_result/feedback_confirmed）には
 * 一切触れない。
 */
class kc_job_view {

    public static function status_labels(): array {
        return [
            ''                        => get_string('kc_status_not_started', 'qbank_stackgen'),
            'feedback_pending'        => get_string('kc_status_feedback_pending', 'qbank_stackgen'),
            'llm_done'                => get_string('kc_status_processing', 'qbank_stackgen'),
            'requires_manual_review'  => get_string('status_requires_manual_review', 'qbank_stackgen'),
            'approved'                => get_string('status_approved', 'qbank_stackgen'),
        ];
    }

    public static function status_classes(): array {
        return [
            ''                        => 'secondary',
            'feedback_pending'        => 'warning',
            'llm_done'                => 'info',
            'requires_manual_review'  => 'danger',
            'approved'                => 'success',
        ];
    }

    /**
     * @param \stdClass $job qbank_stackgen_jobsの1レコード（ocr_result, kc_*列, courseid, id を含む）
     * @param int $courseid
     * @param string $sesskey
     * @return array kc_job_card.mustache 用テンプレートコンテキスト
     */
    public static function build(\stdClass $job, int $courseid, string $sesskey): array {
        $kc_status = $job->kc_status ?? '';

        $ocrdata     = json_decode($job->ocr_result ?? '', true) ?: [];
        $rawproblems = $ocrdata['problems'] ?? [];
        // $problems は Stage3レビュー用データ組み立て（$needsreview時）で使うOCR生データの別名。
        $problems = $rawproblems;

        $notstarted     = $kc_status === '' || $kc_status === null;
        $needsreview    = $kc_status === 'feedback_pending' && empty($job->kc_feedback_confirmed);
        $awaitingxml    = $kc_status === 'feedback_pending' && !empty($job->kc_feedback_confirmed);
        $processing     = $kc_status === 'llm_done';
        $haserror       = $kc_status === 'requires_manual_review';
        $isapproved     = $kc_status === 'approved';

        // ステータスに関わらず常時表示するOCRサマリ（既存ページのjob_view.php::build()と同様の形）。
        $ocrsummary = [];
        foreach ($rawproblems as $p) {
            if (empty($p['problem_expression'])) {
                continue;
            }
            $ocrsummary[] = [
                'question_text'      => $p['question_text'] ?? '',
                'question_type'      => $p['question_type'] ?? '',
                'problem_expression' => $p['problem_expression'] ?? '',
                'answer_expression'  => $p['answer_expression'] ?? null,
                'has_answer'         => !empty($p['answer_expression']),
            ];
        }

        // 確定済み（kc_feedback_confirmed）があれば、誤答例と欠けている知識(KC)を読み取り専用で表示する。
        // レビュー待ち中の編集フォームとは別に、確定後（awaitingxml/processing/haserror/isapproved）も
        // ずっと見える summary として保持する。
        $confirmedproblems = [];
        if (!empty($job->kc_feedback_confirmed)) {
            $confirmed      = json_decode($job->kc_feedback_confirmed, true) ?: [];
            $confirmedprobs = $confirmed['problems'] ?? [];
            foreach ($confirmedprobs as $i => $cp) {
                $ocrp = $rawproblems[$i] ?? [];
                $wronganswers = [];
                foreach ((array)($cp['wronganswers'] ?? []) as $w) {
                    $wronganswers[] = [
                        'expression' => $w['expression'] ?? '',
                        'kc_name'    => $w['kc_name'] ?? '',
                        'feedback'   => $w['feedback'] ?? '',
                    ];
                }
                $confirmedproblems[] = [
                    'displaynum'         => $i + 1,
                    'name'               => !empty($cp['name']) ? $cp['name'] : ('問題 ' . ($i + 1)),
                    'question_text'      => !empty($cp['questiontext']) ? $cp['questiontext'] : ($ocrp['question_text'] ?? ''),
                    'question_type'      => $ocrp['question_type'] ?? '',
                    'problem_expression' => $ocrp['problem_expression'] ?? '',
                    'answer_expression'  => $ocrp['answer_expression'] ?? null,
                    'has_answer'         => !empty($ocrp['answer_expression']),
                    'wronganswers'       => $wronganswers,
                ];
            }
        }

        $kc_catalog = kc_catalog::get_all();
        $reviewproblems = [];
        if ($needsreview) {
            $draft = json_decode($job->kc_feedback_result ?? '', true) ?: [];
            $draftproblems = $draft['problems'] ?? [];

            foreach ($problems as $i => $p) {
                $wronganswers = [];
                foreach (($draftproblems[$i]['wronganswers'] ?? []) as $j => $w) {
                    $kc_id = $w['kc_id'] ?? '';
                    $wronganswers[] = [
                        'index'      => $j,
                        'expression' => $w['expression'] ?? '',
                        'kc_id'      => $kc_id,
                        'kc_name'    => $w['kc_name'] ?? '',
                        'feedback'   => $w['feedback'] ?? '',
                        'kc_options' => self::build_kc_options($kc_catalog, $kc_id),
                    ];
                }

                $reviewproblems[] = [
                    'index'              => $i,
                    'displaynum'         => $i + 1,
                    'name'               => "問題 " . ($i + 1) . "（KC診断版）",
                    'question_text'      => $p['question_text'] ?? '',
                    'problem_expression' => $p['problem_expression'] ?? '',
                    'answer_expression'  => $p['answer_expression'] ?? null,
                    'has_answer'         => !empty($p['answer_expression']),
                    'wronganswers'       => $wronganswers,
                    'kc_options'         => self::build_kc_options($kc_catalog, ''),
                ];
            }
        }

        $bankurl = '';
        if ($isapproved && !empty($job->kc_question_category_id)) {
            $bankurl = (new \moodle_url('/question/edit.php', [
                'courseid' => $courseid,
                'cat'      => $job->kc_question_category_id . ',' . \context_course::instance($courseid)->id,
            ]))->out(false);
        }

        $statuslabels  = self::status_labels();
        $statusclasses = self::status_classes();

        // バックグラウンドで自動的に進行する可能性がある状態だけポーリング対象にする。
        // レビュー待ち（教員の確定操作待ち）・エラー（再実行ボタン待ち）・承認済みは
        // それ以上自動では変化しないのでポーリング不要。
        $polling = $notstarted || $awaitingxml || $processing;

        return [
            'id'             => $job->id,
            'kc_status'      => $kc_status,
            'polling'        => $polling,
            'statuslabel'    => $statuslabels[$kc_status] ?? $kc_status,
            'statusclass'    => $statusclasses[$kc_status] ?? 'secondary',
            'problemcount'   => count($problems),
            'ocrsummary'     => $ocrsummary,
            'hasocrsummary'  => !empty($ocrsummary),
            'notstarted'     => $notstarted,
            'needsreview'    => $needsreview,
            'reviewproblems' => $reviewproblems,
            'hasreviewproblems' => !empty($reviewproblems),
            'confirmedproblems'    => $confirmedproblems,
            'hasconfirmedproblems' => !empty($confirmedproblems),
            'awaitingxml'    => $awaitingxml,
            'processing'     => $processing,
            'haserror'       => $haserror,
            'error_msg'      => $job->kc_error_msg ?? '',
            'isapproved'     => $isapproved,
            'bankurl'        => $bankurl,
            'confirmurl'     => (new \moodle_url('/question/bank/stackgen/kc_review_confirm.php'))->out(false),
            'startactionurl' => (new \moodle_url('/question/bank/stackgen/kc_review.php'))->out(false),
            'sesskey'        => $sesskey,
            'courseid'       => $courseid,
        ];
    }

    /**
     * KCカタログをmustacheの<select>用オプション列に整形する（先頭に空選択肢を追加）。
     */
    private static function build_kc_options(array $catalog, string $selected_kc_id): array {
        $options = [['id' => '', 'label' => '（KCなし・手動）', 'selected' => $selected_kc_id === '']];
        foreach ($catalog as $kc) {
            $options[] = [
                'id'       => $kc['id'],
                'label'    => "{$kc['id']}: {$kc['ja']}",
                'selected' => $kc['id'] === $selected_kc_id,
            ];
        }
        return $options;
    }
}
