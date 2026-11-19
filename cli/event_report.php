<?php
/**
 * 所要時間の集計: qbank_stackgen_events のイベントログから、ジョブ×版ごとに、
 *   A1 機械の待ち時間（アップロード → 誤答例・フィードバック案ができるまで。OCR・Stage 3・cronの待ちを含む）
 *   C  手待ち時間（案ができてから、教員が確認画面を最初に開くまで）
 *   B  教員の作業時間（確認画面で実際に操作していた秒数。画面側で測定）
 *   A2 機械の待ち時間（確定 → 問題バンク登録、または手動確認要になるまで。リトライを含む）
 * を分けて表示する。--csv でCSV出力、--since で対象ジョブを絞る。
 *
 * さらにA1・A2を、cronの順番待ちと実処理に分けて近似する（task_start/task_endは記録していないため）。
 *   cronは1本で直列に処理するので、全ジョブ×全版の「タスク完了時刻」を1本の時系列に並べ、
 *     開始時刻 ≒ max(直前のタスクの完了時刻, そのタスクがキューに入った時刻)
 *     順番待ち = 開始 - キューに入った時刻、 実処理 = 完了 - 開始
 *   とみなす（キューに入った時刻は、前半=アップロード、後半=確定）。
 *   消したKC版の記録もeventsに残っているので、時系列の計算にだけ使う（集計対象は --tracks で選ぶ）。
 *   KC版のタスクを途中で止めた場合に備え、--split で「前半/後半」の境目を指定できる
 *   （省略時は、KC版の最後のタスク完了時刻）。前半と後半で順番待ちの条件が違うため、別々に平均する。
 *   ※ 実測ではなく近似。実行中にcronが止まっていた時間は実処理に混ざる。
 *   ただしタスクが task_start / task_end を記録しているジョブ（2026-11以降に処理したもの）は、
 *   それを使った実測値で上書きする（`計測` 列が 実測 / 近似 / 混在）。
 *
 * コスト: gemini_http_client が記録した api_call（トークン数）を、ジョブ×版ごとに合計する。
 *   --price-in / --price-out（USD per 1M tokens。出力には思考トークンを含める）を渡すと金額も出す。
 *   単価は最新の料金表で確認して渡すこと（コードには埋め込まない）。埋め込みAPIはトークン数が
 *   返らないため金額に含めず、呼び出し回数だけ出す。OCRは版に属さないので、traditional 側に計上される
 *   （traditional版を選ばなかったジョブでは、OCRもその版の記録に含まれる）。
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
    ['since' => '', 'jobs' => '', 'csv' => '', 'tracks' => 'traditional,norag', 'split' => '', 'price-in' => '', 'price-out' => '', 'help' => false],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "Usage: php event_report.php [--since=YYYY-MM-DD[ HH:MM]] [--jobs=98,99] [--csv=/path/out.csv]\n"
        . "       [--tracks=traditional,norag,kc]（集計対象の版。既定: traditional,norag） [--split=YYYY-MM-DD HH:MM]（前半/後半の境目。UTC）\n"
        . "       [--price-in=USD/1Mトークン] [--price-out=USD/1Mトークン]（金額の計算用）\n";
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

/**
 * 全ジョブ×全版のタスク完了時刻から、1本の直列時系列を作り、各タスクの順番待ち・実処理を近似する。
 * 戻り値: [jobid][track][phase] => ['wait' => 秒, 'proc' => 秒, 'end' => 完了時刻]（phase: 1=案の生成, 2=XML生成〜登録）
 */
