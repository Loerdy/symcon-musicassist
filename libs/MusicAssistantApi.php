<?php
declare(strict_types=1);

trait MusicAssistantApi
{
    private function maBaseUrl(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = (int)$this->ReadPropertyInteger('Port');
        return sprintf('http://%s:%d', $host, $port);
    }

    private function maApiUrl(): string
    {
        return $this->maBaseUrl() . '/api';
    }

    private function maNextMessageId(): string
    {
        $id = (int)$this->GetBuffer('MsgId');
        $id++;
        $this->SetBuffer('MsgId', (string)$id);
        return (string)$id;
    }

    private function maSemaphoreName(): string
    {
        // pro Instanz serialisieren
        return 'MA_API_' . (string)$this->InstanceID;
    }

    /**
     * @return array{raw:mixed, result:mixed, success:bool, http_code:int, body:string}
     */
    protected function maCall(
        string $command,
        array $args = [],
        int $timeoutMs = 20000,
        bool $debugResponseBody = true
    ): array
    {
        $payload = [
            'message_id' => $this->maNextMessageId(),
            'command'    => $command,
            'args'       => (object)$args
        ];

        $token = trim($this->ReadPropertyString('Token'));
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $reqJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->SendDebug('MA Request', $reqJson, 0);

        // Konkurrierende HTTP-/Artwork-Zugriffe auf dieselbe Connection serialisieren.
        if (!IPS_SemaphoreEnter($this->maSemaphoreName(), 15000)) {
            throw new Exception('MA API semaphore timeout (parallel requests blocked)');
        }

        try {
            $ch = curl_init($this->maApiUrl());
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER     => true,
                CURLOPT_POST               => true,
                CURLOPT_HTTPHEADER         => $headers,
                CURLOPT_POSTFIELDS         => $reqJson,
                CURLOPT_TIMEOUT_MS         => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS  => 5000,
                CURLOPT_FOLLOWLOCATION     => true
            ]);

            $respBody = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = (string)curl_error($ch);
            curl_close($ch);
        } finally {
            IPS_SemaphoreLeave($this->maSemaphoreName());
        }

        if ($respBody === false || $respBody === null) {
            throw new Exception('HTTP request failed: ' . $err);
        }

        $body = (string)$respBody;

        // Debug Body nicht komplett loggen (kann riesig werden) -> nur Snippet
        $trim = trim($body);
        $snippet = substr($trim, 0, 800);
        if ($debugResponseBody) {
            $this->SendDebug('MA HTTP', 'Code=' . $httpCode . ' BodySnippet=' . $snippet, 0);
        } else {
            $this->SendDebug('MA HTTP', 'Code=' . $httpCode, 0);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = 'HTTP request failed with status ' . $httpCode;
            if ($snippet !== '') {
                $message .= ' Body: ' . $snippet;
            }
            throw new Exception($message);
        }

        if (($trim === '' && ($httpCode === 200 || $httpCode === 204)) || $trim === 'null') {
            return [
                'raw' => ['result' => null],
                'result' => null,
                'success' => true,
                'http_code' => $httpCode,
                'body' => $body
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception('Invalid JSON response from Music Assistant. HTTP ' . $httpCode . ' Body: ' . $snippet);
        }

        $success = !isset($data['error']);
        $result  = $data['result'] ?? $data;

        return [
            'raw' => $data,
            'result' => $result,
            'success' => $success,
            'http_code' => $httpCode,
            'body' => $body
        ];
    }
}
