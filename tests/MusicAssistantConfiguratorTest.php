<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

class MusicAssistantConfiguratorTest extends MusicAssistantTestCase
{
    private const CONFIGURATOR_MODULE_ID = '{7F10CABF-4C5E-D431-8B4E-2528CEF4AE89}';

    // Formular

    public function testFormListsPlayersFromMusicAssistant(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('players/all', [
            ['player_id' => 'kitchen', 'name' => 'Küche 🎵', 'provider' => 'sonos', 'available' => true],
            ['player_id' => 'syncgroup_eg', 'name' => 'Erdgeschoss', 'provider' => 'player_group', 'available' => false]
        ]);

        $form = $this->form($configuratorId);
        $rows = $this->playerRows($form);

        $this->assertFalse($form['elements'][0]['visible']);
        $this->assertCount(2, $rows);
        $this->assertSame('Küche 🎵', $rows[0]['name']);
        $this->assertSame('kitchen', $rows[0]['player_id']);
        $this->assertSame('Yes', $rows[0]['available']);
        $this->assertSame('Not created', $rows[0]['status']);
        $this->assertSame(0, $rows[0]['instanceID']);
        $this->assertSame('No', $rows[1]['available']);
        $suffix = substr(md5('connection:' . $connectionId), 0, 8);
        $this->assertSame([
            'moduleID'      => self::PLAYER_MODULE_ID,
            'configuration' => [
                'PlayerID'        => 'kitchen',
                'PlaylistProfile' => 'MA.Playlists.' . $suffix,
                'PlaylistMap'     => '{}',
                'RadioProfile'    => 'MA.Radios.' . $suffix,
                'RadioMap'        => '{}'
            ],
            'name'          => 'MA Player - Küche 🎵'
        ], $rows[0]['create']);
    }

    public function testExistingPlayerInstanceIsMarked(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, 'kitchen');
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('players/all', [
            ['player_id' => 'kitchen', 'name' => 'Küche', 'provider' => 'sonos', 'available' => true]
        ]);

        $rows = $this->playerRows($this->form($configuratorId));

