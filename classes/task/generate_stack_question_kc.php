<?php
namespace qbank_stackgen\task;

/**
 * Adhoc Task: KC診断版（別ページ）のSTACK問題生成パイプライン（Stage 3〜6）。
 *
 * 既存の generate_stack_question タスク（既存ページ、自由記述の誤答例）とは
 * 完全に独立した系統。Stage 2（OCR）は行わず、同じジョブ（qbank_stackgen_jobs）の
 * 既存 ocr_result をそのまま再利用する。進捗は kc_status 列（既存の status 列とは別）で
 * 管理し、既存ページ側の状態には一切書き込まない。
 *
 * kc_status 遷移:
 *   ''（未着手）        → Stage 3（KC起点の誤答例生成）→ feedback_pending で停止
 *   feedback_pending    → kc_feedback_confirmed が設定されていれば Stage 4（LLM XML生成、
 *                          既存の llm_generator をそのまま再利用）→ Stage 5（CAS検証）を実行
 *   llm_done            → Stage 6（問題バンクへの直接登録）のみ再実行（XML再生成しない）
 *
 * Stage 4（XML生成）・Stage 5（CAS検証、goemaxima）・Stage 6（問題バンク登録）は
 * 既存の llm_generator / maxima_validator / question_importer をそのまま再利用する
 * （これらはDB列に依存しない純粋なAPI/ロジッククラスのため、系統を分けても共有できる）。
 */
class generate_stack_question_kc extends \core\task\adhoc_task {

    public function get_name(): string {
        return get_string('pluginname', 'qbank_stackgen') . ' (KC)';
    }

