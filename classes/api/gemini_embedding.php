<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * RAG検索用: Gemini embeddings API で文字列をベクトル化する。
 *
 * LLM生成(gemini_ocr / feedback_generator / llm_generator)と同じ google_api_key を使い回す
 * (2026-07ミーティングでLLMプロバイダをGeminiに一本化する方針が確定しているため、
 * 埋め込み専用の別プロバイダ・別APIキーは導入しない)。
 *
 * モデルは gemini-embedding-001 を使用する（旧 text-embedding-004 はGoogle側で廃止済みで
 * v1beta の embedContent では 404 になるため使用不可）。
 */
class gemini_embedding {

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent';

    /**
     * @param string $text 埋め込み対象の文字列(問題文など)
     * @return float[] embeddingベクトル
     * @throws \moodle_exception APIキー未設定・通信エラー・レスポンス不正のとき
     */
    public function embed(string $text): array {
        $api_key = get_config('qbank_stackgen', 'google_api_key');
        if (empty($api_key)) {
            throw new \moodle_exception('error_llm_api_key_missing', 'qbank_stackgen');
        }

        $body = json_encode([
            'model'   => 'models/gemini-embedding-001',
            'content' => ['parts' => [['text' => $text]]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $url = self::ENDPOINT . '?key=' . urlencode($api_key);

        $response = gemini_http_client::post($url, $body, 'embedding');

        $decoded = json_decode($response, true);
        $values  = $decoded['embedding']['values'] ?? null;
        if (!is_array($values) || empty($values)) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'Gemini embedding response missing embedding.values: ' . $response);
        }

        return array_map('floatval', $values);
    }
}
