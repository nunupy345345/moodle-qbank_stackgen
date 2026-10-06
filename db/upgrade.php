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

    if ($oldversion < 2026082400) {
        $table = new xmldb_table('qbank_stackgen_jobs');

        $field = new xmldb_field('rag_examples_used', XMLDB_TYPE_TEXT, null, null, null, null, null, 'retry_count');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $examplestable = new xmldb_table('qbank_stackgen_examples');
        if (!$dbman->table_exists($examplestable)) {
            $examplestable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $examplestable->add_field('sourcefile', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $examplestable->add_field('category', XMLDB_TYPE_CHAR, '60', null, null, null, null);
            $examplestable->add_field('qtext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $examplestable->add_field('score', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
            $examplestable->add_field('n_nodes', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
            $examplestable->add_field('distinct_tests', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $examplestable->add_field('distinct_scores', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $examplestable->add_field('embedding', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $examplestable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $examplestable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $examplestable->add_index('sourcefile_idx', XMLDB_INDEX_UNIQUE, ['sourcefile']);

            $dbman->create_table($examplestable);
        }

        upgrade_plugin_savepoint(true, 2026082400, 'qbank', 'stackgen');
    }

    if ($oldversion < 2026083100) {
        $table = new xmldb_table('qbank_stackgen_jobs');

        $field = new xmldb_field('sourcefilename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'rag_examples_used');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('courseid_sourcefilename_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'sourcefilename']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026083100, 'qbank', 'stackgen');
    }

    if ($oldversion < 2026092900) {
        $table = new xmldb_table('qbank_stackgen_jobs');

        $field = new xmldb_field('kc_status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, '', 'sourcefilename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_feedback_result', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kc_status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_feedback_confirmed', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kc_feedback_result');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_xml_result', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kc_feedback_confirmed');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_llm_cot', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kc_xml_result');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_error_msg', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kc_llm_cot');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_retry_count', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'kc_error_msg');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_question_ids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kc_retry_count');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('kc_question_category_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'kc_question_ids');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026092900, 'qbank', 'stackgen');
    }

    if ($oldversion < 2026092901) {
        $table = new xmldb_table('qbank_stackgen_jobs');

        $field = new xmldb_field('track_traditional', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'kc_question_category_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('track_kc', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'track_traditional');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026092901, 'qbank', 'stackgen');
    }

    return true;
}
