<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 4: Gemini API を使って OCR 結果（LaTeX JSON）＋教員確定済みの誤答例/フィードバックから
 * PRT付き STACK XML を生成する。
 * 複数問に対応：問題ごとに1回 API を呼び、最後に <quiz> でまとめる。
 *
 * 生成時は chain-of-thought（PRT分岐の設計根拠）も出力させ、<question>...</question> ブロックのみを
 * 抽出して問題バンク用XMLとする。CoT部分は呼び出し元でDB保存用に別途返す。
 */
class llm_generator {

    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    private const SYSTEM = <<<'SYS'
あなたはMoodle STACK問題XMLを生成する専門家です。
ユーザーから数学問題の情報と、教員が確定した誤答例・フィードバックのリストを受け取り、以下のルールに従って
PRT（採点ロジック）付きの <question type="stack">...</question> ブロックを生成してください。

【出力形式（最重要）】
1. まず、PRTの分岐構成をどう設計したか（どの誤答をどのノードで判定し、どの順番に並べたか）を日本語で
   簡潔に記述すること（chain-of-thought）。この部分はログ保存用であり、問題バンクには使われない。
2. 続けて、必ず <question type="stack"> で始まり </question> で終わるブロックを1つだけ出力すること。
   コードフェンス（```）は使わないこと。
3. 出力の最後に、以下の自己チェックを行い、満たしていない場合は修正してから出力すること。
   - firstnodename が存在するか
   - すべての <node><name> が数字文字列のみか（"node1"のような文字列名は不可）
   - すべての node に truenextnode と falsenextnode があるか

【必須ルール】
- <firstnodename>1</firstnodename> を必ず含める（<prt> の末尾・</prt> の直前）
- <node> の <name> は数字文字列のみ（例: <name>1</name>）。"node1" は不可。
- <truenextnode>-1</truenextnode> と <falsenextnode>-1</falsenextnode> を必ず含める（-1 = 終端）
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

【誤答例が渡された場合の PRT 分岐設計ルール（重要）】
教員が確定した誤答例が渡された場合、正答判定ノードに続けて、誤答例の数だけ判定ノードを直列に追加すること。

- ノード1: 学生解答（ans1）と正答（ta）を選択済みの answertest で比較する。
  - true（正解） → truescore 1、truenextnode -1（終端）、trueanswernote "prt1-1-T"
  - false（不正解） → falsenextnode は次の誤答判定ノード（誤答例がなければ -1）
- ノード2以降（誤答例ごとに1ノード）: 学生解答（ans1）と誤答例の式（wa1, wa2, ... を questionvariables で定義）を
  ATAlgEquiv で比較する。
  - true（その誤答と一致） → falsescoremode/falsescore ではなく truescore 0、truenextnode -1（終端）、
    truefeedback に教員が確定したフィードバック文をそのまま使う（HTML可）
  - false（一致しない） → falsenextnode は次の誤答判定ノードの name（最後のノードなら -1、
    その場合 falsefeedback は「不正解です。」等の汎用フィードバックにする）
- 誤答例が渡されなかった場合は、従来通り正誤2択（AlgEquiv 1ノード）で構成すること。

【誤答例ありの場合の PRT 構造例（誤答例2個: wa1, wa2）】
<questionvariables><text>ta: x^2+2*x+1; wa1: x^2-2*x+1; wa2: x^2+2*x-1;</text></questionvariables>
...
<prt>
  <name>prt1</name>
  <value>1</value>
  <autosimplify>1</autosimplify>
  <feedbackstyle>1</feedbackstyle>
  <feedbackvariables><text></text></feedbackvariables>
  <node>
    <name>1</name>
    <answertest>AlgEquiv</answertest>
    <sans>ans1</sans>
    <tans>ta</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>=</truescoremode>
    <truescore>1</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-1-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>正解です。</p>]]></text></truefeedback>
    <falsescoremode>=</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>2</falsenextnode>
    <falseanswernote>prt1-1-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>2</name>
    <answertest>AlgEquiv</answertest>
    <sans>ans1</sans>
    <tans>wa1</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>=</truescoremode>
    <truescore>0</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-2-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>（誤答1のフィードバック文）</p>]]></text></truefeedback>
    <falsescoremode>=</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>3</falsenextnode>
    <falseanswernote>prt1-2-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>3</name>
    <answertest>AlgEquiv</answertest>
    <sans>ans1</sans>
    <tans>wa2</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>=</truescoremode>
    <truescore>0</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-3-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>（誤答2のフィードバック文）</p>]]></text></truefeedback>
    <falsescoremode>=</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>-1</falsenextnode>
    <falseanswernote>prt1-3-F</falseanswernote>
    <falsefeedback format="html"><text><![CDATA[<p>不正解です。</p>]]></text></falsefeedback>
  </node>
  <firstnodename>1</firstnodename>
</prt>

【XML 全体テンプレート（誤答例がない場合はこの1ノード構成に従うこと）】
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
      <answertest>AlgEquiv</answertest>
      <sans>ans1</sans>
      <tans>ta</tans>
      <testoptions></testoptions>
      <quiet>0</quiet>
      <truescoremode>=</truescoremode>
      <truescore>1</truescore>
      <truepenalty></truepenalty>
      <truefeedback format="html"><text></text></truefeedback>
      <truenextnode>-1</truenextnode>
      <trueanswernote>prt1-1-T</trueanswernote>
      <falsescoremode>=</falsescoremode>
      <falsescore>0</falsescore>
      <falsepenalty></falsepenalty>
      <falsenextnode>-1</falsenextnode>
      <falseanswernote>prt1-1-F</falseanswernote>
      <falsefeedback format="html"><text></text></falsefeedback>
    </node>
    <firstnodename>1</firstnodename>
  </prt>
</question>
SYS;

    /**
     * 複数問の OCR 結果＋確定済み誤答例/フィードバックから STACK XML（<quiz> ラッパー付き）を生成する。
     *
     * @param array $problems          gemini_ocr::run() が返す problems 配列
     * @param array $feedbackproblems  feedback_generator::run() 相当の ['wronganswers'=>[...]] を $problems と
     *                                 同じ順序で並べた配列（教員が review.php で確定したもの）。空でもよい。
     * @return array  ['xml' => "<?xml...><quiz>...</quiz>", 'cot' => "問題ごとの思考過程を連結したテキスト"]
     * @throws \moodle_exception APIキー未設定・通信エラー・XML不正のとき
     */
    public function run(array $problems, array $feedbackproblems = []): array {
        $api_key = get_config('qbank_stackgen', 'google_api_key');
        if (empty($api_key)) {
            throw new \moodle_exception('error_llm_api_key_missing', 'qbank_stackgen');
        }

        $question_xmls = [];
        $cots          = [];
        foreach ($problems as $i => $problem) {
            $wronganswers = $feedbackproblems[$i]['wronganswers'] ?? [];
            [$xml, $cot]  = $this->generate_one($api_key, $problem, $i + 1, $wronganswers);
            $question_xmls[] = $xml;
            if ($cot !== '') {
                $cots[] = "--- 問題{$i}のCoT ---\n{$cot}";
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n"
            . implode("\n\n", $question_xmls)
            . "\n</quiz>";

        return ['xml' => $xml, 'cot' => implode("\n\n", $cots)];
    }

    /**
     * 1問分の STACK XML (<question> ブロック) を生成する。
     *
     * @param array $wronganswers  [['expression'=>..., 'misconception'=>..., 'feedback'=>...], ...]
     * @return array [xml, cot]
     */
    private function generate_one(string $api_key, array $problem, int $num, array $wronganswers): array {
        $qtype     = $problem['question_type'] ?? 'other';
        $variables = $problem['variables']     ?? ['x'];
        $var       = !empty($variables) ? (string)$variables[0] : 'x';
        $expr      = $problem['problem_expression'] ?? '';
        $qtext     = $problem['question_text']      ?? '';
        $answer    = $problem['answer_expression']  ?? null;

        [$answertest, $testopts] = $this->select_answer_test($qtype, $var);

        $user_msg = "以下の数学問題の STACK XML（<question type=\"stack\"> タグのみ、PRT付き）を生成してください。\n\n"
            . "問題番号: {$num}\n"
            . "問題文: {$qtext}\n"
            . "問題タイプ: {$qtype}\n"
            . "主変数: {$var}\n"
            . "LaTeX 式: {$expr}\n"
            . ($answer !== null ? "答え（LaTeX）: {$answer}\n" : "※答えは不明。questionvariables で Maxima を使って ta を計算すること。\n")
            . "\n使用する answertest: {$answertest}"
            . ($testopts !== '' ? "（testoptions: {$testopts}）" : '') . "\n";

        if (!empty($wronganswers)) {
            $user_msg .= "\n教員が確定した誤答例（この順番でノードを直列に追加すること）:\n";
            foreach ($wronganswers as $j => $w) {
                $n = $j + 1;
                $user_msg .= "  誤答{$n}: 式=" . ($w['expression'] ?? '') . "、"
                    . "想定原因=" . ($w['misconception'] ?? '') . "、"
                    . "フィードバック=" . ($w['feedback'] ?? '') . "\n";
            }
        } else {
            $user_msg .= "\n誤答例は確定されていません。正誤2択（AlgEquiv 1ノード）で構成してください。\n";
        }

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
        $raw_text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($raw_text)) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "Gemini returned empty response for problem #{$num}");
        }

        // コードフェンス除去
        $raw_text = preg_replace('/^```(?:xml)?\s*/m', '', $raw_text);
        $raw_text = preg_replace('/```\s*$/m', '', $raw_text);
        $raw_text = trim($raw_text);

        // <question type="stack">...</question> ブロックを抽出。それ以外の部分は CoT として保持する。
        if (preg_match('/<question\b[^>]*type=["\']stack["\'][^>]*>.*?<\/question>/s', $raw_text, $m, PREG_OFFSET_CAPTURE)) {
            $xml = $m[0][0];
            $cot = trim(substr($raw_text, 0, $m[0][1]));
            return [$xml, $cot];
        }

        // 抽出失敗時はそのまま返す（後続で XML 検証が拾う）
        return [$raw_text, ''];
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
