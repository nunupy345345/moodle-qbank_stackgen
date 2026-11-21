<?php
/**
 * テスト・計測の自動化用: その時点で教員の確認待ち（*_feedback_pending かつ未確定）の系統を、
 * AIの提案をそのまま確定して、続きのタスク（XML生成→CAS検証→問題バンク登録）をキューする。
 *
 * 従来版（RAGあり）・RAGなし版・KC版のすべてを対象にする。確定の中身は review.php などが保存する形式
 * （name / questiontext / wronganswers）に揃え、問題名・問題文は空で持たせる＝LLM生成のものを使う
 * （cli/survey_confirm.php と同じ扱い。あちらはアンケート用の問題だけが対象で、こちらは絞り込みなし）。
 *
 * 教員が実際に確認したわけではないので、events に 'auto_confirm' を残す。cli/event_report.php は、
 * この印のある版の「手待ち(C)」「操作時間(B)」を空にする（0秒の確認として平均に混ぜない）。
 *
 * 何度実行してもよい（確定済みの系統は飛ばす）。実行した時点で確認待ちのものだけが対象で、
 * その後に確認待ちになるものは、もう一度実行する。
 *
 * 実行例:
 *   docker exec stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/confirm_all.php --dry-run
 *   ... confirm_all.php --courseid=2 --tracks=traditional,norag --jobs=105,106
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['courseid' => 0, 'jobs' => '', 'tracks' => 'traditional,norag,kc', 'dry-run' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help'])) {
    echo "確認待ちの誤答例・フィードバックのAI提案を、まとめてそのまま確定し、続きのタスクをキューする。\n\n";
    echo "Usage: php confirm_all.php [--courseid=N] [--jobs=105,106] [--tracks=traditional,norag,kc] [--dry-run]\n";
    echo "  --dry-run  確定せず、対象になるものを表示するだけ\n";
    exit(0);
}

// [系統名 => [status列, 結果列, 確定列, タスククラス]]
$systems = [
    'traditional' => ['status', 'feedback_result', 'feedback_confirmed', \qbank_stackgen\task\generate_stack_question::class],
    'norag'       => ['norag_status', 'norag_feedback_result', 'norag_feedback_confirmed',
                      \qbank_stackgen\task\generate_stack_question_norag::class],
    'kc'          => ['kc_status', 'kc_feedback_result', 'kc_feedback_confirmed',
                      \qbank_stackgen\task\generate_stack_question_kc::class],
];

$tracks = array_values(array_filter(array_map('trim', explode(',', $options['tracks']))));
$unknown = array_diff($tracks, array_keys($systems));
if ($unknown) {
    cli_error('--tracks に不明な系統があります: ' . implode(', ', $unknown));
}

$conditions = [];
$params = [];
if (!empty($options['courseid'])) {
    $conditions[] = 'courseid = :courseid';
    $params['courseid'] = (int)$options['courseid'];
}
if ($options['jobs'] !== '') {
    [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', explode(',', $options['jobs'])), SQL_PARAMS_NAMED);
    $conditions[] = "id $insql";
    $params += $inparams;
}
$jobs = $DB->get_records_select('qbank_stackgen_jobs', $conditions ? implode(' AND ', $conditions) : '1=1', $params, 'id');

/**
 * 確定用のJSONを作る。*_feedback_result を解釈できなければ null。
 */
function qbank_stackgen_confirm_all_json(?string $feedbackresult): ?string {
    $decoded = json_decode((string)$feedbackresult, true);
    if (!is_array($decoded) || !isset($decoded['problems'])) {
        return null;
    }
    $problems = [];
    foreach ($decoded['problems'] as $p) {
        $problems[] = array_merge(['name' => '', 'questiontext' => ''], $p);
    }
    return json_encode(['problems' => $problems], JSON_UNESCAPED_UNICODE);
}

$dryrun = !empty($options['dry-run']);
$count = array_fill_keys($tracks, 0);
foreach ($jobs as $job) {
    foreach ($tracks as $track) {
        [$statuscol, $resultcol, $confirmcol, $taskclass] = $systems[$track];
        if ($job->$statuscol !== 'feedback_pending' || !empty($job->$confirmcol)) {
            continue;
        }
        $json = qbank_stackgen_confirm_all_json($job->$resultcol);
        if ($json === null) {
            echo "  [warn] job #{$job->id} {$track}: {$resultcol} を解釈できないため飛ばします。\n";
            continue;
        }
        if ($dryrun) {
            echo "  [対象] job #{$job->id} {$track} {$job->sourcefilename}\n";
            $count[$track]++;
            continue;
        }

        \qbank_stackgen\local\event_log::log($job->id, $track, 'auto_confirm');
        \qbank_stackgen\local\event_log::update_job((object)[
            'id'           => $job->id,
            $confirmcol    => $json,
            'timemodified' => time(),
        ]);
        $task = new $taskclass();
        $task->set_custom_data(['jobid' => $job->id]);
        \core\task\manager::queue_adhoc_task($task, true);
        echo "  [確定] job #{$job->id} {$track} {$job->sourcefilename}\n";
        $count[$track]++;
    }
}

echo ($dryrun ? '対象（未確定のまま）: ' : '確定してキューしました: ')
    . implode(' / ', array_map(function ($t) use ($count) {
        return "{$t}={$count[$t]}";
    }, $tracks)) . "\n";
