<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Add "画像から生成" link to question bank tertiary navigation.
 */
function qbank_stackgen_extend_navigation_course($navigation, $course, $context) {
    // Tertiary navigation is handled via plugin_feature.php (qbank_feature).
    // This hook is kept for compatibility.
}
