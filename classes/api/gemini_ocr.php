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
      "question_text": "画像に書かれている問題の本文そのまま（数式は LaTeX 形式で \( ... \) 区切り、例: \( f(x)=x^2 \)）",
      "stack_question_text": "STACK 出題用にリライトした問題文（後述のリライト規則に従うこと）",
      "question_type": "derivative|integral|equation|factoring|expansion|other",
      "expected_answer_kind": "expression|value|equation",
      "variables": ["x"],
      "problem_expression": "問題となる数式（LaTeX、例: x^2+2x+1）",
      "answer_expression": "答えが明示されている場合のみ（LaTeX）、なければ null"
    }
  ]
}

制約:
- 数式は必ず LaTeX 形式で記述してください（\frac, \sqrt 等も使用可）。
- 数式の区切りには必ず \( ... \)（インライン）または \[ ... \]（別行立て）を使うこと。
  $ ... $ や $$ ... $$ は絶対に使ってはいけません（STACK では表示されないため）。
- 【重要】JSON 文字列内のバックスラッシュは必ず \\ とエスケープすること。
  例: "question_text": "関数 \\( f(x)=x^2 \\) について" （"\( ... \)" のような生のバックスラッシュは不正な JSON になる）
- question_type は derivative / integral / equation / factoring / expansion / other の中から最も近いものを選んでください。
- variables に数式中の変数をすべて列挙してください（例: ["x", "y"]）。
- 画像内のすべての問題（(1)(2)(3)… など）を problems 配列に列挙してください。
- JSON のみ出力し、説明文は不要です。

stack_question_text のリライト規則（重要）:
- STACK ではこの問題は「解答欄1つ」の問題として出題されます。学生が
  「1つの数式または値」を入力すれば答えられる問いに書き換えてください。
- 問うものが1つに定まっている問題は、原文のままでかまいません。
- 1問で複数の答えを要求する問題（例:「最大値と最小値を求めよ」「x と y の値を求めよ」
  「頂点の座標と軸を求めよ」）は、問題を分割し、problems 配列の別々の要素として
  1問1答になるように出力してください（例: 「…の最小値を求めよ。」と「…の最大値を求めよ。」の2問に分ける）。
- 「〜があれば、それを求めよ」のような条件付きの問いは、存在する方のみを問う形に
  書き換えてください（例: 上に凸でない2次関数なら「最小値を求めよ。」のみ）。
  存在しない側（例: 最大値なし）は問題にしないこと。
- 何を入力すべきか一意に分かる文にしてください
  （例: 「最小値を求めよ。」→ 入力は最小値の値のみ、と分かる）。
- expected_answer_kind には、学生が入力するものの種類を入れてください:
  expression = 数式（例: 因数分解の結果）、value = 数値1つ（例: 最小値）、equation = 方程式（例: 放物線の方程式 y=...）。

分割の例:
原文「次の2次関数に最大値, 最小値があれば, それを求めよ。 \( y=x^2+4x+2 \)」
→ problems には次の1問のみを出力（下に凸なので最大値は存在しない）:
  question_text: 原文そのまま
  stack_question_text: "2次関数 \( y=x^2+4x+2 \) の最小値を求めよ。"
  expected_answer_kind: "value"
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

        $response = gemini_http_client::post($url, $body, 'OCR');

        $decoded = json_decode($response, true);
        if (!isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'Gemini response structure unexpected: ' . $response);
        }

        $json_text = $decoded['candidates'][0]['content']['parts'][0]['text'];
        // LLM が LaTeX の \( \frac 等を \\ とエスケープせずに出力すると、不正な JSON になるか、
        // 最悪の場合 \frac → 改ページ文字+"rac" のようにパースは成功するのに中身が壊れる。
        // そのため常に修復を通してからパースする。
        $result = json_decode(self::repair_json_escapes($json_text), true);
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
                'question_text'        => self::normalize_tex((string)$p['question_text']),
                // LLMがリライトを返さなかった場合は原文にフォールバック。
                'stack_question_text'  => self::normalize_tex(
                    (string)($p['stack_question_text'] ?? $p['question_text'])),
                'question_type'        => (string)$p['question_type'],
                'expected_answer_kind' => (string)($p['expected_answer_kind'] ?? 'expression'),
                'variables'            => (array)($p['variables'] ?? []),
                'problem_expression'   => (string)$p['problem_expression'],
                'answer_expression'    => isset($p['answer_expression']) ? (string)$p['answer_expression'] : null,
            ];
        }

        return ['problems' => $problems];
    }

    /**
     * LLM がプロンプトの指示に反して $...$ / $$...$$ 区切りを使った場合の保険。
     * STACK は $ 区切りを表示しないため、\(...\) / \[...\] に機械変換する。
     */
    private static function normalize_tex(string $text): string {
        // $$...$$ → \[...\]
        $text = preg_replace('/\$\$(.+?)\$\$/s', '\\\\[$1\\\\]', $text);
        // $...$ → \(...\)  (エスケープ済み \$ は対象外)
        $text = preg_replace('/(?<!\\\\)\$(.+?)(?<!\\\\)\$/s', '\\\\($1\\\\)', $text);
        return $text;
    }

    /**
     * JSON 文字列内の LaTeX 由来の生バックスラッシュ（\( \) \frac \theta 等）を修復する。
     *
     * そのまま残す有効エスケープは \" \\ \/ \n \uXXXX のみ。
     * \b \f \r \t は JSON としては正規のエスケープだが、この用途では
     * \frac, \beta, \theta, \right 等の LaTeX コマンドである可能性が圧倒的に高く、
     * 放置すると「パースは成功するのに中身が制御文字に化ける」サイレント破損を起こすため、
     * これらも \\ に倒して文字どおりのバックスラッシュとして扱う。
     */
    private static function repair_json_escapes(string $json): string {
        return preg_replace_callback(
            '/\\\\(?:u[0-9a-fA-F]{4}|["\\\\\/n])|\\\\/s',
            function (array $m): string {
                // 有効なエスケープ（長さ2以上でマッチ）はそのまま、それ以外の \ は \\ に。
                return strlen($m[0]) > 1 ? $m[0] : '\\\\';
            },
            $json
        );
    }
}