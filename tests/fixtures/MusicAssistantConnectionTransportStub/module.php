<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../MusicAssistantConnection/module.php';

// Ersetzt in Tests nur den HTTP-Transport; maCall() wertet die simulierte Antwort wie im Betrieb aus.
class MusicAssistantConnectionTransportStub extends MusicAssistantConnection
{
    public static $response = null;
    public static $requests = [];

    protected function maHttpPost(string $url, array $headers, string|false $postFields, int $timeoutMs): array
    {
        self::$requests[] = [
            'url'       => $url,
            'headers'   => $headers,
            'body'      => $postFields,
            'timeoutMs' => $timeoutMs
        ];
        if (!is_array(self::$response)) {
            throw new Exception('No simulated HTTP transport response');
        }

        return self::$response;
    }
}
