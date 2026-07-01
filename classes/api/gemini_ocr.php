<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2: Gemini 2.5 Flash を使って画像から数学問題を OCR する。
 */
class gemini_ocr {

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    private const PROMPT = <<<'PROMPT'
この画像は数学の教科書または問題用紙です。
画像内にある数学問題をすべて認識し、以下の JSON 形式で出力してください。

{
  "problems": [
    {
      "question_text": "問題の本文（数式は LaTeX形式、例: $f(x) = x^2$）",
      "question_type": "derivative|integral|equation|factoring|expansion|other",
      "variables": ["x"],
      "problem_expression": "問題となる数式（LaTeX、例: x^2+2*x+1）",
      "answer_expression": "答えが明示されている場合のみ（LaTeX）、なければ null"
    }
  ]
}

制約:
- 数式は必ず LaTeX 形式で記述してください（\frac, \sqrt 等も使用可）。
- question_type は derivative / integral / equation / factoring / expansion / other の中から最も近いものを選んでください。
- variables に数式中の変数をすべて列挙してください（例: ["x", "y"]）。
- 画像内のすべての問題（(1)(2)(3)… など）を problems 配列に列挙してください。
- JSON のみ出力し、説明文は不要です。
PROMPT;

    /**
     * 画像を Gemini に送り、複数問の LaTeX JSON 配列を返す。
     *
     * @param string $image_b64  Canvas API が出力した base64（プレフィックスなし）
     * @return array  ['problems' => [['question_text'=>..., 'question_type'=>..., ...], ...]]
     * @throws \moodle_exception APIキー未設定・通信エラー・JSON不正のとき
     */
    public function run(string $image_b64): array {
        $api_key = get_config('qbank_stackgen', 'google_api_key');
        if (empty($api_key)) {
            throw new \moodle_exception('error_ocr_api_key_missing', 'qbank_stackgen');
        }

        $body = json_encode([
            'contents' => [[
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data'      => $image_b64,
                        ],
                    ],
                    ['text' => self::PROMPT],
                ],
            ]],
            'generationConfig' => [
                'temperature'        => 0.1,
                'response_mime_type' => 'application/json',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $url = self::ENDPOINT . '?key=' . urlencode($api_key);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'Gemini curl error: ' . $curl_error);
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini API returned HTTP {$http_code}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'Gemini response structure unexpected: ' . $response);
        }

        $json_text = $decoded['candidates'][0]['content']['parts'][0]['text'];
        $result = json_decode($json_text, true);
        if (!is_array($result) || !isset($result['problems']) || !is_array($result['problems'])) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'Gemini OCR JSON parse failed or missing "problems" key: ' . $json_text);
        }

        $problems = [];
        foreach ($result['problems'] as $i => $p) {
            foreach (['question_text', 'question_type', 'variables', 'problem_expression'] as $key) {
                if (!array_key_exists($key, $p)) {
                    throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                        "Problem #{$i} missing key: {$key}");
                }
            }
            $problems[] = [
                'question_text'      => (string)$p['question_text'],
                'question_type'      => (string)$p['question_type'],
                'variables'          => (array)($p['variables'] ?? []),
                'problem_expression' => (string)$p['problem_expression'],
                'answer_expression'  => isset($p['answer_expression']) ? (string)$p['answer_expression'] : null,
            ];
        }

        return ['problems' => $problems];
    }
}
