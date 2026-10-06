<?php
namespace qbank_stackgen\task;

/**
 * Adhoc Task: 従来版をRAGなしで生成する比較用トラック（Stage 3〜6）。
 *
 * 同じ画像（同じジョブ）について、従来版(RAGあり)・RAGなし版・KC版を並べて比較できるように
 * するためのトラック。既存の generate_stack_question（従来版、RAGはqbank_stackgen_examplesが
 * あれば自動で使う）とは完全に独立しており、進捗は norag_status 列（既存の status 列とは別）で
 * 管理し、既存ページ側の状態には一切書き込まない。Stage 2（OCR）は行わず、同じジョブの
 * 既存 ocr_result をそのまま再利用する。
 *
 * 誤答例生成（feedback_generator）・XML生成（llm_generator）は既存ページと全く同じクラスを
 * そのまま再利用するが、RAG検索結果を一切渡さない（常に空配列）ことで「RAGなし」を実現する
 * （job->rag_examples_used に既存track側の検索結果が保存されていても、ここでは意図的に見ない）。
 *
 * norag_status 遷移:
 *   ''（未着手）        → Stage 3（RAGなしで誤答例生成）→ feedback_pending で停止
 *   feedback_pending    → norag_feedback_confirmed が設定されていれば Stage 4（XML生成、RAGなし）→
 *                          Stage 5（CAS検証）を実行
 *   llm_done            → Stage 6（問題バンクへの直接登録）のみ再実行（XML再生成しない）
 */
class generate_stack_question_norag extends \core\task\adhoc_task {

    public function get_name(): string {
        return get_string('pluginname', 'qbank_stackgen') . ' (RAGなし比較)';
    }

