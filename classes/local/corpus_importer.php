<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Step 6 (RAG): 教員手作りの良問(層A)のXMLを読み込み、qtextを埋め込みAPIでベクトル化して
 * qbank_stackgen_examples に取り込む。
 *
 * プラグイン同梱のコーパス(data/corpus/)は、初回のRAG検索時に rag_search から自動で取り込まれる。
 * 別のディレクトリから追加・再計算したい場合は cli/import_corpus.php から同じ処理を呼ぶ。
 * 取り込みは冪等で、取り込み済みの sourcefile は $force が false ならスキップする。
 * 問題バンク(question系テーブル)には何も書き込まない。
 */
class corpus_importer {

    /** 同梱コーパスの questions ディレクトリ。 */
    public const BUNDLED_DIR = __DIR__ . '/../../data/corpus/questions';

    /**
     * 同梱コーパスを取り込む。他のプロセスが取り込み中なら、その完了を待ってから戻る。
     *
     * @return array ['imported'=>int, 'skipped'=>int, 'failed'=>int, 'errors'=>string[]]
     */
    public static function import_bundled(): array {
        $lock = \core\lock\lock_config::get_lock_factory('qbank_stackgen')->get_lock('corpus_import', 300);
        if (!$lock) {
            return ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['lock timeout']];
        }
        try {
            return self::import_dir(self::BUNDLED_DIR);
        } finally {
            $lock->release();
        }
    }

    /**
     * ディレクトリ内の *.xml を取り込む。同階層(dirname)に index.json があれば PRT 構造メタデータを付与する。
     *
     * @param string        $sourcedir *.xml を置いたディレクトリ
     * @param bool          $force     取り込み済みのsourcefileも埋め込みを再計算するか
     * @param callable|null $log       進捗出力用 function(string $line): void
     * @return array ['imported'=>int, 'skipped'=>int, 'failed'=>int, 'errors'=>string[]]
     */
    public static function import_dir(string $sourcedir, bool $force = false, ?callable $log = null): array {
        global $DB;

        $log = $log ?? function (string $line): void {
        };
        $result = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        $files = glob(rtrim($sourcedir, '/') . '/*.xml') ?: [];
        sort($files);
        if (empty($files)) {
            return $result;
        }

        // index.json があればscore/PRT構造メタデータをファイル名で引けるようにする。無くても取り込みは続行する。
        $metabyfile = [];
        $indexpath = dirname(rtrim($sourcedir, '/')) . '/index.json';
        if (is_readable($indexpath)) {
            foreach ((array)json_decode(file_get_contents($indexpath), true) as $entry) {
                if (isset($entry['file'])) {
                    $metabyfile[$entry['file']] = $entry;
                }
            }
        }

        $embedder = new \qbank_stackgen\api\gemini_embedding();

        foreach ($files as $path) {
            $filename = basename($path);

            $existing = $DB->get_record('qbank_stackgen_examples', ['sourcefile' => $filename]);
            if ($existing && !$force) {
                $log("  [skip] {$filename}（取り込み済み。再計算するには --force）");
                $result['skipped']++;
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

                $qtext = self::extract_qtext($question);
                if ($qtext === '') {
                    throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null, "qtextが空です: {$filename}");
                }

                $meta = $metabyfile[$filename] ?? [];

                $record = (object)[
                    'sourcefile'      => $filename,
                    'category'        => self::extract_category($filename),
                    'qtext'           => $qtext,
                    'score'           => isset($meta['score']) ? (int)$meta['score'] : null,
                    'n_nodes'         => isset($meta['n_nodes']) ? (int)$meta['n_nodes'] : null,
                    'distinct_tests'  => isset($meta['distinct_tests'])
                        ? json_encode($meta['distinct_tests'], JSON_UNESCAPED_UNICODE) : null,
                    'distinct_scores' => isset($meta['distinct_scores'])
                        ? json_encode($meta['distinct_scores'], JSON_UNESCAPED_UNICODE) : null,
                    'embedding'       => json_encode($embedder->embed($qtext), JSON_UNESCAPED_UNICODE),
                    'timecreated'     => time(),
                ];

                if ($existing) {
                    $record->id = $existing->id;
                    $DB->update_record('qbank_stackgen_examples', $record);
                    $log("  [update] {$filename}");
                } else {
                    $DB->insert_record('qbank_stackgen_examples', $record);
                    $log("  [insert] {$filename}");
                }
                $result['imported']++;

            } catch (\Throwable $e) {
                $log("  [error] {$filename}: " . $e->getMessage());
                $result['errors'][] = $filename . ': ' . $e->getMessage();
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * <question type="stack"> 要素から埋め込み対象のqtextを抽出する。
     * questiontext > text の中身からHTMLタグを除去し、実体参照をデコードし、空白を正規化する。
     */
    private static function extract_qtext(\SimpleXMLElement $question): string {
        $raw = (string)($question->questiontext->text ?? '');
        $stripped = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $stripped));
    }

    /**
     * ファイル名(連番_カテゴリ_問題名.xml)からカテゴリを復元する。
     */
    private static function extract_category(string $filename): string {
        if (preg_match('/^\d+_([a-z]+)_/', $filename, $m)) {
            return $m[1];
        }
        return 'other';
    }
}
