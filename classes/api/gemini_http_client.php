<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Gemini API 呼び出し共通ヘルパー（gemini_ocr / feedback_generator / llm_generator で共用）。
 *
 * 503（高負荷）や429（レート制限）等の一時的なエラーは、Google側のドキュメント通り
 * 「通常は一時的」なので、短い待機を挟んで自動的にリトライする。
 *
 * コスト計測: タスクが set_usage_context() でジョブ・版を指定している間は、成功した呼び出しごとに
 * レスポンスの usageMetadata（入力/出力/思考トークン数）を qbank_stackgen_events に
 * 'api_call' として記録する（金額は cli/event_report.php が単価から計算する）。
 */
class gemini_http_client {

    private const MAX_ATTEMPTS = 3;
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];
    private const RETRY_DELAYS_SEC = [2, 5]; // 1回目→2回目、2回目→3回目の待機秒数

    /** @var int|null トークン数の記録先ジョブ（タスクが execute の間だけ設定する） */
    private static ?int $usagejobid = null;
    /** @var string 記録先の版（traditional / norag / kc） */
    private static string $usagetrack = '';

    /**
     * 以降の post() のトークン使用量を、このジョブ・版のものとして記録する。
     * 記録を止めるには clear_usage_context() を呼ぶ（cronは1本で直列に動くが、別タスクへの混入を避けるため
     * タスク側で finally 句から必ず呼ぶ）。
     */
    public static function set_usage_context(int $jobid, string $track): void {
        self::$usagejobid = $jobid;
        self::$usagetrack = $track;
    }

    public static function clear_usage_context(): void {
        self::$usagejobid = null;
        self::$usagetrack = '';
    }

    /**
     * 成功した呼び出しの使用量を記録する。記録の失敗（DBエラー等）で本来の処理を止めないよう、握りつぶす。
     */
    private static function record_usage(string $url, string $response, string $context, float $seconds, int $attempts): void {
        if (self::$usagejobid === null) {
            return;
        }
        try {
            $decoded = json_decode($response, true);
            $usage = is_array($decoded) ? ($decoded['usageMetadata'] ?? []) : [];
            preg_match('#/models/([^:/?]+)#', $url, $m);
            \qbank_stackgen\local\event_log::log(self::$usagejobid, self::$usagetrack, 'api_call', json_encode([
                'ctx'      => $context,
                'model'    => $m[1] ?? '',
                // 実際に応答したモデル。指定したモデルが別のモデルに自動で振り替えられたとき（廃止予定モデルなど）に気づくため。
                'modelver' => is_array($decoded) ? ($decoded['modelVersion'] ?? null) : null,
                // embedContent の応答には usageMetadata が無いので、その場合は null（トークン数不明）のまま残す。
                'in'       => $usage['promptTokenCount'] ?? null,
                'out'      => $usage['candidatesTokenCount'] ?? null,
                'think'    => $usage['thoughtsTokenCount'] ?? null,
                'total'    => $usage['totalTokenCount'] ?? null,
                'sec'      => round($seconds, 2),
                'attempts' => $attempts,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            debugging('qbank_stackgen: api_call usage logging failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * @param string $url      APIキー付きエンドポイントURL
     * @param string $body     JSONエンコード済みリクエストボディ
     * @param string $context  エラーメッセージに含める文脈（例: "feedback, problem #1"）
     * @param int    $timeout  1回あたりのcurlタイムアウト秒数
     * @return string  HTTP 200 のレスポンスボディ
     * @throws \moodle_exception 最終試行まで失敗したとき
     */
    public static function post(string $url, string $body, string $context, int $timeout = 90): string {
        $lastcurlerror = '';
        $lasthttpcode  = 0;
        $lastresponse  = '';
        $started       = microtime(true);

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
                self::record_usage($url, (string)$response, $context, microtime(true) - $started, $attempt);
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
