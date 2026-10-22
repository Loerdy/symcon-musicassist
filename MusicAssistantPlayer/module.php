<?php
declare(strict_types=1);

class MusicAssistantPlayer extends IPSModule
{
    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';
    private const REPEAT_PROFILE  = 'MA.RepeatMode';
    private const VOLUP_PROFILE   = 'MA.VolumeUp';
    private const VOLDOWN_PROFILE = 'MA.VolumeDown';
    private const COVER_SEMAPHORE_TIMEOUT = 5000;
    private const GROUP_MEMBER_VOLUME_SEMAPHORE_TIMEOUT = 40000;
    private const DEFAULT_COVER = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCABAAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD5qooooAKKK6Twd8OvEPj/AO1/2Dp/2/7Js879/HHs3btv32Gc7W6elAHN0V6rpH7MvjvUrlorixtdKQIWE13doyMcj5R5Rc55zyMcHnpnrdI/ZB1Ka2ZtU8R2tncbyFS0tmuEK4GCWZkIOc8Y7DnngA+faK+mfir8EPCvgn4R31/a2G/WrOK2Q3/nSjzHMsaO/llyo3ZbjtnjpXzNQAUUUUAFfSH7Hf8AzN3/AG5/+1q+b6+kP2O/+Zu/7c//AGtQBJ48/am1LQ9a1PSNL0K1juLC+ltmubudpUkVGZSQihCpJAP3jjkc9a4DV/2mvHepXKy299a6UgQKYbS0RkY5PzHzQ5zzjg44HHXPHfEv/ko/ir/sLXf/AKOarPhX4T+LfGtm15o+iT3NoOk8jJDG/JHyM5UPgqQducd8UAfSHxS1G41j9mY395J513dafp880m0LudpIWY4GAMkngV8hV9e/FLTrjR/2ZjYXkfk3drp+nwTR7g211khVhkZBwQeRXyFQAUUUUAdj8MPhhqXxR1qWyspUtLe3RZbm7mRikalgMDAwXI3EKSM7W5GK+p/g78Hf+FTf2v8A8Tf+1f7Q8n/l28ny9m//AG2znf7dK8g/ZO8U6bo2ta5pd7cJa3GopA1s0zqiSMjMvlgkglyZVwoBzhvTn6Q8U+KdN8G6LcapqlwkFvCjMqs6q8zBSwjQMQGchThc80AfDXxL/wCSj+Kv+wtd/wDo5q+z/hJ9j/4Vj4X+w+R5P9nw7vs+NvmbR5mcfxb9+7vuznnNfCup6jcaxqV3f3knnXd1K880m0LudiWY4GAMkngV0nhX4seLfBVm1no+tz21oekEipNGnJPyK4YJksSduM980AfSn7UXiKHSfhnJpzbHuNVuI4UQyBXVUYSM4XqwBRVPTHmDnoD8fVt+KvGuueNr1brXNTn1CVfuLIQI48gA7EGFXO0ZwBnGTzWJQAUUUUAFFFFABRRRQAUUUUAf/9k=';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);

        $this->RegisterPropertyString('PlayerID', '');
        $this->RegisterPropertyBoolean('ShowGroupMemberVolumes', false);

        $this->RegisterPropertyString('PlaylistProfile', '');
        $this->RegisterPropertyString('PlaylistMap', '{}');

        $this->RegisterPropertyString('RadioProfile', '');
        $this->RegisterPropertyString('RadioMap', '{}');

        $this->RegisterAttributeString('CoverProxyID', '');
        $this->RegisterAttributeString('CoverTargetProxyID', '');
        $this->RegisterAttributeBoolean('CoverIsDefault', false);
        $this->RegisterAttributeString('GroupMemberVolumeMap', '{}');

        $this->SetBuffer('ParentInstanceId', '0');
        $this->SetBuffer('RegisteredPlayerID', '');
        $this->SetBuffer('GroupMemberVolumeRefreshRequest', '');
        $this->SetBuffer('KnownPlayerTypeID', '');
        $this->SetBuffer('KnownPlayerType', '');

