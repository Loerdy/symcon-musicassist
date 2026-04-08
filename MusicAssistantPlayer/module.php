<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApi.php';

class MusicAssistantPlayer extends IPSModule
{
    use MusicAssistantApi;

    private const REPEAT_PROFILE  = 'MA.RepeatMode';
    private const VOLUP_PROFILE   = 'MA.VolumeUp';
    private const VOLDOWN_PROFILE = 'MA.VolumeDown';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '127.0.0.1');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');

        $this->RegisterPropertyString('PlayerID', '');

        $this->RegisterPropertyString('PlaylistProfile', '');
        $this->RegisterPropertyString('PlaylistMap', '{}');

        $this->RegisterPropertyString('RadioProfile', '');
        $this->RegisterPropertyString('RadioMap', '{}');

        $this->RegisterPropertyInteger('StateSyncInterval', 5);

        $this->SetBuffer('MsgId', '0');
        $this->SetBuffer('QueueId', '');

        $this->RegisterTimer('ResetPlaylist', 0, 'MA_ResetPlaylistSelection($_IPS["TARGET"]);');
        $this->RegisterTimer('ResetRadio', 0, 'MA_ResetRadioSelection($_IPS["TARGET"]);');
        $this->RegisterTimer('PollState', 0, 'MA_PollState($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetBuffer('QueueId', '');

        $this->ensureIntegerSelectorVariable('Playlist', 'Playlist', $this->ReadPropertyString('PlaylistProfile'), 10);
        $this->ensureIntegerSelectorVariable('Radio', 'Radio', $this->ReadPropertyString('RadioProfile'), 20);

        $vidPlaylist = @$this->GetIDForIdent('Playlist');
        if ($vidPlaylist > 0) {
            IPS_SetIcon($vidPlaylist, 'list-music');
        }
        $vidRadio = @$this->GetIDForIdent('Radio');
        if ($vidRadio > 0) {
            IPS_SetIcon($vidRadio, 'radio');
        }

        $this->MaintainVariable('Transport', 'Wiedergabe', VARIABLETYPE_INTEGER, '~PlaybackPreviousNext', 30, true);
        $this->EnableAction('Transport');

        $this->MaintainVariable('Shuffle', 'Shuffle', VARIABLETYPE_BOOLEAN, '~Shuffle', 40, true);
        $this->EnableAction('Shuffle');

        $this->ensureRepeatProfile();
        $this->MaintainVariable('Repeat', 'Repeat', VARIABLETYPE_STRING, self::REPEAT_PROFILE, 50, true);
        $this->EnableAction('Repeat');

        $this->MaintainVariable('Mute', 'Mute', VARIABLETYPE_BOOLEAN, '~Mute', 60, true);
        $this->EnableAction('Mute');

        $this->MaintainVariable('VolumeLevel', 'Volume', VARIABLETYPE_INTEGER, '~Volume', 70, true);
        $this->EnableAction('VolumeLevel');

        $this->ensureVolumeButtonProfiles();
        $this->MaintainVariable('VolumeUp', 'Volume +', VARIABLETYPE_INTEGER, self::VOLUP_PROFILE, 80, true);
        $this->EnableAction('VolumeUp');

        $this->MaintainVariable('VolumeDown', 'Volume -', VARIABLETYPE_INTEGER, self::VOLDOWN_PROFILE, 90, true);
        $this->EnableAction('VolumeDown');

        $this->MaintainVariable('NowTitle',  'Titel',     VARIABLETYPE_STRING, '~Song',   110, true);
        $this->MaintainVariable('NowArtist', 'Interpret', VARIABLETYPE_STRING, '~Artist', 120, true);
        $this->MaintainVariable('NowAlbum',  'Album',     VARIABLETYPE_STRING, '',        130, true);
        $vidAlbum = @$this->GetIDForIdent('NowAlbum');
        if ($vidAlbum > 0) {
            IPS_SetIcon($vidAlbum, 'album');
        }

        if (@$this->GetIDForIdent('Repeat') > 0 && (string)@$this->GetValue('Repeat') === '') {
            $this->SetValue('Repeat', 'off');
        }

        $sec = (int)$this->ReadPropertyInteger('StateSyncInterval');
        $this->SetTimerInterval('PollState', ($sec > 0) ? $sec * 1000 : 0);

        $this->UpdateConfigView();
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Server'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port'],
                ['type' => 'PasswordTextBox', 'name' => 'Token', 'caption' => 'Token'],

                ['type' => 'ValidationTextBox', 'name' => 'PlayerID', 'caption' => 'Player ID (änderbar)'],
                ['type' => 'NumberSpinner', 'name' => 'StateSyncInterval', 'caption' => 'Polling (Sek.)'],

                ['type' => 'Label', 'caption' => 'Konfiguration (read-only):'],
                [
                    'type' => 'TextBox',
                    'name' => 'ConfigView',
                    'caption' => '',
                    'multiline' => true,
                    'enabled' => false,
                    'value' => ''
                ]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Konfiguration aktualisieren', 'onClick' => 'IPS_RequestAction($id, "UpdateConfigView", true);'],
                ['type' => 'Button', 'caption' => 'Queue-Cache leeren', 'onClick' => 'IPS_RequestAction($id, "ClearQueueCache", true);'],
                ['type' => 'Button', 'caption' => 'PollState jetzt', 'onClick' => 'IPS_RequestAction($id, "PollNow", true);'],
            ]
        ];

        return json_encode($form);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'UpdateConfigView':
                $this->UpdateConfigView();
                break;

            case 'ClearQueueCache':
                $this->ClearQueueCache();
                $this->UpdateConfigView();
                break;

            case 'PollNow':
                $this->PollState();
                $this->UpdateConfigView();
                break;

            case 'Playlist':
                try {
                    $itemId = (int)$Value;
                    if ($itemId > 0) {
                        $uri = $this->resolveUriFromMap($itemId, 'PlaylistMap', 'music/playlists/library_items');
                        $this->PlayMediaUri($uri);
                        $this->SetValue('Playlist', $itemId);
                        $this->SetTimerInterval('ResetPlaylist', 5000);
                        $this->PollState();
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Playlist start failed', $e->getMessage(), 0);
                    IPS_LogMessage('MusicAssistantPlayer', 'Playlist start failed: ' . $e->getMessage());
                }
                break;

            case 'Radio':
                try {
                    $itemId = (int)$Value;
                    if ($itemId > 0) {
                        $uri = $this->resolveUriFromMap($itemId, 'RadioMap', 'music/radios/library_items');
                        $this->PlayMediaUri($uri);
                        $this->SetValue('Radio', $itemId);
                        $this->SetTimerInterval('ResetRadio', 5000);
                        $this->PollState();
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Radio start failed', $e->getMessage(), 0);
                    IPS_LogMessage('MusicAssistantPlayer', 'Radio start failed: ' . $e->getMessage());
                }
                break;

            case 'Transport':
                $this->ExecuteTransport((int)$Value);
                $this->SetValue('Transport', (int)$Value);
                $this->PollState();
                break;

            case 'Shuffle':
                $enabled = (bool)$Value;
                $this->SetShuffle($enabled);
                $this->SetValue('Shuffle', $enabled);
                $this->PollState();
                break;

            case 'Repeat':
                $mode = strtolower(trim((string)$Value));
                if (!in_array($mode, ['off', 'one', 'all'], true)) $mode = 'off';
                $this->SetRepeat($mode);
                $this->SetValue('Repeat', $mode);
                $this->PollState();
                break;

            case 'Mute':
                $muted = (bool)$Value;
                try {
                    $this->SetMute($muted);
                    $this->SetValue('Mute', $muted);
                    $this->PollState();
                } catch (Throwable $e) {
                    $this->SendDebug('Mute failed', $e->getMessage(), 0);
                    IPS_LogMessage('MusicAssistantPlayer', 'Mute failed: ' . $e->getMessage());
                }
                break;

            case 'VolumeLevel':
                $level = max(0, min(100, (int)$Value));
                try {
                    $this->SetVolumeLevel($level);
                    $this->PollState();
                } catch (Throwable $e) {
                    $this->SendDebug('VolumeSet failed', $e->getMessage(), 0);
                    IPS_LogMessage('MusicAssistantPlayer', 'VolumeSet failed: ' . $e->getMessage());
                }
                break;

            case 'VolumeUp':
                if ((int)$Value === 1) {
                    try { $this->GroupVolumeUp(); $this->PollState(); }
                    catch (Throwable $e) { $this->SendDebug('VolumeUp failed', $e->getMessage(), 0); }
                }
                $this->SetValue('VolumeUp', 0);
                break;

            case 'VolumeDown':
                if ((int)$Value === 1) {
                    try { $this->GroupVolumeDown(); $this->PollState(); }
                    catch (Throwable $e) { $this->SendDebug('VolumeDown failed', $e->getMessage(), 0); }
                }
                $this->SetValue('VolumeDown', 0);
                break;

            default:
                throw new Exception('Unknown action: ' . $Ident);
        }
    }

    public function ResetPlaylistSelection(): void
    {
        $this->SetTimerInterval('ResetPlaylist', 0);
        if (@$this->GetIDForIdent('Playlist') > 0) $this->SetValue('Playlist', 0);
    }

    public function ResetRadioSelection(): void
    {
        $this->SetTimerInterval('ResetRadio', 0);
        if (@$this->GetIDForIdent('Radio') > 0) $this->SetValue('Radio', 0);
    }

    public function ClearQueueCache(): void
    {
        $this->SetBuffer('QueueId', '');
        $this->SendDebug('QueueCache', 'cleared', 0);
    }

    public function UpdateConfigView(): void
    {
        $cfg = [
            'InstanceID'        => $this->InstanceID,
            'Host'              => $this->ReadPropertyString('Host'),
            'Port'              => $this->ReadPropertyInteger('Port'),
            'PlayerID'          => $this->ReadPropertyString('PlayerID'),
            'StateSyncInterval' => $this->ReadPropertyInteger('StateSyncInterval'),
            'PlaylistProfile'   => $this->ReadPropertyString('PlaylistProfile'),
            'RadioProfile'      => $this->ReadPropertyString('RadioProfile'),
            'QueueIdCache'      => (string)$this->GetBuffer('QueueId'),
            'MsgId'             => (string)$this->GetBuffer('MsgId'),
        ];

        $text = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->UpdateFormField('ConfigView', 'value', $text);
    }

    public function PollState(): void
    {
        try {
            $queueId = $this->getQueueIdForPlayer();

            $resp   = $this->maCall('player_queues/all');
            $queues = $resp['result'] ?? null;

            if (is_array($queues) && $this->isList($queues)) {
                foreach ($queues as $q) {
                    if ((string)($q['queue_id'] ?? '') !== $queueId) continue;

                    if (isset($q['shuffle_enabled']) && @$this->GetIDForIdent('Shuffle') > 0) {
                        $shuffle = (bool)$q['shuffle_enabled'];
                        if ((bool)$this->GetValue('Shuffle') !== $shuffle) $this->SetValue('Shuffle', $shuffle);
                    }

                    if (isset($q['repeat_mode']) && @$this->GetIDForIdent('Repeat') > 0) {
                        $repeat = strtolower(trim((string)$q['repeat_mode']));
                        if (in_array($repeat, ['off', 'one', 'all'], true) && (string)$this->GetValue('Repeat') !== $repeat) {
                            $this->SetValue('Repeat', $repeat);
                        }
                    }

                    $state = strtolower(trim((string)($q['state'] ?? '')));
                    $hasCurrent = isset($q['current_item']) && is_array($q['current_item']);

                    $title = $artist = $album = '';
                    if ($state !== 'idle' && $hasCurrent) {
                        $mi = $q['current_item']['media_item'] ?? null;
                        if (is_array($mi)) {
                            $title = (string)($mi['name'] ?? '');
                            if (isset($mi['artists']) && is_array($mi['artists']) && count($mi['artists']) > 0) {
                                $a0 = $mi['artists'][0];
                                $artist = is_array($a0) ? (string)($a0['name'] ?? '') : (string)$a0;
                            }
                            if (isset($mi['album']) && is_array($mi['album'])) {
                                $album = (string)($mi['album']['name'] ?? '');
                            }
                        }
                    }

                    $this->setIfChangedString('NowTitle', $title);
                    $this->setIfChangedString('NowArtist', $artist);
                    $this->setIfChangedString('NowAlbum', $album);

                    break;
                }
            }

            $this->updateVolumeFromPlayersAll();
        } catch (Throwable $e) {
            $this->SendDebug('PollState failed', $e->getMessage(), 0);
        }
    }

    private function updateVolumeFromPlayersAll(): void
    {
        try {
            $pid = $this->playerId();

            $resp = $this->maCall('players/all');
            $players = $resp['result'] ?? null;

            if (!is_array($players) || !$this->isList($players)) return;

            foreach ($players as $p) {
                $id = (string)($p['player_id'] ?? $p['id'] ?? '');
                if ($id !== $pid) continue;

                $vol = null;
                foreach (['volume_level', 'volume', 'volumeLevel', 'group_volume_level', 'group_volume', 'groupVolumeLevel'] as $k) {
                    if (isset($p[$k])) { $vol = (int)$p[$k]; break; }
                }

                if ($vol !== null && @$this->GetIDForIdent('VolumeLevel') > 0) {
                    $vol = max(0, min(100, $vol));
                    if ((int)$this->GetValue('VolumeLevel') !== $vol) $this->SetValue('VolumeLevel', $vol);
                }
                break;
            }
        } catch (Throwable $e) {
            $this->SendDebug('updateVolumeFromPlayersAll failed', $e->getMessage(), 0);
        }
    }

    private function setIfChangedString(string $ident, string $value): void
    {
        if (@$this->GetIDForIdent($ident) <= 0) return;
        if ((string)$this->GetValue($ident) !== $value) $this->SetValue($ident, $value);
    }

    public function SetShuffle(bool $enabled): void
    {
        $queueId = $this->getQueueIdForPlayer();
        $this->maCall('player_queues/shuffle', ['queue_id' => $queueId, 'shuffle_enabled' => $enabled]);
    }

    public function SetRepeat(string $mode): void
    {
        $queueId = $this->getQueueIdForPlayer();
        $this->maCall('player_queues/repeat', ['queue_id' => $queueId, 'repeat_mode' => $mode]);
    }

    public function SetMute(bool $muted): void
    {
        $playerId = $this->playerId();

        try {
            $this->maCall('players/cmd/volume_mute', ['player_id' => $playerId, 'muted' => $muted]);
            return;
        } catch (Throwable $e) {
            if (str_starts_with($playerId, 'syncgroup_')) {
                $members = $this->getSyncGroupMembers($playerId);
                if (count($members) === 0) throw $e;

                foreach ($members as $mid) {
                    try {
                        $this->maCall('players/cmd/volume_mute', ['player_id' => $mid, 'muted' => $muted]);
                    } catch (Throwable $inner) {
                        $this->SendDebug('Mute member failed', $mid . ': ' . $inner->getMessage(), 0);
                    }
                }
                return;
            }
            throw $e;
        }
    }

    public function SetVolumeLevel(int $level): void
    {
        $this->maCall('players/cmd/volume_set', ['player_id' => $this->playerId(), 'volume_level' => $level]);
    }

    public function GroupVolumeUp(): void
    {
        $this->maCall('players/cmd/group_volume_up', ['player_id' => $this->playerId()]);
    }

    public function GroupVolumeDown(): void
    {
        $this->maCall('players/cmd/group_volume_down', ['player_id' => $this->playerId()]);
    }

    private function getSyncGroupMembers(string $syncGroupId): array
    {
        $resp   = $this->maCall('players/all');
        $result = $resp['result'];

        if (!is_array($result) || !$this->isList($result)) return [];

        foreach ($result as $p) {
            $pid = (string)($p['player_id'] ?? $p['id'] ?? '');
            if ($pid !== $syncGroupId) continue;

            $candidates = [
                $p['group_members'] ?? null,
                $p['members'] ?? null,
                $p['players'] ?? null,
                $p['child_player_ids'] ?? null,
                $p['group_childs'] ?? null
            ];

            foreach ($candidates as $cand) {
                if (!is_array($cand)) continue;

                $out = [];
                foreach ($cand as $x) {
                    if (is_string($x) && $x !== '') $out[] = $x;
                    elseif (is_array($x)) {
                        $id = (string)($x['player_id'] ?? $x['id'] ?? '');
                        if ($id !== '') $out[] = $id;
                    }
                }
                return array_values(array_unique($out));
            }

            return [];
        }

        return [];
    }

    private function ensureRepeatProfile(): void
    {
        if (IPS_VariableProfileExists(self::REPEAT_PROFILE)) {
            $p = IPS_GetVariableProfile(self::REPEAT_PROFILE);
            if (($p['ProfileType'] ?? -1) !== VARIABLETYPE_STRING) IPS_DeleteVariableProfile(self::REPEAT_PROFILE);
        }
        if (!IPS_VariableProfileExists(self::REPEAT_PROFILE)) {
            IPS_CreateVariableProfile(self::REPEAT_PROFILE, VARIABLETYPE_STRING);
        }

        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'off', 'Off', 'ban', -1);
        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'one', 'One', 'arrows-repeat-1', -1);
        IPS_SetVariableProfileAssociation(self::REPEAT_PROFILE, 'all', 'All', 'arrows-repeat', -1);
    }

    private function ensureVolumeButtonProfiles(): void
    {
        $this->ensureIntButtonProfile(self::VOLUP_PROFILE, 'Up', 'volume-up');
        $this->ensureIntButtonProfile(self::VOLDOWN_PROFILE, 'Down', 'volume-down');
    }

    private function ensureIntButtonProfile(string $profile, string $caption, string $icon): void
    {
        if (IPS_VariableProfileExists($profile)) {
            $p = IPS_GetVariableProfile($profile);
            if (($p['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) IPS_DeleteVariableProfile($profile);
        }
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation($profile, 1, $caption, $icon, -1);
    }

    private function ExecuteTransport(int $value): void
    {
        $pid = $this->playerId();
        switch ($value) {
            case 0: $this->maCall('players/cmd/previous', ['player_id' => $pid]); break;
            case 1: $this->maCall('players/cmd/stop',     ['player_id' => $pid]); break;
            case 2: $this->maCall('players/cmd/play',     ['player_id' => $pid]); break;
            case 3: $this->maCall('players/cmd/pause',    ['player_id' => $pid]); break;
            case 4: $this->maCall('players/cmd/next',     ['player_id' => $pid]); break;
            default: break;
        }
    }

    private function getQueueIdForPlayer(): string
    {
        $playerId = $this->playerId();

        $cached = trim((string)$this->GetBuffer('QueueId'));
        if ($cached !== '') return $cached;

        $resp   = $this->maCall('player_queues/all');
        $result = $resp['result'];

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

        $this->SetBuffer('QueueId', $playerId);
        return $playerId;
    }

    private function ensureIntegerSelectorVariable(string $ident, string $name, string $profile, int $pos): void
    {
        $existingId = @$this->GetIDForIdent($ident);
        if ($existingId > 0) {
            $v = IPS_GetVariable($existingId);
            if (($v['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) IPS_DeleteVariable($existingId);
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
        if ($itemId <= 0) throw new Exception('Invalid item_id');

        $mapRaw = trim($this->ReadPropertyString($mapProperty));
        $map = json_decode($mapRaw, true);
        if (!is_array($map)) $map = [];

        $key = (string)$itemId;
        if (isset($map[$key]) && is_string($map[$key]) && $map[$key] !== '') return $map[$key];

        $resp = $this->maCall($refreshCommand);
        $result = $resp['result'];

        if (is_array($result) && $this->isList($result)) {
            foreach ($result as $it) {
                $idStr = (string)($it['item_id'] ?? '');
                $id    = (int)$idStr;
                $uri   = (string)($it['uri'] ?? '');
                if ($id > 0 && $uri !== '') $map[(string)$id] = $uri;
            }
            if (isset($map[$key])) return $map[$key];
        }

        throw new Exception('URI not found for item_id=' . $itemId);
    }

    private function PlayMediaUri(string $uri): void
    {
        if ($uri === '') throw new Exception('Media URI is empty');

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
        if ($id === '') throw new Exception('PlayerID not configured');
        return $id;
    }

    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}