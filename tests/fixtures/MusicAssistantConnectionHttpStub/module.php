<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../MusicAssistantConnection/module.php';

// Ersetzt in Tests nur den HTTP-Aufruf der Connection; alle übrigen Abläufe bleiben unverändert.
// Ohne vorgegebene Antwort schlägt der Aufruf fehl, damit Tests nie echte Netzwerkzugriffe ausführen.
class MusicAssistantConnectionHttpStub extends MusicAssistantConnection
{
    public static $responses = [];
    public static $calls = [];

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
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
