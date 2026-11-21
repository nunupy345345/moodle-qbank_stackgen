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

    private const SYSTEM_BASE = <<<'SYS'
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
   - PRT の <feedbackvariables> で定義した ans2, ans3, ... がすべていずれかのノードで使われているか（KC診断版は誤答例の式を PRT に書かない）
   - 誤答例の式を <questionvariables> に書いていないか（書くなら <prt> の <feedbackvariables> に）
   - すべてのノードの <description> が空か
   - すべての開始タグと終了タグの名前が一致しているか（例: <truescore>...</truescoremode> の
     ように、開始タグと違うタグ名で閉じてしまっているところがないか、出力全体を見直すこと）

【必須ルール】
- <prt> 要素を必ず1つ以上含めること。PRT のない <question> は不正な出力である。
- <firstnodename>1</firstnodename> を必ず含める（<prt> の末尾・</prt> の直前）
- <node> の <name> は数字文字列のみ（例: <name>1</name>）。"node1" は不可。
- <truenextnode>-1</truenextnode> と <falsenextnode>-1</falsenextnode> を必ず含める（-1 = 終端）
- questionvariables の Maxima 式は必ず明示的な乗算記号（2*x、NOT 2x）
- 誤答例の式（ans2, ans3, ...）は questionvariables には書かず、<prt> の <feedbackvariables> に定義すること
  （教員が実際に作る問題と同じ書き方）。<feedbackvariables> の定義は1つ1行とし、各行を必ず ; で
  終えること。<feedbackvariables> の中でも expr と ans は参照できる。
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
- derivative → answertest: AlgEquiv、testoptions: (空)（sans は ans1、tans は ans = diff(expr, x)）
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
- questionvariables で expr（問題の式）と ans（正答）を Maxima 式で定義すること（定義の仕方は後述の変数名ルールに従う）

SYS;

    // 誤答判定ノードの分岐方式（通常版・RAGなし版）: 「早期終了型」。
    // 誤答例の式（ans2, ans3, ...）とリテラルに一致するか ATAlgEquiv で判定し、
    // 一致した時点で打ち切る（早期終了）。変数名・ノード構成は、教員が実際に作った問題
    // （数学入門の良問）の書き方に合わせてある。
    private const SYSTEM_EARLY_EXIT = <<<'SYS'
【変数名の決め方（教員が作る問題と同じにすること）】
- <questionvariables> には expr（問題の式）と ans（正答）を定義する。
  - 微分: expr に微分する関数、ans: diff(expr, x);
  - 不定積分: expr に被積分関数、ans: integrate(expr, x);（積分定数は付けない。Maximaで閉じた式が
    得られない場合のみ、手計算した原始関数を明記する）
  - 定積分: expr に被積分関数、ans に積分値（数値または式）
  - それ以外: expr に問題の式、ans に正答
- 誤答例の式は <prt> の <feedbackvariables> に ans2, ans3, ... として定義する（1つ目の誤答が ans2）。
  ans1 は学生の入力欄の名前なので、変数名には使わない。1行に1つ、各行を ; で終える。
- <input> の <tans> は、不定積分のとき ans+C、それ以外は ans とする。
- <questionnote> は {@expr@} とする。
- 各ノードの <description> は必ず空にする（<description></description>）。

【誤答例が渡された場合の PRT 分岐設計ルール（重要）】
教員が確定した誤答例が渡された場合、正答判定ノードに続けて、誤答例の数だけ判定ノードを直列に追加すること。
実際に教員が作り込んだ質の高いPRT問題を分析した結果、誤答には常に0点を与えるのではなく、
誤りの重大さに応じてtruescoreを段階的に配点しているものほど質が高い（採点の解像度が高い）とわかっている。

- ノード1（正答判定）: 学生解答（ans1）と正答（ans）を、選択済みの answertest で比較する。
  - true（正解） → truescoremode "="、truescore 1、truenextnode -1（終端）、truefeedback は空
  - false（不正解） → falsescoremode "="、falsescore 0、falsenextnode は次のノード（なければ -1）
