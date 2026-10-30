<?php
declare(strict_types=1);

// Sleep-Timer sowie Crossfade und Autoplay der Queue im MusicAssistantPlayer; Konstanten liegen in der Klasse.
trait MusicAssistantPlayerPlaybackOptions
{
    private function maintainPlaybackOptionVariables(): void
    {
        $showSleepTimer = $this->ReadPropertyBoolean('ShowSleepTimer');
        $options = [['Value' => 0, 'Caption' => $this->Translate('Off')]];
        foreach (self::SLEEP_TIMER_MINUTES as $minutes) {
            $options[] = ['Value' => $minutes, 'Caption' => sprintf($this->Translate('%d min'), $minutes)];
        }
        $this->MaintainVariable(
            'SleepTimer',
            $this->Translate('Sleep timer'),
            VARIABLETYPE_INTEGER,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'ICON'         => 'moon',
                'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
            ],
            100,
            $showSleepTimer
        );
        $this->MaintainVariable(
            'SleepTimerEnd',
            $this->Translate('Sleep timer ends'),
            VARIABLETYPE_STRING,
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'ICON'         => 'clock'
            ],
            105,
            $showSleepTimer
        );
        if ($showSleepTimer) {
            $this->EnableAction('SleepTimer');
            if ((string)$this->GetValue('SleepTimerEnd') === '') {
                $this->SetValue('SleepTimerEnd', '-');
            }
        }

        $showQueueOptions = $this->ReadPropertyBoolean('ShowQueueOptions');
        $this->MaintainVariable(
            'Crossfade',
            $this->Translate('Crossfade'),
            VARIABLETYPE_BOOLEAN,
            ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'USE_ICON_FALSE' => false, 'ICON_TRUE' => 'wave-square'],
            55,
            $showQueueOptions
        );
        $this->MaintainVariable(
            'Autoplay',
            $this->Translate('Autoplay'),
            VARIABLETYPE_BOOLEAN,
            ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'USE_ICON_FALSE' => false, 'ICON_TRUE' => 'infinity'],
            56,
            $showQueueOptions
        );
        if ($showQueueOptions) {
            $this->EnableAction('Crossfade');
            $this->EnableAction('Autoplay');
        }
    }

    // Holt beim Einschalten den aktuellen Zustand; danach folgen die Werte den Ereignissen der Connection.
    private function refreshPlaybackOptions(): void
    {
        $showSleepTimer = $this->ReadPropertyBoolean('ShowSleepTimer');
        $showQueueOptions = $this->ReadPropertyBoolean('ShowQueueOptions');
        if ((!$showSleepTimer && !$showQueueOptions)
            || IPS_GetKernelRunlevel() !== KR_READY
            || trim($this->ReadPropertyString('PlayerID')) === ''
            || !$this->HasActiveParent()) {
            return;
        }
        if ($showSleepTimer) {
            try {
                $expiresAt = $this->sendApiRequest('players/sleep_timer/get', ['player_id' => $this->playerId()]);
                $this->updateSleepTimerState($expiresAt);
            } catch (Throwable $e) {
                $this->SendDebug('Sleep timer', 'Zustand konnte nicht gelesen werden: ' . $e->getMessage(), 0);
            }
        }
        if ($showQueueOptions) {
            try {
                $this->updateQueueOptionStates($this->getActiveQueueForPlayer());
            } catch (Throwable $e) {
                $this->SendDebug('Queue options', 'Zustand konnte nicht gelesen werden: ' . $e->getMessage(), 0);
            }
        }
    }

    private function requestSleepTimer(int $minutes): void
    {
        if ($minutes !== 0 && !in_array($minutes, self::SLEEP_TIMER_MINUTES, true)) {
            throw new InvalidArgumentException($this->Translate('Invalid sleep timer duration.'));
        }
        if ($minutes === 0) {
            $this->sendApiRequest('players/sleep_timer/clear', ['player_id' => $this->playerId()]);
            $this->updateSleepTimerState(null);
            return;
        }
        $seconds = $minutes * 60;
        $expiresAt = $this->sendApiRequest('players/sleep_timer/set', ['player_id' => $this->playerId(), 'seconds' => $seconds]);
        $this->SetValue('SleepTimer', $minutes);
        // Die Antwort ist laut Doku eine Zahl; ohne verwertbaren Zeitpunkt wird das Ende aus der Dauer berechnet.
        $this->updateSleepTimerState($this->sleepTimerTimestamp($expiresAt) ?? (float)(time() + $seconds));
    }

    private function requestQueueOption(string $ident, bool $enabled): void
    {
        $command = $ident === 'Crossfade' ? 'player_queues/crossfade' : 'player_queues/autoplay';
        $field = $ident === 'Crossfade' ? 'crossfade_enabled' : 'autoplay_enabled';
        $this->sendApiRequest($command, ['queue_id' => $this->getQueueIdForPlayer(), $field => $enabled]);
        $this->SetValue($ident, $enabled);
    }

    private function updateSleepTimerState($expiresAt): void
    {
        if (@$this->GetIDForIdent('SleepTimer') <= 0 || @$this->GetIDForIdent('SleepTimerEnd') <= 0) {
            return;
        }
        $timestamp = $this->sleepTimerTimestamp($expiresAt);
        if ($timestamp === null || $timestamp <= time()) {
            if ((int)$this->GetValue('SleepTimer') !== 0) {
                $this->SetValue('SleepTimer', 0);
            }
            if ((string)$this->GetValue('SleepTimerEnd') !== '-') {
                $this->SetValue('SleepTimerEnd', '-');
            }
            return;
        }
        // Ein außerhalb von IP-Symcon gesetzter Timer erhält die nächstgrößere Stufe; das Ende bleibt exakt.
        if ((int)$this->GetValue('SleepTimer') === 0) {
            $remaining = (int)ceil(($timestamp - time()) / 60);
            $step = self::SLEEP_TIMER_MINUTES[count(self::SLEEP_TIMER_MINUTES) - 1];
            foreach (self::SLEEP_TIMER_MINUTES as $minutes) {
                if ($minutes >= $remaining) {
                    $step = $minutes;
                    break;
                }
            }
            $this->SetValue('SleepTimer', $step);
        }
        $end = date('H:i', (int)round($timestamp));
        if ((string)$this->GetValue('SleepTimerEnd') !== $end) {
            $this->SetValue('SleepTimerEnd', $end);
        }
        $this->SendDebug('Sleep timer', 'Aktiv bis ' . $end, 0);
    }

    private function updateQueueOptionStates(array $data): void
    {
        foreach (['Crossfade' => 'crossfade_enabled', 'Autoplay' => 'autoplay_enabled'] as $ident => $field) {
            if (!array_key_exists($field, $data) || !is_bool($data[$field]) || @$this->GetIDForIdent($ident) <= 0) {
                continue;
            }
            if ((bool)$this->GetValue($ident) !== $data[$field]) {
                $this->SetValue($ident, $data[$field]);
                $this->SendDebug($ident, 'Queue-State -> ' . ($data[$field] ? 'Ein' : 'Aus'), 0);
            }
        }
    }

    private function sleepTimerTimestamp($value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $timestamp = (float)$value;
        // Ein Zeitstempel in Millisekunden wird erkannt, da Sekundenwerte erst im Jahr 5138 diese Größe erreichen.
        if ($timestamp > 1.0e11) {
            $timestamp /= 1000;
        }
        return $timestamp > 0 ? $timestamp : null;
    }
}
