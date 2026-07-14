<?php
defined('MOODLE_INTERNAL') || die();

// V11 — exception bas niveau : porte le code HTTP (0 = pas de réponse / erreur réseau)
// et le message brut de l'API, pour permettre aux méthodes publiques de mod_livestream_api
// de choisir le bon message utilisateur selon le contexte de l'appel.
class mod_livestream_api_response_exception extends \Exception {
    public int $httpcode;
    public string $apimessage;

    public function __construct(int $httpcode, string $apimessage) {
        $this->httpcode   = $httpcode;
        $this->apimessage = $apimessage;
        parent::__construct($apimessage);
    }
}

class mod_livestream_api {
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct() {
        $this->baseUrl = rtrim(get_config('mod_livestream', 'apiurl'), '/');
        $this->apiKey  = get_config('mod_livestream', 'apikey');
        $this->timeout = (int)(get_config('mod_livestream', 'apitimeout') ?: 30);

        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new moodle_exception('apinotconfigured', 'mod_livestream');
        }
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw new moodle_exception('invalidapiurl', 'mod_livestream');
        }
    }

    private function validateId(string $id, string $param = 'id'): string {
        if (empty($id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            throw new moodle_exception('invalidparameter', 'mod_livestream', '', $param);
        }
        return $id;
    }

    private function validateEmail(string $email): string {
        if (!validate_email($email)) {
            throw new moodle_exception('invalidparameter', 'mod_livestream', '', 'email');
        }
        return $email;
    }

    // V08 — sanitiser userName avant envoi API
    private function sanitizeUserName(string $name): string {
        $name = clean_param($name, PARAM_TEXT);
        $name = mb_substr(trim($name), 0, 100);
        if (empty($name)) {
            $name = 'Utilisateur';
        }
        return $name;
    }

    private function request(string $method, string $path, array $data = []): array {
        $url  = $this->baseUrl . $path;
        $curl = curl_init();

        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->apiKey,
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            $payload = json_encode($data);
            if ($payload === false) {
                throw new moodle_exception('jsonencodeerror', 'mod_livestream');
            }
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        } elseif ($method === 'DELETE') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($data)) {
                $payload = json_encode($data);
                if ($payload === false) {
                    throw new moodle_exception('jsonencodeerror', 'mod_livestream');
                }
                curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
            }
        }

        $response  = curl_exec($curl);
        $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        // V10 — message générique sans données sensibles côté cURL (pas de fuite d'infra),
        // mais toujours journalisé pour les développeurs. V11 — httpcode=0 signale "pas de
        // réponse HTTP" (réseau/timeout), distinct d'une réponse HTTP d'erreur.
        if ($curlError) {
            debugging('LiveStream cURL error: ' . $curlError, DEBUG_DEVELOPER);
            throw new mod_livestream_api_response_exception(0,
                'Erreur de communication avec le serveur de streaming');
        }

        if ($response === false || $response === '') {
            throw new mod_livestream_api_response_exception(0, 'Réponse vide du serveur');
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new mod_livestream_api_response_exception($httpCode, 'Réponse invalide du serveur');
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error'] ?? 'Erreur inconnue (HTTP ' . $httpCode . ')';
            // V11 — désormais journalisé (auparavant silencieux pour les erreurs HTTP).
            debugging('LiveStream API error HTTP ' . $httpCode . ': ' . $msg, DEBUG_DEVELOPER);
            throw new mod_livestream_api_response_exception($httpCode, $msg);
        }

        return is_array($decoded) ? $decoded : [];
    }

    // V11 — traduit l'exception bas niveau en message utilisateur actionnable.
    // $notenrolled404 : pour certains endpoints (ex. création de salle), un 404 signifie
    // spécifiquement "compte modérateur introuvable", pas "salle introuvable".
    private function translateApiException(
        mod_livestream_api_response_exception $e,
        bool $notenrolled404 = false
    ): moodle_exception {
        $debuginfo = 'HTTP ' . $e->httpcode;

        if ($e->httpcode === 0) {
            return new moodle_exception('errorconnection', 'mod_livestream', '', null, $debuginfo);
        }
        if ($e->httpcode === 403 || ($notenrolled404 && $e->httpcode === 404)) {
            return new moodle_exception('errornotenrolled', 'mod_livestream', '', $e->apimessage, $debuginfo);
        }
        return new moodle_exception('apierror', 'mod_livestream', '', $e->apimessage, $debuginfo);
    }

    public function createRoom(string $courseId, string $meetingId, string $title, string $moderatorEmail, string $description = ''): array {
        try {
            return $this->request('POST', '/api/moodle/rooms', [
                'courseId'       => $courseId,
                'meetingId'      => $meetingId,
                'title'          => $title,
                'description'    => $description,
                'moderatorEmail' => $this->validateEmail($moderatorEmail),
            ]);
        } catch (mod_livestream_api_response_exception $e) {
            // Sur cet endpoint, un 404 signifie "compte modérateur introuvable" (V11).
            throw $this->translateApiException($e, true);
        }
    }

    public function getRoomStatus(string $roomId): array {
        $roomId = $this->validateId($roomId, 'roomId');
        try {
            return $this->request('GET', '/api/moodle/rooms/' . $roomId . '/status');
        } catch (mod_livestream_api_response_exception $e) {
            throw $this->translateApiException($e);
        }
    }

    public function getRecordings(string $roomId): array {
        $roomId = $this->validateId($roomId, 'roomId');
        try {
            return $this->request('GET', '/api/moodle/rooms/' . $roomId . '/recordings');
        } catch (mod_livestream_api_response_exception $e) {
            throw $this->translateApiException($e);
        }
    }

    public function joinRoom(string $roomId, string $userEmail, string $userName): array {
        $roomId   = $this->validateId($roomId, 'roomId');
        $userName = $this->sanitizeUserName($userName); // V08
        try {
            return $this->request('POST', '/api/moodle/join', [
                'roomId'    => $roomId,
                'userEmail' => $this->validateEmail($userEmail),
                'userName'  => $userName,
            ]);
        } catch (mod_livestream_api_response_exception $e) {
            throw $this->translateApiException($e);
        }
    }

    public function startRoom(string $roomId, string $moderatorEmail, string $moderatorName): array {
        $roomId        = $this->validateId($roomId, 'roomId');
        $moderatorName = $this->sanitizeUserName($moderatorName); // V08
        try {
            return $this->request('POST', '/api/moodle/start', [
                'roomId'         => $roomId,
                'moderatorEmail' => $this->validateEmail($moderatorEmail),
                'moderatorName'  => $moderatorName,
            ]);
        } catch (mod_livestream_api_response_exception $e) {
            // Sur cet endpoint, un 403 signifie "pas encore modérateur" (V11).
            throw $this->translateApiException($e);
        }
    }

    public function enrollUsers(string $roomId, array $emails): array {
        $roomId      = $this->validateId($roomId, 'roomId');
        $validEmails = [];
        foreach ($emails as $email) {
            if (validate_email($email)) {
                $validEmails[] = $email;
            }
        }
        if (empty($validEmails)) {
            throw new moodle_exception('invalidparameter', 'mod_livestream', '', 'emails');
        }
        try {
            return $this->request('POST', '/api/moodle/enroll', [
                'roomId' => $roomId,
                'emails' => $validEmails,
            ]);
        } catch (mod_livestream_api_response_exception $e) {
            throw $this->translateApiException($e);
        }
    }

    public function deleteRecording(string $recordingId): array {
        $recordingId = $this->validateId($recordingId, 'recordingId');
        try {
            return $this->request('DELETE', '/api/moodle/recordings/' . $recordingId);
        } catch (mod_livestream_api_response_exception $e) {
            throw $this->translateApiException($e);
        }
    }
}
