<?php
defined('MOODLE_INTERNAL') || die();

$string['modulename']               = 'LiveStream Webinar';
$string['modulenameplural']         = 'LiveStream Webinars';
$string['modulename_help']          = 'The LiveStream module allows creating webconference sessions integrated into Moodle via the LiveStream UN-CHK platform.';
$string['pluginname']               = 'LiveStream UN-CHK';
$string['pluginadministration']     = 'LiveStream Administration';
$string['livestream:addinstance']   = 'Add a LiveStream webinar';
$string['livestream:view']          = 'View a LiveStream webinar';
$string['livestream:moderate']      = 'Moderate a LiveStream webinar';
$string['apiurl']                   = 'LiveStream platform URL';
$string['apiurl_desc']              = 'Full URL of your LiveStream UN-CHK instance (e.g. https://preprod-webinaire.unchk.sn)';
$string['apikey']                   = 'API Key';
$string['apikey_desc']              = 'Secret key shared between Moodle and LiveStream (MOODLE_API_KEY in LiveStream .env)';
$string['apitimeout']               = 'Timeout (seconds)';
$string['apitimeout_desc']          = 'Maximum delay for API calls to LiveStream';
$string['sessionname']              = 'Session name';
$string['sessiondesc']              = 'Description';
$string['joinbutton']               = 'Join session';
$string['startbutton']              = 'Start session';
$string['recordings']               = 'Recordings';
$string['norecordings']             = 'No recordings available';
$string['status_live']              = 'Live';
$string['status_scheduled']         = 'Scheduled';
$string['status_ended']             = 'Ended';
$string['sessionready']             = 'This room is ready. You can join the session now.';
$string['sessionlive']              = 'Session in progress — join now!';
$string['apierror']                 = 'LiveStream platform error: {$a}';
$string['roomreset_backendchanged'] = 'The LiveStream platform address has changed since this room was created: the previous room belonged to a different server. The link has been reset — a moderator can recreate the room on the current platform.';
$string['roomreset_roommissing']    = 'The associated room no longer exists on the LiveStream platform (deleted, or created on a different server). The link has been reset — a moderator can recreate it.';
$string['errorconnection']          = 'Unable to reach the LiveStream platform (network issue or server unavailable). Please try again shortly; contact your administrator if the issue persists.';
$string['errornotenrolled']         = 'You do not have moderator rights on the LiveStream platform yet ({$a}). Log in at least once on the platform, or contact your administrator to obtain the required rights.';
$string['errorratelimit']           = 'Too many attempts. Please wait a minute before trying again.';
$string['notconnected']             = 'You must log in to the LiveStream platform at least once before joining a session.';
$string['duration_minutes']         = '{$a} min';
$string['deleterecording']          = 'Delete';
$string['viewrecording']            = 'View';
$string['autoenroll']               = 'Automatic enrollment';
$string['autoenroll_desc']          = 'Automatically enroll course participants in the LiveStream room';

// V09 — Audit event strings.
$string['event_session_joined']     = 'LiveStream session joined';
$string['event_session_started']    = 'LiveStream session started';
$string['event_recording_deleted']  = 'LiveStream recording deleted';
$string['event_room_created']       = 'LiveStream room created';
