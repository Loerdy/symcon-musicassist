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
        $this->assertSame(
            ['name' => 'showLabel', 'add' => true, 'edit' => 'CheckBox'],
            [
                'name' => $form['elements'][2]['columns'][2]['name'],
                'add'  => $form['elements'][2]['columns'][2]['add'],
                'edit' => $form['elements'][2]['columns'][2]['edit']['type']
            ]
        );
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
            ['index' => 0, 'type' => 'radio', 'label' => 'Radio <Bob>', 'available' => true, 'showLabel' => true, 'image' => ''],
            ['index' => 1, 'type' => 'playlist', 'label' => 'Lieblingslieder', 'available' => true, 'showLabel' => true, 'image' => ''],
            ['index' => 2, 'type' => 'radio', 'label' => '', 'available' => false, 'showLabel' => true, 'image' => '']
        ], $state['entries']);
    }

    public function testTilePassesHiddenLabelAndKeepsItForOlderEntries(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [
            ['item' => 'radio:12', 'label' => '', 'showLabel' => false],
            ['item' => 'radio:15', 'label' => '', 'showLabel' => true],
            ['item' => 'playlist:7', 'label' => '']
        ]);

        $this->assertSame([false, true, true], array_column($this->tileState($shortcutId)['entries'], 'showLabel'));
    }

    public function testTileEscapesMarkupInInitialState(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:15', 'label' => '']]);

        $tile = IPS\InstanceManager::getInstanceInterface($shortcutId)->GetVisualizationTile();

        $this->assertStringNotContainsString('Radio <Bob>', $tile);
        $this->assertStringNotContainsString('__MUSIC_ASSISTANT_INITIAL_STATE__', $tile);
    }

    // Bilder

    public function testMissingImagesAreScheduledAfterApplyChanges(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '']]);

        $this->assertSame(100, $this->shortcut($shortcutId)->GetTimerIntervalForTest('LoadImages'));
    }

    public function testNoImagesAreScheduledWithoutPlayer(): void
    {
        $shortcutId = $this->createShortcut($this->createConnection(), 0, [['item' => 'radio:12', 'label' => '']]);

        $this->assertSame(0, $this->shortcut($shortcutId)->GetTimerIntervalForTest('LoadImages'));
    }

    public function testLibraryImageIsLoadedAndShownInTile(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '']]);
        $this->simulateHttpResult('music/radios/library_items', [
            $this->libraryItem('15', [$this->image('thumb', 'c')]),
            $this->libraryItem('12', [$this->image('logo', 'b'), $this->image('thumb', 'a')])
        ]);
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody('swr3')));

        IPS_RequestAction($shortcutId, 'LoadImages', false);

        $this->assertSame(['summary' => false], $this->lastCall('music/radios/library_items')['args']);
        $this->assertCount(1, $this->artworkRequests());
        $this->assertStringContainsString('/imageproxy/' . str_repeat('a', 64) . '?', $this->artworkRequests()[0]['url']);
        $this->assertSame(
            'data:image/jpeg;base64,' . base64_encode($this->jpegBody('swr3')),
            $this->tileState($shortcutId)['entries'][0]['image']
        );
        $mediaId = $this->ownImageMedia($shortcutId);
        $this->assertTrue(IPS_GetObject($mediaId)['ObjectIsHidden']);
        $this->assertSame(0, $this->shortcut($shortcutId)->GetTimerIntervalForTest('LoadImages'));
    }

    public function testLoadedImageIsNotRequestedAgain(): void
    {
        $shortcutId = $this->createShortcutWithLoadedImage();
        $this->clearHttpCalls();
        MusicAssistantConnectionHttpStub::$artworkRequests = [];

        IPS_RequestAction($shortcutId, 'LoadImages', false);
        IPS_ApplyChanges($shortcutId);

        $this->assertSame([], $this->httpCalls());
        $this->assertSame([], $this->artworkRequests());
        $this->assertSame(0, $this->shortcut($shortcutId)->GetTimerIntervalForTest('LoadImages'));
    }

    public function testFailedReloadKeepsExistingImage(): void
    {
        $shortcutId = $this->createShortcutWithLoadedImage();
        $this->simulateArtwork($this->artworkResponse(404, 'text/html', 'not found'));

        IPS_RequestAction($shortcutId, 'RefreshImages', true);

        $this->assertCount(2, $this->artworkRequests());
        $this->assertSame(
            'data:image/jpeg;base64,' . base64_encode($this->jpegBody('swr3')),
            $this->tileState($shortcutId)['entries'][0]['image']
        );
    }

    public function testReloadReplacesImageAndFileFormat(): void
    {
        $shortcutId = $this->createShortcutWithLoadedImage();
        $png = "\x89PNG\r\n\x1A\n" . 'neu';
        $this->simulateArtwork($this->artworkResponse(200, 'image/png', $png));

        IPS_RequestAction($shortcutId, 'RefreshImages', true);

        $this->assertSame('data:image/png;base64,' . base64_encode($png), $this->tileState($shortcutId)['entries'][0]['image']);
        $this->assertStringEndsWith('.png', IPS_GetMedia($this->ownImageMedia($shortcutId))['MediaFile']);
    }

    public function testImageIdWithUnexpectedFormatIsNotRequested(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '']]);
        $this->simulateHttpResult('music/radios/library_items', [
            $this->libraryItem('12', [['type' => 'thumb', 'path' => 'x', 'provider' => 'radiobrowser', 'proxy_id' => '../etc/passwd']])
        ]);

        IPS_RequestAction($shortcutId, 'LoadImages', false);

        $this->assertSame([], $this->artworkRequests());
        $this->assertSame('', $this->tileState($shortcutId)['entries'][0]['image']);
    }

    public function testTooLargeImageIsNotStored(): void
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '']]);
        $this->simulateHttpResult('music/radios/library_items', [$this->libraryItem('12', [$this->image('thumb', 'a')])]);
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody(str_repeat('x', 524288))));

        IPS_RequestAction($shortcutId, 'LoadImages', false);

        $this->assertSame('', $this->tileState($shortcutId)['entries'][0]['image']);
        $this->assertSame([], array_filter(IPS_GetChildrenIDs($shortcutId), static function (int $childId): bool {
            return str_starts_with(IPS_GetObject($childId)['ObjectIdent'], 'Image_');
        }));
    }

    public function testOwnImageOverridesLibraryImage(): void
    {
        $connectionId = $this->createConnection();
        $png = "\x89PNG\r\n\x1A\n" . 'eigenes-logo';
        $mediaId = $this->createUserImage(0, base64_encode($png));
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '', 'image' => $mediaId]]);

        IPS_RequestAction($shortcutId, 'LoadImages', false);

        $this->assertSame(0, $this->shortcut($shortcutId)->GetTimerIntervalForTest('LoadImages'));
        $this->assertSame([], $this->httpCalls());
        $this->assertSame('data:image/png;base64,' . base64_encode($png), $this->tileState($shortcutId)['entries'][0]['image']);
    }

    public function testInvalidOwnImageFallsBackToLibraryImage(): void
    {
        $connectionId = $this->createConnection();
        $mediaId = $this->createUserImage(0, base64_encode('kein Bild'));
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '', 'image' => $mediaId]]);
        $this->simulateHttpResult('music/radios/library_items', [$this->libraryItem('12', [$this->image('thumb', 'a')])]);
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody('swr3')));

        $this->assertSame(100, $this->shortcut($shortcutId)->GetTimerIntervalForTest('LoadImages'));
        IPS_RequestAction($shortcutId, 'LoadImages', false);

        $this->assertStringStartsWith('data:image/jpeg;base64,', $this->tileState($shortcutId)['entries'][0]['image']);
    }

    public function testDeletedOwnImageSchedulesLibraryImage(): void
    {
        $connectionId = $this->createConnection();
        $mediaId = $this->createUserImage(0, base64_encode("\x89PNG\r\n\x1A\n" . 'logo'));
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '', 'image' => $mediaId]]);
        IPS_DeleteMedia($mediaId, true);

        $this->shortcut($shortcutId)->MessageSink(0, $mediaId, MM_DELETE, []);

        $this->assertSame(100, $this->shortcut($shortcutId)->GetTimerIntervalForTest('LoadImages'));
    }

    public function testRemovedEntryDeletesOnlyOwnImages(): void
    {
        $shortcutId = $this->createShortcutWithLoadedImage();
        $ownImageId = $this->ownImageMedia($shortcutId);
        $userMediaId = $this->createUserImage($shortcutId, base64_encode($this->jpegBody('benutzer')));
        IPS_SetIdent($userMediaId, 'Logo');

        IPS_SetProperty($shortcutId, 'Entries', '[]');
        IPS_ApplyChanges($shortcutId);

        $this->assertFalse(IPS_MediaExists($ownImageId));
        $this->assertTrue(IPS_MediaExists($userMediaId));
    }

    public function testUnreadableEntryListKeepsImages(): void
    {
        $shortcutId = $this->createShortcutWithLoadedImage();
        $ownImageId = $this->ownImageMedia($shortcutId);

        IPS_SetProperty($shortcutId, 'Entries', '{kaputt');
        IPS_ApplyChanges($shortcutId);

        $this->assertTrue(IPS_MediaExists($ownImageId));
    }

    private function createShortcutWithLoadedImage(): int
    {
        $connectionId = $this->createConnection();
        $shortcutId = $this->createShortcut($connectionId, $this->createProfiledPlayer($connectionId), [['item' => 'radio:12', 'label' => '']]);
        $this->simulateHttpResult('music/radios/library_items', [$this->libraryItem('12', [$this->image('thumb', 'a')])]);
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody('swr3')));
        IPS_RequestAction($shortcutId, 'LoadImages', false);

        return $shortcutId;
    }

    private function createUserImage(int $parentId, string $content): int
    {
        $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetParent($mediaId, $parentId);
        IPS_SetMediaFile($mediaId, 'media/user_' . $mediaId . '.png', false);
        IPS_SetMediaContent($mediaId, $content);

        return $mediaId;
    }

    private function ownImageMedia(int $shortcutId): int
    {
        foreach (IPS_GetChildrenIDs($shortcutId) as $childId) {
            if (str_starts_with(IPS_GetObject($childId)['ObjectIdent'], 'Image_')) {
                return $childId;
            }
        }
        $this->fail('Kein eigenes Bild-Medium angelegt');
    }

    private function libraryItem(string $itemId, array $images): array
    {
        return [
            'item_id'  => $itemId,
            'provider' => 'library',
            'name'     => 'Sender ' . $itemId,
            'uri'      => 'library://radio/' . $itemId,
            'metadata' => ['images' => $images]
        ];
    }

    private function image(string $type, string $proxyChar): array
    {
        return ['type' => $type, 'path' => 'https://example.invalid/' . $type, 'provider' => 'radiobrowser', 'proxy_id' => str_repeat($proxyChar, 64)];
    }

    private function shortcut(int $shortcutId): MusicAssistantShortcutStubCompat
    {
        return IPS\InstanceManager::getInstanceInterface($shortcutId);
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
