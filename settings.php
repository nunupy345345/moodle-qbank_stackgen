<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_heading(
        'qbank_stackgen/apiheading',
        get_string('settings_api_heading', 'qbank_stackgen'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_stackgen/google_api_key',
        get_string('settings_google_api_key', 'qbank_stackgen'),
        get_string('settings_google_api_key_desc', 'qbank_stackgen'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configselect(
        'qbank_stackgen/llm_provider',
        get_string('settings_llm_provider', 'qbank_stackgen'),
        get_string('settings_llm_provider_desc', 'qbank_stackgen'),
        'claude',
        [
            'claude' => 'Claude (Anthropic)',
            'openai' => 'OpenAI (GPT-4o)',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_stackgen/anthropic_api_key',
        get_string('settings_anthropic_api_key', 'qbank_stackgen'),
        get_string('settings_anthropic_api_key_desc', 'qbank_stackgen'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_stackgen/openai_api_key',
        get_string('settings_openai_api_key', 'qbank_stackgen'),
        get_string('settings_openai_api_key_desc', 'qbank_stackgen'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_stackgen/maxima_url',
        get_string('settings_maxima_url', 'qbank_stackgen'),
        get_string('settings_maxima_url_desc', 'qbank_stackgen'),
        'http://maxima:8080/goemaxima',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_stackgen/max_retry',
        get_string('settings_max_retry', 'qbank_stackgen'),
        get_string('settings_max_retry_desc', 'qbank_stackgen'),
        '3',
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'qbank_stackgen/ragheading',
        get_string('settings_rag_heading', 'qbank_stackgen'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'qbank_stackgen/rag_enabled',
        get_string('settings_rag_enabled', 'qbank_stackgen'),
        get_string('settings_rag_enabled_desc', 'qbank_stackgen'),
        '1'
    ));

    $settings->add(new admin_setting_configtext(
        'qbank_stackgen/rag_topk',
        get_string('settings_rag_topk', 'qbank_stackgen'),
        get_string('settings_rag_topk_desc', 'qbank_stackgen'),
        '3',
        PARAM_INT
    ));
}
