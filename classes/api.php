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
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if (!empty($data)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response  = curl_exec($curl);
        $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($curlError) {
            throw new moodle_exception('apierror', 'mod_livestream', '', $curlError);
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('apierror', 'mod_livestream', '', 'Reponse invalide: ' . $response);
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error'] ?? 'Erreur inconnue';
            throw new moodle_exception('apierror', 'mod_livestream', '', $msg);
        }

        return $decoded;
    }

    public function createRoom(string $courseId, string $meetingId, string $title, string $moderatorEmail, string $description = ''): array {
        return $this->request('POST', '/api/moodle/rooms', [
            'courseId'       => $courseId,
            'meetingId'      => $meetingId,
            'title'          => $title,
            'description'    => $description,
            'moderatorEmail' => $moderatorEmail,
        ]);
    }

    public function getRoomStatus(string $roomId): array {
        return $this->request('GET', '/api/moodle/rooms/' . $roomId . '/status');
    }

    public function getRecordings(string $roomId): array {
        return $this->request('GET', '/api/moodle/rooms/' . $roomId . '/recordings');
    }

    public function joinRoom(string $roomId, string $userEmail, string $userName): array {
        return $this->request('POST', '/api/moodle/join', [
            'roomId'    => $roomId,
            'userEmail' => $userEmail,
            'userName'  => $userName,
        ]);
    }

    public function startRoom(string $roomId, string $moderatorEmail, string $moderatorName): array {
        return $this->request('POST', '/api/moodle/start', [
            'roomId'         => $roomId,
            'moderatorEmail' => $moderatorEmail,
            'moderatorName'  => $moderatorName,
        ]);
    }

    public function enrollUsers(string $roomId, array $emails): array {
        return $this->request('POST', '/api/moodle/enroll', [
            'roomId' => $roomId,
            'emails' => $emails,
        ]);
    }

    public function deleteRecording(string $recordingId): array {
        return $this->request('DELETE', '/api/moodle/recordings/' . $recordingId);
    }
}
