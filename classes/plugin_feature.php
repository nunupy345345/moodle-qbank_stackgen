<?php
namespace qbank_stackgen;

use core_question\local\bank\navigation_node_base;
use core_question\local\bank\plugin_features_base;
use core_question\local\bank\view;
use context;

class plugin_feature extends plugin_features_base {

    public function get_navigation_node(): ?navigation_node_base {
        return new navigation();
    }

    public function get_question_bank_controls(view $qbank, context $context, int $categoryid): array {
        return [];
    }
}
