<?php
/**
 * アンケート用: survey_seed.php で登録したジョブについて、Stage 3 が終わった系統の
 * 誤答例・フィードバックのAI提案を、教員が確認した扱いでそのまま確定し、続きのタスクをキューする。
 *
 * 従来版・RAGなし版・KC版はそれぞれ別々に誤答例を生成・確定する系統なので、各系統について
 * 「*_feedback_pending かつ未確定」なら *_feedback_result を *_feedback_confirmed にコピーする
 * （review.php が保存する形式に合わせ、問題名・問題文は空で持たせる＝LLM生成のものを使う）。
 *
 * KC版は従来版のRAG検索結果（rag_examples_used）を読むため、従来版のStage 3完了後に
 * ここで初めてキューする。何度実行してもよい（cronで各Stageが進むたびに再実行する）。
 * 対象は sourcefilename が data/survey/items.json に載っているジョブのみ。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/survey_confirm.php --courseid=2
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(['courseid' => 0, 'help' => false], ['h' => 'help']);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help']) || empty($options['courseid'])) {
    echo "アンケート用ジョブの誤答例AI提案をそのまま確定し、続きのタスクをキューする。\n\n";
    echo "Usage: php survey_confirm.php --courseid=N\n";
    exit(empty($options['help']) ? 1 : 0);
}

$courseid = (int)$options['courseid'];
$data = json_decode((string)file_get_contents(__DIR__ . '/../data/survey/items.json'), true);
$files = array_column($data['items'] ?? [], 'sourcefile');
if (empty($files)) {
    cli_error('アンケート問題定義を読み込めません。');
}

/**
 * review.php が保存する形式（name/questiontext/wronganswers）に揃えて確定JSONを作る。
 */
function qbank_stackgen_survey_confirmed_json(?string $feedbackresult): ?string {
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

// [系統名 => [status列, 結果列, 確定列, タスククラス]]
$systems = [
    'traditional' => ['status', 'feedback_result', 'feedback_confirmed', \qbank_stackgen\task\generate_stack_question::class],
    'norag'       => ['norag_status', 'norag_feedback_result', 'norag_feedback_confirmed',
                      \qbank_stackgen\task\generate_stack_question_norag::class],
    'kc'          => ['kc_status', 'kc_feedback_result', 'kc_feedback_confirmed',
                      \qbank_stackgen\task\generate_stack_question_kc::class],
];

[$insql, $params] = $DB->get_in_or_equal($files, SQL_PARAMS_NAMED);
$params['courseid'] = $courseid;
$jobs = $DB->get_records_select('qbank_stackgen_jobs', "courseid = :courseid AND sourcefilename $insql", $params, 'id');

$confirmed = 0;
foreach ($jobs as $job) {
    foreach ($systems as $name => [$statuscol, $resultcol, $confirmcol, $taskclass]) {
        // KC版: 従来版のStage 3（RAG検索）完了後に初めてキューする。
        if ($name === 'kc' && !empty($job->track_kc) && ($job->kc_status === '' || $job->kc_status === null)
                && !empty($job->rag_examples_used)) {
            $task = new $taskclass();
            $task->set_custom_data(['jobid' => $job->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            echo "  [kc-start] job #{$job->id} {$job->sourcefilename}\n";
            continue;
        }

        if ($job->$statuscol !== 'feedback_pending' || !empty($job->$confirmcol)) {
            continue;
        }
        $json = qbank_stackgen_survey_confirmed_json($job->$resultcol);
        if ($json === null) {
            echo "  [warn] job #{$job->id} {$name}: {$resultcol} を解釈できません。\n";
            continue;
        }

        \qbank_stackgen\local\event_log::update_job((object)[
            'id'         => $job->id,
            $confirmcol  => $json,
            'timemodified' => time(),
        ]);
        $task = new $taskclass();
        $task->set_custom_data(['jobid' => $job->id]);
        \core\task\manager::queue_adhoc_task($task, true);
        echo "  [confirmed] job #{$job->id} {$name} {$job->sourcefilename}\n";
        $confirmed++;
    }
}

echo "完了: {$confirmed}系統を確定しました。cronを実行して次のStageを進めてください。\n";
