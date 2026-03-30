<?php
defined('MOODLE_INTERNAL') || die();

class mod_livestream_api {
    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    public function __construct() {
        $this->baseUrl = rtrim(get_config('mod_livestream', 'apiurl'), '/');
        $this->apiKey  = get_config('mod_livestream', 'apikey');
        $this->timeout = (int)(get_config('mod_livestream', 'apitimeout') ?: 30);

        // Validation config obligatoire
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

    private function request(string $method, string $path, array $data = []): array {
        $url  = $this->baseUrl . $path;
        $curl = curl_init();

        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->apiKey,
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL             => $url,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
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

        if ($curlError) {
            throw new moodle_exception('apierror', 'mod_livestream', '', $curlError);
        }

        if ($response === false || $response === '') {
            throw new moodle_exception('apierror', 'mod_livestream', '', 'Réponse vide du serveur');
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('apierror', 'mod_livestream', '', 'Réponse invalide du serveur');
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error'] ?? 'Erreur inconnue (HTTP ' . $httpCode . ')';
            throw new moodle_exception('apierror', 'mod_livestream', '', $msg);
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function createRoom(string $courseId, string $meetingId, string $title, string $moderatorEmail, string $description = ''): array {
        return $this->request('POST', '/api/moodle/rooms', [
            'courseId'       => $courseId,
            'meetingId'      => $meetingId,
            'title'          => $title,
            'description'    => $description,
            'moderatorEmail' => $this->validateEmail($moderatorEmail),
        ]);
    }

    public function getRoomStatus(string $roomId): array {
        $roomId = $this->validateId($roomId, 'roomId');
        return $this->request('GET', '/api/moodle/rooms/' . $roomId . '/status');
    }

    public function getRecordings(string $roomId): array {
        $roomId = $this->validateId($roomId, 'roomId');
        return $this->request('GET', '/api/moodle/rooms/' . $roomId . '/recordings');
    }

    public function joinRoom(string $roomId, string $userEmail, string $userName): array {
        $roomId = $this->validateId($roomId, 'roomId');
        return $this->request('POST', '/api/moodle/join', [
            'roomId'    => $roomId,
            'userEmail' => $this->validateEmail($userEmail),
            'userName'  => $userName,
        ]);
    }

    public function startRoom(string $roomId, string $moderatorEmail, string $moderatorName): array {
        $roomId = $this->validateId($roomId, 'roomId');
        return $this->request('POST', '/api/moodle/start', [
            'roomId'         => $roomId,
            'moderatorEmail' => $this->validateEmail($moderatorEmail),
            'moderatorName'  => $moderatorName,
        ]);
    }

    public function enrollUsers(string $roomId, array $emails): array {
        $roomId = $this->validateId($roomId, 'roomId');
        $validEmails = [];
        foreach ($emails as $email) {
            if (validate_email($email)) {
                $validEmails[] = $email;
            }
        }
        if (empty($validEmails)) {
            throw new moodle_exception('invalidparameter', 'mod_livestream', '', 'emails');
        }
        return $this->request('POST', '/api/moodle/enroll', [
            'roomId' => $roomId,
            'emails' => $validEmails,
        ]);
    }

    public function deleteRecording(string $recordingId): array {
        $recordingId = $this->validateId($recordingId, 'recordingId');
        return $this->request('DELETE', '/api/moodle/recordings/' . $recordingId);
    }
}
