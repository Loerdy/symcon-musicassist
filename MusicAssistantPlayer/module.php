<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApi.php';

class MusicAssistantPlayer extends IPSModule
{
    use MusicAssistantApi;

    private const REPEAT_PROFILE = 'MA.RepeatMode';

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
        $this->SetBuffer('QueueId', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Queue-Cache leeren, damit SyncGroup/Player-Wechsel sauber ist
        $this->SetBuffer('QueueId', '');

        // Auswahl-Variablen
        $this->ensureIntegerSelectorVariable('Playlist', 'Playlist', $this->ReadPropertyString('PlaylistProfile'), 10);
        $this->ensureIntegerSelectorVariable('Radio', 'Radio', $this->ReadPropertyString('RadioProfile'), 20);

        // Transport-Variable mit Legacy Profil ~PlaybackPreviousNext
        // Werte-Mapping: 0=Previous, 1=Stop, 2=Play, 3=Pause, 4=Next
        $this->MaintainVariable('Transport', 'Wiedergabe', VARIABLETYPE_INTEGER, '~PlaybackPreviousNext', 30, true);
        $this->EnableAction('Transport');

        // Shuffle an/aus (Profil ~Shuffle)
        $this->MaintainVariable('Shuffle', 'Shuffle', VARIABLETYPE_BOOLEAN, '~Shuffle', 40, true);
        $this->EnableAction('Shuffle');

        // Repeat (off/one/all)
        $this->ensureRepeatProfile();
        $this->MaintainVariable('Repeat', 'Repeat', VARIABLETYPE_STRING, self::REPEAT_PROFILE, 50, true);
        $this->EnableAction('Repeat');

        // Mute (Profil ~Mute)
        $this->MaintainVariable('Mute', 'Mute', VARIABLETYPE_BOOLEAN, '~Mute', 60, true);
        $this->EnableAction('Mute');

        // Initialwert (falls leer) auf off
        if (@$this->GetIDForIdent('Repeat') > 0) {
            $cur = (string)@$this->GetValue('Repeat');
            if ($cur === '') {
                $this->SetValue('Repeat', 'off');
            }
        }
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

            case 'Shuffle':
                $enabled = (bool)$Value;
                $this->SetShuffle($enabled);
                $this->SetValue('Shuffle', $enabled);
                break;

            case 'Repeat':
                $mode = strtolower(trim((string)$Value));
                if (!in_array($mode, ['off', 'one', 'all'], true)) {
                    $mode = 'off';
                }
                $this->SetRepeat($mode);
                $this->SetValue('Repeat', $mode);
                break;

            case 'Mute':
                $muted = (bool)$Value;
                try {
                    $this->SetMute($muted);
                    $this->SetValue('Mute', $muted);
                } catch (Throwable $e) {
                    $this->SendDebug('Mute failed', $e->getMessage(), 0);
                    IPS_LogMessage('MusicAssistantPlayer', 'Mute failed: ' . $e->getMessage());
                    // Variable bewusst NICHT setzen, damit UI-Status nicht falsch wird
                }
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

    // Shuffle an/aus (laut deiner API-Doku)
    public function SetShuffle(bool $enabled): void
    {
        $queueId = $this->getQueueIdForPlayer();

        $this->maCall('player_queues/shuffle', [
            'queue_id'        => $queueId,
            'shuffle_enabled' => $enabled
        ]);
    }

    // Repeat off/one/all (laut deiner API-Doku)
    public function SetRepeat(string $mode): void
    {
        $queueId = $this->getQueueIdForPlayer();

        $this->maCall('player_queues/repeat', [
            'queue_id'    => $queueId,
            'repeat_mode' => $mode
        ]);
    }

    /**
     * Mute:
     * - zuerst direkt auf player_id probieren
     * - wenn das (bei SyncGroups oft) mit 500 scheitert: Mitglieder ermitteln und einzeln muten
     */
    public function SetMute(bool $muted): void
    {
        $playerId = $this->playerId();

        // 1) Direkt versuchen
        try {
            $this->maCall('players/cmd/volume_mute', [
                'player_id' => $playerId,
                'muted'     => $muted
            ]);
            return;
        } catch (Throwable $e) {
            // 2) SyncGroup: auf Mitglieder ausweichen
            if (str_starts_with($playerId, 'syncgroup_')) {
                $members = $this->getSyncGroupMembers($playerId);
                if (count($members) === 0) {
                    throw $e;
                }

                foreach ($members as $mid) {
                    try {
                        $this->maCall('players/cmd/volume_mute', [
                            'player_id' => $mid,
                            'muted'     => $muted
                        ]);
                    } catch (Throwable $inner) {
                        $this->SendDebug('Mute member failed', $mid . ': ' . $inner->getMessage(), 0);
                    }
                }
                return;
            }

            throw $e;
        }
    }

    // --------- intern ---------

    private function ensureRepeatProfile(): void
    {
        if (IPS_VariableProfileExists(self::REPEAT_PROFILE)) {
            $p = IPS_GetVariableProfile(self::REPEAT_PROFILE);
            if (($p['ProfileType'] ?? -1) !== VARIABLETYPE_STRING) {
                IPS_DeleteVariableProfile(self::REPEAT_PROFILE);
            }
        }
        if (!IPS_VariableProfileExists(self::REPEAT_PROFILE)) {
            IPS_CreateVariableProfile(self::REPEAT_PROFILE, VARIABLETYPE_STRING);
        }

        // Associations zurücksetzen
        $p = IPS_GetVariableProfile(self::REPEAT_PROFILE);
        foreach (($p['Associations'] ?? []) as $assoc) {
            IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, (string)$assoc['Value'], '', '', -1);
        }

        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'off', 'Off', '', -1);
        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'one', 'One', '', -1);
        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'all', 'All', '', -1);
    }

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

    /**
     * Queue-ID ermitteln (inkl. SyncGroups):
     * - Cache
     * - player_queues/all: match über player_id / queue_id / players[]
     * - Fallback: player_id
     */
    private function getQueueIdForPlayer(): string
    {
        $playerId = $this->playerId();

        $cached = trim((string)$this->GetBuffer('QueueId'));
        if ($cached !== '') {
            return $cached;
        }

        $resp   = $this->maCall('player_queues/all');
        $result = $resp['result'];

        $this->SendDebug('Queues RAW', json_encode($result, JSON_UNESCAPED_SLASHES), 0);

        if (is_array($result) && $this->isList($result)) {
            foreach ($result as $q) {
                $qid = (string)($q['queue_id'] ?? $q['id'] ?? '');
                $pid = (string)($q['player_id'] ?? $q['player'] ?? $q['playerId'] ?? '');

                if ($qid !== '' && $pid === $playerId) {
                    $this->SetBuffer('QueueId', $qid);
                    return $qid;
                }

                if ($qid !== '' && $qid === $playerId) {
                    $this->SetBuffer('QueueId', $qid);
                    return $qid;
                }

                if ($qid !== '' && isset($q['players']) && is_array($q['players'])) {
                    foreach ($q['players'] as $p) {
                        if ((string)$p === $playerId) {
                            $this->SetBuffer('QueueId', $qid);
                            return $qid;
                        }
                    }
                }
            }
        }

        $this->SendDebug('Queue fallback', 'Using player_id as queue_id: ' . $playerId, 0);
        $this->SetBuffer('QueueId', $playerId);
        return $playerId;
    }

    /**
     * SyncGroup-Member ermitteln (Key-Namen können je nach Version variieren).
     */
    private function getSyncGroupMembers(string $syncGroupId): array
    {
        $resp   = $this->maCall('players/all');
        $result = $resp['result'];

        if (!is_array($result) || !$this->isList($result)) {
            return [];
        }

        foreach ($result as $p) {
            $pid = (string)($p['player_id'] ?? $p['id'] ?? '');
            if ($pid !== $syncGroupId) {
                continue;
            }

            $candidates = [
                $p['group_members'] ?? null,
                $p['members'] ?? null,
                $p['players'] ?? null,
                $p['child_player_ids'] ?? null,
                $p['group_childs'] ?? null
            ];

            foreach ($candidates as $cand) {
                if (!is_array($cand)) {
                    continue;
                }

                $out = [];
                foreach ($cand as $x) {
                    if (is_string($x) && $x !== '') {
                        $out[] = $x;
                    } elseif (is_array($x)) {
                        $id = (string)($x['player_id'] ?? $x['id'] ?? '');
                        if ($id !== '') {
                            $out[] = $id;
                        }
                    }
                }

                $out = array_values(array_unique($out));
                $this->SendDebug('SyncGroup members', json_encode($out, JSON_UNESCAPED_SLASHES), 0);
                return $out;
            }

            return [];
        }

        return [];
    }

    private function ensureIntegerSelectorVariable(string $ident, string $name, string $profile, int $pos): void
    {
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

        $resp = $this->maCall($refreshCommand);
        $result = $resp['result'];

        if (is_array($result) && $this->isList($result)) {
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

        // PlayMedia nutzt queue_id – hier hat bisher player_id funktioniert, daher lassen wir es so.
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

    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