    public function execute(): void {
        global $DB;

        $data  = $this->get_custom_data();
        $jobid = $data->jobid ?? null;

        if (!$jobid) {
            mtrace('[qbank_stackgen] generate_stack_question_norag: jobid missing, skipping.');
            return;
        }

        $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid]);
        if (!$job) {
            mtrace("[qbank_stackgen] NoRAG Job {$jobid} not found.");
            return;
        }

        mtrace("[qbank_stackgen] NoRAG Job {$jobid}: starting (norag_status={$job->norag_status}).");

        if (empty($job->ocr_result)) {
            mtrace("[qbank_stackgen] NoRAG Job {$jobid}: no ocr_result yet, skipping (original track hasn't OCR'd this job).");
            return;
        }

        $ocr_data = json_decode($job->ocr_result, true);
        $problems = isset($ocr_data['problems']) ? $ocr_data['problems'] : [$ocr_data];

        if ($job->norag_status === '' || $job->norag_status === null) {
            $this->run_feedback_stage($jobid, $problems);
            return;
        }

        if ($job->norag_status === 'feedback_pending') {
            if (empty($job->norag_feedback_confirmed)) {
                mtrace("[qbank_stackgen] NoRAG Job {$jobid}: feedback not confirmed yet, waiting for teacher review.");
                return;
            }

            $confirmed = json_decode($job->norag_feedback_confirmed, true);
            $feedbackproblems = $confirmed['problems'] ?? [];

            foreach ($problems as $i => &$problem) {
                $questiontext = trim((string)($feedbackproblems[$i]['questiontext'] ?? ''));
                if ($questiontext !== '') {
                    $problem['question_text'] = $questiontext;
                    $problem['stack_question_text'] = $questiontext;
                }
            }
            unset($problem);

            $this->run_xml_stage($jobid, $problems, $feedbackproblems);
            return;
        }

        if ($job->norag_status === 'llm_done') {
            mtrace("[qbank_stackgen] NoRAG Job {$jobid}: resuming from llm_done (import only).");
            $this->run_import_stage($jobid);
            return;
        }

        mtrace("[qbank_stackgen] NoRAG Job {$jobid}: unexpected norag_status={$job->norag_status}, skipping.");
    }

    /**
     * Stage 3: 誤答例/フィードバック案をRAGなしで生成し、教員のレビュー待ちにする。
     * 既存の feedback_generator をそのまま再利用するが、ragexamplesは渡さない（常に空）。
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
                'id'                   => $jobid,
                'norag_status'         => 'feedback_pending',
                'norag_feedback_result' => json_encode($feedback, JSON_UNESCAPED_UNICODE),
                'timemodified'         => time(),
            ]);
            mtrace("[qbank_stackgen] NoRAG Job {$jobid}: feedback draft generated (no RAG), awaiting teacher review.");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'            => $jobid,
                'norag_status'  => 'requires_manual_review',
                'norag_error_msg' => 'NoRAG Stage3 feedback error: ' . $e->getMessage(),
                'timemodified'  => time(),
            ]);
            mtrace("[qbank_stackgen] NoRAG Job {$jobid}: feedback generation failed: " . $e->getMessage());
        }
    }

    /**
     * Stage 4〜5: 確定済みの誤答例/フィードバックを組み込んで STACK XML（PRT付き）をRAGなしで
     * 生成し、CAS検証（goemaxima）に通す。既存の llm_generator / maxima_validator をそのまま再利用する。
     */
    private function run_xml_stage(int $jobid, array $problems, array $feedbackproblems): void {
        global $DB;

        $maxretry  = max(1, (int)(get_config('qbank_stackgen', 'max_retry') ?: 3));
        $validator = new \qbank_stackgen\api\maxima_validator();
        $lasterror = '';
        $count     = count($problems);

        for ($attempt = 1; $attempt <= $maxretry; $attempt++) {
            try {
                $generator = new \qbank_stackgen\api\llm_generator();
                $output    = $generator->run($problems, $feedbackproblems, $lasterror);
            } catch (\Throwable $e) {
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'              => $jobid,
                    'norag_status'    => 'requires_manual_review',
                    'norag_error_msg' => 'NoRAG Stage4 LLM error: ' . $e->getMessage(),
                    'timemodified'    => time(),
                ]);
                mtrace("[qbank_stackgen] NoRAG Job {$jobid}: LLM failed (attempt {$attempt}): " . $e->getMessage());
                return;
            }

            try {
                $result = $validator->validate($output['xml']);
            } catch (\Throwable $e) {
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'               => $jobid,
                    'norag_status'     => 'requires_manual_review',
                    'norag_xml_result' => $output['xml'],
                    'norag_llm_cot'    => $output['cot'],
                    'norag_error_msg'  => 'NoRAG Stage5 CAS validator error: ' . $e->getMessage(),
                    'timemodified'     => time(),
                ]);
                mtrace("[qbank_stackgen] NoRAG Job {$jobid}: CAS validator unreachable: " . $e->getMessage());
                return;
            }

            if ($result['valid']) {
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'                => $jobid,
                    'norag_status'      => 'llm_done',
                    'norag_xml_result'  => $output['xml'],
                    'norag_llm_cot'     => $output['cot'],
                    'norag_retry_count' => $attempt - 1,
                    'norag_error_msg'   => null,
                    'timemodified'      => time(),
                ]);
                mtrace("[qbank_stackgen] NoRAG Job {$jobid}: CAS validation OK on attempt {$attempt}"
                    . " ({$count} question(s)).");
                $this->run_import_stage($jobid);
                return;
            }

            $lasterror = implode(' / ', $result['errors']);
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'                => $jobid,
                'norag_xml_result'  => $output['xml'],
                'norag_llm_cot'     => $output['cot'],
                'norag_retry_count' => $attempt,
                'norag_error_msg'   => 'NoRAG Stage5 CAS validation failed: ' . $lasterror,
                'timemodified'      => time(),
            ]);
            mtrace("[qbank_stackgen] NoRAG Job {$jobid}: CAS validation failed (attempt {$attempt}/{$maxretry}): "
                . $lasterror);
        }

        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'              => $jobid,
            'norag_status'    => 'requires_manual_review',
            'norag_error_msg' => "NoRAG Stage5 CAS validation failed after {$maxretry} attempts: {$lasterror}",
            'timemodified'    => time(),
        ]);
        mtrace("[qbank_stackgen] NoRAG Job {$jobid}: CAS validation failed {$maxretry} times, requires manual review.");
    }

    /**
     * Stage 6: 確定済みXMLを問題バンクに直接登録する。既存の question_importer をそのまま再利用する
     * （xml_result/courseidしか見ないため、norag_xml_resultを積んだ一時オブジェクトを渡すだけでよい）。
     */
    private function run_import_stage(int $jobid): void {
        global $DB;

        $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid], '*', MUST_EXIST);

        try {
            $importjob = (object)[
                'xml_result' => $job->norag_xml_result,
                'courseid'   => $job->courseid,
            ];
            $result = \qbank_stackgen\question_importer::import($importjob);

            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'                         => $jobid,
                'norag_status'               => 'approved',
                'norag_question_ids'         => json_encode($result['questionids']),
                'norag_question_category_id' => $result['categoryid'],
                'norag_error_msg'            => null,
                'timemodified'               => time(),
            ]);
            mtrace("[qbank_stackgen] NoRAG Job {$jobid}: imported into question bank ("
                . count($result['questionids']) . " question(s)).");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'              => $jobid,
                'norag_status'    => 'requires_manual_review',
                'norag_error_msg' => 'NoRAG Stage6 import error: ' . $e->getMessage(),
                'timemodified'    => time(),
            ]);
            mtrace("[qbank_stackgen] NoRAG Job {$jobid}: import failed: " . $e->getMessage());
        }
    }
}