    public function execute(): void {
        global $DB;

        $data  = $this->get_custom_data();
        $jobid = $data->jobid ?? null;

        if (!$jobid) {
            mtrace('[qbank_stackgen] generate_stack_question_kc: jobid missing, skipping.');
            return;
        }

        $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid]);
        if (!$job) {
            mtrace("[qbank_stackgen] KC Job {$jobid} not found.");
            return;
        }

        mtrace("[qbank_stackgen] KC Job {$jobid}: starting (kc_status={$job->kc_status}).");

        if (empty($job->ocr_result)) {
            mtrace("[qbank_stackgen] KC Job {$jobid}: no ocr_result yet, skipping (original track hasn't OCR'd this job).");
            return;
        }

        $ocr_data = json_decode($job->ocr_result, true);
        $problems = isset($ocr_data['problems']) ? $ocr_data['problems'] : [$ocr_data];

        if ($job->kc_status === '' || $job->kc_status === null) {
            // 既存track（generate_stack_question）がStage3突入時に検索・保存済みならそれを再利用する。
            // 未検索（既存trackが未着手、またはRAG無効）ならRAG無しで進める。
            $ragresults = json_decode($job->rag_examples_used ?? '', true) ?: [];
            $this->run_feedback_stage($jobid, $problems, $ragresults);
            return;
        }

        if ($job->kc_status === 'feedback_pending') {
            if (empty($job->kc_feedback_confirmed)) {
                mtrace("[qbank_stackgen] KC Job {$jobid}: feedback not confirmed yet, waiting for teacher review.");
                return;
            }

            $confirmed = json_decode($job->kc_feedback_confirmed, true);
            $feedbackproblems = $confirmed['problems'] ?? [];

            foreach ($problems as $i => &$problem) {
                $questiontext = trim((string)($feedbackproblems[$i]['questiontext'] ?? ''));
                if ($questiontext !== '') {
                    $problem['question_text'] = $questiontext;
                    $problem['stack_question_text'] = $questiontext;
                }
            }
            unset($problem);

            // 既存track（run_rag_search）が既に検索・保存していればそれを再利用する。未検索ならRAG無しで進める
            // （KC診断版のためだけに追加の埋め込みAPI呼び出しは行わない）。
            $ragresults = json_decode($job->rag_examples_used ?? '', true) ?: [];

            $this->run_xml_stage($jobid, $problems, $feedbackproblems, $ragresults);
            return;
        }

        if ($job->kc_status === 'llm_done') {
            mtrace("[qbank_stackgen] KC Job {$jobid}: resuming from llm_done (import only).");
            $this->run_import_stage($jobid);
            return;
        }

        mtrace("[qbank_stackgen] KC Job {$jobid}: unexpected kc_status={$job->kc_status}, skipping.");
    }

    /**
     * Stage 3: KC起点の誤答例を生成し、教員のレビュー待ちにする。
     */
    private function run_feedback_stage(int $jobid, array $problems, array $ragresults = []): void {
        global $DB;

        try {
            if (empty($problems)) {
                throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                    'OCR result contains no problems');
            }

            if (empty($ragresults)) {
                $ragresults = array_fill(0, count($problems), []);
            }

            $generator = new \qbank_stackgen\api\kc_feedback_generator();
            $feedback  = $generator->run($problems, $ragresults);

            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'                => $jobid,
                'kc_status'         => 'feedback_pending',
                'kc_feedback_result' => json_encode($feedback, JSON_UNESCAPED_UNICODE),
                'timemodified'      => time(),
            ]);
            mtrace("[qbank_stackgen] KC Job {$jobid}: KC feedback draft generated, awaiting teacher review.");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'kc_status'    => 'requires_manual_review',
                'kc_error_msg' => 'KC Stage3 feedback error: ' . $e->getMessage(),
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] KC Job {$jobid}: KC feedback generation failed: " . $e->getMessage());
        }
    }

    /**
     * Stage 4〜5: 確定済みのKC起点誤答例を組み込んで STACK XML（PRT付き）を生成し、
     * CAS検証（goemaxima）に通す。既存の llm_generator / maxima_validator をそのまま再利用する。
     */
    private function run_xml_stage(int $jobid, array $problems, array $feedbackproblems, array $ragresults = []): void {
        global $DB;

        $maxretry = max(1, (int)(get_config('qbank_stackgen', 'max_retry') ?: 3));
        $apikey   = get_config('qbank_stackgen', 'google_api_key');
        if (empty($apikey)) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'kc_status'    => 'requires_manual_review',
                'kc_error_msg' => 'KC Stage4 LLM error: google_api_key が設定されていません',
                'timemodified' => time(),
            ]);
            return;
        }

        $validator = new \qbank_stackgen\api\maxima_validator();
        $generator = new \qbank_stackgen\api\llm_generator();
        $count     = count($problems);

        // 1問ごとに「確定済み（CAS検証OK）のXML」を保持し、リトライのたびに全問ゼロからGeminiへ
        // 再生成させるのではなく、まだ不合格の問題だけを再生成・再検証する（失敗していない問題を
        // 無駄にAPI呼び出し・CAS検証し直さないことで、複数問ジョブのリトライコストを大幅に削減する）。
        $confirmedxml = array_fill(0, $count, null);
        $debugxml     = array_fill(0, $count, null); // 不合格でも直近の生成結果をデバッグ表示用に保持
        $cots         = array_fill(0, $count, '');
        $problemerror = array_fill(0, $count, ''); // 問題ごとの直近のエラー（再生成時にLLMへ伝える）
        $pending      = range(0, $count - 1);
        $lasterror    = '';
        $laststage    = '';

        for ($attempt = 1; $attempt <= $maxretry; $attempt++) {
            foreach ($pending as $i) {
                $wronganswers = $feedbackproblems[$i]['wronganswers'] ?? [];
                $name         = $feedbackproblems[$i]['name'] ?? '';
                try {
                    [$xml, $cot] = $generator->generate_one(
                        $apikey, $problems[$i], $i + 1, $wronganswers, $name,
                        $problemerror[$i], $ragresults[$i] ?? [], 'independent');
                    $debugxml[$i] = $xml;
                    $cots[$i]     = $cot;
                } catch (\Throwable $e) {
                    $laststage = 'Stage4 LLM error';
                    $lasterror = "問題{$i}: " . $e->getMessage();
                    $debugxml[$i] = null;
                    // この問題は生成自体に失敗したので、このattempt内では検証段階に進めない
                    // （$debugxml[$i]===nullのまま次のpending判定でリトライ対象に残る）。
                }
            }

            $newpending = [];
            foreach ($pending as $i) {
                if ($debugxml[$i] === null) {
                    $newpending[] = $i;
                    continue;
                }
                try {
                    $result = $validator->validate_one($debugxml[$i], $i + 1);
                } catch (\Throwable $e) {
                    $laststage = 'Stage5 CAS validator error';
                    $lasterror = "問題" . ($i + 1) . ": " . $e->getMessage();
                    $problemerror[$i] = $lasterror;
                    $newpending[] = $i;
                    continue;
                }
                if ($result['valid']) {
                    $confirmedxml[$i] = $debugxml[$i];
                    continue;
                }
                $laststage = 'Stage5 CAS validation failed';
                $lasterror = implode(' / ', $result['errors']);
                $problemerror[$i] = $lasterror;
                $newpending[] = $i;
            }

            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'             => $jobid,
                'kc_xml_result'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n"
                    . implode("\n\n", array_filter(array_map(
                        fn($i) => $confirmedxml[$i] ?? $debugxml[$i], range(0, $count - 1))))
                    . "\n</quiz>",
                'kc_llm_cot'     => implode("\n\n", array_filter($cots)),
                'kc_retry_count' => $attempt,
                'kc_error_msg'   => empty($newpending) ? null
                    : "KC {$laststage} (attempt {$attempt}/{$maxretry}, 残り" . count($newpending) . "/{$count}問): {$lasterror}",
                'timemodified'   => time(),
            ]);

            if (empty($newpending)) {
                $DB->update_record('qbank_stackgen_jobs', (object)[
                    'id'             => $jobid,
                    'kc_status'      => 'llm_done',
                    'timemodified'   => time(),
                ]);
                mtrace("[qbank_stackgen] KC Job {$jobid}: CAS validation OK on attempt {$attempt}"
                    . " ({$count} question(s)).");
                $this->run_import_stage($jobid);
                return;
            }

            mtrace("[qbank_stackgen] KC Job {$jobid}: CAS validation failed (attempt {$attempt}/{$maxretry}), "
                . "残り" . count($newpending) . "/{$count}問: " . $lasterror);
            $pending = $newpending;
        }

        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'           => $jobid,
            'kc_status'    => 'requires_manual_review',
            'kc_error_msg' => "KC {$laststage} after {$maxretry} attempts (未解決" . count($pending) . "/{$count}問): {$lasterror}",
            'timemodified' => time(),
        ]);
        mtrace("[qbank_stackgen] KC Job {$jobid}: CAS validation failed {$maxretry} times, requires manual review.");
    }

    /**
     * Stage 6: 確定済みXMLを問題バンクに直接登録する。既存の question_importer をそのまま再利用する
     * （xml_result/courseidしか見ないため、kc_xml_resultを積んだ一時オブジェクトを渡すだけでよい）。
     */
    private function run_import_stage(int $jobid): void {
        global $DB;

        $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid], '*', MUST_EXIST);

        try {
            $importjob = (object)[
                'xml_result' => $job->kc_xml_result,
                'courseid'   => $job->courseid,
            ];
            $result = \qbank_stackgen\question_importer::import(
                $importjob, \qbank_stackgen\question_importer::CATEGORY_MARKER_KC);

            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'                       => $jobid,
                'kc_status'                => 'approved',
                'kc_question_ids'          => json_encode($result['questionids']),
                'kc_question_category_id'  => $result['categoryid'],
                'kc_error_msg'             => null,
                'timemodified'             => time(),
            ]);
            mtrace("[qbank_stackgen] KC Job {$jobid}: imported into question bank ("
                . count($result['questionids']) . " question(s)).");

        } catch (\Throwable $e) {
            $DB->update_record('qbank_stackgen_jobs', (object)[
                'id'           => $jobid,
                'kc_status'    => 'requires_manual_review',
                'kc_error_msg' => 'KC Stage6 import error: ' . $e->getMessage(),
                'timemodified' => time(),
            ]);
            mtrace("[qbank_stackgen] KC Job {$jobid}: import failed: " . $e->getMessage());
        }
    }
}
