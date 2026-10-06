<?php
namespace qbank_stackgen\task;

/**
 * Adhoc Task: STACK問題生成パイプライン（Stage 2〜6）
 *
 * ステータス分岐:
 *   pending           → Stage 2（OCR）→ Stage 3（誤答例/フィードバック案生成）→ feedback_pending で停止
 *   ocr_done          → Stage 3（誤答例/フィードバック案生成）のみ（OCR 再実行しない）
 *   feedback_pending  → feedback_confirmed が設定されていれば Stage 4（LLM XML生成）→
 *                        Stage 5（CAS検証）を実行
 *   llm_done          → Stage 6（問題バンクへの直接登録）のみ再実行（XML再生成しない）
 *
 * feedback_pending で一度停止するのは、教員が review.php で誤答例/フィードバック案を
 * 確認・修正して確定するのを待つため（案B: 確定内容をXML生成プロンプトに組み込む）。
 * Stage 4（XML生成）の後、教員の操作を待たずに同一実行内でStage 5（CAS検証、goemaxima）に
 * 進む。検証NGの場合は設定 `max_retry`（既定3回）まで、直前の検証エラーをLLMに伝えつつ
 * 同一実行内でStage 4から再生成する。検証OKならStage 6（問題バンク登録）まで自動的に進む。
 *
 * Step 6（RAG）: Stage 3突入時（run_feedback_stage）に一度だけ教員手作りコーパス
 * （qbank_stackgen_examples）を検索し、結果を rag_examples_used に保存する。
 * Stage 4（run_xml_stage）はCAS検証リトライのたびに再検索せず、この保存結果を使い回す。
 *
 * 生成方式の選択（アップロード画面のチェックボックス、`track_traditional`/`track_kc`列）:
 *   - `track_traditional` が偽の場合、Stage 2（OCR）完了後は何もせず ocr_done で停止する
 *     （この系統の誤答例生成・XML生成・問題バンク登録は一切行わない）。
 *   - `track_kc` が真の場合、Stage 2（OCR）完了直後（`ocr_result`が揃ったタイミング）に、
 *     別系統のAdhoc Task（generate_stack_question_kc、KC起点の誤答例〜問題バンク登録）を
 *     自動でキューする。既存のkc_review.phpの手動開始ボタンは、この自動キューを選ばなかった
 *     旧ジョブに後からKC版を追加する用途として引き続き使える。
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

            $this->maybe_start_kc_track($jobid, $job);
            $this->maybe_start_norag_track($jobid, $job);

            if (empty($job->track_traditional)) {
                mtrace("[qbank_stackgen] Job {$jobid}: traditional track not selected, stopping after OCR.");
                return;
            }

            $this->run_feedback_stage($jobid, $problems);
            return;
        }

        if ($job->status === 'ocr_done') {
            if (empty($job->track_traditional)) {
                mtrace("[qbank_stackgen] Job {$jobid}: traditional track not selected, nothing to resume.");
                return;
            }

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

            // 教員がレビュー画面で問題文を修正していれば、Stage 4 のプロンプトに使う
            // 問題文（question_text / stack_question_text）をその内容で上書きする。
            foreach ($problems as $i => &$problem) {
                $questiontext = trim((string)($feedbackproblems[$i]['questiontext'] ?? ''));
                if ($questiontext !== '') {
                    $problem['question_text'] = $questiontext;
                    $problem['stack_question_text'] = $questiontext;
                }
            }
            unset($problem);

            // Step 6 RAG: Stage 3突入時に検索・保存済みの結果をそのまま再利用する（ここで再検索しない）。
            $ragresults = json_decode($job->rag_examples_used ?? '', true) ?: [];

            $this->run_xml_stage($jobid, $problems, $feedbackproblems, $ragresults);
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
     * `track_kc` が選択されていれば、別系統のAdhoc Task（generate_stack_question_kc）を
     * OCR完了直後にキューする。KC版は既存の`status`/`feedback_*`/`xml_result`等には触れず、
     * `kc_*`列だけを読み書きするため、この系統（従来版）の状態遷移とは独立して進行する。
     */
    private function maybe_start_kc_track(int $jobid, \stdClass $job): void {
        if (empty($job->track_kc)) {
            return;
        }

        $task = new generate_stack_question_kc();
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task, true);
        mtrace("[qbank_stackgen] Job {$jobid}: KC track selected, queued generate_stack_question_kc.");
    }

    /**
     * `track_norag` が選択されていれば、別系統のAdhoc Task（generate_stack_question_norag）を
     * OCR完了直後にキューする。同じ画像について従来版(RAGあり)・RAGなし版・KC版を並べて比較する
     * ための系統で、`norag_*`列だけを読み書きするため、この系統（従来版）の状態遷移とは独立して進行する。
     */
    private function maybe_start_norag_track(int $jobid, \stdClass $job): void {
        if (empty($job->track_norag)) {
            return;
        }

        $task = new generate_stack_question_norag();
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task, true);
        mtrace("[qbank_stackgen] Job {$jobid}: RAGなし比較トラック選択、queued generate_stack_question_norag.");
    }

    /**
     * Stage 3: 誤答例/フィードバック案を生成し、教員のレビュー待ちにする。
     *
     * Step 6 RAG: ジョブごとに1回だけ類似問題を検索し、結果を rag_examples_used に保存する
     * （CAS検証リトライのたびに再検索はせず、Stage 4（run_xml_stage）でもこの保存結果を再利用する）。
     */
    private function run_feedback_stage(int $jobid, array $problems): void {
        global $DB;

        try {
            if (empty($problems)) {
                throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                    'OCR result contains no problems');
            }

            $ragresults = $this->run_rag_search($jobid, $problems);

            $generator = new \qbank_stackgen\api\feedback_generator();
            $feedback  = $generator->run($problems, $ragresults);

            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'                => $jobid,
                'status'            => 'feedback_pending',
                'feedback_result'   => json_encode($feedback, JSON_UNESCAPED_UNICODE),
                'rag_examples_used' => json_encode($ragresults, JSON_UNESCAPED_UNICODE),
                'timemodified'      => time(),
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
     * Step 6 RAG: OCR結果の各問題文で教員手作りコーパス（qbank_stackgen_examples）を検索する。
     * 設定で無効化されている場合や埋め込みAPI呼び出しに失敗した場合は、パイプライン全体を
     * 止めずに空の検索結果を返す（RAGはあくまで精度向上のための追加要素であり必須ではないため）。
     *
     * @return array $problems と同じ順序の配列。各要素は rag_search::search() の結果（空配列もありうる）。
     */
    private function run_rag_search(int $jobid, array $problems): array {
        if (empty(get_config('qbank_stackgen', 'rag_enabled'))) {
            return array_fill(0, count($problems), []);
        }

        $topk = max(1, (int)(get_config('qbank_stackgen', 'rag_topk') ?: 3));
        $results = [];
        foreach ($problems as $i => $problem) {
            try {
                $querytext = $problem['stack_question_text'] ?? ($problem['question_text'] ?? '');
                $results[$i] = $querytext !== '' ? \qbank_stackgen\local\rag_search::search($querytext, $topk) : [];
            } catch (\Throwable $e) {
                mtrace("[qbank_stackgen] Job {$jobid}: RAG search failed for problem #" . ($i + 1)
                    . ", continuing without it: " . $e->getMessage());
                $results[$i] = [];
            }
        }
        return $results;
    }

    /**
     * Stage 4〜5: 確定済みの誤答例/フィードバックを組み込んで STACK XML（PRT付き）を生成し、
     * CAS検証（goemaxima）に通す。検証NGの場合は設定上限（max_retry）まで、直前の検証エラーを
     * LLMに伝えつつ同一タスク実行内で再生成する。教員の操作は待たない。
     *
     * @param array $ragresults  Step 6 RAG: run_feedback_stage() が検索・保存した結果（再検索しない）。
     */
    private function run_xml_stage(int $jobid, array $problems, array $feedbackproblems, array $ragresults = []): void {
        global $DB;

        $maxretry  = max(1, (int)(get_config('qbank_stackgen', 'max_retry') ?: 3));
        $validator = new \qbank_stackgen\api\maxima_validator();
        $lasterror = '';
        $count     = count($problems);

        for ($attempt = 1; $attempt <= $maxretry; $attempt++) {
            try {
                $generator = new \qbank_stackgen\api\llm_generator();
                $output    = $generator->run($problems, $feedbackproblems, $lasterror, $ragresults);
            } catch (\Throwable $e) {
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'           => $jobid,
                    'status'       => 'requires_manual_review',
                    'error_msg'    => 'Stage4 LLM error: ' . $e->getMessage(),
                    'timemodified' => time(),
                ]);
                mtrace("[qbank_stackgen] Job {$jobid}: LLM failed (attempt {$attempt}): " . $e->getMessage());
                return;
            }

            try {
                $result = $validator->validate($output['xml']);
            } catch (\Throwable $e) {
                // goemaximaとの通信エラーはコンテンツの問題ではないインフラ障害なので、
                // 再生成リトライはせず即座に手動レビューへ回す。
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'           => $jobid,
                    'status'       => 'requires_manual_review',
                    'xml_result'   => $output['xml'],
                    'llm_cot'      => $output['cot'],
                    'error_msg'    => 'Stage5 CAS validator error: ' . $e->getMessage(),
                    'timemodified' => time(),
                ]);
                mtrace("[qbank_stackgen] Job {$jobid}: CAS validator unreachable: " . $e->getMessage());
                return;
            }

            if ($result['valid']) {
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'           => $jobid,
                    'status'       => 'validated',
                    'xml_result'   => $output['xml'],
                    'llm_cot'      => $output['cot'],
                    'retry_count'  => $attempt - 1,
                    'error_msg'    => null,
                    'timemodified' => time(),
                ]);
                mtrace("[qbank_stackgen] Job {$jobid}: CAS validation OK on attempt {$attempt}"
                    . " ({$count} question(s)).");
                $this->run_import_stage($jobid);
                return;
            }

            $lasterror = implode(' / ', $result['errors']);
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'status'       => 'validation_failed',
                'xml_result'   => $output['xml'],
                'llm_cot'      => $output['cot'],
                'retry_count'  => $attempt,
                'error_msg'    => 'Stage5 CAS validation failed: ' . $lasterror,
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] Job {$jobid}: CAS validation failed (attempt {$attempt}/{$maxretry}): "
                . $lasterror);
        }

        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'           => $jobid,
            'status'       => 'requires_manual_review',
            'error_msg'    => "Stage5 CAS validation failed after {$maxretry} attempts: {$lasterror}",
            'timemodified' => time(),
        ]);
        mtrace("[qbank_stackgen] Job {$jobid}: CAS validation failed {$maxretry} times, requires manual review.");
    }

    /**
     * Stage 6: 確定済みXMLを問題バンクに直接登録する。
     */
    private function run_import_stage(int $jobid): void {
        global $DB;

        $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid], '*', MUST_EXIST);

        try {
            $result = \qbank_stackgen\question_importer::import($job);

            // 承認済み（問題バンク登録完了）になったので、一時保存していた元画像は不要になる。
            // CLAUDE.md記載の方針「image_b64はDBに一時保存のみ。承認/却下後に削除する」に従い、
            // ここで確実に削除する（xml_result等の生成結果は評価データとして残す）。
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'                    => $jobid,
                'status'                => 'approved',
                'question_ids'          => json_encode($result['questionids']),
                'question_category_id'  => $result['categoryid'],
                'image_b64'             => null,
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
