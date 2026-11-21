<?php
/**
 * アンケート用: 比較対象の人間版(同梱コーパスの教員手作りPRT)を、問題バンクに取り込むCLIスクリプト。
 *
 * data/survey/items.json に載っている16問を、専用カテゴリ(STACKGen人間版)へ登録する。
 * 生成3版と同じく問題バンク上の問題になるので、STACKが実際に表示する問題文・プレビューURLを
 * 持て、export_survey_dataset.php が4版を同じ経路で書き出せる。
 *
 * 元ファイルとの対応は question_bank_entries.idnumber にファイル名を入れて持たせる
 * （DBの列は増やさない）。同じファイル名が既にあれば再取り込みしない（べき等）。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/survey_import_human.php --courseid=2
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(['courseid' => 0, 'userid' => 0, 'help' => false], ['h' => 'help']);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help']) || empty($options['courseid'])) {
    echo "アンケート用の人間版PRT(16問)を問題バンクに取り込む。\n\n";
    echo "Usage: php survey_import_human.php --courseid=N [--userid=N]\n";
    exit(empty($options['help']) ? 1 : 0);
}

$courseid = (int)$options['courseid'];
$DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
\core\session\manager::set_user(!empty($options['userid']) ? \core_user::get_user((int)$options['userid']) : get_admin());

$data = json_decode((string)file_get_contents(__DIR__ . '/../data/survey/items.json'), true);
if (empty($data['items'])) {
    cli_error('アンケート問題定義を読み込めません。');
}

$context  = context_course::instance($courseid);
$category = \qbank_stackgen\question_importer::get_target_category(
    $context, \qbank_stackgen\question_importer::CATEGORY_MARKER_HUMAN);

$imported = 0;
$skipped  = 0;
foreach ($data['items'] as $item) {
    $file = $item['sourcefile'];

    if ($DB->record_exists('question_bank_entries', ['questioncategoryid' => $category->id, 'idnumber' => $file])) {
        echo "  [skip] {$file}（取り込み済み）\n";
        $skipped++;
        continue;
    }

    $path = \qbank_stackgen\local\corpus_importer::BUNDLED_DIR . '/' . $file;
    if (!is_readable($path)) {
        echo "  [error] {$file}: XMLが見つかりません。\n";
        continue;
    }

    $result = \qbank_stackgen\question_importer::import(
        (object)['courseid' => $courseid, 'xml_result' => file_get_contents($path)],
        \qbank_stackgen\question_importer::CATEGORY_MARKER_HUMAN
    );

    foreach ($result['questionids'] as $qid) {
        $entryid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $qid], MUST_EXIST);
        $DB->set_field('question_bank_entries', 'idnumber', $file, ['id' => $entryid]);
        echo "  [imported] {$file} -> question #{$qid}\n";
    }
    $imported++;
}

echo "完了: {$imported}件取り込み, {$skipped}件スキップ。\n";