- 【不定積分のときだけ】ノード2: 学生が積分定数を付け忘れた答えなど、「導関数は被積分関数に一致する」
  答えを拾うノードを必ず置く。answertest は AlgEquiv、sans は diff(ans1, x)、tans は expr、
  true（導関数が一致） → truescoremode "+"、truescore 0.9、truenextnode -1、truefeedback は空。
  false → falsescoremode "-"、falsescore 0、falsenextnode は次のノード（なければ -1）。
  「積分定数の付け忘れ」は、このノードが担当するので、誤答例としては扱わない。
- 誤答判定ノード（誤答例ごとに1ノード、上のノードの後ろに直列に並べる）:
  - 不定積分以外: answertest は AlgEquiv、sans は ans1、tans は ans2, ans3, ...（誤答例の順）。
  - 不定積分: answertest は AlgEquiv、sans は diff(ans1, x)、tans は diff(ans2, x), diff(ans3, x), ...
    （積分定数の有無・式の形に左右されず、導関数どうしで判定する）。
  - true（その誤答と一致） → truescoremode "+"。**truescore を 0 固定にしないこと。** 教員が入力した
    「想定原因」の重大さを見て 0.1〜0.8 の範囲で個別に配点する（目安: 符号ミス等の軽微な計算ミス→0.5〜0.8、
    公式自体を取り違えている等の概念的な誤り→0.1〜0.3）。誤答例が複数ある場合、全ノードのtruescoreが
    同じ値にならないよう、誤りごとに差をつけること。truenextnode -1（終端）、truefeedback に教員が
    確定したフィードバック文をそのまま使う（HTML可）。
  - false（一致しない） → falsescoremode "-"、falsescore 0、falsenextnode は次の誤答判定ノードの name
    （最後のノードなら -1）。
- すべてのノードで falsefeedback は空にする（全体の不正解メッセージは prtincorrect が表示するため）。
- 誤答例が渡されなかった場合は、従来通り正誤2択（1ノード）で構成すること。
- **誤答例の式（ans2, ans3, ...）は、正答 ans と数式的に同値になってはいけない。** 特に因数分解・展開の問題で
  「展開する前／後の同じ式」を誤答例にすると、AlgEquivでは正答と区別できず誤答判定ノードとして意味を
  なさない（CAS検証で弾かれる）。不定積分では、誤答例の導関数が被積分関数 expr と一致してもいけない
  （導関数が一致する答えは、上のノード2が担当するため）。誤答例は必ず、実際に起こりうる計算ミスにすること。
- 誤答例の式に積分定数を含める場合は C を使う（例: ... + C）。

【PRT 構造例1（微分・誤答例3個: ans2〜ans4）】
※ 教員が実際に作った問題（cos(x)^3 の微分）の構成を、この出力形式に合わせたもの。
   教員は誤答の式を PRT の <feedbackvariables> に置き、誤りの原因を短く示すフィードバック文
   （例:「合成関数の微分を思い出しましょう。」）を付けている。同じ書き方にすること。
※ 下例では紙幅の都合で省略しているが、実際の出力では各 <node> に
   <description></description> <truepenalty></truepenalty> <falsepenalty></falsepenalty> も含めること。
<questionvariables><text>expr: cos(x)^3;
ans: diff(expr, x);</text></questionvariables>
...
<prt>
  <name>prt1</name>
  <value>1</value>
  <autosimplify>1</autosimplify>
  <feedbackstyle>1</feedbackstyle>
  <feedbackvariables><text>ans2: 3*cos(x)^2*sin(x);
