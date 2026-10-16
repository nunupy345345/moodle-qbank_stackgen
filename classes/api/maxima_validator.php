<?php
namespace qbank_stackgen\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 5: goemaxima を使って、LLMが生成したSTACK XMLのMaxima式が
 * 内部無矛盾かどうかを検証する。
 *
 * ここで見るのは「LLMが書いたMaxima式どうしの整合性」だけであり、
 * 問題文の内容を理解して ta が数学的に正しいかどうかまでは判定できない
 * （独立した正解を再計算する手段が現状ないため）。
 *   1. ta / wa1 / wa2 ... が Maxima の構文として壊れていないか
 *   2. 誤答例（wa*）が正答（ta）と数式的に完全一致していないか
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
     * @return array{valid: bool, errors: string[]}
     * @throws \moodle_exception goemaximaとの通信エラーのとき
     */
    public function validate_one(string $questionxml, int $qnum): array {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($questionxml)) {
            return ['valid' => false, 'errors' => ["問題{$qnum}: 生成されたXMLがパースできません（整形式のXMLではありません）"]];
        }

        $questions = $dom->getElementsByTagName('question');
        if ($questions->length === 0) {
            return ['valid' => false, 'errors' => ["問題{$qnum}: <question> 要素が見つかりません"]];
        }

        $errors = $this->validate_question($questions->item(0), $qnum);
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * @return string[] このquestionで見つかったエラーメッセージ（なければ空配列）
     */
    private function validate_question(\DOMElement $question, int $qnum): array {
        $qvnodes = $question->getElementsByTagName('questionvariables');
        if ($qvnodes->length === 0) {
            return ["問題{$qnum}: <questionvariables> が見つかりません"];
        }
        $textnodes = $qvnodes->item(0)->getElementsByTagName('text');
        $qvtext = $textnodes->length > 0 ? $textnodes->item(0)->textContent : '';
        $qvtext = trim($qvtext);

        if ($qvtext === '' || !preg_match('/^\s*ta\s*:/m', $qvtext)) {
            return ["問題{$qnum}: questionvariables に ta の定義が見つかりません"];
        }

        // wa1, wa2, ... のような誤答例変数名を抽出（ta以外の代入先。早期終了型/従来版・RAGなし版用）。
        preg_match_all('/^\s*(wa\d+)\s*:/m', $qvtext, $m);
        $wanames = $m[1];

        // wa1_pred(ex):=..., wa2_pred(ex):=... のような誤答判定述語関数名を抽出（KC診断版の全ノード評価モード用）。
        preg_match_all('/^\s*(wa\d+_pred)\s*\(\s*ex\s*\)\s*:=/m', $qvtext, $pm);
        $prednames = $pm[1];

        $errors = [];

        if (empty($wanames) && empty($prednames)) {
            // 誤答例なし: ta単体の構文チェックのみ。
            $response = $this->call_goemaxima_with_retry("{$qvtext} ta;");
            if ($this->looks_like_error($response)) {
                $errors[] = "問題{$qnum}: ta の構文エラー: " . $this->extract_error_detail($response);
            }
            return $errors;
        }

        foreach ($wanames as $waname) {
            $input = self::NORM_FUNC . " {$qvtext} "
                . "is(ratsimp(stackgen_norm(ta)-stackgen_norm({$waname}))=0);";
            $response = $this->call_goemaxima_with_retry($input);

            if ($this->looks_like_error($response)) {
                $errors[] = "問題{$qnum}: ta / {$waname} の構文エラー: " . $this->extract_error_detail($response);
                continue;
            }

            $result = trim((string)$this->extract_last_result($response));
            if ($result === 'true') {
                $errors[] = "問題{$qnum}: 誤答例 {$waname} が正答 ta と数式的に一致しています（PRTの誤答判定が機能しません）";
            } else if ($result !== 'false') {
                $errors[] = "問題{$qnum}: {$waname} の検証結果を判定できませんでした（応答: {$result}）";
            }
        }

        // KC診断版(全ノード評価モード)の安全チェック: 各誤答判定述語は、正答taに対して必ずfalseを
        // 返さなければならない（正答した学生を誤ってKC欠落と判定してはならないため）。
        $freevars = null;
        foreach ($prednames as $predname) {
            $input = "{$qvtext} is(not({$predname}(ta)));";
            $response = $this->call_goemaxima_with_retry($input);

            if ($this->looks_like_error($response)) {
                $errors[] = "問題{$qnum}: ta / {$predname} の構文エラー: " . $this->extract_error_detail($response);
                continue;
            }

            $result = trim((string)$this->extract_last_result($response));
            if ($result === 'true') {
                continue;
            }
            if ($result === 'false') {
                $errors[] = "問題{$qnum}: 誤答判定述語 {$predname} が正答 ta に対してtrueを返しています"
                    . "（正答した学生を誤ってKC欠落と判定してしまいます）";
                continue;
            }

            // is() が symbolic に真偽を決定できなかった場合（応答: unknown 等）。ta に自由変数
            // （x, c など）が残っていると、Maximaの is() は一般にその大小・真偽を決定できないため、
            // 具体的な数値を代入した上で再検証する（STACKの等価判定でも使われる数値サンプリング）。
            if ($freevars === null) {
                $freevars = $this->free_vars($qvtext);
            }
            $numericresult = $this->check_predicate_numeric($qvtext, $predname, $freevars);
            if ($numericresult === 'true') {
                continue;
            }
            if ($numericresult === 'false') {
                $errors[] = "問題{$qnum}: 誤答判定述語 {$predname} が正答 ta に対してtrueを返しています"
                    . "（正答した学生を誤ってKC欠落と判定してしまいます・数値代入検証）";
                continue;
            }
            $errors[] = "問題{$qnum}: {$predname} の検証結果を判定できませんでした"
                . "（応答: {$result}、数値代入検証でも判定不能）";
        }

        return $errors;
    }

    /**
     * ta に含まれる自由変数名の一覧を取得する（例: [x, c]）。取得できなければ空配列。
     */
    private function free_vars(string $qvtext): array {
        $response = $this->call_goemaxima_with_retry("{$qvtext} listofvars(ta);");
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
    private function check_predicate_numeric(string $qvtext, string $predname, array $vars): string {
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
            $input = "{$qvtext} is(not({$predname}(subst([{$substlist}], ta))));";
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
