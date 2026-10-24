<?php
declare(strict_types=1);

class MusicAssistantConfigurator extends IPSModule
{
    private const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';
    private const CONNECTION_MODULE_ID = '{880534D6-998A-704B-DFD1-ABCD3D23B811}';
    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('TargetCategoryID', 0);

        $this->RegisterAttributeString('ProfileNames', '');
        $this->RegisterAttributeString('PlaylistMap', '');
        $this->RegisterAttributeString('RadioMap', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
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
        $values = $this->buildPlayerListValues();

        $form = [
            'elements' => [
                [
                    'type'    => 'SelectCategory',
                    'name'    => 'TargetCategoryID',
                    'caption' => 'Zielordner für neue Player'
                ],

                [
                    'type'    => 'Button',
                    'caption' => 'Playlists laden (Profil erstellen/aktualisieren)',
                    'onClick' => 'MASS_SyncPlaylistsProfile($id);'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Radios laden (Profil erstellen/aktualisieren)',
                    'onClick' => 'MASS_SyncRadiosProfile($id);'
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
                        ['caption' => 'Verfügbar',   'name' => 'available',   'width' => '85px'],
                        ['caption' => 'Status',      'name' => 'status',      'width' => '130px']
                    ],
                    'values'   => $values
                ]
            ]
        ];

