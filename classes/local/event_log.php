<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * 所要時間の計測用イベントログ。
 *
 * ジョブの状態遷移（status / norag_status / kc_status）、リトライ回数、教員による確定を
 * qbank_stackgen_events に時刻付きで残す。確認画面を開いた時刻と、実際に操作していた秒数は
 * 画面側（AMD JS）から log_event.php / 各 *_confirm.php 経由で記録する。
 *
 * 機械の待ち時間（アップロード→誤答例案、確定→登録）、教員の作業時間（確認画面で操作していた秒数）、
 * その間の手待ち時間（誤答例案ができてから確認画面を開くまで）を、後から cli/event_report.php で分けて集計する。
 */
class event_log {
    /** jobs の列と、イベントの版の対応。 */
    private const STATUS_COLUMNS = [
        'status'       => 'traditional',
        'norag_status' => 'norag',
        'kc_status'    => 'kc',
    ];
    private const RETRY_COLUMNS = [
        'retry_count'       => 'traditional',
        'norag_retry_count' => 'norag',
        'kc_retry_count'    => 'kc',
    ];
    private const CONFIRMED_COLUMNS = [
        'feedback_confirmed'       => 'traditional',
        'norag_feedback_confirmed' => 'norag',
        'kc_feedback_confirmed'    => 'kc',
    ];

    /**
     * イベントを1件記録する。
     */
    public static function log(int $jobid, string $track, string $event, ?string $detail = null): void {
        global $DB;
        $DB->insert_record('qbank_stackgen_events', (object)[
            'jobid'     => $jobid,
            'track'     => $track,
            'event'     => $event,
            'detail'    => $detail,
            'eventtime' => round(microtime(true), 3),
        ]);
    }

    /**
     * $DB->update_record('qbank_stackgen_jobs', ...) の代わりに呼ぶ。更新の前後を比べて、状態・リトライ回数・
     * 教員の確定が変わっていればイベントとして残す。戻り値は update_record と同じ。
     *
     * @param \stdClass $record id を含む更新内容
     */
    public static function update_job(\stdClass $record): bool {
        global $DB;

        $columns = array_keys(self::STATUS_COLUMNS + self::RETRY_COLUMNS + self::CONFIRMED_COLUMNS);
        $old = $DB->get_record('qbank_stackgen_jobs', ['id' => $record->id], implode(',', $columns));

        $result = $DB->update_record('qbank_stackgen_jobs', $record);

        if ($old) {
            $now = round(microtime(true), 3);
            $rows = [];
            foreach (self::STATUS_COLUMNS as $col => $track) {
                if (property_exists($record, $col) && (string)$record->$col !== (string)$old->$col) {
                    $rows[] = ['track' => $track, 'event' => 'status', 'detail' => (string)$record->$col];
                }
            }
            foreach (self::RETRY_COLUMNS as $col => $track) {
                if (property_exists($record, $col) && (int)$record->$col !== (int)$old->$col) {
                    $rows[] = ['track' => $track, 'event' => 'retry', 'detail' => (string)(int)$record->$col];
                }
            }
            foreach (self::CONFIRMED_COLUMNS as $col => $track) {
                if (!empty($record->$col ?? null) && empty($old->$col)) {
                    $rows[] = ['track' => $track, 'event' => 'confirmed', 'detail' => null];
                }
            }
            foreach ($rows as $row) {
                $DB->insert_record('qbank_stackgen_events', (object)($row + [
                    'jobid' => $record->id, 'eventtime' => $now,
                ]));
            }
        }

        return $result;
    }
}