ans3: 3*cos(x)^2;
ans4: -3*sin(x)^2;</text></feedbackvariables>
  <node>
    <name>1</name>
    <answertest>AlgEquiv</answertest>
    <sans>ans1</sans>
    <tans>ans</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>=</truescoremode>
    <truescore>1</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-1-T</trueanswernote>
    <truefeedback format="html"><text></text></truefeedback>
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
    <tans>ans2</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.7</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-2-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>三角関数の微分を確認しましょう。</p>]]></text></truefeedback>
    <falsescoremode>-</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>3</falsenextnode>
    <falseanswernote>prt1-2-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>3</name>
    <answertest>AlgEquiv</answertest>
    <sans>ans1</sans>
    <tans>ans3</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.3</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-3-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>合成関数の微分を思い出しましょう。</p>]]></text></truefeedback>
    <falsescoremode>-</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>4</falsenextnode>
    <falseanswernote>prt1-3-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>4</name>
    <answertest>AlgEquiv</answertest>
    <sans>ans1</sans>
    <tans>ans4</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.1</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-4-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>合成関数の微分を思い出しましょう。</p>]]></text></truefeedback>
    <falsescoremode>-</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>-1</falsenextnode>
    <falseanswernote>prt1-4-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <firstnodename>1</firstnodename>
</prt>

【PRT 構造例2（不定積分・誤答例2個: ans2, ans3）】
※ 教員が実際に作った問題（x*exp(-3*x) の不定積分）の構成を、この出力形式に合わせたもの。
   ノード2は「導関数が被積分関数に一致する答え（積分定数の付け忘れなど）」を拾う不定積分共通のノード。
   誤答判定ノードは diff(ans1, x) と diff(ansN, x) の比較で判定している。
<questionvariables><text>expr: x*exp(-3*x);
ans: integrate(expr, x);</text></questionvariables>
...
<input> ... <tans>ans+C</tans> ... </input>
<prt>
  <name>prt1</name>
  <value>1</value>
  <autosimplify>1</autosimplify>
  <feedbackstyle>1</feedbackstyle>
  <feedbackvariables><text>ans2: -x*exp(-3*x)/3 + exp(-3*x)/9 + C;
ans3: x*exp(-3*x) + exp(-3*x)/3 + C;</text></feedbackvariables>
  <node>
    <name>1</name>
    <answertest>Int</answertest>
    <sans>ans1</sans>
    <tans>ans</tans>
    <testoptions>x</testoptions>
    <quiet>0</quiet>
    <truescoremode>=</truescoremode>
    <truescore>1</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-1-T</trueanswernote>
    <truefeedback format="html"><text></text></truefeedback>
    <falsescoremode>=</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>2</falsenextnode>
    <falseanswernote>prt1-1-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>2</name>
    <answertest>AlgEquiv</answertest>
    <sans>diff(ans1, x)</sans>
    <tans>expr</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.9</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-2-T</trueanswernote>
    <truefeedback format="html"><text></text></truefeedback>
    <falsescoremode>-</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>3</falsenextnode>
    <falseanswernote>prt1-2-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>3</name>
    <answertest>AlgEquiv</answertest>
    <sans>diff(ans1, x)</sans>
    <tans>diff(ans2, x)</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.6</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-3-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>部分積分の符号を確認しましょう。</p>]]></text></truefeedback>
    <falsescoremode>-</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>4</falsenextnode>
    <falseanswernote>prt1-3-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>4</name>
    <answertest>AlgEquiv</answertest>
    <sans>diff(ans1, x)</sans>
    <tans>diff(ans3, x)</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.2</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-4-T</trueanswernote>
    <truefeedback format="html"><text><![CDATA[<p>部分積分の公式を確認しましょう。</p>]]></text></truefeedback>
    <falsescoremode>-</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>-1</falsenextnode>
    <falseanswernote>prt1-4-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <firstnodename>1</firstnodename>
</prt>
SYS;

    // KC診断版の分岐方式: 「chain型」。授業で実際に出題されたchain型PRT（fixtures/prt/chain_BKT、
    // 解答ログをBKTで解析したもの）の設計に合わせる。ノード1が正誤判定、ノード2以降はKCごとに1ノードで、
    // 各ノードは「そのKCを使えている」ことを学生の最終解答の特徴で判定する（真=使えている）。
    // 一致しても打ち切らず、必ず全KCノードを評価する。STACKのspecificfeedbackは通過した全ノードの
    // feedbackを連結表示するため、複数のKCが使えていないことを同時に提示できる。
    private const SYSTEM_ALL_NODES = <<<'SYS'
