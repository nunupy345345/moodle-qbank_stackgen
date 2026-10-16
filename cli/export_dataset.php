<?php
/**
 * 承認済み（status=approved）ジョブを、学習・評価用データセットとしてエクスポートするCLIスクリプト。
 *
 * 各ジョブについて、LLMがStage4/5で生成した「ドラフトXML」（qbank_stackgen_jobs.xml_result）と、
 * Moodleの問題バンク編集画面で教員が実際に確定させた「現在の最終状態」（question_ids から
 * qformat_xml で再エクスポート）の両方を書き出す。後者は登録後の教員修正を反映するが、
 * qbank_stackgen_jobs 側のカラムには一切保存されないため、この差分は実行のたびに
 * Moodle本体の question テーブルから読み直さないと得られない。
 *
 * 出力: question/bank/stackgen/cli/dataset_export/<jobid>/
 *   meta.json     ジョブのメタデータ（status, question_ids 等）
 *   input.json    OCR結果＋教員確定済み誤答例/フィードバック（LLMへの入力に相当）
 *   llm_draft.xml Stage4/5で生成・CAS検証済みのXML
 *   llm_cot.txt   Stage4のchain-of-thought（あれば）
 *   final.xml     question_ids の現在のDB状態を再エクスポートしたXML（教員編集後の最終版）
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/export_dataset.php
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/export_dataset.php --courseid=2
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

list($options, $unrecognized) = cli_get_params(
    ['courseid' => 0, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help'])) {
    echo "承認済みジョブを学習用データセットとしてエクスポートする。\n\n";
    echo "Usage: php export_dataset.php [--courseid=N]\n";
    echo "  --courseid=N  このコースIDのジョブのみ対象にする（省略時は全コース）\n";
    exit(0);
}

/**
 * question_ids（JSON配列）の現在のDB状態を、Moodle標準のexport経路
 * （question/bank/exporttoxml/exportone.php と同じ手順）で1つのXMLに書き出す。
 *
 * @return string|null 成功時はXML文字列、対象questionが1件も読み込めなければnull
 */
function qbank_stackgen_export_final_xml(stdClass $course, context $context, array $questionids, int $jobid): ?string {
    $questiondatas = [];
    foreach ($questionids as $qid) {
        try {
            $questiondatas[] = question_bank::load_question_data((int)$qid);
        } catch (\Throwable $e) {
            echo "  [job {$jobid}] question id {$qid} の読み込みに失敗（削除済み？）: " . $e->getMessage() . "\n";
        }
    }

    if (empty($questiondatas)) {
        return null;
    }

    $qformat = new qformat_xml();
    $qformat->setCourse($course);
    $qformat->setContexts([$context]);
    $qformat->setQuestions($questiondatas);
    $qformat->setCattofile(false);
    $qformat->setContexttofile(false);

    if (!$qformat->exportpreprocess()) {
        echo "  [job {$jobid}] exportpreprocess() に失敗しました。\n";
        return null;
    }

    try {
        // exportprocess(true) は question_has_capability_on() でログイン中ユーザーの権限を見るが、
        // このスクリプトはCLIで$USERが未ログイン状態のため、trueだと全問題が権限チェックで弾かれ
        // 「エクスポートできる問題が見つかりませんでした」で例外になる。信頼済みの管理CLIなので
        // 権限チェックはスキップする。
        $xml = $qformat->exportprocess(false);
    } catch (\Throwable $e) {
        echo "  [job {$jobid}] exportprocess() が例外を投げました: " . $e->getMessage() . "\n";
        return null;
    }

    if ($xml === false) {
        echo "  [job {$jobid}] exportprocess() に失敗しました。\n";
        return null;
    }

    return $xml;
}

function qbank_stackgen_export_job(stdClass $job, string $outdir): void {
    $jobdir = $outdir . '/' . $job->id;
    if (!is_dir($jobdir) && !mkdir($jobdir, 0775, true) && !is_dir($jobdir)) {
        echo "  [job {$job->id}] 出力先ディレクトリの作成に失敗しました: {$jobdir}\n";
        return;
    }

    $questionids = json_decode($job->question_ids ?: '[]', true) ?: [];

    file_put_contents($jobdir . '/meta.json', json_encode([
        'job_id'       => (int)$job->id,
        'status'       => $job->status,
        'courseid'     => (int)$job->courseid,
        'timecreated'  => (int)$job->timecreated,
        'timemodified' => (int)$job->timemodified,
        'retry_count'  => (int)$job->retry_count,
        'question_ids' => $questionids,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    file_put_contents($jobdir . '/input.json', json_encode([
        'ocr_result'         => json_decode($job->ocr_result ?: '{}', true),
        'feedback_confirmed' => json_decode($job->feedback_confirmed ?: '{}', true),
        // Step 6 RAG: 問題インデックス順の配列。各要素は検索でヒットした例（id/sourcefile/category/
        // qtext/score/n_nodes/distinct_tests/distinct_scores/similarity）のtop-kリスト（空配列もありうる）。
        // RAG無効時や検索失敗時も要素自体は空配列で残るため、有効/無効の判別にも使える。
        'rag_examples_used'  => json_decode($job->rag_examples_used ?? '', true) ?: [],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if (!empty($job->xml_result)) {
        file_put_contents($jobdir . '/llm_draft.xml', $job->xml_result);
    }

    if (!empty($job->llm_cot)) {
        file_put_contents($jobdir . '/llm_cot.txt', $job->llm_cot);
    }

    if (empty($questionids)) {
        echo "  [job {$job->id}] question_ids が空のため final.xml をスキップします。\n";
        return;
    }

    $course  = get_course($job->courseid);
    $context = context_course::instance($job->courseid);
    $finalxml = qbank_stackgen_export_final_xml($course, $context, $questionids, (int)$job->id);

    if ($finalxml === null) {
        echo "  [job {$job->id}] 有効なquestionが1件もないため final.xml をスキップします。\n";
        return;
    }

    file_put_contents($jobdir . '/final.xml', $finalxml);
    echo "  [job {$job->id}] エクスポート完了 -> {$jobdir}\n";
}

$conditions = ['status' => 'approved'];
if (!empty($options['courseid'])) {
    $conditions['courseid'] = (int)$options['courseid'];
}

$jobs = $DB->get_records('qbank_stackgen_jobs', $conditions, 'id ASC');

echo count($jobs) . " 件の承認済みジョブをエクスポートします。\n";

$outdir = __DIR__ . '/dataset_export';
if (!is_dir($outdir) && !mkdir($outdir, 0775, true) && !is_dir($outdir)) {
    cli_error("出力先ディレクトリの作成に失敗しました: {$outdir}");
}

foreach ($jobs as $job) {
    qbank_stackgen_export_job($job, $outdir);
}

echo "完了。\n";
