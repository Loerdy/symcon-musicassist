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
        $this->RegisterPropertyString('PlaylistProfile', 'MA.Playlists.default');

        $this->SetBuffer('MsgId', '0');

        // Variablen
        $this->RegisterVariableString('Playlist', 'Playlist', $this->ReadPropertyString('PlaylistProfile'), 10);
        $this->EnableAction('Playlist');

        $this->RegisterVariableInteger('Volume', 'Volume', '~Intensity.100', 20);
        $this->EnableAction('Volume');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Profil (falls geändert) an Variable binden
        $vid = @$this->GetIDForIdent('Playlist');
        if ($vid > 0) {
            IPS_SetVariableCustomProfile($vid, $this->ReadPropertyString('PlaylistProfile'));
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Playlist':
                $this->PlayPlaylist((string)$Value);
                // Variable auf Auswahl setzen
                $this->SetValue('Playlist', (string)$Value);
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

    // --------- öffentliche Funktionen (im Symcon Script nutzbar) ---------

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

    public function PlayPlaylist(string $playlistUri): void
    {
        if ($playlistUri === '') {
            throw new Exception('Playlist URI is empty');
        }

        // Queue-ID ist i. d. R. gleich Player-ID (außer Gruppen), in Logs oft so beschrieben. :contentReference[oaicite:9]{index=9}
        $queueId = $this->playerId();

        $this->maCall('player_queues/play_media', [
            'queue_id' => $queueId,
            'media'    => $playlistUri,
            'enqueue'  => 'replace'
        ]);
    }

    // --------- intern ---------

    private function playerId(): string
    {
        $id = trim($this->ReadPropertyString('PlayerID'));
        if ($id === '') {
            throw new Exception('PlayerID not configured');
        }
        return $id;
    }
}
