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
   記述すること（chain-of-thought）。ただし5行以内に収めること。この部分はログ保存用であり、
   問題バンクには使われない。
2. 続けて、必ず <question type="stack"> で始まり </question> で終わるブロックを1つだけ出力すること。
   コードフェンス（```）は使わないこと。
3. 出力の最後に、以下の自己チェックを行い、満たしていない場合は修正してから出力すること。
   - <prt> 要素が1つ以上存在するか（存在しない出力は不正。<input> の直後に必ず <prt> を置くこと）
   - <specificfeedback> の中身が [[feedback:prt1]] になっているか（{@prt1@} は誤りなので不可）
   - firstnodename が存在するか
   - すべての <node><name> が数字文字列のみか（"node1"のような文字列名は不可）
   - すべての node に truenextnode と falsenextnode があるか
   - falsenextnode / truenextnode が参照するノード名がすべて実在するか（終端は -1）
   - 問題文・フィードバック内の数式区切りがすべて \( \) または \[ \] か（$...$ は不可）
   - questionvariables で定義した wa1, wa2, ... がすべていずれかのノードの tans で使われているか

【必須ルール】
- <prt> 要素を必ず1つ以上含めること。PRT のない <question> は不正な出力である。
- <firstnodename>1</firstnodename> を必ず含める（<prt> の末尾・</prt> の直前）
- <node> の <name> は数字文字列のみ（例: <name>1</name>）。"node1" は不可。
- <truenextnode>-1</truenextnode> と <falsenextnode>-1</falsenextnode> を必ず含める（-1 = 終端）
- questionvariables の Maxima 式は必ず明示的な乗算記号（2*x、NOT 2x）
- questiontext には必ず [[input:ans1]] [[validation:ans1]] を含める
- specificfeedback は必ず [[feedback:prt1]] とする（{@prt1@} は CAS 変数の埋め込み記法であり誤り。使用禁止）
- 数式の区切りは \( \)（インライン）と \[ \]（別行立て）のみ。$...$ と $$...$$ は使用禁止。
  入力の問題文に $...$ が含まれていた場合も、必ず \( \) に置き換えて出力すること。
- generalfeedback には模範解答（解き方の手順を数式付きで2〜5文程度）を必ず書くこと。
  これは正誤にかかわらず全学生に表示される解説である。空にしてはいけない。
- questiontext は「解答欄1つ」で答えられる問いになっていること。渡された問題文が
  複数の答えを要求している場合は、主要な1つ（通常は最初に問われているもの）を問う形に
  書き換えること。
- <input> の <type> は必ず algebraic にすること。「学生が入力するもの」が equation（方程式）や
  value（数値）であっても、STACK に "equation" や "value" という入力タイプは存在しない。
  方程式（例: y=3(x+3)^2+2）も数値も algebraic 型でそのまま受け付けられるので、
  <type>equation</type> のような存在しない入力タイプを書いてはならない。

【Answer Test 選択ルール】
- derivative → answertest: Diff、testoptions: 変数名（例: x）
- integral   → answertest: Int、testoptions: 変数名
- factoring  → answertest: FacForm、testoptions: (空)
- expansion  → answertest: Expanded、testoptions: (空)
- equation / other → answertest: AlgEquiv、testoptions: (空)
※ ここに書く名前は XML の <answertest> タグに入れる「公開名」であり、
  Maxima 内部の関数名（AT が付く形、例: ATAlgEquiv）とは異なる。<answertest> に AT を
  付けた名前を書くと Moodle 側でエラーになるので、AT を付けないこと。

【LaTeX → Maxima 変換ルール】
- \frac{a}{b} → a/b
- \sqrt{x} → sqrt(x)
- x^{2} → x^2
- \sin(x) → sin(x)、\cos(x) → cos(x)、\ln(x) → log(x)
- 積分の答えには必ず + c を付ける（例: x^2/2 + c）
- questionvariables で ta（正答）を Maxima 式で定義すること

【誤答例が渡された場合の PRT 分岐設計ルール（重要）】
教員が確定した誤答例が渡された場合、正答判定ノードに続けて、誤答例の数だけ判定ノードを直列に追加すること。
実際に教員が作り込んだ質の高いPRT問題を分析した結果、誤答には常に0点を与えるのではなく、
誤りの重大さに応じてtruescoreを段階的に配点しているものほど質が高い（採点の解像度が高い）とわかっている。

- ノード1: 学生解答（ans1）と正答（ta）を選択済みの answertest で比較する。
  - true（正解） → truescore 1、truenextnode -1（終端）、trueanswernote "prt1-1-T"
  - false（不正解） → falsenextnode は次の誤答判定ノード（誤答例がなければ -1）
- ノード2以降（誤答例ごとに1ノード）: 学生解答（ans1）と誤答例の式（wa1, wa2, ... を questionvariables で定義）を
  ATAlgEquiv で比較する。
  - true（その誤答と一致） → **truescore を 0 固定にしないこと。** 教員が入力した「想定原因」の重大さを見て
    0.1〜0.9 の範囲で個別に配点する（目安: 符号ミス・積分定数忘れ等の軽微な計算ミス→0.7〜0.9、
    公式自体を取り違えている等の概念的な誤り→0.1〜0.3）。誤答例が複数ある場合、
    全ノードのtruescoreが同じ値にならないよう、誤りごとに差をつけること。
    truenextnode -1（終端）、truefeedback に教員が確定したフィードバック文をそのまま使う（HTML可）。
  - false（一致しない） → falsenextnode は次の誤答判定ノードの name（最後のノードなら -1、
    その場合 falsefeedback は「不正解です。」等の汎用フィードバックにする）
- 誤答例が渡されなかった場合は、従来通り正誤2択（AlgEquiv 1ノード）で構成すること。

【誤答例ありの場合の PRT 構造例（積分・誤答例2個: wa1, wa2）】
※ node1 の answertest は「Answer Test選択ルール」に従って問題タイプごとに変わる（この例は積分なので Int）。
   node2以降の誤答判定ノードは常に ATAlgEquiv（学生解答が誤答例の式と数式的に一致するか）でよい。
※ 下例では紙幅の都合で省略しているが、実際の出力では各 <node> に
   <description></description> <truepenalty></truepenalty> <falsepenalty></falsepenalty> も含めること。
<questionvariables><text>ta: x^2/2 + c; wa1: x^2/2; wa2: x^2 + c;</text></questionvariables>
...
<prt>
  <name>prt1</name>
  <value>1</value>
  <autosimplify>1</autosimplify>
  <feedbackstyle>1</feedbackstyle>
  <feedbackvariables><text></text></feedbackvariables>
  <node>
    <name>1</name>
    <answertest>Int</answertest>
    <sans>ans1</sans>
    <tans>ta</tans>
    <testoptions>x</testoptions>
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
    <truescore>0.9</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-2-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>積分定数 C を忘れています。忘れずに付け加えましょう。</p>]]></text></truefeedback>
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
    <truescore>0.2</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-3-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>べき乗の積分公式（次数を1つ上げて次数で割る）を確認しましょう。</p>]]></text></truefeedback>
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
  <generalfeedback format="html"><text><![CDATA[<p>（ここに模範解答: 解き方の手順を \( \) 区切りの数式付きで記述）</p>]]></text></generalfeedback>
  <defaultgrade>1</defaultgrade>
  <penalty>0.1</penalty>
  <hidden>0</hidden>
  <idnumber></idnumber>
  <stackversion><text>2026042200</text></stackversion>
  <questionvariables><text>ta: ...Maxima式...;</text></questionvariables>
  <specificfeedback format="html"><text><![CDATA[<p>[[feedback:prt1]]</p>]]></text></specificfeedback>
  <questionnote format="html"><text><![CDATA[{@ta@}]]></text></questionnote>
  <questionsimplify>1</questionsimplify>
  <assumepositive>0</assumepositive>
  <assumereal>0</assumereal>
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
     * @param string $lasterror  直前の試行がCAS検証(Stage 5)で失敗した場合のエラー内容。
     *                           再生成時にLLMへ伝え、同じ間違いを繰り返しにくくする。空でもよい。
     * @param array $ragexamples  Step 6 RAG: $problems と同じ順序で、rag_search::search() の結果配列を並べたもの。
     * @return array  ['xml' => "<?xml...><quiz>...</quiz>", 'cot' => "問題ごとの思考過程を連結したテキスト"]
     * @throws \moodle_exception APIキー未設定・通信エラー・XML不正のとき
     */
    public function run(array $problems, array $feedbackproblems = [], string $lasterror = '', array $ragexamples = []): array {
        $api_key = get_config('qbank_stackgen', 'google_api_key');
        if (empty($api_key)) {
            throw new \moodle_exception('error_llm_api_key_missing', 'qbank_stackgen');
        }

        $question_xmls = [];
        $cots          = [];
        foreach ($problems as $i => $problem) {
            $name         = $feedbackproblems[$i]['name'] ?? '';
            $wronganswers = $feedbackproblems[$i]['wronganswers'] ?? [];
            [$xml, $cot]  = $this->generate_one(
                $api_key, $problem, $i + 1, $wronganswers, $name, $lasterror, $ragexamples[$i] ?? []);
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
     * @param string $name  教員がレビュー画面で確定した問題名。空ならLLM任せの名前をそのまま使う。
     * @param string $lasterror  直前の試行のCAS検証エラー内容。空でなければ再生成時にLLMへ伝える。
     * @param array $examples  Step 6 RAG: この問題に類似した過去の良問（rag_search::search()の結果）
     * @return array [xml, cot]
     */
    private function generate_one(
        string $api_key, array $problem, int $num, array $wronganswers, string $name = '', string $lasterror = '',
        array $examples = []
    ): array {
        $qtype     = $problem['question_type'] ?? 'other';
        $variables = $problem['variables']     ?? ['x'];
        $var       = !empty($variables) ? (string)$variables[0] : 'x';
        $expr      = $problem['problem_expression'] ?? '';
        // OCR段階でSTACK用（解答欄1つで答えられる形）にリライト済みの問題文を優先して使う。
        $qtext     = $problem['stack_question_text'] ?? ($problem['question_text'] ?? '');
        $anskind   = $problem['expected_answer_kind'] ?? 'expression';
        $answer    = $problem['answer_expression']  ?? null;

        [$answertest, $testopts] = $this->select_answer_test($qtype, $var);

        $user_msg = "以下の数学問題の STACK XML（<question type=\"stack\"> タグのみ、PRT付き）を生成してください。\n\n"
            . "問題番号: {$num}\n"
            . "問題文（この文をそのまま questiontext に使うこと。数式区切りは \\( \\) を維持）: {$qtext}\n"
            . "問題タイプ: {$qtype}\n"
            . "学生が入力するもの: {$anskind}（expression=数式 / value=数値1つ / equation=方程式）\n"
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

        if ($lasterror !== '') {
            $user_msg .= "\n前回の生成はCAS検証（Maximaでの数式チェック）で以下のエラーが検出されました。"
                . "同じ間違いを繰り返さないよう修正して再生成すること:\n{$lasterror}\n";
        }

        $user_msg .= self::build_rag_block($examples);

        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => self::SYSTEM]]],
            'contents'           => [['parts' => [['text' => $user_msg]]]],
            'generationConfig'   => ['temperature' => 0.1],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $url = self::ENDPOINT . '?key=' . urlencode($api_key);

        $response = gemini_http_client::post($url, $body, "problem #{$num}");

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

            // 教員がレビュー画面で確定した問題名があれば、LLMが生成した<name>を
            // 決定的に上書きする（LLMにプロンプトで"守らせる"のではなく、後処理で確定させる）。
            if ($name !== '') {
                $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $xml = preg_replace(
                    '/<name>\s*<text>.*?<\/text>\s*<\/name>/s',
                    "<name><text>{$safe_name}</text></name>",
                    $xml, 1
                );
            }

            // 保険: <input>の<type>にequation/valueなど存在しないSTACK入力タイプが
            // 混入していたら algebraic に強制的に置き換える（STACKはこれを"unknown input type"
            // として問題バンク登録時にエラーにするため、事前に確実に潰しておく）。
            $xml = preg_replace('/<type>(?!algebraic<\/type>)[^<]*<\/type>/', '<type>algebraic</type>', $xml, 1);

            // 保険: $...$ / $$...$$ 区切りが残っていたら \(...\) / \[...\] に変換する。
            $xml = preg_replace('/\$\$(.+?)\$\$/s', '\\\\[$1\\\\]', $xml);
            $xml = preg_replace('/(?<!\\\\)\$(.+?)(?<!\\\\)\$/s', '\\\\($1\\\\)', $xml);

            // 最低限の門番チェック: 採点不能な問題を問題バンクに入れない。
            // (STACKはPRTゼロの問題もエラーなしでインポートしてしまうため、ここで止める)
            $fatal = [];
            if (strpos($xml, '<prt>') === false) {
                $fatal[] = '<prt> element is missing (question would be un-gradable)';
            }
            if (strpos($xml, '{@prt1@}') !== false) {
                $fatal[] = 'specificfeedback uses {@prt1@}; must be [[feedback:prt1]]';
            }
            if (strpos($xml, '<firstnodename>') === false && strpos($xml, '<prt>') !== false) {
                $fatal[] = '<firstnodename> is missing inside <prt>';
            }
            if (!empty($fatal)) {
                throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                    "Generated XML failed structural check (problem #{$num}): "
                    . implode(' / ', $fatal) . "\n--- raw output ---\n" . $raw_text);
            }

            return [$xml, $cot];
        }

        // 抽出失敗時はそのまま返す（後続で XML 検証が拾う）
        return [$raw_text, ''];
    }

    /**
     * Step 6 RAG: 類似する過去の良問のPRT構造的特徴をFew-shotとしてプロンプトに追記する文字列を作る。
     * 教員手作りの良問データセット分析(fixtures/prt/README.md)で確認された「段階的部分点チェーン」
     * 「専用Answer Testの使用」の傾向を、類似問題の実測値として具体的に示す。
     */
    private static function build_rag_block(array $examples): string {
        if (empty($examples)) {
            return '';
        }

        $block = "\n参考: 過去に教員が作成した類似問題のPRT構造（内容の丸写しではなく、"
            . "ノード数・Answer Test・段階的部分点の付け方の参考にすること）:\n";
        foreach ($examples as $j => $ex) {
            $n = $j + 1;
            $tests  = !empty($ex['distinct_tests'])  ? implode(', ', $ex['distinct_tests'])  : '(不明)';
            $scores = !empty($ex['distinct_scores']) ? implode(', ', $ex['distinct_scores']) : '(不明)';
            $block .= "  類似問題{$n}: 問題文=" . ($ex['qtext'] ?? '') . "\n"
                . "    PRTノード数=" . ($ex['n_nodes'] ?? '?') . "、使用Answer Test=[{$tests}]、"
                . "truescoreの段階=[{$scores}]\n";
        }
        return $block;
    }

    /**
     * 問題タイプと変数名から Answer Test と testoptions を決定する。
     *
     * 注意: ここで返す文字列は XML の <answertest> タグに入れる「公開名」であり、
     * Maxima 内部の関数名（AT が付く形、例: ATAlgEquiv）とは異なる。
     * AT を付けた名前を <answertest> に書くと、STACK 側の設定配列に見つからず
     * "Undefined array key" 警告が出て PRT が正しく動作しない（採点不能になる）。
     *
     * @return array [answertest, testoptions]
     */
    private function select_answer_test(string $qtype, string $var): array {
        switch ($qtype) {
            case 'derivative':
                return ['Diff', $var];
            case 'integral':
                return ['Int', $var];
            case 'factoring':
                return ['FacForm', ''];
            case 'expansion':
                return ['Expanded', ''];
            default:
                return ['AlgEquiv', ''];
        }
    }
}