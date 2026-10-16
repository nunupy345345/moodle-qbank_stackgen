<?php
namespace qbank_stackgen\api;

use qbank_stackgen\local\kc_catalog;

defined('MOODLE_INTERNAL') || die();

/**
 * KC診断版（別ページ）Stage3: Gemini API を使って OCR 結果（LaTeX JSON）から
 * KC（前提知識、fixtures/kc/kc_master.csv）起点の誤答例を生成する。
 *
 * 既存の `feedback_generator.php`（自由記述の誤答例、既存ページで使用）とは完全に独立した
 * クラス。まずこの問題が直接要求するKCをLLMに判断させ、KCごとに1つ
 * 「そのKCが欠けていたらこう間違う」誤答例を作らせる（前提知識チェーンの遡り・
 * kc_subtypes.csvの細目は対象外。トップレベルKCのみ）。
 *
 * 生成結果は kc_review.php で教員が確認・修正し、確定したものだけが
 * Stage 4（llm_generator、既存クラスをそのまま再利用）のPRT生成プロンプトに組み込まれる。
 */
class kc_feedback_generator {

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    private const SYSTEM = <<<'SYS'
あなたは数学教育の専門家です。与えられた数学問題について、学生がどの前提知識（KC）を欠いていると
間違えるかを判断し、KCごとに想定される誤答とその採点フィードバック（なぜ間違っているか、どこを
見直すべきか）を日本語で作成してください。

プロンプトには前提知識（KC）の一覧（ID・日本語名）が渡されます。この問題を正しく解くために
直接必要なKCをその中から選び、KCごとに1つずつ誤答例を作成すること。

出力は必ず以下の JSON 形式のみとし、説明文やコードフェンスは付けないこと。

{
  "wronganswers": [
    {
      "kc_id": "渡されたKC一覧のIDそのまま（例: D05）",
      "expression": "誤答の数式（Maxima記法。乗算は必ず* を使う。例: 2*x ではなく2xは不可）",
      "feedback": "学生への具体的なフィードバック文（HTML可、日本語、1〜2文程度）"
    }
  ]
}

制約:
- 誤答はこの問題が直接要求するKCの数だけ（目安2〜4個程度）作成すること。KC一覧に無いIDは使わないこと。
- expression は正答と必ず異なる Maxima 式にすること。**単に展開・因数分解しただけで数式的には
  正答と同値になる式（例: 正答が n*(n-10) のとき n^2-10*n）は誤答例として不可。** CAS検証では
  展開前後の同値な式は正答と区別できず、誤答判定ノードとして機能しない。
- feedback は学生が自分の間違いに気づけるよう、具体的な誤りの箇所を指摘すること。
- feedback 内で数式を使う場合の区切りは \( ... \) とし、$ ... $ は使わないこと。
- 【重要】JSON 文字列内のバックスラッシュは必ず \\ とエスケープすること（例: "\\( x \\)"）。
SYS;

    /**
     * 複数問の OCR 結果それぞれについてKC起点の誤答例を生成する。
     *
     * @param array $problems     gemini_ocr::run() が返す problems 配列
     * @param array $ragexamples  Step 6 RAG: $problems と同じ順序で、rag_search::search() の結果配列を並べたもの。
     * @return array  ['problems' => [['wronganswers' => [...]], ...]]  （$problems と同じ順序）
     * @throws \moodle_exception APIキー未設定・通信エラーのとき
     */
    public function run(array $problems, array $ragexamples = []): array {
        $api_key = get_config('qbank_stackgen', 'google_api_key');
        if (empty($api_key)) {
            throw new \moodle_exception('error_llm_api_key_missing', 'qbank_stackgen');
        }

        $result = [];
        foreach ($problems as $i => $problem) {
            $result[] = ['wronganswers' => $this->generate_one($api_key, $problem, $i + 1, $ragexamples[$i] ?? [])];
        }

        return ['problems' => $result];
    }

    /**
     * 1問分のKC起点誤答例を生成する。
     *
     * @param array $examples  Step 6 RAG: この問題に類似した過去の良問（rag_search::search()の結果）
     * @return array  [['expression'=>..., 'kc_id'=>..., 'kc_name'=>..., 'feedback'=>...], ...]
     */
    private function generate_one(string $api_key, array $problem, int $num, array $examples = []): array {
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

        $user_msg .= self::build_kc_block();
        $user_msg .= self::build_rag_block($examples);

        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => self::SYSTEM]]],
            'contents'           => [['parts' => [['text' => $user_msg]]]],
            'generationConfig'   => [
                'temperature'        => 0.2,
                'response_mime_type' => 'application/json',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $url = self::ENDPOINT . '?key=' . urlencode($api_key);

        $response = gemini_http_client::post($url, $body, "kc feedback, problem #{$num}");

        $decoded   = json_decode($response, true);
        $json_text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // LaTeX 由来の生バックスラッシュによる不正 JSON / サイレント破損を防ぐため常に修復してからパースする。
        $parsed = json_decode(self::repair_json_escapes($json_text), true);
        if (!is_array($parsed) || !isset($parsed['wronganswers']) || !is_array($parsed['wronganswers'])) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini KC feedback JSON parse failed (problem #{$num}): {$json_text}");
        }

        $wronganswers = [];
        foreach ($parsed['wronganswers'] as $w) {
            if (empty($w['expression'])) {
                continue;
            }
            $kc_id = (string)($w['kc_id'] ?? '');
            $wronganswers[] = [
                'expression' => (string)$w['expression'],
                'kc_id'      => $kc_id,
                'kc_name'    => $kc_id !== '' ? kc_catalog::label($kc_id) : '',
                'feedback'   => (string)($w['feedback'] ?? ''),
            ];
        }

        return $wronganswers;
    }

    /**
     * この問題が直接要求するKCをLLMに判断させるため、KCカタログ（ID・日本語名）を列挙した文字列を作る。
     */
    private static function build_kc_block(): string {
        $block = "\n前提知識（KC）一覧（この中から、この問題が直接要求するものだけを選ぶこと）:\n";
        foreach (kc_catalog::get_all() as $kc) {
            $block .= "  {$kc['id']}: {$kc['ja']}\n";
        }
        return $block;
    }

    /**
     * Step 6 RAG: 類似する過去の良問（教員手作りコーパス）をFew-shotとしてプロンプトに追記する文字列を作る。
     */
    private static function build_rag_block(array $examples): string {
        if (empty($examples)) {
            return '';
        }

        $block = "\n参考: 過去に教員が作成した類似問題（内容の近さの参考。丸写しではなく、似た誤りの傾向を推測する材料にすること）:\n";
        foreach ($examples as $j => $ex) {
            $n = $j + 1;
            $block .= "  類似問題{$n}（カテゴリ: " . ($ex['category'] ?? '') . "）: " . ($ex['qtext'] ?? '') . "\n";
        }
        return $block;
    }

    /**
     * JSON 文字列内の LaTeX 由来の生バックスラッシュ（\( \) \frac \theta 等）を修復する。
     */
    private static function repair_json_escapes(string $json): string {
        return preg_replace_callback(
            '/\\\\(?:u[0-9a-fA-F]{4}|["\\\\\/n])|\\\\/s',
            function (array $m): string {
                return strlen($m[0]) > 1 ? $m[0] : '\\\\';
            },
            $json
        );
    }
}
