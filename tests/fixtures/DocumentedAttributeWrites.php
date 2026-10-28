<?php

declare(strict_types=1);

// Die SymconStubs liefern bei IPSModule::WriteAttribute* keinen Rückgabewert. Laut Symcon-Dokumentation
// (https://www.symcon.de/de/llms/function-index.md) geben die Funktionen bool zurück; die Module prüfen dies.
trait DocumentedAttributeWrites
{
    protected function WriteAttributeBoolean($Name, bool $Value)
    {
        parent::WriteAttributeBoolean($Name, $Value);
        return true;
    }

    protected function WriteAttributeInteger($Name, int $Value)
    {
        parent::WriteAttributeInteger($Name, $Value);
        return true;
    }

    protected function WriteAttributeFloat($Name, float $Value)
    {
        parent::WriteAttributeFloat($Name, $Value);
        return true;
    }

    protected function WriteAttributeString($Name, string $Value)
    {
        parent::WriteAttributeString($Name, $Value);
        return true;
    }
}
