<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'qbank_stackgen';
$plugin->version      = 2026102100;
$plugin->requires     = 2024100700; // Moodle 4.5
$plugin->maturity     = MATURITY_ALPHA;
$plugin->release      = '0.1.2';
$plugin->dependencies = [
    'qtype_stack' => ANY_VERSION,
];
