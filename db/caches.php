<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'ratelimit' => [
        'mode'       => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'ttl'        => 60, // 60 secondes de fenêtre
    ],
];