【変数名の決め方（教員が作る問題と同じにすること）】
- <questionvariables> には expr（問題の式）と ans（正答）を定義する（微分は ans: diff(expr, x);、
  不定積分は expr に被積分関数、ans: integrate(expr, x);。積分定数は付けない）。
- <questionnote> は {@expr@} とする。各ノードの <description> は必ず空にする。
- <input> の <tans> は、不定積分のとき ans+C、それ以外は ans とする。

【KC診断PRT（chain型）の設計ルール（重要）】
教員が確定した誤答例が渡される。誤答例はKC（前提知識）ごとに1つで、「そのKCだけが欠けた答え」である。
並び順は、この問題を解くときにKCを使う順（外側の操作から内側へ）。PRTも同じ順序で作る。
PRTは「正誤判定ノード1つ ＋ KCごとに1ノード」の一本の鎖（chain）にする。

- ノード1（正誤判定）: sans は ans1、tans は ans、answertest は選択済みのもの。
  - true（正解） → truescoremode "="、truescore 1、truenextnode -1（終端）、truefeedback は空
  - false → falsescoremode "="、falsescore 0、falsenextnode は最初のKCノード（なければ -1）、falsefeedback は空
- ノード2以降（KCごとに1ノード、誤答例と同じ順序）: 学生の最終解答 ans1 の特徴から、「そのKCを使えている」かを判定する。
  - answertest は AlgEquiv。sans に判定式、tans に期待値を書く（真偽値の判定式なら tans は true）。
  - true（そのKCを使えている） → truescoremode "+"、truescore は 0.9 をKCノードの数で割った値、truefeedback は空
  - false（使えていない） → falsescoremode "+"、falsescore 0、falsefeedback に、教員が確定したそのKCの
    フィードバック文をそのまま使う（HTML可）。
  - **true・falseのどちらでも、truenextnode・falsenextnode は両方とも次のKCノードを指すこと**
    （最後のKCノードだけ、両方 -1）。使えていたからといって打ち切ってはならない（必ず全KCノードを評価する）。
- 判定式の型（正答の式から機械的に作る。**展開・因数分解などの見た目の違いに左右されない B・C・D を優先**し、
  A は exp(...) や cos(x) のような「ひとかたまりの関数」を探すときだけ使うこと）:
  - A 含有: sans `not freeof(部分式, ans1)`、tans true
  - B 比: sans `ratsimp(ans1 / F)`（または `ratsimp(ans1 * F)`）、tans 期待する残りの式
  - C 分母: sans `denom(ratsimp(ans1 ...))`、tans 期待する分母
  - D 次数・係数: sans `hipow(expand(ans1), x)` や `ratcoef(expand(ans1), x, n)`、tans 期待値
- **判定式が必ず満たすべき3条件**（CAS検証で機械的に確かめられ、満たさないと再生成になる）:
  1. 正答 ans（不定積分は ans+C）を ans1 に代入すると、判定が真になること。
  2. そのKCの誤答例（渡された「そのKCだけが欠けた答え」）を ans1 に代入すると、判定が偽になること。
  3. KCが2つ以上あるとき、**少なくとも1つのKCノードは、他のKCの誤答例（そのKCは使えている答え）で判定が真に
     なること。** すべてのノードがどの誤答例でも偽になると、判定式が「答えが正答と一致するか」とほぼ同じで、
     どのKCが欠けたか診断できない。また、KCノードどうしで同じ判定式を使わないこと。
  判定式を書いたら、この3条件を手で確かめてから出力すること。
  そのため、**最初のKCノード（外側の操作）は、他のKCの欠落に左右されない、そのKCだけの特徴**
  （型A「特定の関数・部分式を含む」、型D「次数・係数」）で判定すること。後ろのKCノードは、前のKCが
  できていることを前提にした比（型B）などでよい（鎖になる）。
- 不定積分の注意: 学生の答えには積分定数が付く。積分定数を消してから判定したいときは、ans1 の代わりに
  subst([C=0,c=0], ans1) を使う。ただし KC「積分定数」の判定だけは消さず、
  `not freeof(C, ans1) or not freeof(c, ans1)`（tans は true）を使う。
