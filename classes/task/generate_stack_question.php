<?php
namespace qbank_stackgen\task;

/**
 * Adhoc Task: STACK問題生成パイプライン（Stage 2〜4）
 *
 * ステータス分岐:
 *   pending  → Stage 2（OCR）→ Stage 3（LLM）
 *   ocr_done → Stage 3（LLM）のみ（OCR 再実行しない）
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

                // Stage 3 に渡す problems を保持
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

        } elseif ($job->status === 'ocr_done') {
            // OCR 済み: 既存の ocr_result を使う
            $ocr_data = json_decode($job->ocr_result, true);
            // 旧形式（単一問題オブジェクト）との後方互換
            $problems = isset($ocr_data['problems']) ? $ocr_data['problems'] : [$ocr_data];
            mtrace("[qbank_stackgen] Job {$jobid}: resuming from ocr_done (" . count($problems) . " problem(s)).");

        } else {
            mtrace("[qbank_stackgen] Job {$jobid}: unexpected status={$job->status}, skipping.");
            return;
        }

        // Stage 3: LLM → STACK XML
        try {
            if (empty($problems)) {
                throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                    'OCR result contains no problems');
            }

            $generator = new \qbank_stackgen\api\llm_generator();
            $xml       = $generator->run($problems);

            $count = count($problems);
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'status'       => 'llm_done',
                'xml_result'   => $xml,
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: LLM done ({$count} question(s) generated).");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'status'       => 'requires_manual_review',
                'error_msg'    => 'Stage3 LLM error: ' . $e->getMessage(),
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: LLM failed: " . $e->getMessage());
            return;
        }

        // Stage 4 は Step 3 で追加
    }
}
