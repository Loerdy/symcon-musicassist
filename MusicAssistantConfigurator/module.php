<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApiClient.php';

class MusicAssistantConfigurator extends IPSModule
{
    use MusicAssistantApiClient;

    private const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';
    private const CONNECTION_MODULE_ID = '{880534D6-998A-704B-DFD1-ABCD3D23B811}';
    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeString('ProfileNames', '');
        $this->RegisterAttributeString('PlaylistMap', '');
        $this->RegisterAttributeString('RadioMap', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Store-Reviewrichtlinie Punkt 3: Ohne verbundene Connection ist die Instanz gültig, aber noch nicht
        // einsatzbereit. Profilnamen und Zuordnungen werden bei der ersten Verwendung übernommen.
        try {
            $this->getConfiguratorConnectionId();
        } catch (Throwable $e) {
            $this->SendDebug('Configurator', 'Noch keine MusicAssistantConnection verbunden: ' . $e->getMessage(), 0);
            return;
        }
        try {
            $this->initializeProfileNames();
        } catch (Throwable $e) {
            $this->SendDebug('Profiles', $e->getMessage(), 0);
        }
        $this->initializePersistentMap('PlaylistMap', true);
        $this->initializePersistentMap('RadioMap', false);
    }

    public function ReceiveData($JSONString): string
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || ($packet['DataID'] ?? null) !== self::CONNECTION_EVENT) {
            return '';
        }
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $configurationError = null;
        $values = $this->buildPlayerListValues($configurationError);

        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => sprintf(
                        $this->Translate('New players cannot be created: %s'),
                        (string)$configurationError
                    ),
                    'visible' => $configurationError !== null
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Load playlists (create/update profile)',
                    'onClick' => '$connectionId = (int)(IPS_GetInstance($id)["ConnectionID"] ?? 0); '
                        . 'if ($connectionId > 0 && IPS_InstanceExists($connectionId) '
                        . '&& trim((string) IPS_GetProperty($connectionId, "Token")) === "") { '
                        . 'echo IPS_Translate($id, "Please enter the Bearer token in the Music Assistant Connection instance."); '
                        . '} else { try { MASS_SyncPlaylistsProfile($id); } catch (Throwable $e) { echo $e->getMessage(); } }'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Load radio stations (create/update profile)',
                    'onClick' => '$connectionId = (int)(IPS_GetInstance($id)["ConnectionID"] ?? 0); '
                        . 'if ($connectionId > 0 && IPS_InstanceExists($connectionId) '
                        . '&& trim((string) IPS_GetProperty($connectionId, "Token")) === "") { '
                        . 'echo IPS_Translate($id, "Please enter the Bearer token in the Music Assistant Connection instance."); '
                        . '} else { try { MASS_SyncRadiosProfile($id); } catch (Throwable $e) { echo $e->getMessage(); } }'
                ],

                [
                    'type'     => 'Configurator',
                    'name'     => 'Players',
                    'caption'  => 'Player',
                    'delete'   => true,
                    'rowCount' => 0,
                    'columns'  => [
                        ['caption' => 'Name',        'name' => 'name',        'width' => 'auto'],
                        ['caption' => 'Player ID',   'name' => 'player_id',   'width' => '360px'],
                        ['caption' => 'Provider',    'name' => 'provider',    'width' => '170px'],
                        ['caption' => 'Available',   'name' => 'available',   'width' => '85px'],
                        ['caption' => 'Status',      'name' => 'status',      'width' => '130px']
                    ],
                    'values'   => $values
                ]
            ]
        ];

        return json_encode($form);
    }

    public function CreatePlayer(string $playerId, string $name, int $targetCategoryId = 0): string
    {
        return $this->createPlayerInternal($playerId, $name, true, $targetCategoryId);
    }

    private function createPlayerInternal(
        string $playerId,
        string $name,
        bool $reloadForm,
        int $targetCategoryId = 0
    ): string
    {
        $playerId = trim($playerId);
        if ($playerId === '') {
            throw new Exception($this->Translate('Please select a player in the list first.'));
        }
        if ($targetCategoryId < 0 || ($targetCategoryId > 0 && !IPS_CategoryExists($targetCategoryId))) {
            throw new InvalidArgumentException($this->Translate('The specified target category is invalid or no longer exists.'));
        }

        $semaphore = 'MusicAssistantConfigurator.CreatePlayer.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            throw new Exception($this->Translate('Another player creation is already running. Please try again.'));
        }

        $newPlayerId = 0;
        try {
            $connectionId = $this->getConfiguratorConnectionId();
            $existingPlayerId = $this->findExistingPlayerInstance($playerId, $connectionId);
            if ($existingPlayerId > 0) {
                return sprintf($this->Translate('Player already exists as instance %d.'), $existingPlayerId);
            }

            if (!IPS_IsModuleCompatible(self::PLAYER_MODULE_ID, self::CONNECTION_MODULE_ID)) {
                throw new Exception($this->Translate('MusicAssistantPlayer and MusicAssistantConnection are not compatible.'));
            }

            // Abweichung nach Store-Reviewrichtlinie Punkt 7: MASS_CreatePlayer wird ausdrücklich vom Benutzer aufgerufen
            // und setzt nur die Erstkonfiguration der dabei neu angelegten Instanz, wie die native Configurator-Erstellung.
            $newPlayerId = IPS_CreateInstance(self::PLAYER_MODULE_ID);
            if ($newPlayerId <= 0) {
                throw new Exception($this->Translate('MusicAssistantPlayer could not be created.'));
            }
            if (!IPS_IsInstanceCompatible($newPlayerId, $connectionId)) {
                throw new Exception($this->Translate('The selected MusicAssistantConnection is not compatible with the player.'));
            }

            foreach ($this->playerConfiguration($playerId) as $property => $value) {
                if (!IPS_SetProperty($newPlayerId, $property, $value)) {
                    throw new Exception(sprintf($this->Translate('Player property could not be set: %s'), $property));
                }
            }
            if (!IPS_SetName($newPlayerId, 'MA Player - ' . (trim($name) !== '' ? trim($name) : $playerId))) {
                throw new Exception($this->Translate('Player name could not be set.'));
            }
            if ($targetCategoryId > 0 && !IPS_SetParent($newPlayerId, $targetCategoryId)) {
                throw new Exception($this->Translate('Player could not be moved to the selected target category.'));
            }

            $this->validateConfiguratorConnection($connectionId);
            if (!IPS_IsInstanceCompatible($newPlayerId, $connectionId)
                || !IPS_ConnectInstance($newPlayerId, $connectionId)) {
                throw new Exception($this->Translate('Player could not be connected to the MusicAssistantConnection.'));
            }
            if (!IPS_ApplyChanges($newPlayerId)) {
                throw new Exception($this->Translate('Player configuration could not be applied.'));
            }

            $createdPlayerId = $newPlayerId;
            $newPlayerId = 0;
            if ($reloadForm) {
                try {
                    $this->ReloadForm();
                } catch (Throwable $e) {
                    $this->SendDebug('CreatePlayer', 'Formular konnte nach der Erstellung nicht aktualisiert werden', 0);
                }
            }
            return sprintf($this->Translate('Player was created as instance %d.'), $createdPlayerId);
        } catch (Throwable $e) {
            if ($newPlayerId > 0 && IPS_InstanceExists($newPlayerId)) {
                try {
                    $this->rollbackCreatedPlayer($newPlayerId);
                } catch (Throwable $rollbackError) {
                    $this->SendDebug('CreatePlayer', $rollbackError->getMessage(), 0);
                    throw new Exception($e->getMessage() . ' ' . sprintf($this->Translate('Rollback failed: %s'), $rollbackError->getMessage()));
                }
            }
            $this->SendDebug('CreatePlayer', $e->getMessage(), 0);
            throw $e;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    public function SyncPlaylistsProfile(): void
    {
        $semaphore = 'MusicAssistantConfigurator.SyncPlaylistsProfile.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            throw new Exception($this->Translate('Another playlist synchronization is already running. Please try again.'));
        }

        try {
            $this->syncPlaylistsProfileInternal();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function syncPlaylistsProfileInternal(): void
    {
        $profile = $this->playlistProfileName();

        $result = $this->sendApiRequest('music/playlists/library_items', ['summary' => false]);

        $items = null;
        if (is_array($result) && $this->isList($result)) {
            $items = $result;
        } elseif (is_array($result)
            && isset($result['items'])
            && is_array($result['items'])
            && $this->isList($result['items'])) {
            $items = $result['items'];
        }
        if ($items === null) {
            throw new Exception($this->Translate('The playlist query did not return a complete list. Existing data was kept.'));
        }

        $map = [];
        $associations = [];
        $count = 0;
        foreach ($items as $pl) {
            if ($count >= 127) break;

            if (!is_array($pl)
                || !is_array($pl['provider_mappings'] ?? null)) {
                throw new Exception($this->Translate('The playlist query returned an incomplete entry. Existing data was kept.'));
            }

            $idStr = (string)($pl['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($pl['name'] ?? ('Playlist ' . $idStr));
            $uri   = (string)($pl['uri'] ?? '');

            if ($id <= 0 || $uri === '') {
                throw new Exception($this->Translate('The playlist query returned an incomplete entry. Existing data was kept.'));
            }

            $map[(string)$id] = [
                'uri'               => $uri,
                'provider_mappings' => $pl['provider_mappings']
            ];
            $associations[(string)$id] = $name;
            $count++;
        }

        $mapJson = json_encode((object)$map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->ensureIntegerProfile($profile);

        // Standard-Icon für das Profil
        if (!IPS_SetVariableProfileIcon($profile, 'list-music')) {
            throw new Exception(sprintf($this->Translate('The icon of the playlist profile %s could not be set.'), $profile));
        }

        // Associations löschen
        $p = IPS_GetVariableProfile($profile);
        foreach (($p['Associations'] ?? []) as $assoc) {
            if (!IPS_SetVariableProfileAssociation($profile, (float)$assoc['Value'], '', '', -1)) {
                throw new Exception(sprintf(
                    $this->Translate('The existing association %s of the playlist profile %s could not be removed.'),
                    $assoc['Value'],
                    $profile
                ));
            }
        }

        // 0 = "-" (Neutralwert)
        if (!IPS_SetVariableProfileAssociation($profile, 0, '-', '', -1)) {
            throw new Exception(sprintf($this->Translate('The neutral association of the playlist profile %s could not be set.'), $profile));
        }

        foreach ($associations as $id => $name) {
            // einzelne Playlist-Einträge ohne eigenes Icon -> Profil-Icon gilt als Default
            if (!IPS_SetVariableProfileAssociation($profile, (float)$id, $name, '', -1)) {
                throw new Exception(sprintf(
                    $this->Translate('The association %s of the playlist profile %s could not be set.'),
                    $id,
                    $profile
                ));
            }
        }

        if (!$this->WriteAttributeString('PlaylistMap', $mapJson)) {
            throw new Exception($this->Translate('PlaylistMap could not be saved persistently.'));
        }
        $this->notifyProfilesUpdated();
        $this->SendDebug('Playlists', 'Profile=' . $profile . ' entries=' . $count, 0);
        $this->ReloadForm();
    }

    public function SyncRadiosProfile(): void
    {
        $semaphore = 'MusicAssistantConfigurator.SyncRadiosProfile.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            throw new Exception($this->Translate('Another radio synchronization is already running. Please try again.'));
        }

        try {
            $this->syncRadiosProfileInternal();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function syncRadiosProfileInternal(): void
    {
        $profile = $this->radioProfileName();

        $result = $this->sendApiRequest('music/radios/library_items');

        $items = null;
        if (is_array($result) && $this->isList($result)) {
            $items = $result;
        } elseif (is_array($result)
            && isset($result['items'])
            && is_array($result['items'])
            && $this->isList($result['items'])) {
            $items = $result['items'];
        }
        if ($items === null) {
            throw new Exception($this->Translate('The radio query did not return a complete list. Existing data was kept.'));
        }

        $map = [];
        $associations = [];
        $count = 0;
        foreach ($items as $r) {
            if ($count >= 127) break;

            if (!is_array($r)) {
                throw new Exception($this->Translate('The radio query returned an incomplete entry. Existing data was kept.'));
            }

            $idStr = (string)($r['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($r['name'] ?? ('Radio ' . $idStr));
            $uri   = (string)($r['uri'] ?? '');

            if ($id <= 0 || $uri === '') {
                throw new Exception($this->Translate('The radio query returned an incomplete entry. Existing data was kept.'));
            }

            $map[(string)$id] = $uri;
            $associations[(string)$id] = $name;
            $count++;
        }
        $this->ensureIntegerProfile($profile);

        // Standard-Icon für das Profil
        if (!IPS_SetVariableProfileIcon($profile, 'radio')) {
            throw new Exception(sprintf($this->Translate('The icon of the radio profile %s could not be set.'), $profile));
        }

        // Associations löschen
        $p = IPS_GetVariableProfile($profile);
        foreach (($p['Associations'] ?? []) as $assoc) {
            if (!IPS_SetVariableProfileAssociation($profile, (float)$assoc['Value'], '', '', -1)) {
                throw new Exception(sprintf(
                    $this->Translate('The existing association %s of the radio profile %s could not be removed.'),
                    $assoc['Value'],
                    $profile
                ));
            }
        }

        // 0 = "-" (Neutralwert)
        if (!IPS_SetVariableProfileAssociation($profile, 0, '-', '', -1)) {
            throw new Exception(sprintf($this->Translate('The neutral association of the radio profile %s could not be set.'), $profile));
        }

        foreach ($associations as $id => $name) {
            // einzelne Radios ohne eigenes Icon -> Profil-Icon gilt als Default
            if (!IPS_SetVariableProfileAssociation($profile, (float)$id, $name, '', -1)) {
                throw new Exception(sprintf(
                    $this->Translate('The association %s of the radio profile %s could not be set.'),
                    $id,
                    $profile
                ));
            }
        }

        $mapJson = json_encode((object)$map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$this->WriteAttributeString('RadioMap', $mapJson)) {
            throw new Exception($this->Translate('RadioMap could not be saved persistently.'));
        }
        $this->notifyProfilesUpdated();
        $this->SendDebug('Radios', 'Profile=' . $profile . ' entries=' . $count, 0);
        $this->ReloadForm();
    }

    private function ensureIntegerProfile(string $profile): void
    {
        if (IPS_VariableProfileExists($profile)) {
            $p = IPS_GetVariableProfile($profile);
            if (($p['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                throw new Exception(sprintf(
                    $this->Translate('Variable profile %s does not have the expected type Integer. Synchronization was aborted; the profile was not changed.'),
                    $profile
                ));
            }
        }
        if (!IPS_VariableProfileExists($profile)) {
            if (!IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER)) {
                throw new Exception(sprintf($this->Translate('The variable profile %s could not be created.'), $profile));
            }
        }
    }

    private function playlistProfileName(): string
    {
        $profileNames = $this->initializeProfileNames();
        if ($profileNames === null) {
            throw new Exception($this->Translate('Playlist/radio profile names could not be initialized yet.'));
        }
        return $profileNames['playlist'];
    }

    private function radioProfileName(): string
    {
        $profileNames = $this->initializeProfileNames();
        if ($profileNames === null) {
            throw new Exception($this->Translate('Playlist/radio profile names could not be initialized yet.'));
        }
        return $profileNames['radio'];
    }

    /**
     * @return array{state:string, profileNames:?array, reason:string}
     */
    private function readProfileNames(): array
    {
        $value = trim($this->ReadAttributeString('ProfileNames'));
        if ($value === '') {
            return ['state' => 'empty', 'profileNames' => null, 'reason' => ''];
        }
        try {
            $profileNames = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                'state' => 'invalid',
                'profileNames' => null,
                'reason' => $this->Translate('invalid JSON')
            ];
        }
        if (!is_array($profileNames)
            || !is_string($profileNames['playlist'] ?? null)
            || trim($profileNames['playlist']) === ''
            || !is_string($profileNames['radio'] ?? null)
            || trim($profileNames['radio']) === '') {
            return [
                'state' => 'invalid',
                'profileNames' => null,
                'reason' => $this->Translate('unknown data structure')
            ];
        }
        return [
            'state' => 'valid',
            'profileNames' => [
                'playlist' => trim($profileNames['playlist']),
                'radio'    => trim($profileNames['radio'])
            ],
            'reason' => ''
        ];
    }

    private function initializeProfileNames(): ?array
    {
        $storedProfileNames = $this->readProfileNames();
        if ($storedProfileNames['state'] === 'valid') {
            return $storedProfileNames['profileNames'];
        }
        if ($storedProfileNames['state'] === 'invalid') {
            $this->reportInvalidProfileNames($storedProfileNames['reason']);
            return null;
        }

        $connectionId = $this->getConfiguratorConnectionId();
        $playerProfiles = $this->profileNamesFromConnectedPlayers($connectionId);
        if ($playerProfiles['profileNames'] !== null
            && !$playerProfiles['conflict']
            && !$playerProfiles['unavailable']) {
            return $this->storeProfileNames($playerProfiles['profileNames'], 'verbundenen Playern');
        }

        if ($playerProfiles['conflict']) {
            throw new Exception($this->Translate('Conflicting playlist/radio profile names found on connected players.'));
        }

        if ($playerProfiles['unavailable']) {
            throw new Exception($this->Translate('Connected players are not yet fully available while initializing the profile names.'));
        }

        $suffix = substr(md5('connection:' . $connectionId), 0, 8);
        return $this->storeProfileNames([
            'playlist' => 'MA.Playlists.' . $suffix,
            'radio'    => 'MA.Radios.' . $suffix
        ], 'der MusicAssistantConnection');
    }

    private function profileNamesFromConnectedPlayers(int $connectionId): array
    {
        $pairs = [];
        $unavailable = false;
        foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $playerInstanceId) {
            if (!IPS_InstanceExists($playerInstanceId)) {
                $unavailable = true;
                continue;
            }
            $instance = @IPS_GetInstance($playerInstanceId);
            if (!is_array($instance)) {
                $unavailable = true;
                continue;
            }
            if (!array_key_exists('ConnectionID', $instance)) {
                $unavailable = true;
                continue;
            }
            if ((int)($instance['ConnectionID'] ?? 0) !== $connectionId) {
                continue;
            }
            $configurationJson = @IPS_GetConfiguration($playerInstanceId);
            if (!is_string($configurationJson) || $configurationJson === '') {
                $unavailable = true;
                continue;
            }
            $configuration = json_decode($configurationJson, true);
            if (!is_array($configuration)) {
                $unavailable = true;
                continue;
            }
            $playlist = is_string($configuration['PlaylistProfile'] ?? null)
                ? trim($configuration['PlaylistProfile'])
                : '';
            $radio = is_string($configuration['RadioProfile'] ?? null)
                ? trim($configuration['RadioProfile'])
                : '';
            if ($playlist === '' || $radio === '') {
                continue;
            }
            $pairs[$playlist . "\0" . $radio] = [
                'playlist' => $playlist,
                'radio'    => $radio
            ];
        }
        return [
            'profileNames' => count($pairs) === 1 ? array_values($pairs)[0] : null,
            'conflict'     => count($pairs) > 1,
            'unavailable'  => $unavailable
        ];
    }

    private function storeProfileNames(array $profileNames, string $source): ?array
    {
        $existing = $this->readProfileNames();
        if ($existing['state'] === 'valid') {
            return $existing['profileNames'];
        }
        if ($existing['state'] === 'invalid') {
            $this->reportInvalidProfileNames($existing['reason']);
            return null;
        }
        $value = json_encode($profileNames, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$this->WriteAttributeString('ProfileNames', $value)) {
            throw new Exception($this->Translate('Playlist/radio profile names could not be saved.'));
        }
        $this->SendDebug('Profiles', 'Profilnamen übernommen aus ' . $source, 0);
        return $profileNames;
    }

    private function reportInvalidProfileNames(string $reason): void
    {
        $message = sprintf(
            $this->Translate('The persistent attribute ProfileNames contains %s; initialization was aborted and the original data was kept.'),
            $reason
        );
        $this->SendDebug('Profiles', $message, 0);
        $this->LogMessage($message, KL_ERROR);
    }

    private function initializePersistentMap(string $attribute, bool $playlist): bool
    {
        $stored = $this->readMap($this->ReadAttributeString($attribute), $playlist);
        if ($stored['state'] === 'valid') {
            return true;
        }
        if ($stored['state'] === 'invalid') {
            $this->reportMapError(
                $attribute,
                sprintf($this->Translate('The persistent attribute is corrupted (%s). The original data was kept.'), $stored['reason'])
            );
            return false;
        }

        try {
            $connectionId = $this->getConfiguratorConnectionId();
            $maps = [];
            foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $playerInstanceId) {
                if (!IPS_InstanceExists($playerInstanceId)) {
                    continue;
                }
                $instance = IPS_GetInstance($playerInstanceId);
                if (!is_array($instance) || (int)($instance['ConnectionID'] ?? 0) !== $connectionId) {
                    continue;
                }

                $playerMap = $this->readMap((string)IPS_GetProperty($playerInstanceId, $attribute), $playlist);
                if ($playerMap['state'] !== 'valid') {
                    throw new Exception(sprintf(
                        $this->Translate('Player instance %d does not contain a valid %s (%s).'),
                        $playerInstanceId,
                        $attribute,
                        $playerMap['reason'] !== '' ? $playerMap['reason'] : $this->Translate('not saved')
                    ));
                }
                $matchingMap = null;
                foreach ($maps as $index => $candidate) {
                    if ($candidate['map'] == $playerMap['map']) {
                        $matchingMap = $index;
                        break;
                    }
                }
                if ($matchingMap === null) {
                    $maps[] = [
                        'map'       => $playerMap['map'],
                        'json'      => $playerMap['json'],
                        'playerIds' => [$playerInstanceId]
                    ];
                } else {
                    $maps[$matchingMap]['playerIds'][] = $playerInstanceId;
                }
            }

            if (count($maps) === 0) {
                return true;
            }
            if (count($maps) > 1) {
                $details = [];
                foreach ($maps as $map) {
                    $details[] = implode(', ', $map['playerIds']);
                }
                throw new Exception(sprintf(
                    $this->Translate('Connected players contain conflicting maps (instances: %s).'),
                    implode(' / ', $details)
                ));
            }

            $mapJson = $maps[0]['json'];
            if (!$this->WriteAttributeString($attribute, $mapJson)) {
                throw new Exception($this->Translate('The map could not be saved as a persistent attribute.'));
            }
            $this->SendDebug($attribute, 'Persistente Map aus verbundenen Playern übernommen.', 0);
            return true;
        } catch (Throwable $e) {
            $this->reportMapError($attribute, sprintf($this->Translate('Migration not performed: %s'), $e->getMessage()));
            return false;
        }
    }

    /**
     * @return array{state:string, map:array, json:string, reason:string}
     */
    private function readMap(string $value, bool $playlist): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['state' => 'missing', 'map' => [], 'json' => '', 'reason' => ''];
        }

        try {
            $object = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            $map = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => $this->Translate('invalid JSON')];
        }
        if (!is_object($object) || !is_array($map)) {
            return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => $this->Translate('the map is not a JSON object')];
        }

        foreach ($map as $itemId => $entry) {
            $itemId = (string)$itemId;
            if ((int)$itemId <= 0 || (string)(int)$itemId !== $itemId) {
                return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => $this->Translate('invalid entry ID')];
            }
            if ($playlist) {
                $legacyEntry = is_string($entry) && trim($entry) !== '';
                $currentEntry = is_array($entry)
                    && is_string($entry['uri'] ?? null)
                    && trim($entry['uri']) !== ''
                    && (!array_key_exists('provider_mappings', $entry)
                        || is_array($entry['provider_mappings']));
                if (!$legacyEntry && !$currentEntry) {
                    return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => $this->Translate('invalid playlist entry')];
                }
            } elseif (!is_string($entry) || trim($entry) === '') {
                return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => $this->Translate('invalid radio entry')];
            }
        }

        ksort($map, SORT_NUMERIC);
        return [
            'state' => 'valid',
            'map' => $map,
            'json' => json_encode((object)$map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'reason' => ''
        ];
    }

    private function getPersistentMapJson(string $attribute, bool $playlist): string
    {
        $stored = $this->readMap($this->ReadAttributeString($attribute), $playlist);
        if ($stored['state'] === 'missing') {
            if (!$this->initializePersistentMap($attribute, $playlist)) {
                throw new Exception(sprintf($this->Translate('%s could not be migrated safely from the connected players.'), $attribute));
            }
            $stored = $this->readMap($this->ReadAttributeString($attribute), $playlist);
        }
        if ($stored['state'] === 'missing') {
            return '{}';
        }
        if ($stored['state'] !== 'valid') {
            throw new Exception(sprintf(
                $this->Translate('%s contains corrupted persistent data (%s).'),
                $attribute,
                $stored['reason']
            ));
        }
        return $stored['json'];
    }

    private function reportMapError(string $attribute, string $message): void
    {
        $message = $attribute . ': ' . $message;
        $this->SendDebug($attribute, $message, 0);
        $this->LogMessage($message, KL_ERROR);
    }

    private function buildPlayerListValues(?string &$configurationError = null): array
    {
        $players = [];
        $playersLoaded = false;
        $connectionId = 0;
        try {
            $connectionId = $this->getConfiguratorConnectionId();
            $result = @$this->sendApiRequest('players/all');

            if (is_array($result) && $this->isList($result)) {
                $players = $result;
                $playersLoaded = true;
            } elseif (is_array($result) && isset($result['items']) && is_array($result['items'])) {
                $players = $result['items'];
                $playersLoaded = true;
            }
        } catch (Throwable $e) {
            $this->SendDebug('Players', 'players/all failed: ' . $e->getMessage(), 0);
        }

        // Fehler in Profilnamen oder Maps dürfen das Formular nicht verhindern; die Liste bleibt ohne Erstellung nutzbar.
        $baseConfiguration = null;
        if (count($players) > 0) {
            try {
                $baseConfiguration = $this->playerConfigurationBase();
            } catch (Throwable $e) {
                $configurationError = $e->getMessage();
                $this->SendDebug('Players', 'Player-Konfiguration nicht verfügbar: ' . $e->getMessage(), 0);
            }
        }

        $rows = [];
        $listedInstanceIds = [];
        $listedPlayerIds = [];
        foreach ($players as $p) {
            $playerId = (string)($p['player_id'] ?? $p['id'] ?? '');
            if ($playerId === '') continue;

            $name     = (string)($p['name'] ?? $playerId);
            $provider = (string)($p['provider'] ?? '');
            $avail    = (bool)($p['available'] ?? false);

            $instanceId = $this->findExistingPlayerInstance($playerId, $connectionId);
            if ($instanceId > 0) {
                $listedInstanceIds[$instanceId] = true;
            }
            $listedPlayerIds[$playerId] = true;

            $row = [
                'name'        => $name,
                'player_id'   => $playerId,
                'provider'    => $provider,
                'available'   => $avail ? $this->Translate('Yes') : $this->Translate('No'),
                'status'      => $instanceId > 0 ? $this->Translate('Existing') : $this->Translate('Not created'),
                'instanceID'  => $instanceId
            ];
            if ($baseConfiguration !== null) {
                $row['create'] = [
                    'moduleID'      => self::PLAYER_MODULE_ID,
                    'configuration' => ['PlayerID' => $playerId] + $baseConfiguration,
                    'name'          => 'MA Player - ' . $name
                ];
            }
            $rows[] = $row;
        }

        // Store-Reviewrichtlinie Punkt 15: Alle Player dieser Connection werden gelistet, auch wenn Music Assistant
        // sie nicht (mehr) meldet oder gerade nicht erreichbar ist.
        if ($connectionId > 0) {
            foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $instanceId) {
                if (isset($listedInstanceIds[$instanceId]) || !IPS_InstanceExists($instanceId)
                    || (int)(IPS_GetInstance($instanceId)['ConnectionID'] ?? 0) !== $connectionId) {
                    continue;
                }
                $playerId = trim((string)IPS_GetProperty($instanceId, 'PlayerID'));
                if (!$playersLoaded) {
                    $status = $this->Translate('Music Assistant not reachable');
                } elseif ($playerId !== '' && isset($listedPlayerIds[$playerId])) {
                    $status = $this->Translate('Duplicate instance');
                } else {
                    $status = $this->Translate('Not found in Music Assistant');
                }
                $rows[] = [
                    'name'       => IPS_GetName($instanceId),
                    'player_id'  => $playerId,
                    'provider'   => '',
                    'available'  => $playersLoaded ? $this->Translate('No') : '',
                    'status'     => $status,
                    'instanceID' => $instanceId
                ];
            }
        }

        return $rows;
    }

    private function playerConfiguration(string $playerId): array
    {
        return ['PlayerID' => $playerId] + $this->playerConfigurationBase();
    }

    private function playerConfigurationBase(): array
    {
        return [
            'PlaylistProfile' => $this->playlistProfileName(),
            'PlaylistMap'     => $this->getPersistentMapJson('PlaylistMap', true),
            'RadioProfile'    => $this->radioProfileName(),
            'RadioMap'        => $this->getPersistentMapJson('RadioMap', false)
        ];
    }

    // Store-Reviewrichtlinie Punkt 13: Der Configurator ändert keine Eigenschaften bestehender Player. Er meldet
    // neu geladene Profile über die Connection; die Player aktualisieren Auswahl und Kachel daraufhin selbst.
    private function notifyProfilesUpdated(): void
    {
        try {
            $response = @$this->SendDataToParent(json_encode([
                'DataID'  => self::CONNECTION_REQUEST,
                'Command' => 'ProfilesUpdated'
            ], JSON_THROW_ON_ERROR));
            $result = is_string($response) ? json_decode($response, true) : null;
            if (!is_array($result) || ($result['success'] ?? false) !== true) {
                $this->SendDebug('Profiles', 'Profilaktualisierung konnte nicht an die Player gemeldet werden', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Profiles', 'Profilaktualisierung konnte nicht an die Player gemeldet werden', 0);
        }
    }

    private function getConfiguratorConnectionId(): int
    {
        $configurator = IPS_GetInstance($this->InstanceID);
        if (!is_array($configurator)) {
            throw new Exception($this->Translate('The configurator instance could not be read.'));
        }
        $connectionId = (int)($configurator['ConnectionID'] ?? 0);
        if ($connectionId <= 0) {
            throw new Exception($this->Translate('The configurator is not connected to a MusicAssistantConnection.'));
        }
        if (!IPS_InstanceExists($connectionId)) {
            throw new Exception($this->Translate('The MusicAssistantConnection of the configurator no longer exists.'));
        }
        $connection = IPS_GetInstance($connectionId);
        if (!is_array($connection)
            || ($connection['ModuleInfo']['ModuleID'] ?? null) !== self::CONNECTION_MODULE_ID) {
            throw new Exception($this->Translate('The configurator is not connected to a valid MusicAssistantConnection.'));
        }
        return $connectionId;
    }

    private function validateConfiguratorConnection(int $connectionId): void
    {
        if ($this->getConfiguratorConnectionId() !== $connectionId) {
            throw new Exception($this->Translate('The MusicAssistantConnection of the configurator was changed in the meantime.'));
        }
    }

    private function rollbackCreatedPlayer(int $playerId): void
    {
        $instance = @IPS_GetInstance($playerId);
        if (is_array($instance) && (int)($instance['ConnectionID'] ?? 0) > 0
            && !IPS_DisconnectInstance($playerId)) {
            throw new Exception(sprintf($this->Translate('Incomplete player instance %d could not be disconnected.'), $playerId));
        }
        // Alle Unterobjekte stammen aus der im aktuellen Vorgang neu erstellten Instanz (z. B. Variablen und Cover-Medium).
        foreach (IPS_GetChildrenIDs($playerId) as $childId) {
            $objectType = IPS_GetObject($childId)['ObjectType'] ?? -1;
            if ($objectType === OBJECTTYPE_VARIABLE) {
                $deleted = IPS_DeleteVariable($childId);
            } elseif ($objectType === OBJECTTYPE_MEDIA) {
                $deleted = IPS_DeleteMedia($childId, true);
            } else {
                $deleted = false;
            }
            if (!$deleted) {
                throw new Exception(sprintf(
                    $this->Translate('Child object %d of the incomplete player instance %d could not be removed.'),
                    $childId,
                    $playerId
                ));
            }
        }
        if (!IPS_DeleteInstance($playerId)) {
            throw new Exception(sprintf($this->Translate('Incompletely created player instance %d could not be removed.'), $playerId));
        }
    }

    private function findExistingPlayerInstance(string $playerId, int $connectionId): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID);
        foreach ($ids as $id) {
            $instance = IPS_GetInstance($id);
            if (!is_array($instance) || (int)($instance['ConnectionID'] ?? 0) !== $connectionId) {
                continue;
            }
            $cfg = IPS_GetConfiguration($id);
            $arr = json_decode($cfg, true);
            if (is_array($arr) && (string)($arr['PlayerID'] ?? '') === $playerId) {
                return (int)$id;
            }
        }
        return 0;
    }
}