        $this->assertCount(1, $rows);
        $this->assertSame($playerInstanceId, $rows[0]['instanceID']);
        $this->assertSame('Existing', $rows[0]['status']);
    }

    public function testPlayersAreListedWhenMusicAssistantIsUnreachable(): void
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, 'kitchen');
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpFailure('players/all');

        $rows = $this->playerRows($this->form($configuratorId));

        $this->assertCount(1, $rows);
        $this->assertSame($playerInstanceId, $rows[0]['instanceID']);
        $this->assertSame('Music Assistant not reachable', $rows[0]['status']);
        $this->assertArrayNotHasKey('create', $rows[0]);
    }

    public function testUnknownAndDuplicatePlayerInstancesAreListed(): void
    {
        $connectionId = $this->createConnection();
        $firstInstanceId = $this->createPlayer($connectionId, 'kitchen');
        $duplicateInstanceId = $this->createPlayer($connectionId, 'kitchen');
        $unknownInstanceId = $this->createPlayer($connectionId, 'removed-player');
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('players/all', [
            ['player_id' => 'kitchen', 'name' => 'Küche', 'provider' => 'sonos', 'available' => true]
        ]);

        $statusByInstance = array_column($this->playerRows($this->form($configuratorId)), 'status', 'instanceID');

        $this->assertSame([
            $firstInstanceId     => 'Existing',
            $duplicateInstanceId => 'Duplicate instance',
            $unknownInstanceId   => 'Not found in Music Assistant'
        ], $statusByInstance);
    }

    public function testPlayersOfOtherConnectionsAreNotListed(): void
    {
        $connectionId = $this->createConnection();
        $this->createPlayer($this->createConnection('192.168.29.16'), 'kitchen');
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('players/all', [
            ['player_id' => 'kitchen', 'name' => 'Küche', 'provider' => 'sonos', 'available' => true]
        ]);

        $rows = $this->playerRows($this->form($configuratorId));

        $this->assertCount(1, $rows);
        $this->assertSame('Not created', $rows[0]['status']);
    }

    public function testCorruptedProfileNamesDisableCreationButKeepList(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $this->writeAttribute($configuratorId, 'ProfileNames', '{broken');
        $this->simulateHttpResult('players/all', [
            ['player_id' => 'kitchen', 'name' => 'Küche', 'provider' => 'sonos', 'available' => true]
        ]);

        $form = $this->form($configuratorId);
        $rows = $this->playerRows($form);

        $this->assertTrue($form['elements'][0]['visible']);
        $this->assertStringContainsString('could not be initialized', $form['elements'][0]['caption']);
        $this->assertContains(
            ['Message' => 'The persistent attribute ProfileNames contains invalid JSON; initialization was aborted and the original data was kept.', 'Type' => KL_ERROR],
            IPS\LogServer::getLogMessages((string)$configuratorId)
        );
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('create', $rows[0]);
        $this->assertSame('{broken', $this->readAttribute($configuratorId, 'ProfileNames'));
    }

    public function testConfiguratorWithoutConnectionStillProvidesForm(): void
    {
        $configuratorId = IPS_CreateInstance(self::CONFIGURATOR_MODULE_ID);

        $form = $this->form($configuratorId);

        $this->assertSame([], $this->playerRows($form));
        $this->assertSame('', $this->readAttribute($configuratorId, 'ProfileNames'));
    }

    // Playlist- und Radioprofile

    public function testSyncPlaylistsCreatesProfileAndMap(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('music/playlists/library_items', [
            $this->playlist(5, 'Chill – Ünïcode 🎵'),
            $this->playlist(9, 'Rock')
        ]);

        MASS_SyncPlaylistsProfile($configuratorId);

        $profile = 'MA.Playlists.' . substr(md5('connection:' . $connectionId), 0, 8);
        $this->assertSame(['music/playlists/library_items', ['summary' => false]], $this->lastHttpCall());
        $this->assertSame(VARIABLETYPE_INTEGER, IPS_GetVariableProfile($profile)['ProfileType']);
        $this->assertSame('list-music', IPS_GetVariableProfile($profile)['Icon']);
        $this->assertSame([0 => '-', 5 => 'Chill – Ünïcode 🎵', 9 => 'Rock'], $this->associations($profile));
        $this->assertSame([
            '5' => ['uri' => 'library://playlist/5', 'provider_mappings' => [['item_id' => 'p5', 'provider_instance' => 'spotify--a']]],
            '9' => ['uri' => 'library://playlist/9', 'provider_mappings' => [['item_id' => 'p9', 'provider_instance' => 'spotify--a']]]
        ], json_decode($this->readAttribute($configuratorId, 'PlaylistMap'), true));
    }

    public function testSyncPlaylistsReplacesOutdatedAssociations(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('music/playlists/library_items', [$this->playlist(5, 'Alt'), $this->playlist(9, 'Rock')]);
        MASS_SyncPlaylistsProfile($configuratorId);

        $this->simulateHttpResult('music/playlists/library_items', [$this->playlist(9, 'Rock neu'), $this->playlist(12, 'Jazz')]);
        MASS_SyncPlaylistsProfile($configuratorId);

        $profile = 'MA.Playlists.' . substr(md5('connection:' . $connectionId), 0, 8);
        $this->assertSame([0 => '-', 9 => 'Rock neu', 12 => 'Jazz'], $this->associations($profile));
        $this->assertSame([9, 12], array_keys(json_decode($this->readAttribute($configuratorId, 'PlaylistMap'), true)));
    }

    public function testIncompletePlaylistEntryKeepsExistingData(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('music/playlists/library_items', [$this->playlist(5, 'Chill')]);
        MASS_SyncPlaylistsProfile($configuratorId);
        $mapBefore = $this->readAttribute($configuratorId, 'PlaylistMap');

        $incomplete = $this->playlist(9, 'Ohne URI');
        unset($incomplete['uri']);
        $this->simulateHttpResult('music/playlists/library_items', [$this->playlist(7, 'Neu'), $incomplete]);

        try {
            MASS_SyncPlaylistsProfile($configuratorId);
            $this->fail('Synchronization with an incomplete entry must fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('incomplete entry', $e->getMessage());
        }

        $profile = 'MA.Playlists.' . substr(md5('connection:' . $connectionId), 0, 8);
        $this->assertSame([0 => '-', 5 => 'Chill'], $this->associations($profile));
        $this->assertSame($mapBefore, $this->readAttribute($configuratorId, 'PlaylistMap'));
    }

    public function testExistingProfileWithWrongTypeIsNotChanged(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $profile = 'MA.Playlists.' . substr(md5('connection:' . $connectionId), 0, 8);
        IPS_CreateVariableProfile($profile, VARIABLETYPE_STRING);
        IPS_SetVariableProfileAssociation($profile, 'user', 'Benutzereintrag', '', -1);
        $this->simulateHttpResult('music/playlists/library_items', [$this->playlist(5, 'Chill')]);

        try {
            MASS_SyncPlaylistsProfile($configuratorId);
            $this->fail('Synchronization into a profile of the wrong type must fail');
        } catch (Exception $e) {
            $this->assertStringContainsString('does not have the expected type Integer', $e->getMessage());
        }

        $this->assertSame(VARIABLETYPE_STRING, IPS_GetVariableProfile($profile)['ProfileType']);
        $this->assertSame(['user' => 'Benutzereintrag'], $this->associations($profile));
        $this->assertSame('', $this->readAttribute($configuratorId, 'PlaylistMap'));
    }

    public function testSyncPlaylistsIsLimitedTo127Entries(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $items = [];
        for ($id = 1; $id <= 130; $id++) {
            $items[] = $this->playlist($id, 'Playlist ' . $id);
        }
        $this->simulateHttpResult('music/playlists/library_items', $items);

        MASS_SyncPlaylistsProfile($configuratorId);

        $profile = 'MA.Playlists.' . substr(md5('connection:' . $connectionId), 0, 8);
        $this->assertCount(128, $this->associations($profile));
        $this->assertCount(127, json_decode($this->readAttribute($configuratorId, 'PlaylistMap'), true));
    }

    public function testSyncRadiosCreatesProfileAndMap(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('music/radios/library_items', [
            ['item_id' => '3', 'name' => 'Bayern 3', 'uri' => 'library://radio/3', 'provider_mappings' => []],
            ['item_id' => '8', 'name' => 'Radio Österreich 1', 'uri' => 'library://radio/8', 'provider_mappings' => []]
        ]);

        MASS_SyncRadiosProfile($configuratorId);

        $profile = 'MA.Radios.' . substr(md5('connection:' . $connectionId), 0, 8);
        $this->assertSame(['music/radios/library_items', []], $this->lastHttpCall());
        $this->assertSame('radio', IPS_GetVariableProfile($profile)['Icon']);
        $this->assertSame([0 => '-', 3 => 'Bayern 3', 8 => 'Radio Österreich 1'], $this->associations($profile));
        $this->assertSame(
            ['3' => 'library://radio/3', '8' => 'library://radio/8'],
            json_decode($this->readAttribute($configuratorId, 'RadioMap'), true)
        );
    }

    public function testPlayerResetsSelectionRemovedByProfileUpdate(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('music/playlists/library_items', [$this->playlist(5, 'Chill'), $this->playlist(9, 'Rock')]);
        MASS_SyncPlaylistsProfile($configuratorId);
        $suffix = substr(md5('connection:' . $connectionId), 0, 8);
        $playerInstanceId = $this->createConfiguredPlayer(
            $connectionId,
            'kitchen',
            'MA.Playlists.' . $suffix,
            'MA.Radios.' . $suffix,
            $this->readAttribute($configuratorId, 'PlaylistMap')
        );
        $playlistVariableId = IPS_GetObjectIDByIdent('Playlist', $playerInstanceId);
        SetValue($playlistVariableId, 9);

        $this->simulateHttpResult('music/playlists/library_items', [$this->playlist(5, 'Chill')]);
        MASS_SyncPlaylistsProfile($configuratorId);

        $this->assertSame(0, GetValue($playlistVariableId));
    }

    // Player anlegen

    public function testCreatePlayerCreatesConnectedInstance(): void
    {
        $this->requireModuleCompatibilityCheck();
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);

        $message = MASS_CreatePlayer($configuratorId, 'kitchen', 'Küche', 0);

        $playerInstanceId = $this->findPlayerInstance('kitchen');
        $this->assertSame(sprintf('Player was created as instance %d.', $playerInstanceId), $message);
        $this->assertSame($connectionId, IPS_GetInstance($playerInstanceId)['ConnectionID']);
        $this->assertSame('MA Player - Küche', IPS_GetName($playerInstanceId));
        $this->assertSame(0, IPS_GetParent($playerInstanceId));
        $suffix = substr(md5('connection:' . $connectionId), 0, 8);
        $this->assertSame('MA.Playlists.' . $suffix, IPS_GetProperty($playerInstanceId, 'PlaylistProfile'));
        $this->assertSame('MA.Radios.' . $suffix, IPS_GetProperty($playerInstanceId, 'RadioProfile'));
    }

    public function testCreatePlayerTwiceDoesNotCreateDuplicate(): void
    {
        $this->requireModuleCompatibilityCheck();
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        MASS_CreatePlayer($configuratorId, 'kitchen', 'Küche', 0);
        $playerInstanceId = $this->findPlayerInstance('kitchen');

        $message = MASS_CreatePlayer($configuratorId, 'kitchen', 'Küche', 0);

        $this->assertSame(sprintf('Player already exists as instance %d.', $playerInstanceId), $message);
        $this->assertCount(1, IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID));
    }

    public function testCreatePlayerUsesTargetCategory(): void
    {
        $this->requireModuleCompatibilityCheck();
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);
        $categoryId = IPS_CreateCategory();

        MASS_CreatePlayer($configuratorId, 'kitchen', 'Küche', $categoryId);

        $this->assertSame($categoryId, IPS_GetParent($this->findPlayerInstance('kitchen')));
    }

    public function testCreatePlayerWithInvalidCategoryCreatesNothing(): void
    {
        $connectionId = $this->createConnection();
        $configuratorId = $this->createConfigurator($connectionId);

        try {
            MASS_CreatePlayer($configuratorId, 'kitchen', 'Küche', 99999);
            $this->fail('An invalid target category must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('target category', $e->getMessage());
        }

        $this->assertSame([], IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID));
    }

    // Übernahme bestehender Installationen

    public function testProfileNamesAndMapsAreTakenFromExistingPlayers(): void
    {
        $connectionId = $this->createConnection();
        $playlistMap = '{"5":{"uri":"library://playlist/5","provider_mappings":[]}}';
        $this->createConfiguredPlayer($connectionId, 'kitchen', 'Eigene.Playlists', 'Eigene.Radios', $playlistMap);
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('players/all', [
            ['player_id' => 'bath', 'name' => 'Bad', 'provider' => 'sonos', 'available' => true]
        ]);

        $configuration = $this->playerRows($this->form($configuratorId))[0]['create']['configuration'];

        $this->assertSame('Eigene.Playlists', $configuration['PlaylistProfile']);
        $this->assertSame('Eigene.Radios', $configuration['RadioProfile']);
        $this->assertSame($playlistMap, $configuration['PlaylistMap']);
    }

    public function testConflictingProfileNamesOfPlayersBlockCreation(): void
    {
        $connectionId = $this->createConnection();
        $this->createConfiguredPlayer($connectionId, 'kitchen', 'A.Playlists', 'A.Radios', '{}');
        $this->createConfiguredPlayer($connectionId, 'bath', 'B.Playlists', 'B.Radios', '{}');
        $configuratorId = $this->createConfigurator($connectionId);
        $this->simulateHttpResult('players/all', [
            ['player_id' => 'living', 'name' => 'Wohnzimmer', 'provider' => 'sonos', 'available' => true]
        ]);

        $form = $this->form($configuratorId);
        $rows = $this->playerRows($form);

        $this->assertTrue($form['elements'][0]['visible']);
        $this->assertStringContainsString('Conflicting playlist/radio profile names', $form['elements'][0]['caption']);
        $livingRow = array_values(array_filter($rows, static function (array $row): bool {
            return $row['player_id'] === 'living';
        }))[0];
        $this->assertArrayNotHasKey('create', $livingRow);
        $this->assertSame('', $this->readAttribute($configuratorId, 'ProfileNames'));
    }

    // IPS_IsModuleCompatible ist in den SymconStubs noch nicht implementiert; die Tests laufen automatisch, sobald es verfügbar ist.
    private function requireModuleCompatibilityCheck(): void
    {
        try {
            IPS_IsModuleCompatible(self::PLAYER_MODULE_ID, self::CONNECTION_MODULE_ID);
        } catch (Exception $e) {
            if ($e->getMessage() !== 'Not implemented') {
                throw $e;
            }
            $this->markTestSkipped('SymconStubs implementieren IPS_IsModuleCompatible() noch nicht.');
        }
    }

    private function createConfigurator(int $connectionId): int
    {
        $configuratorId = IPS_CreateInstance(self::CONFIGURATOR_MODULE_ID);
        IPS_ConnectInstance($configuratorId, $connectionId);
        IPS_ApplyChanges($configuratorId);
        $this->clearHttpCalls();

        return $configuratorId;
    }

    private function createConfiguredPlayer(
        int $connectionId,
        string $playerId,
        string $playlistProfile,
        string $radioProfile,
        string $playlistMap
    ): int
    {
        $instanceId = IPS_CreateInstance(self::PLAYER_MODULE_ID);
        IPS_SetProperty($instanceId, 'PlayerID', $playerId);
        IPS_SetProperty($instanceId, 'PlaylistProfile', $playlistProfile);
        IPS_SetProperty($instanceId, 'RadioProfile', $radioProfile);
        IPS_SetProperty($instanceId, 'PlaylistMap', $playlistMap);
        IPS_ConnectInstance($instanceId, $connectionId);
        IPS_ApplyChanges($instanceId);

        return $instanceId;
    }

    private function form(int $configuratorId): array
    {
        return json_decode(
            IPS\InstanceManager::getInstanceInterface($configuratorId)->GetConfigurationForm(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function playerRows(array $form): array
    {
        foreach ($form['elements'] as $element) {
            if (($element['type'] ?? null) === 'Configurator') {
                return $element['values'];
            }
        }
        $this->fail('Configurator element missing in form');
    }

    private function playlist(int $id, string $name): array
    {
        return [
            'item_id'           => (string)$id,
            'provider'          => 'library',
            'name'              => $name,
            'uri'               => 'library://playlist/' . $id,
            'provider_mappings' => [['item_id' => 'p' . $id, 'provider_instance' => 'spotify--a']]
        ];
    }

    private function associations(string $profile): array
    {
        return array_column(IPS_GetVariableProfile($profile)['Associations'], 'Name', 'Value');
    }

    private function lastHttpCall(): array
    {
        $calls = $this->httpCalls();
        $this->assertNotEmpty($calls, 'No HTTP request was sent');
        $call = $calls[count($calls) - 1];

        return [$call['command'], $call['args']];
    }

    private function findPlayerInstance(string $playerId): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $instanceId) {
            if (IPS_GetProperty($instanceId, 'PlayerID') === $playerId) {
                return $instanceId;
            }
        }
        $this->fail('Player instance not found: ' . $playerId);
    }

    private function readAttribute(int $instanceId, string $name): string
    {
        return (new ReflectionMethod(IPSModule::class, 'ReadAttributeString'))
            ->invoke(IPS\InstanceManager::getInstanceInterface($instanceId), $name);
    }

    private function writeAttribute(int $instanceId, string $name, string $value): void
    {
        (new ReflectionMethod(IPSModule::class, 'WriteAttributeString'))
            ->invoke(IPS\InstanceManager::getInstanceInterface($instanceId), $name, $value);
    }
}
