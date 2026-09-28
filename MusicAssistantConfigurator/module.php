<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApi.php';

class MusicAssistantConfigurator extends IPSModule
{
    use MusicAssistantApi;

    private const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';
    private const CONNECTION_MODULE_ID = '{880534D6-998A-704B-DFD1-ABCD3D23B811}';
    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';
    private const PARENT_RETRY_PENDING = 0;
    private const PARENT_RETRY_DONE = 1;
    private const PARENT_RETRY_MAX_ATTEMPTS = 30;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '127.0.0.1');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyInteger('TargetCategoryID', 0);

        $this->SetBuffer('MsgId', '0');
        $this->SetBuffer('ParentRetryAttempts', '0');
        $this->SetBuffer('ParentUnavailableLogged', '0');

        // item_id => uri
        $this->SetBuffer('PlaylistMap', '{}');
        $this->SetBuffer('RadioMap', '{}');
        $this->RegisterTimer('ParentConnectionRetry', 0, 'MA_RetryConfiguratorParent($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('ParentConnectionRetry', 0);
        $this->SetBuffer('ParentRetryAttempts', '0');
        $this->SetBuffer('ParentUnavailableLogged', '0');
        if ($this->ensureConnectionParent() === self::PARENT_RETRY_PENDING) {
            $this->SetTimerInterval('ParentConnectionRetry', 2000);
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

    public function RetryConfiguratorParent(): void
    {
        $attempts = (int)$this->GetBuffer('ParentRetryAttempts') + 1;
        $this->SetBuffer('ParentRetryAttempts', (string)$attempts);
        if ($this->ensureConnectionParent() !== self::PARENT_RETRY_PENDING) {
            $this->SetTimerInterval('ParentConnectionRetry', 0);
            return;
        }
        if ($attempts >= self::PARENT_RETRY_MAX_ATTEMPTS) {
            $this->SetTimerInterval('ParentConnectionRetry', 0);
            $this->SendDebug('Parent', 'Automatische Parent-Zuordnung nach 30 Versuchen beendet', 0);
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'ReloadPlayers':
                $this->ReloadForm();
                break;
            default:
                throw new Exception('Unknown action: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $values = $this->buildPlayerListValues();

        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Server'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port'],
                ['type' => 'PasswordTextBox', 'name' => 'Token', 'caption' => 'Token'],
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
                    'type'                        => 'List',
                    'name'                        => 'Players',
                    'caption'                     => 'Player',
                    'add'                         => false,
                    'delete'                      => false,
                    'changeOrder'                 => false,
                    'loadValuesFromConfiguration' => false,
                    'columns'                     => [
                        ['caption' => 'Name',        'name' => 'name',        'width' => '220px'],
                        ['caption' => 'Player ID',   'name' => 'player_id',   'width' => '300px'],
                        ['caption' => 'Provider',    'name' => 'provider',    'width' => '150px'],
                        ['caption' => 'Verfügbar',   'name' => 'available',   'width' => '90px'],
                        ['caption' => 'Status',      'name' => 'status',      'width' => '130px'],
                        ['caption' => 'Instanz-ID',  'name' => 'instance_id', 'width' => '90px']
                    ],
                    'values'                      => $values
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Ausgewählten Player erstellen',
                    'onClick' => 'echo MA_CreatePlayer($id, (string)($Players["player_id"] ?? ""), (string)($Players["name"] ?? ""));'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Alle fehlenden Player erstellen',
                    'onClick' => 'echo MA_CreateMissingPlayers($id);'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Player neu laden',
                    'onClick' => 'IPS_RequestAction($id, "ReloadPlayers", true);'
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

    public function CreateMissingPlayers(): string
    {
        $created = 0;
        $existing = 0;
        $failures = [];
        foreach ($this->buildPlayerListValues() as $player) {
            if ((int)($player['instanceID'] ?? 0) > 0) {
                $existing++;
                continue;
            }
            $playerId = (string)($player['player_id'] ?? '');
            $name = (string)($player['name'] ?? $playerId);
            try {
                $result = $this->createPlayerInternal($playerId, $name, false);
                if (strpos($result, 'existiert bereits') !== false) {
                    $existing++;
                } else {
                    $created++;
                }
            } catch (Throwable $e) {
                $failures[] = $name . ': ' . $e->getMessage();
            }
        }

        try {
            $this->ReloadForm();
        } catch (Throwable $e) {
            $this->SendDebug('CreatePlayer', 'Formular konnte nach der Erstellung nicht aktualisiert werden', 0);
        }

        $summary = 'Erstellt: ' . $created . '; bereits vorhanden: ' . $existing
            . '; fehlgeschlagen: ' . count($failures) . '.';
        if (count($failures) > 0) {
            $summary .= "\n" . implode("\n", $failures);
        }
        return $summary;
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
        $key = strtolower(trim($this->ReadPropertyString('Host'))) . ':' . (string)$this->ReadPropertyInteger('Port');
        return 'MA.Playlists.' . substr(md5($key), 0, 8);
    }

    private function radioProfileName(): string
    {
        $key = strtolower(trim($this->ReadPropertyString('Host'))) . ':' . (string)$this->ReadPropertyInteger('Port');
        return 'MA.Radios.' . substr(md5($key), 0, 8);
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
            $result = $this->sendApiRequest('players/all');

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
                'instance_id' => $instanceId > 0 ? (string)$instanceId : '-',
                'instanceID'  => $instanceId,
                'editable'    => false,
                'deletable'   => false
            ];
            if ($instanceId > 0) {
                $row['rowColor'] = '#C8F7C5';
            }
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
            'Host'            => $this->ReadPropertyString('Host'),
            'Port'            => $this->ReadPropertyInteger('Port'),
            'Token'           => $this->ReadPropertyString('Token'),
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

    private function ensureConnectionParent(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($parentId > 0) {
            return self::PARENT_RETRY_DONE;
        }

        $host = $this->normalizeServerHost($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        if ($host === '' || $port <= 0 || $port > 65535) {
            $this->SendDebug('Parent', 'Host/Port nicht für automatische Parent-Zuordnung geeignet', 0);
            return self::PARENT_RETRY_DONE;
        }

        $connectionIds = IPS_GetInstanceListByModuleID(self::CONNECTION_MODULE_ID);
        if (count($connectionIds) === 0) {
            $this->SendDebug('Parent', 'Keine MusicAssistantConnection vorhanden', 0);
            return self::PARENT_RETRY_DONE;
        }

        $matches = [];
        $unavailableConnections = [];
        foreach ($connectionIds as $connectionId) {
            $matchesServer = $this->connectionMatchesServer((int)$connectionId);
            if ($matchesServer === null) {
                $unavailableConnections[] = (int)$connectionId;
            } elseif ($matchesServer) {
                $matches[] = (int)$connectionId;
            }
        }

        if (count($unavailableConnections) > 0) {
            if ($this->GetBuffer('ParentUnavailableLogged') !== '1') {
                $this->SetBuffer('ParentUnavailableLogged', '1');
                $this->SendDebug(
                    'Parent',
                    'Connection ' . $unavailableConnections[0] . ' während Initialisierung noch nicht verfügbar',
                    0
                );
            }
            return self::PARENT_RETRY_PENDING;
        }
        if (count($matches) === 0) {
            return self::PARENT_RETRY_DONE;
        }
        if (count($matches) > 1) {
            $this->SendDebug('Parent', 'Mehrere passende MusicAssistantConnections gefunden; keine Verbindung geändert', 0);
            return self::PARENT_RETRY_DONE;
        }

        if (IPS_ConnectInstance($this->InstanceID, $matches[0])) {
            $this->SendDebug('Parent', 'Passende MusicAssistantConnection verbunden: ' . $matches[0], 0);
            return self::PARENT_RETRY_DONE;
        }

        $this->SendDebug('Parent', 'Verbindung mit MusicAssistantConnection fehlgeschlagen: ' . $matches[0], 0);
        return self::PARENT_RETRY_PENDING;
    }

    private function connectionMatchesServer(int $connectionId): ?bool
    {
        if (!IPS_InstanceExists($connectionId)) {
            return null;
        }
        $connection = IPS_GetInstance($connectionId);
        if (!is_array($connection)) {
            return null;
        }
        if (($connection['ModuleInfo']['ModuleID'] ?? null) !== self::CONNECTION_MODULE_ID) {
            return false;
        }
        $configurationJson = @IPS_GetConfiguration($connectionId);
        if (!is_string($configurationJson) || $configurationJson === '') {
            return null;
        }
        $configuration = json_decode($configurationJson, true);
        if (!is_array($configuration)) {
            return null;
        }
        return $this->normalizeServerHost((string)($configuration['Host'] ?? ''))
                === $this->normalizeServerHost($this->ReadPropertyString('Host'))
            && (int)($configuration['Port'] ?? 0) === $this->ReadPropertyInteger('Port');
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

    private function normalizeServerHost(string $host): string
    {
        $host = trim($host);
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        $host = strtolower(rtrim($host, '.'));
        $packedAddress = @inet_pton($host);
        return $packedAddress === false ? $host : bin2hex($packedAddress);
    }

    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
