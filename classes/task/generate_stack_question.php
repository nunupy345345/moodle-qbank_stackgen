<?php
namespace qbank_stackgen\task;

/**
 * Adhoc Task: STACK問題生成パイプライン（Stage 2〜4）
 *
 * Step 0 ではスタブのみ。Stage 2〜4 の実装は Step 1〜3 で追加する。
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

        // --- Stage 2〜4 の実装は Step 1〜3 で追加 ---
        // ここではスタブとして requires_manual_review に遷移させる。

        $DB->update_record('qbank_stackgen_jobs', (object)[
            'id'           => $jobid,
            'status'       => 'requires_manual_review',
            'error_msg'    => 'Stub: Stage 2-4 not yet implemented.',
            'timemodified' => time(),
        ]);

        mtrace("[qbank_stackgen] Job {$jobid}: status set to requires_manual_review (stub).");
    }
}
