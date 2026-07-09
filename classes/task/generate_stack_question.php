<?php
namespace qbank_stackgen\task;

/**
 * Adhoc Task: STACK問題生成パイプライン（Stage 2〜4）
 *
 * ステータス分岐:
 *   pending           → Stage 2（OCR）→ Stage 3（誤答例/フィードバック案生成）→ feedback_pending で停止
 *   ocr_done          → Stage 3（誤答例/フィードバック案生成）のみ（OCR 再実行しない）
 *   feedback_pending  → feedback_confirmed が設定されていれば Stage 4（LLM XML生成）を実行
 *   llm_done          → Stage 6（問題バンクへの直接登録）のみ再実行（XML再生成しない）
 *
 * feedback_pending で一度停止するのは、教員が review.php で誤答例/フィードバック案を
 * 確認・修正して確定するのを待つため（案B: 確定内容をXML生成プロンプトに組み込む）。
 * Stage 4（XML生成）が成功した後は、教員の操作を待たずに同一実行内でStage 6（問題バンク登録）
 * まで自動的に進む。CAS検証（Stage 5）は未実装のため、現時点では検証をスキップして登録する。
 */
class generate_stack_question extends \core\task\adhoc_task {

    public function get_name(): string {
        return get_string('pluginname', 'qbank_stackgen');
    }

    public function execute(): void {
        global $DB;

        $data  = $this->get_custom_data();
        $jobid = $data->jobid ?? null;

        if (!$jobid) {
            mtrace('[qbank_stackgen] generate_stack_question: jobid missing, skipping.');
            return;
        }

        $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid]);
        if (!$job) {
            mtrace("[qbank_stackgen] Job {$jobid} not found.");
            return;
        }

        mtrace("[qbank_stackgen] Job {$jobid}: starting (status={$job->status}).");

        // Stage 2: Gemini OCR（pending のときのみ実行）
        if ($job->status === 'pending') {
            try {
                $ocr    = new \qbank_stackgen\api\gemini_ocr();
                $result = $ocr->run($job->image_b64);

                $count = count($result['problems']);
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'           => $jobid,
                    'status'       => 'ocr_done',
                    'ocr_result'   => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'timemodified' => time(),
                ]);
                mtrace("[qbank_stackgen] Job {$jobid}: OCR done ({$count} problem(s) found).");

                $problems = $result['problems'];

            } catch (\Throwable $e) {
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'           => $jobid,
                    'status'       => 'requires_manual_review',
                    'error_msg'    => 'Stage2 OCR error: ' . $e->getMessage(),
                    'timemodified' => time(),
                ]);
                mtrace("[qbank_stackgen] Job {$jobid}: OCR failed: " . $e->getMessage());
                return;
            }

            $this->run_feedback_stage($jobid, $problems);
            return;
        }

        if ($job->status === 'ocr_done') {
            // OCR 済み: 既存の ocr_result を使って Stage 3（誤答例/フィードバック案生成）へ。
            $ocr_data = json_decode($job->ocr_result, true);
            $problems = isset($ocr_data['problems']) ? $ocr_data['problems'] : [$ocr_data];
            mtrace("[qbank_stackgen] Job {$jobid}: resuming from ocr_done (" . count($problems) . " problem(s)).");

            $this->run_feedback_stage($jobid, $problems);
            return;
        }

        if ($job->status === 'feedback_pending') {
            if (empty($job->feedback_confirmed)) {
                mtrace("[qbank_stackgen] Job {$jobid}: feedback not confirmed yet, waiting for teacher review.");
                return;
            }

            $ocr_data = json_decode($job->ocr_result, true);
            $problems = isset($ocr_data['problems']) ? $ocr_data['problems'] : [$ocr_data];
            $confirmed = json_decode($job->feedback_confirmed, true);
            $feedbackproblems = $confirmed['problems'] ?? [];

            $this->run_xml_stage($jobid, $problems, $feedbackproblems);
            return;
        }

        if ($job->status === 'llm_done') {
            // XMLは既に生成済み（再試行でここに戻された場合）。Stage 6だけ再実行する。
            mtrace("[qbank_stackgen] Job {$jobid}: resuming from llm_done (import only).");
            $this->run_import_stage($jobid);
            return;
        }

        mtrace("[qbank_stackgen] Job {$jobid}: unexpected status={$job->status}, skipping.");
    }

    /**
     * Stage 3: 誤答例/フィードバック案を生成し、教員のレビュー待ちにする。
     */
    private function run_feedback_stage(int $jobid, array $problems): void {
        global $DB;

        try {
            if (empty($problems)) {
                throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                    'OCR result contains no problems');
            }

            $generator = new \qbank_stackgen\api\feedback_generator();
            $feedback  = $generator->run($problems);

            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'               => $jobid,
                'status'           => 'feedback_pending',
                'feedback_result'  => json_encode($feedback, JSON_UNESCAPED_UNICODE),
                'timemodified'     => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: feedback draft generated, awaiting teacher review.");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'status'       => 'requires_manual_review',
                'error_msg'    => 'Stage3 feedback error: ' . $e->getMessage(),
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: feedback generation failed: " . $e->getMessage());
        }
    }

    /**
     * Stage 4: 確定済みの誤答例/フィードバックを組み込んで STACK XML（PRT付き）を生成する。
     */
    private function run_xml_stage(int $jobid, array $problems, array $feedbackproblems): void {
        global $DB;

        try {
            $generator = new \qbank_stackgen\api\llm_generator();
            $output    = $generator->run($problems, $feedbackproblems);

            $count = count($problems);
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'status'       => 'llm_done',
                'xml_result'   => $output['xml'],
                'llm_cot'      => $output['cot'],
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: LLM done ({$count} question(s) generated).");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'status'       => 'requires_manual_review',
                'error_msg'    => 'Stage4 LLM error: ' . $e->getMessage(),
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: LLM failed: " . $e->getMessage());
            return;
        }

        // Stage 5 (CAS検証) は Step 4 で追加。現時点では検証をスキップしてStage 6へ直接進む。
        $this->run_import_stage($jobid);
    }

    /**
     * Stage 6: 確定済みXMLを問題バンクに直接登録する。
     */
    private function run_import_stage(int $jobid): void {
        global $DB;

        $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid], '*', MUST_EXIST);

        try {
            $result = \qbank_stackgen\question_importer::import($job);

            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'                    => $jobid,
                'status'                => 'approved',
                'question_ids'          => json_encode($result['questionids']),
                'question_category_id'  => $result['categoryid'],
                'error_msg'             => null,
                'timemodified'          => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: imported into question bank ("
                . count($result['questionids']) . " question(s)).");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'status'       => 'requires_manual_review',
                'error_msg'    => 'Stage6 import error: ' . $e->getMessage(),
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: import failed: " . $e->getMessage());
        }
    }
}
