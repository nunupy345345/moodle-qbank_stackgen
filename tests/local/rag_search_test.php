<?php
namespace qbank_stackgen\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Step 6 (RAG): rag_search::cosine_similarity() の単体テスト。
 *
 * DBアクセスを伴わない純粋な計算のみをテストする(advanced_testcaseではなくbasic_testcaseでよい)。
 */
class rag_search_test extends \basic_testcase {

    public function test_identical_vectors_have_similarity_one(): void {
        $v = [1.0, 2.0, 3.0];
        $this->assertEqualsWithDelta(1.0, rag_search::cosine_similarity($v, $v), 1e-9);
    }

    public function test_orthogonal_vectors_have_similarity_zero(): void {
        $this->assertEqualsWithDelta(0.0, rag_search::cosine_similarity([1.0, 0.0], [0.0, 1.0]), 1e-9);
    }

    public function test_opposite_vectors_have_similarity_negative_one(): void {
        $this->assertEqualsWithDelta(-1.0, rag_search::cosine_similarity([1.0, 2.0], [-1.0, -2.0]), 1e-9);
    }

    public function test_more_similar_vector_scores_higher(): void {
        $query = [1.0, 1.0, 0.0];
        $close = [1.0, 0.9, 0.1];
        $far   = [0.0, 0.1, 1.0];

        $simclose = rag_search::cosine_similarity($query, $close);
        $simfar   = rag_search::cosine_similarity($query, $far);

        $this->assertGreaterThan($simfar, $simclose);
    }

    public function test_mismatched_dimensions_return_zero(): void {
        $this->assertSame(0.0, rag_search::cosine_similarity([1.0, 2.0], [1.0, 2.0, 3.0]));
    }

    public function test_zero_vector_returns_zero(): void {
        $this->assertSame(0.0, rag_search::cosine_similarity([0.0, 0.0], [1.0, 1.0]));
    }

    public function test_empty_vectors_return_zero(): void {
        $this->assertSame(0.0, rag_search::cosine_similarity([], []));
    }
}
