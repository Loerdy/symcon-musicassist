<?php
declare(strict_types=1);

// Zustand der HTML-Kachel des MusicAssistantPlayer; GetVisualizationTile() und Konstanten liegen in der Klasse.
trait MusicAssistantPlayerTile
{
    private function configureVisualizationType(): void
    {
        $this->SetVisualizationType(
            $this->ReadPropertyBoolean('EnableCustomTile') ? 1 : 0
        );
    }

    private function updateVisualization(bool $force = false): void
    {
        if (!$this->ReadPropertyBoolean('EnableCustomTile')) {
            return;
        }

        try {
            // Das Cover (bis zu 512 KB) wird nur nach einer Änderung übertragen; neu geöffnete Kacheln erhalten es
            // über GetVisualizationTile im vollständigen Initialzustand.
            $coverRevision = $this->GetBuffer('CoverRevision');
            $includeCover = $this->GetBuffer('VisualizationCoverRevision') !== $coverRevision;
            $state = $this->buildVisualizationState(false);
            $stateHash = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
            if (!$force && !$includeCover && $this->GetBuffer('VisualizationStateHash') === $stateHash) {
                return;
            }
            if ($includeCover) {
                $state['cover'] = $this->readVisualizationCover();
            }
            $transmitted = $this->UpdateVisualizationValue(json_encode($state, JSON_THROW_ON_ERROR));
            if ($transmitted !== true) {
                if ($transmitted === false) {
                    $this->SendDebug('Visualization', 'Kachelzustand konnte nicht übertragen werden', 0);
                }
                return;
            }
            $this->SetBuffer('VisualizationStateHash', $stateHash);
            if ($includeCover) {
                $this->SetBuffer('VisualizationCoverRevision', $coverRevision);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Kachel konnte nicht aktualisiert werden: ' . $e->getMessage(), 0);
        }
    }

    private function buildVisualizationState(bool $includeCover = true): array
    {
        $transport = @$this->GetIDForIdent('Transport') > 0
            ? (int)$this->GetValue('Transport')
            : 0;
        $states = [1 => 'idle', 2 => 'playing', 3 => 'paused'];
        $volume = @$this->GetIDForIdent('VolumeLevel') > 0
            ? max(0, min(100, (int)$this->GetValue('VolumeLevel')))
            : 0;
        $muted = @$this->GetIDForIdent('Mute') > 0
            ? (bool)$this->GetValue('Mute')
            : false;
        $shuffle = @$this->GetIDForIdent('Shuffle') > 0
            ? (bool)$this->GetValue('Shuffle')
            : false;
        $repeatValue = @$this->GetIDForIdent('Repeat') > 0
            ? (int)$this->GetValue('Repeat')
            : 0;
        $repeat = self::REPEAT_VALUE_TO_MODE[$repeatValue] ?? 'off';
        $showFavorite = $this->ReadPropertyBoolean('ShowFavorite');
        $favorite = $showFavorite && @$this->GetIDForIdent('Favorite') > 0
            ? (bool)$this->GetValue('Favorite')
            : false;
        $playlistSelection = $this->readVisualizationSelection('Playlist');
        $radioSelection = $this->readVisualizationSelection('Radio');
        $playlists = $this->buildVisualizationSelectionOptions('Playlist', 'PlaylistProfile');
        $radios = $this->buildVisualizationSelectionOptions('Radio', 'RadioProfile');
        $groupMembers = $this->buildVisualizationGroupMembers();

        $state = [
            'title'       => $this->readVisualizationString('NowTitle'),
            'artist'      => $this->readVisualizationString('NowArtist'),
            'status'      => $states[$transport] ?? 'unknown',
            'volume'      => $volume,
            'muted'       => $muted,
            'shuffle'     => $shuffle,
            'repeat'      => $repeat,
            'showFavorite' => $showFavorite,
            'favorite'    => $favorite,
            'playlistSelection' => $playlistSelection,
            'radioSelection' => $radioSelection,
            'playlistSelectionRevision' => (int)$this->GetBuffer('PlaylistSelectionRevision'),
            'radioSelectionRevision' => (int)$this->GetBuffer('RadioSelectionRevision'),
            'playlists'   => $playlists,
            'radios'      => $radios,
            'groupMembers' => $groupMembers
        ] + $this->buildVisualizationPlaybackOptions();
        if ($includeCover) {
            $state['cover'] = $this->readVisualizationCover();
        }
        return $state;
    }

    // Die Schalter erscheinen nur, wenn die zugehörigen Variablen über die Instanzkonfiguration angelegt sind.
    private function buildVisualizationPlaybackOptions(): array
    {
        $showSleepTimer = $this->ReadPropertyBoolean('ShowSleepTimer')
            && @$this->GetIDForIdent('SleepTimer') > 0
            && @$this->GetIDForIdent('SleepTimerEnd') > 0;
        $sleepTimer = $showSleepTimer ? (int)$this->GetValue('SleepTimer') : 0;
        $sleepTimerEnd = $showSleepTimer && $sleepTimer !== 0 ? (string)$this->GetValue('SleepTimerEnd') : '';
        $sleepTimerOptions = [];
        if ($showSleepTimer) {
            $sleepTimerOptions[] = ['value' => 0, 'caption' => $this->Translate('Off')];
            foreach (self::SLEEP_TIMER_MINUTES as $minutes) {
                $sleepTimerOptions[] = ['value' => $minutes, 'caption' => sprintf($this->Translate('%d min'), $minutes)];
            }
        }
        $showQueueOptions = $this->ReadPropertyBoolean('ShowQueueOptions')
            && @$this->GetIDForIdent('Crossfade') > 0
            && @$this->GetIDForIdent('Autoplay') > 0;

        return [
            'showSleepTimer'    => $showSleepTimer,
            'sleepTimer'        => $sleepTimer,
            'sleepTimerEnd'     => $sleepTimerEnd === '-' ? '' : $sleepTimerEnd,
            'sleepTimerOptions' => $sleepTimerOptions,
            'showQueueOptions'  => $showQueueOptions,
            'crossfade'         => $showQueueOptions && (bool)$this->GetValue('Crossfade'),
            'autoplay'          => $showQueueOptions && (bool)$this->GetValue('Autoplay')
        ];
    }

    private function readVisualizationSelection(string $ident): int
    {
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return 0;
        }
        $variable = IPS_GetVariable($variableId);
        if (($variable['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            return 0;
        }
        return max(0, (int)$this->GetValue($ident));
    }

    private function buildVisualizationGroupMembers(): array
    {
        if (!$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
            return [];
        }
        return $this->withGroupMemberVolumeLock(function (): array {
            $map = $this->readGroupMemberVolumeMap();
            if ($map === null) {
                return [];
            }

            $members = [];
            foreach ($map as $ident => $entry) {
                $variableId = @$this->GetIDForIdent($ident);
                if ($variableId !== $entry['variable_id'] || !IPS_VariableExists($variableId)) {
                    continue;
                }
                $variable = IPS_GetVariable($variableId);
                if (($variable['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                    continue;
                }
                $volume = (int)$this->GetValue($ident);
                if ($volume < 0 || $volume > 100) {
                    continue;
                }
                $members[] = [
                    'ident'  => $ident,
                    'name'   => $entry['name'],
                    'volume' => $volume
                ];
            }
            return $members;
        });
    }

    private function buildVisualizationSelectionOptions(string $ident, string $profileProperty): array
    {
        return $this->readSelectionOptions($ident, $profileProperty) ?? [];
    }

    private function readVisualizationString(string $ident): string
    {
        return @$this->GetIDForIdent($ident) > 0
            ? (string)$this->GetValue($ident)
            : '';
    }

    private function readVisualizationCover(): string
    {
        $mediaId = @$this->GetIDForIdent('Cover');
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId) || !$this->coverMediaHasContent($mediaId)) {
            return '';
        }

        $media = IPS_GetMedia($mediaId);
        if ((int)($media['MediaSize'] ?? 0) > self::TILE_COVER_MAX_BYTES) {
            $this->SendDebug('Visualization', 'Cover ist für die Kachel zu groß', 0);
            return '';
        }
        $content = IPS_GetMediaContent($mediaId);
        if ($content === '') {
            return '';
        }
        $signature = base64_decode(substr($content, 0, 16), true);
        if (!is_string($signature)) {
            return '';
        }
        if (substr($signature, 0, 3) === "\xFF\xD8\xFF") {
            $contentType = 'image/jpeg';
        } elseif (substr($signature, 0, 8) === "\x89PNG\r\n\x1A\n") {
            $contentType = 'image/png';
        } else {
            return '';
        }

        return 'data:' . $contentType . ';base64,' . $content;
    }
}
