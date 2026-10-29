<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../MusicAssistantConnection/module.php';

// Ersetzt in Tests nur den HTTP-Aufruf der Connection; alle übrigen Abläufe bleiben unverändert.
// Ohne vorgegebene Antwort schlägt der Aufruf fehl, damit Tests nie echte Netzwerkzugriffe ausführen.
class MusicAssistantConnectionHttpStub extends MusicAssistantConnection
{
    public static $responses = [];
    public static $calls = [];
    public static $artworkResponses = [];
    public static $artworkRequests = [];

    protected function maCall(
        string $command,
        array $args = [],
        int $timeoutMs = 20000,
        bool $debugResponseBody = false
    ): array
    {
        self::$calls[] = ['command' => $command, 'args' => $args];
        if (!array_key_exists($command, self::$responses)) {
            throw new Exception('No simulated HTTP response for ' . $command);
        }
        $response = self::$responses[$command];
        // Eine Closure simuliert Ereignisse, die während des laufenden HTTP-Aufrufs eintreffen.
        if ($response instanceof Closure) {
            $response = $response($args);
        }
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    // Antworten werden in der vorgegebenen Reihenfolge verbraucht, damit Wiederholungsversuche prüfbar sind.
    protected function artworkHttpGet(string $url, array $headers): array
    {
        self::$artworkRequests[] = ['url' => $url, 'headers' => $headers];
        if (count(self::$artworkResponses) === 0) {
            throw new Exception('No simulated artwork response');
        }
        $response = array_shift(self::$artworkResponses);
        if ($response instanceof Closure) {
            $response = $response();
        }
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