        return json_encode($form);
    }

    public function CreatePlayer(string $playerId, string $name): string
    {
        return $this->createPlayerInternal($playerId, $name, true);
    }

    private function createPlayerInternal(string $playerId, string $name, bool $reloadForm): string
    {
        $playerId = trim($playerId);
        if ($playerId === '') {
            throw new Exception('Bitte zuerst einen Player in der Liste auswählen.');
        }

        $semaphore = 'MusicAssistantConfigurator.CreatePlayer.' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            throw new Exception('Eine andere Player-Erstellung läuft bereits. Bitte erneut versuchen.');
        }

        $newPlayerId = 0;
        try {
            $connectionId = $this->getConfiguratorConnectionId();
            $existingPlayerId = $this->findExistingPlayerInstance($playerId, $connectionId);
            if ($existingPlayerId > 0) {
                return 'Player existiert bereits als Instanz ' . $existingPlayerId . '.';
            }

            $targetCategoryId = $this->getValidTargetCategoryId();
            if (!IPS_IsModuleCompatible(self::PLAYER_MODULE_ID, self::CONNECTION_MODULE_ID)) {
                throw new Exception('MusicAssistantPlayer und MusicAssistantConnection sind nicht kompatibel.');
            }

            $newPlayerId = IPS_CreateInstance(self::PLAYER_MODULE_ID);
            if ($newPlayerId <= 0) {
                throw new Exception('MusicAssistantPlayer konnte nicht erstellt werden.');
            }
            if (!IPS_IsInstanceCompatible($newPlayerId, $connectionId)) {
                throw new Exception('Die ausgewählte MusicAssistantConnection ist nicht mit dem Player kompatibel.');
            }

            foreach ($this->playerConfiguration($playerId) as $property => $value) {
                if (!IPS_SetProperty($newPlayerId, $property, $value)) {
                    throw new Exception('Player-Eigenschaft konnte nicht gesetzt werden: ' . $property);
                }
            }
            if (!IPS_SetName($newPlayerId, 'MA Player - ' . (trim($name) !== '' ? trim($name) : $playerId))) {
                throw new Exception('Playername konnte nicht gesetzt werden.');
            }
            if ($targetCategoryId > 0 && !IPS_SetParent($newPlayerId, $targetCategoryId)) {
                throw new Exception('Player konnte nicht in den ausgewählten Zielordner verschoben werden.');
            }

            $this->validateConfiguratorConnection($connectionId);
            if (!IPS_IsInstanceCompatible($newPlayerId, $connectionId)
                || !IPS_ConnectInstance($newPlayerId, $connectionId)) {
                throw new Exception('Player konnte nicht mit der MusicAssistantConnection verbunden werden.');
            }
            if (!IPS_ApplyChanges($newPlayerId)) {
                throw new Exception('Player-Konfiguration konnte nicht übernommen werden.');
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
            return 'Player wurde als Instanz ' . $createdPlayerId . ' erstellt.';
        } catch (Throwable $e) {
            if ($newPlayerId > 0 && IPS_InstanceExists($newPlayerId)) {
                try {
                    $this->rollbackCreatedPlayer($newPlayerId);
                } catch (Throwable $rollbackError) {
                    $this->SendDebug('CreatePlayer', $rollbackError->getMessage(), 0);
                    throw new Exception($e->getMessage() . ' Rollback fehlgeschlagen: ' . $rollbackError->getMessage());
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
        if ($items === null || count($items) === 0) {
            throw new Exception('Playlist-Abfrage lieferte keine vollständige Liste. Vorhandene Daten wurden beibehalten.');
        }

        $map = [];
        $associations = [];
        $count = 0;
        foreach ($items as $pl) {
            if ($count >= 127) break;

            if (!is_array($pl)
                || !is_array($pl['provider_mappings'] ?? null)) {
                throw new Exception('Playlist-Abfrage lieferte einen unvollständigen Eintrag. Vorhandene Daten wurden beibehalten.');
            }

            $idStr = (string)($pl['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($pl['name'] ?? ('Playlist ' . $idStr));
            $uri   = (string)($pl['uri'] ?? '');

            if ($id <= 0 || $uri === '') {
                throw new Exception('Playlist-Abfrage lieferte einen unvollständigen Eintrag. Vorhandene Daten wurden beibehalten.');
            }

            $map[(string)$id] = [
                'uri'               => $uri,
                'provider_mappings' => $pl['provider_mappings']
            ];
            $associations[(string)$id] = $name;
            $count++;
        }

        if ($count === 0) {
            throw new Exception('Playlist-Abfrage lieferte keine verwendbaren Einträge. Vorhandene Daten wurden beibehalten.');
        }

        $mapJson = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->ensureIntegerProfile($profile);

        // Standard-Icon für das Profil
        IPS_SetVariableProfileIcon($profile, 'list-music');

        // Associations löschen
        $p = IPS_GetVariableProfile($profile);
        foreach (($p['Associations'] ?? []) as $assoc) {
            IPS_SetVariableProfileAssociation($profile, (float)$assoc['Value'], '', '', -1);
        }

        // 0 = "-" (Neutralwert)
        IPS_SetVariableProfileAssociation($profile, 0, '-', '', -1);

        foreach ($associations as $id => $name) {
            // einzelne Playlist-Einträge ohne eigenes Icon -> Profil-Icon gilt als Default
            IPS_SetVariableProfileAssociation($profile, (float)$id, $name, '', -1);
        }

        if (!$this->WriteAttributeString('PlaylistMap', $mapJson)) {
            throw new Exception('PlaylistMap konnte nicht persistent gespeichert werden.');
        }
        $this->updateConnectedPlayerPlaylistMaps($mapJson);
        $this->SendDebug('Playlists', 'Profile=' . $profile . ' entries=' . $count, 0);
        $this->ReloadForm();
    }

    public function SyncRadiosProfile(): void
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
        if ($items === null || count($items) === 0) {
            throw new Exception('Radio-Abfrage lieferte keine vollständige Liste. Vorhandene Daten wurden beibehalten.');
        }

        $map = [];
        $associations = [];
        $count = 0;
        foreach ($items as $r) {
            if ($count >= 127) break;

            if (!is_array($r)) {
                throw new Exception('Radio-Abfrage lieferte einen unvollständigen Eintrag. Vorhandene Daten wurden beibehalten.');
            }

            $idStr = (string)($r['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($r['name'] ?? ('Radio ' . $idStr));
            $uri   = (string)($r['uri'] ?? '');

            if ($id <= 0 || $uri === '') {
                throw new Exception('Radio-Abfrage lieferte einen unvollständigen Eintrag. Vorhandene Daten wurden beibehalten.');
            }

            $map[(string)$id] = $uri;
            $associations[(string)$id] = $name;
            $count++;
        }
        if ($count === 0) {
            throw new Exception('Radio-Abfrage lieferte keine verwendbaren Einträge. Vorhandene Daten wurden beibehalten.');
        }

        $this->ensureIntegerProfile($profile);

        // Standard-Icon für das Profil
        IPS_SetVariableProfileIcon($profile, 'radio');

        // Associations löschen
        $p = IPS_GetVariableProfile($profile);
        foreach (($p['Associations'] ?? []) as $assoc) {
            IPS_SetVariableProfileAssociation($profile, (float)$assoc['Value'], '', '', -1);
        }

        // 0 = "-" (Neutralwert)
        IPS_SetVariableProfileAssociation($profile, 0, '-', '', -1);

        foreach ($associations as $id => $name) {
            // einzelne Radios ohne eigenes Icon -> Profil-Icon gilt als Default
            IPS_SetVariableProfileAssociation($profile, (float)$id, $name, '', -1);
        }

        $mapJson = json_encode($map, JSON_UNESCAPED_SLASHES);
        if (!$this->WriteAttributeString('RadioMap', $mapJson)) {
            throw new Exception('RadioMap konnte nicht persistent gespeichert werden.');
        }
        $this->updateConnectedPlayerRadioMaps($mapJson);
        $this->SendDebug('Radios', 'Profile=' . $profile . ' entries=' . $count, 0);
        $this->ReloadForm();
    }

    private function ensureIntegerProfile(string $profile): void
    {
        if (IPS_VariableProfileExists($profile)) {
            $p = IPS_GetVariableProfile($profile);
            if (($p['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                throw new Exception(
                    'Variablenprofil ' . $profile
                    . ' hat nicht den erwarteten Typ Integer. Synchronisierung wurde abgebrochen; das Profil blieb unverändert.'
                );
            }
        }
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
        }
    }

    private function playlistProfileName(): string
    {
        $profileNames = $this->initializeProfileNames();
        if ($profileNames === null) {
            throw new Exception('Playlist-/Radio-Profilnamen konnten noch nicht initialisiert werden.');
        }
        return $profileNames['playlist'];
    }

    private function radioProfileName(): string
    {
        $profileNames = $this->initializeProfileNames();
        if ($profileNames === null) {
            throw new Exception('Playlist-/Radio-Profilnamen konnten noch nicht initialisiert werden.');
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
                'reason' => 'ungültiges JSON'
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
                'reason' => 'unbekannte Datenstruktur'
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
            throw new Exception('Widersprüchliche Playlist-/Radio-Profilnamen bei verbundenen Playern gefunden.');
        }

        if ($playerProfiles['unavailable']) {
            throw new Exception('Verbundene Player sind während der Profilnamen-Initialisierung noch nicht vollständig verfügbar.');
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
            throw new Exception('Playlist-/Radio-Profilnamen konnten nicht gespeichert werden.');
        }
        $this->SendDebug('Profiles', 'Profilnamen übernommen aus ' . $source, 0);
        return $profileNames;
    }

    private function reportInvalidProfileNames(string $reason): void
    {
        $message = 'Persistentes Attribut ProfileNames enthält ' . $reason
            . '; Initialisierung wurde abgebrochen und die Originaldaten wurden beibehalten.';
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
                'Das persistente Attribut ist beschädigt (' . $stored['reason'] . '). Die Originaldaten wurden beibehalten.'
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
                    throw new Exception(
                        'Player-Instanz ' . $playerInstanceId . ' enthält keine gültige ' . $attribute
                        . ' (' . ($playerMap['reason'] !== '' ? $playerMap['reason'] : 'nicht gespeichert') . ').'
                    );
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
                throw new Exception(
                    'Verbundene Player enthalten widersprüchliche Maps (Instanzen: '
                    . implode(' / ', $details) . ').'
                );
            }

            $mapJson = $maps[0]['json'];
            if (!$this->WriteAttributeString($attribute, $mapJson)) {
                throw new Exception('Die Map konnte nicht als persistentes Attribut gespeichert werden.');
            }
            $this->SendDebug($attribute, 'Persistente Map aus verbundenen Playern übernommen.', 0);
            return true;
        } catch (Throwable $e) {
            $this->reportMapError($attribute, 'Migration nicht durchgeführt: ' . $e->getMessage());
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
            return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => 'ungültiges JSON'];
        }
        if (!is_object($object) || !is_array($map)) {
            return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => 'die Map ist kein JSON-Objekt'];
        }

        foreach ($map as $itemId => $entry) {
            $itemId = (string)$itemId;
            if ((int)$itemId <= 0 || (string)(int)$itemId !== $itemId) {
                return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => 'ungültige Eintrags-ID'];
            }
            if ($playlist) {
                $legacyEntry = is_string($entry) && trim($entry) !== '';
                $currentEntry = is_array($entry)
                    && is_string($entry['uri'] ?? null)
                    && trim($entry['uri']) !== ''
                    && (!array_key_exists('provider_mappings', $entry)
                        || is_array($entry['provider_mappings']));
                if (!$legacyEntry && !$currentEntry) {
                    return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => 'ungültiger Playlist-Eintrag'];
                }
            } elseif (!is_string($entry) || trim($entry) === '') {
                return ['state' => 'invalid', 'map' => [], 'json' => '', 'reason' => 'ungültiger Radio-Eintrag'];
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
                throw new Exception($attribute . ' konnte nicht sicher aus den verbundenen Playern migriert werden.');
            }
            $stored = $this->readMap($this->ReadAttributeString($attribute), $playlist);
        }
        if ($stored['state'] === 'missing') {
            return '{}';
        }
        if ($stored['state'] !== 'valid') {
            throw new Exception(
                $attribute . ' enthält beschädigte persistente Daten (' . $stored['reason'] . ').'
            );
        }
        return $stored['json'];
    }

    private function reportMapError(string $attribute, string $message): void
    {
        $message = $attribute . ': ' . $message;
        $this->SendDebug($attribute, $message, 0);
        $this->LogMessage($message, KL_ERROR);
    }

    private function buildPlayerListValues(): array
    {
        $players = [];
        $connectionId = 0;
        try {
            $connectionId = $this->getConfiguratorConnectionId();
            $result = @$this->sendApiRequest('players/all');

            if (is_array($result) && $this->isList($result)) {
                $players = $result;
            } elseif (is_array($result) && isset($result['items']) && is_array($result['items'])) {
                $players = $result['items'];
            }
        } catch (Throwable $e) {
            $this->SendDebug('Players', 'players/all failed: ' . $e->getMessage(), 0);
        }

        $rows = [];
        foreach ($players as $p) {
            $playerId = (string)($p['player_id'] ?? $p['id'] ?? '');
            if ($playerId === '') continue;

            $name     = (string)($p['name'] ?? $playerId);
            $provider = (string)($p['provider'] ?? '');
            $avail    = (bool)($p['available'] ?? false);

            $instanceId = $this->findExistingPlayerInstance($playerId, $connectionId);

            $row = [
                'name'        => $name,
                'player_id'   => $playerId,
                'provider'    => $provider,
                'available'   => $avail ? 'Ja' : 'Nein',
                'status'      => $instanceId > 0 ? 'Vorhanden' : 'Nicht angelegt',
                'instanceID'  => $instanceId,
                'create'      => [
                    'moduleID'      => self::PLAYER_MODULE_ID,
                    'configuration' => $this->playerConfiguration($playerId),
                    'name'          => 'MA Player - ' . $name
                ]
            ];
            $rows[] = $row;
        }

        return $rows;
    }

    private function getValidTargetCategoryId(): int
    {
        $targetCategoryId = $this->ReadPropertyInteger('TargetCategoryID');
        if ($targetCategoryId < 0 || ($targetCategoryId > 0 && !IPS_CategoryExists($targetCategoryId))) {
            throw new Exception('Der ausgewählte Zielordner ist keine gültige Kategorie.');
        }
        return $targetCategoryId;
    }

    private function playerConfiguration(string $playerId): array
    {
        return [
            'PlayerID'        => $playerId,
            'PlaylistProfile' => $this->playlistProfileName(),
            'PlaylistMap'     => $this->getPersistentMapJson('PlaylistMap', true),
            'RadioProfile'    => $this->radioProfileName(),
            'RadioMap'        => $this->getPersistentMapJson('RadioMap', false)
        ];
    }

    private function updateConnectedPlayerPlaylistMaps(string $mapJson): void
    {
        $connectionId = $this->getConfiguratorConnectionId();
        foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $playerInstanceId) {
            if (!IPS_InstanceExists($playerInstanceId)) {
                continue;
            }
            $instance = IPS_GetInstance($playerInstanceId);
            if (!is_array($instance) || (int)($instance['ConnectionID'] ?? 0) !== $connectionId) {
                continue;
            }
            if (IPS_GetProperty($playerInstanceId, 'PlaylistMap') === $mapJson) {
                continue;
            }
            if (!IPS_SetProperty($playerInstanceId, 'PlaylistMap', $mapJson)) {
                throw new Exception('PlaylistMap konnte für Player-Instanz ' . $playerInstanceId . ' nicht gesetzt werden.');
            }
            if (!IPS_ApplyChanges($playerInstanceId)) {
                throw new Exception('PlaylistMap konnte für Player-Instanz ' . $playerInstanceId . ' nicht übernommen werden.');
            }
        }
    }

    private function updateConnectedPlayerRadioMaps(string $mapJson): void
    {
        $connectionId = $this->getConfiguratorConnectionId();
        foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $playerInstanceId) {
            if (!IPS_InstanceExists($playerInstanceId)) {
                continue;
            }
            $instance = IPS_GetInstance($playerInstanceId);
            if (!is_array($instance) || (int)($instance['ConnectionID'] ?? 0) !== $connectionId) {
                continue;
            }
            if (IPS_GetProperty($playerInstanceId, 'RadioMap') === $mapJson) {
                continue;
            }
            if (!IPS_SetProperty($playerInstanceId, 'RadioMap', $mapJson)) {
                throw new Exception('RadioMap konnte für Player-Instanz ' . $playerInstanceId . ' nicht gesetzt werden.');
            }
            if (!IPS_ApplyChanges($playerInstanceId)) {
                throw new Exception('RadioMap konnte für Player-Instanz ' . $playerInstanceId . ' nicht übernommen werden.');
            }
        }
    }

    private function getConfiguratorConnectionId(): int
    {
        $configurator = IPS_GetInstance($this->InstanceID);
        if (!is_array($configurator)) {
            throw new Exception('Die Configurator-Instanz konnte nicht gelesen werden.');
        }
        $connectionId = (int)($configurator['ConnectionID'] ?? 0);
        if ($connectionId <= 0) {
            throw new Exception('Der Configurator ist nicht mit einer MusicAssistantConnection verbunden.');
        }
        if (!IPS_InstanceExists($connectionId)) {
            throw new Exception('Die MusicAssistantConnection des Configurators ist nicht mehr vorhanden.');
        }
        $connection = IPS_GetInstance($connectionId);
        if (!is_array($connection)
            || ($connection['ModuleInfo']['ModuleID'] ?? null) !== self::CONNECTION_MODULE_ID) {
            throw new Exception('Der Configurator ist nicht mit einer gültigen MusicAssistantConnection verbunden.');
        }
        return $connectionId;
    }

    private function validateConfiguratorConnection(int $connectionId): void
    {
        if ($this->getConfiguratorConnectionId() !== $connectionId) {
            throw new Exception('Die MusicAssistantConnection des Configurators wurde zwischenzeitlich geändert.');
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

    private function rollbackCreatedPlayer(int $playerId): void
    {
        $instance = @IPS_GetInstance($playerId);
        if (is_array($instance) && (int)($instance['ConnectionID'] ?? 0) > 0
            && !IPS_DisconnectInstance($playerId)) {
            throw new Exception('Unvollständige Playerinstanz ' . $playerId . ' konnte nicht getrennt werden.');
        }
        foreach (IPS_GetChildrenIDs($playerId) as $childId) {
            $object = IPS_GetObject($childId);
            if (($object['ObjectType'] ?? -1) !== 2 || !IPS_DeleteVariable($childId)) {
                throw new Exception('Unterobjekte der unvollständigen Playerinstanz ' . $playerId
                    . ' konnten nicht entfernt werden.');
            }
        }
        if (!IPS_DeleteInstance($playerId)) {
            throw new Exception('Unvollständig erstellte Playerinstanz ' . $playerId . ' konnte nicht entfernt werden.');
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

    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