- **不定積分では、型B（比）を使ってはならない。** integrate が返す原始関数は、(3*x+2)^5/15 のような教科書の形ではなく、
  展開された多項式など、それと「定数だけ違う別の形」になる。特定の原始関数との比は、定数のぶんだけ
  ずれて、正答でも成り立たない。判定は型D（次数・係数。必ず expand をかけてから hipow / ratcoef を使う）か、
  型Aで行うこと。例: (3*x+2)^4 の不定積分の正答は 81*x^5/5 + ... と展開されるので、
  「合成関数の積分」は ratcoef(expand(subst([C=0,c=0],ans1)), x, 5) = 81/5、
  「多項積分（べき乗の積分）」は hipow(expand(subst([C=0,c=0],ans1)), x) = 5 のように判定する。
- 誤答例の式そのものは PRT に書かない（<feedbackvariables> は空にする）。上の3条件の確認にだけ使われる。
- 誤答例が渡されなかった場合は、従来通り正誤2択（1ノード）で構成すること。

【KC診断PRTの例】（誤答例は「そのKCだけが欠けた答え」。判定式は、正答で真・その誤答例で偽になるように作られている）

例1: exp(2x-1) の微分   expr: exp(2*x-1);  ans: diff(expr, x);  （正答 2*exp(2*x-1)）
  KC1 指数関数の微分  誤答例 2                判定式 sans: not freeof(exp(2*x-1), ans1)  tans: true
  KC2 合成関数の微分  誤答例 exp(2*x-1)       判定式 sans: ratsimp(ans1/exp(2*x-1))       tans: 2

例2: log(x^2+x+1) の微分   expr: log(x^2+x+1);  ans: diff(expr, x);  （正答 (2*x+1)/(x^2+x+1)）
  KC1 対数関数の微分  誤答例 2*x+1            判定式 sans: not freeof(x^2+x+1, ans1)       tans: true
  KC2 合成関数の微分  誤答例 1/(x^2+x+1)      判定式 sans: ratsimp(ans1*(x^2+x+1))        tans: 2*x+1

例3: x*exp(-3*x) の不定積分   expr: x*exp(-3*x);  ans: integrate(expr, x);  （正答 -(3*x+1)*exp(-3*x)/9、学生は + C を付けて答える）
  KC1 部分積分        誤答例 -x^2*exp(-3*x)/6 + C
      判定式 sans: hipow(expand(ratsimp(subst([C=0,c=0],ans1)/exp(-3*x))), x)         tans: 1
  KC2 指数関数の積分  誤答例 (x-1)*exp(-3*x) + C
      判定式 sans: ratcoef(expand(ratsimp(subst([C=0,c=0],ans1)/exp(-3*x))), x, 1)   tans: -1/3
  KC3 積分定数        誤答例 -(3*x+1)*exp(-3*x)/9        （積分定数なし）
      判定式 sans: not freeof(C, ans1) or not freeof(c, ans1)                          tans: true

例4: (2*x+5)^3 の不定積分   expr: (2*x+5)^3;  ans: integrate(expr, x);  （学生は + C を付けて答える）
  KC1 合成関数の積分  誤答例 (2*x+5)^4/4 + C     判定式 sans: ratcoef(expand(subst([C=0,c=0],ans1)), x, 4)  tans: 2
  KC2 多項積分        誤答例 (2*x+5)^3/6 + C     判定式 sans: hipow(expand(subst([C=0,c=0],ans1)), x)        tans: 4

【例1のPRT構造（KC2個: ノード2・3がKC診断ノード）】
※ 下例では紙幅の都合で省略しているが、実際の出力では各 <node> に
   <description></description> <truepenalty></truepenalty> <falsepenalty></falsepenalty> も含めること。
