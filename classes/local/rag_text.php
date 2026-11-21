<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RAG検索の「埋め込み対象テキスト」を作る。
 *
 * コーパスの問題文は「次の関数を x で微分せよ。{@expr@}」のように式そのものを含まないため、
 * 問題文だけを埋め込むと、どの問題も類似度がほぼ同じになり検索が機能しない。
 * そこで例側・クエリ側の両方で、問題文に「式」を足したテキストを埋め込む。
 *
 * 記法をそろえるため、例側はSTACK問題の questionvariables にあるMaxima式、
 * クエリ側はOCRの problem_expression(LaTeX)をMaxima風に簡易変換したものを使う。
 */
class rag_text {

    /**
     * @param string $qtext      問題文
     * @param string $expression Maxima風の式(空なら問題文のみ)
     */
    public static function build(string $qtext, string $expression): string {
        $expression = trim($expression);
        return $expression === '' ? $qtext : $qtext . "\n式: " . $expression;
    }

    /**
     * STACK問題の questionvariables から、問題の式を表す変数(expr / ex)の定義を取り出す。
     *
     * @return string Maxima式。見つからなければ空文字
     */
    public static function extract_expression(\SimpleXMLElement $question): string {
        $vars = (string)($question->questionvariables->text ?? '');
        if (preg_match('/(?:^|[;\s])(?:expr|ex)\s*:\s*([^;]+);/u', $vars, $m)) {
            return trim(preg_replace('/\s+/u', ' ', $m[1]));
        }
        return '';
    }

    /**
     * LaTeXの式をMaxima風の文字列へ簡易変換する(検索用。厳密なパーサではない)。
     */
    public static function latex_to_maxima(string $latex): string {
        $s = $latex;
        $s = preg_replace('/\\\\(?:left|right|displaystyle|,|;|!|:)/', '', $s);
        $s = str_replace(['\\cdot', '\\times'], '*', $s);

        // 内側から順に \frac{A}{B} → (A)/(B)、\sqrt{A} → sqrt(A) と展開する。
        for ($i = 0; $i < 10; $i++) {
            $before = $s;
            $s = preg_replace('/\\\\d?frac\s*\{([^{}]*)\}\s*\{([^{}]*)\}/', '(\1)/(\2)', $s);
            $s = preg_replace('/\\\\sqrt\s*\{([^{}]*)\}/', ' sqrt(\1)', $s);
            $s = preg_replace('/\^\s*\{([^{}]*)\}/', '^(\1)', $s);
            if ($s === $before) {
                break;
            }
        }

        // \sin, \ln, \cosh ... → sin, ln, cosh ...
        // 前後に空白を入れて x\sin x → x sin x のように隣の文字とつながらないようにする。
        $s = preg_replace('/\\\\([a-zA-Z]+)/', ' \1 ', $s);
        $s = preg_replace('/\|([^|]*)\|/', ' abs(\1)', $s);
        $s = str_replace(['{', '}'], ['(', ')'], $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        $s = preg_replace(['/\(\s+/', '/\s+\^/'], ['(', '^'], $s);

        // 関数名と括弧の間の空白は詰める(sin (x) → sin(x))。
        return preg_replace('/\b(sin|cos|tan|sinh|cosh|tanh|arcsin|arccos|arctan|ln|log|exp|sqrt|abs) \(/', '\1(', $s);
    }
}
