<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApi.php';

class MusicAssistantConfigurator extends IPSModule
{
    use MusicAssistantApi;

    private const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '127.0.0.1');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');

        $this->SetBuffer('MsgId', '0');

        // item_id => uri
        $this->SetBuffer('PlaylistMap', '{}');
        $this->SetBuffer('RadioMap', '{}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
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
        $values = $this->buildPlayersConfiguratorValues();

        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Server'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port'],
                ['type' => 'PasswordTextBox', 'name' => 'Token', 'caption' => 'Token'],

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
                    'type'    => 'Configurator',
                    'name'    => 'Players',
                    'caption' => 'Player',
                    'columns' => [
                        ['caption' => 'Name',      'name' => 'name',      'width' => '250px'],
                        ['caption' => 'Player ID', 'name' => 'player_id', 'width' => '260px'],
                        ['caption' => 'Provider',  'name' => 'provider',  'width' => '160px'],
                        ['caption' => 'Available', 'name' => 'available', 'width' => '90px']
                    ],
                    'values'  => $values
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Player neu laden',
                    'onClick' => 'IPS_RequestAction($id, "ReloadPlayers", true);'
                ]
            ]
        ];

        return json_encode($form);
    }

    public function SyncPlaylistsProfile(): void
    {
        $profile = $this->playlistProfileName();

        $resp = $this->maCall('music/playlists/library_items');
        $result = $resp['result'];

        $items = [];
        if (is_array($result) && $this->isList($result)) {
            $items = $result;
        } elseif (is_array($result) && isset($result['items']) && is_array($result['items'])) {
            $items = $result['items'];
        }

        $this->ensureIntegerProfile($profile);

        $p = IPS_GetVariableProfile($profile);
        foreach (($p['Associations'] ?? []) as $assoc) {
            IPS_SetVariableProfileAssociation($profile, (float)$assoc['Value'], '', '', -1);
        }

        $map = [];
        $count = 0;
        foreach ($items as $pl) {
            if ($count >= 128) break;

            $idStr = (string)($pl['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($pl['name'] ?? ('Playlist ' . $idStr));
            $uri   = (string)($pl['uri'] ?? '');

            if ($id <= 0 || $uri === '') continue;

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

        $resp = $this->maCall('music/radios/library_items');
        $result = $resp['result'];

        $items = [];
        if (is_array($result) && $this->isList($result)) {
            $items = $result;
        } elseif (is_array($result) && isset($result['items']) && is_array($result['items'])) {
            $items = $result['items'];
        }

        $this->ensureIntegerProfile($profile);

        $p = IPS_GetVariableProfile($profile);
        foreach (($p['Associations'] ?? []) as $assoc) {
            IPS_SetVariableProfileAssociation($profile, (float)$assoc['Value'], '', '', -1);
        }

        $map = [];
        $count = 0;
        foreach ($items as $r) {
            if ($count >= 128) break;

            $idStr = (string)($r['item_id'] ?? '');
            $id    = (int)$idStr;
            $name  = (string)($r['name'] ?? ('Radio ' . $idStr));
            $uri   = (string)($r['uri'] ?? '');

            if ($id <= 0 || $uri === '') continue;

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

    private function buildPlayersConfiguratorValues(): array
    {
        $players = [];
        try {
            $resp = $this->maCall('players/all');
            $result = $resp['result'];

            if (is_array($result) && $this->isList($result)) {
                $players = $result;
            } elseif (is_array($result) && isset($result['items']) && is_array($result['items'])) {
                $players = $result['items'];
            }
        } catch (Throwable $e) {
            $this->SendDebug('Players', 'players/all failed: ' . $e->getMessage(), 0);
        }

        $playlistProfile = $this->playlistProfileName();
        $radioProfile    = $this->radioProfileName();
        $playlistMap     = $this->getPlaylistMap();
        $radioMap        = $this->getRadioMap();

        $rows = [];
        foreach ($players as $p) {
            $playerId = (string)($p['player_id'] ?? $p['id'] ?? '');
            if ($playerId === '') continue;

            $name     = (string)($p['name'] ?? $playerId);
            $provider = (string)($p['provider'] ?? '');
            $avail    = (bool)($p['available'] ?? false);

            $instanceId = $this->findExistingPlayerInstance($playerId);

            $rows[] = [
                'name'       => $name,
                'player_id'  => $playerId,
                'provider'   => $provider,
                'available'  => $avail ? 'Yes' : 'No',
                'instanceID' => $instanceId,
                'create'     => [
                    'moduleID' => self::PLAYER_MODULE_ID,
                    'name'     => 'MA Player - ' . $name,
                    'configuration' => [
                        'Host'            => $this->ReadPropertyString('Host'),
                        'Port'            => $this->ReadPropertyInteger('Port'),
                        'Token'           => $this->ReadPropertyString('Token'),
                        'PlayerID'        => $playerId,

                        'PlaylistProfile' => $playlistProfile,
                        'PlaylistMap'     => json_encode($playlistMap, JSON_UNESCAPED_SLASHES),

                        'RadioProfile'    => $radioProfile,
                        'RadioMap'        => json_encode($radioMap, JSON_UNESCAPED_SLASHES)
                    ]
                ]
            ];
        }

        return $rows;
    }

    private function findExistingPlayerInstance(string $playerId): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID);
        foreach ($ids as $id) {
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
