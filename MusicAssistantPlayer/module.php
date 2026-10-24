<?php
declare(strict_types=1);

class MusicAssistantPlayer extends IPSModule
{
    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';
    private const REPEAT_PROFILE  = 'MA.RepeatMode';
    private const VOLUP_PROFILE   = 'MA.VolumeUp';
    private const VOLDOWN_PROFILE = 'MA.VolumeDown';
    private const FAVORITE_PROFILE = 'MA.Favorite';
    private const COVER_SEMAPHORE_TIMEOUT = 5000;
    private const GROUP_MEMBER_VOLUME_SEMAPHORE_TIMEOUT = 40000;
    private const FAVORITE_SEMAPHORE_TIMEOUT = 20000;
    private const TILE_COVER_MAX_BYTES = 524288;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);

        $this->RegisterPropertyString('PlayerID', '');
        $this->RegisterPropertyInteger('InitialTargetCategoryID', 0);
        $this->RegisterPropertyBoolean('ShowGroupMemberVolumes', false);
        $this->RegisterPropertyBoolean('ShowFavorite', false);
        $this->RegisterPropertyBoolean('EnableCustomTile', false);

        $this->RegisterPropertyString('PlaylistProfile', '');
        $this->RegisterPropertyString('PlaylistMap', '{}');

        $this->RegisterPropertyString('RadioProfile', '');
        $this->RegisterPropertyString('RadioMap', '{}');

        $this->RegisterAttributeString('CoverProxyID', '');
        $this->RegisterAttributeString('CoverTargetProxyID', '');
        $this->RegisterAttributeBoolean('CoverIsDefault', false);
        $this->RegisterAttributeString('GroupMemberVolumeMap', '{}');
        $this->RegisterAttributeBoolean('InitialPlacementCompleted', false);

        $this->SetBuffer('ParentInstanceId', '0');
        $this->SetBuffer('RegisteredPlayerID', '');
        $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
        $this->SetBuffer('GroupMemberVolumeRefreshGeneration', '0');
        $this->SetBuffer('RunningGroupMemberVolumeRefresh', '');
        $this->SetBuffer('GroupMemberVolumeUpdateGeneration', '0');
        $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
        $this->SetBuffer('KnownPlayerTypeID', '');
        $this->SetBuffer('KnownPlayerType', '');
        $this->SetBuffer('CurrentFavoriteTrack', '{}');
        $this->SetBuffer('PendingFavoriteResolution', '{}');
        $this->SetBuffer('FavoriteResolutionGeneration', '0');
        $this->SetBuffer('RunningFavoriteResolution', '');
        $this->SetBuffer('PendingCoverDownload', '{}');
        $this->SetBuffer('CoverDownloadGeneration', '0');
        $this->SetBuffer('RunningCoverDownload', '');

        $this->RegisterTimer('ResetPlaylist', 0, 'MASS_ResetPlaylistSelection($_IPS["TARGET"]);');
        $this->RegisterTimer('ResetRadio', 0, 'MASS_ResetRadioSelection($_IPS["TARGET"]);');
        $this->RegisterTimer('ResolveFavorite', 0, 'MASS_ProcessFavoriteResolution($_IPS["TARGET"]);');
        $this->RegisterTimer('DownloadCover', 0, 'MASS_ProcessCoverDownload($_IPS["TARGET"]);');
        $this->RegisterTimer('RefreshGroupMemberVolumes', 0, 'MASS_ProcessGroupMemberVolumeRefresh($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->applyInitialPlacement();
        $this->SetTimerInterval('ResolveFavorite', 0);
        try {
            $this->withFavoriteLock(function (): void {
                $this->invalidatePendingFavoriteResolutionUnlocked();
            });
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Ausstehende Auflösung konnte nicht verworfen werden: ' . $e->getMessage(), 0);
        }
        $this->SetTimerInterval('DownloadCover', 0);
        try {
            $this->withCoverLock(function (): void {
                $this->invalidatePendingCoverDownloadUnlocked();
            });
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Ausstehender Download konnte nicht verworfen werden: ' . $e->getMessage(), 0);
        }
        $this->SetTimerInterval('RefreshGroupMemberVolumes', 0);
        try {
            $this->withGroupMemberVolumeLock(function (): void {
                $this->invalidatePendingGroupMemberVolumeRefreshUnlocked();
                $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
            });
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Ausstehende Aktualisierung konnte nicht verworfen werden: ' . $e->getMessage(), 0);
        }
        $this->configureVisualizationType();
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
        $favoriteId = @$this->GetIDForIdent('Favorite');
        $hadFavoriteVariable = $favoriteId > 0
            && IPS_VariableExists($favoriteId)
            && (IPS_GetVariable($favoriteId)['VariableType'] ?? -1) === VARIABLETYPE_BOOLEAN;
        $showFavorite = $this->ReadPropertyBoolean('ShowFavorite');
        $favoritePresentation = '~Switch';
        if (defined('VARIABLE_PRESENTATION_SWITCH')) {
            $favoritePresentation = [
                'PRESENTATION'   => constant('VARIABLE_PRESENTATION_SWITCH'),
                'USE_ICON_FALSE' => false,
                'ICON_TRUE'      => 'heart',
                'GLOW_INTENSITY' => 30,
                'USAGE_TYPE'     => 2
            ];
        } elseif ($showFavorite && $this->ensureFavoriteProfile()) {
            $favoritePresentation = self::FAVORITE_PROFILE;
        }
        $this->MaintainVariable('Favorite', 'Favorit', VARIABLETYPE_BOOLEAN, $favoritePresentation, 140, $showFavorite);
        if ($showFavorite) {
            $favoriteId = @$this->GetIDForIdent('Favorite');
            $favoriteCreated = !$hadFavoriteVariable
                && $favoriteId > 0
                && IPS_VariableExists($favoriteId)
                && (IPS_GetVariable($favoriteId)['VariableType'] ?? -1) === VARIABLETYPE_BOOLEAN;
            if ($favoriteCreated) {
                IPS_SetIcon($favoriteId, 'Heart');
            }
            $this->EnableAction('Favorite');
        } else {
            $this->clearCurrentFavoriteTrack();
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

    private function applyInitialPlacement(): void
    {
        if ($this->ReadAttributeBoolean('InitialPlacementCompleted')) {
            return;
        }

        $targetCategoryId = $this->ReadPropertyInteger('InitialTargetCategoryID');
        if ($targetCategoryId <= 0) {
            $this->WriteAttributeBoolean('InitialPlacementCompleted', true);
            return;
        }
        if (!IPS_CategoryExists($targetCategoryId)) {
            $message = 'Initiale Zielkategorie ' . $targetCategoryId . ' ist nicht vorhanden.';
            $this->SendDebug('Initial placement', $message, 0);
            $this->LogMessage($message, KL_ERROR);
            return;
        }
        if (!IPS_SetParent($this->InstanceID, $targetCategoryId)) {
            $message = 'Player konnte nicht in die initiale Zielkategorie ' . $targetCategoryId . ' verschoben werden.';
            $this->SendDebug('Initial placement', $message, 0);
            $this->LogMessage($message, KL_ERROR);
            return;
        }

        $this->WriteAttributeBoolean('InitialPlacementCompleted', true);
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
                    'name' => 'ShowFavorite',
                    'caption' => 'Favoritensteuerung für den aktuellen Titel anzeigen'
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'EnableCustomTile',
                    'caption' => 'Eigene Player-Kachel in der Tile Visu anzeigen (ab IP-Symcon 7.1)'
                ],
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
        if (($packet['Event'] ?? null) === 'media_item_updated') {
            if ($this->ReadPropertyBoolean('ShowFavorite')) {
                try {
                    $this->processFavoriteMediaItemUpdate($packet);
                } catch (Throwable $e) {
                    $this->SendDebug('Favorite', 'Medienaktualisierung konnte nicht verarbeitet werden: ' . $e->getMessage(), 0);
                }
                $this->updateVisualization();
            }
            return '';
        }
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
                if ($this->ReadPropertyBoolean('ShowFavorite') && ($isIdle || $hasCurrentItem)) {
                    try {
                        $this->updateFavoriteFromQueueItem($isIdle ? null : $data['current_item']);
                    } catch (Throwable $e) {
                        try {
                            $this->clearCurrentFavoriteTrack();
                        } catch (Throwable $clearError) {
                            $this->SendDebug(
                                'Favorite',
                                'Favoritenstatus konnte nach einem Fehler nicht zurückgesetzt werden: '
                                    . $clearError->getMessage(),
                                0
                            );
                        }
                        $this->SendDebug('Favorite', 'Titel konnte nicht aufgelöst werden: ' . $e->getMessage(), 0);
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
                $this->updateVisualization();
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
        $this->updateVisualization();
        return '';
    }

    public function GetVisualizationTile(): string
    {
        $template = @file_get_contents(__DIR__ . '/module.html');
        if (!is_string($template)) {
            $this->SendDebug('Visualization', 'HTML-Vorlage konnte nicht geladen werden', 0);
            return '';
        }

        $this->SetBuffer('VisualizationStateHash', '');
        try {
            $state = $this->buildVisualizationState();
            $initialState = json_encode(
                $state,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Initialzustand konnte nicht erstellt werden: ' . $e->getMessage(), 0);
            $initialState = '{"cover":"","title":"","artist":"","status":"unknown"}';
        }

        return str_replace('__MUSIC_ASSISTANT_INITIAL_STATE__', $initialState, $template);
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

            case 'Favorite':
                try {
                    $this->setCurrentTrackFavorite((bool)$Value);
                    $this->updateVisualization();
                } catch (Throwable $e) {
                    $this->SendDebug('Favorite failed', $e->getMessage(), 0);
                    $this->LogMessage('Favorite failed: ' . $e->getMessage(), KL_ERROR);
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

    public function ProcessFavoriteResolution(): void
    {
        $this->SetTimerInterval('ResolveFavorite', 0);

        try {
            $job = $this->withFavoriteLock(function (): ?array {
                if ($this->GetBuffer('RunningFavoriteResolution') !== '') {
                    return null;
                }
                $pending = $this->readPendingFavoriteResolution();
                if ($pending === null) {
                    return null;
                }
                $this->SetBuffer('RunningFavoriteResolution', (string)$pending['generation']);
                return $pending;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Auflösungsauftrag konnte nicht gestartet werden: ' . $e->getMessage(), 0);
            return;
        }
        if ($job === null) {
            return;
        }

        $updateVisualization = false;
        try {
            if ($this->GetStatus() !== IS_ACTIVE
                || !$this->ReadPropertyBoolean('ShowFavorite')
                || !$this->HasActiveParent()
                || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                return;
            }

            $resolvedState = $this->resolveFavoriteLibraryState($job['state']);
            $updateVisualization = $this->withFavoriteLock(function () use ($job, $resolvedState): bool {
                $pending = $this->readPendingFavoriteResolution();
                $currentState = $this->readCurrentFavoriteTrack();
                if ($pending === null
                    || $pending['player_id'] !== $job['player_id']
                    || $pending['generation'] !== $job['generation']
                    || $this->GetStatus() !== IS_ACTIVE
                    || !$this->ReadPropertyBoolean('ShowFavorite')
                    || !$this->HasActiveParent()
                    || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']
                    || !$this->favoriteStatesIdentifySameTrack($currentState, $job['state'])) {
                    return false;
                }
                $this->SetBuffer('PendingFavoriteResolution', '{}');
                $this->writeCurrentFavoriteTrack($resolvedState);
                $this->updateFavoriteValue($resolvedState['favorite']);
                return true;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Library-Track konnte nicht aufgelöst werden: ' . $e->getMessage(), 0);
        } finally {
            try {
                $restart = $this->withFavoriteLock(function () use ($job): bool {
                    $pending = $this->readPendingFavoriteResolution();
                    if ($pending !== null && $pending['generation'] === $job['generation']) {
                        $this->SetBuffer('PendingFavoriteResolution', '{}');
                        $pending = null;
                    }
                    if ($this->GetBuffer('RunningFavoriteResolution') === (string)$job['generation']) {
                        $this->SetBuffer('RunningFavoriteResolution', '');
                    }
                    return $pending !== null;
                });
                if ($restart) {
                    $this->SetTimerInterval('ResolveFavorite', 100);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Favorite', 'Auflösungsauftrag konnte nicht abgeschlossen werden: ' . $e->getMessage(), 0);
            }
        }

        if ($updateVisualization) {
            $this->updateVisualization();
        }
    }

    public function ProcessCoverDownload(): void
    {
        $this->SetTimerInterval('DownloadCover', 0);

        try {
            $job = $this->withCoverLock(function (): ?array {
                if ($this->GetBuffer('RunningCoverDownload') !== '') {
                    return null;
                }
                $pending = $this->readPendingCoverDownload();
                if ($pending === null
                    || $this->ReadAttributeString('CoverTargetProxyID') !== $pending['proxy_id']) {
                    return null;
                }
                $this->SetBuffer('RunningCoverDownload', (string)$pending['generation']);
                return $pending;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Cover-Download konnte nicht gestartet werden: ' . $e->getMessage(), 0);
            return;
        }
        if ($job === null) {
            return;
        }

        $updateVisualization = false;
        try {
            if ($this->GetStatus() !== IS_ACTIVE
                || !$this->HasActiveParent()
                || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                return;
            }

            $response = $this->requestArtwork($job['proxy_id']);
            $content = $response['content'] ?? null;
            $contentType = $response['content_type'] ?? null;
            if (($response['proxy_id'] ?? null) !== $job['proxy_id']
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

            $updateVisualization = $this->withCoverLock(function () use ($job, $content, $contentType): bool {
                $pending = $this->readPendingCoverDownload();
                if ($pending === null
                    || $pending['generation'] !== $job['generation']
                    || $pending['player_id'] !== $job['player_id']
                    || $pending['proxy_id'] !== $job['proxy_id']
                    || $this->GetStatus() !== IS_ACTIVE
                    || !$this->HasActiveParent()
                    || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']
                    || $this->ReadAttributeString('CoverTargetProxyID') !== $job['proxy_id']) {
                    return false;
                }
                $mediaId = $this->ensureCoverMedia();
                if ($mediaId <= 0) {
                    throw new Exception('Cover-Medium ist nicht verfügbar.');
                }
                $this->ensureCoverMediaFormat($mediaId, $contentType);
                if (!IPS_SetMediaContent($mediaId, $content)) {
                    throw new Exception('Cover-Medium konnte nicht aktualisiert werden.');
                }
                if (!$this->WriteAttributeString('CoverProxyID', $job['proxy_id'])) {
                    throw new Exception('Cover-Cache konnte nicht gespeichert werden.');
                }
                if (!$this->WriteAttributeBoolean('CoverIsDefault', false)) {
                    throw new Exception('Cover-Status konnte nicht gespeichert werden.');
                }
                $this->updateCoverVisibilityFromQueueState($job['state'], true);
                $this->SetBuffer('PendingCoverDownload', '{}');
                return true;
            });
            if ($updateVisualization) {
                $this->SendDebug('Artwork', 'Cover aktualisiert', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Cover konnte nicht aktualisiert werden: ' . $e->getMessage(), 0);
        } finally {
            try {
                $restart = $this->withCoverLock(function () use ($job): bool {
                    $pending = $this->readPendingCoverDownload();
                    if ($pending !== null && $pending['generation'] === $job['generation']) {
                        $this->SetBuffer('PendingCoverDownload', '{}');
                        $pending = null;
                    }
                    if ($this->GetBuffer('RunningCoverDownload') === (string)$job['generation']) {
                        $this->SetBuffer('RunningCoverDownload', '');
                    }
                    return $pending !== null;
                });
                if ($restart) {
                    $this->SetTimerInterval('DownloadCover', 100);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Artwork', 'Cover-Download konnte nicht abgeschlossen werden: ' . $e->getMessage(), 0);
            }
        }

        if ($updateVisualization) {
            $this->updateVisualization();
        }
    }

    public function ProcessGroupMemberVolumeRefresh(): void
    {
        $this->SetTimerInterval('RefreshGroupMemberVolumes', 0);

        try {
            $job = $this->withGroupMemberVolumeLock(function (): ?array {
                if ($this->GetBuffer('RunningGroupMemberVolumeRefresh') !== '') {
                    return null;
                }
                $pending = $this->readPendingGroupMemberVolumeRefresh();
                if ($pending === null) {
                    return null;
                }
                $this->SetBuffer('RunningGroupMemberVolumeRefresh', (string)$pending['generation']);
                return $pending;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Aktualisierung konnte nicht gestartet werden: ' . $e->getMessage(), 0);
            return;
        }
        if ($job === null) {
            return;
        }

        $updated = false;
        try {
            if ($this->GetStatus() !== IS_ACTIVE
                || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')
                || !$this->HasActiveParent()
                || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                return;
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

            $updated = $this->withGroupMemberVolumeLock(function () use ($job, $playersById): bool {
                $pending = $this->readPendingGroupMemberVolumeRefresh();
                if ($pending === null
                    || $pending['generation'] !== $job['generation']
                    || $pending['player_id'] !== $job['player_id']
                    || $pending['member_ids'] !== $job['member_ids']
                    || $this->GetStatus() !== IS_ACTIVE
                    || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')
                    || !$this->HasActiveParent()
                    || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                    return false;
                }

                if ($job['member_ids'] === null) {
                    if (!isset($playersById[$job['player_id']])) {
                        throw new UnexpectedValueException('Der konfigurierte Player fehlt in players/all.');
                    }
                    $group = $playersById[$job['player_id']];
                    if (($group['type'] ?? null) !== 'group') {
                        $changed = $this->removeGroupMemberVolumeVariablesUnlocked();
                        $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
                        $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
                        return $changed;
                    }
                    if (!array_key_exists('group_members', $group)) {
                        throw new UnexpectedValueException('Der Gruppensnapshot enthält keine vollständige Mitgliederliste.');
                    }
                    $memberIds = $this->validateGroupMemberIds($group['group_members'], $job['player_id']);
                    if ($memberIds === null) {
                        throw new UnexpectedValueException('Der Gruppensnapshot enthält ungültige Mitglieder-IDs.');
                    }
                } else {
                    $memberIds = $job['member_ids'];
                }

                $volumeUpdates = $this->readGroupMemberVolumeUpdates();
                $members = [];
                foreach ($memberIds as $memberId) {
                    if (!isset($playersById[$memberId])) {
                        throw new UnexpectedValueException('Ein Gruppenmitglied fehlt in players/all.');
                    }
                    $member = $playersById[$memberId];
                    $name = is_string($member['name'] ?? null) ? trim($member['name']) : '';
                    $volume = $member['volume_level'] ?? null;
                    if (isset($volumeUpdates[$memberId])
                        && $volumeUpdates[$memberId]['generation'] > $job['volume_generation']) {
                        $volume = $volumeUpdates[$memberId]['volume_level'];
                    }
                    if ($name === '' || ($volume !== null && (!is_int($volume) || $volume < 0 || $volume > 100))) {
                        throw new UnexpectedValueException('Ein Gruppenmitglied enthält unvollständige Playerdaten.');
                    }
                    $members[] = [
                        'player_id' => $memberId,
                        'name' => $name,
                        'volume_level' => $volume
                    ];
                }
                $this->reconcileGroupMemberVolumeVariables($members);
                $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
                $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
                return true;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Aktualisierung fehlgeschlagen: ' . $e->getMessage(), 0);
        } finally {
            try {
                $restart = $this->withGroupMemberVolumeLock(function () use ($job): bool {
                    $pending = $this->readPendingGroupMemberVolumeRefresh();
                    if ($pending !== null && $pending['generation'] === $job['generation']) {
                        $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
                        $pending = null;
                    }
                    if ($this->GetBuffer('RunningGroupMemberVolumeRefresh') === (string)$job['generation']) {
                        $this->SetBuffer('RunningGroupMemberVolumeRefresh', '');
                    }
                    return $pending !== null;
                });
                if ($restart) {
                    $this->SetTimerInterval('RefreshGroupMemberVolumes', 100);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Group member volumes', 'Aktualisierung konnte nicht abgeschlossen werden: ' . $e->getMessage(), 0);
            }
        }

        if ($updated) {
            $this->updateVisualization();
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
        $playerId = $this->withGroupMemberVolumeLock(function () use ($ident, $value): ?string {
            if (!$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
                return null;
            }
            $map = $this->readGroupMemberVolumeMap();
            if ($map === null || !isset($map[$ident])) {
                return null;
            }
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId !== $map[$ident]['variable_id'] || !IPS_VariableExists($variableId)
                || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                return null;
            }
            if (!is_int($value) || $value < 0 || $value > 100) {
                throw new InvalidArgumentException('Group member volume must be between 0 and 100.');
            }
            return $map[$ident]['player_id'];
        });
        if ($playerId === null) {
            return false;
        }

        $this->sendApiRequest('players/cmd/volume_set', [
            'player_id' => $playerId,
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
            $schedule = $this->withGroupMemberVolumeLock(function () use ($memberIds, $isResync): bool {
                $map = $this->readGroupMemberVolumeMap();
                if ($map === null) {
                    return false;
                }
                $knownMemberIds = array_column($map, 'player_id');
                sort($knownMemberIds);
                if (!$isResync && $knownMemberIds === $memberIds) {
                    $pending = $this->readPendingGroupMemberVolumeRefresh();
                    if ($pending !== null && $pending['member_ids'] !== null
                        && $pending['member_ids'] !== $memberIds) {
                        $this->invalidatePendingGroupMemberVolumeRefreshUnlocked();
                    }
                    return false;
                }
                return $this->queueGroupMemberVolumeRefreshUnlocked($memberIds, $isResync);
            });
            if ($schedule) {
                $this->SetTimerInterval('RefreshGroupMemberVolumes', 100);
            }
            return;
        }

        $updated = $this->withGroupMemberVolumeLock(function () use ($objectId, $data): bool {
            $map = $this->readGroupMemberVolumeMap();
            if ($map === null) {
                return false;
            }
            $volume = $data['volume_level'] ?? null;
            if (!is_int($volume) || $volume < 0 || $volume > 100) {
                return false;
            }
            $pending = $this->readPendingGroupMemberVolumeRefresh();
            $isPendingMember = $pending !== null
                && ($pending['member_ids'] === null || in_array($objectId, $pending['member_ids'], true));
            foreach ($map as $ident => $entry) {
                if ($entry['player_id'] !== $objectId) {
                    continue;
                }
                $this->recordGroupMemberVolumeUpdateUnlocked($objectId, $volume);
                $variableId = @$this->GetIDForIdent($ident);
                if ($variableId !== $entry['variable_id'] || !IPS_VariableExists($variableId)
                    || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER
                ) {
                    return false;
                }
                $this->SetValue($ident, $volume);
                return true;
            }
            if ($isPendingMember) {
                $this->recordGroupMemberVolumeUpdateUnlocked($objectId, $volume);
            }
            return false;
        });
        if ($updated) {
            $this->updateVisualization();
        }
    }

    private function refreshGroupMemberVolumes(): void
    {
        $groupPlayerId = trim($this->ReadPropertyString('PlayerID'));
        if ($groupPlayerId === '' || $this->GetStatus() !== IS_ACTIVE || !$this->HasActiveParent()) {
            return;
        }
        $schedule = $this->withGroupMemberVolumeLock(function (): bool {
            if (!$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
                return false;
            }
            return $this->queueGroupMemberVolumeRefreshUnlocked(null, true);
        });
        if ($schedule) {
            $this->SetTimerInterval('RefreshGroupMemberVolumes', 100);
        }
    }

    private function queueGroupMemberVolumeRefreshUnlocked(?array $memberIds, bool $forceRefresh): bool
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if ($playerId === '') {
            return false;
        }
        if ($memberIds !== null) {
            sort($memberIds);
        }
        $pending = $this->readPendingGroupMemberVolumeRefresh();
        if ($pending !== null
            && $pending['player_id'] === $playerId
            && $pending['member_ids'] === $memberIds
            && ($pending['force_refresh'] || !$forceRefresh)) {
            return $this->GetBuffer('RunningGroupMemberVolumeRefresh') !== (string)$pending['generation'];
        }

        $generation = $this->nextGroupMemberVolumeRefreshGenerationUnlocked();
        $this->SetBuffer('PendingGroupMemberVolumeRefresh', json_encode([
            'generation'        => $generation,
            'player_id'         => $playerId,
            'member_ids'        => $memberIds,
            'force_refresh'     => $forceRefresh,
            'volume_generation' => (int)$this->GetBuffer('GroupMemberVolumeUpdateGeneration')
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return true;
    }

    private function readPendingGroupMemberVolumeRefresh(): ?array
    {
        try {
            $pending = json_decode(
                trim($this->GetBuffer('PendingGroupMemberVolumeRefresh')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Aktualisierungsauftrag enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($pending) || count($pending) === 0) {
            return null;
        }
        $memberIds = $pending['member_ids'] ?? null;
        if (!is_int($pending['generation'] ?? null)
            || $pending['generation'] <= 0
            || !is_string($pending['player_id'] ?? null)
            || trim($pending['player_id']) === ''
            || ($memberIds !== null && $this->validateGroupMemberIds($memberIds, $pending['player_id']) === null)
            || !is_bool($pending['force_refresh'] ?? null)
            || !is_int($pending['volume_generation'] ?? null)
            || $pending['volume_generation'] < 0) {
            $this->SendDebug('Group member volumes', 'Aktualisierungsauftrag hat eine ungültige Struktur', 0);
            return null;
        }
        if ($memberIds !== null) {
            sort($memberIds);
        }
        return [
            'generation'        => $pending['generation'],
            'player_id'         => trim($pending['player_id']),
            'member_ids'        => $memberIds,
            'force_refresh'     => $pending['force_refresh'],
            'volume_generation' => $pending['volume_generation']
        ];
    }

    private function nextGroupMemberVolumeRefreshGenerationUnlocked(): int
    {
        $generation = (int)$this->GetBuffer('GroupMemberVolumeRefreshGeneration') + 1;
        $this->SetBuffer('GroupMemberVolumeRefreshGeneration', (string)$generation);
        return $generation;
    }

    private function invalidatePendingGroupMemberVolumeRefreshUnlocked(): void
    {
        $this->nextGroupMemberVolumeRefreshGenerationUnlocked();
        $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
    }

    private function recordGroupMemberVolumeUpdateUnlocked(string $playerId, int $volume): void
    {
        $generation = (int)$this->GetBuffer('GroupMemberVolumeUpdateGeneration') + 1;
        $this->SetBuffer('GroupMemberVolumeUpdateGeneration', (string)$generation);
        $updates = $this->readGroupMemberVolumeUpdates();
        $updates[$playerId] = [
            'generation'   => $generation,
            'volume_level' => $volume
        ];
        $this->SetBuffer('GroupMemberVolumeUpdates', json_encode($updates, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function readGroupMemberVolumeUpdates(): array
    {
        try {
            $updates = json_decode(
                trim($this->GetBuffer('GroupMemberVolumeUpdates')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Lautstärkeaktualisierungen enthalten ungültiges JSON', 0);
            return [];
        }
        if (!is_array($updates)) {
            return [];
        }
        foreach ($updates as $playerId => $update) {
            if (!is_string($playerId) || trim($playerId) === '' || !is_array($update)
                || !is_int($update['generation'] ?? null) || $update['generation'] <= 0
                || !is_int($update['volume_level'] ?? null)
                || $update['volume_level'] < 0 || $update['volume_level'] > 100) {
                unset($updates[$playerId]);
            }
        }
        return $updates;
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
        $this->SetTimerInterval('RefreshGroupMemberVolumes', 0);
        $updated = $this->withGroupMemberVolumeLock(function (): bool {
            $this->invalidatePendingGroupMemberVolumeRefreshUnlocked();
            $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
            return $this->removeGroupMemberVolumeVariablesUnlocked();
        });
        if ($updated) {
            $this->updateVisualization();
        }
    }

    private function removeGroupMemberVolumeVariablesUnlocked(): bool
    {
        $map = $this->readGroupMemberVolumeMap();
        if ($map === null) {
            return false;
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
            return true;
        }
        return false;
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

    private function updateFavoriteFromQueueItem($currentItem): void
    {
        $schedule = $this->withFavoriteLock(function () use ($currentItem): bool {
            if ($currentItem === null) {
                $this->clearCurrentFavoriteTrackUnlocked();
                return false;
            }
            if (!is_array($currentItem)
                || !is_string($currentItem['queue_item_id'] ?? null)
                || trim($currentItem['queue_item_id']) === '') {
                $this->clearCurrentFavoriteTrackUnlocked();
                return false;
            }

            $queueItemId = trim($currentItem['queue_item_id']);
            $currentState = $this->readCurrentFavoriteTrack();
            if ($currentState !== null && $currentState['queue_item_id'] === $queueItemId) {
                return !$currentState['resolved'] && $this->queueFavoriteResolutionUnlocked($currentState);
            }

            $mediaItem = $currentItem['media_item'] ?? null;
            if (!is_array($mediaItem)
                || ($mediaItem['media_type'] ?? null) !== 'track'
                || !is_string($mediaItem['item_id'] ?? null)
                || trim($mediaItem['item_id']) === ''
                || !is_string($mediaItem['provider'] ?? null)
                || trim($mediaItem['provider']) === ''
                || !is_string($mediaItem['uri'] ?? null)
                || trim($mediaItem['uri']) === '') {
                $this->clearCurrentFavoriteTrackUnlocked();
                return false;
            }

            $state = [
                'queue_item_id'   => $queueItemId,
                'source_uri'      => trim($mediaItem['uri']),
                'source_item_id'  => trim($mediaItem['item_id']),
                'source_provider' => trim($mediaItem['provider']),
                'library_item_id' => '',
                'library_uri'     => '',
                'favorite'        => false,
                'resolved'        => false
            ];
            if ($state['source_provider'] === 'library' && is_bool($mediaItem['favorite'] ?? null)) {
                $state['library_item_id'] = $state['source_item_id'];
                $state['library_uri'] = $state['source_uri'];
                $state['favorite'] = $mediaItem['favorite'];
                $state['resolved'] = true;
                $this->invalidatePendingFavoriteResolutionUnlocked();
            } else {
                $this->writeCurrentFavoriteTrack($state);
                $this->updateFavoriteValue(false);
                return $this->queueFavoriteResolutionUnlocked($state);
            }
            $this->writeCurrentFavoriteTrack($state);
            $this->updateFavoriteValue($state['favorite']);
            return false;
        });
        if ($schedule) {
            $this->SetTimerInterval('ResolveFavorite', 100);
        }
    }

    private function processFavoriteMediaItemUpdate(array $packet): void
    {
        if (!is_string($packet['ObjectID'] ?? null) || !is_array($packet['Data'] ?? null)) {
            return;
        }
        $schedule = $this->withFavoriteLock(function () use ($packet): bool {
            $state = $this->readCurrentFavoriteTrack();
            if ($state === null) {
                return false;
            }
            $objectId = trim($packet['ObjectID']);
            $data = $packet['Data'];
            $eventUri = is_string($data['uri'] ?? null) ? trim($data['uri']) : '';
            $eventItemId = is_string($data['item_id'] ?? null) ? trim($data['item_id']) : '';
            $eventProvider = is_string($data['provider'] ?? null) ? trim($data['provider']) : '';
            $matches = $objectId === $state['source_uri']
                || ($state['library_uri'] !== '' && $objectId === $state['library_uri'])
                || $eventUri === $state['source_uri']
                || ($state['library_uri'] !== '' && $eventUri === $state['library_uri'])
                || ($eventItemId === $state['source_item_id'] && $eventProvider === $state['source_provider'])
                || ($state['library_item_id'] !== '' && $eventItemId === $state['library_item_id'])
                || $this->favoriteEventHasSourceMapping($data, $state);
            if (!$matches || (($data['media_type'] ?? 'track') !== 'track')) {
                return false;
            }

            if (is_bool($data['favorite'] ?? null)
                && $eventItemId !== ''
                && ($eventProvider === 'library'
                    || $objectId === $state['library_uri']
                    || $eventUri === $state['library_uri'])) {
                $state['library_item_id'] = $eventItemId;
                if ($eventUri !== '') {
                    $state['library_uri'] = $eventUri;
                }
                $state['favorite'] = $data['favorite'];
                $state['resolved'] = true;
                $this->invalidatePendingFavoriteResolutionUnlocked();
            } else {
                return $this->queueFavoriteResolutionUnlocked($state);
            }
            $this->writeCurrentFavoriteTrack($state);
            $this->updateFavoriteValue($state['favorite']);
            return false;
        });
        if ($schedule) {
            $this->SetTimerInterval('ResolveFavorite', 100);
        }
    }

    private function favoriteEventHasSourceMapping(array $mediaItem, array $state): bool
    {
        if (!is_array($mediaItem['provider_mappings'] ?? null)) {
            return false;
        }
        foreach ($mediaItem['provider_mappings'] as $mapping) {
            if (is_array($mapping)
                && ($mapping['item_id'] ?? null) === $state['source_item_id']
                && (($mapping['provider_instance'] ?? null) === $state['source_provider']
                    || ($mapping['provider_domain'] ?? null) === $state['source_provider'])) {
                return true;
            }
        }
        return false;
    }

    private function setCurrentTrackFavorite(bool $favorite): void
    {
        if (!$this->ReadPropertyBoolean('ShowFavorite')) {
            throw new Exception('Favoritensteuerung ist deaktiviert.');
        }
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        $snapshot = $this->withFavoriteLock(function () use ($favorite): array {
            $state = $this->readCurrentFavoriteTrack();
            if ($state === null || !$state['resolved'] || $state['source_uri'] === '') {
                throw new Exception('Der aktuelle Titel konnte nicht eindeutig ermittelt werden.');
            }
            if ($favorite === $state['favorite']) {
                $this->updateFavoriteValue($state['favorite']);
                return [];
            }
            return [
                'generation' => (int)$this->GetBuffer('FavoriteResolutionGeneration'),
                'state'      => $state
            ];
        });
        if ($snapshot === []) {
            return;
        }
        $state = $snapshot['state'];

        if ($favorite) {
            $this->sendApiRequest('music/favorites/add_item', ['item' => $state['source_uri']]);
        } else {
            if ($state['library_item_id'] === '') {
                throw new Exception('Die Library-ID des aktuellen Titels ist nicht verfügbar.');
            }
            $this->sendApiRequest('music/favorites/remove_item', [
                'media_type'     => 'track',
                'library_item_id' => $state['library_item_id']
            ]);
        }

        $updatedState = $this->resolveFavoriteLibraryState($state);
        if ($updatedState['favorite'] !== $favorite) {
            throw new Exception('Der geänderte Favoritenstatus konnte nicht bestätigt werden.');
        }
        $this->withFavoriteLock(function () use ($playerId, $snapshot, $state, $updatedState): void {
            if (trim($this->ReadPropertyString('PlayerID')) !== $playerId
                || (int)$this->GetBuffer('FavoriteResolutionGeneration') !== $snapshot['generation']
                || !$this->favoriteStatesIdentifySameTrack($this->readCurrentFavoriteTrack(), $state)) {
                throw new Exception('Der aktuelle Titel hat sich während der Favoritenänderung geändert.');
            }
            $this->invalidatePendingFavoriteResolutionUnlocked();
            $this->writeCurrentFavoriteTrack($updatedState);
            $this->updateFavoriteValue($updatedState['favorite']);
        });
    }

    private function resolveFavoriteLibraryState(array $state): array
    {
        $libraryItem = $this->sendApiRequest('music/get_library_item', [
            'media_type'                    => 'track',
            'item_id'                       => $state['source_item_id'],
            'provider_instance_id_or_domain' => $state['source_provider']
        ]);
        if ($libraryItem === null) {
            $state['library_item_id'] = '';
            $state['library_uri'] = '';
            $state['favorite'] = false;
            $state['resolved'] = true;
            return $state;
        }
        if (!is_array($libraryItem)
            || ($libraryItem['media_type'] ?? null) !== 'track'
            || !is_string($libraryItem['item_id'] ?? null)
            || trim($libraryItem['item_id']) === ''
            || !is_bool($libraryItem['favorite'] ?? null)) {
            throw new Exception('Music Assistant lieferte keinen vollständigen Library-Track.');
        }
        $state['library_item_id'] = trim($libraryItem['item_id']);
        $state['library_uri'] = is_string($libraryItem['uri'] ?? null)
            ? trim($libraryItem['uri'])
            : '';
        $state['favorite'] = $libraryItem['favorite'];
        $state['resolved'] = true;
        return $state;
    }

    private function queueFavoriteResolutionUnlocked(array $state): bool
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if ($playerId === '') {
            return false;
        }
        $pending = $this->readPendingFavoriteResolution();
        if ($pending !== null
            && $pending['player_id'] === $playerId
            && $this->favoriteStatesIdentifySameTrack($pending['state'], $state)) {
            if ($this->GetBuffer('RunningFavoriteResolution') !== (string)$pending['generation']) {
                return true;
            }
        }

        $generation = $this->nextFavoriteResolutionGenerationUnlocked();
        $this->SetBuffer('PendingFavoriteResolution', json_encode([
            'generation' => $generation,
            'player_id'  => $playerId,
            'state'      => $state
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return true;
    }

    private function readPendingFavoriteResolution(): ?array
    {
        try {
            $pending = json_decode(
                trim($this->GetBuffer('PendingFavoriteResolution')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Auflösungsauftrag enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($pending) || count($pending) === 0) {
            return null;
        }
        if (!is_int($pending['generation'] ?? null)
            || $pending['generation'] <= 0
            || !is_string($pending['player_id'] ?? null)
            || trim($pending['player_id']) === ''
            || !is_array($pending['state'] ?? null)
            || !$this->favoriteStatesIdentifySameTrack($pending['state'], $pending['state'])) {
            $this->SendDebug('Favorite', 'Auflösungsauftrag hat eine ungültige Struktur', 0);
            return null;
        }
        return $pending;
    }

    private function favoriteStatesIdentifySameTrack(?array $left, array $right): bool
    {
        if ($left === null) {
            return false;
        }
        foreach (['queue_item_id', 'source_uri', 'source_item_id', 'source_provider'] as $key) {
            if (!is_string($left[$key] ?? null)
                || !is_string($right[$key] ?? null)
                || $left[$key] === ''
                || $left[$key] !== $right[$key]) {
                return false;
            }
        }
        return true;
    }

    private function nextFavoriteResolutionGenerationUnlocked(): int
    {
        $generation = (int)$this->GetBuffer('FavoriteResolutionGeneration') + 1;
        $this->SetBuffer('FavoriteResolutionGeneration', (string)$generation);
        return $generation;
    }

    private function invalidatePendingFavoriteResolutionUnlocked(): void
    {
        $this->nextFavoriteResolutionGenerationUnlocked();
        $this->SetBuffer('PendingFavoriteResolution', '{}');
    }

    private function readCurrentFavoriteTrack(): ?array
    {
        try {
            $state = json_decode(trim($this->GetBuffer('CurrentFavoriteTrack')), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Aktuelle Trackkennung enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($state) || count($state) === 0) {
            return null;
        }
        foreach (['queue_item_id', 'source_uri', 'source_item_id', 'source_provider', 'library_item_id', 'library_uri'] as $key) {
            if (!is_string($state[$key] ?? null)) {
                $this->SendDebug('Favorite', 'Aktuelle Trackkennung hat eine ungültige Struktur', 0);
                return null;
            }
        }
        if ($state['queue_item_id'] === ''
            || $state['source_uri'] === ''
            || $state['source_item_id'] === ''
            || $state['source_provider'] === ''
            || !is_bool($state['favorite'] ?? null)
            || !is_bool($state['resolved'] ?? null)) {
            $this->SendDebug('Favorite', 'Aktuelle Trackkennung ist unvollständig', 0);
            return null;
        }
        return $state;
    }

    private function writeCurrentFavoriteTrack(array $state): void
    {
        $this->SetBuffer('CurrentFavoriteTrack', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function clearCurrentFavoriteTrack(): void
    {
        $this->withFavoriteLock(function (): void {
            $this->clearCurrentFavoriteTrackUnlocked();
        });
    }

    private function clearCurrentFavoriteTrackUnlocked(): void
    {
        $this->invalidatePendingFavoriteResolutionUnlocked();
        $this->SetBuffer('CurrentFavoriteTrack', '{}');
        $this->updateFavoriteValue(false);
    }

    private function updateFavoriteValue(bool $favorite): void
    {
        $variableId = @$this->GetIDForIdent('Favorite');
        if ($variableId > 0
            && IPS_VariableExists($variableId)
            && (bool)$this->GetValue('Favorite') !== $favorite) {
            $this->SetValue('Favorite', $favorite);
        }
    }

    private function withFavoriteLock(callable $operation)
    {
        $semaphore = 'MA_FAVORITE_' . (string)$this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, self::FAVORITE_SEMAPHORE_TIMEOUT)) {
            throw new RuntimeException('Favoriten-Semaphore konnte nicht erworben werden.');
        }
        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
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

    private function configureVisualizationType(): void
    {
        if (!method_exists($this, 'SetVisualizationType')
            || !defined('INSTANCE_VISUALIZATION_TYPE_HTML')) {
            return;
        }

        $visualizationType = $this->ReadPropertyBoolean('EnableCustomTile')
            ? constant('INSTANCE_VISUALIZATION_TYPE_HTML')
            : (defined('INSTANCE_VISUALIZATION_TYPE_NONE')
                ? constant('INSTANCE_VISUALIZATION_TYPE_NONE')
                : 0);
        $this->SetVisualizationType($visualizationType);
    }

    private function updateVisualization(): void
    {
        if (!$this->ReadPropertyBoolean('EnableCustomTile')
            || !method_exists($this, 'UpdateVisualizationValue')) {
            return;
        }

        try {
            $stateJson = json_encode($this->buildVisualizationState(), JSON_THROW_ON_ERROR);
            $stateHash = hash('sha256', $stateJson);
            if ($this->GetBuffer('VisualizationStateHash') === $stateHash) {
                return;
            }
            $transmitted = $this->UpdateVisualizationValue($stateJson);
            if ($transmitted !== true) {
                if ($transmitted === false) {
                    $this->SendDebug('Visualization', 'Kachelzustand konnte nicht übertragen werden', 0);
                }
                return;
            }
            $this->SetBuffer('VisualizationStateHash', $stateHash);
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Kachel konnte nicht aktualisiert werden: ' . $e->getMessage(), 0);
        }
    }

    private function buildVisualizationState(): array
    {
        $transport = @$this->GetIDForIdent('Transport') > 0
            ? (int)$this->GetValue('Transport')
            : 0;
        $states = [1 => 'idle', 2 => 'playing', 3 => 'paused'];
        $volume = @$this->GetIDForIdent('VolumeLevel') > 0
            ? max(0, min(100, (int)$this->GetValue('VolumeLevel')))
            : 0;
        $muted = @$this->GetIDForIdent('Mute') > 0
            ? (bool)$this->GetValue('Mute')
            : false;
        $shuffle = @$this->GetIDForIdent('Shuffle') > 0
            ? (bool)$this->GetValue('Shuffle')
            : false;
        $repeat = @$this->GetIDForIdent('Repeat') > 0
            ? strtolower(trim((string)$this->GetValue('Repeat')))
            : 'off';
        if (!in_array($repeat, ['off', 'one', 'all'], true)) {
            $repeat = 'off';
        }
        $showFavorite = $this->ReadPropertyBoolean('ShowFavorite');
        $favorite = $showFavorite && @$this->GetIDForIdent('Favorite') > 0
            ? (bool)$this->GetValue('Favorite')
            : false;
        $playlists = $this->buildVisualizationSelectionOptions('Playlist', 'PlaylistMap', 'PlaylistProfile');
        $radios = $this->buildVisualizationSelectionOptions('Radio', 'RadioMap', 'RadioProfile');
        $groupMembers = $this->buildVisualizationGroupMembers();

        return [
            'cover'       => $this->readVisualizationCover(),
            'title'       => $this->readVisualizationString('NowTitle'),
            'artist'      => $this->readVisualizationString('NowArtist'),
            'status'      => $states[$transport] ?? 'unknown',
            'volume'      => $volume,
            'muted'       => $muted,
            'shuffle'     => $shuffle,
            'repeat'      => $repeat,
            'showFavorite' => $showFavorite,
            'favorite'    => $favorite,
            'playlists'   => $playlists,
            'radios'      => $radios,
            'groupMembers' => $groupMembers
        ];
    }

    private function buildVisualizationGroupMembers(): array
    {
        if (!$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
            return [];
        }
        return $this->withGroupMemberVolumeLock(function (): array {
            $map = $this->readGroupMemberVolumeMap();
            if ($map === null) {
                return [];
            }

            $members = [];
            foreach ($map as $ident => $entry) {
                $variableId = @$this->GetIDForIdent($ident);
                if ($variableId !== $entry['variable_id'] || !IPS_VariableExists($variableId)) {
                    continue;
                }
                $variable = IPS_GetVariable($variableId);
                if (($variable['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                    continue;
                }
                $volume = (int)$this->GetValue($ident);
                if ($volume < 0 || $volume > 100) {
                    continue;
                }
                $members[] = [
                    'ident'  => $ident,
                    'name'   => $entry['name'],
                    'volume' => $volume
                ];
            }
            return $members;
        });
    }

    private function buildVisualizationSelectionOptions(
        string $ident,
        string $mapProperty,
        string $profileProperty
    ): array
    {
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return [];
        }
        $variable = IPS_GetVariable($variableId);
        if (($variable['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            return [];
        }

        $profile = is_string($variable['VariableCustomProfile'] ?? null)
            ? trim($variable['VariableCustomProfile'])
            : '';
        if ($profile === '') {
            $profile = is_string($variable['VariableProfile'] ?? null)
                ? trim($variable['VariableProfile'])
                : trim($this->ReadPropertyString($profileProperty));
        }
        if ($profile === '' || !IPS_VariableProfileExists($profile)) {
            return [];
        }
        $profileData = IPS_GetVariableProfile($profile);
        if (($profileData['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            return [];
        }

        try {
            $map = json_decode(trim($this->ReadPropertyString($mapProperty)), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [];
        }
        if (!is_array($map)) {
            return [];
        }

        $options = [];
        foreach (($profileData['Associations'] ?? []) as $association) {
            $value = $association['Value'] ?? null;
            $name = $association['Name'] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_string($name)) {
                continue;
            }
            $itemId = (int)$value;
            $key = (string)$itemId;
            $validMapEntry = $mapProperty === 'PlaylistMap'
                ? isset($map[$key]) && $this->normalizePlaylistMapEntry($map[$key]) !== null
                : isset($map[$key]) && is_string($map[$key]) && trim($map[$key]) !== '';
            if ($itemId <= 0 || (float)$itemId !== (float)$value || trim($name) === '' || !$validMapEntry) {
                continue;
            }
            $options[] = ['value' => $itemId, 'label' => trim($name)];
        }
        return $options;
    }

    private function readVisualizationString(string $ident): string
    {
        return @$this->GetIDForIdent($ident) > 0
            ? (string)$this->GetValue($ident)
            : '';
    }

    private function readVisualizationCover(): string
    {
        $mediaId = @$this->GetIDForIdent('Cover');
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId) || !$this->coverMediaHasContent($mediaId)) {
            return '';
        }

        $media = IPS_GetMedia($mediaId);
        if ((int)($media['MediaSize'] ?? 0) > self::TILE_COVER_MAX_BYTES) {
            $this->SendDebug('Visualization', 'Cover ist für die Kachel zu groß', 0);
            return '';
        }
        $content = IPS_GetMediaContent($mediaId);
        if ($content === '') {
            return '';
        }
        $signature = base64_decode(substr($content, 0, 16), true);
        if (!is_string($signature)) {
            return '';
        }
        if (substr($signature, 0, 3) === "\xFF\xD8\xFF") {
            $contentType = 'image/jpeg';
        } elseif (substr($signature, 0, 8) === "\x89PNG\r\n\x1A\n") {
            $contentType = 'image/png';
        } else {
            return '';
        }

        return 'data:' . $contentType . ';base64,' . $content;
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
        if ($proxyId === '') {
            $this->withCoverLock(function (): void {
                $this->invalidatePendingCoverDownloadUnlocked();
                if ($this->ReadAttributeString('CoverTargetProxyID') !== ''
                    && !$this->WriteAttributeString('CoverTargetProxyID', '')) {
                    throw new Exception('Cover-Ziel konnte nicht zurückgesetzt werden.');
                }
            });
        }
        $mediaId = $this->ensureCoverMedia();
        if ($mediaId <= 0) {
            throw new Exception('Cover-Medium ist nicht verfügbar.');
        }
        if ($proxyId === '') {
            $this->showDefaultCover($mediaId, '', $state);
            return;
        }

        $schedule = $this->withCoverLock(function () use ($mediaId, $proxyId, $state): bool {
            if ($this->ReadAttributeString('CoverTargetProxyID') !== $proxyId) {
                $this->invalidatePendingCoverDownloadUnlocked();
                if (!$this->WriteAttributeString('CoverTargetProxyID', $proxyId)) {
                    throw new Exception('Cover-Ziel konnte nicht gespeichert werden.');
                }
                $this->setDefaultCover($mediaId, $state);
            }
            if ($this->ReadAttributeString('CoverProxyID') === $proxyId
                && !$this->ReadAttributeBoolean('CoverIsDefault')
                && $this->coverMediaHasContent($mediaId)) {
                $this->invalidatePendingCoverDownloadUnlocked();
                $this->updateCoverVisibilityFromQueueState($state, true);
                return false;
            }
            if ($this->ReadAttributeBoolean('CoverIsDefault') || !$this->coverMediaHasContent($mediaId)) {
                $this->setDefaultCover($mediaId, $state);
            }
            return $this->queueCoverDownloadUnlocked($proxyId, $state);
        });
        if ($schedule) {
            $this->SetTimerInterval('DownloadCover', 100);
        }
    }

    private function showDefaultCover(int $mediaId, string $targetProxyId, $state): void
    {
        $this->withCoverLock(function () use ($mediaId, $targetProxyId, $state): void {
            if ($this->ReadAttributeString('CoverTargetProxyID') !== $targetProxyId
                && !$this->WriteAttributeString('CoverTargetProxyID', $targetProxyId)) {
                throw new Exception('Cover-Ziel konnte nicht gespeichert werden.');
            }
            $this->setDefaultCover($mediaId, $state);
        });
    }

    private function setDefaultCover(int $mediaId, $state): void
    {
        $content = $this->loadDefaultCoverContent();
        $this->ensureCoverMediaFormat($mediaId, 'image/png');
        $currentContent = $this->coverMediaHasContent($mediaId)
            ? IPS_GetMediaContent($mediaId)
            : '';
        if ($currentContent !== $content && !IPS_SetMediaContent($mediaId, $content)) {
            throw new Exception('Standard-Cover konnte nicht gesetzt werden.');
        }
        if (!$this->ReadAttributeBoolean('CoverIsDefault')
            && !$this->WriteAttributeBoolean('CoverIsDefault', true)) {
            throw new Exception('Cover-Status konnte nicht gespeichert werden.');
        }
        $this->updateCoverVisibilityFromQueueState($state, true);
    }

    private function loadDefaultCoverContent(): string
    {
        $file = __DIR__ . '/default-cover.png';
        if (!is_file($file) || !is_readable($file)) {
            throw new Exception('Standard-Cover-Datei ist nicht verfügbar.');
        }

        $binary = @file_get_contents($file);
        $imageInfo = is_string($binary) && $binary !== ''
            ? @getimagesizefromstring($binary)
            : false;
        if (!is_string($binary)
            || substr($binary, 0, 8) !== "\x89PNG\r\n\x1A\n"
            || !is_array($imageInfo)
            || ($imageInfo['mime'] ?? '') !== 'image/png') {
            throw new Exception('Standard-Cover-Datei ist keine gültige PNG-Datei.');
        }
        if (strlen($binary) > self::TILE_COVER_MAX_BYTES) {
            throw new Exception('Standard-Cover-Datei ist für die HTML-Kachel zu groß.');
        }

        return base64_encode($binary);
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

    private function queueCoverDownloadUnlocked(string $proxyId, $state): bool
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if ($playerId === '') {
            return false;
        }
        $pending = $this->readPendingCoverDownload();
        if ($pending !== null
            && $pending['player_id'] === $playerId
            && $pending['proxy_id'] === $proxyId) {
            return $this->GetBuffer('RunningCoverDownload') !== (string)$pending['generation'];
        }

        $generation = $this->nextCoverDownloadGenerationUnlocked();
        $this->SetBuffer('PendingCoverDownload', json_encode([
            'generation' => $generation,
            'player_id'  => $playerId,
            'proxy_id'   => $proxyId,
            'state'      => $state
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return true;
    }

    private function readPendingCoverDownload(): ?array
    {
        try {
            $pending = json_decode(
                trim($this->GetBuffer('PendingCoverDownload')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Download-Auftrag enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($pending) || count($pending) === 0) {
            return null;
        }
        if (!is_int($pending['generation'] ?? null)
            || $pending['generation'] <= 0
            || !is_string($pending['player_id'] ?? null)
            || trim($pending['player_id']) === ''
            || !is_string($pending['proxy_id'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $pending['proxy_id']) !== 1
            || !array_key_exists('state', $pending)) {
            $this->SendDebug('Artwork', 'Download-Auftrag hat eine ungültige Struktur', 0);
            return null;
        }
        return $pending;
    }

    private function nextCoverDownloadGenerationUnlocked(): int
    {
        $generation = (int)$this->GetBuffer('CoverDownloadGeneration') + 1;
        $this->SetBuffer('CoverDownloadGeneration', (string)$generation);
        return $generation;
    }

    private function invalidatePendingCoverDownloadUnlocked(): void
    {
        $this->nextCoverDownloadGenerationUnlocked();
        $this->SetBuffer('PendingCoverDownload', '{}');
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
            $this->withCoverLock(function (): void {
                $this->invalidatePendingCoverDownloadUnlocked();
            });
            return;
        }
        $this->withCoverLock(function () use ($mediaId): void {
            $this->invalidatePendingCoverDownloadUnlocked();
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

    private function playRefreshedPlaylist(string $queueId, string $itemId, string $provider): void
    {
        try {
            $response = $this->SendDataToParent(json_encode([
                'DataID'   => self::CONNECTION_REQUEST,
                'Command'  => 'PlayRefreshedPlaylist',
                'QueueID'  => $queueId,
                'ItemID'   => $itemId,
                'Provider' => $provider
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            throw new Exception('PlayRefreshedPlaylist konnte nicht erzeugt oder gesendet werden.');
        }

        if (!is_string($response) || trim($response) === '') {
            throw new Exception('PlayRefreshedPlaylist lieferte keine gültige Antwort.');
        }

        try {
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new Exception('PlayRefreshedPlaylist lieferte ungültiges JSON.');
        }
        if (!is_array($result)) {
            throw new Exception('PlayRefreshedPlaylist-Antwort ist kein JSON-Objekt.');
        }
        if (($result['success'] ?? false) !== true) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : [];
            $code = is_string($error['code'] ?? null) && trim($error['code']) !== ''
                ? trim($error['code'])
                : 'UNKNOWN_ERROR';
            $message = is_string($error['message'] ?? null) && trim($error['message']) !== ''
                ? trim($error['message'])
                : 'PlayRefreshedPlaylist ist fehlgeschlagen.';
            throw new Exception($code . ': ' . $message);
        }
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

    private function ensureFavoriteProfile(): bool
    {
        if (!IPS_VariableProfileExists(self::FAVORITE_PROFILE)) {
            IPS_CreateVariableProfile(self::FAVORITE_PROFILE, VARIABLETYPE_BOOLEAN);
        }
        if (!IPS_VariableProfileExists(self::FAVORITE_PROFILE)) {
            $this->LogMessage('Variablenprofil ' . self::FAVORITE_PROFILE . ' konnte nicht erstellt werden.', KL_ERROR);
            return false;
        }

        $profileData = IPS_GetVariableProfile(self::FAVORITE_PROFILE);
        if (($profileData['ProfileType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
            $this->LogMessage(
                'Variablenprofil ' . self::FAVORITE_PROFILE . ' hat nicht den erwarteten Typ Boolean und wird nicht verändert.',
                KL_ERROR
            );
            return false;
        }

        IPS_SetVariableProfileAssociation(self::FAVORITE_PROFILE, false, 'Favorit', 'Heart', 0x555555);
        IPS_SetVariableProfileAssociation(self::FAVORITE_PROFILE, true, 'Favorit', 'Heart', 0xE53935);
        return true;
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
        $legacyEntry = null;
        if (isset($map[$key])) {
            $entry = $this->normalizePlaylistMapEntry($map[$key]);
            if ($entry !== null) {
                if (!is_string($map[$key])) {
                    return $entry;
                }
                $legacyEntry = $entry;
            }
        }

        try {
            $result = $this->sendApiRequest('music/playlists/library_items', ['summary' => false]);
        } catch (Throwable $e) {
            if ($legacyEntry !== null) {
                $this->SendDebug('Playlist', 'Provider-Auflösung fehlgeschlagen: ' . $e->getMessage(), 0);
                return $legacyEntry;
            }
            throw $e;
        }
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
        if ($legacyEntry !== null) {
            $this->SendDebug('Playlist', 'Keine Provider-Daten für item_id=' . $itemId . '; gespeicherte URI wird verwendet', 0);
            return $legacyEntry;
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
        $builtinItemId = $this->getRefreshableBuiltinPlaylistItemId($playlist);
        if ($builtinItemId === null) {
            $this->PlayMediaUri($playlist['uri']);
            return;
        }

        $queueId = $this->getQueueIdForPlayer();
        $this->playRefreshedPlaylist($queueId, $builtinItemId, 'builtin');
    }

    private function getRefreshableBuiltinPlaylistItemId(array $playlist): ?string
    {
        $refreshableItemIds = ['random_tracks', 'random_album', 'random_artist'];
        foreach ($playlist['provider_mappings'] as $mapping) {
            if (!is_array($mapping)
                || !is_string($mapping['item_id'] ?? null)
                || !in_array($mapping['item_id'], $refreshableItemIds, true)) {
                continue;
            }
            if (($mapping['provider_instance'] ?? null) === 'builtin'
                && ($mapping['provider_domain'] ?? null) === 'builtin') {
                return $mapping['item_id'];
            }
        }
        return null;
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
