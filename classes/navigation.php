<?php
namespace qbank_stackgen;

class navigation extends \core_question\local\bank\navigation_node_base {

    public function get_navigation_title(): string {
        return get_string('generate_from_image', 'qbank_stackgen');
    }

    public function get_navigation_key(): string {
        return 'qbank_stackgen_upload';
    }

    public function get_navigation_url(): \moodle_url {
        return new \moodle_url('/question/bank/stackgen/upload.php');
    }
}