function qbank_stackgen_timeline(): array {
    global $DB;
    $all = [];
    foreach ($DB->get_records('qbank_stackgen_events', null, 'eventtime ASC, id ASC') as $e) {
        $all[$e->jobid][] = $e;
    }
    $tasks = [];
    foreach ($all as $jobid => $events) {
        $created = qbank_stackgen_first($events, '', 'created');
        foreach (['traditional', 'norag', 'kc'] as $track) {
            $fb = qbank_stackgen_first($events, $track, 'status', 'feedback_pending');
            if ($fb === null) {
                continue;
            }
            // 後から版を足したジョブ（cli/add_tracks.php）は、追加した時刻がキューに入った時刻。
            $enq1 = qbank_stackgen_first($events, $track, 'track_added') ?? $created ?? $fb;
            $tasks[] = ['job' => $jobid, 'track' => $track, 'phase' => 1, 'enq' => $enq1, 'end' => $fb];
            $confirmed = qbank_stackgen_first($events, $track, 'confirmed');
            $end = qbank_stackgen_first($events, $track, 'status', 'approved')
                ?? qbank_stackgen_first($events, $track, 'status', 'requires_manual_review');
            if ($confirmed !== null && $end !== null) {
                $tasks[] = ['job' => $jobid, 'track' => $track, 'phase' => 2, 'enq' => $confirmed, 'end' => $end];
            }
        }
    }
    usort($tasks, function ($a, $b) {
        return $a['end'] <=> $b['end'];
    });
    $out = [];
    $prevend = null;
    foreach ($tasks as $t) {
        $start = $prevend === null ? $t['enq'] : max($prevend, $t['enq']);
        $start = min($start, $t['end']);
        $out[$t['job']][$t['track']][$t['phase']] = [
            'wait' => $start - $t['enq'], 'proc' => $t['end'] - $start, 'end' => $t['end'],
        ];
        $prevend = $t['end'];
    }
    return $out;
}

$timeline = qbank_stackgen_timeline();
$tracks = array_values(array_filter(array_map('trim', explode(',', $options['tracks']))));

// 前半/後半の境目: 指定が無ければ、KC版のタスクが最後に完了した時刻。
$split = null;
if ($options['split'] !== '') {
    $split = strtotime($options['split']);
} else {
    foreach ($timeline as $bytrack) {
        foreach ($bytrack['kc'] ?? [] as $ph) {
            $split = max($split ?? 0, $ph['end']);
        }
    }
}

/** その版の task_start/task_end を順に組にして、時刻 $t を含むもの（開始<=t<=終了）を返す。 */
function qbank_stackgen_task_pair(array $events, string $track, float $t): ?array {
    $start = null;
    foreach ($events as $e) {
        if ($e->track !== $track) {
            continue;
        }
        if ($e->event === 'task_start') {
            $start = (float)$e->eventtime;
        } else if ($e->event === 'task_end' && $start !== null) {
            if ($start <= $t && $t <= (float)$e->eventtime) {
                return [$start, (float)$e->eventtime];
            }
            $start = null;
        }
    }
    return null;
}

$price_in  = $options['price-in'] !== '' ? (float)$options['price-in'] : null;
$price_out = $options['price-out'] !== '' ? (float)$options['price-out'] : null;

