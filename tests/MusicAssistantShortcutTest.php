<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantShortcutTest extends MusicAssistantTestCase
{
    private const SHORTCUT_MODULE_ID = '{B930F5DE-D03E-05BE-FB31-E2A9733BBC5D}';
    private const RADIO_PROFILE = 'MA.Radios.test';
    private const PLAYLIST_PROFILE = 'MA.Playlists.test';

    protected function setUp(): void
    {
        parent::setUp();
        IPS_CreateVariableProfile(self::RADIO_PROFILE, VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation(self::RADIO_PROFILE, 12, 'SWR3', '', -1);
        IPS_SetVariableProfileAssociation(self::RADIO_PROFILE, 15, 'Radio <Bob>', '', -1);
        IPS_CreateVariableProfile(self::PLAYLIST_PROFILE, VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation(self::PLAYLIST_PROFILE, 7, 'Favoriten', '', -1);
    }

    // Konfiguration

    public function testShortcutWithoutPlayerIsInactive(): void
    {
        $shortcutId = $this->createShortcut($this->createConnection(), 0, []);

        $this->assertSame(104, IPS_GetInstance($shortcutId)['InstanceStatus']);
    }

    public function testShortcutWithPlayerOfSameConnectionIsActive(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), []);

        $this->assertSame(102, IPS_GetInstance($shortcutId)['InstanceStatus']);
    }

    public function testShortcutRejectsPlayerOfOtherConnection(): void
    {
        $playerInstanceId = $this->createProfiledPlayer($this->createConnection());
        $shortcutId = $this->createShortcut($this->createConnection(), $playerInstanceId, []);

        $this->assertSame(202, IPS_GetInstance($shortcutId)['InstanceStatus']);
    }

    public function testShortcutRejectsInstanceThatIsNoPlayer(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $connectionId, []);

        $this->assertSame(201, IPS_GetInstance($shortcutId)['InstanceStatus']);
    }

    public function testFormOffersRadiosAndPlaylistsOfPlayer(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), []);

        $form = json_decode(IPS\InstanceManager::getInstanceInterface($shortcutId)->GetConfigurationForm(), true);
        $options = $form['elements'][2]['columns'][0]['edit']['options'];

        $this->assertSame(
            ['', 'radio:12', 'radio:15', 'playlist:7'],
            array_column($options, 'value')
        );
        $this->assertSame('Radio: SWR3', $options[1]['caption']);
        $this->assertSame('Playlist: Favoriten', $options[3]['caption']);
    }

    public function testReceiveDataFilterOnlyPassesProfileUpdates(): void
    {
        $shortcutId = $this->createShortcut($this->createConnection(), 0, []);
        $filter = IPS\InstanceManager::getInstanceInterface($shortcutId)->GetReceiveDataFilter();
        $dataId = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';

        $this->assertSame(1, preg_match('/' . $filter . '/', json_encode(['DataID' => $dataId, 'Event' => 'profiles_updated'])));
        $this->assertSame(0, preg_match('/' . $filter . '/', json_encode([
            'DataID' => $dataId, 'Event' => 'player_updated', 'ObjectID' => 'kitchen', 'Data' => ['volume_level' => 20]
        ])));
    }

    // Abspielen

    public function testTappingRadioEntryStartsStationOnPlayer(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createProfiledPlayer($connectionId);
        $shortcutId = $this->createShortcut($connectionId, $playerInstanceId, [['item' => 'radio:12', 'label' => '']]);
        $this->simulateHttpResult('player_queues/get_active_queue', ['queue_id' => 'queue-1']);
        $this->simulateHttpResult('player_queues/play_media', null);

        IPS_RequestAction($shortcutId, 'Play', 0);

        $this->assertSame(
            ['queue_id' => 'queue-1', 'media' => 'library://radio/12', 'option' => 'replace'],
            $this->lastCall('player_queues/play_media')['args']
        );
        $this->assertSame(12, GetValue(IPS_GetObjectIDByIdent('Radio', $playerInstanceId)));
    }

    public function testTappingPlaylistEntryStartsPlaylistOnPlayer(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createProfiledPlayer($connectionId);
        $shortcutId = $this->createShortcut($connectionId, $playerInstanceId, [
            ['item' => 'radio:12', 'label' => ''],
            ['item' => 'playlist:7', 'label' => 'Lieblingslieder']
        ]);
        $this->simulateHttpResult('player_queues/get_active_queue', ['queue_id' => 'queue-1']);
        $this->simulateHttpResult('player_queues/play_media', null);

        IPS_RequestAction($shortcutId, 'Play', 1);

        $this->assertSame('library://playlist/7', $this->lastCall('player_queues/play_media')['args']['media']);
        $this->assertSame(7, GetValue(IPS_GetObjectIDByIdent('Playlist', $playerInstanceId)));
    }

    public function testEntryRemovedFromProfileIsNotStarted(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:99', 'label' => '']]);

        IPS_RequestAction($shortcutId, 'Play', 0);

        $this->assertSame([], $this->httpCalls());
    }

    public function testPlayerOfOtherConnectionIsNotControlled(): void
    {
        $playerInstanceId = $this->createProfiledPlayer($this->createConnection());
        $shortcutId = $this->createShortcut($this->createConnection(), $playerInstanceId, [['item' => 'radio:12', 'label' => '']]);

        IPS_RequestAction($shortcutId, 'Play', 0);

        $this->assertSame([], $this->httpCalls());
    }

    public function testUnknownEntryIndexIsIgnored(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '']]);

        IPS_RequestAction($shortcutId, 'Play', 5);

        $this->assertSame([], $this->httpCalls());
    }

    // Kachel

    public function testTileShowsEntriesWithLabels(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [
            ['item' => 'radio:15', 'label' => ''],
            ['item' => 'playlist:7', 'label' => 'Lieblingslieder'],
            ['item' => 'radio:99', 'label' => ''],
            ['item' => 'invalid', 'label' => 'ignoriert']
        ]);

        $state = $this->tileState($shortcutId);

        $this->assertSame([
            ['index' => 0, 'type' => 'radio', 'label' => 'Radio <Bob>', 'available' => true],
            ['index' => 1, 'type' => 'playlist', 'label' => 'Lieblingslieder', 'available' => true],
            ['index' => 2, 'type' => 'radio', 'label' => '', 'available' => false]
        ], $state['entries']);
    }

    public function testTileEscapesMarkupInInitialState(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:15', 'label' => '']]);

        $tile = IPS\InstanceManager::getInstanceInterface($shortcutId)->GetVisualizationTile();

        $this->assertStringNotContainsString('Radio <Bob>', $tile);
        $this->assertStringNotContainsString('__MUSIC_ASSISTANT_INITIAL_STATE__', $tile);
    }

    private function createProfiledPlayer(int $connectionId): int
    {
        $instanceId = IPS_CreateInstance(self::PLAYER_MODULE_ID);
        IPS_SetProperty($instanceId, 'PlayerID', 'player-1');
        IPS_SetProperty($instanceId, 'RadioProfile', self::RADIO_PROFILE);
        IPS_SetProperty($instanceId, 'RadioMap', json_encode(['12' => 'library://radio/12', '15' => 'library://radio/15']));
        IPS_SetProperty($instanceId, 'PlaylistProfile', self::PLAYLIST_PROFILE);
        IPS_SetProperty($instanceId, 'PlaylistMap', json_encode(['7' => ['uri' => 'library://playlist/7', 'provider_mappings' => []]]));
        IPS_ConnectInstance($instanceId, $connectionId);
        IPS_ApplyChanges($instanceId);

        return $instanceId;
    }

    private function createShortcut(int $connectionId, int $playerInstanceId, array $entries): int
    {
        $shortcutId = IPS_CreateInstance(self::SHORTCUT_MODULE_ID);
        IPS_SetProperty($shortcutId, 'PlayerInstanceID', $playerInstanceId);
        IPS_SetProperty($shortcutId, 'Entries', json_encode($entries));
        IPS_ConnectInstance($shortcutId, $connectionId);
        IPS_ApplyChanges($shortcutId);
        $this->clearHttpCalls();

        return $shortcutId;
    }

    private function tileState(int $shortcutId): array
    {
        $tile = IPS\InstanceManager::getInstanceInterface($shortcutId)->GetVisualizationTile();
        $this->assertSame(1, preg_match('/renderState\((\{.*\})\);/', $tile, $match));

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function lastCall(string $command): array
    {
        $calls = array_values(array_filter($this->httpCalls(), static function (array $call) use ($command): bool {
            return $call['command'] === $command;
        }));
        $this->assertNotEmpty($calls, 'Kein Aufruf von ' . $command);

        return $calls[count($calls) - 1];
    }
}
