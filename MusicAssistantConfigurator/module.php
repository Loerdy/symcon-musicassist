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

        // Name des Playlist-Profils wird automatisch aus Host:Port abgeleitet
        $this->SetBuffer('MsgId', '0');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
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
                    'type' => 'Button',
                    'caption' => 'Playlists laden (Variablenprofil erstellen/aktualisieren)',
                    'onClick' => 'MA_SyncPlaylistsProfile($id);'
                ],
                [
                    'type' => 'Configurator',
                    'name' => 'Players',
                    'caption' => 'Player',
                    'columns' => [
                        ['caption' => 'Name',      'name' => 'name',      'width' => '250px'],
                        ['caption' => 'Player ID',  'name' => 'player_id', 'width' => '260px'],
                        ['caption' => 'Provider',   'name' => 'provider',  'width' => '160px'],
                        ['caption' => 'Available',  'name' => 'available', 'width' => '90px']
                    ],
                    'values' => $values
                ]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Player neu laden', 'onClick' => 'IPS_RequestAction($id, "ReloadPlayers", true);']
            ]
        ];

        return json_encode($form);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'ReloadPlayers':
                // Form wird neu gerendert – GetConfigurationForm zieht neue Daten
                $this->ReloadForm();
                break;
            default:
                throw new Exception('Unknown action: ' . $Ident);
        }
    }

    public function SyncPlaylistsProfile(): void
    {
        $profile = $this->playlistProfileName();

        // Versuche: music/playlists/library_items (aus Logs bekannt)
        $items = [];
        try {
            $resp = $this->maCall('music/playlists/library_items', [
                'limit' => 500,
                'offset' => 0,
                'order_by' => 'name'
            ]);
            $result = $resp['result'];
            $items = is_array($result['items'] ?? null) ? $result['items'] : (is_array($result) ? ($result['items'] ?? []) : []);
        } catch (Throwable $e) {
            $this->SendDebug('Playlists', 'library_items failed: ' . $e->getMessage(), 0);
        }

        // Fallback: get_library_items (in der Praxis als “get library items call” bekannt)
        if (!is_array($items) || count($items) === 0) {
            try {
                $resp = $this->maCall('music/get_library_items', [
                    'media_type' => 'playlist',
                    'limit' => 500,
                    'offset' => 0,
                    'order_by' => 'name'
                ]);
                $result = $resp['result'];
                $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            } catch (Throwable $e) {
                $this->SendDebug('Playlists', 'get_library_items failed: ' . $e->getMessage(), 0);
            }
        }

        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_STRING);
        }

        // Existing Associations löschen
        $p = IPS_GetVariableProfile($profile);
        foreach (($p['Associations'] ?? []) as $assoc) {
            IPS_SetVariableProfileAssociation($profile, (string)$assoc['Value'], '', '', -1);
        }

        $count = 0;
        foreach ($items as $pl) {
            if ($count >= 128) {
                break;
            }
            $uri = (string)($pl['uri'] ?? '');
            $name = (string)($pl['name'] ?? $uri);
            if ($uri === '') {
                continue;
            }
            IPS_SetVariableProfileAssociation($profile, $uri, $name, '', -1);
            $count++;
        }

        $this->SendDebug('Playlists', 'Profile=' . $profile . ' entries=' . $count, 0);
        $this->ReloadForm();
    }

    private function playlistProfileName(): string
    {
        $key = strtolower(trim($this->ReadPropertyString('Host'))) . ':' . (string)$this->ReadPropertyInteger('Port');
        return 'MA.Playlists.' . substr(md5($key), 0, 8);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPlayersConfiguratorValues(): array
    {
        $players = [];
        try {
            $resp = $this->maCall('players/all');
            $list = $this->maAsList($resp['result']);
            $players = $list;
        } catch (Throwable $e) {
            $this->SendDebug('Players', 'players/all failed: ' . $e->getMessage(), 0);
        }

        $rows = [];
        foreach ($players as $p) {
            $playerId = (string)($p['player_id'] ?? $p['id'] ?? '');
            $name     = (string)($p['name'] ?? $playerId);
            $provider = (string)($p['provider'] ?? '');
            $avail    = (bool)($p['available'] ?? false);

            if ($playerId === '') {
                continue;
            }

            $instanceId = $this->findExistingPlayerInstance($playerId);

            $rows[] = [
                'name'      => $name,
                'player_id' => $playerId,
                'provider'  => $provider,
                'available' => $avail ? 'Yes' : 'No',
                'instanceID' => $instanceId,
                'create' => [
                    'moduleID' => self::PLAYER_MODULE_ID,
                    'name'     => 'MA Player - ' . $name,
                    'configuration' => [
                        'Host' => $this->ReadPropertyString('Host'),
                        'Port' => $this->ReadPropertyInteger('Port'),
                        'Token' => $this->ReadPropertyString('Token'),
                        'PlayerID' => $playerId,
                        'PlaylistProfile' => $this->playlistProfileName()
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
}
