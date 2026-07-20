<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Gemini API 呼び出し共通ヘルパー（gemini_ocr / feedback_generator / llm_generator で共用）。
 *
 * 503（高負荷）や429（レート制限）等の一時的なエラーは、Google側のドキュメント通り
 * 「通常は一時的」なので、短い待機を挟んで自動的にリトライする。
 */
class gemini_http_client {

    private const MAX_ATTEMPTS = 3;
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];
    private const RETRY_DELAYS_SEC = [2, 5]; // 1回目→2回目、2回目→3回目の待機秒数

    /**
     * @param string $url      APIキー付きエンドポイントURL
     * @param string $body     JSONエンコード済みリクエストボディ
     * @param string $context  エラーメッセージに含める文脈（例: "feedback, problem #1"）
     * @param int    $timeout  1回あたりのcurlタイムアウト秒数
     * @return string  HTTP 200 のレスポンスボディ
     * @throws \moodle_exception 最終試行まで失敗したとき
     */
    public static function post(string $url, string $body, string $context, int $timeout = 60): string {
        $lastcurlerror = '';
        $lasthttpcode  = 0;
        $lastresponse  = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);

            $response   = curl_exec($ch);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if (!$curl_error && $http_code === 200) {
                return (string)$response;
            }

            $lastcurlerror = $curl_error;
            $lasthttpcode  = $http_code;
            $lastresponse  = (string)$response;

            $retryable = $curl_error !== '' || in_array($http_code, self::RETRY_STATUSES, true);
            if (!$retryable || $attempt === self::MAX_ATTEMPTS) {
                break;
            }
            sleep(self::RETRY_DELAYS_SEC[$attempt - 1] ?? 5);
        }

        if ($lastcurlerror) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini curl error ({$context}): {$lastcurlerror}");
        }
        throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
            "Gemini API HTTP {$lasthttpcode} ({$context}): {$lastresponse}");
    }
}
