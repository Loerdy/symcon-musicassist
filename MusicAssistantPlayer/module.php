<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApiClient.php';
require_once __DIR__ . '/../libs/MusicAssistantPlayerCover.php';
require_once __DIR__ . '/../libs/MusicAssistantPlayerFavorite.php';
require_once __DIR__ . '/../libs/MusicAssistantPlayerGroupVolumes.php';
require_once __DIR__ . '/../libs/MusicAssistantPlayerTile.php';

class MusicAssistantPlayer extends IPSModule
{
    use MusicAssistantApiClient;
    use MusicAssistantPlayerCover;
    use MusicAssistantPlayerFavorite;
    use MusicAssistantPlayerGroupVolumes;
    use MusicAssistantPlayerTile;

    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';
    private const REPEAT_MODE_TO_VALUE = ['off' => 0, 'all' => 1, 'one' => 2];
    private const REPEAT_VALUE_TO_MODE = [0 => 'off', 1 => 'all', 2 => 'one'];
    private const COVER_SEMAPHORE_TIMEOUT = 5000;
    private const GROUP_MEMBER_VOLUME_SEMAPHORE_TIMEOUT = 40000;
    private const GROUP_MEMBER_REMOVAL_CONFIRMATION_DELAY = 1500;
    private const GROUP_MEMBER_REMOVAL_CONFIRMATION_ATTEMPTS = 3;
    private const FAVORITE_SEMAPHORE_TIMEOUT = 20000;
    private const TILE_COVER_MAX_BYTES = 524288;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);

        $this->RegisterPropertyString('PlayerID', '');
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
        $this->RegisterAttributeBoolean('CoverVisibilityMigrated', false);
        $this->RegisterAttributeString('GroupMemberVolumeMap', '{}');

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
        // Abweichende Revisionen sorgen dafür, dass die erste Kachelaktualisierung nach dem Laden das Cover enthält.
        $this->SetBuffer('CoverRevision', '1');
        $this->SetBuffer('VisualizationCoverRevision', '');

        $this->RegisterTimer('ResetPlaylist', 0, 'MASS_ResetPlaylistSelection($_IPS["TARGET"]);');
        $this->RegisterTimer('ResetRadio', 0, 'MASS_ResetRadioSelection($_IPS["TARGET"]);');
        $this->RegisterTimer('ResolveFavorite', 0, 'MASS_ProcessFavoriteResolution($_IPS["TARGET"]);');
        $this->RegisterTimer('DownloadCover', 0, 'MASS_ProcessCoverDownload($_IPS["TARGET"]);');
        $this->RegisterTimer('RefreshGroupMemberVolumes', 0, 'MASS_ProcessGroupMemberVolumeRefresh($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
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
        if ($this->ensureIntegerSelectorVariable('Playlist', $this->Translate('Playlist'), $this->ReadPropertyString('PlaylistProfile'), 10)) {
            $vidPlaylist = @$this->GetIDForIdent('Playlist');
            IPS_SetIcon($vidPlaylist, 'list-music');
        }
        if ($this->ensureIntegerSelectorVariable('Radio', $this->Translate('Radio'), $this->ReadPropertyString('RadioProfile'), 20)) {
            $vidRadio = @$this->GetIDForIdent('Radio');
            IPS_SetIcon($vidRadio, 'radio');
        }
        $this->resetInvalidMediaSelection('Playlist', 'PlaylistProfile', 'ResetPlaylist');
        $this->resetInvalidMediaSelection('Radio', 'RadioProfile', 'ResetRadio');

        // Transport
        $this->MaintainVariable('Transport', $this->Translate('Playback'), VARIABLETYPE_INTEGER, '~PlaybackPreviousNext', 30, true);
        $this->EnableAction('Transport');

        // Shuffle
        $this->MaintainVariable('Shuffle', $this->Translate('Shuffle'), VARIABLETYPE_BOOLEAN, '~Shuffle', 40, true);
        $this->EnableAction('Shuffle');

        // Repeat
        $this->MaintainVariable(
            'Repeat',
            $this->Translate('Repeat'),
            VARIABLETYPE_INTEGER,
            '~Repeat',
            50,
            true
        );
        $this->EnableAction('Repeat');

        // Mute
        $this->MaintainVariable(
            'Mute',
            $this->Translate('Mute'),
            VARIABLETYPE_BOOLEAN,
            [
                'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
                'USE_ICON_FALSE' => true,
                'ICON_FALSE'     => 'volume',
                'ICON_TRUE'      => 'volume-xmark',
                'USAGE_TYPE'     => 1
            ],
            60,
            true
        );
        $this->EnableAction('Mute');

        // Volume
        $this->MaintainVariable(
            'VolumeLevel',
            $this->Translate('Volume'),
            VARIABLETYPE_INTEGER,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN'          => 0,
                'MAX'          => 100,
                'STEP_SIZE'    => 1,
                'USAGE_TYPE'   => 3,
                'PERCENTAGE'   => true
            ],
            70,
            true
        );
        $this->EnableAction('VolumeLevel');

        // Volume Buttons (Group Up/Down)
        $this->MaintainVariable(
            'VolumeUp',
            $this->Translate('Volume +'),
            VARIABLETYPE_INTEGER,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'LAYOUT'       => 1,
                'DISPLAY'      => 1,
                'OPTIONS'      => json_encode([
                    [
                        'Value'      => 1,
                        'Caption'    => $this->Translate('Up'),
                        'IconActive' => true,
                        'IconValue'  => 'angles-up'
                    ]
                ], JSON_THROW_ON_ERROR)
            ],
            80,
            true
        );
        $this->EnableAction('VolumeUp');

        $this->MaintainVariable(
            'VolumeDown',
            $this->Translate('Volume -'),
            VARIABLETYPE_INTEGER,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'LAYOUT'       => 1,
                'DISPLAY'      => 1,
                'OPTIONS'      => json_encode([
                    [
                        'Value'      => 1,
                        'Caption'    => $this->Translate('Down'),
                        'IconActive' => true,
                        'IconValue'  => 'angles-down'
                    ]
                ], JSON_THROW_ON_ERROR)
            ],
            90,
            true
        );
        $this->EnableAction('VolumeDown');

        // Now Playing
        $this->MaintainVariable('NowTitle',  $this->Translate('Title'),  VARIABLETYPE_STRING, '~Song',   110, true);
        $this->MaintainVariable('NowArtist', $this->Translate('Artist'), VARIABLETYPE_STRING, '~Artist', 120, true);
        $albumId = @$this->GetIDForIdent('NowAlbum');
        $hadAlbumVariable = $albumId > 0
            && IPS_VariableExists($albumId)
            && (IPS_GetVariable($albumId)['VariableType'] ?? -1) === VARIABLETYPE_STRING;
        $this->MaintainVariable(
            'NowAlbum',
            $this->Translate('Album'),
            VARIABLETYPE_STRING,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'album'
            ],
            130,
            true
        );
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
        $favoritePresentation = [
            'PRESENTATION'   => constant('VARIABLE_PRESENTATION_SWITCH'),
            'USE_ICON_FALSE' => false,
            'ICON_TRUE'      => 'heart',
            'GLOW_INTENSITY' => 30,
            'USAGE_TYPE'     => 2
        ];
        $this->MaintainVariable('Favorite', $this->Translate('Favorite'), VARIABLETYPE_BOOLEAN, $favoritePresentation, 140, $showFavorite);
        if ($showFavorite) {
            $favoriteId = @$this->GetIDForIdent('Favorite');
            $favoriteCreated = !$hadFavoriteVariable
                && $favoriteId > 0
                && IPS_VariableExists($favoriteId)
                && (IPS_GetVariable($favoriteId)['VariableType'] ?? -1) === VARIABLETYPE_BOOLEAN;
            if ($favoriteCreated) {
                IPS_SetIcon($favoriteId, 'heart');
            }
            $this->EnableAction('Favorite');
        } else {
            $this->clearCurrentFavoriteTrack();
        }
        $this->ensureCoverMedia();
        $this->migrateCoverVisibility();

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
        if (@$this->GetIDForIdent('Repeat') > 0
            && !array_key_exists((int)@$this->GetValue('Repeat'), self::REPEAT_VALUE_TO_MODE)) {
            $this->SetValue('Repeat', 0);
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
                ['type' => 'ValidationTextBox', 'name' => 'PlayerID', 'caption' => 'Player ID (editable)'],
                ['type' => 'Label', 'caption' => 'Configuration (read-only):'],
                // Inhalt wird per UpdateFormField("ConfigView","caption", ...) gesetzt
                ['type' => 'Label', 'name' => 'ConfigView', 'caption' => ''],
                ['type' => 'Button', 'caption' => 'Update configuration', 'onClick' => 'IPS_RequestAction($id, "UpdateConfigView", true);'],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowFavorite',
                    'caption' => 'Show favorite control for the current track'
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'EnableCustomTile',
                    'caption' => 'Show custom player tile in the Tile Visualization'
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowGroupMemberVolumes',
                    'caption' => 'Show individual volume controls for current group members',
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
        if (($packet['Event'] ?? null) === 'profiles_updated') {
            // Der Configurator hat Playlist- oder Radio-Profile neu geladen; Auswahl und Kachel folgen dem Profil.
            $this->resetInvalidMediaSelection('Playlist', 'PlaylistProfile', 'ResetPlaylist');
            $this->resetInvalidMediaSelection('Radio', 'RadioProfile', 'ResetRadio');
            $this->updateVisualization(true);
            return '';
        }
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
                        $this->assertIntegerVariable('Playlist');
                        $playlist = $this->resolvePlaylistFromMap($itemId);
                        $this->PlayPlaylist($playlist);
                        $this->SetValue('Playlist', $itemId);
                        $this->SetTimerInterval('ResetPlaylist', 5000);
                        $this->SetBuffer('PlaylistSelectionRevision', (string)((int)$this->GetBuffer('PlaylistSelectionRevision') + 1));
                        $this->updateVisualization(true);
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Playlist start failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Playlist start failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'Radio':
                try {
                    $itemId = (int)$Value;
                    if ($itemId > 0) {
                        $this->assertIntegerVariable('Radio');
                        $uri = $this->resolveUriFromMap($itemId, 'RadioMap', 'music/radios/library_items');
                        $this->PlayMediaUri($uri);
                        $this->SetValue('Radio', $itemId);
                        $this->SetTimerInterval('ResetRadio', 5000);
                        $this->SetBuffer('RadioSelectionRevision', (string)((int)$this->GetBuffer('RadioSelectionRevision') + 1));
                        $this->updateVisualization(true);
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Radio start failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Radio start failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'Transport':
                try {
                    $this->ExecuteTransport((int)$Value);
                    $this->SetValue('Transport', (int)$Value);
                } catch (Throwable $e) {
                    $this->SendDebug('Transport failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Transport failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'Shuffle':
                $enabled = (bool)$Value;
                try {
                    $this->SetShuffle($enabled);
                    $this->SetValue('Shuffle', $enabled);
                } catch (Throwable $e) {
                    $this->SendDebug('Shuffle failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Shuffle failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'Repeat':
                try {
                    $mode = null;
                    if (is_int($Value)) {
                        $mode = self::REPEAT_VALUE_TO_MODE[$Value] ?? null;
                    } elseif (is_string($Value)) {
                        $normalizedMode = strtolower(trim($Value));
                        if (array_key_exists($normalizedMode, self::REPEAT_MODE_TO_VALUE)) {
                            $mode = $normalizedMode;
                        }
                    }
                    if ($mode === null) {
                        throw new InvalidArgumentException($this->Translate('Invalid repeat mode.'));
                    }
                    $this->SetRepeat($mode);
                    $this->SetValue('Repeat', self::REPEAT_MODE_TO_VALUE[$mode]);
                } catch (Throwable $e) {
                    $this->SendDebug('Repeat failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Repeat failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'Mute':
                $muted = (bool)$Value;
                try {
                    $this->SetMute($muted);
                    $this->SetValue('Mute', $muted);
                } catch (Throwable $e) {
                    $this->SendDebug('Mute failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Mute failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'Favorite':
                try {
                    $this->setCurrentTrackFavorite((bool)$Value);
                    $this->updateVisualization();
                } catch (Throwable $e) {
                    $this->SendDebug('Favorite failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Favorite failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'VolumeLevel':
                $level = max(0, min(100, (int)$Value));
                try {
                    $this->SetVolumeLevel($level);
                } catch (Throwable $e) {
                    $this->SendDebug('VolumeSet failed', $e->getMessage(), 0);
                    $this->LogMessage(sprintf($this->Translate('Setting volume failed: %s'), $e->getMessage()), KL_ERROR);
                }
                break;

            case 'VolumeUp':
                if ((int)$Value === 1) {
                    try {
                        $this->GroupVolumeUp();
                    } catch (Throwable $e) {
                        $this->SendDebug('VolumeUp failed', $e->getMessage(), 0);
                        $this->LogMessage(sprintf($this->Translate('Volume up failed: %s'), $e->getMessage()), KL_ERROR);
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
                        $this->LogMessage(sprintf($this->Translate('Volume down failed: %s'), $e->getMessage()), KL_ERROR);
                    }
                }
                $this->SetValue('VolumeDown', 0);
                break;

            default:
                throw new Exception(sprintf($this->Translate('Unknown action: %s'), $Ident));
        }
    }

    public function ResetPlaylistSelection(): void
    {
        $this->SetTimerInterval('ResetPlaylist', 0);
        if (@$this->GetIDForIdent('Playlist') > 0) {
            $this->SetValue('Playlist', 0);
            $this->updateVisualization();
        }
    }

    public function ResetRadioSelection(): void
    {
        $this->SetTimerInterval('ResetRadio', 0);
        if (@$this->GetIDForIdent('Radio') > 0) {
            $this->SetValue('Radio', 0);
            $this->updateVisualization();
        }
    }

    public function ProcessFavoriteResolution(): void
    {
        $this->runFavoriteResolution();
    }

    public function ProcessCoverDownload(): void
    {
        $this->runCoverDownload();
    }

    public function ProcessGroupMemberVolumeRefresh(): void
    {
        $this->runGroupMemberVolumeRefresh();
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
            $text = substr($text, 0, 1800) . "\n" . $this->Translate('... (truncated)');
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
        if (!array_key_exists($mode, self::REPEAT_MODE_TO_VALUE)) {
            $this->SendDebug('Repeat', 'Unbekannter Repeat-Modus: ' . $mode, 0);
            return false;
        }
        $repeatValue = self::REPEAT_MODE_TO_VALUE[$mode];
        if (@$this->GetIDForIdent('Repeat') <= 0 || (int)$this->GetValue('Repeat') === $repeatValue) {
            return false;
        }
        $this->SetValue('Repeat', $repeatValue);
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

    // Die Auswahl beruht allein auf dem Variablenprofil; Playlist- und Radio-Adressen ermittelt der Player beim
    // Abspielen selbst. Der Configurator schreibt keine Zuordnungen mehr in die Player (Store-Reviewrichtlinie Punkt 13).
    private function readSelectionOptions(string $ident, string $profileProperty): ?array
    {
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $variable = IPS_GetVariable($variableId);
        if (($variable['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            return null;
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
            return null;
        }
        $profileData = IPS_GetVariableProfile($profile);
        if (($profileData['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            return null;
        }

        $options = [];
        foreach (($profileData['Associations'] ?? []) as $association) {
            $value = $association['Value'] ?? null;
            $name = $association['Name'] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_string($name)) {
                continue;
            }
            $itemId = (int)$value;
            if ($itemId <= 0 || (float)$itemId !== (float)$value || trim($name) === '') {
                continue;
            }
            $options[] = ['value' => $itemId, 'label' => trim($name)];
        }
        return $options;
    }

    private function updateMetadataVisibilityFromQueueState($state): void
    {
        $normalizedState = is_string($state) ? strtolower(trim($state)) : '';
        if ($normalizedState === 'idle') {
            $this->updateMetadataFromQueueItem(null);
        }
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
            throw new Exception($this->Translate('PlayRefreshedPlaylist could not be created or sent.'));
        }

        if (!is_string($response) || trim($response) === '') {
            throw new Exception($this->Translate('PlayRefreshedPlaylist did not return a valid response.'));
        }

        try {
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new Exception($this->Translate('PlayRefreshedPlaylist returned invalid JSON.'));
        }
        if (!is_array($result)) {
            throw new Exception($this->Translate('PlayRefreshedPlaylist response is not a JSON object.'));
        }
        if (($result['success'] ?? false) !== true) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : [];
            $code = is_string($error['code'] ?? null) && trim($error['code']) !== ''
                ? trim($error['code'])
                : 'UNKNOWN_ERROR';
            $message = is_string($error['message'] ?? null) && trim($error['message']) !== ''
                ? trim($error['message'])
                : $this->Translate('PlayRefreshedPlaylist failed.');
            throw new Exception($code . ': ' . $message);
        }
    }

    // --------- MA Commands ---------

    public function SetShuffle(bool $enabled): void
    {
        $this->sendApiRequest('players/cmd/shuffle', ['player_id' => $this->playerId(), 'shuffle_enabled' => $enabled]);
    }

    public function SetRepeat(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['off', 'one', 'all'], true)) {
            throw new InvalidArgumentException($this->Translate('Invalid repeat mode.'));
        }

        $this->sendApiRequest('players/cmd/repeat', ['player_id' => $this->playerId(), 'repeat_mode' => $mode]);
    }

    public function SetMute(bool $muted): void
    {
        $playerId = $this->playerId();

        try {
            $this->sendApiRequest('players/cmd/volume_mute', ['player_id' => $playerId, 'muted' => $muted]);
            return;
        } catch (Throwable $e) {
            // group_volume_mute leitet synchronisierte Mitglieder an ihren Leader weiter und ist daher nur
            // als Ersatz für Gruppen-Player geeignet, nicht als allgemeiner Mute-Befehl.
            if ($this->isConfiguredPlayerKnownGroup() || str_starts_with($playerId, 'syncgroup_')) {
                $this->SendDebug('Mute', 'volume_mute fehlgeschlagen; Gruppen-Mute wird verwendet', 0);
                $this->sendApiRequest('players/cmd/group_volume_mute', ['player_id' => $playerId, 'muted' => $muted]);
                return;
            }
            throw $e;
        }
    }

    public function SetVolumeLevel(int $level): void
    {
        if ($level < 0 || $level > 100) {
            throw new InvalidArgumentException($this->Translate('Volume level must be between 0 and 100.'));
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
            throw new Exception(sprintf($this->Translate('No valid active queue found for PlayerID=%s'), $playerId));
        }

        return $result;
    }

    private function ensureIntegerSelectorVariable(string $ident, string $name, string $profile, int $pos): bool
    {
        $existingId = @$this->GetIDForIdent($ident);
        $hadIntegerVariable = false;
        if ($existingId > 0) {
            $existingType = IPS_VariableExists($existingId)
                ? (IPS_GetVariable($existingId)['VariableType'] ?? -1)
                : -1;
            $hadIntegerVariable = $existingType === VARIABLETYPE_INTEGER;
            // Bis alpha 0.4 war Playlist eine String-Variable des Moduls; nur diese wird migriert.
            $isLegacyPlaylist = $ident === 'Playlist' && $existingType === VARIABLETYPE_STRING;
            if (!$hadIntegerVariable && !$isLegacyPlaylist) {
                $message = sprintf(
                    $this->Translate('The ident %s is used by an incompatible object. The selection variable was not created.'),
                    $ident
                );
                $this->SendDebug($ident, $message, 0);
                $this->LogMessage($message, KL_WARNING);
                return false;
            }
            if ($isLegacyPlaylist && !IPS_DeleteVariable($existingId)) {
                $message = sprintf($this->Translate('The legacy variable %s could not be replaced.'), $ident);
                $this->SendDebug($ident, $message, 0);
                $this->LogMessage($message, KL_ERROR);
                return false;
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

    private function assertIntegerVariable(string $ident): void
    {
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId <= 0
            || !IPS_VariableExists($variableId)
            || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            throw new Exception(sprintf(
                $this->Translate('The selection variable %s is not available.'),
                $ident
            ));
        }
    }

    private function resetInvalidMediaSelection(string $ident, string $profileProperty, string $resetTimer): void
    {
        if ($this->GetTimerInterval($resetTimer) > 0) {
            return;
        }
        $options = $this->readSelectionOptions($ident, $profileProperty);
        if ($options === null) {
            return;
        }
        $selectedItemId = (int)$this->GetValue($ident);
        if ($selectedItemId <= 0 || in_array($selectedItemId, array_column($options, 'value'), true)) {
            return;
        }

        $this->SetTimerInterval($resetTimer, 0);
        $this->SetValue($ident, 0);
    }

    private function resolveUriFromMap(int $itemId, string $mapProperty, string $refreshCommand): string
    {
        if ($itemId <= 0) {
            throw new Exception($this->Translate('Invalid item_id'));
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

        throw new Exception(sprintf($this->Translate('URI not found for item_id=%s'), $itemId));
    }

    private function PlayMediaUri(string $uri): void
    {
        if ($uri === '') {
            throw new Exception($this->Translate('Media URI is empty'));
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
            throw new Exception($this->Translate('Invalid item_id'));
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

        throw new Exception(sprintf($this->Translate('URI not found for item_id=%s'), $itemId));
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
            throw new Exception($this->Translate('PlayerID not configured'));
        }
        return $id;
    }
}
