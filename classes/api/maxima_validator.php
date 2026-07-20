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
    private const NORM_FUNC = 'stackgen_norm(e):=if op(e)="=" then lhs(e)-rhs(e) else e$';

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

        // wa1, wa2, ... のような誤答例変数名を抽出（ta以外の代入先）。
        preg_match_all('/^\s*(wa\d+)\s*:/m', $qvtext, $m);
        $wanames = $m[1];

        $errors = [];

        if (empty($wanames)) {
            // 誤答例なし: ta単体の構文チェックのみ。
            $response = $this->call_goemaxima("{$qvtext} ta;");
            if ($this->looks_like_error($response)) {
                $errors[] = "問題{$qnum}: ta の構文エラー: " . $this->extract_error_detail($response);
            }
            return $errors;
        }

        foreach ($wanames as $waname) {
            $input = self::NORM_FUNC . " {$qvtext} "
                . "is(ratsimp(stackgen_norm(ta)-stackgen_norm({$waname}))=0);";
            $response = $this->call_goemaxima($input);

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

        return $errors;
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
     * ここではエラーマーカーを含む行そのものを抜き出す。
     */
    private function extract_error_detail(string $response): string {
        $lines = preg_split('/\r?\n/', $response);
        $matched = [];
        foreach ($lines as $line) {
            $lower = mb_strtolower($line);
            foreach (self::ERROR_MARKERS as $marker) {
                if (strpos($lower, mb_strtolower($marker)) !== false) {
                    $matched[] = trim($line);
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
