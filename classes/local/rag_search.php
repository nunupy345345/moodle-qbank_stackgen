<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Step 6 (RAG): qbank_stackgen_examples に保存された教員手作りの良問コーパス(層A)に対する
 * ブルートフォースのコサイン類似度検索。
 *
 * コーパス規模(現状26件、当面数百件程度)ではQdrant等の専用ベクトルDBは過剰なため、
 * PHPで全件ロード→コサイン類似度計算→top-k抽出という単純な実装にとどめる
 * (docs/requirements.md 1.2.1節参照)。
 */
class rag_search {

    /**
     * クエリ文字列に類似する例をコーパスから検索する。コーパスが空なら同梱の良問を先に取り込む
     * (初回のみ。埋め込みAPIを良問の件数分呼ぶ)。
     *
     * @param string $querytext 検索クエリ(OCR結果の問題文)
     * @param int    $topk      取得件数
     * @return array [['id'=>int, 'sourcefile'=>string, 'category'=>string, 'qtext'=>string,
     *                 'score'=>int, 'n_nodes'=>int, 'distinct_tests'=>string[], 'distinct_scores'=>string[],
     *                 'similarity'=>float], ...]  類似度降順
     * @throws \moodle_exception 埋め込みAPI呼び出しに失敗したとき
     */
    public static function search(string $querytext, int $topk = 3): array {
        global $DB;

        // 初回(コーパスが空)のみ、プラグイン同梱の良問を自動で取り込む。
        if ($DB->count_records('qbank_stackgen_examples') === 0) {
            corpus_importer::import_bundled();
        }

        $examples = $DB->get_records('qbank_stackgen_examples');
        if (empty($examples) || $topk <= 0) {
            return [];
        }

        $embedder     = new \qbank_stackgen\api\gemini_embedding();
        $queryvector  = $embedder->embed($querytext);

        $scored = [];
        foreach ($examples as $example) {
            $vector = json_decode($example->embedding, true);
            if (!is_array($vector) || empty($vector)) {
                continue;
            }
            $scored[] = [
                'id'              => (int)$example->id,
                'sourcefile'      => $example->sourcefile,
                'category'        => (string)$example->category,
                'qtext'           => $example->qtext,
                'score'           => (int)$example->score,
                'n_nodes'         => (int)$example->n_nodes,
                'distinct_tests'  => (array)json_decode($example->distinct_tests ?: '[]', true),
                'distinct_scores' => (array)json_decode($example->distinct_scores ?: '[]', true),
                'similarity'      => self::cosine_similarity($queryvector, $vector),
            ];
        }

        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($scored, 0, $topk);
    }

    /**
     * 2ベクトル間のコサイン類似度を計算する。次元数が一致しない場合は0を返す。
     */
    public static function cosine_similarity(array $a, array $b): float {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dot = 0.0;
        $norma = 0.0;
        $normb = 0.0;
        foreach ($a as $i => $va) {
            $vb = $b[$i];
            $dot   += $va * $vb;
            $norma += $va * $va;
            $normb += $vb * $vb;
        }

        if ($norma <= 0.0 || $normb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($norma) * sqrt($normb));
    }
}
