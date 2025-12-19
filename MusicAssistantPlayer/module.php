<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApi.php';

class MusicAssistantPlayer extends IPSModule
{
    use MusicAssistantApi;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '127.0.0.1');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('PlayerID', '');

        $this->RegisterPropertyString('PlaylistProfile', '');
        $this->RegisterPropertyString('PlaylistMap', '{}');

        $this->SetBuffer('MsgId', '0');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Playlist Variable als INTEGER (item_id)
        $profile = trim($this->ReadPropertyString('PlaylistProfile'));

        // Falls Variable schon existiert aber falscher Typ, löschen
        $existingId = @$this->GetIDForIdent('Playlist');
        if ($existingId > 0) {
            $v = IPS_GetVariable($existingId);
            if (($v['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                IPS_DeleteVariable($existingId);
            }
        }

        $this->MaintainVariable('Playlist', 'Playlist', VARIABLETYPE_INTEGER, '', 10, true);
        $this->EnableAction('Playlist');

        $this->MaintainVariable('Volume', 'Volume', VARIABLETYPE_INTEGER, '~Intensity.100', 20, true);
        $this->EnableAction('Volume');

        $vid = @$this->GetIDForIdent('Playlist');
        if ($vid > 0) {
            if ($profile !== '' && IPS_VariableProfileExists($profile)) {
                $pp = IPS_GetVariableProfile($profile);
                if (($pp['ProfileType'] ?? -1) === VARIABLETYPE_INTEGER) {
                    IPS_SetVariableCustomProfile($vid, $profile);
                } else {
                    IPS_SetVariableCustomProfile($vid, '');
                    $this->SendDebug('PlaylistProfile', 'Wrong profile type: ' . $profile, 0);
                }
            } else {
                IPS_SetVariableCustomProfile($vid, '');
            }
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Playlist':
                $itemId = (int)$Value;
                $uri = $this->resolvePlaylistUri($itemId);
                $this->PlayPlaylistUri($uri);
                $this->SetValue('Playlist', $itemId);
                break;

            case 'Volume':
                $vol = max(0, min(100, (int)$Value));
                $this->SetVolume($vol);
                $this->SetValue('Volume', $vol);
                break;

            default:
                throw new Exception('Unknown action: ' . $Ident);
        }
    }

    // --------- öffentliche Funktionen ---------

    public function PlayPause(): void
    {
        $this->maCall('players/cmd/play_pause', ['player_id' => $this->playerId()]);
    }

    public function Next(): void
    {
        $this->maCall('players/cmd/next', ['player_id' => $this->playerId()]);
    }

    public function Previous(): void
    {
        $this->maCall('players/cmd/previous', ['player_id' => $this->playerId()]);
    }

    public function SetVolume(int $volume): void
    {
        $this->maCall('players/cmd/volume_set', [
            'player_id' => $this->playerId(),
            'volume'    => $volume
        ]);
    }

    // --------- intern ---------

    private function resolvePlaylistUri(int $itemId): string
    {
        if ($itemId <= 0) {
            throw new Exception('Invalid playlist item_id');
        }

        $mapRaw = trim($this->ReadPropertyString('PlaylistMap'));
        $map = json_decode($mapRaw, true);
        if (!is_array($map)) {
            $map = [];
        }

        $key = (string)$itemId;
        if (isset($map[$key]) && is_string($map[$key]) && $map[$key] !== '') {
            return $map[$key];
        }

        // Fallback: Map aus MA ziehen
        $resp = $this->maCall('music/playlists/library_items');
        $result = $resp['result'];

        if (is_array($result) && array_keys($result) === range(0, count($result) - 1)) {
            foreach ($result as $pl) {
                $idStr = (string)($pl['item_id'] ?? '');
                $id    = (int)$idStr;
                $uri   = (string)($pl['uri'] ?? '');
                if ($id > 0 && $uri !== '') {
                    $map[(string)$id] = $uri;
                }
            }
            if (isset($map[$key])) {
                return $map[$key];
            }
        }

        throw new Exception('Playlist URI not found for item_id=' . $itemId);
    }

    private function PlayPlaylistUri(string $playlistUri): void
    {
        if ($playlistUri === '') {
            throw new Exception('Playlist URI is empty');
        }

        $queueId = $this->playerId();

        $this->maCall('player_queues/play_media', [
            'queue_id' => $queueId,
            'media'    => $playlistUri,
            'enqueue'  => 'replace'
        ]);
    }

    private function playerId(): string
    {
        $id = trim($this->ReadPropertyString('PlayerID'));
        if ($id === '') {
            throw new Exception('PlayerID not configured');
        }
        return $id;
    }
}
