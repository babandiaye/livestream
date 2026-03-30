<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_configtext(
        'mod_livestream/apiurl',
        get_string('apiurl', 'mod_livestream'),
        get_string('apiurl_desc', 'mod_livestream'),
        'https://preprod-webinaire.unchk.sn',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_livestream/apikey',
        get_string('apikey', 'mod_livestream'),
        get_string('apikey_desc', 'mod_livestream'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'mod_livestream/apitimeout',
        get_string('apitimeout', 'mod_livestream'),
        get_string('apitimeout_desc', 'mod_livestream'),
        '30',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_livestream/autoenroll',
        get_string('autoenroll', 'mod_livestream'),
        get_string('autoenroll_desc', 'mod_livestream'),
        1
    ));
}

