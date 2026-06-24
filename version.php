<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component    = 'mod_livestream';
$plugin->version      = 2026062400; // rollback code avril (acc5f98) ; version > 2026061500 pour que Moodle accepte la MAJ
$plugin->requires     = 2024100700; // Moodle 4.5
$plugin->maturity     = MATURITY_BETA;
$plugin->release      = '1.2.3-rollback-avril';
