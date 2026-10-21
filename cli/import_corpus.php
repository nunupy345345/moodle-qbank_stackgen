<?php
/**
 * Step 6 (RAG): 教員手作りの良問(層A)のXMLを読み込み、
 * qtext を Gemini embeddings API でベクトル化して qbank_stackgen_examples に取り込む。
 *
 * 同梱コーパス(data/corpus/)は、初回のRAG検索時に自動で取り込まれるため、通常はこのCLIは不要。
 * 独自のXMLを追加したいときや、--force で埋め込みを再計算したいときに使う。
 * 実処理は classes/local/corpus_importer.php。
 *
 * べき等: 既に取り込み済みの sourcefile はデフォルトでスキップする(--force で再計算)。
 * オフライン処理(コーパス追加のたびに1回実行すればよい)であり、Adhoc Task経由では実行しない。
 *
 * 実行例:
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/import_corpus.php
 *   docker exec -it stack-moodle-moodle-1 \
 *     php /var/www/html/question/bank/stackgen/cli/import_corpus.php --force
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['sourcedir' => '', 'force' => false, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (!empty($options['help'])) {
    echo "良問のXMLをRAGコーパス(qbank_stackgen_examples)に取り込む。\n\n";
    echo "Usage: php import_corpus.php [--sourcedir=PATH] [--force]\n";
    echo "  --sourcedir=PATH  *.xml を読み込むディレクトリ(省略時はプラグイン同梱のdata/corpus/questions)\n";
    echo "  --force           既に取り込み済みのsourcefileも埋め込みを再計算する\n";
    exit(0);
}

$sourcedir = !empty($options['sourcedir'])
    ? rtrim((string)$options['sourcedir'], '/')
    : \qbank_stackgen\local\corpus_importer::BUNDLED_DIR;

if (!is_dir($sourcedir)) {
    cli_error("コーパスディレクトリが見つかりません: {$sourcedir}");
}
if (empty(glob($sourcedir . '/*.xml'))) {
    cli_error("コーパスディレクトリにXMLファイルがありません: {$sourcedir}");
}

echo "{$sourcedir} を処理します。\n";

$result = \qbank_stackgen\local\corpus_importer::import_dir($sourcedir, !empty($options['force']), function (string $line) {
    echo $line . "\n";
});

echo "完了: {$result['imported']}件取り込み, {$result['skipped']}件スキップ, {$result['failed']}件失敗。\n";