$rows = [];
foreach ($jobids as $jobid) {
    $events = array_values($DB->get_records('qbank_stackgen_events', ['jobid' => $jobid], 'eventtime ASC, id ASC'));
    $created = qbank_stackgen_first($events, '', 'created');
    $job = $DB->get_record('qbank_stackgen_jobs', ['id' => $jobid], 'id, sourcefilename, ocr_result');
    $ocr = json_decode($job->ocr_result ?? '', true);
    $problemlist = is_array($ocr) ? ($ocr['problems'] ?? []) : [];
    $nprob = count($problemlist);
    $qtypes = implode('/', array_unique(array_filter(array_column($problemlist, 'question_type'))));
    foreach ($tracks as $track) {
        $fbready = qbank_stackgen_first($events, $track, 'status', 'feedback_pending');
        if ($fbready === null) {
            continue;
        }
        $open      = qbank_stackgen_first($events, $track, 'review_open');
        $confirmed = qbank_stackgen_first($events, $track, 'confirmed');
        // cli/confirm_all.php で自動確定した版は、人が確認していないので C・B を出さない。
        $auto      = qbank_stackgen_first($events, $track, 'auto_confirm') !== null;
        if ($auto) {
            $open = null;
        }
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
        $p1 = $timeline[$jobid][$track][1] ?? null;
        $p2 = $timeline[$jobid][$track][2] ?? null;
        // task_start/task_end があるフェーズは、近似ではなく実測で上書きする。
        $measured = 0;
        $phases = 0;
        foreach ([1 => [$fbready, qbank_stackgen_first($events, $track, 'track_added') ?? $created], 2 => [$end, $confirmed]] as $ph => [$endt, $enq]) {
            if ($endt === null || $enq === null) {
                continue;
            }
            $phases++;
            $pair = qbank_stackgen_task_pair($events, $track, $endt);
            if ($pair === null) {
                continue;
            }
            $measured++;
            $v = ['wait' => max(0.0, $pair[0] - $enq), 'proc' => $pair[1] - $pair[0], 'end' => $pair[1]];
            if ($ph === 1) {
                $p1 = $v;
            } else {
                $p2 = $v;
            }
        }
        $calls = 0;
        $embedcalls = 0;
        $intok = 0;
        $outtok = 0;
        $thinktok = 0;
        $apisec = 0.0;
        foreach ($events as $e) {
            if ($e->track !== $track || $e->event !== 'api_call') {
                continue;
            }
            $d = json_decode((string)$e->detail, true) ?: [];
            $calls++;
            $apisec += (float)($d['sec'] ?? 0);
            if (($d['in'] ?? null) === null) {
                $embedcalls++;
                continue;
            }
            $intok += (int)$d['in'];
            $outtok += (int)($d['out'] ?? 0);
            $thinktok += (int)($d['think'] ?? 0);
        }
        $cost = ($calls && $price_in !== null && $price_out !== null)
            ? $intok * $price_in / 1e6 + ($outtok + $thinktok) * $price_out / 1e6 : null;
        $procsum = ($p1['proc'] ?? 0) + ($p2['proc'] ?? 0);
        $last = $p2['end'] ?? ($p1['end'] ?? null);
        $rows[] = [
            'job'     => $jobid,
            'file'    => $job->sourcefilename ?? '',
            'track'   => $track,
            'A1'      => $created !== null ? $fbready - (qbank_stackgen_first($events, $track, 'track_added') ?? $created) : null,
            'A1待ち(近似)' => $p1['wait'] ?? null,
            'A1処理(近似)' => $p1['proc'] ?? null,
            'C'       => $open !== null ? $open - $fbready : null,
            'B'       => ($confirmed !== null && !$auto) ? $active : null,
            'review_wall' => ($open !== null && $confirmed !== null) ? $confirmed - $open : null,
            'A2'      => ($confirmed !== null && $end !== null) ? $end - $confirmed : null,
            'A2待ち(近似)' => $p2['wait'] ?? null,
            'A2処理(近似)' => $p2['proc'] ?? null,
            'retries' => $retries,
            'result'  => $approved !== null ? 'approved' : ($manual !== null ? 'manual_review' : 'running'),
            'period'  => ($split === null || $last === null) ? 'all' : ($last <= $split ? '前半' : '後半'),
            '計測'     => $phases === 0 ? '-' : ($measured === $phases ? '実測' : ($measured === 0 ? '近似' : '混在')),
            'auto_confirm' => $auto ? 1 : 0,
            'nprob'   => $nprob,
            'qtype'   => $qtypes,
            'api_calls' => $calls,
            'embed_calls' => $embedcalls,
            'in_tok'  => $calls ? $intok : null,
            'out_tok' => $calls ? $outtok : null,
            'think_tok' => $calls ? $thinktok : null,
            'api_sec' => $calls ? $apisec : null,
            'cost_usd' => $cost,
            'proc_per_q' => $nprob ? $procsum / $nprob : null,
            'cost_per_q' => ($cost !== null && $nprob) ? $cost / $nprob : null,
        ];
    }
}
if (!$rows) {
    echo "対象の版（--tracks={$options['tracks']}）のデータがありません。\n";
    exit(0);
}

