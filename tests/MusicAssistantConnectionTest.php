<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantConnectionTest extends MusicAssistantTestCase
{
    public function testGreetingSendsAuthenticationWithToken(): void
    {
        $connectionId = $this->createConnection();

        $this->sendGreeting($connectionId);

        $auth = $this->lastSentMessage($connectionId, 'auth');
        $this->assertSame(['token' => self::TOKEN], $auth['args']);
        $this->assertStringStartsWith('auth-', $auth['message_id']);
        $this->assertSame(104, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testKernelStartReevaluatesPendingClientConfiguration(): void
    {
        $connectionId = $this->createConnection();
        $this->assertSame(104, IPS_GetInstance($connectionId)['InstanceStatus']);
        // Simuliert einen Client, dessen Adresse beim Start nicht mehr zur Connection passt.
        IPS_SetProperty($this->webSocketId($connectionId), 'URL', 'ws://192.0.2.1:8095/ws');
        IPS_ApplyChanges($this->webSocketId($connectionId));

        $this->connection($connectionId)->MessageSink(time(), 0, IPS_KERNELSTARTED, []);

        $this->assertSame(204, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testKernelStartKeepsAuthenticatedSession(): void
    {
        $connectionId = $this->createConnection();
        $this->authenticate($connectionId);
        IPS_SetProperty($this->webSocketId($connectionId), 'URL', 'ws://192.0.2.1:8095/ws');
        IPS_ApplyChanges($this->webSocketId($connectionId));

        $this->connection($connectionId)->MessageSink(time(), 0, IPS_KERNELSTARTED, []);

        $this->assertSame(102, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testConfirmedAuthenticationActivatesConnection(): void
    {
        $connectionId = $this->createConnection();

        $this->authenticate($connectionId);

        $this->assertSame(102, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testRejectedAuthenticationSetsErrorStatus(): void
    {
        $connectionId = $this->createConnection();
        $this->sendGreeting($connectionId);
        $auth = $this->lastSentMessage($connectionId, 'auth');

        $this->receiveFromServer($connectionId, [
            'message_id' => $auth['message_id'],
            'error_code' => 20,
            'details'    => 'Invalid token'
        ]);

        $this->assertSame(202, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testResponseWithForeignMessageIdDoesNotAuthenticate(): void
    {
        $connectionId = $this->createConnection();
        $this->sendGreeting($connectionId);

        $this->receiveFromServer($connectionId, [
            'message_id' => 'auth-foreign',
            'result'     => ['authenticated' => true]
        ]);

        $this->assertSame(104, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testAuthenticationResponseFromPreviousSessionIsIgnored(): void
    {
        $connectionId = $this->createConnection();
        $this->sendGreeting($connectionId);
        $previousAuth = $this->lastSentMessage($connectionId, 'auth');
        $this->sendGreeting($connectionId);
        $currentAuth = $this->lastSentMessage($connectionId, 'auth');
        $this->assertNotSame($previousAuth['message_id'], $currentAuth['message_id']);

        $this->receiveFromServer($connectionId, [
            'message_id' => $previousAuth['message_id'],
            'result'     => ['authenticated' => true]
        ]);
        $this->assertSame(104, IPS_GetInstance($connectionId)['InstanceStatus']);

        $this->receiveFromServer($connectionId, [
            'message_id' => $currentAuth['message_id'],
            'result'     => ['authenticated' => true]
        ]);
        $this->assertSame(102, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testInvalidJsonFromServerIsIgnored(): void
    {
        $connectionId = $this->createConnection();
        $this->sendGreeting($connectionId);

        $this->webSocket($connectionId)->ReceiveText('{invalid');

        $this->assertSame(104, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    public function testApiRequestWithCommandOutsideAllowListIsRejected(): void
    {
        $connectionId = $this->createConnection();

        $response = $this->forwardToConnection($connectionId, [
            'Command'    => 'ApiRequest',
            'ApiCommand' => 'config/providers/remove',
            'Params'     => ['instance_id' => 'x']
        ]);

        $this->assertFalse($response['success']);
        $this->assertSame('COMMAND_NOT_ALLOWED', $response['error']['code']);
    }

    public function testApiRequestWithListParamsIsRejected(): void
    {
        $connectionId = $this->createConnection();

        $response = $this->forwardToConnection($connectionId, [
            'Command'    => 'ApiRequest',
            'ApiCommand' => 'players/cmd/play',
            'Params'     => ['player-1']
        ]);

        $this->assertFalse($response['success']);
        $this->assertSame('INVALID_REQUEST', $response['error']['code']);
    }

    public function testApiRequestWithUnexpectedFieldIsRejected(): void
    {
        $connectionId = $this->createConnection();

        $response = $this->forwardToConnection($connectionId, [
            'Command'    => 'ApiRequest',
            'ApiCommand' => 'players/cmd/play',
            'Params'     => ['player_id' => 'player-1'],
            'Url'        => 'http://example.invalid/'
        ]);

        $this->assertFalse($response['success']);
        $this->assertSame('INVALID_REQUEST', $response['error']['code']);
    }

    public function testApiRequestWithoutTokenIsRejected(): void
    {
        $connectionId = $this->createConnection();
        IPS_SetProperty($connectionId, 'Token', '');
        IPS_ApplyChanges($connectionId);

        $response = $this->forwardToConnection($connectionId, [
            'Command'    => 'ApiRequest',
            'ApiCommand' => 'players/cmd/play',
            'Params'     => ['player_id' => 'player-1']
        ]);

        $this->assertFalse($response['success']);
        $this->assertSame('NOT_CONFIGURED', $response['error']['code']);
        $this->assertSame(201, IPS_GetInstance($connectionId)['InstanceStatus']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function webSocketUrlProvider(): array
    {
        return [
            'IPv4'             => ['192.168.29.15', 'ws://192.168.29.15:8095/ws'],
            'DNS name'         => ['music.local', 'ws://music.local:8095/ws'],
            'IPv6'             => ['fd00::15', 'ws://[fd00::15]:8095/ws'],
            'bracketed IPv6'   => ['[fd00::15]', 'ws://[fd00::15]:8095/ws']
        ];
    }

    /**
     * @dataProvider webSocketUrlProvider
     */
    public function testWebSocketUrlFormatsHost(string $host, string $expectedUrl): void
    {
        $connectionId = $this->createConnection($host);

        $configuration = json_decode($this->connection($connectionId)->GetConfigurationForParent(), true);

        $this->assertSame($expectedUrl, $configuration['URL']);
    }
}