<questionvariables><text>expr: exp(2*x-1);
ans: diff(expr, x);</text></questionvariables>
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
    <tans>ans</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>=</truescoremode>
    <truescore>1</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-1-T</trueanswernote>
    <truefeedback format="html"><text></text></truefeedback>
    <falsescoremode>=</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>2</falsenextnode>
    <falseanswernote>prt1-1-F</falseanswernote>
    <falsefeedback format="html"><text></text></falsefeedback>
  </node>
  <node>
    <name>2</name>
    <answertest>AlgEquiv</answertest>
    <sans>not freeof(exp(2*x-1), ans1)</sans>
    <tans>true</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.45</truescore>
    <truenextnode>3</truenextnode>
    <trueanswernote>prt1-2-T</trueanswernote>
    <truefeedback format="html"><text></text></truefeedback>
    <falsescoremode>+</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>3</falsenextnode>
    <falseanswernote>prt1-2-F</falseanswernote>
    <falsefeedback format="html"><text><![CDATA[<p>指数関数の微分を確認しましょう。</p>]]></text></falsefeedback>
  </node>
  <node>
    <name>3</name>
    <answertest>AlgEquiv</answertest>
    <sans>ratsimp(ans1/exp(2*x-1))</sans>
    <tans>2</tans>
    <testoptions></testoptions>
    <quiet>0</quiet>
    <truescoremode>+</truescoremode>
    <truescore>0.45</truescore>
    <truenextnode>-1</truenextnode>
    <trueanswernote>prt1-3-T</trueanswernote>
    <truefeedback format="html"><text></text></truefeedback>
    <falsescoremode>+</falsescoremode>
    <falsescore>0</falsescore>
    <falsenextnode>-1</falsenextnode>
    <falseanswernote>prt1-3-F</falseanswernote>
    <falsefeedback format="html"><text><![CDATA[<p>合成関数の微分を思い出しましょう。</p>]]></text></falsefeedback>
  </node>
  <firstnodename>1</firstnodename>
</prt>
SYS;

    private const SYSTEM_ALL_NODES_SELFCHECK = <<<'SYS'

【KC診断ノードの判定式の自己チェック（出力前に必ず実施すること）】
KC診断ノードの sans / tans を書き終えたら、<question> ブロックを出力する前に、以下を1つずつ検算してから
出力すること。検算せずに「たぶん大丈夫」で出力しないこと。
1. 各KC診断ノードについて、正答 ans（不定積分は ans+C）を ans1 に代入して判定式を手計算し、tans と一致して
   真になることを確認する（一致しないなら判定式を書き直す）。
2. 各KC診断ノードについて、対応するKCの誤答例を ans1 に代入して判定式を手計算し、偽になることを確認する
   （真になるなら、そのKCの欠落を区別できないので判定式を書き直す）。
2'. KC診断ノードのうち少なくとも1つ（特に最初のKCノード）が、他のKCの誤答例（そのKCは使えている答え）を
   ans1 に代入したときに真になることを確認する（全ノードがどの誤答例でも偽なら、正答との一致判定と同じになり
   診断できないので、最初のKCノードを、他に左右されない特徴（型A・型D）で書き直す）。
   KCノードどうしで同一の判定式になっていないことも確認する。
3. KC診断ノードの数が誤答例の数と同じで、順序が誤答例と同じか確認する。
4. すべてのKC診断ノードで truenextnode と falsenextnode が両方とも次のKCノード（最後だけ -1）か確認する。
5. 括弧の対応、乗算記号（2*x）、has( ) や limit( ) を使っていないこと（「含む」は freeof で判定する）を確認する。
6. 不定積分で、比（型B）を使っていないか確認する（次数・係数（型D）を、expand をかけて使うこと）。
SYS;

    private const SYSTEM_TEMPLATE = <<<'SYS'
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
  <questionvariables><text>expr: ...問題の式（Maxima式）...;