$fmt = function ($v) {
    return $v === null ? '-' : sprintf('%.1f', $v);
};
echo "※ 「待ち」「処理」は、cronが直列に処理する前提の近似（実測ではない）。\n";
if ($split !== null) {
    echo "※ 前半/後半の境目: " . gmdate('Y-m-d H:i:s', (int)$split) . " UTC（以前=前半。KC版のタスクが動いていた期間）\n";
}
printf("%-4s %-26s %-11s %8s %8s %8s %7s %7s %8s %8s %8s %8s %4s %-6s %s\n",
    'job', 'file', 'track', 'A1', 'A1待ち', 'A1処理', 'C', 'B', 'A2', 'A2待ち', 'A2処理', '画面滞在', '再試', '期間', '結果');
foreach ($rows as $r) {
    printf("%-4d %-26s %-11s %8s %8s %8s %7s %7s %8s %8s %8s %8s %4d %-6s %s\n",
        $r['job'], mb_strimwidth($r['file'], 0, 26, '…'), $r['track'],
        $fmt($r['A1']), $fmt($r['A1待ち(近似)']), $fmt($r['A1処理(近似)']), $fmt($r['C']), $fmt($r['B']),
        $fmt($r['A2']), $fmt($r['A2待ち(近似)']), $fmt($r['A2処理(近似)']), $fmt($r['review_wall']),
        $r['retries'], $r['period'], $r['result']);
}

echo "\n問題数・API使用量（ジョブ×版）\n";
printf("%-4s %-11s %-12s %5s %6s %9s %9s %9s %8s %9s %9s %10s\n",
    'job', 'track', 'type', '問題数', '呼出', '入力tok', '出力tok', '思考tok', 'API秒', '処理/問', '$合計', '$/問');
foreach ($rows as $r) {
    printf("%-4d %-11s %-12s %5d %6s %9s %9s %9s %8s %9s %9s %10s\n",
        $r['job'], $r['track'], mb_strimwidth($r['qtype'], 0, 12), $r['nprob'], $r['api_calls'],
        $r['in_tok'] ?? '-', $r['out_tok'] ?? '-', $r['think_tok'] ?? '-', $fmt($r['api_sec']),
        $fmt($r['proc_per_q']), $r['cost_usd'] === null ? '-' : sprintf('%.4f', $r['cost_usd']),
        $r['cost_per_q'] === null ? '-' : sprintf('%.5f', $r['cost_per_q']));
}
if ($price_in === null || $price_out === null) {
    echo "（金額は --price-in / --price-out を指定すると表示されます。トークン数が '-' のジョブは、api_call の記録がありません）\n";
}

$summary = function (string $title, array $set) use ($fmt) {
    if (!$set) {
        return;
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
    printf("%-18s 件数=%d approved=%d(%.0f%%) A1=%s[待%s/処理%s] C=%s B=%s A2=%s[待%s/処理%s] 再試=%s 問題数=%s 入力tok=%s 出力tok=%s $=%s $/問=%s 処理/問=%s\n",
        $title, count($set), $approved, 100 * $approved / count($set),
        $avg('A1'), $avg('A1待ち(近似)'), $avg('A1処理(近似)'), $avg('C'), $avg('B'),
        $avg('A2'), $avg('A2待ち(近似)'), $avg('A2処理(近似)'), $avg('retries'),
        $avg('nprob'), $avg('in_tok'), $avg('out_tok'), $avg('cost_usd'), $avg('cost_per_q'), $avg('proc_per_q'));
};
echo "\n版ごとの平均（秒）と到達率\n";
foreach ($tracks as $track) {
    $summary($track, array_filter($rows, function ($r) use ($track) {
        return $r['track'] === $track;
    }));
}
if ($split !== null) {
    echo "\n版×期間ごとの平均（前半=KC版と並走、後半=並走なし。待ちは前半のほうが長くなる）\n";
    foreach ($tracks as $track) {
        foreach (['前半', '後半'] as $period) {
            $summary("{$track}/{$period}", array_filter($rows, function ($r) use ($track, $period) {
                return $r['track'] === $track && $r['period'] === $period;
            }));
        }
    }
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
