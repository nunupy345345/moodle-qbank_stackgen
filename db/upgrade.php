<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_qbank_stackgen_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026070800) {
        $table = new xmldb_table('qbank_stackgen_jobs');

        $field = new xmldb_field('feedback_result', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ocr_result');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('feedback_confirmed', XMLDB_TYPE_TEXT, null, null, null, null, null, 'feedback_result');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('llm_cot', XMLDB_TYPE_TEXT, null, null, null, null, null, 'xml_result');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070800, 'qbank', 'stackgen');
    }

    if ($oldversion < 2026071000) {
        $table = new xmldb_table('qbank_stackgen_jobs');

        $field = new xmldb_field('question_ids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'error_msg');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('question_category_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'question_ids');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026071000, 'qbank', 'stackgen');
    }

    return true;
}
