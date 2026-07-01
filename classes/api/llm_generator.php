<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 3: Claude API を使って OCR 結果（LaTeX JSON）から STACK XML を生成する。
 * 複数問に対応：問題ごとに1回 API を呼び、最後に <quiz> でまとめる。
 */
class llm_generator {

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    private const SYSTEM = <<<'SYS'
あなたはMoodle STACK問題XMLを生成する専門家です。
ユーザーから数学問題の情報を受け取り、以下のルールに従って <question type="stack">...</question> ブロックのみを出力してください。

【必須ルール】
- 出力は <question type="stack"> で始まり </question> で終わること。コードフェンス（```）や説明文は不要。
- <firstnodename>1</firstnodename> を必ず含める（<prt> の末尾・</prt> の直前）
- <node> の <name> は数字文字列のみ（例: <name>1</name>）。"node1" は不可。
- <truenextnode>-1</truenextnode> と <falsenextnode>-1</falsenextnode> を必ず含める
- questionvariables の Maxima 式は必ず明示的な乗算記号（2*x、NOT 2x）
- questiontext には必ず [[input:ans1]] [[validation:ans1]] を含める
- specificfeedback は {@prt1@} を使う

【Answer Test 選択ルール】
- derivative → answertest: ATDiff、testoptions: 変数名（例: x）
- integral   → answertest: ATInt、testoptions: 変数名
- factoring  → answertest: ATFacForm、testoptions: (空)
- expansion  → answertest: ATExpanded、testoptions: (空)
- equation / other → answertest: ATAlgEquiv、testoptions: (空)

【LaTeX → Maxima 変換ルール】
- \frac{a}{b} → a/b
- \sqrt{x} → sqrt(x)
- x^{2} → x^2
- \sin(x) → sin(x)、\cos(x) → cos(x)、\ln(x) → log(x)
- 積分の答えには必ず + c を付ける（例: x^2/2 + c）
- questionvariables で ta（正答）を Maxima 式で定義すること

【XML 構造テンプレート（必ずこの構造に従うこと）】
<question type="stack">
  <name><text>問題 N</text></name>
  <questiontext format="html"><text><![CDATA[<p>問題文</p><p>[[input:ans1]] [[validation:ans1]]</p>]]></text></questiontext>
  <generalfeedback format="html"><text></text></generalfeedback>
  <defaultgrade>1</defaultgrade>
  <penalty>0.1</penalty>
  <hidden>0</hidden>
  <idnumber></idnumber>
  <stackversion><text>2023010400</text></stackversion>
  <questionvariables><text>ta: ...Maxima式...;</text></questionvariables>
  <specificfeedback format="html"><text><![CDATA[{@prt1@}]]></text></specificfeedback>
  <questionnote format="html"><text><![CDATA[{@ta@}]]></text></questionnote>
  <questionsimplify>1</questionsimplify>
  <assumeposreal>0</assumeposreal>
  <prtcorrect format="html"><text><![CDATA[<p>正解です。</p>]]></text></prtcorrect>
  <prtpartiallycorrect format="html"><text><![CDATA[<p>部分的に正解です。</p>]]></text></prtpartiallycorrect>
  <prtincorrect format="html"><text><![CDATA[<p>不正解です。</p>]]></text></prtincorrect>
  <multiplicationsign>dot</multiplicationsign>
  <sqrtsign>1</sqrtsign>
  <complexno>i</complexno>
  <inversetrig>cos-1</inversetrig>
  <logicsymbol>lang</logicsymbol>
  <matrixparens>[</matrixparens>
  <input>
    <name>ans1</name>
    <type>algebraic</type>
    <tans>ta</tans>
    <boxsize>15</boxsize>
    <strictsyntax>1</strictsyntax>
    <insertstars>0</insertstars>
    <syntaxhint></syntaxhint>
    <syntaxattribute>0</syntaxattribute>
    <forbidwords></forbidwords>
    <allowwords></allowwords>
    <forbidfloat>1</forbidfloat>
    <requirelowestterms>0</requirelowestterms>
    <checkanswertype>0</checkanswertype>
    <mustverify>1</mustverify>
    <showvalidation>1</showvalidation>
    <options></options>
  </input>
  <prt>
    <name>prt1</name>
    <value>1</value>
    <autosimplify>1</autosimplify>
    <feedbackstyle>1</feedbackstyle>
    <feedbackvariables><text></text></feedbackvariables>
    <node>
      <name>1</name>
      <description></description>
      <answertest>ATAlgEquiv</answertest>
      <sans>ans1</sans>
      <tans>ta</tans>
      <testoptions></testoptions>
      <quiet>0</quiet>
      <truescoremode>=</truescoremode>
      <truescore>1</truescore>
      <truepenalty></truepenalty>
      <truefeedback format="html"><text></text></truefeedback>
      <truenextnode>-1</truenextnode>
      <falsescoremode>=</falsescoremode>
      <falsescore>0</falsescore>
      <falsepenalty></falsepenalty>
      <falsefeedback format="html"><text></text></falsefeedback>
      <falsenextnode>-1</falsenextnode>
    </node>
    <firstnodename>1</firstnodename>
  </prt>
</question>
SYS;

    /**
     * 複数問の OCR 結果から STACK XML（<quiz> ラッパー付き）を生成する。
     *
     * @param array $problems  gemini_ocr::run() が返す problems 配列
     * @return string  <?xml ...?><quiz>...</quiz> 形式の XML 文字列
     * @throws \moodle_exception APIキー未設定・通信エラー・XML不正のとき
     */
    public function run(array $problems): string {
        $api_key = get_config('qbank_stackgen', 'google_api_key');
        if (empty($api_key)) {
            throw new \moodle_exception('error_llm_api_key_missing', 'qbank_stackgen');
        }

        $question_xmls = [];
        foreach ($problems as $i => $problem) {
            $question_xmls[] = $this->generate_one($api_key, $problem, $i + 1);
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n"
            . implode("\n\n", $question_xmls)
            . "\n</quiz>";
    }

    /**
     * 1問分の STACK XML (<question> ブロック) を生成する。
     */
    private function generate_one(string $api_key, array $problem, int $num): string {
        $qtype     = $problem['question_type'] ?? 'other';
        $variables = $problem['variables']     ?? ['x'];
        $var       = !empty($variables) ? (string)$variables[0] : 'x';
        $expr      = $problem['problem_expression'] ?? '';
        $qtext     = $problem['question_text']      ?? '';
        $answer    = $problem['answer_expression']  ?? null;

        [$answertest, $testopts] = $this->select_answer_test($qtype, $var);

        $user_msg = "以下の数学問題の STACK XML（<question type=\"stack\"> タグのみ）を生成してください。\n\n"
            . "問題番号: {$num}\n"
            . "問題文: {$qtext}\n"
            . "問題タイプ: {$qtype}\n"
            . "主変数: {$var}\n"
            . "LaTeX 式: {$expr}\n"
            . ($answer !== null ? "答え（LaTeX）: {$answer}\n" : "※答えは不明。questionvariables で Maxima を使って ta を計算すること。\n")
            . "\n使用する answertest: {$answertest}"
            . ($testopts !== '' ? "（testoptions: {$testopts}）" : '') . "\n";

        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => self::SYSTEM]]],
            'contents'           => [['parts' => [['text' => $user_msg]]]],
            'generationConfig'   => ['temperature' => 0.1],
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
                "Gemini curl error (problem #{$num}): {$curl_error}");
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini API HTTP {$http_code} (problem #{$num}): {$response}");
        }

        $decoded  = json_decode($response, true);
        $xml_text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($xml_text)) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Claude returned empty response for problem #{$num}");
        }

        // コードフェンス除去
        $xml_text = preg_replace('/^```(?:xml)?\s*/m', '', $xml_text);
        $xml_text = preg_replace('/```\s*$/m', '', $xml_text);
        $xml_text = trim($xml_text);

        // <question type="stack">...</question> ブロックを抽出
        if (preg_match('/<question\b[^>]*type=["\']stack["\'][^>]*>.*?<\/question>/s', $xml_text, $m)) {
            return $m[0];
        }

        // 抽出失敗時はそのまま返す（後続で XML 検証が拾う）
        return $xml_text;
    }

    /**
     * 問題タイプと変数名から Answer Test と testoptions を決定する。
     *
     * @return array [answertest, testoptions]
     */
    private function select_answer_test(string $qtype, string $var): array {
        switch ($qtype) {
            case 'derivative':
                return ['ATDiff', $var];
            case 'integral':
                return ['ATInt', $var];
            case 'factoring':
                return ['ATFacForm', ''];
            case 'expansion':
                return ['ATExpanded', ''];
            default:
                return ['ATAlgEquiv', ''];
        }
    }
}
