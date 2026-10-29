<?php
declare(strict_types=1);

// API-Anfragen der Child-Module über die MusicAssistantConnection; die verwendende Klasse definiert CONNECTION_REQUEST.
trait MusicAssistantApiClient
{
    private function sendApiRequest(string $apiCommand, array $params = [])
    {
        try {
            $response = $this->SendDataToParent(json_encode([
                'DataID' => self::CONNECTION_REQUEST,
                'Command' => 'ApiRequest',
                'ApiCommand' => $apiCommand,
                'Params' => (object)$params
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            throw new Exception($this->Translate('ApiRequest could not be created or sent.'));
        }

        if (!is_string($response) || trim($response) === '') {
            throw new Exception($this->Translate('ApiRequest did not return a valid response.'));
        }

        try {
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new Exception($this->Translate('ApiRequest returned invalid JSON.'));
        }
        if (!is_array($result)) {
            throw new Exception($this->Translate('ApiRequest response is not a JSON object.'));
        }
        if (($result['success'] ?? false) !== true) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : [];
            $code = is_string($error['code'] ?? null) && trim($error['code']) !== ''
                ? trim($error['code'])
                : 'UNKNOWN_ERROR';
            $message = is_string($error['message'] ?? null) && trim($error['message']) !== ''
                ? trim($error['message'])
                : $this->Translate('ApiRequest failed.');
            throw new Exception($code . ': ' . $message);
        }
        return $result['result'] ?? null;
    }

    private function isList(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }
}
