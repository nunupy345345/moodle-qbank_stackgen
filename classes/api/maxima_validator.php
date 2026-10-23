<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 5: goemaxima を使って、LLMが生成したSTACK XMLのMaxima式が
 * 内部無矛盾かどうかを検証する。
 *
 * ここで見るのは「LLMが書いたMaxima式どうしの整合性」だけであり、
 * 問題文の内容を理解して ans（正答）が数学的に正しいかどうかまでは判定できない
 * （独立した正解を再計算する手段が現状ないため）。
 *   1. ans / ans2 / ans3 ... が Maxima の構文として壊れていないか
 *      （ans は questionvariables、ans2… や述語は PRT の feedbackvariables にある。両方を合わせて検証する。
 *       旧形式の ta / wa1 … も受け付ける）
 *   2. 誤答例（ans2…）が正答（ans）と数式的に完全一致していないか
 *      （不定積分は、誤答例の導関数が被積分関数 expr と一致していないか）
 *      （一致していたら、その誤答ノードは意味をなさないPRTロジックのバグ）
 */
class maxima_validator {

    private const VERSION = '2026042200';

    // 方程式（lhs=rhs）と素の式のどちらでも同じように比較できるようにする正規化関数。
    // ta/waNがLLMによって「6」「3」のような単純な数値（Maximaのアトム）として生成された場合、
    // op(e)はアトムに対してエラーになる（例: stackgen_norm(e=6) -- an error.）ため、
    // atom(e)チェックでガードしてから演算子判定する。
    private const NORM_FUNC = 'stackgen_norm(e):=if atom(e) then e else (if op(e)="=" then lhs(e)-rhs(e) else e)$';

    private const ERROR_MARKERS = ['syntax error', 'incorrect syntax', '-- an error.'];

    /**
     * @param string $xml  <quiz>...</quiz> 全体（llm_generator::run() の出力）
     * @return array{valid: bool, errors: string[]}
     * @throws \moodle_exception goemaximaとの通信エラーのとき（内容不正ではなくインフラ障害のため、
     *                            呼び出し元でリトライ対象にせず即requires_manual_reviewにする想定）
     */
    public function validate(string $xml): array {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return ['valid' => false, 'errors' => ['生成されたXMLがパースできません（整形式のXMLではありません）']];
        }

