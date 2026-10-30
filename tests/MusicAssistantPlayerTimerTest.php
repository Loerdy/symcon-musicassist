<?php

declare(strict_types=1);

include_once __DIR__ . '/MusicAssistantTestCase.php';

// Die Stubs führen Timer nicht aus: Die Tests prüfen das geplante Intervall und lösen anschließend
// dieselbe Aktion aus, die das Timer-Skript nach Ablauf des Intervalls per IPS_RequestAction aufruft.
class MusicAssistantPlayerTimerTest extends MusicAssistantTestCase
{
    private const PLAYER_ID = 'player-1';
    private const GROUP_ID = 'group-1';
    private const QUEUE_ID = 'queue-1';

    public function testNonLibraryTrackResolvesFavoriteOutsideEventProcessing(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, ['ShowFavorite' => true]);

        $this->answerActiveQueue($connectionId, $this->queueItem('item-1', 'sp-1'));

        // Die Auflösung läuft nicht synchron im Ereignispfad, sondern wird über den Timer geplant.
        $this->assertSame([], $this->httpCalls());
        $this->assertSame(100, $this->timerInterval($playerInstanceId, 'ResolveFavorite'));
        $this->assertFalse($this->value($playerInstanceId, 'Favorite'));

        $this->simulateHttpResult('music/get_library_item', $this->libraryTrack('42', true));
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        $this->assertSame(
            [[
                'command' => 'music/get_library_item',
                'args'    => ['media_type' => 'track', 'item_id' => 'sp-1', 'provider_instance_id_or_domain' => 'spotify']
            ]],
            $this->httpCalls()
        );
        $this->assertTrue($this->value($playerInstanceId, 'Favorite'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'ResolveFavorite'));
    }

    public function testLibraryTrackWithFavoriteNeedsNoResolution(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, ['ShowFavorite' => true]);

        $this->answerActiveQueue($connectionId, $this->queueItem('item-1', '42', 'library', true));
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        $this->assertSame([], $this->httpCalls());
        $this->assertTrue($this->value($playerInstanceId, 'Favorite'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'ResolveFavorite'));
    }

    public function testTrackChangeDuringResolutionDiscardsStaleResultAndRestarts(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, ['ShowFavorite' => true]);
        $this->answerActiveQueue($connectionId, $this->queueItem('item-1', 'sp-1'));

        $this->simulateHttpResultDuring('music/get_library_item', function () use ($connectionId): void {
            $this->sendQueueUpdated($connectionId, ['state' => 'playing', 'current_item' => $this->queueItem('item-2', 'sp-2')]);
        }, $this->libraryTrack('42', true));
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        // Das Ergebnis für den alten Titel wird verworfen; der neue Titel wird erneut geplant.
        $this->assertFalse($this->value($playerInstanceId, 'Favorite'));
        $this->assertSame(100, $this->timerInterval($playerInstanceId, 'ResolveFavorite'));

        $this->simulateHttpResult('music/get_library_item', $this->libraryTrack('43', true));
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        $this->assertSame(['sp-1', 'sp-2'], array_column(array_column($this->httpCalls(), 'args'), 'item_id'));
        $this->assertTrue($this->value($playerInstanceId, 'Favorite'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'ResolveFavorite'));
    }

    public function testIdleQueueCancelsPendingResolution(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, ['ShowFavorite' => true]);
        $this->answerActiveQueue($connectionId, $this->queueItem('item-1', 'sp-1'));
        $this->simulateHttpResult('music/get_library_item', $this->libraryTrack('42', true));

        $this->sendQueueUpdated($connectionId, ['state' => 'idle', 'current_item' => null]);
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        $this->assertSame([], $this->httpCalls());
        $this->assertFalse($this->value($playerInstanceId, 'Favorite'));
    }

    public function testFailedResolutionKeepsFavoriteOffAndFinishesJob(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, ['ShowFavorite' => true]);
        $this->answerActiveQueue($connectionId, $this->queueItem('item-1', 'sp-1'));
        $this->simulateHttpFailure('music/get_library_item');

        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        $this->assertCount(1, $this->httpCalls());
        $this->assertFalse($this->value($playerInstanceId, 'Favorite'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'ResolveFavorite'));
    }

    public function testFavoriteResolutionResumesAfterFailure(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, ['ShowFavorite' => true]);
        $this->answerActiveQueue($connectionId, $this->queueItem('item-1', 'sp-1'));
        $this->simulateHttpFailure('music/get_library_item');
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        $this->sendQueueUpdated($connectionId, ['state' => 'playing', 'current_item' => $this->queueItem('item-2', 'sp-2')]);
        $this->simulateHttpResult('music/get_library_item', $this->libraryTrack('43', true));
        IPS_RequestAction($playerInstanceId, 'ResolveFavorite', 0);

        $this->assertTrue($this->value($playerInstanceId, 'Favorite'));
    }

    public function testGroupSnapshotCreatesMemberVolumeVariables(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::GROUP_ID, ['ShowGroupMemberVolumes' => true]);

        $this->sendGroupMembers($connectionId, ['member-1', 'member-2']);

        $this->assertSame([], $this->httpCalls());
        $this->assertSame(100, $this->timerInterval($playerInstanceId, 'RefreshGroupMemberVolumes'));

        $this->simulateHttpResult('players/all', $this->playersSnapshot(['member-1', 'member-2']));
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->assertSame(
            [['command' => 'players/all', 'args' => ['return_unavailable' => true, 'return_disabled' => true]]],
            $this->httpCalls()
        );
        $this->assertSame(30, $this->memberVolume($playerInstanceId, 'member-1'));
        $this->assertSame(50, $this->memberVolume($playerInstanceId, 'member-2'));
        $this->assertSame('Küche', IPS_GetName($this->memberVolumeId($playerInstanceId, 'member-1')));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'RefreshGroupMemberVolumes'));
    }

    public function testVolumeEventDuringSnapshotIsNotOverwritten(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::GROUP_ID, ['ShowGroupMemberVolumes' => true]);
        $this->sendGroupMembers($connectionId, ['member-1', 'member-2']);

        $this->simulateHttpResultDuring('players/all', function () use ($connectionId): void {
            $this->sendPlayerUpdated($connectionId, 'member-1', ['volume_level' => 77]);
        }, $this->playersSnapshot(['member-1', 'member-2']));
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->assertSame(77, $this->memberVolume($playerInstanceId, 'member-1'));
        $this->assertSame(50, $this->memberVolume($playerInstanceId, 'member-2'));
    }

    public function testRemovedMemberIsConfirmedBySecondSnapshot(): void
    {
        [$connectionId, $playerInstanceId] = $this->createGroupWithMembers(['member-1', 'member-2']);
        $member2VariableId = $this->memberVolumeId($playerInstanceId, 'member-2');

        $this->sendGroupMembers($connectionId, ['member-1']);
        $this->simulateHttpResult('players/all', $this->playersSnapshot(['member-1']));
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        // Eine kurzzeitige Verkleinerung entfernt noch keinen Regler, sondern plant einen Bestätigungs-Snapshot.
        $this->assertTrue(IPS_VariableExists($member2VariableId));
        $this->assertSame(1500, $this->timerInterval($playerInstanceId, 'RefreshGroupMemberVolumes'));

        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->assertFalse(IPS_VariableExists($member2VariableId));
        $this->assertSame(30, $this->memberVolume($playerInstanceId, 'member-1'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'RefreshGroupMemberVolumes'));
    }

    public function testMemberReturningBeforeConfirmationKeepsVariable(): void
    {
        [$connectionId, $playerInstanceId] = $this->createGroupWithMembers(['member-1', 'member-2']);
        $member2VariableId = $this->memberVolumeId($playerInstanceId, 'member-2');

        $this->sendGroupMembers($connectionId, ['member-1']);
        $this->simulateHttpResult('players/all', $this->playersSnapshot(['member-1']));
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->simulateHttpResult('players/all', $this->playersSnapshot(['member-1', 'member-2']));
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->assertTrue(IPS_VariableExists($member2VariableId));
        $this->assertSame($member2VariableId, $this->memberVolumeId($playerInstanceId, 'member-2'));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'RefreshGroupMemberVolumes'));
    }

    public function testFailedSnapshotKeepsExistingVariables(): void
    {
        [$connectionId, $playerInstanceId] = $this->createGroupWithMembers(['member-1', 'member-2']);
        $variableIds = [
            $this->memberVolumeId($playerInstanceId, 'member-1'),
            $this->memberVolumeId($playerInstanceId, 'member-2')
        ];

        $this->sendGroupMembers($connectionId, ['member-1', 'member-2', 'member-3']);
        $this->simulateHttpFailure('players/all');
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->assertSame($variableIds, [
            $this->memberVolumeId($playerInstanceId, 'member-1'),
            $this->memberVolumeId($playerInstanceId, 'member-2')
        ]);
        $this->assertFalse(@IPS_GetObjectIDByIdent($this->memberVolumeIdent('member-3'), $playerInstanceId));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'RefreshGroupMemberVolumes'));
    }

    public function testEmptyPlayerListKeepsExistingVariables(): void
    {
        [$connectionId, $playerInstanceId] = $this->createGroupWithMembers(['member-1', 'member-2']);
        $variableIds = [
            $this->memberVolumeId($playerInstanceId, 'member-1'),
            $this->memberVolumeId($playerInstanceId, 'member-2')
        ];

        $this->sendGroupMembers($connectionId, []);
        $this->simulateHttpResult('players/all', []);
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->assertTrue(IPS_VariableExists($variableIds[0]));
        $this->assertTrue(IPS_VariableExists($variableIds[1]));
    }

    public function testDisabledGroupVolumesSkipQueuedRefresh(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::GROUP_ID, ['ShowGroupMemberVolumes' => true]);
        $this->sendGroupMembers($connectionId, ['member-1']);

        IPS_SetProperty($playerInstanceId, 'ShowGroupMemberVolumes', false);
        IPS_ApplyChanges($playerInstanceId);
        $this->simulateHttpResult('players/all', $this->playersSnapshot(['member-1']));
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);

        $this->assertSame([], $this->httpCalls());
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'RefreshGroupMemberVolumes'));
    }

    public function testCoverIsDownloadedOutsideEventProcessing(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, []);

        $this->answerActiveQueue($connectionId, $this->withCover($this->queueItem('item-1', 'sp-1'), 'a'));

        // Bis zum Download zeigt das Medium das Standardcover; der Abruf läuft erst über den Timer.
        $this->assertSame([], $this->artworkRequests());
        $this->assertSame(100, $this->timerInterval($playerInstanceId, 'DownloadCover'));
        $this->assertSame($this->defaultCover(), $this->coverContent($playerInstanceId));

        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody('a')));
        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);

        $this->assertCount(1, $this->artworkRequests());
        $this->assertStringContainsString('/imageproxy/' . hash('sha256', 'a') . '?', $this->artworkRequests()[0]['url']);
        $this->assertSame(base64_encode($this->jpegBody('a')), $this->coverContent($playerInstanceId));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'DownloadCover'));
    }

    public function testUnchangedCoverIsNotDownloadedAgain(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, []);
        $item = $this->withCover($this->queueItem('item-1', 'sp-1'), 'a');
        $this->answerActiveQueue($connectionId, $item);
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody('a')));
        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);

        $this->sendQueueUpdated($connectionId, ['state' => 'playing', 'current_item' => $item]);
        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);

        $this->assertCount(1, $this->artworkRequests());
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'DownloadCover'));
        $this->assertSame(base64_encode($this->jpegBody('a')), $this->coverContent($playerInstanceId));
    }

    public function testTrackChangeDuringCoverDownloadDiscardsStaleCover(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, []);
        $this->answerActiveQueue($connectionId, $this->withCover($this->queueItem('item-1', 'sp-1'), 'a'));

        $this->simulateArtwork(function () use ($connectionId): array {
            $this->sendQueueUpdated($connectionId, [
                'state'        => 'playing',
                'current_item' => $this->withCover($this->queueItem('item-2', 'sp-2'), 'b')
            ]);
            return $this->artworkResponse(200, 'image/jpeg', $this->jpegBody('a'));
        });
        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);

        $this->assertSame($this->defaultCover(), $this->coverContent($playerInstanceId));
        $this->assertSame(100, $this->timerInterval($playerInstanceId, 'DownloadCover'));

        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody('b')));
        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);

        $this->assertStringContainsString('/imageproxy/' . hash('sha256', 'b') . '?', $this->artworkRequests()[1]['url']);
        $this->assertSame(base64_encode($this->jpegBody('b')), $this->coverContent($playerInstanceId));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'DownloadCover'));
    }

    public function testFailedCoverDownloadKeepsDefaultCoverAndFinishesJob(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, []);
        $this->answerActiveQueue($connectionId, $this->withCover($this->queueItem('item-1', 'sp-1'), 'a'));
        $this->simulateArtwork($this->artworkResponse(200, 'text/html', 'error page'));

        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);
        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);

        $this->assertCount(1, $this->artworkRequests());
        $this->assertSame($this->defaultCover(), $this->coverContent($playerInstanceId));
        $this->assertSame(0, $this->timerInterval($playerInstanceId, 'DownloadCover'));
    }

    public function testIdleQueueClearsCoverAndCancelsDownload(): void
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::PLAYER_ID, []);
        $this->answerActiveQueue($connectionId, $this->withCover($this->queueItem('item-1', 'sp-1'), 'a'));
        $this->simulateArtwork($this->artworkResponse(200, 'image/jpeg', $this->jpegBody('a')));

        $this->sendQueueUpdated($connectionId, ['state' => 'idle', 'current_item' => null]);
        IPS_RequestAction($playerInstanceId, 'DownloadCover', 0);

        $this->assertSame([], $this->artworkRequests());
        $this->assertSame('', $this->coverContent($playerInstanceId));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createActivePlayer(string $playerId, array $properties): array
    {
        $connectionId = $this->createConnection();
        $playerInstanceId = $this->createPlayer($connectionId, $playerId);
        foreach ($properties as $name => $value) {
            IPS_SetProperty($playerInstanceId, $name, $value);
        }
        IPS_ApplyChanges($playerInstanceId);
        $this->authenticate($connectionId);
        $this->notifyConnectionStatus($connectionId, $playerInstanceId);
        $this->clearHttpCalls();

        return [$connectionId, $playerInstanceId];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createGroupWithMembers(array $memberIds): array
    {
        [$connectionId, $playerInstanceId] = $this->createActivePlayer(self::GROUP_ID, ['ShowGroupMemberVolumes' => true]);
        $this->sendGroupMembers($connectionId, $memberIds);
        $this->simulateHttpResult('players/all', $this->playersSnapshot($memberIds));
        IPS_RequestAction($playerInstanceId, 'RefreshGroupMemberVolumes', 0);
        $this->clearHttpCalls();

        return [$connectionId, $playerInstanceId];
    }

    private function answerActiveQueue(int $connectionId, array $currentItem): void
    {
        $queueRequest = $this->lastSentMessage($connectionId, 'player_queues/get_active_queue');
        $this->receiveFromServer($connectionId, [
            'message_id' => $queueRequest['message_id'],
            'result'     => [
                'queue_id'     => self::QUEUE_ID,
                'active'       => true,
                'display_name' => 'Wohnzimmer',
                'available'    => true,
                'items'        => 1,
                'state'        => 'playing',
                'current_item' => $currentItem
            ]
        ]);
    }

    private function sendQueueUpdated(int $connectionId, array $data): void
    {
        $this->receiveFromServer($connectionId, [
            'event'     => 'queue_updated',
            'object_id' => self::QUEUE_ID,
            'data'      => $data
        ]);
    }

    private function sendPlayerUpdated(int $connectionId, string $playerId, array $data): void
    {
        $this->receiveFromServer($connectionId, [
            'event'     => 'player_updated',
            'object_id' => $playerId,
            'data'      => $data
        ]);
    }

    private function sendGroupMembers(int $connectionId, array $memberIds): void
    {
        $this->sendPlayerUpdated($connectionId, self::GROUP_ID, ['type' => 'group', 'group_members' => $memberIds]);
    }

    private function queueItem(string $queueItemId, string $itemId, string $provider = 'spotify', ?bool $favorite = null): array
    {
        $mediaItem = [
            'media_type' => 'track',
            'item_id'    => $itemId,
            'provider'   => $provider,
            'uri'        => $provider . '://track/' . $itemId,
            'name'       => 'Titel ' . $itemId,
            'artists'    => [['name' => 'Interpret']]
        ];
        if ($favorite !== null) {
            $mediaItem['favorite'] = $favorite;
        }

        return [
            'queue_id'      => self::QUEUE_ID,
            'queue_item_id' => $queueItemId,
            'name'          => $mediaItem['name'],
            'media_item'    => $mediaItem
        ];
    }

    private function withCover(array $queueItem, string $seed): array
    {
        return $queueItem + ['image' => ['proxy_id' => hash('sha256', $seed)]];
    }

    private function coverContent(int $instanceId): string
    {
        return IPS_GetMediaContent(IPS_GetObjectIDByIdent('Cover', $instanceId));
    }

    private function defaultCover(): string
    {
        return base64_encode((string)file_get_contents(__DIR__ . '/../MusicAssistantPlayer/default-cover.png'));
    }

    private function libraryTrack(string $itemId, bool $favorite): array
    {
        return [
            'media_type' => 'track',
            'item_id'    => $itemId,
            'provider'   => 'library',
            'uri'        => 'library://track/' . $itemId,
            'favorite'   => $favorite
        ];
    }

    private function playersSnapshot(array $memberIds): array
    {
        $members = [
            'member-1' => ['name' => 'Küche', 'volume_level' => 30],
            'member-2' => ['name' => 'Bad', 'volume_level' => 50],
            'member-3' => ['name' => 'Büro', 'volume_level' => 70]
        ];
        $players = [['player_id' => self::GROUP_ID, 'name' => 'Erdgeschoss', 'type' => 'group', 'group_members' => $memberIds]];
        foreach ($members as $playerId => $member) {
            $players[] = ['player_id' => $playerId, 'type' => 'player'] + $member;
        }

        return $players;
    }

    private function player(int $instanceId): MusicAssistantPlayerStubCompat
    {
        return IPS\InstanceManager::getInstanceInterface($instanceId);
    }

    private function timerInterval(int $instanceId, string $ident): int
    {
        return $this->player($instanceId)->GetTimerIntervalForTest($ident);
    }

    private function value(int $instanceId, string $ident)
    {
        return GetValue(IPS_GetObjectIDByIdent($ident, $instanceId));
    }

    private function memberVolumeIdent(string $playerId): string
    {
        return 'MemberVolume_' . hash('sha256', $playerId);
    }

    private function memberVolumeId(int $instanceId, string $playerId): int
    {
        return IPS_GetObjectIDByIdent($this->memberVolumeIdent($playerId), $instanceId);
    }

    private function memberVolume(int $instanceId, string $playerId): int
    {
        return GetValue($this->memberVolumeId($instanceId, $playerId));
    }
}
