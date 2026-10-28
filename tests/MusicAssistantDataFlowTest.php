<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantDataFlowTest extends MusicAssistantTestCase
{
    private const PLAYER_ID = 'player-1';
    private const QUEUE_ID = 'queue-1';

    public function testRegisteredPlayerIsResynchronizedAfterAuthentication(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);

        $this->authenticateAndRegister($connectionId, $playerInstanceId);

        $this->assertSame(['player_id' => self::PLAYER_ID], $this->lastSentMessage($connectionId, 'players/get')['args']);
        $this->assertSame(
            ['player_id' => self::PLAYER_ID],
            $this->lastSentMessage($connectionId, 'player_queues/get_active_queue')['args']
        );
    }

    public function testPlayerUpdatedSetsVolumeAndMute(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        $this->authenticateAndRegister($connectionId, $playerInstanceId);

        $this->sendPlayerUpdated($connectionId, self::PLAYER_ID, ['volume_level' => 37, 'volume_muted' => true]);

        $this->assertSame(37, GetValue($this->variableId($playerInstanceId, 'VolumeLevel')));
        $this->assertTrue(GetValue($this->variableId($playerInstanceId, 'Mute')));
    }

    public function testPlayerUpdatedForOtherPlayerIsIgnored(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        $this->authenticateAndRegister($connectionId, $playerInstanceId);

        $this->sendPlayerUpdated($connectionId, 'player-2', ['volume_level' => 37, 'volume_muted' => true]);

        $this->assertSame(0, GetValue($this->variableId($playerInstanceId, 'VolumeLevel')));
        $this->assertFalse(GetValue($this->variableId($playerInstanceId, 'Mute')));
    }

    public function testPlayerUpdatedBeforeAuthenticationIsIgnored(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        $this->sendGreeting($connectionId);

        $this->sendPlayerUpdated($connectionId, self::PLAYER_ID, ['volume_level' => 37]);

        $this->assertSame(0, GetValue($this->variableId($playerInstanceId, 'VolumeLevel')));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function metadataProvider(): array
    {
        return [
            'ASCII'          => ['Paranoid', 'Black Sabbath', 'Paranoid'],
            'Umlauts'        => ['Für Elise', 'Ludwig van Beethoven', 'Klavierstücke – Größte Werke'],
            'Special chars'  => ['Rock & Roll "Live" <Edit>', 'AC/DC', 'If You Want Blood \'78'],
            'Unicode'        => ['Ágætis byrjun ♫', 'Sigur Rós', '東京 🎵 Ñoño']
        ];
    }

    /**
     * @dataProvider metadataProvider
     */
    public function testQueueMetadataKeepsEncoding(string $title, string $artist, string $album): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        $this->authenticateAndRegister($connectionId, $playerInstanceId);
        $queueRequest = $this->lastSentMessage($connectionId, 'player_queues/get_active_queue');

        $this->receiveFromServer($connectionId, [
            'message_id' => $queueRequest['message_id'],
            'result'     => $this->playerQueue([
                'state'        => 'playing',
                'current_item' => [
                    'queue_id'      => self::QUEUE_ID,
                    'queue_item_id' => 'item-1',
                    'name'          => $title,
                    'media_item'    => [
                        'name'    => $title,
                        'artists' => [['name' => $artist]],
                        'album'   => ['name' => $album]
                    ]
                ]
            ])
        ]);

        $this->assertSame($title, GetValue($this->variableId($playerInstanceId, 'NowTitle')));
        $this->assertSame($artist, GetValue($this->variableId($playerInstanceId, 'NowArtist')));
        $this->assertSame($album, GetValue($this->variableId($playerInstanceId, 'NowAlbum')));
        $this->assertSame(2, GetValue($this->variableId($playerInstanceId, 'Transport')));
    }

    public function testQueueUpdatedReachesPlayerAfterResync(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        $this->authenticateAndRegister($connectionId, $playerInstanceId);
        $queueRequest = $this->lastSentMessage($connectionId, 'player_queues/get_active_queue');
        $this->receiveFromServer($connectionId, [
            'message_id' => $queueRequest['message_id'],
            'result'     => $this->playerQueue(['state' => 'playing'])
        ]);

        $this->receiveFromServer($connectionId, [
            'event'     => 'queue_updated',
            'object_id' => self::QUEUE_ID,
            'data'      => ['state' => 'paused', 'shuffle_enabled' => true, 'repeat_mode' => 'all']
        ]);

        $this->assertSame(3, GetValue($this->variableId($playerInstanceId, 'Transport')));
        $this->assertTrue(GetValue($this->variableId($playerInstanceId, 'Shuffle')));
        $this->assertSame(1, GetValue($this->variableId($playerInstanceId, 'Repeat')));
    }

    public function testQueueResponseFromPreviousSessionIsIgnored(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, self::PLAYER_ID);
        $this->authenticateAndRegister($connectionId, $playerInstanceId);
        $staleRequest = $this->lastSentMessage($connectionId, 'player_queues/get_active_queue');

        $this->authenticateAndRegister($connectionId, $playerInstanceId);
        $this->receiveFromServer($connectionId, [
            'message_id' => $staleRequest['message_id'],
            'result'     => $this->playerQueue(['state' => 'playing'])
        ]);

        $this->assertSame(0, GetValue($this->variableId($playerInstanceId, 'Transport')));
    }

    private function authenticateAndRegister(int $connectionId, int $playerInstanceId): void
    {
        $this->authenticate($connectionId);
        $this->notifyConnectionStatus($connectionId, $playerInstanceId);
    }

    private function sendPlayerUpdated(int $connectionId, string $playerId, array $data): void
    {
        $this->receiveFromServer($connectionId, [
            'event'     => 'player_updated',
            'object_id' => $playerId,
            'data'      => $data
        ]);
    }

    private function playerQueue(array $data): array
    {
        return $data + [
            'queue_id'     => self::QUEUE_ID,
            'active'       => true,
            'display_name' => 'Wohnzimmer',
            'available'    => true,
            'items'        => 1
        ];
    }

    private function variableId(int $instanceId, string $ident): int
    {
        return IPS_GetObjectIDByIdent($ident, $instanceId);
    }
}
