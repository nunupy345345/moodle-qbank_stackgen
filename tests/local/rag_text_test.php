<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RAG検索の埋め込みテキスト生成(rag_text)の単体テスト。DBアクセスなしの純粋な変換のみ。
 */
class rag_text_test extends \basic_testcase {

    public function test_build_appends_expression(): void {
        $this->assertSame("次を微分せよ。\n式: x*sin(x)", rag_text::build('次を微分せよ。', 'x*sin(x)'));
    }

    public function test_build_without_expression_returns_qtext(): void {
        $this->assertSame('次を微分せよ。', rag_text::build('次を微分せよ。', ' '));
    }

    public function test_extract_expression_reads_expr_variable(): void {
        $q = simplexml_load_string('<question><questionvariables><text>expr:x*sin(x);
ans:integrate(expr, x);</text></questionvariables></question>');
        $this->assertSame('x*sin(x)', rag_text::extract_expression($q));
    }

    public function test_extract_expression_reads_ex_variable(): void {
        $q = simplexml_load_string('<question><questionvariables><text>ex: x/sqrt(2*x+1);
ans: int(ex, x);</text></questionvariables></question>');
        $this->assertSame('x/sqrt(2*x+1)', rag_text::extract_expression($q));
    }

    public function test_extract_expression_empty_when_absent(): void {
        $q = simplexml_load_string('<question><questionvariables><text>a:1;</text></questionvariables></question>');
        $this->assertSame('', rag_text::extract_expression($q));
    }

    public function test_latex_to_maxima(): void {
        $this->assertSame('(2x-1)/(3x+4)', rag_text::latex_to_maxima('\frac{2x-1}{3x+4}'));
        $this->assertSame('x sin x', rag_text::latex_to_maxima('x\sin x'));
        $this->assertSame('(x)/(sqrt(2x+1))', rag_text::latex_to_maxima('\frac{x}{\sqrt{2x+1}}'));
        $this->assertSame('x e^(-x^2)', rag_text::latex_to_maxima('x e^{-x^2}'));
        $this->assertSame('ln abs(sin x)', rag_text::latex_to_maxima('\ln|\sin x|'));
        $this->assertSame('cos((2)/(3)x)', rag_text::latex_to_maxima('\cos\left(\frac{2}{3}x\right)'));
    }
}