ans: ...正答（Maxima式）...;</text></questionvariables>
  <specificfeedback format="html"><text><![CDATA[<p>[[feedback:prt1]]</p>]]></text></specificfeedback>
  <questionnote format="html"><text><![CDATA[{@expr@}]]></text></questionnote>
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
    <tans>ans</tans>
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
      <tans>ans</tans>
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
     * @param string $mode  PRT誤答判定ノードの分岐方式。'early_exit'（デフォルト、通常版・RAGなし版）=
     *                       誤答例とリテラルに一致したら打ち切る早期終了型。'all_nodes'（KC診断版）=
     *                       誤答例を構造的特徴の述語関数として扱い、全KC診断ノードを必ず評価する全ノード評価型。
     * @return array  ['xml' => "<?xml...><quiz>...</quiz>", 'cot' => "問題ごとの思考過程を連結したテキスト"]
     * @throws \moodle_exception APIキー未設定・通信エラー・XML不正のとき
     */
    public function run(
        array $problems, array $feedbackproblems = [], string $lasterror = '', array $ragexamples = [],
        string $mode = 'early_exit'
    ): array {
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
                $api_key, $problem, $i + 1, $wronganswers, $name, $lasterror, $ragexamples[$i] ?? [], $mode);
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
     *                             （KC診断版から呼ばれる場合は misconception の代わりに kc_name を持つ）
     * @param string $name  教員がレビュー画面で確定した問題名。空ならLLM任せの名前をそのまま使う。
     * @param string $lasterror  直前の試行のCAS検証エラー内容。空でなければ再生成時にLLMへ伝える。
     * @param array $examples  Step 6 RAG: この問題に類似した過去の良問（rag_search::search()の結果）
     * @param string $mode  'early_exit'（既定）または'all_nodes'（KC診断版）。run()のモード引数を参照。
     * @return array [xml, cot]
     *
     * public: run() が全問一括生成に使うほか、呼び出し元タスク（KC診断版）が
     * CAS検証に失敗した問題だけをピンポイントで再生成するためにも直接呼び出す。
     */
    public function generate_one(
        string $api_key, array $problem, int $num, array $wronganswers, string $name = '', string $lasterror = '',
        array $examples = [], string $mode = 'early_exit'
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
        // 積分範囲が指定された定積分は答えが値なので、Int(原始関数の判定)ではなく AlgEquiv で判定する
        // （教員が作る定積分の問題もそうなっている）。
        if ($qtype === 'integral' && preg_match('/\\int\s*_|定積分/u', $qtext . ' ' . $expr)) {
            [$answertest, $testopts] = ['AlgEquiv', ''];
        }

        $user_msg = "以下の数学問題の STACK XML（<question type=\"stack\"> タグのみ、PRT付き）を生成してください。\n\n"
            . "問題番号: {$num}\n"
            . "問題文（この文をそのまま questiontext に使うこと。数式区切りは \\( \\) を維持）: {$qtext}\n"
            . "問題タイプ: {$qtype}\n"
            . "学生が入力するもの: {$anskind}（expression=数式 / value=数値1つ / equation=方程式）\n"
            . "主変数: {$var}\n"
            . "LaTeX 式: {$expr}\n"
            . ($answer !== null ? "答え（LaTeX）: {$answer}\n" : "※答えは不明。questionvariables で Maxima を使って ans を計算すること。\n")
            . "\n使用する answertest: {$answertest}"
            . ($testopts !== '' ? "（testoptions: {$testopts}）" : '') . "\n";

        if (!empty($wronganswers)) {
            $user_msg .= $mode === 'all_nodes'
                ? "\n教員が確定したKCごとの誤答例（「そのKCだけが欠けた答え」。この順序でKC診断ノードを作ること。"
                    . "誤答例の数=KC診断ノードの数）:\n"
                : "\n教員が確定した誤答例（この順番でノードを直列に追加すること）:\n";
            foreach ($wronganswers as $j => $w) {
                $n = $j + 1;
                $reason = $w['misconception'] ?? ($w['kc_name'] ?? '');
                $user_msg .= "  誤答{$n}: 式=" . ($w['expression'] ?? '') . "、"
                    . "想定原因=" . $reason . "、"
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
            'system_instruction' => ['parts' => [['text' => self::build_system($mode)]]],
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

            // 保険: <truescore>...</truescoremode> のような、名前が似た隣接タグ（truescore/
            // truescoremode、falsescore/falsescoremode）の開始・終了タグ取り違えを機械的に修正する。
            // プロンプトでこの具体例を名指しで注意しても、LLMが再発させることを実機で確認したため
            // （同じ誤りが複数ジョブで再現）、プロンプト指示に頼らずここで決定的に修復する。
            $xml = preg_replace('/<truescore>([^<]*)<\/truescoremode>/', '<truescore>$1</truescore>', $xml);
            $xml = preg_replace('/<truescoremode>([^<]*)<\/truescore>/', '<truescoremode>$1</truescoremode>', $xml);
            $xml = preg_replace('/<falsescore>([^<]*)<\/falsescoremode>/', '<falsescore>$1</falsescore>', $xml);
            $xml = preg_replace('/<falsescoremode>([^<]*)<\/falsescore>/', '<falsescoremode>$1</falsescoremode>', $xml);

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

            return [self::normalize_to_teacher_style($xml), $cot];
        }

        // 抽出失敗時はそのまま返す（後続で XML 検証が拾う）
        return [$raw_text, ''];
    }

    /**
     * 生成した<question>XMLを、教員が実際に作る問題の体裁に決定的にそろえる。
     *   - 各ノードの <description> を空にする（教員の問題は全ノード空。LLMの気まぐれな説明を残さない）。
     *   - ノード名を 0 始まりの連番に振り直す（STACKの編集画面で作った問題は 0 から始まる）。
     *     truenextnode / falsenextnode / firstnodename も同じ対応で書き換える（-1 は終端なのでそのまま）。
     * プロンプトで頼むのではなくコードで確定させる。ノード名が数字文字列でないなど想定外の形なら、
     * 壊さないよう振り直しはせずそのまま返す（検証・後続処理が拾う）。
     */
    private static function normalize_to_teacher_style(string $questionxml): string {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($questionxml)) {
            return $questionxml;
        }

        foreach ($dom->getElementsByTagName('description') as $desc) {
            if ($desc->parentNode && $desc->parentNode->nodeName === 'node') {
                while ($desc->firstChild) {
                    $desc->removeChild($desc->firstChild);
                }
            }
        }

        foreach ($dom->getElementsByTagName('prt') as $prt) {
            $nodes = [];
            foreach ($prt->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->nodeName === 'node') {
                    $nodes[] = $child;
                }
            }
            $names = [];
            foreach ($nodes as $node) {
                $nameel = $node->getElementsByTagName('name')->item(0);
                $name = $nameel ? trim($nameel->textContent) : '';
                if (!ctype_digit($name) || isset($names[$name])) {
                    continue 2;
                }
                $names[$name] = true;
            }
            $order = array_map('strval', array_keys($names));
            usort($order, fn($a, $b) => (int)$a <=> (int)$b);
            $map = array_flip($order);

            $remap = function (\DOMElement $parent, string $tag) use ($map): void {
                $el = $parent->getElementsByTagName($tag)->item(0);
                if ($el && array_key_exists(trim($el->textContent), $map)) {
                    $el->textContent = (string)$map[trim($el->textContent)];
                }
            };
            foreach ($nodes as $node) {
                $remap($node, 'name');
                $remap($node, 'truenextnode');
                $remap($node, 'falsenextnode');
            }
            $remap($prt, 'firstnodename');
        }

        return $dom->saveXML($dom->documentElement);
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
     * $mode に応じたPRT分岐設計ルール（誤答判定ノードの部分のみ差し替え）を組み込んだ
     * システムプロンプト全体を組み立てる。
     *
     * @param string $mode 'early_exit'（既定）または'all_nodes'
     */
    private static function build_system(string $mode): string {
        $rules = $mode === 'all_nodes' ? self::SYSTEM_ALL_NODES : self::SYSTEM_EARLY_EXIT;
        $selfcheck = $mode === 'all_nodes' ? self::SYSTEM_ALL_NODES_SELFCHECK : '';
        return self::SYSTEM_BASE . $rules . $selfcheck . "\n" . self::SYSTEM_TEMPLATE;
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
                // 教員が作る微分問題は、第1ノードを AlgEquiv(ans1, ans)（ans: diff(expr, x)）で判定している。
                return ['AlgEquiv', ''];
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