<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_livestream_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    // 1.4.0 — cache local des enregistrements taggés par serveur webinaire.
    if ($oldversion < 2026061500) {
        $table = new xmldb_table('livestream_recordings');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('livestreamid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('courseid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('groupid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('serverurl',    XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL);
            $table->add_field('roomid',       XMLDB_TYPE_CHAR,   '255', null, null);
            $table->add_field('recordingid',  XMLDB_TYPE_CHAR,   '255', null, XMLDB_NOTNULL);
            $table->add_field('name',         XMLDB_TYPE_CHAR,   '255', null, null);
            $table->add_field('duration',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('recdate',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('playurl',      XMLDB_TYPE_TEXT,    null, null, null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('livestreamid', XMLDB_KEY_FOREIGN, ['livestreamid'], 'livestream', ['id']);

            $table->add_index('instance_server_group', XMLDB_INDEX_NOTUNIQUE, ['livestreamid', 'serverurl', 'groupid']);
            $table->add_index('recordingid', XMLDB_INDEX_NOTUNIQUE, ['recordingid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026061500, 'livestream');
    }

    return true;
}
