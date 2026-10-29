<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

abstract class MusicAssistantTestCase extends TestCase
{
    protected const CONNECTION_MODULE_ID = '{880534D6-998A-704B-DFD1-ABCD3D23B811}';
    protected const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';
    protected const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    protected const TOKEN = 'test-token';

    protected function setUp(): void
    {
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $library = json_decode((string)file_get_contents(__DIR__ . '/../library.json'), true);
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/fixtures/WebSocketClient', $library['id']);
        // Gleiche Modul-ID wie die Connection: Instanzen verwenden die Unterklasse mit simuliertem HTTP.
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/fixtures/MusicAssistantConnectionHttpStub', $library['id']);
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/fixtures/MusicAssistantConfiguratorStubCompat', $library['id']);
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/fixtures/MusicAssistantPlayerStubCompat', $library['id']);
        MusicAssistantConnectionHttpStub::$responses = [];
        MusicAssistantConnectionHttpStub::$calls = [];
        MusicAssistantConnectionHttpStub::$artworkResponses = [];
        MusicAssistantConnectionHttpStub::$artworkRequests = [];
        parent::setUp();
    }

    protected function simulateHttpResult(string $command, $result): void
    {
        MusicAssistantConnectionHttpStub::$responses[$command] = [
            'raw'       => ['result' => $result],
            'result'    => $result,
            'success'   => true,
            'http_code' => 200,
            'body'      => json_encode(['result' => $result])
        ];
    }

    // Die Antwort entsteht erst während des HTTP-Aufrufs; so lassen sich zwischenzeitliche Ereignisse simulieren.
    protected function simulateHttpResultDuring(string $command, callable $during, $result): void
    {
        MusicAssistantConnectionHttpStub::$responses[$command] = function (array $args) use ($during, $result): array {
            $during($args);
            return [
                'raw'       => ['result' => $result],
                'result'    => $result,
                'success'   => true,
                'http_code' => 200,
                'body'      => json_encode(['result' => $result])
            ];
        };
    }

    protected function simulateHttpApiError(string $command): void
    {
        MusicAssistantConnectionHttpStub::$responses[$command] = [
            'raw'       => ['error' => 'Player not found'],
            'result'    => ['error' => 'Player not found'],
            'success'   => false,
            'http_code' => 200,
            'body'      => '{"error":"Player not found"}'
        ];
    }

    protected function simulateHttpFailure(string $command): void
    {
        MusicAssistantConnectionHttpStub::$responses[$command] = new Exception('HTTP request failed: timeout');
    }

    protected function artworkResponse(
        int $httpCode,
        string $contentType,
        string $body,
        bool $transferSucceeded = true,
        bool $sizeExceeded = false,
        int $errorNumber = 0
    ): array
    {
        return [
            'transfer_succeeded' => $transferSucceeded,
            'http_code'          => $httpCode,
            'content_type'       => $contentType,
            'body'               => $body,
            'size_exceeded'      => $sizeExceeded,
            'error_number'       => $errorNumber
        ];
    }

    protected function jpegBody(string $marker = 'cover'): string
    {
        return "\xFF\xD8\xFF\xE0" . $marker;
    }

    /**
     * @param array|Throwable|Closure ...$responses
     */
    protected function simulateArtwork(...$responses): void
    {
        MusicAssistantConnectionHttpStub::$artworkResponses = $responses;
    }

    /**
     * @return array<int, array{url: string, headers: array}>
     */
    protected function artworkRequests(): array
    {
        return MusicAssistantConnectionHttpStub::$artworkRequests;
    }

    /**
     * @return array<int, array{command: string, args: array}>
     */
    protected function httpCalls(): array
    {
        return MusicAssistantConnectionHttpStub::$calls;
    }

    protected function clearHttpCalls(): void
    {
        MusicAssistantConnectionHttpStub::$calls = [];
    }

    // Legt eine konfigurierte Connection an, deren WebSocket Client die passende URL übernommen hat und aktiv ist.
    protected function createConnection(string $host = '127.0.0.1', int $port = 8095): int
    {
        $connectionId = IPS_CreateInstance(self::CONNECTION_MODULE_ID);
        IPS_SetProperty($connectionId, 'Host', $host);
        IPS_SetProperty($connectionId, 'Port', $port);
        IPS_SetProperty($connectionId, 'Token', self::TOKEN);
        IPS_ApplyChanges($connectionId);

        $parentId = $this->webSocketId($connectionId);
        $configuration = json_decode(IPS\InstanceManager::getInstanceInterface($connectionId)->GetConfigurationForParent(), true);
        IPS_SetProperty($parentId, 'URL', $configuration['URL']);
        IPS_SetProperty($parentId, 'Active', true);
        IPS_ApplyChanges($parentId);
        IPS_ApplyChanges($connectionId);

        return $connectionId;
    }

    protected function createPlayer(int $connectionId, string $playerId): int
    {
        $instanceId = IPS_CreateInstance(self::PLAYER_MODULE_ID);
        IPS_SetProperty($instanceId, 'PlayerID', $playerId);
        IPS_ConnectInstance($instanceId, $connectionId);
        IPS_ApplyChanges($instanceId);

        return $instanceId;
    }

    // Die Stubs versenden keine Statusnachrichten; der Kernel meldet IM_CHANGESTATUS der Connection an die Player.
    protected function notifyConnectionStatus(int $connectionId, int $playerInstanceId): void
    {
        IPS\InstanceManager::getInstanceInterface($playerInstanceId)->MessageSink(
            0,
            $connectionId,
            IM_CHANGESTATUS,
            [IPS_GetInstance($connectionId)['InstanceStatus'], 0]
        );
    }

    protected function webSocketId(int $connectionId): int
    {
        return IPS_GetInstance($connectionId)['ConnectionID'];
    }

    protected function webSocket(int $connectionId): WebSocketClient
    {
        return IPS\InstanceManager::getInstanceInterface($this->webSocketId($connectionId));
    }

    protected function connection(int $connectionId): MusicAssistantConnection
    {
        return IPS\InstanceManager::getInstanceInterface($connectionId);
    }

    protected function receiveFromServer(int $connectionId, array $message): void
    {
        $this->webSocket($connectionId)->ReceiveText(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected function sendGreeting(int $connectionId): void
    {
        $this->receiveFromServer($connectionId, [
            'server_id'                    => 'test-server',
            'server_version'               => '2.10.8',
            'schema_version'               => 28,
            'min_supported_schema_version' => 28
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sentMessages(int $connectionId): array
    {
        return array_map(
            static function (string $message): array {
                return json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            },
            $this->webSocket($connectionId)->GetSentMessages()
        );
    }

    protected function lastSentMessage(int $connectionId, string $command): array
    {
        $matches = array_values(array_filter(
            $this->sentMessages($connectionId),
            static function (array $message) use ($command): bool {
                return ($message['command'] ?? null) === $command;
            }
        ));
        $this->assertNotEmpty($matches, 'No message sent for command ' . $command);

        return $matches[count($matches) - 1];
    }

    protected function authenticate(int $connectionId): void
    {
        $this->sendGreeting($connectionId);
        $auth = $this->lastSentMessage($connectionId, 'auth');
        $this->receiveFromServer($connectionId, [
            'message_id' => $auth['message_id'],
            'result'     => ['authenticated' => true]
        ]);
        $this->assertSame(102, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    protected function forwardToConnection(int $connectionId, array $request): array
    {
        $response = $this->connection($connectionId)->ForwardData(json_encode(
            ['DataID' => self::CONNECTION_REQUEST] + $request,
            JSON_THROW_ON_ERROR
        ));

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}
