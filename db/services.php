<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'qbank_stackgen_get_job_statuses' => [
        'classname'    => 'qbank_stackgen\external\get_job_statuses',
        'description'  => 'Get current status/render data for the current user\'s recent qbank_stackgen jobs in a course, for live UI polling.',
        'type'         => 'read',
        'capabilities' => 'moodle/question:add',
        'ajax'         => true,
    ],
    'qbank_stackgen_get_kc_job_statuses' => [
        'classname'    => 'qbank_stackgen\external\get_kc_job_statuses',
        'description'  => 'Get current status/render data for the current user\'s KC-track jobs in a course (kc_review.php), for live UI polling.',
        'type'         => 'read',
        'capabilities' => 'moodle/question:add',
        'ajax'         => true,
    ],
];
