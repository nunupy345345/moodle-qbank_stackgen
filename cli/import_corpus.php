<?php
/**
 * Step 6 (RAG): fixtures/prt/questions の教員手作りの良問(層A)を読み込み、
 * qtext を Gemini embeddings API でベクトル化して qbank_stackgen_examples に取り込む。
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
    echo "fixtures/prt/questions の良問をRAGコーパス(qbank_stackgen_examples)に取り込む。\n\n";
    echo "Usage: php import_corpus.php [--sourcedir=PATH] [--force]\n";
    echo "  --sourcedir=PATH  *.xml を読み込むディレクトリ(省略時はプラグイン内のfixtures_prtマウント)\n";
    echo "  --force           既に取り込み済みのsourcefileも埋め込みを再計算する\n";
    exit(0);
}

$sourcedir = !empty($options['sourcedir'])
    ? rtrim((string)$options['sourcedir'], '/')
    : __DIR__ . '/../fixtures_prt/questions';

if (!is_dir($sourcedir)) {
    cli_error("コーパスディレクトリが見つかりません: {$sourcedir}\n"
        . "docker-compose.yaml で ./fixtures/prt を question/bank/stackgen/fixtures_prt にマウントしているか確認してください。");
}

/**
 * <question type="stack"> 要素から埋め込み対象のqtextを抽出する。
 * fixtures/prt/build_fixtures.py の text_of()/strip_html() と同じ抽出ロジック
 * (questiontext > text の中身からHTMLタグを除去し、実体参照をデコードし、空白を正規化)。
 */
function qbank_stackgen_extract_qtext(SimpleXMLElement $question): string {
    $raw = (string)($question->questiontext->text ?? '');
    $stripped = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $stripped));
}

function qbank_stackgen_extract_category(SimpleXMLElement $question, string $filename): string {
    // build_fixtures.py 側で連番_カテゴリ_問題名.xml という命名にしているため、ファイル名から復元する。
    if (preg_match('/^\d+_([a-z]+)_/', $filename, $m)) {
        return $m[1];
    }
    return 'other';
}

$files = glob($sourcedir . '/*.xml');
sort($files);

if (empty($files)) {
    cli_error("コーパスディレクトリにXMLファイルがありません: {$sourcedir}");
}

// index.json（同階層）があればscore/PRT構造メタデータをファイル名で引けるようにする。
// 無くても取り込み自体は続行する（score/n_nodes等はnullのまま埋め込みだけ計算する）。
$metabyfile = [];
$indexpath = dirname($sourcedir) . '/index.json';
if (is_readable($indexpath)) {
    $index = json_decode(file_get_contents($indexpath), true);
    foreach ((array)$index as $entry) {
        if (isset($entry['file'])) {
            $metabyfile[$entry['file']] = $entry;
        }
    }
}

echo count($files) . " ファイルを処理します（ソース: {$sourcedir}）。\n";

$embedder = new \qbank_stackgen\api\gemini_embedding();
$imported = 0;
$skipped  = 0;
$failed   = 0;

foreach ($files as $path) {
    $filename = basename($path);

    $existing = $DB->get_record('qbank_stackgen_examples', ['sourcefile' => $filename]);
    if ($existing && empty($options['force'])) {
        echo "  [skip] {$filename}（取り込み済み。再計算するには --force）\n";
        $skipped++;
        continue;
    }

    try {
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null, "XMLパース失敗: {$filename}");
        }
        $question = $xml->getName() === 'question' ? $xml : ($xml->question ?? null);
        if ($question === null) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "<question>要素が見つかりません: {$filename}");
        }

        $qtext    = qbank_stackgen_extract_qtext($question);
        $category = qbank_stackgen_extract_category($question, $filename);
        if ($qtext === '') {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "qtextが空です: {$filename}");
        }

        $vector = $embedder->embed($qtext);
        $meta   = $metabyfile[$filename] ?? [];

        $record = (object)[
            'sourcefile'      => $filename,
            'category'        => $category,
            'qtext'           => $qtext,
            'score'           => isset($meta['score']) ? (int)$meta['score'] : null,
            'n_nodes'         => isset($meta['n_nodes']) ? (int)$meta['n_nodes'] : null,
            'distinct_tests'  => isset($meta['distinct_tests'])
                ? json_encode($meta['distinct_tests'], JSON_UNESCAPED_UNICODE) : null,
            'distinct_scores' => isset($meta['distinct_scores'])
                ? json_encode($meta['distinct_scores'], JSON_UNESCAPED_UNICODE) : null,
            'embedding'       => json_encode($vector, JSON_UNESCAPED_UNICODE),
            'timecreated'     => time(),
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('qbank_stackgen_examples', $record);
            echo "  [update] {$filename}\n";
        } else {
            $DB->insert_record('qbank_stackgen_examples', $record);
            echo "  [insert] {$filename}\n";
        }
        $imported++;

    } catch (\Throwable $e) {
        echo "  [error] {$filename}: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "完了: {$imported}件取り込み, {$skipped}件スキップ, {$failed}件失敗。\n";
