<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantPlayerControlTest extends MusicAssistantTestCase
{
    private const PLAYER_ID = 'player-1';

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function transportProvider(): array
    {
        return [
            'previous' => [0, 'players/cmd/previous'],
            'stop'     => [1, 'players/cmd/stop'],
            'play'     => [2, 'players/cmd/play'],
            'pause'    => [3, 'players/cmd/pause'],
            'next'     => [4, 'players/cmd/next']
        ];
    }

    /**
     * @dataProvider transportProvider
     */
    public function testTransportSendsCommandAndSetsVariable(int $value, string $command): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpResult($command, null);

        IPS_RequestAction($playerInstanceId, 'Transport', $value);

        $this->assertSame([['command' => $command, 'args' => ['player_id' => self::PLAYER_ID]]], $this->httpCalls());
        $this->assertSame($value, $this->value($playerInstanceId, 'Transport'));
    }

    public function testFailedTransportKeepsVariable(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpFailure('players/cmd/play');

        IPS_RequestAction($playerInstanceId, 'Transport', 2);

        $this->assertSame(0, $this->value($playerInstanceId, 'Transport'));
    }

    public function testVolumeIsClampedAndWaitsForEvent(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpResult('players/cmd/volume_set', null);

        IPS_RequestAction($playerInstanceId, 'VolumeLevel', 150);

        $this->assertSame(
            [['command' => 'players/cmd/volume_set', 'args' => ['player_id' => self::PLAYER_ID, 'volume_level' => 100]]],
            $this->httpCalls()
        );
        // Die Lautstärke wird erst durch das folgende player_updated-Ereignis übernommen.
        $this->assertSame(0, $this->value($playerInstanceId, 'VolumeLevel'));
    }

    public function testMuteSendsCommandAndSetsVariable(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpResult('players/cmd/volume_mute', null);

        IPS_RequestAction($playerInstanceId, 'Mute', true);

        $this->assertSame(
            [['command' => 'players/cmd/volume_mute', 'args' => ['player_id' => self::PLAYER_ID, 'muted' => true]]],
            $this->httpCalls()
        );
        $this->assertTrue($this->value($playerInstanceId, 'Mute'));
    }

    public function testMuteFallsBackToGroupMuteForKnownGroup(): void
    {
        $playerInstanceId = $this->createConnectedPlayerOfType('living', 'group');
        $this->simulateHttpApiError('players/cmd/volume_mute');
        $this->simulateHttpResult('players/cmd/group_volume_mute', null);

        IPS_RequestAction($playerInstanceId, 'Mute', true);

        $this->assertSame(
            ['players/cmd/volume_mute', 'players/cmd/group_volume_mute'],
            array_column($this->httpCalls(), 'command')
        );
        $this->assertSame(['player_id' => 'living', 'muted' => true], $this->httpCalls()[1]['args']);
        $this->assertTrue($this->value($playerInstanceId, 'Mute'));
    }

    public function testSyncGroupPrefixAloneDoesNotUseGroupMute(): void
    {
        $playerInstanceId = $this->createConnectedPlayerOfType('syncgroup_living', 'player');
        $this->simulateHttpApiError('players/cmd/volume_mute');
        $this->simulateHttpResult('players/cmd/group_volume_mute', null);

        IPS_RequestAction($playerInstanceId, 'Mute', true);

        $this->assertSame(['players/cmd/volume_mute'], array_column($this->httpCalls(), 'command'));
        $this->assertFalse($this->value($playerInstanceId, 'Mute'));
    }

    public function testFailedMuteOfNormalPlayerDoesNotUseGroupMute(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpApiError('players/cmd/volume_mute');
        $this->simulateHttpResult('players/cmd/group_volume_mute', null);

        IPS_RequestAction($playerInstanceId, 'Mute', true);

        $this->assertSame(['players/cmd/volume_mute'], array_column($this->httpCalls(), 'command'));
        $this->assertFalse($this->value($playerInstanceId, 'Mute'));
    }

    public function testShuffleSendsCommandAndSetsVariable(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpResult('players/cmd/shuffle', null);

        IPS_RequestAction($playerInstanceId, 'Shuffle', true);

        $this->assertSame(
            [['command' => 'players/cmd/shuffle', 'args' => ['player_id' => self::PLAYER_ID, 'shuffle_enabled' => true]]],
            $this->httpCalls()
        );
        $this->assertTrue($this->value($playerInstanceId, 'Shuffle'));
    }

    public function testFailedShuffleKeepsVariable(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpApiError('players/cmd/shuffle');

        IPS_RequestAction($playerInstanceId, 'Shuffle', true);

        $this->assertFalse($this->value($playerInstanceId, 'Shuffle'));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function repeatProvider(): array
    {
        return [
            'off' => [0, 'off'],
            'all' => [1, 'all'],
            'one' => [2, 'one']
        ];
    }

    /**
     * @dataProvider repeatProvider
     */
    public function testRepeatSendsModeAndSetsVariable(int $value, string $mode): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpResult('players/cmd/repeat', null);

        IPS_RequestAction($playerInstanceId, 'Repeat', $value);

        $this->assertSame(
            [['command' => 'players/cmd/repeat', 'args' => ['player_id' => self::PLAYER_ID, 'repeat_mode' => $mode]]],
            $this->httpCalls()
        );
        $this->assertSame($value, $this->value($playerInstanceId, 'Repeat'));
    }

    public function testInvalidRepeatValueSendsNoCommand(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpResult('players/cmd/repeat', null);

        IPS_RequestAction($playerInstanceId, 'Repeat', 5);

        $this->assertSame([], $this->httpCalls());
        $this->assertSame(0, $this->value($playerInstanceId, 'Repeat'));
    }

    public function testGroupVolumeButtonsSendCommandAndReset(): void
    {
        $playerInstanceId = $this->createConnectedPlayer();
        $this->simulateHttpResult('players/cmd/group_volume_up', null);
        $this->simulateHttpResult('players/cmd/group_volume_down', null);

        IPS_RequestAction($playerInstanceId, 'VolumeUp', 1);
        IPS_RequestAction($playerInstanceId, 'VolumeDown', 1);

        $this->assertSame(
            [
                ['command' => 'players/cmd/group_volume_up', 'args' => ['player_id' => self::PLAYER_ID]],
                ['command' => 'players/cmd/group_volume_down', 'args' => ['player_id' => self::PLAYER_ID]]
            ],
            $this->httpCalls()
        );
        $this->assertSame(0, $this->value($playerInstanceId, 'VolumeUp'));
        $this->assertSame(0, $this->value($playerInstanceId, 'VolumeDown'));
    }

    public function testApiRequestReturnsResultFromMusicAssistant(): void
    {
        $connectionId = $this->createConnection();
        $players = [['player_id' => self::PLAYER_ID, 'name' => 'Küche']];
        $this->simulateHttpResult('players/all', $players);

        $response = $this->forwardToConnection($connectionId, [
            'Command'    => 'ApiRequest',
            'ApiCommand' => 'players/all',
            'Params'     => new stdClass()
        ]);

        $this->assertTrue($response['success']);
        $this->assertSame($players, $response['result']);
    }

    public function testApiErrorAndHttpFailureAreReportedWithoutDetails(): void
    {
        $connectionId = $this->createConnection();
        $this->simulateHttpApiError('players/cmd/play');
        $this->simulateHttpFailure('players/cmd/stop');

        foreach (['players/cmd/play', 'players/cmd/stop'] as $command) {
            $response = $this->forwardToConnection($connectionId, [
                'Command'    => 'ApiRequest',
                'ApiCommand' => $command,
                'Params'     => ['player_id' => self::PLAYER_ID]
            ]);

            $this->assertFalse($response['success']);
            $this->assertSame('API_ERROR', $response['error']['code']);
            $this->assertStringNotContainsString('Player not found', $response['error']['message']);
            $this->assertStringNotContainsString('timeout', $response['error']['message']);
        }
    }

    private function createConnectedPlayer(string $playerId = self::PLAYER_ID): int
    {
        $playerInstanceId = $this->createPlayer($this->createConnection(), $playerId);
        $this->clearHttpCalls();

        return $playerInstanceId;
    }

    private function createConnectedPlayerOfType(string $playerId, string $type): int
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, $playerId);
        $this->authenticate($connectionId);
        $this->notifyConnectionStatus($connectionId, $playerInstanceId);
        $this->receiveFromServer($connectionId, [
            'event'     => 'player_updated',
            'object_id' => $playerId,
            'data'      => ['type' => $type]
        ]);
        $this->clearHttpCalls();

        return $playerInstanceId;
    }

    private function value(int $instanceId, string $ident)
    {
        return GetValue(IPS_GetObjectIDByIdent($ident, $instanceId));
    }
}
