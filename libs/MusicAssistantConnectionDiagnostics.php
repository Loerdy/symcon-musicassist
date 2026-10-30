<?php
declare(strict_types=1);

// Diagnosebericht der MusicAssistantConnection für Fehlermeldungen im Forum; Konstanten liegen in der Klasse.
// Der Bericht nutzt nur lokal vorhandene Daten und enthält weder Token, Adressen noch Player-Namen oder -IDs.
trait MusicAssistantConnectionDiagnostics
{
    private function buildDiagnosticsReport(): string
    {
        $lines = [
            $this->Translate('Music Assistant diagnostics report'),
            $this->Translate('Created') . ': ' . date('Y-m-d H:i:s'),
            $this->Translate('Library') . ': ' . $this->diagnosticsLibraryVersion(),
            'IP-Symcon: ' . IPS_GetKernelVersion() . ' (' . IPS_GetKernelPlatform() . ')',
            'PHP: ' . PHP_VERSION,
            'Music Assistant: ' . $this->diagnosticsServerVersion(),
            $this->Translate('Tested with Music Assistant') . ': ' . self::TESTED_SERVER_VERSION,
            $this->diagnosticsConnectionLine(),
            $this->diagnosticsParentLine(),
            $this->Translate('Configuration') . ': ' . sprintf(
                $this->Translate('Port %d, token %s'),
                $this->ReadPropertyInteger('Port'),
                trim($this->ReadPropertyString('Token')) !== '' ? $this->Translate('set') : $this->Translate('missing')
            )
        ];
        return implode("\n", array_merge($lines, $this->diagnosticsPlayerLines()));
    }

    private function refreshDiagnosticsReport(): void
    {
        $this->UpdateFormField('DiagnosticsReport', 'value', $this->buildDiagnosticsReport());
    }

    private function diagnosticsLibraryVersion(): string
    {
        try {
            $moduleId = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
            $library = IPS_GetLibrary(IPS_GetModule($moduleId)['LibraryID']);
            // Git-Releases v<Major>.<Minor>.<Patch> bilden den Patch im Feld build ab.
            return $library['Version'] . '.' . $library['Build'];
        } catch (Throwable $e) {
            return $this->Translate('unknown');
        }
    }

    private function diagnosticsServerVersion(): string
    {
        $version = $this->GetBuffer('ServerVersion');
        if ($version === '') {
            return $this->Translate('unknown (no server greeting received)');
        }
        return $version . ' (' . sprintf($this->Translate('schema %s'), $this->GetBuffer('SchemaVersion')) . ')';
    }

    private function diagnosticsConnectionLine(): string
    {
        $status = $this->GetStatus();
        $captions = array_column($this->statusDefinitions(), 'caption', 'code');
        $caption = isset($captions[$status]) ? ' – ' . $this->Translate($captions[$status]) : '';
        return sprintf('Connection #%d: ', $this->InstanceID) . sprintf($this->Translate('status %d'), $status) . $caption;
    }

    private function diagnosticsParentLine(): string
    {
        $parentId = (int)$this->GetBuffer('ParentInstanceId');
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return $this->Translate('WebSocket Client') . ': ' . $this->Translate('not connected');
        }
        $parent = IPS_GetInstance($parentId);
        if (($parent['ModuleInfo']['ModuleID'] ?? null) !== self::WEBSOCKET_MODULE) {
            return sprintf('%s #%d: ', $this->Translate('Parent instance'), $parentId) . $this->Translate('not a WebSocket Client');
        }
        $active = IPS_GetKernelRunlevel() === KR_READY && IPS_GetProperty($parentId, 'Active') === true;
        return sprintf('%s #%d: ', $this->Translate('WebSocket Client'), $parentId)
            . ($active ? $this->Translate('active') : $this->Translate('inactive')) . ', '
            . sprintf($this->Translate('status %d'), (int)$parent['InstanceStatus']);
    }

    private function diagnosticsPlayerLines(): array
    {
        try {
            $registeredPlayers = $this->withStateLock(function (): array {
                return $this->readJsonBuffer('RegisteredPlayers');
            });
        } catch (Throwable $e) {
            $registeredPlayers = null;
        }

        $players = [];
        foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $playerInstanceId) {
            if (!IPS_InstanceExists($playerInstanceId)) {
                continue;
            }
            $playerInstance = IPS_GetInstance($playerInstanceId);
            if ((int)($playerInstance['ConnectionID'] ?? 0) !== $this->InstanceID) {
                continue;
            }
            $playerId = trim((string)IPS_GetProperty($playerInstanceId, 'PlayerID'));
            if ($playerId === '') {
                $registration = $this->Translate('no player ID');
            } elseif ($registeredPlayers === null) {
                $registration = $this->Translate('registration unknown');
            } else {
                $registration = isset($registeredPlayers[$playerId])
                    ? $this->Translate('registered')
                    : $this->Translate('not registered');
            }
            $players[] = sprintf('- #%d: ', $playerInstanceId)
                . sprintf($this->Translate('status %d'), (int)$playerInstance['InstanceStatus']) . ', ' . $registration;
        }
        return array_merge([sprintf($this->Translate('Player instances: %d'), count($players))], $players);
    }
}