        $errors = [];
        $questions = $dom->getElementsByTagName('question');
        foreach ($questions as $qindex => $question) {
            $errors = array_merge($errors, $this->validate_question($question, $qindex + 1));
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * 1問分の <question type="stack">...</question> ブロック単体を検証する。
     * KC診断版の部分リトライ（CAS検証に落ちた問題だけを再生成・再検証する）で使う。
     *
     * @param string $questionxml  単一の <question>...</question> ブロック
     * @param int    $qnum  エラーメッセージに含める問題番号
     * @param array  $options  ['mode' => 'kc_chain', 'variants' => string[]] を渡すと、KC診断版（chain型）として
     *                         検証する。variants は KC診断ノードと同じ順序で並んだ「そのKCだけが欠けた答え」のMaxima式。
     * @return array{valid: bool, errors: string[]}
     * @throws \moodle_exception goemaximaとの通信エラーのとき
     */
    public function validate_one(string $questionxml, int $qnum, array $options = []): array {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($questionxml)) {
            return ['valid' => false, 'errors' => ["問題{$qnum}: 生成されたXMLがパースできません（整形式のXMLではありません）"]];
        }

        $questions = $dom->getElementsByTagName('question');
        if ($questions->length === 0) {
            return ['valid' => false, 'errors' => ["問題{$qnum}: <question> 要素が見つかりません"]];
        }

        $errors = $this->validate_question($questions->item(0), $qnum, $options);
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * @return string[] このquestionで見つかったエラーメッセージ（なければ空配列）
     */
    private function validate_question(\DOMElement $question, int $qnum, array $options = []): array {
        $qvnodes = $question->getElementsByTagName('questionvariables');
        if ($qvnodes->length === 0) {
            return ["問題{$qnum}: <questionvariables> が見つかりません"];
        }
        $textnodes = $qvnodes->item(0)->getElementsByTagName('text');
        $qvtext = $textnodes->length > 0 ? $textnodes->item(0)->textContent : '';
        $qvtext = trim($qvtext);

        // 正答の変数名。教員が作る問題と同じ ans を基本とし、旧形式の ta も受け付ける。
        if ($qvtext === '' || !preg_match('/^\s*(ans|ta)\s*:/m', $qvtext, $tm)) {
            return ["問題{$qnum}: questionvariables に ans（正答）の定義が見つかりません"];
        }
        $tavar = $tm[1];

        // 誤答例の式（wa1, ...）や述語（waN_pred）は、教員が作る問題と同じく PRT の <feedbackvariables> に
        // 書かれる。questionvariables と合わせた全体を、以降のCAS検証の文脈として使う
        // （ta の存在確認だけは上で questionvariables 単独に対して済ませてある）。
        $fvtext = '';
        foreach ($question->getElementsByTagName('feedbackvariables') as $fvnode) {
            $fvtextnodes = $fvnode->getElementsByTagName('text');
            if ($fvtextnodes->length > 0) {
                $fvtext .= "\n" . trim($fvtextnodes->item(0)->textContent);
            }
        }
        $fvtext = trim($fvtext);
        if ($fvtext !== '') {
            // 末尾の文が ; や $ で終わっていないと、後ろに続ける式とつながって構文エラーになる。
            if (!preg_match('/[;$]\s*$/', $fvtext)) {
                $fvtext .= ';';
            }
            if (!preg_match('/[;$]\s*$/', $qvtext)) {
                $qvtext .= ';';
            }
            $qvtext = $qvtext . "\n" . $fvtext;
        }

        // KC診断版（chain型）: ノードごとの判定式が、正答で真・「そのKCだけが欠けた答え」で偽になるかを確かめる。
        if (($options['mode'] ?? '') === 'kc_chain') {
            return $this->validate_kc_chain($question, $qnum, $qvtext, $tavar, array_values((array)($options['variants'] ?? [])));
        }

        // 誤答例変数名を抽出（正答以外の代入先。早期終了型/従来版・RAGなし版用）。
        // 教員の問題と同じ ans2, ans3, ...（ans1 は入力欄の名前）。旧形式の wa1, wa2, ... も受け付ける。
        preg_match_all('/^\s*(wa\d+|ans[2-9]\d*)\s*:/m', $qvtext, $m);
        $wanames = $m[1];

        // wa1_pred(ex):=..., wa2_pred(ex):=... のような誤答判定述語関数名を抽出（KC診断版の全ノード評価モード用）。
        preg_match_all('/^\s*(wa\d+_pred)\s*\(\s*ex\s*\)\s*:=/m', $qvtext, $pm);
        $prednames = $pm[1];

        $errors = [];

        if (empty($wanames) && empty($prednames)) {
            // 誤答例なし: ta単体の構文チェックのみ。
            $response = $this->call_goemaxima_with_retry("{$qvtext} {$tavar};");
            if ($this->looks_like_error($response)) {
                $errors[] = "問題{$qnum}: {$tavar} の構文エラー: " . $this->extract_error_detail($response);
            }
            return $errors;
        }

        // 不定積分は、教員の問題と同じく「学生解答の導関数」で誤答判定する（PRTのノードの sans が
        // diff(ans1, ...) になる）。この場合、誤答例の導関数が被積分関数 expr と一致していると、
        // 積分定数の付け忘れ等を拾う共通ノードと重複して、誤答判定ノードが働かない。
        $intvar = $this->derivative_judging_variable($question);

        foreach ($wanames as $waname) {
            if ($intvar !== null) {
                if (!preg_match('/^\s*(expr|ex)\s*:/m', $qvtext, $em)) {
                    $errors[] = "問題{$qnum}: PRTが導関数で判定しているのに questionvariables に expr（被積分関数）の定義がありません";
                    break;
                }
                $input = "{$qvtext} is(ratsimp(diff({$waname}, {$intvar}) - {$em[1]}) = 0);";
                $response = $this->call_goemaxima_with_retry($input);

                if ($this->looks_like_error($response)) {
                    $errors[] = "問題{$qnum}: {$waname} の構文エラー: " . $this->extract_error_detail($response);
                    continue;
                }
                $result = trim((string)$this->extract_last_result($response));
                if ($result === 'true') {
                    $errors[] = "問題{$qnum}: 誤答例 {$waname} の導関数が被積分関数 {$em[1]} と一致しています"
                        . "（積分定数の付け忘れなど、導関数が一致する答えは共通ノードが担当するため、"
                        . "導関数が一致しない誤答例にしてください）";
                } else if ($result !== 'false') {
                    $errors[] = "問題{$qnum}: {$waname} の検証結果を判定できませんでした（応答: {$result}）";
                }
                continue;
            }

            $input = self::NORM_FUNC . " {$qvtext} "
                . "is(ratsimp(stackgen_norm({$tavar})-stackgen_norm({$waname}))=0);";
            $response = $this->call_goemaxima_with_retry($input);

            if ($this->looks_like_error($response)) {
                $errors[] = "問題{$qnum}: {$tavar} / {$waname} の構文エラー: " . $this->extract_error_detail($response);
                continue;
            }

            $result = trim((string)$this->extract_last_result($response));
            if ($result === 'true') {
                $errors[] = "問題{$qnum}: 誤答例 {$waname} が正答 {$tavar} と数式的に一致しています（PRTの誤答判定が機能しません）";
            } else if ($result !== 'false') {
                $errors[] = "問題{$qnum}: {$waname} の検証結果を判定できませんでした（応答: {$result}）";
            }
        }

        // KC診断版(全ノード評価モード)の安全チェック: 各誤答判定述語は、正答taに対して必ずfalseを
        // 返さなければならない（正答した学生を誤ってKC欠落と判定してはならないため）。
        $freevars = null;
        foreach ($prednames as $predname) {
            $input = "{$qvtext} is(not({$predname}({$tavar})));";
            $response = $this->call_goemaxima_with_retry($input);

            if ($this->looks_like_error($response)) {
                $errors[] = "問題{$qnum}: {$tavar} / {$predname} の構文エラー: " . $this->extract_error_detail($response);
                continue;
            }

            $result = trim((string)$this->extract_last_result($response));
            if ($result === 'true') {
                continue;
            }
            if ($result === 'false') {
                $errors[] = "問題{$qnum}: 誤答判定述語 {$predname} が正答 {$tavar} に対してtrueを返しています"
                    . "（正答した学生を誤ってKC欠落と判定してしまいます）";
                continue;
            }

            // is() が symbolic に真偽を決定できなかった場合（応答: unknown 等）。ta に自由変数
            // （x, c など）が残っていると、Maximaの is() は一般にその大小・真偽を決定できないため、
            // 具体的な数値を代入した上で再検証する（STACKの等価判定でも使われる数値サンプリング）。
            if ($freevars === null) {
                $freevars = $this->free_vars($qvtext, $tavar);
            }
            $numericresult = $this->check_predicate_numeric($qvtext, $predname, $freevars, $tavar);
            if ($numericresult === 'true') {
                continue;
            }
            if ($numericresult === 'false') {
                $errors[] = "問題{$qnum}: 誤答判定述語 {$predname} が正答 {$tavar} に対してtrueを返しています"
                    . "（正答した学生を誤ってKC欠落と判定してしまいます・数値代入検証）";
                continue;
            }
            $errors[] = "問題{$qnum}: {$predname} の検証結果を判定できませんでした"
                . "（応答: {$result}、数値代入検証でも判定不能）";
        }

        return $errors;
    }

    /**
     * KC診断版（chain型）PRTの検証。
     *
     * PRTは「ノード1=正誤判定、ノード2以降=KCごとに1ノード」で、各KCノードは「そのKCを使えている」ことを
     * 学生の最終解答（ans1）の特徴で判定する（真=使えている）。そこで次の2つを機械的に確かめる。
     *   1. 正答（不定積分は 正答+C）を ans1 に入れると、すべてのKCノードの判定が真になる。
     *   2. そのKCだけが欠けた答え（variants[k]）を ans1 に入れると、k番目のKCノードの判定が偽になる。
     *      （真になるなら、そのノードはKCの欠落を区別できない）
     *   3. KCが2つ以上あるとき、少なくとも1つのKCノードが、他のKCが欠けた答え（variants[j]、j≠k。そのKCkは
     *      使えている答え）で真になる。全ノードがどれでも偽だと、判定式が「答えが正答と一致するか」とほぼ同じで、
     *      どのKCが欠けたかを診断できない（掛け算でつながるKCは、一方が欠けると他方の比が偽になるため、
     *      すべてのノードが独立である必要はない。chain型は後ろのKCほど前のKCに依存してよい）。
     *      あわせて、KCノードどうしが同一の判定式にならないことも確かめる。
     *
     * @param string[] $variants KCノードと同じ順序の「そのKCだけが欠けた答え」（Maxima式）
     * @return string[] エラーメッセージ（なければ空配列）
     */
    private function validate_kc_chain(
        \DOMElement $question, int $qnum, string $qvtext, string $tavar, array $variants
    ): array {
        $prts = $question->getElementsByTagName('prt');
        if ($prts->length === 0) {
            return ["問題{$qnum}: <prt> が見つかりません"];
        }
        $prt = $prts->item(0);

        $text = function (\DOMElement $parent, string $tag): string {
            $el = $parent->getElementsByTagName($tag)->item(0);
            return $el ? trim($el->textContent) : '';
        };

        $firstname = $text($prt, 'firstnodename');
        $first = null;
        $kcnodes = [];
        foreach ($prt->childNodes as $child) {
            if (!($child instanceof \DOMElement) || $child->nodeName !== 'node') {
                continue;
            }
            if ($first === null && $text($child, 'name') === $firstname) {
                $first = $child;
            } else {
                $kcnodes[] = $child;
            }
        }
        if ($first === null) {
            return ["問題{$qnum}: firstnodename が指すノードが見つかりません"];
        }
        if (count($kcnodes) !== count($variants)) {
            return ["問題{$qnum}: KC診断ノードの数(" . count($kcnodes) . ")が、KCごとの誤答例の数("
                . count($variants) . ")と一致しません（誤答例1つにつきKC診断ノードを1つ、同じ順序で作ること）"];
        }

        // 不定積分はノード1が Int。学生は積分定数を付けて答えるので、正答側にも + C を付けて確かめる。
        $reference = $text($first, 'answertest') === 'Int' ? "({$tavar}+C)" : $tavar;

        $errors = [];
        $truefound = false;
        $ansvalue = null;
        foreach ($kcnodes as $k => $node) {
            $label = "KC診断ノード" . ($k + 1);
            $sans = $text($node, 'sans');
            $tans = $text($node, 'tans');
            if ($sans === '' || $tans === '') {
                $errors[] = "問題{$qnum}: {$label}: sans または tans が空です";
                continue;
            }

            [$ref, $refdetail] = $this->kc_chain_test($qvtext, $sans, $tans, $reference);
            if ($ref !== 'true') {
                // LLMは integrate / diff がMaximaで実際に返す式の形を知らない（展開された多項式など、
                // 教科書の形と定数だけ違う別の形になる）ため、実際の値を伝えて次の試行で合わせさせる。
                if ($ansvalue === null) {
                    $ansvalue = $this->kc_chain_answer_value($qvtext, $reference);
                }
                $errors[] = "問題{$qnum}: {$label}（判定式 {$sans} = {$tans}）が、正答では真になりません"
                    . "（応答: " . ($refdetail !== '' ? $refdetail : $ref) . "）。正答を使えば真になる判定式にしてください"
                    . ($ansvalue !== '' ? "。ans1 に入れる正答 {$reference} をMaximaで評価した実際の値は {$ansvalue} です。"
                        . "この形（展開された式など）で成り立つ判定式にすること" : '');
                continue;
            }

            [$var, $vardetail] = $this->kc_chain_test($qvtext, $sans, $tans, '(' . $variants[$k] . ')');
            if ($var === 'true') {
                $errors[] = "問題{$qnum}: {$label}（判定式 {$sans} = {$tans}）が、そのKCだけが欠けた答え {$variants[$k]} でも"
                    . "真になります（KCの欠落を区別できません）。その答えでは偽になる判定式にしてください";
                continue;
            } else if ($var !== 'false') {
                $errors[] = "問題{$qnum}: {$label}（判定式 {$sans} = {$tans}）を、そのKCだけが欠けた答え {$variants[$k]} で"
                    . "判定できませんでした（応答: " . ($vardetail !== '' ? $vardetail : $var) . "）";
                continue;
            }

            // 条件3: 少なくとも1つのKCノードが、他のKCが欠けた答え（このKCは使えている答え）で真になること。
            if (count($kcnodes) >= 2 && !$truefound) {
                foreach ($variants as $j => $other) {
                    if ($j === $k) {
                        continue;
                    }
                    [$r] = $this->kc_chain_test($qvtext, $sans, $tans, '(' . $other . ')');
                    if ($r === 'true') {
                        $truefound = true;
                        break;
                    }
                }
            }
        }

        if (empty($errors) && count($kcnodes) >= 2 && !$truefound) {
            $errors[] = "問題{$qnum}: どのKC診断ノードの判定式も、他のKCが欠けた答え（そのKCは使えている答え）では偽になります。"
                . "これでは「答えが正答と一致するか」とほぼ同じで、どのKCが欠けたか診断できません。"
                . "特に最初のKCノードは、他のKCの欠落に左右されない、そのKCだけの特徴"
                . "（型A「特定の関数・部分式を含む」、型D「次数・係数」など）で判定してください";
        }

        // 条件6: 各KCの誤答（そのKCだけが欠けた答え）で、自分以外のKCノードが少なくとも1つ真になること。
        // 全ノードが偽になる答えは、どのKCが欠けたか特定できず診断として成立しない。
        if (empty($errors) && count($kcnodes) >= 2) {
            foreach ($variants as $k => $variant) {
                $othertrue = 0;
                foreach ($kcnodes as $j => $node) {
                    if ($j === $k) {
                        continue;
                    }
                    [$r] = $this->kc_chain_test($qvtext, $text($node, 'sans'), $text($node, 'tans'), '(' . $variant . ')');
                    if ($r === 'true') {
                        $othertrue++;
                    }
                }
                if ($othertrue === 0) {
                    $errors[] = "問題{$qnum}: 誤答 {$variant} では、そのKC以外の診断ノードがすべて偽になり、"
                        . "どのKCが欠けたか特定できません。他のKCノードは「そのKC以外の特徴」だけを見る判定式にして、"
                        . "この誤答でも少なくとも1つは真になるようにしてください"
                        . "（例: 型A「特定の関数・部分式を含む」、型D「次数・係数」は、答えが大きく崩れても影響を受けにくい）";
                }
            }
        }

        // KCノードどうしで同一の判定式を使わない（別々のKCを区別できないため）。
        $seen = [];
        foreach ($kcnodes as $k => $node) {
            $key = preg_replace('/\s+/', '', $text($node, 'sans') . '|' . $text($node, 'tans'));
            if (isset($seen[$key])) {
                $errors[] = "問題{$qnum}: KC診断ノード" . ($seen[$key] + 1) . "とKC診断ノード" . ($k + 1)
                    . "が同一の判定式です（別々のKCを区別できません）";
            } else {
                $seen[$key] = $k;
            }
        }

        return $errors;
    }

    /**
     * 正答式をMaximaで評価した実際の値（展開した形）の文字列を返す。取得できなければ空文字。
     */
    private function kc_chain_answer_value(string $qvtext, string $answerexpr): string {
        $response = $this->call_goemaxima_with_retry("{$qvtext} string(expand(ratsimp({$answerexpr})));");
        if ($this->looks_like_error($response)) {
            return '';
        }
        return trim(trim((string)$this->extract_last_result($response)), '"');
    }

    /**
     * KC診断ノードの判定（sans を tans と AlgEquiv で比べる）を、ans1 に $answerexpr を入れて評価する。
     * 真偽値どうしは等号、式どうしは ratsimp(差)=0 で比べる（STACKのAlgEquivの簡易版）。
     *
     * @return array [結果('true'|'false'|'error'|その他の応答), エラー詳細]
     */
    private function kc_chain_test(string $qvtext, string $sans, string $tans, string $answerexpr): array {
        $input = "{$qvtext} ans1: {$answerexpr}; "
            . "block([r, t], r: ev({$sans}), t: ev({$tans}), "
            . "if member(r, [true, false]) or member(t, [true, false]) then is(r = t) else is(ratsimp(r - t) = 0));";
        $response = $this->call_goemaxima_with_retry($input);

        if ($this->looks_like_error($response)) {
            return ['error', $this->extract_error_detail($response)];
        }
        return [trim((string)$this->extract_last_result($response)), ''];
    }

    /**
     * PRTのノードが学生解答の導関数 diff(ans1, 変数) で判定しているか（不定積分の誤答判定方式）を調べ、
     * そうであれば積分変数名を返す。そうでなければ null。
     */
    private function derivative_judging_variable(\DOMElement $question): ?string {
        foreach ($question->getElementsByTagName('sans') as $sansel) {
            if (preg_match('/^\s*diff\(\s*ans1\s*,\s*([A-Za-z]\w*)\s*\)\s*$/', $sansel->textContent, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * 正答（ans）に含まれる自由変数名の一覧を取得する（例: [x, c]）。取得できなければ空配列。
     */
    private function free_vars(string $qvtext, string $tavar = 'ans'): array {
        $response = $this->call_goemaxima_with_retry("{$qvtext} listofvars({$tavar});");
        if ($this->looks_like_error($response)) {
            return [];
        }
        $result = trim((string)$this->extract_last_result($response));
        if (preg_match('/^\[(.*)\]$/', $result, $m) && trim($m[1]) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
        }
        return [];
    }

    /**
     * is() が symbolic に決定できなかった述語を、ta の自由変数に具体的な数値を代入した上で
     * 複数パターン再検証する。いずれかのサンプルで定義域エラーにならず true/false が決まれば
     * その結果を返す（代入後は自由変数が残らないため、Maximaは通常は確定的に判定できる）。
     * 全サンプルで判定不能だった場合は 'unknown' を返す。
     */
    private function check_predicate_numeric(string $qvtext, string $predname, array $vars, string $tavar = 'ans'): string {
        if (empty($vars)) {
            return 'unknown';
        }

        // ゼロ除算・負数の平方根・対数の定義域外などを避けつつ、符号・値の異なる複数パターンを試す。
        $samplesets = [
            [2, 3, -2, 5, 7],
            [-3, 2, 5, -2, 2],
            [4, -1, 3, 2, -5],
        ];

        foreach ($samplesets as $values) {
            $substs = [];
            foreach ($vars as $i => $var) {
                $substs[] = "{$var}=" . ($values[$i] ?? ($i + 2));
            }
            $substlist = implode(',', $substs);
            $input = "{$qvtext} is(not({$predname}(subst([{$substlist}], {$tavar}))));";
            $response = $this->call_goemaxima_with_retry($input);

            if ($this->looks_like_error($response)) {
                continue;
            }
            $result = trim((string)$this->extract_last_result($response));
            if ($result === 'true' || $result === 'false') {
                return $result;
            }
        }

        return 'unknown';
    }

    /**
     * goemaximaは短時間に連続でリクエストを送るとワーカーの使い回し起因と見られる不安定な
     * エラー応答（本来アトム値のはずの単純な数値・記号に対してop()がエラーになる等、手動で
     * 同一入力を単発送信すると再現しない）を稀に返すことが実機検証で確認された。
     * 内容自体の構文エラーであれば再送しても同じ結果になるだけなので、一度だけ再送して
     * 一時的な不安定性を吸収する（本当の構文エラーを見逃すリスクはない）。
     */
    private function call_goemaxima_with_retry(string $input, int $retries = 1): string {
        $response = $this->call_goemaxima($input);
        while ($this->looks_like_error($response) && $retries > 0) {
            $retries--;
            usleep(300000);
            $response = $this->call_goemaxima($input);
        }
        return $response;
    }

    private function looks_like_error(string $response): bool {
        $lower = mb_strtolower($response);
        foreach (self::ERROR_MARKERS as $marker) {
            if (strpos($lower, mb_strtolower($marker)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * goemaximaの応答（対話セッション形式のテキスト）から、最後の (%oN) 行の中身を取り出す。
     */
    private function extract_last_result(string $response): string {
        if (preg_match_all('/\(%o\d+\)\s*(.*)/', $response, $m) && !empty($m[1])) {
            return trim(end($m[1]));
        }
        return trim($response);
    }

    /**
     * エラー発生時の詳細メッセージ抽出。構文エラー等は (%oN) ラベルを伴わずに出力されるため、
     * extract_last_result() で拾うと無関係な直前の結果を誤って掴むことがある。
     * ここではエラーマーカーを含む行を抜き出すが、Maximaのエラーは通常
     * 「具体的な説明（例: op: operand must not be atom...）」→「-- an error. To debug...」
     * という2行構成で出力されるため、マーカー行だけを拾うと肝心の説明文が失われる。
     * そのため直前の空行でない1行も文脈として含める。
     */
    private function extract_error_detail(string $response): string {
        $lines = preg_split('/\r?\n/', $response);
        $matched = [];
        foreach ($lines as $i => $line) {
            $lower = mb_strtolower($line);
            foreach (self::ERROR_MARKERS as $marker) {
                if (strpos($lower, mb_strtolower($marker)) !== false) {
                    $detail = trim($line);
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]);
                        if ($prev === '') {
                            continue;
                        }
                        $detail = "{$prev} {$detail}";
                        break;
                    }
                    $matched[] = $detail;
                    break;
                }
            }
        }
        return !empty($matched) ? implode(' / ', $matched) : trim($response);
    }

    private function call_goemaxima(string $input): string {
        $url = get_config('qbank_stackgen', 'maxima_url');
        if (empty($url)) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'goemaxima URL is not configured');
        }

        $body = 'input=' . urlencode($input)
            . '&timeout=30000&ploturlbase=' . urlencode('!ploturl!')
            . '&version=' . self::VERSION;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35,
        ]);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'goemaxima curl error: ' . $curl_error);
        }
        if ($http_code === 416) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                'goemaxima timed out (HTTP 416)');
        }
        if ($http_code !== 200) {
            throw new \moodle_exception('error_generic', 'qbank_stackgen', '', null,
                "goemaxima returned HTTP {$http_code}: {$response}");
        }

        return (string)$response;
    }
}