        $this->RegisterTimer('ResetPlaylist', 0, 'MASS_ResetPlaylistSelection($_IPS["TARGET"]);');
        $this->RegisterTimer('ResetRadio', 0, 'MASS_ResetRadioSelection($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->registerParentStatusMessage();
        $this->registerWithParent();

        // Auswahl-Variablen
        if ($this->ensureIntegerSelectorVariable('Playlist', 'Playlist', $this->ReadPropertyString('PlaylistProfile'), 10)) {
            $vidPlaylist = @$this->GetIDForIdent('Playlist');
            IPS_SetIcon($vidPlaylist, 'list-music');
        }
        if ($this->ensureIntegerSelectorVariable('Radio', 'Radio', $this->ReadPropertyString('RadioProfile'), 20)) {
            $vidRadio = @$this->GetIDForIdent('Radio');
            IPS_SetIcon($vidRadio, 'radio');
        }

        // Transport
        $this->MaintainVariable('Transport', 'Wiedergabe', VARIABLETYPE_INTEGER, '~PlaybackPreviousNext', 30, true);
        $this->EnableAction('Transport');

        // Shuffle
        $this->MaintainVariable('Shuffle', 'Shuffle', VARIABLETYPE_BOOLEAN, '~Shuffle', 40, true);
        $this->EnableAction('Shuffle');

        // Repeat
        $repeatProfileAvailable = $this->ensureRepeatProfile();
        $this->MaintainVariable(
            'Repeat',
            'Repeat',
            VARIABLETYPE_STRING,
            $repeatProfileAvailable ? self::REPEAT_PROFILE : '',
            50,
            true
        );
        $this->EnableAction('Repeat');

        // Mute
        $this->MaintainVariable('Mute', 'Mute', VARIABLETYPE_BOOLEAN, '~Mute', 60, true);
        $this->EnableAction('Mute');

        // Volume
        $this->MaintainVariable('VolumeLevel', 'Volume', VARIABLETYPE_INTEGER, '~Volume', 70, true);
        $this->EnableAction('VolumeLevel');

        // Volume Buttons (Group Up/Down)
        $volumeButtonProfiles = $this->ensureVolumeButtonProfiles();
        $this->MaintainVariable(
            'VolumeUp',
            'Volume +',
            VARIABLETYPE_INTEGER,
            $volumeButtonProfiles[self::VOLUP_PROFILE] ? self::VOLUP_PROFILE : '',
            80,
            true
        );
        $this->EnableAction('VolumeUp');

        $this->MaintainVariable(
            'VolumeDown',
            'Volume -',
            VARIABLETYPE_INTEGER,
            $volumeButtonProfiles[self::VOLDOWN_PROFILE] ? self::VOLDOWN_PROFILE : '',
            90,
            true
        );
        $volumeDownId = $this->GetIDForIdent('VolumeDown');
        if (IPS_GetName($volumeDownId) === 'Volume') {
            IPS_SetName($volumeDownId, 'Volume -');
        }
        $this->EnableAction('VolumeDown');

        // Now Playing
        $this->MaintainVariable('NowTitle',  'Titel',     VARIABLETYPE_STRING, '~Song',   110, true);
        $this->MaintainVariable('NowArtist', 'Interpret', VARIABLETYPE_STRING, '~Artist', 120, true);
        $albumId = @$this->GetIDForIdent('NowAlbum');
        $hadAlbumVariable = $albumId > 0
            && IPS_VariableExists($albumId)
            && (IPS_GetVariable($albumId)['VariableType'] ?? -1) === VARIABLETYPE_STRING;
        $this->MaintainVariable('NowAlbum',  'Album',     VARIABLETYPE_STRING, '',        130, true);
        $vidAlbum = @$this->GetIDForIdent('NowAlbum');
        $albumCreated = !$hadAlbumVariable
            && $vidAlbum > 0
            && IPS_VariableExists($vidAlbum)
            && (IPS_GetVariable($vidAlbum)['VariableType'] ?? -1) === VARIABLETYPE_STRING
            && ($albumId <= 0 || $vidAlbum !== $albumId);
        if ($albumCreated) {
            IPS_SetIcon($vidAlbum, 'album');
        }
        $this->ensureCoverMedia();

        if ($this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
            try {
                $this->refreshGroupMemberVolumes();
            } catch (Throwable $e) {
                $this->SendDebug('Group member volumes', 'Initialisierung fehlgeschlagen: ' . $e->getMessage(), 0);
            }
        } else {
            $this->removeGroupMemberVolumeVariables();
        }

        // Repeat Initialwert
        if (@$this->GetIDForIdent('Repeat') > 0 && (string)@$this->GetValue('Repeat') === '') {
            $this->SetValue('Repeat', 'off');
        }

        // Konfig-Anzeige initial aktualisieren
        $this->UpdateConfigView();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === FM_CONNECT && $SenderID === $this->InstanceID) {
            $this->registerParentStatusMessage();
            $this->registerWithParent();
        }
        if ($Message === IM_CHANGESTATUS
            && $SenderID === (int)$this->GetBuffer('ParentInstanceId')
            && $this->HasActiveParent()) {
            $this->registerWithParent();
        }
    }

    public function GetConfigurationForm(): string
    {
        // Die Konfigurationsanzeige wird kompatibel über Label und UpdateFormField umgesetzt.
        $showGroupMemberVolumes = $this->isConfiguredPlayerKnownGroup();
        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'PlayerID', 'caption' => 'Player ID (änderbar)'],
                ['type' => 'Label', 'caption' => 'Konfiguration (read-only):'],
                // Inhalt wird per UpdateFormField("ConfigView","caption", ...) gesetzt
                ['type' => 'Label', 'name' => 'ConfigView', 'caption' => ''],
                ['type' => 'Button', 'caption' => 'Konfiguration aktualisieren', 'onClick' => 'IPS_RequestAction($id, "UpdateConfigView", true);'],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowGroupMemberVolumes',
                    'caption' => 'Individuelle Lautstärkeregler für aktuelle Gruppenmitglieder anzeigen',
                    'visible' => $showGroupMemberVolumes
                ]
            ]
        ];

        return json_encode($form);
    }

    public function ReceiveData($JSONString): string
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || ($packet['DataID'] ?? null) !== self::CONNECTION_EVENT) {
            return '';
        }

        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if (($packet['Event'] ?? null) === 'queue_updated') {
            if (is_string($packet['ObjectID'] ?? null)
                && is_array($packet['PlayerIDs'] ?? null)
                && in_array($playerId, $packet['PlayerIDs'], true)
                && is_array($packet['Data'] ?? null)) {
                $this->SendDebug('queue_updated', 'Passendes Queue-Event empfangen für QueueID=' . $packet['ObjectID'], 0);
                $data = $packet['Data'];
                if (array_key_exists('state', $data)) {
                    $this->updateTransportFromQueueState($data['state']);
                }
                $hasCurrentItem = array_key_exists('current_item', $data);
                $isIdle = is_string($data['state'] ?? null)
                    && strtolower(trim($data['state'])) === 'idle';
                if ($hasCurrentItem && ($data['current_item'] === null || !$isIdle)) {
                    if ($this->updateMetadataFromQueueItem($data['current_item'])) {
                        $this->SendDebug('queue_updated', 'Metadaten aktualisiert', 0);
                    }
                }
                if (array_key_exists('state', $data)) {
                    $this->updateMetadataVisibilityFromQueueState($data['state']);
                }
                try {
                    if ($isIdle) {
                        $this->clearCover();
                    } elseif ($hasCurrentItem) {
                        $this->updateCoverFromQueueItem($data['current_item'], $data['state'] ?? null);
                    } elseif (array_key_exists('state', $data)) {
                        $this->updateCoverVisibilityFromQueueState($data['state']);
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Artwork', 'Cover konnte nicht aktualisiert werden: ' . $e->getMessage(), 0);
                }
                if (array_key_exists('shuffle_enabled', $data)) {
                    $this->updateShuffleState($data['shuffle_enabled']);
                }
                if (array_key_exists('repeat_mode', $data)) {
                    $this->updateRepeatMode($data['repeat_mode']);
                }
            }
            return '';
        }

        if (($packet['Event'] ?? null) !== 'player_updated'
            || !is_string($packet['ObjectID'] ?? null)
            || !is_array($packet['Data'] ?? null)) {
            return '';
        }

        $objectId = $packet['ObjectID'];
        $data = $packet['Data'];
        if ($objectId === $playerId && is_string($data['type'] ?? null)
            && trim($data['type']) !== '') {
            $playerType = trim($data['type']);
            if ($this->GetBuffer('KnownPlayerTypeID') !== $playerId
                || $this->GetBuffer('KnownPlayerType') !== $playerType) {
                $this->SetBuffer('KnownPlayerTypeID', $playerId);
                $this->SetBuffer('KnownPlayerType', $playerType);
                $this->updateGroupMemberVolumesFormVisibility();
            }
        }
        if ($this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
            try {
                $this->processGroupMemberPlayerUpdate(
                    $objectId,
                    $data,
                    ($packet['Source'] ?? null) === 'resync'
                );
            } catch (Throwable $e) {
                $this->SendDebug('Group member volumes', 'Event konnte nicht verarbeitet werden: ' . $e->getMessage(), 0);
            }
        }
        if ($objectId !== $playerId) {
            return '';
        }

        $this->SendDebug('player_updated', 'Passendes Event empfangen für PlayerID=' . $objectId, 0);

        $volumeLevel = $data['volume_level'] ?? null;
        if (!is_int($volumeLevel) && !is_float($volumeLevel)
            && (is_int($data['group_volume'] ?? null) || is_float($data['group_volume'] ?? null))) {
            $volumeLevel = $data['group_volume'];
        }
        if ($this->updateVolumeLevel($volumeLevel)) {
            $this->SendDebug('player_updated', 'Volume aktualisiert', 0);
        }
        if ($this->updateMuteState($data['volume_muted'] ?? null)) {
            $this->SendDebug('player_updated', 'Mute aktualisiert', 0);
        }
        return '';
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($this->requestGroupMemberVolumeAction((string)$Ident, $Value)) {
            return;
        }

        switch ($Ident) {
            case 'UpdateConfigView':
                $this->UpdateConfigView();
                break;

            case 'Playlist':
                try {
                    $itemId = (int)$Value;
                    if ($itemId > 0) {
                        $playlist = $this->resolvePlaylistFromMap($itemId);
                        $this->PlayPlaylist($playlist);
                        $this->SetValue('Playlist', $itemId);
                        $this->SetTimerInterval('ResetPlaylist', 5000);
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Playlist start failed', $e->getMessage(), 0);
                    $this->LogMessage('Playlist start failed: ' . $e->getMessage(), KL_ERROR);
                }
                break;

            case 'Radio':
                try {
                    $itemId = (int)$Value;
                    if ($itemId > 0) {
                        $uri = $this->resolveUriFromMap($itemId, 'RadioMap', 'music/radios/library_items');
                        $this->PlayMediaUri($uri);
                        $this->SetValue('Radio', $itemId);
                        $this->SetTimerInterval('ResetRadio', 5000);
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Radio start failed', $e->getMessage(), 0);
                    $this->LogMessage('Radio start failed: ' . $e->getMessage(), KL_ERROR);
                }
                break;

            case 'Transport':
                $this->ExecuteTransport((int)$Value);
                $this->SetValue('Transport', (int)$Value);
                break;

            case 'Shuffle':
                $enabled = (bool)$Value;
                $this->SetShuffle($enabled);
                $this->SetValue('Shuffle', $enabled);
                break;

            case 'Repeat':
                $mode = strtolower(trim((string)$Value));
                if (!in_array($mode, ['off', 'one', 'all'], true)) {
                    $mode = 'off';
                }
                $this->SetRepeat($mode);
                $this->SetValue('Repeat', $mode);
                break;

            case 'Mute':
                $muted = (bool)$Value;
                try {
                    $this->SetMute($muted);
                    $this->SetValue('Mute', $muted);
                } catch (Throwable $e) {
                    $this->SendDebug('Mute failed', $e->getMessage(), 0);
                    $this->LogMessage('Mute failed: ' . $e->getMessage(), KL_ERROR);
                }
                break;

            case 'VolumeLevel':
                $level = max(0, min(100, (int)$Value));
                try {
                    $this->SetVolumeLevel($level);
                } catch (Throwable $e) {
                    $this->SendDebug('VolumeSet failed', $e->getMessage(), 0);
                    $this->LogMessage('VolumeSet failed: ' . $e->getMessage(), KL_ERROR);
                }
                break;

            case 'VolumeUp':
                if ((int)$Value === 1) {
                    try {
                        $this->GroupVolumeUp();
                    } catch (Throwable $e) {
                        $this->SendDebug('VolumeUp failed', $e->getMessage(), 0);
                    }
                }
                $this->SetValue('VolumeUp', 0);
                break;

            case 'VolumeDown':
                if ((int)$Value === 1) {
                    try {
                        $this->GroupVolumeDown();
                    } catch (Throwable $e) {
                        $this->SendDebug('VolumeDown failed', $e->getMessage(), 0);
                    }
                }
                $this->SetValue('VolumeDown', 0);
                break;

            default:
                throw new Exception('Unknown action: ' . $Ident);
        }
    }

    public function ResetPlaylistSelection(): void
    {
        $this->SetTimerInterval('ResetPlaylist', 0);
        if (@$this->GetIDForIdent('Playlist') > 0) {
            $this->SetValue('Playlist', 0);
        }
    }

    public function ResetRadioSelection(): void
    {
        $this->SetTimerInterval('ResetRadio', 0);
        if (@$this->GetIDForIdent('Radio') > 0) {
            $this->SetValue('Radio', 0);
        }
    }

    public function UpdateConfigView(): void
    {
        $cfg = [
            'InstanceID'        => $this->InstanceID,
            'PlayerID'          => $this->ReadPropertyString('PlayerID'),
            'PlaylistProfile'   => $this->ReadPropertyString('PlaylistProfile'),
            'RadioProfile'      => $this->ReadPropertyString('RadioProfile'),
        ];

        $text = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Label Caption ist oft in der Länge begrenzt -> notfalls kürzen
        if (strlen($text) > 1800) {
            $text = substr($text, 0, 1800) . "\n... (gekürzt)";
        }

        $this->UpdateFormField('ConfigView', 'caption', $text);
        $this->updateGroupMemberVolumesFormVisibility();
    }

    private function isConfiguredPlayerKnownGroup(): bool
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        return $playerId !== ''
            && $this->GetBuffer('KnownPlayerTypeID') === $playerId
            && $this->GetBuffer('KnownPlayerType') === 'group';
    }

    private function updateGroupMemberVolumesFormVisibility(): void
    {
        $this->UpdateFormField(
            'ShowGroupMemberVolumes',
            'visible',
            $this->isConfiguredPlayerKnownGroup()
        );
    }

    private function requestGroupMemberVolumeAction(string $ident, $value): bool
    {
        if (!$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
            return false;
        }
        $map = $this->readGroupMemberVolumeMap();
        if ($map === null || !isset($map[$ident])) {
            return false;
        }
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId !== $map[$ident]['variable_id'] || !IPS_VariableExists($variableId)) {
            return false;
        }
        if (!is_int($value) || $value < 0 || $value > 100) {
            throw new InvalidArgumentException('Group member volume must be between 0 and 100.');
        }

        $this->sendApiRequest('players/cmd/volume_set', [
            'player_id' => $map[$ident]['player_id'],
            'volume_level' => $value
        ]);
        return true;
    }

    private function processGroupMemberPlayerUpdate(string $objectId, array $data, bool $isResync): void
    {
        $groupPlayerId = trim($this->ReadPropertyString('PlayerID'));
        if ($objectId === $groupPlayerId) {
            if (($data['type'] ?? null) !== 'group') {
                if ($isResync && array_key_exists('type', $data)) {
                    $this->removeGroupMemberVolumeVariables();
                }
                return;
            }
            if (!array_key_exists('group_members', $data)) {
                return;
            }
            $memberIds = $this->validateGroupMemberIds($data['group_members'], $groupPlayerId);
            if ($memberIds === null) {
                return;
            }
            sort($memberIds);
            $requestId = bin2hex(random_bytes(16));
            $this->SetBuffer('GroupMemberVolumeRefreshRequest', $requestId);
            $map = $this->readGroupMemberVolumeMap();
            if ($map === null) {
                return;
            }
            $knownMemberIds = array_column($map, 'player_id');
            sort($knownMemberIds);
            $sortedMemberIds = $memberIds;
            sort($sortedMemberIds);
            if ($isResync || $knownMemberIds !== $sortedMemberIds) {
                $this->refreshGroupMemberVolumes($memberIds, $isResync, $requestId);
            }
            return;
        }

        $map = $this->readGroupMemberVolumeMap();
        if ($map === null) {
            return;
        }
        foreach ($map as $ident => $entry) {
            if ($entry['player_id'] !== $objectId) {
                continue;
            }
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId !== $entry['variable_id']) {
                return;
            }
            $volume = $data['volume_level'] ?? null;
            if (is_int($volume) && $volume >= 0 && $volume <= 100
                && IPS_VariableExists($variableId)) {
                $this->SetValue($ident, $volume);
            }
            return;
        }
    }

    private function refreshGroupMemberVolumes(
        ?array $confirmedMemberIds = null,
        bool $forceRefresh = false,
        ?string $requestId = null
    ): void
    {
        $groupPlayerId = trim($this->ReadPropertyString('PlayerID'));
        if ($groupPlayerId === '' || !$this->HasActiveParent()) {
            return;
        }
        if ($requestId === null) {
            $requestId = bin2hex(random_bytes(16));
            $this->SetBuffer('GroupMemberVolumeRefreshRequest', $requestId);
        }
        $this->withGroupMemberVolumeLock(function () use (
            $groupPlayerId,
            $confirmedMemberIds,
            $forceRefresh,
            $requestId
        ): void {
            if ($this->GetBuffer('GroupMemberVolumeRefreshRequest') !== $requestId
                || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')
                || trim($this->ReadPropertyString('PlayerID')) !== $groupPlayerId
                || !$this->HasActiveParent()) {
                return;
            }
            if ($confirmedMemberIds !== null && !$forceRefresh) {
                $map = $this->readGroupMemberVolumeMap();
                if ($map === null) {
                    return;
                }
                $knownMemberIds = array_column($map, 'player_id');
                sort($knownMemberIds);
                $currentMemberIds = $confirmedMemberIds;
                sort($currentMemberIds);
                if ($knownMemberIds === $currentMemberIds) {
                    return;
                }
            }

            $players = $this->sendApiRequest('players/all', [
                'return_unavailable' => true,
                'return_disabled' => true
            ]);
            if (!is_array($players) || !$this->isList($players)) {
                throw new UnexpectedValueException('players/all lieferte keine vollständige Playerliste.');
            }

            $playersById = [];
            foreach ($players as $player) {
                if (!is_array($player) || !is_string($player['player_id'] ?? null)
                    || trim($player['player_id']) === '') {
                    continue;
                }
                $playersById[trim($player['player_id'])] = $player;
            }
            if ($this->GetBuffer('GroupMemberVolumeRefreshRequest') !== $requestId
                || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')
                || trim($this->ReadPropertyString('PlayerID')) !== $groupPlayerId
                || !$this->HasActiveParent()) {
                return;
            }

            if ($confirmedMemberIds === null) {
                if (!isset($playersById[$groupPlayerId])) {
                    throw new UnexpectedValueException('Der konfigurierte Player fehlt in players/all.');
                }
                $group = $playersById[$groupPlayerId];
                if (($group['type'] ?? null) !== 'group') {
                    $this->removeGroupMemberVolumeVariablesUnlocked();
                    return;
                }
                if (!array_key_exists('group_members', $group)) {
                    throw new UnexpectedValueException('Der Gruppensnapshot enthält keine vollständige Mitgliederliste.');
                }
                $memberIds = $this->validateGroupMemberIds($group['group_members'], $groupPlayerId);
                if ($memberIds === null) {
                    throw new UnexpectedValueException('Der Gruppensnapshot enthält ungültige Mitglieder-IDs.');
                }
            } else {
                $memberIds = $confirmedMemberIds;
            }

            $members = [];
            foreach ($memberIds as $memberId) {
                if (!isset($playersById[$memberId])) {
                    throw new UnexpectedValueException('Ein Gruppenmitglied fehlt in players/all.');
                }
                $member = $playersById[$memberId];
                $name = is_string($member['name'] ?? null) ? trim($member['name']) : '';
                $volume = $member['volume_level'] ?? null;
                if ($name === '' || ($volume !== null && (!is_int($volume) || $volume < 0 || $volume > 100))) {
                    throw new UnexpectedValueException('Ein Gruppenmitglied enthält unvollständige Playerdaten.');
                }
                $members[] = [
                    'player_id' => $memberId,
                    'name' => $name,
                    'volume_level' => $volume
                ];
            }
            if ($this->GetBuffer('GroupMemberVolumeRefreshRequest') !== $requestId
                || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')
                || trim($this->ReadPropertyString('PlayerID')) !== $groupPlayerId
                || !$this->HasActiveParent()) {
                return;
            }
            $this->reconcileGroupMemberVolumeVariables($members);
        });
    }

    private function validateGroupMemberIds($value, string $groupPlayerId): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $memberIds = [];
        foreach ($value as $memberId) {
            if (!is_string($memberId) || trim($memberId) === '') {
                return null;
            }
            $memberId = trim($memberId);
            if ($memberId !== $groupPlayerId) {
                $memberIds[$memberId] = true;
            }
        }
        return array_keys($memberIds);
    }

    private function reconcileGroupMemberVolumeVariables(array $members): void
    {
        $existingMap = $this->readGroupMemberVolumeMap();
        if ($existingMap === null) {
            throw new UnexpectedValueException('Die Zuordnung der Gruppenlautstärken ist ungültig.');
        }

        $desired = [];
        foreach ($members as $member) {
            $ident = $this->groupMemberVolumeIdent($member['player_id']);
            if (isset($desired[$ident])
                || (isset($existingMap[$ident]) && $existingMap[$ident]['player_id'] !== $member['player_id'])) {
                throw new UnexpectedValueException('Kollision bei der Variablenzuordnung für Gruppenmitglieder.');
            }
            $objectId = @$this->GetIDForIdent($ident);
            if (isset($existingMap[$ident]) && $objectId > 0
                && $objectId !== $existingMap[$ident]['variable_id']) {
                throw new UnexpectedValueException('Der Ident eines Gruppenlautstärkereglers wurde anderweitig belegt.');
            }
            if (!isset($existingMap[$ident]) && $objectId > 0) {
                throw new UnexpectedValueException('Der Ident eines Gruppenlautstärkereglers ist bereits belegt.');
            }
            $desired[$ident] = [
                'player_id' => $member['player_id'],
                'name' => $member['name'],
                'volume_level' => $member['volume_level']
            ];
        }

        $newMap = [];
        $position = 200;
        foreach ($desired as $ident => $member) {
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId <= 0) {
                $this->MaintainVariable(
                    $ident,
                    $member['name'],
                    VARIABLETYPE_INTEGER,
                    '~Volume',
                    $position,
                    true
                );
                $variableId = @$this->GetIDForIdent($ident);
            }
            if ($variableId <= 0 || !IPS_VariableExists($variableId)
                || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                throw new UnexpectedValueException('Gruppenlautstärkevariable konnte nicht sicher angelegt werden.');
            }

            if (isset($existingMap[$ident]) && IPS_GetName($variableId) === $existingMap[$ident]['name']
                && $existingMap[$ident]['name'] !== $member['name']) {
                IPS_SetName($variableId, $member['name']);
            }
            $this->EnableAction($ident);
            if (is_int($member['volume_level'])) {
                $this->SetValue($ident, $member['volume_level']);
            }
            $newMap[$ident] = [
                'player_id' => $member['player_id'],
                'name' => $member['name'],
                'variable_id' => $variableId
            ];
            $position += 10;
        }

        foreach ($existingMap as $ident => $entry) {
            if (isset($newMap[$ident])) {
                continue;
            }
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === $entry['variable_id'] && IPS_VariableExists($variableId)
                && (IPS_GetVariable($variableId)['VariableType'] ?? -1) === VARIABLETYPE_INTEGER) {
                $this->UnregisterVariable($ident);
            }
            if (@$this->GetIDForIdent($ident) > 0) {
                $newMap[$ident] = $entry;
            }
        }
        $this->writeGroupMemberVolumeMap($newMap);
    }

    private function removeGroupMemberVolumeVariables(): void
    {
        $requestId = bin2hex(random_bytes(16));
        $this->SetBuffer('GroupMemberVolumeRefreshRequest', $requestId);
        $this->withGroupMemberVolumeLock(function () use ($requestId): void {
            if ($this->GetBuffer('GroupMemberVolumeRefreshRequest') !== $requestId) {
                return;
            }
            $this->removeGroupMemberVolumeVariablesUnlocked();
        });
    }

    private function removeGroupMemberVolumeVariablesUnlocked(): void
    {
        $map = $this->readGroupMemberVolumeMap();
        if ($map === null) {
            return;
        }
        $remaining = [];
        foreach ($map as $ident => $entry) {
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === $entry['variable_id'] && IPS_VariableExists($variableId)
                && (IPS_GetVariable($variableId)['VariableType'] ?? -1) === VARIABLETYPE_INTEGER) {
                $this->UnregisterVariable($ident);
            }
            if (@$this->GetIDForIdent($ident) > 0) {
                $remaining[$ident] = $entry;
            }
        }
        if ($remaining !== $map) {
            $this->writeGroupMemberVolumeMap($remaining);
        }
    }

    private function withGroupMemberVolumeLock(callable $operation)
    {
        $semaphore = 'MA_GROUP_MEMBER_VOLUMES_' . (string)$this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, self::GROUP_MEMBER_VOLUME_SEMAPHORE_TIMEOUT)) {
            throw new RuntimeException('Gruppenlautstärke-Semaphore konnte nicht erworben werden.');
        }
        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function groupMemberVolumeIdent(string $playerId): string
    {
        return 'MemberVolume_' . hash('sha256', $playerId);
    }

    private function readGroupMemberVolumeMap(): ?array
    {
        $rawValue = trim($this->ReadAttributeString('GroupMemberVolumeMap'));
        try {
            $map = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($map)) {
            $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung hat einen ungültigen Wurzeltyp', 0);
            return null;
        }

        $playerIds = [];
        foreach ($map as $ident => $entry) {
            if (!is_string($ident) || !is_array($entry)
                || !is_string($entry['player_id'] ?? null) || trim($entry['player_id']) === ''
                || !is_string($entry['name'] ?? null) || trim($entry['name']) === ''
                || !is_int($entry['variable_id'] ?? null) || $entry['variable_id'] <= 0) {
                $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung hat eine ungültige Struktur', 0);
                return null;
            }
            $playerId = trim($entry['player_id']);
            if ($ident !== $this->groupMemberVolumeIdent($playerId) || isset($playerIds[$playerId])) {
                $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung ist nicht eindeutig', 0);
                return null;
            }
            $map[$ident] = [
                'player_id' => $playerId,
                'name' => trim($entry['name']),
                'variable_id' => $entry['variable_id']
            ];
            $playerIds[$playerId] = true;
        }
        return $map;
    }

    private function writeGroupMemberVolumeMap(array $map): void
    {
        if (!$this->WriteAttributeString(
            'GroupMemberVolumeMap',
            json_encode(count($map) === 0 ? new stdClass() : $map, JSON_THROW_ON_ERROR)
        )) {
            throw new RuntimeException('Die Zuordnung der Gruppenlautstärken konnte nicht gespeichert werden.');
        }
    }

    private function updateVolumeLevel($value): bool
    {
        if ((!is_int($value) && !is_float($value)) || @$this->GetIDForIdent('VolumeLevel') <= 0) {
            return false;
        }
        $volume = max(0, min(100, (int)$value));
        if ((int)$this->GetValue('VolumeLevel') === $volume) {
            return false;
        }
        $this->SetValue('VolumeLevel', $volume);
        return true;
    }

    private function updateMuteState($value): bool
    {
        if (!is_bool($value) || @$this->GetIDForIdent('Mute') <= 0
            || (bool)$this->GetValue('Mute') === $value) {
            return false;
        }
        $this->SetValue('Mute', $value);
        return true;
    }

    private function updateShuffleState($value): bool
    {
        if (!is_bool($value) || @$this->GetIDForIdent('Shuffle') <= 0
            || (bool)$this->GetValue('Shuffle') === $value) {
            return false;
        }
        $this->SetValue('Shuffle', $value);
        $this->SendDebug('Shuffle', 'Queue-State -> ' . ($value ? 'Ein' : 'Aus'), 0);
        return true;
    }

    private function updateRepeatMode($value): bool
    {
        $mode = is_string($value) ? strtolower(trim($value)) : '';
        if (!in_array($mode, ['off', 'one', 'all'], true)) {
            $this->SendDebug('Repeat', 'Unbekannter Repeat-Modus: ' . $mode, 0);
            return false;
        }
        if (@$this->GetIDForIdent('Repeat') <= 0 || (string)$this->GetValue('Repeat') === $mode) {
            return false;
        }
        $this->SetValue('Repeat', $mode);
        $captions = ['off' => 'Off', 'one' => 'One', 'all' => 'All'];
        $this->SendDebug('Repeat', 'Queue-State ' . $mode . ' -> ' . $captions[$mode], 0);
        return true;
    }

    private function updateTransportFromQueueState($state): bool
    {
        $normalizedState = is_string($state) ? strtolower(trim($state)) : '';
        $transportStates = ['idle' => 1, 'playing' => 2, 'paused' => 3];
        if (!isset($transportStates[$normalizedState])) {
            $this->SendDebug('Transport', 'Unbekannter Queue-State: ' . $normalizedState, 0);
            return false;
        }

        $transport = $transportStates[$normalizedState];
        if (@$this->GetIDForIdent('Transport') <= 0
            || (int)$this->GetValue('Transport') === $transport) {
            return false;
        }
        $this->SetValue('Transport', $transport);
        $captions = ['idle' => 'Stop', 'playing' => 'Play', 'paused' => 'Pause'];
        $this->SendDebug('Transport', 'Queue-State ' . $normalizedState . ' -> ' . $captions[$normalizedState], 0);
        return true;
    }

    private function updateMetadataFromQueueItem($currentItem): bool
    {
        if ($currentItem !== null && !is_array($currentItem)) {
            return false;
        }

        $title = $artist = $album = '';
        if (is_array($currentItem)) {
            $mediaItem = $currentItem['media_item'] ?? null;
            if (is_array($mediaItem)) {
                $title = (string)($mediaItem['name'] ?? '');
                if (isset($mediaItem['artists']) && is_array($mediaItem['artists'])
                    && count($mediaItem['artists']) > 0) {
                    $firstArtist = $mediaItem['artists'][0];
                    $artist = is_array($firstArtist)
                        ? (string)($firstArtist['name'] ?? '')
                        : (string)$firstArtist;
                }
                if (isset($mediaItem['album']) && is_array($mediaItem['album'])) {
                    $album = (string)($mediaItem['album']['name'] ?? '');
                }
            }
        }

        $changed = $this->setIfChangedString('NowTitle', $title);
        $changed = $this->setIfChangedString('NowArtist', $artist) || $changed;
        return $this->setIfChangedString('NowAlbum', $album) || $changed;
    }

    private function updateMetadataVisibilityFromQueueState($state): void
    {
        $normalizedState = is_string($state) ? strtolower(trim($state)) : '';
        if ($normalizedState === 'idle') {
            $this->updateMetadataFromQueueItem(null);
        }
    }

    private function updateCoverFromQueueItem($currentItem, $state): void
    {
        if ($currentItem === null) {
            $this->clearCover();
            return;
        }

        $proxyId = '';
        if (is_array($currentItem) && is_array($currentItem['image'] ?? null)
            && is_string($currentItem['image']['proxy_id'] ?? null)) {
            $candidate = trim($currentItem['image']['proxy_id']);
            if (preg_match('/^[a-f0-9]{64}$/D', $candidate) === 1) {
                $proxyId = $candidate;
            }
        }
        $mediaId = $this->ensureCoverMedia();
        if ($mediaId <= 0) {
            throw new Exception('Cover-Medium ist nicht verfügbar.');
        }
        if ($proxyId === '') {
            $this->showDefaultCover($mediaId, '', $state);
            return;
        }

        $needsDownload = $this->withCoverLock(function () use ($mediaId, $proxyId, $state): bool {
            if ($this->ReadAttributeString('CoverTargetProxyID') !== $proxyId) {
                if (!$this->WriteAttributeString('CoverTargetProxyID', $proxyId)) {
                    throw new Exception('Cover-Ziel konnte nicht gespeichert werden.');
                }
                $this->setDefaultCover($mediaId, $state);
            }
            if ($this->ReadAttributeString('CoverProxyID') === $proxyId
                && !$this->ReadAttributeBoolean('CoverIsDefault')
                && $this->coverMediaHasContent($mediaId)) {
                $this->updateCoverVisibilityFromQueueState($state, true);
                return false;
            }
            if (!$this->ReadAttributeBoolean('CoverIsDefault') || !$this->coverMediaHasContent($mediaId)) {
                $this->setDefaultCover($mediaId, $state);
            }
            return true;
        });
        if (!$needsDownload) {
            return;
        }

        $response = $this->requestArtwork($proxyId);
        $content = $response['content'] ?? null;
        $contentType = $response['content_type'] ?? null;
        if (($response['proxy_id'] ?? null) !== $proxyId
            || !in_array($contentType, ['image/jpeg', 'image/png'], true)
            || !is_string($content)) {
            throw new Exception('Artwork-Antwort ist ungültig.');
        }
        $binary = base64_decode($content, true);
        $validSignature = is_string($binary) && $binary !== '' && ($contentType === 'image/jpeg'
            ? substr($binary, 0, 3) === "\xFF\xD8\xFF"
            : substr($binary, 0, 8) === "\x89PNG\r\n\x1A\n");
        if (!$validSignature) {
            throw new Exception('Artwork-Signatur passt nicht zum Content-Type.');
        }
        $updated = $this->withCoverLock(function () use ($mediaId, $proxyId, $content, $contentType, $state): bool {
            if ($this->ReadAttributeString('CoverTargetProxyID') !== $proxyId) {
                return false;
            }
            $this->ensureCoverMediaFormat($mediaId, $contentType);
            if (!IPS_SetMediaContent($mediaId, $content)) {
                throw new Exception('Cover-Medium konnte nicht aktualisiert werden.');
            }
            if (!$this->WriteAttributeString('CoverProxyID', $proxyId)) {
                throw new Exception('Cover-Cache konnte nicht gespeichert werden.');
            }
            if (!$this->WriteAttributeBoolean('CoverIsDefault', false)) {
                throw new Exception('Cover-Status konnte nicht gespeichert werden.');
            }
            $this->updateCoverVisibilityFromQueueState($state, true);
            return true;
        });
        if ($updated) {
            $this->SendDebug('Artwork', 'Cover aktualisiert', 0);
        }
    }

    private function showDefaultCover(int $mediaId, string $targetProxyId, $state): void
    {
        $this->withCoverLock(function () use ($mediaId, $targetProxyId, $state): void {
            if ($this->ReadAttributeString('CoverTargetProxyID') !== $targetProxyId
                && !$this->WriteAttributeString('CoverTargetProxyID', $targetProxyId)) {
                throw new Exception('Cover-Ziel konnte nicht gespeichert werden.');
            }
            if (!$this->ReadAttributeBoolean('CoverIsDefault') || !$this->coverMediaHasContent($mediaId)) {
                $this->setDefaultCover($mediaId, $state);
                return;
            }
            $this->updateCoverVisibilityFromQueueState($state, true);
        });
    }

    private function setDefaultCover(int $mediaId, $state): void
    {
        $this->ensureCoverMediaFormat($mediaId, 'image/jpeg');
        if (!IPS_SetMediaContent($mediaId, self::DEFAULT_COVER)) {
            throw new Exception('Standard-Cover konnte nicht gesetzt werden.');
        }
        if (!$this->WriteAttributeBoolean('CoverIsDefault', true)) {
            throw new Exception('Cover-Status konnte nicht gespeichert werden.');
        }
        $this->updateCoverVisibilityFromQueueState($state, true);
    }

    private function ensureCoverMediaFormat(int $mediaId, string $contentType): void
    {
        $extension = $contentType === 'image/png' ? 'png' : 'jpg';
        $mediaFile = 'media/MusicAssistantCover_' . $this->InstanceID . '.' . $extension;
        $media = IPS_GetMedia($mediaId);
        $currentFile = str_replace('\\', '/', (string)($media['MediaFile'] ?? ''));
        $normalizedFile = ltrim($currentFile, '/');
        if ($normalizedFile === $mediaFile
            || substr($normalizedFile, -strlen('/' . $mediaFile)) === '/' . $mediaFile) {
            return;
        }
        if (!IPS_SetMediaFile($mediaId, $mediaFile, false)) {
            throw new Exception('Cover-Dateiformat konnte nicht aktualisiert werden.');
        }
    }

    private function updateCoverVisibilityFromQueueState($state, bool $hasCurrentItem = false): void
    {
        $normalizedState = is_string($state) ? strtolower(trim($state)) : '';
        if (!in_array($normalizedState, ['idle', 'playing', 'paused'], true)) {
            return;
        }

        $mediaId = $this->ensureCoverMedia();
        if ($mediaId <= 0) {
            return;
        }
        if ($normalizedState === 'idle') {
            if (!IPS_SetHidden($mediaId, true)) {
                throw new Exception('Cover-Medium konnte nicht ausgeblendet werden.');
            }
            return;
        }
        if (!$hasCurrentItem) {
            return;
        }
        if ($this->ReadAttributeString('CoverProxyID') !== ''
            && $this->coverMediaHasContent($mediaId)
            && !IPS_SetHidden($mediaId, false)) {
            throw new Exception('Cover-Medium konnte nicht eingeblendet werden.');
        }
    }

    private function requestArtwork(string $proxyId): array
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::CONNECTION_REQUEST,
            'Command' => 'ArtworkRequest',
            'ProxyID' => $proxyId
        ], JSON_THROW_ON_ERROR));
        if (!is_string($response) || trim($response) === '') {
            throw new Exception('ArtworkRequest lieferte keine gültige Antwort.');
        }
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            throw new Exception('ArtworkRequest ist fehlgeschlagen.');
        }
        return $result;
    }

    private function ensureCoverMedia(): int
    {
        $mediaId = @$this->GetIDForIdent('Cover');
        if ($mediaId > 0) {
            if (!IPS_MediaExists($mediaId)) {
                $this->SendDebug('Artwork', 'Ident Cover wird bereits von einem anderen Objekttyp verwendet', 0);
                return 0;
            }
            return $mediaId;
        }

        $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
        if ($mediaId <= 0) {
            $this->SendDebug('Artwork', 'Cover-Medium konnte nicht angelegt werden', 0);
            return 0;
        }
        $configured = IPS_SetParent($mediaId, $this->InstanceID);
        $configured = IPS_SetIdent($mediaId, 'Cover') && $configured;
        $configured = IPS_SetName($mediaId, 'Cover') && $configured;
        $configured = IPS_SetPosition($mediaId, 140) && $configured;
        $configured = IPS_SetMediaCached($mediaId, false) && $configured;
        $configured = IPS_SetMediaFile(
            $mediaId,
            'media/MusicAssistantCover_' . $this->InstanceID . '.jpg',
            false
        ) && $configured;
        $configured = IPS_SetHidden($mediaId, true) && $configured;
        if (!$configured) {
            $this->SendDebug('Artwork', 'Cover-Medium konnte nicht vollständig konfiguriert werden', 0);
            if (!IPS_DeleteMedia($mediaId, true)) {
                $this->SendDebug('Artwork', 'Unvollständiges Cover-Medium konnte nicht entfernt werden', 0);
            }
            return 0;
        }
        return $mediaId;
    }

    private function clearCover(): void
    {
        $mediaId = $this->ensureCoverMedia();
        if ($mediaId <= 0) {
            return;
        }
        $this->withCoverLock(function () use ($mediaId): void {
            $hadContent = $this->coverMediaHasContent($mediaId);
            $hadProxyId = $this->ReadAttributeString('CoverProxyID') !== '';
            if ($hadContent && !IPS_SetMediaContent($mediaId, '')) {
                throw new Exception('Cover-Medium konnte nicht geleert werden.');
            }
            if (!IPS_SetHidden($mediaId, true)) {
                throw new Exception('Cover-Medium konnte nicht ausgeblendet werden.');
            }
            if ($hadProxyId && !$this->WriteAttributeString('CoverProxyID', '')) {
                throw new Exception('Cover-Cache konnte nicht zurückgesetzt werden.');
            }
            if (!$this->WriteAttributeString('CoverTargetProxyID', '')
                || !$this->WriteAttributeBoolean('CoverIsDefault', false)) {
                throw new Exception('Cover-Status konnte nicht zurückgesetzt werden.');
            }
            if ($hadContent || $hadProxyId) {
                $this->SendDebug('Artwork', 'Cover geleert', 0);
            }
        });
    }

    private function withCoverLock(callable $operation)
    {
        $semaphore = 'MA_COVER_' . (string)$this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, self::COVER_SEMAPHORE_TIMEOUT)) {
            throw new Exception('Cover-Semaphore konnte nicht erworben werden.');
        }
        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function coverMediaHasContent(int $mediaId): bool
    {
        $media = IPS_GetMedia($mediaId);
        return is_array($media)
            && ($media['MediaIsAvailable'] ?? false) === true
            && (int)($media['MediaSize'] ?? 0) > 0;
    }

    private function setIfChangedString(string $ident, string $value): bool
    {
        if (@$this->GetIDForIdent($ident) <= 0) {
            return false;
        }
        if ((string)$this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
            return true;
        }
        return false;
    }

    private function registerParentStatusMessage(): void
    {
        $previousParentId = (int)$this->GetBuffer('ParentInstanceId');
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($previousParentId > 0 && $previousParentId !== $parentId) {
            $this->UnregisterMessage($previousParentId, IM_CHANGESTATUS);
        }
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, IM_CHANGESTATUS);
        }
        if ($previousParentId !== $parentId) {
            $this->SetBuffer('RegisteredPlayerID', '');
        }
        $this->SetBuffer('ParentInstanceId', (string)$parentId);
    }

    private function registerWithParent(): void
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if (!$this->HasActiveParent()) {
            return;
        }

        $registeredPlayerId = $this->GetBuffer('RegisteredPlayerID');
        if ($registeredPlayerId !== '' && $registeredPlayerId !== $playerId) {
            if (!$this->sendRegistrationRequest('UnregisterPlayer', $registeredPlayerId)) {
                return;
            }
            $this->SetBuffer('RegisteredPlayerID', '');
            $this->SendDebug('Registration', 'Abmeldung gesendet: ' . $registeredPlayerId, 0);
        }

        if ($playerId !== '' && $this->sendRegistrationRequest('RegisterPlayer', $playerId)) {
            $this->SetBuffer('RegisteredPlayerID', $playerId);
            $this->SendDebug('Registration', 'Registrierung gesendet: ' . $playerId, 0);
        }
    }

    private function sendRegistrationRequest(string $command, string $playerId): bool
    {
        try {
            $response = @$this->SendDataToParent(json_encode([
                'DataID' => self::CONNECTION_REQUEST,
                'Command' => $command,
                'PlayerID' => $playerId
            ], JSON_THROW_ON_ERROR));
            if (!is_string($response)) {
                return false;
            }
            $result = json_decode($response, true);
            return is_array($result) && ($result['success'] ?? false) === true;
        } catch (Throwable $e) {
            $this->SendDebug('Registration', 'Registrierungsanfrage konnte nicht gesendet werden', 0);
            return false;
        }
    }

    private function sendApiRequest(string $apiCommand, array $params = [])
    {
        try {
            $response = $this->SendDataToParent(json_encode([
                'DataID' => self::CONNECTION_REQUEST,
                'Command' => 'ApiRequest',
                'ApiCommand' => $apiCommand,
                'Params' => (object)$params
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            throw new Exception('ApiRequest konnte nicht erzeugt oder gesendet werden.');
        }

        if (!is_string($response) || trim($response) === '') {
            throw new Exception('ApiRequest lieferte keine gültige Antwort.');
        }

        try {
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new Exception('ApiRequest lieferte ungültiges JSON.');
        }
        if (!is_array($result)) {
            throw new Exception('ApiRequest-Antwort ist kein JSON-Objekt.');
        }
        if (($result['success'] ?? false) !== true) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : [];
            $code = is_string($error['code'] ?? null) && trim($error['code']) !== ''
                ? trim($error['code'])
                : 'UNKNOWN_ERROR';
            $message = is_string($error['message'] ?? null) && trim($error['message']) !== ''
                ? trim($error['message'])
                : 'ApiRequest ist fehlgeschlagen.';
            throw new Exception($code . ': ' . $message);
        }
        return $result['result'] ?? null;
    }

    // --------- MA Commands ---------

    public function SetShuffle(bool $enabled): void
    {
        $queueId = $this->getQueueIdForPlayer();
        $this->sendApiRequest('player_queues/shuffle', ['queue_id' => $queueId, 'shuffle_enabled' => $enabled]);
    }

    public function SetRepeat(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['off', 'one', 'all'], true)) {
            throw new InvalidArgumentException('Invalid repeat mode.');
        }

        $queueId = $this->getQueueIdForPlayer();
        $this->sendApiRequest('player_queues/repeat', ['queue_id' => $queueId, 'repeat_mode' => $mode]);
    }

    public function SetMute(bool $muted): void
    {
        $playerId = $this->playerId();

        try {
            $this->sendApiRequest('players/cmd/volume_mute', ['player_id' => $playerId, 'muted' => $muted]);
            return;
        } catch (Throwable $e) {
            if (str_starts_with($playerId, 'syncgroup_')) {
                $members = $this->getSyncGroupMembers($playerId);
                if (count($members) === 0) {
                    throw $e;
                }
                foreach ($members as $mid) {
                    try {
                        $this->sendApiRequest('players/cmd/volume_mute', ['player_id' => $mid, 'muted' => $muted]);
                    } catch (Throwable $inner) {
                        $this->SendDebug('Mute member failed', $mid . ': ' . $inner->getMessage(), 0);
                    }
                }
                return;
            }
            throw $e;
        }
    }

    public function SetVolumeLevel(int $level): void
    {
        if ($level < 0 || $level > 100) {
            throw new InvalidArgumentException('Volume level must be between 0 and 100.');
        }

        $this->sendApiRequest('players/cmd/volume_set', ['player_id' => $this->playerId(), 'volume_level' => $level]);
    }

    public function GroupVolumeUp(): void
    {
        $this->sendApiRequest('players/cmd/group_volume_up', ['player_id' => $this->playerId()]);
    }

    public function GroupVolumeDown(): void
    {
        $this->sendApiRequest('players/cmd/group_volume_down', ['player_id' => $this->playerId()]);
    }

    // --------- intern ---------

    private function ensureRepeatProfile(): bool
    {
        if (!IPS_VariableProfileExists(self::REPEAT_PROFILE)) {
            IPS_CreateVariableProfile(self::REPEAT_PROFILE, VARIABLETYPE_STRING);
        }
        if (!IPS_VariableProfileExists(self::REPEAT_PROFILE)) {
            $this->LogMessage('Variablenprofil ' . self::REPEAT_PROFILE . ' konnte nicht erstellt werden.', KL_ERROR);
            return false;
        }

        $profileData = IPS_GetVariableProfile(self::REPEAT_PROFILE);
        if (($profileData['ProfileType'] ?? -1) !== VARIABLETYPE_STRING) {
            $this->LogMessage(
                'Variablenprofil ' . self::REPEAT_PROFILE . ' hat nicht den erwarteten Typ String und wird nicht verändert.',
                KL_ERROR
            );
            return false;
        }

        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'off', 'Off', 'ban', -1);
        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'one', 'One', 'arrows-repeat-1', -1);
        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'all', 'All', 'arrows-repeat', -1);
        return true;
    }

    private function ensureVolumeButtonProfiles(): array
    {
        return [
            self::VOLUP_PROFILE   => $this->ensureIntButtonProfile(self::VOLUP_PROFILE, 'Up', 'volume-up'),
            self::VOLDOWN_PROFILE => $this->ensureIntButtonProfile(self::VOLDOWN_PROFILE, 'Down', 'volume-down')
        ];
    }

    private function ensureIntButtonProfile(string $profile, string $caption, string $icon): bool
    {
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
        }
        if (!IPS_VariableProfileExists($profile)) {
            $this->LogMessage('Variablenprofil ' . $profile . ' konnte nicht erstellt werden.', KL_ERROR);
            return false;
        }

        $profileData = IPS_GetVariableProfile($profile);
        if (($profileData['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            $this->LogMessage(
                'Variablenprofil ' . $profile . ' hat nicht den erwarteten Typ Integer und wird nicht verändert.',
                KL_ERROR
            );
            return false;
        }
        foreach (($profileData['Associations'] ?? []) as $association) {
            if (isset($association['Value']) && (float)$association['Value'] === 0.0) {
                IPS_SetVariableProfileAssociation($profile, 0, '', '', -1);
                break;
            }
        }

        IPS_SetVariableProfileAssociation($profile, 1, $caption, $icon, -1);
        return true;
    }

    private function ExecuteTransport(int $value): void
    {
        $pid = $this->playerId();
        switch ($value) {
            case 0: $this->sendApiRequest('players/cmd/previous', ['player_id' => $pid]); break;
            case 1: $this->sendApiRequest('players/cmd/stop',     ['player_id' => $pid]); break;
            case 2: $this->sendApiRequest('players/cmd/play',     ['player_id' => $pid]); break;
            case 3: $this->sendApiRequest('players/cmd/pause',    ['player_id' => $pid]); break;
            case 4: $this->sendApiRequest('players/cmd/next',     ['player_id' => $pid]); break;
            default: break;
        }
    }

    private function getQueueIdForPlayer(): string
    {
        $queue = $this->getActiveQueueForPlayer();
        return trim($queue['queue_id']);
    }

    private function getActiveQueueForPlayer(): array
    {
        $playerId = $this->playerId();

        $result = $this->sendApiRequest(
            'player_queues/get_active_queue',
            ['player_id' => $playerId]
        );
        $queueId = is_array($result) && is_string($result['queue_id'] ?? null)
            ? trim($result['queue_id'])
            : '';

        if ($queueId === '') {
            throw new Exception('No valid active queue found for PlayerID=' . $playerId);
        }

        return $result;
    }

    private function ensureIntegerSelectorVariable(string $ident, string $name, string $profile, int $pos): bool
    {
        $existingId = @$this->GetIDForIdent($ident);
        $hadIntegerVariable = false;
        if ($existingId > 0) {
            $v = IPS_GetVariable($existingId);
            $hadIntegerVariable = ($v['VariableType'] ?? -1) === VARIABLETYPE_INTEGER;
            if (!$hadIntegerVariable) {
                IPS_DeleteVariable($existingId);
            }
        }

        $profile = trim($profile);
        $validProfile = '';
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            $pp = IPS_GetVariableProfile($profile);
            if (($pp['ProfileType'] ?? -1) === VARIABLETYPE_INTEGER) {
                $validProfile = $profile;
            }
        }

        $this->MaintainVariable($ident, $name, VARIABLETYPE_INTEGER, $validProfile, $pos, true);
        $this->EnableAction($ident);

        $variableId = @$this->GetIDForIdent($ident);
        return !$hadIntegerVariable
            && $variableId > 0
            && IPS_VariableExists($variableId)
            && (IPS_GetVariable($variableId)['VariableType'] ?? -1) === VARIABLETYPE_INTEGER
            && ($existingId <= 0 || $variableId !== $existingId);
    }

    private function resolveUriFromMap(int $itemId, string $mapProperty, string $refreshCommand): string
    {
        if ($itemId <= 0) {
            throw new Exception('Invalid item_id');
        }

        $mapRaw = trim($this->ReadPropertyString($mapProperty));
        $map = json_decode($mapRaw, true);
        if (!is_array($map)) {
            $map = [];
        }

        $key = (string)$itemId;
        if (isset($map[$key]) && is_string($map[$key]) && $map[$key] !== '') {
            return $map[$key];
        }

        $result = $this->sendApiRequest($refreshCommand);

        if (is_array($result) && $this->isList($result)) {
            foreach ($result as $it) {
                $idStr = (string)($it['item_id'] ?? '');
                $id    = (int)$idStr;
                $uri   = (string)($it['uri'] ?? '');
                if ($id > 0 && $uri !== '') {
                    $map[(string)$id] = $uri;
                }
            }
            if (isset($map[$key])) {
                return $map[$key];
            }
        }

        throw new Exception('URI not found for item_id=' . $itemId);
    }

    private function PlayMediaUri(string $uri): void
    {
        if ($uri === '') {
            throw new Exception('Media URI is empty');
        }

        $queueId = $this->getQueueIdForPlayer();
        $this->sendApiRequest('player_queues/play_media', [
            'queue_id' => $queueId,
            'media'    => $uri,
            'option'   => 'replace'
        ]);
    }

    private function resolvePlaylistFromMap(int $itemId): array
    {
        if ($itemId <= 0) {
            throw new Exception('Invalid item_id');
        }

        $map = json_decode(trim($this->ReadPropertyString('PlaylistMap')), true);
        if (!is_array($map)) {
            $map = [];
        }

        $key = (string)$itemId;
        if (isset($map[$key])) {
            $entry = $this->normalizePlaylistMapEntry($map[$key]);
            if ($entry !== null) {
                return $entry;
            }
        }

        $result = $this->sendApiRequest('music/playlists/library_items', ['summary' => false]);
        if (is_array($result) && $this->isList($result)) {
            foreach ($result as $playlist) {
                if (!is_array($playlist) || (int)($playlist['item_id'] ?? 0) !== $itemId) {
                    continue;
                }
                $entry = $this->normalizePlaylistMapEntry([
                    'uri'               => $playlist['uri'] ?? null,
                    'provider_mappings' => $playlist['provider_mappings'] ?? []
                ]);
                if ($entry !== null) {
                    return $entry;
                }
            }
        }

        throw new Exception('URI not found for item_id=' . $itemId);
    }

    private function normalizePlaylistMapEntry($entry): ?array
    {
        if (is_string($entry) && trim($entry) !== '') {
            return ['uri' => trim($entry), 'provider_mappings' => []];
        }
        if (!is_array($entry) || !is_string($entry['uri'] ?? null) || trim($entry['uri']) === '') {
            return null;
        }

        return [
            'uri'               => trim($entry['uri']),
            'provider_mappings' => is_array($entry['provider_mappings'] ?? null)
                ? $entry['provider_mappings']
                : []
        ];
    }

    private function PlayPlaylist(array $playlist): void
    {
        if (!$this->isBuiltinRandomTracksPlaylist($playlist)) {
            $this->PlayMediaUri($playlist['uri']);
            return;
        }

        $tracks = $this->sendApiRequest('music/playlists/playlist_tracks', [
            'item_id'                       => 'random_tracks',
            'provider_instance_id_or_domain' => 'builtin',
            'force_refresh'                 => true
        ]);
        if (!is_array($tracks) || !$this->isList($tracks) || count($tracks) === 0) {
            throw new Exception('Refresh of builtin random_tracks returned no complete track list.');
        }

        $trackUris = [];
        foreach ($tracks as $track) {
            if (!is_array($track) || !is_string($track['uri'] ?? null) || trim($track['uri']) === '') {
                throw new Exception('Refresh of builtin random_tracks returned an invalid track.');
            }
            $trackUris[] = trim($track['uri']);
        }

        $queueId = $this->getQueueIdForPlayer();
        $this->sendApiRequest('player_queues/play_media', [
            'queue_id' => $queueId,
            'media'    => $trackUris,
            'option'   => 'replace'
        ]);
    }

    private function isBuiltinRandomTracksPlaylist(array $playlist): bool
    {
        foreach ($playlist['provider_mappings'] as $mapping) {
            if (!is_array($mapping) || ($mapping['item_id'] ?? null) !== 'random_tracks') {
                continue;
            }
            if (($mapping['provider_instance'] ?? null) === 'builtin'
                && ($mapping['provider_domain'] ?? null) === 'builtin') {
                return true;
            }
        }
        return false;
    }

    private function playerId(): string
    {
        $id = trim($this->ReadPropertyString('PlayerID'));
        if ($id === '') {
            throw new Exception('PlayerID not configured');
        }
        return $id;
    }

    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function getSyncGroupMembers(string $syncGroupId): array
    {
        $result = $this->sendApiRequest('players/all');

        if (!is_array($result) || !$this->isList($result)) {
            return [];
        }

        foreach ($result as $p) {
            $pid = (string)($p['player_id'] ?? $p['id'] ?? '');
            if ($pid !== $syncGroupId) {
                continue;
            }

            $candidates = [
                $p['group_members'] ?? null,
                $p['members'] ?? null,
                $p['players'] ?? null,
                $p['child_player_ids'] ?? null,
                $p['group_childs'] ?? null
            ];

            foreach ($candidates as $cand) {
                if (!is_array($cand)) {
                    continue;
                }

                $out = [];
                foreach ($cand as $x) {
                    if (is_string($x) && $x !== '') {
                        $out[] = $x;
                    } elseif (is_array($x)) {
                        $id = (string)($x['player_id'] ?? $x['id'] ?? '');
                        if ($id !== '') {
                            $out[] = $id;
                        }
                    }
                }

                return array_values(array_unique($out));
            }

            return [];
        }

        return [];
    }
}
