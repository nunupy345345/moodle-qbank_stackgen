<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 3: Gemini API を使って OCR 結果（LaTeX JSON）から
 * 誤答例（想定される間違った答え）とそのフィードバック案を生成する。
 *
 * 生成結果は教員が review.php で確認・修正し、確定したものだけが
 * Stage 4（llm_generator）のPRT生成プロンプトに組み込まれる。
 */
class feedback_generator {

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    private const SYSTEM = <<<'SYS'
あなたは数学教育の専門家です。与えられた数学問題について、学生が犯しやすい典型的な誤答パターンを想定し、
それぞれの誤答に対する採点フィードバック（なぜ間違っているか、どこを見直すべきか）を日本語で作成してください。

出力は必ず以下の JSON 形式のみとし、説明文やコードフェンスは付けないこと。

{
  "wronganswers": [
    {
      "expression": "誤答の数式（Maxima記法。乗算は必ず* を使う。例: 2*x ではなく2xは不可）",
      "misconception": "想定される誤りの原因（例: 符号ミス、係数の計算違い、公式の適用ミス等）",
      "feedback": "学生への具体的なフィードバック文（HTML可、日本語、1〜2文程度）"
    }
  ]
}

制約:
- 誤答は2〜4個程度、実際によくある間違いを想定すること（符号ミス、係数ミス、公式の取り違え、定数項の付け忘れ等）。
- expression は正答と必ず異なる Maxima 式にすること。
- feedback は学生が自分の間違いに気づけるよう、具体的な誤りの箇所を指摘すること。
SYS;

    /**
     * 複数問の OCR 結果それぞれについて誤答例/フィードバック案を生成する。
     *
     * @param array $problems  gemini_ocr::run() が返す problems 配列
     * @return array  ['problems' => [['wronganswers' => [...]], ...]]  （$problems と同じ順序）
     * @throws \moodle_exception APIキー未設定・通信エラーのとき
     */
    public function run(array $problems): array {
        $api_key = get_config('qbank_stackgen', 'google_api_key');
        if (empty($api_key)) {
            throw new \moodle_exception('error_llm_api_key_missing', 'qbank_stackgen');
        }

        $result = [];
        foreach ($problems as $i => $problem) {
            $result[] = ['wronganswers' => $this->generate_one($api_key, $problem, $i + 1)];
        }

        return ['problems' => $result];
    }

    /**
     * 1問分の誤答例/フィードバック案を生成する。
     *
     * @return array  [['expression'=>..., 'misconception'=>..., 'feedback'=>...], ...]
     */
    private function generate_one(string $api_key, array $problem, int $num): array {
        $qtext  = $problem['question_text']      ?? '';
        $qtype  = $problem['question_type']      ?? 'other';
        $expr   = $problem['problem_expression'] ?? '';
        $answer = $problem['answer_expression']  ?? null;

        $user_msg = "以下の数学問題について、想定される誤答例とフィードバック案を JSON で生成してください。\n\n"
            . "問題番号: {$num}\n"
            . "問題文: {$qtext}\n"
            . "問題タイプ: {$qtype}\n"
            . "LaTeX 式: {$expr}\n"
            . ($answer !== null ? "正答（LaTeX）: {$answer}\n" : "※正答は不明。問題の内容から妥当な誤答パターンを推測すること。\n");

        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => self::SYSTEM]]],
            'contents'           => [['parts' => [['text' => $user_msg]]]],
            'generationConfig'   => [
                'temperature'        => 0.2,
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

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini curl error (feedback, problem #{$num}): {$curl_error}");
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini API HTTP {$http_code} (feedback, problem #{$num}): {$response}");
        }

        $decoded   = json_decode($response, true);
        $json_text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $parsed = json_decode($json_text, true);
        if (!is_array($parsed) || !isset($parsed['wronganswers']) || !is_array($parsed['wronganswers'])) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini feedback JSON parse failed (problem #{$num}): {$json_text}");
        }

        $wronganswers = [];
        foreach ($parsed['wronganswers'] as $w) {
            if (empty($w['expression'])) {
                continue;
            }
            $wronganswers[] = [
                'expression'    => (string)$w['expression'],
                'misconception' => (string)($w['misconception'] ?? ''),
                'feedback'      => (string)($w['feedback'] ?? ''),
            ];
        }

        return $wronganswers;
    }
}
