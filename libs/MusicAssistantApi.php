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
        // Music Assistant JSON-RPC Endpoint
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
     * @return array{raw:mixed, result:mixed, success:bool, http_code:int, body:string}
     */
    protected function maCall(string $command, array $args = [], int $timeoutMs = 8000): array
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

        $ch = curl_init($this->maApiUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $reqJson,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_FOLLOWLOCATION => true // falls Proxy/Redirect im Spiel ist
        ]);

        $respBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = (string)curl_error($ch);
        curl_close($ch);

        if ($respBody === false || $respBody === null) {
            throw new Exception('HTTP request failed: ' . $err);
        }

        $body = (string)$respBody;
        $this->SendDebug('MA HTTP', 'Code=' . $httpCode . ' Body=' . $body, 0);

        $trim = trim($body);

        // 1) Leere Antwort (z.B. 204 No Content) als Erfolg akzeptieren
        if ($trim === '' && ($httpCode === 200 || $httpCode === 204)) {
            return [
                'raw' => ['result' => null],
                'result' => null,
                'success' => true,
                'http_code' => $httpCode,
                'body' => $body
            ];
        }

        // 2) JSON "null" akzeptieren
        if ($trim === 'null') {
            return [
                'raw' => ['result' => null],
                'result' => null,
                'success' => true,
                'http_code' => $httpCode,
                'body' => $body
            ];
        }

        $data = json_decode($body, true);

        // 3) Wenn kein JSON: Exception mit Details (HTTP-Code + Body-Auszug)
        if (!is_array($data)) {
            $snippet = substr($trim, 0, 300);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function maAsList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        return $isList ? $value : [];
    }
}
