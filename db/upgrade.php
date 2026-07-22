<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Mises à niveau du schéma de mod_livestream.
 *
 * @param int $oldversion version installée avant cette mise à niveau
 * @return bool
 */
function xmldb_livestream_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // V15 — colonne roomapihost : mémorise l'hôte de l'apiurl au moment de la
    // création de la salle. Permet de détecter qu'un roomid stocké appartient à
    // un autre backend (ex. salle créée contre preprod-webinaire alors que
    // l'activité pointe désormais sur webinaire) et de forcer sa recréation.
    if ($oldversion < 2026072200) {
        $table = new xmldb_table('livestream');
        $field = new xmldb_field('roomapihost', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'roomname');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072200, 'livestream');
    }

    return true;
}
