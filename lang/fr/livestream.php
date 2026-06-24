<?php
defined('MOODLE_INTERNAL') || die();

$string['modulename']               = 'Webinaire LiveStream';
$string['modulenameplural']         = 'Webinaires LiveStream';
$string['modulename_help']          = 'Le module LiveStream permet de créer des sessions de webconférence intégrées à Moodle via la plateforme LiveStream UN-CHK.';
$string['pluginname']               = 'LiveStream UN-CHK';
$string['pluginadministration']     = 'Administration LiveStream';
$string['livestream:addinstance']   = 'Ajouter un webinaire LiveStream';
$string['livestream:view']          = 'Voir un webinaire LiveStream';
$string['livestream:moderate']      = 'Modérer un webinaire LiveStream';
$string['apiurl']                   = 'URL de la plateforme LiveStream';
$string['apiurl_desc']              = 'URL complète de votre instance LiveStream UN-CHK (ex: https://preprod-webinaire.unchk.sn)';
$string['apikey']                   = 'Clé API';
$string['apikey_desc']              = 'Clé secrète partagée entre Moodle et LiveStream (MOODLE_API_KEY dans le .env de LiveStream)';
$string['apitimeout']               = 'Timeout (secondes)';
$string['apitimeout_desc']          = 'Délai maximum pour les appels API vers LiveStream';
$string['sessionname']              = 'Nom de la session';
$string['sessiondesc']              = 'Description';
$string['joinbutton']               = 'Rejoindre la session';
$string['startbutton']              = 'Démarrer la session';
$string['recordings']               = 'Enregistrements';
$string['norecordings']             = 'Aucun enregistrement disponible';
$string['status_live']              = 'En direct';
$string['status_scheduled']         = 'Programmée';
$string['status_ended']             = 'Terminée';
$string['sessionready']             = 'Cette salle est prête. Vous pouvez rejoindre la session maintenant.';
$string['sessionlive']              = 'Session en cours — rejoignez maintenant !';
$string['apierror']                 = 'Erreur de connexion à la plateforme LiveStream. Vérifiez la configuration.';
$string['notconnected']             = 'Vous devez vous connecter une fois sur la plateforme LiveStream avant de pouvoir rejoindre une session.';
$string['duration_minutes']         = '{$a} min';
$string['deleterecording']          = 'Supprimer';
$string['viewrecording']            = 'Voir';
$string['autoenroll']               = 'Enrôlement automatique';
$string['autoenroll_desc']          = 'Enrôler automatiquement les participants du cours dans la salle LiveStream';

// V09 — Chaînes pour les événements d'audit.
$string['event_session_joined']     = 'Session LiveStream rejointe';
$string['event_session_started']    = 'Session LiveStream démarrée';
$string['event_recording_deleted']  = 'Enregistrement LiveStream supprimé';
$string['event_room_created']       = 'Salle LiveStream créée';
