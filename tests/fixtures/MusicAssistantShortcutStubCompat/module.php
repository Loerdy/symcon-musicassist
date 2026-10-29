<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../MusicAssistantShortcut/module.php';

class MusicAssistantShortcutStubCompat extends MusicAssistantShortcut
{
    // Die Stubs führen Timer nicht aus; Tests prüfen das geplante Intervall und rufen die Zielaktion selbst auf.
    public function GetTimerIntervalForTest(string $Ident): int
    {
        return $this->GetTimerInterval($Ident);
    }
}
