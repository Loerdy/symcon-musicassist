<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../MusicAssistantPlayer/module.php';
require_once __DIR__ . '/../DocumentedAttributeWrites.php';

class MusicAssistantPlayerStubCompat extends MusicAssistantPlayer
{
    use DocumentedAttributeWrites;

    // Die Stubs führen Timer nicht aus; Tests prüfen das geplante Intervall und rufen die Zielmethode selbst auf.
    public function GetTimerIntervalForTest(string $Ident): int
    {
        return $this->GetTimerInterval($Ident);
    }
}
