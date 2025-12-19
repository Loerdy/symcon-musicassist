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

        // WICHTIG: Kein Default-Profil erzwingen, das evtl. nicht existiert
        $this->RegisterPropertyString('PlaylistProfile', '');

        $this->SetBuffer('MsgId', '0');

        // Variablen
        // WICHTIG: Playlist-Variable ohne Profil anlegen (Profil kommt erst in ApplyChanges)
        $this->RegisterVariableString('Playlist', 'Playlist', '', 10);
        $this->EnableAction('Playlist');

        $this->RegisterVariableInteger('Volume', 'Volume', '~Intensity.100', 20);
        $this->EnableAction('Volume');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $vid = @$this->GetIDForIdent('Playlist');
        if ($vid <= 0) {
            return;
        }

        $profile = trim($this->ReadPropertyString('PlaylistProfile'));

        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            IPS_SetVariableCustomProfile($vid, $profile);
        } else {
            // Profil (noch) nicht vorhanden -> ohne Profil arbeiten
            IPS_SetVariableCustomProfile($vid, '');
            if ($profile !== '') {
                $this->SendDebug('PlaylistProfile', 'Profile not found: ' . $profile, 0);
            }
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Playlist':
                $this->PlayPlaylist((string)$Value);
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
