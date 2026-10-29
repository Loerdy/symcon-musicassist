<?php
declare(strict_types=1);

// Liest die Playlist- bzw. Radio-Auswahl aus dem Integer-Profil einer Auswahlvariable; genutzt von Player und Schnellzugriff.
trait MusicAssistantSelectionOptions
{
    private function readSelectionOptionsOfVariable(int $variableId, string $fallbackProfile): ?array
    {
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return null;
        }
        $variable = IPS_GetVariable($variableId);
        if (($variable['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            return null;
        }

        $profile = is_string($variable['VariableCustomProfile'] ?? null)
            ? trim($variable['VariableCustomProfile'])
            : '';
        if ($profile === '') {
            $profile = is_string($variable['VariableProfile'] ?? null)
                ? trim($variable['VariableProfile'])
                : trim($fallbackProfile);
        }
        if ($profile === '' || !IPS_VariableProfileExists($profile)) {
            return null;
        }
        $profileData = IPS_GetVariableProfile($profile);
        if (($profileData['ProfileType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            return null;
        }

        $options = [];
        foreach (($profileData['Associations'] ?? []) as $association) {
            $value = $association['Value'] ?? null;
            $name = $association['Name'] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_string($name)) {
                continue;
            }
            $itemId = (int)$value;
            if ($itemId <= 0 || (float)$itemId !== (float)$value || trim($name) === '') {
                continue;
            }
            $options[] = ['value' => $itemId, 'label' => trim($name)];
        }
        return $options;
    }
}
