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

        // item_id => uri
        $this->SetBuffer('PlaylistMap', '{}');
        $this->SetBuffer('RadioMap', '{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        try {
            $this->initializeProfileNames();
        } catch (Throwable $e) {
            $this->SendDebug('Profiles', $e->getMessage(), 0);
        }
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
                    'onClick' => 'MA_SyncPlaylistsProfile($id);'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Radios laden (Profil erstellen/aktualisieren)',
                    'onClick' => 'MA_SyncRadiosProfile($id);'
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

        $result = $this->sendApiRequest('music/playlists/library_items');

        $items = [];
        if (is_array($result) && $this->isList($result)) {
            $items = $result;
        } elseif (is_array($result) && isset($result['items']) && is_array($result['items'])) {
            $items = $result['items'];
        }

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

        $map = [];
        $count = 0;
        foreach ($items as $pl) {
            if ($count >= 127) break;

            $idStr = (string)($pl['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($pl['name'] ?? ('Playlist ' . $idStr));
            $uri   = (string)($pl['uri'] ?? '');

            if ($id <= 0 || $uri === '') continue;

            // einzelne Playlist-Einträge ohne eigenes Icon -> Profil-Icon gilt als Default
            IPS_SetVariableProfileAssociation($profile, (float)$id, $name, '', -1);
            $map[(string)$id] = $uri;
            $count++;
        }

        $this->SetBuffer('PlaylistMap', json_encode($map, JSON_UNESCAPED_SLASHES));
        $this->SendDebug('Playlists', 'Profile=' . $profile . ' entries=' . $count, 0);
        $this->ReloadForm();
    }

    public function SyncRadiosProfile(): void
    {
        $profile = $this->radioProfileName();

        $result = $this->sendApiRequest('music/radios/library_items');

        $items = [];
        if (is_array($result) && $this->isList($result)) {
            $items = $result;
        } elseif (is_array($result) && isset($result['items']) && is_array($result['items'])) {
            $items = $result['items'];
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

        $map = [];
        $count = 0;
        foreach ($items as $r) {
            if ($count >= 127) break;

            $idStr = (string)($r['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($r['name'] ?? ('Radio ' . $idStr));
            $uri   = (string)($r['uri'] ?? '');

            if ($id <= 0 || $uri === '') continue;

            // einzelne Radios ohne eigenes Icon -> Profil-Icon gilt als Default
            IPS_SetVariableProfileAssociation($profile, (float)$id, $name, '', -1);
            $map[(string)$id] = $uri;
            $count++;
        }

        $this->SetBuffer('RadioMap', json_encode($map, JSON_UNESCAPED_SLASHES));
        $this->SendDebug('Radios', 'Profile=' . $profile . ' entries=' . $count, 0);
        $this->ReloadForm();
    }

    private function ensureIntegerProfile(string $profile): void
    {
        if (IPS_VariableProfileExists($profile)) {
            $p = IPS_GetVariableProfile($profile);
            if (($p['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                IPS_DeleteVariableProfile($profile);
            }
        }
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
        }
    }

    private function playlistProfileName(): string
    {
        $profileNames = $this->readProfileNames() ?? $this->initializeProfileNames();
        if ($profileNames === null) {
            throw new Exception('Playlist-/Radio-Profilnamen konnten noch nicht initialisiert werden.');
        }
        return $profileNames['playlist'];
    }

    private function radioProfileName(): string
    {
        $profileNames = $this->readProfileNames() ?? $this->initializeProfileNames();
        if ($profileNames === null) {
            throw new Exception('Playlist-/Radio-Profilnamen konnten noch nicht initialisiert werden.');
        }
        return $profileNames['radio'];
    }

    private function readProfileNames(): ?array
    {
        $value = trim($this->ReadAttributeString('ProfileNames'));
        if ($value === '') {
            return null;
        }
        try {
            $profileNames = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($profileNames)
            || !is_string($profileNames['playlist'] ?? null)
            || trim($profileNames['playlist']) === ''
            || !is_string($profileNames['radio'] ?? null)
            || trim($profileNames['radio']) === '') {
            return null;
        }
        return [
            'playlist' => trim($profileNames['playlist']),
            'radio'    => trim($profileNames['radio'])
        ];
    }

    private function initializeProfileNames(): ?array
    {
        $profileNames = $this->readProfileNames();
        if ($profileNames !== null) {
            return $profileNames;
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

    private function storeProfileNames(array $profileNames, string $source): array
    {
        $existing = $this->readProfileNames();
        if ($existing !== null) {
            return $existing;
        }
        $value = json_encode($profileNames, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$this->WriteAttributeString('ProfileNames', $value)) {
            throw new Exception('Playlist-/Radio-Profilnamen konnten nicht gespeichert werden.');
        }
        $this->SendDebug('Profiles', 'Profilnamen übernommen aus ' . $source, 0);
        return $profileNames;
    }

    private function getPlaylistMap(): array
    {
        $arr = json_decode((string)$this->GetBuffer('PlaylistMap'), true);
        return is_array($arr) ? $arr : [];
    }

    private function getRadioMap(): array
    {
        $arr = json_decode((string)$this->GetBuffer('RadioMap'), true);
        return is_array($arr) ? $arr : [];
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
            'PlaylistMap'     => json_encode($this->getPlaylistMap(), JSON_UNESCAPED_SLASHES),
            'RadioProfile'    => $this->radioProfileName(),
            'RadioMap'        => json_encode($this->getRadioMap(), JSON_UNESCAPED_SLASHES)
        ];
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
