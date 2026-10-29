<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantHttpTransportTest extends MusicAssistantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ersetzt für diese Tests die HTTP-Simulation auf maCall()-Ebene durch eine auf Transportebene.
        $library = json_decode((string)file_get_contents(__DIR__ . '/../library.json'), true);
        IPS\ModuleLoader::loadSingleModule(__DIR__ . '/fixtures/MusicAssistantConnectionTransportStub', $library['id']);
        MusicAssistantConnectionTransportStub::$response = null;
        MusicAssistantConnectionTransportStub::$requests = [];
    }

    public function testRequestContainsCommandArgsAndBearerToken(): void
    {
        $connectionId = $this->createConnection();
        $this->simulateTransport(200, '{"result":null}');

        $this->apiRequest($connectionId, 'players/cmd/volume_set', ['player_id' => 'player-1', 'volume_level' => 40]);

        $request = $this->lastRequest();
        $this->assertSame('http://127.0.0.1:8095/api', $request['url']);
        $this->assertContains('Authorization: Bearer ' . self::TOKEN, $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame(20000, $request['timeoutMs']);
        $body = json_decode($request['body'], true);
        $this->assertSame('players/cmd/volume_set', $body['command']);
        $this->assertSame(['player_id' => 'player-1', 'volume_level' => 40], $body['args']);
        $this->assertIsString($body['message_id']);
    }

    public function testEmptyArgsAreSentAsJsonObject(): void
    {
        $connectionId = $this->createConnection();
        $this->simulateTransport(200, '{"result":[]}');

        $this->apiRequest($connectionId, 'players/all', []);

        $this->assertStringContainsString('"args":{}', $this->lastRequest()['body']);
    }

    public function testMessageIdsAreUnique(): void
    {
        $connectionId = $this->createConnection();
        $this->simulateTransport(200, '{"result":null}');

        $this->apiRequest($connectionId, 'players/cmd/play', ['player_id' => 'player-1']);
        $this->apiRequest($connectionId, 'players/cmd/play', ['player_id' => 'player-1']);

        $ids = array_map(static function (array $request): string {
            return json_decode($request['body'], true)['message_id'];
        }, MusicAssistantConnectionTransportStub::$requests);
        $this->assertCount(2, array_unique($ids));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function apiHostProvider(): array
    {
        return [
            'IPv4'           => ['192.168.29.15', 'http://192.168.29.15:8095/api'],
            'DNS name'       => ['music.local', 'http://music.local:8095/api'],
            'IPv6'           => ['fd00::15', 'http://[fd00::15]:8095/api'],
            'bracketed IPv6' => ['[fd00::15]', 'http://[fd00::15]:8095/api']
        ];
    }

    /**
     * @dataProvider apiHostProvider
     */
    public function testApiUrlFormatsHost(string $host, string $expectedUrl): void
    {
        $connectionId = $this->createConnection($host);
        $this->simulateTransport(200, '{"result":null}');

        $this->apiRequest($connectionId, 'players/cmd/play', ['player_id' => 'player-1']);

        $this->assertSame($expectedUrl, $this->lastRequest()['url']);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: mixed}>
     */
    public static function successfulResponseProvider(): array
    {
        return [
            'result object'        => [200, '{"result":{"player_id":"player-1","name":"Küche 🎵"}}', ['player_id' => 'player-1', 'name' => 'Küche 🎵']],
            'result list'          => [200, '{"result":[{"player_id":"a"},{"player_id":"b"}]}', [['player_id' => 'a'], ['player_id' => 'b']]],
            'explicit null result' => [200, '{"result":null}', null],
            'body null'            => [200, 'null', null],
            'empty body 200'       => [200, '', null],
            'empty body 204'       => [204, '', null],
            'object without result' => [200, '{"player_id":"player-1"}', ['player_id' => 'player-1']]
        ];
    }

    /**
     * @dataProvider successfulResponseProvider
     */
    public function testSuccessfulResponseIsReturned(int $httpCode, string $body, $expectedResult): void
    {
        $connectionId = $this->createConnection();
        $this->simulateTransport($httpCode, $body);

        $response = $this->apiRequest($connectionId, 'players/all', []);

        $this->assertTrue($response['success']);
        $this->assertSame($expectedResult, $response['result']);
        $this->assertSame($httpCode, $response['http_code']);
    }

    /**
     * @return array<string, array{0: int, 1: string|false}>
     */
    public static function failedResponseProvider(): array
    {
        return [
            'error in body'        => [200, '{"error":"Player not found","error_code":999}'],
            'HTTP 401'             => [401, '{"error":"Unauthorized"}'],
            'HTTP 500'             => [500, 'Internal Server Error'],
            'empty body 500'       => [500, ''],
            'HTTP 502 valid JSON'  => [502, '{"result":{"player_id":"player-1"}}'],
            'invalid JSON'         => [200, '{invalid'],
            'scalar JSON'          => [200, '42'],
            'transport failure'    => [0, false]
        ];
    }

    /**
     * @dataProvider failedResponseProvider
     */
    public function testFailedResponseIsReportedAsApiError(int $httpCode, string|false $body): void
    {
        $connectionId = $this->createConnection();
        $this->simulateTransport($httpCode, $body);

        $response = $this->apiRequest($connectionId, 'players/all', []);

        $this->assertFalse($response['success']);
        $this->assertSame('API_ERROR', $response['error']['code']);
    }

    public function testTokenAndResponseBodyAreNotWrittenToDebug(): void
    {
        $connectionId = $this->createConnection();
        $this->simulateTransport(500, '{"error":"secret-server-detail"}');

        $this->apiRequest($connectionId, 'players/cmd/play', ['player_id' => 'player-1']);

        $debug = json_encode(IPS\DebugServer::getDebugMessages($connectionId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('players/cmd/play', $debug);
        $this->assertStringNotContainsString(self::TOKEN, $debug);
        $this->assertStringNotContainsString('secret-server-detail', $debug);
    }

    private function simulateTransport(int $httpCode, string|false $body): void
    {
        MusicAssistantConnectionTransportStub::$response = [
            'body'      => $body,
            'http_code' => $httpCode,
            'error'     => $body === false ? 'Connection refused' : ''
        ];
    }

    private function apiRequest(int $connectionId, string $command, array $params): array
    {
        return $this->forwardToConnection($connectionId, [
            'Command'    => 'ApiRequest',
            'ApiCommand' => $command,
            'Params'     => (object)$params
        ]);
    }

    private function lastRequest(): array
    {
        $this->assertNotEmpty(MusicAssistantConnectionTransportStub::$requests, 'No HTTP request was sent');

        return MusicAssistantConnectionTransportStub::$requests[count(MusicAssistantConnectionTransportStub::$requests) - 1];
    }
}
