<?php
/**
 * 所要時間の集計: qbank_stackgen_events のイベントログから、ジョブ×版ごとに、
 *   A1 機械の待ち時間（アップロード → 誤答例・フィードバック案ができるまで。OCR・Stage 3・cronの待ちを含む）
 *   C  手待ち時間（案ができてから、教員が確認画面を最初に開くまで）
 *   B  教員の作業時間（確認画面で実際に操作していた秒数。画面側で測定）
 *   A2 機械の待ち時間（確定 → 問題バンク登録、または手動確認要になるまで。リトライを含む）
 * を分けて表示する。--csv でCSV出力、--since で対象ジョブを絞る。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/event_report.php --since=2026-11-01
 *   ... --csv=/tmp/times.csv
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['since' => '', 'jobs' => '', 'csv' => '', 'help' => false],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "Usage: php event_report.php [--since=YYYY-MM-DD[ HH:MM]] [--jobs=98,99] [--csv=/path/out.csv]\n";
    exit(0);
}

$params = [];
$where = "event = 'created'";
if ($options['since'] !== '') {
    $where .= ' AND eventtime >= :since';
    $params['since'] = strtotime($options['since']);
}
$jobids = $DB->get_fieldset_select('qbank_stackgen_events', 'DISTINCT jobid', $where, $params);
if ($options['jobs'] !== '') {
    $jobids = array_values(array_intersect($jobids, array_map('intval', explode(',', $options['jobs']))));
}
sort($jobids);
if (!$jobids) {
    echo "対象のジョブがありません（イベントログが無い、または絞り込みで0件）。\n";
    exit(0);
}

/** 版ごとの、そのイベント（status の値指定可）が最初に起きた時刻。 */
function qbank_stackgen_first(array $events, string $track, string $event, ?string $detail = null): ?float {
    foreach ($events as $e) {
        if ($e->track === $track && $e->event === $event && ($detail === null || $e->detail === $detail)) {
            return (float)$e->eventtime;
        }
    }
    return null;
}

$rows = [];
foreach ($jobids as $jobid) {
    $events = array_values($DB->get_records('qbank_stackgen_events', ['jobid' => $jobid], 'eventtime ASC, id ASC'));
    $created = qbank_stackgen_first($events, '', 'created');
    $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid], 'id, sourcefilename');
    foreach (['traditional', 'norag', 'kc'] as $track) {
        $fbready = qbank_stackgen_first($events, $track, 'status', 'feedback_pending');
        if ($fbready === null) {
            continue;
        }
        $open      = qbank_stackgen_first($events, $track, 'review_open');
        $confirmed = qbank_stackgen_first($events, $track, 'confirmed');
        $approved  = qbank_stackgen_first($events, $track, 'status', 'approved');
        $manual    = qbank_stackgen_first($events, $track, 'status', 'requires_manual_review');
        $end       = $approved ?? $manual;
        $active    = 0.0;
        $retries   = 0;
        foreach ($events as $e) {
            if ($e->track !== $track) {
                continue;
            }
            if ($e->event === 'review_active') {
                $active += (float)$e->detail;
            } else if ($e->event === 'retry') {
                $retries = max($retries, (int)$e->detail);
            }
        }
        $rows[] = [
            'job'     => $jobid,
            'file'    => $job->sourcefilename ?? '',
            'track'   => $track,
            'A1'      => $created !== null ? $fbready - $created : null,
            'C'       => $open !== null ? $open - $fbready : null,
            'B'       => $confirmed !== null ? $active : null,
            'review_wall' => ($open !== null && $confirmed !== null) ? $confirmed - $open : null,
            'A2'      => ($confirmed !== null && $end !== null) ? $end - $confirmed : null,
            'retries' => $retries,
            'result'  => $approved !== null ? 'approved' : ($manual !== null ? 'manual_review' : 'running'),
        ];
    }
}

$fmt = function ($v) {
    return $v === null ? '-' : sprintf('%.1f', $v);
};
printf("%-4s %-34s %-12s %9s %9s %9s %9s %9s %4s  %s\n",
    'job', 'file', 'track', 'A1機械(s)', 'C手待ち', 'B操作(s)', '画面滞在', 'A2機械(s)', '再試', '結果');
foreach ($rows as $r) {
    printf("%-4d %-34s %-12s %9s %9s %9s %9s %9s %4d  %s\n",
        $r['job'], mb_strimwidth($r['file'], 0, 34, '…'), $r['track'],
        $fmt($r['A1']), $fmt($r['C']), $fmt($r['B']), $fmt($r['review_wall']), $fmt($r['A2']),
        $r['retries'], $r['result']);
}

echo "\n版ごとの平均（秒）と到達率\n";
foreach (['traditional', 'norag', 'kc'] as $track) {
    $set = array_filter($rows, function ($r) use ($track) {
        return $r['track'] === $track;
    });
    if (!$set) {
        continue;
    }
    $avg = function (string $key) use ($set, $fmt) {
        $vals = array_filter(array_column($set, $key), function ($v) {
            return $v !== null;
        });
        return $fmt($vals ? array_sum($vals) / count($vals) : null);
    };
    $approved = count(array_filter($set, function ($r) {
        return $r['result'] === 'approved';
    }));
    printf("%-12s 件数=%d  approved=%d (%.0f%%)  A1=%s  C=%s  B=%s  A2=%s  再試平均=%s\n",
        $track, count($set), $approved, 100 * $approved / count($set),
        $avg('A1'), $avg('C'), $avg('B'), $avg('A2'), $avg('retries'));
}

if ($options['csv'] !== '') {
    $fh = fopen($options['csv'], 'w');
    fputcsv($fh, array_keys($rows[0]));
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);
    echo "\nCSV を書き出しました: {$options['csv']}\n";
}
