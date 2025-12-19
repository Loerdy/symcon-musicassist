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

    /**
     * @return array{raw: mixed, result: mixed, success: bool}
     */
    protected function maCall(string $command, array $args = [], int $timeoutMs = 6000): array
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

        $this->SendDebug('MA Request', json_encode($payload, JSON_UNESCAPED_SLASHES), 0);

        $ch = curl_init($this->maApiUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT_MS     => $timeoutMs
        ]);

        $respBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = (string)curl_error($ch);
        curl_close($ch);

        if ($respBody === false || $respBody === null) {
            throw new Exception('HTTP request failed: ' . $err);
        }

        $this->SendDebug('MA HTTP', 'Code=' . $httpCode . ' Body=' . (string)$respBody, 0);

        $data = json_decode((string)$respBody, true);
        if (!is_array($data)) {
            throw new Exception('Invalid JSON response from Music Assistant');
        }

        $result = $data['result'] ?? $data;
        $success = !isset($data['error']);

        return ['raw' => $data, 'result' => $result, 'success' => $success];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function maAsList($value): array
    {
        if (is_array($value)) {
            // entweder schon Liste oder assoziativ
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                return $value;
            }
        }
        return [];
    }
}
