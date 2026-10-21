<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'qbank_stackgen';
$plugin->version      = 2026102201;
$plugin->requires     = 2024100700; // Moodle 4.5
$plugin->maturity     = MATURITY_ALPHA;
$plugin->release      = '0.1.3-survey';
$plugin->dependencies = [
    'qtype_stack' => ANY_VERSION,
];
