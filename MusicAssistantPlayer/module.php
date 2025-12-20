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

        // Playlists
        $this->RegisterPropertyString('PlaylistProfile', '');
        $this->RegisterPropertyString('PlaylistMap', '{}');

        // Radios
        $this->RegisterPropertyString('RadioProfile', '');
        $this->RegisterPropertyString('RadioMap', '{}');

        $this->SetBuffer('MsgId', '0');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Auswahl-Variablen
        $this->ensureIntegerSelectorVariable('Playlist', 'Playlist', $this->ReadPropertyString('PlaylistProfile'), 10);
        $this->ensureIntegerSelectorVariable('Radio', 'Radio', $this->ReadPropertyString('RadioProfile'), 20);

        // Transport-Variable mit Legacy Profil ~PlaybackPreviousNext
        // Werte-Mapping: 0=Previous, 1=Stop, 2=Play, 3=Pause, 4=Next
        $this->MaintainVariable('Transport', 'Wiedergabe', VARIABLETYPE_INTEGER, '~PlaybackPreviousNext', 30, true);
        $this->EnableAction('Transport');
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Playlist':
                $itemId = (int)$Value;
                $uri = $this->resolveUriFromMap($itemId, 'PlaylistMap', 'music/playlists/library_items');
                $this->PlayMediaUri($uri);
                $this->SetValue('Playlist', $itemId);
                break;

            case 'Radio':
                $itemId = (int)$Value;
                $uri = $this->resolveUriFromMap($itemId, 'RadioMap', 'music/radios/library_items');
                $this->PlayMediaUri($uri);
                $this->SetValue('Radio', $itemId);
                break;

            case 'Transport':
                $this->ExecuteTransport((int)$Value);
                $this->SetValue('Transport', (int)$Value);
                break;

            default:
                throw new Exception('Unknown action: ' . $Ident);
        }
    }

    // --------- MA Commands (laut deiner API-Doku) ---------

    public function Next(): void
    {
        $this->maCall('players/cmd/next', ['player_id' => $this->playerId()]);
    }

    public function Previous(): void
    {
        $this->maCall('players/cmd/previous', ['player_id' => $this->playerId()]);
    }

    public function Pause(): void
    {
        $this->maCall('players/cmd/pause', ['player_id' => $this->playerId()]);
    }

    public function Play(): void
    {
        $this->maCall('players/cmd/play', ['player_id' => $this->playerId()]);
    }

    public function PlayPause(): void
    {
        $this->maCall('players/cmd/play_pause', ['player_id' => $this->playerId()]);
    }

    public function Stop(): void
    {
        $this->maCall('players/cmd/stop', ['player_id' => $this->playerId()]);
    }

    // --------- intern ---------

    private function ExecuteTransport(int $value): void
    {
        switch ($value) {
            case 0: $this->Previous(); break;
            case 1: $this->Stop();     break;
            case 2: $this->Play();     break;
            case 3: $this->Pause();    break;
            case 4: $this->Next();     break;
            default: break;
        }
    }

    private function ensureIntegerSelectorVariable(string $ident, string $name, string $profile, int $pos): void
    {
        // Wenn Variable existiert aber falscher Typ: löschen
        $existingId = @$this->GetIDForIdent($ident);
        if ($existingId > 0) {
            $v = IPS_GetVariable($existingId);
            if (($v['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                IPS_DeleteVariable($existingId);
            }
        }

        $this->MaintainVariable($ident, $name, VARIABLETYPE_INTEGER, '', $pos, true);
        $this->EnableAction($ident);

        $vid = @$this->GetIDForIdent($ident);
        if ($vid <= 0) return;

        $profile = trim($profile);
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            $pp = IPS_GetVariableProfile($profile);
            if (($pp['ProfileType'] ?? -1) === VARIABLETYPE_INTEGER) {
                IPS_SetVariableCustomProfile($vid, $profile);
                return;
            }
        }
        IPS_SetVariableCustomProfile($vid, '');
    }

    private function resolveUriFromMap(int $itemId, string $mapProperty, string $refreshCommand): string
    {
        if ($itemId <= 0) {
            throw new Exception('Invalid item_id');
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

        // Fallback: erneut aus MA ziehen
        $resp = $this->maCall($refreshCommand);
        $result = $resp['result'];

        if (is_array($result) && array_keys($result) === range(0, count($result) - 1)) {
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

        throw new Exception('URI not found for item_id=' . $itemId);
    }

    private function PlayMediaUri(string $uri): void
    {
        if ($uri === '') {
            throw new Exception('Media URI is empty');
        }

        $queueId = $this->playerId();
        $this->maCall('player_queues/play_media', [
            'queue_id' => $queueId,
            'media'    => $uri,
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
