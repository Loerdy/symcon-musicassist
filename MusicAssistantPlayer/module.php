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
        $this->SetBuffer('CoverUrl', '');
        $this->SetBuffer('CoverRoute', ''); // gemerkter Route-Name: direct|imageproxy|image|media_image

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
        if ($vidPlaylist > 0) IPS_SetIcon($vidPlaylist, 'list-music');

        $vidRadio = @$this->GetIDForIdent('Radio');
        if ($vidRadio > 0) IPS_SetIcon($vidRadio, 'radio');

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
        if ($vidAlbum > 0) IPS_SetIcon($vidAlbum, 'album');

        $this->EnsureCoverMedia();

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
                ['type' => 'Label', 'name' => 'ConfigView', 'caption' => '']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Konfiguration aktualisieren', 'onClick' => 'IPS_RequestAction($id, "UpdateConfigView", true);'],
                ['type' => 'Button', 'caption' => 'Queue-Cache leeren', 'onClick' => 'IPS_RequestAction($id, "ClearQueueCache", true);'],
                ['type' => 'Button', 'caption' => 'PollState jetzt', 'onClick' => 'IPS_RequestAction($id, "PollNow", true);'],
                ['type' => 'Button', 'caption' => 'Cover neu laden', 'onClick' => 'IPS_RequestAction($id, "RefreshCover", true);'],
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
            case 'RefreshCover':
                $this->SetBuffer('CoverUrl', '');
                $this->SetBuffer('CoverRoute', '');
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
                $this->SetMute($muted);
                $this->SetValue('Mute', $muted);
                $this->PollState();
                break;

            case 'VolumeLevel':
                $level = max(0, min(100, (int)$Value));
                $this->SetVolumeLevel($level);
                $this->PollState();
                break;

            case 'VolumeUp':
                if ((int)$Value === 1) {
                    $this->GroupVolumeUp();
                    $this->PollState();
                }
                $this->SetValue('VolumeUp', 0);
                break;

            case 'VolumeDown':
                if ((int)$Value === 1) {
                    $this->GroupVolumeDown();
                    $this->PollState();
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
            'CoverUrlCache'     => (string)$this->GetBuffer('CoverUrl'),
            'CoverRoute'        => (string)$this->GetBuffer('CoverRoute'),
            'MsgId'             => (string)$this->GetBuffer('MsgId'),
        ];

        $text = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (strlen($text) > 1800) $text = substr($text, 0, 1800) . "\n... (gekürzt)";
        $this->UpdateFormField('ConfigView', 'caption', $text);
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

                    // Cover candidate (nur Bilder)
                    $cover = '';

                    if (isset($q['current_item']['image']['path'])) {
                        $cand = (string)$q['current_item']['image']['path'];
                        if ($this->isLikelyImagePath($cand)) $cover = $cand;
                    }

                    if ($cover === '' && isset($q['current_item']['media_item']['image']['path'])) {
                        $cand = (string)$q['current_item']['media_item']['image']['path'];
                        if ($this->isLikelyImagePath($cand)) $cover = $cand;
                    }

                    if ($cover === '' && isset($q['current_item']['media_item']['metadata']['images']) && is_array($q['current_item']['media_item']['metadata']['images'])) {
                        $imgs = $q['current_item']['media_item']['metadata']['images'];

                        foreach ($imgs as $img) {
                            if (!is_array($img)) continue;
                            $path = (string)($img['path'] ?? '');
                            $remote = (bool)($img['remotely_accessible'] ?? false);
                            if ($remote && $this->isLikelyImagePath($path)) { $cover = $path; break; }
                        }
                        if ($cover === '') {
                            foreach ($imgs as $img) {
                                if (!is_array($img)) continue;
                                $path = (string)($img['path'] ?? '');
                                if ($this->isLikelyImagePath($path)) { $cover = $path; break; }
                            }
                        }
                    }

                    $this->SendDebug('CoverCandidate', $cover, 0);
                    $this->UpdateCoverIfChanged($cover);

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

    // ---------- Cover ----------

    private function isLikelyImagePath(string $path): bool
    {
        $p = strtolower(trim($path));
        if ($p === '') return false;
        if (preg_match('~^https?://~', $p)) return true;
        if (str_starts_with($p, '/')) return true;
        return (bool)preg_match('~\.(jpg|jpeg|png|webp|gif)$~', $p);
    }

    private function EnsureCoverMedia(): void
    {
        $mid = @$this->GetIDForIdent('Cover');
        if ($mid > 0) {
            $o = IPS_GetObject($mid);
            if (($o['ObjectType'] ?? 0) === OBJECTTYPE_MEDIA) return;
            @IPS_DeleteMedia($mid);
        }
        $mid = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetParent($mid, $this->InstanceID);
        IPS_SetIdent($mid, 'Cover');
        IPS_SetName($mid, 'Cover');
    }

    private function UpdateCoverIfChanged(string $pathOrUrl): void
    {
        $pathOrUrl = trim($pathOrUrl);
        $last = (string)$this->GetBuffer('CoverUrl');
        if ($pathOrUrl === $last) return;

        $this->SetBuffer('CoverUrl', $pathOrUrl);

        if ($pathOrUrl === '') {
            $this->ClearCover();
            return;
        }
        $this->SetCoverFromPathOrUrl($pathOrUrl);
    }

    private function SetCoverFromPathOrUrl(string $pathOrUrl): void
    {
        $base = $this->maBaseUrl();

        // direkt nutzbar (relative oder absolute URL)
        if (str_starts_with($pathOrUrl, '/')) {
            $url = $base . $pathOrUrl;
            $this->FetchCoverToMedia($url);
            $this->SetBuffer('CoverRoute', 'direct');
            return;
        }
        if (preg_match('~^https?://~i', $pathOrUrl)) {
            $this->FetchCoverToMedia($pathOrUrl);
            $this->SetBuffer('CoverRoute', 'direct');
            return;
        }

        // lokaler Pfad: Route autodetect (merkt sich erfolgreichen Pfad)
        $route = (string)$this->GetBuffer('CoverRoute');

        $candidates = [];
        if ($route === 'imageproxy')      $candidates[] = $base . '/imageproxy?path=' . rawurlencode($pathOrUrl);
        else if ($route === 'image')      $candidates[] = $base . '/image?path=' . rawurlencode($pathOrUrl);
        else if ($route === 'media_image')$candidates[] = $base . '/media/image?path=' . rawurlencode($pathOrUrl);

        // fallback-Reihenfolge
        $candidates[] = $base . '/imageproxy?path=' . rawurlencode($pathOrUrl);
        $candidates[] = $base . '/image?path=' . rawurlencode($pathOrUrl);
        $candidates[] = $base . '/media/image?path=' . rawurlencode($pathOrUrl);

        foreach (array_values(array_unique($candidates)) as $u) {
            $code = $this->FetchCoverToMedia($u, true);
            $this->SendDebug('CoverFetchHTTP', $u . ' -> ' . $code, 0);
            if ($code >= 200 && $code < 300) {
                if (str_contains($u, '/imageproxy?')) $this->SetBuffer('CoverRoute', 'imageproxy');
                elseif (str_contains($u, '/image?')) $this->SetBuffer('CoverRoute', 'image');
                else $this->SetBuffer('CoverRoute', 'media_image');
                return;
            }
        }
    }

    /**
     * Lädt URL per wget, setzt MediaContent. Wenn $returnHttpCode=true, gibt HTTP-Code zurück.
     */
    private function FetchCoverToMedia(string $url, bool $returnHttpCode = false): int
    {
        $tmp = sys_get_temp_dir() . '/ma_cover_' . $this->InstanceID . '.img';

        $token = trim($this->ReadPropertyString('Token'));
        $authHeader = ($token !== '') ? (' --header=' . escapeshellarg('Authorization: Bearer ' . $token)) : '';

        // HTTP-Code ermitteln
        $cmd = 'wget -S -qO ' . escapeshellarg($tmp)
            . ' --timeout=10'
            . $authHeader
            . ' ' . escapeshellarg($url)
            . ' 2>&1';

        $this->SendDebug('CoverFetchURL', $url, 0);
        $out = (string)@shell_exec($cmd);

        $code = 0;
        if (preg_match('~HTTP/\\d\\.\\d\\s+(\\d{3})~', $out, $m)) {
            $code = (int)$m[1];
        }

        $size = (is_file($tmp)) ? (int)filesize($tmp) : 0;
        $this->SendDebug('CoverFetchSize', (string)$size, 0);

        if ($code >= 200 && $code < 300 && $size >= 500) {
            $data = @file_get_contents($tmp);
            @unlink($tmp);
            if ($data !== false && $data !== '') {
                $mid = @$this->GetIDForIdent('Cover');
                if ($mid > 0) {
                    IPS_SetMediaContent($mid, base64_encode($data));
                }
            }
        } else {
            @unlink($tmp);
        }

        return $returnHttpCode ? $code : 0;
    }

    private function ClearCover(): void
    {
        $mid = @$this->GetIDForIdent('Cover');
        if ($mid > 0) IPS_SetMediaContent($mid, base64_encode(''));
    }

    // --------- MA Commands ---------

    public function SetShuffle(bool $enabled): void
    {
        $this->maCall('player_queues/shuffle', ['queue_id' => $this->playerId(), 'shuffle_enabled' => $enabled]);
    }

    public function SetRepeat(string $mode): void
    {
        $this->maCall('player_queues/repeat', ['queue_id' => $this->playerId(), 'repeat_mode' => $mode]);
    }

    public function SetMute(bool $muted): void
    {
        $this->maCall('players/cmd/volume_mute', ['player_id' => $this->playerId(), 'muted' => $muted]);
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

    private function ensureRepeatProfile(): void
    {
        if (IPS_VariableProfileExists(self::REPEAT_PROFILE)) {
            $p = IPS_GetVariableProfile(self::REPEAT_PROFILE);
            if (($p['ProfileType'] ?? -1) !== VARIABLETYPE_STRING) IPS_DeleteVariableProfile(self::REPEAT_PROFILE);
        }
        if (!IPS_VariableProfileExists(self::REPEAT_PROFILE)) IPS_CreateVariableProfile(self::REPEAT_PROFILE, VARIABLETYPE_STRING);

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
        if (!IPS_VariableProfileExists($profile)) IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
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
                $qid = (string)($q['queue_id'] ?? '');
                if ($qid === $playerId) {
                    $this->SetBuffer('QueueId', $qid);
                    return $qid;
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

        $this->maCall('player_queues/play_media', [
            'queue_id' => $this->playerId(),
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