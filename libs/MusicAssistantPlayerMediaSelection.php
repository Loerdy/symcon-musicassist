<?php
declare(strict_types=1);

// Playlist- und Radio-Auswahl des MusicAssistantPlayer; ResetPlaylistSelection()/ResetRadioSelection() und Konstanten liegen in der Klasse.
trait MusicAssistantPlayerMediaSelection
{
    // Die Auswahl beruht allein auf dem Variablenprofil; Playlist- und Radio-Adressen ermittelt der Player beim
    // Abspielen selbst. Der Configurator schreibt keine Zuordnungen mehr in die Player (Store-Reviewrichtlinie Punkt 13).
    private function readSelectionOptions(string $ident, string $profileProperty): ?array
    {
        $variableId = @$this->GetIDForIdent($ident);
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
                : trim($this->ReadPropertyString($profileProperty));
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

    private function resetInvalidMediaSelection(string $ident, string $profileProperty, string $resetTimer): void
    {
        if ($this->GetTimerInterval($resetTimer) > 0) {
            return;
        }
        $options = $this->readSelectionOptions($ident, $profileProperty);
        if ($options === null) {
            return;
        }
        $selectedItemId = (int)$this->GetValue($ident);
        if ($selectedItemId <= 0 || in_array($selectedItemId, array_column($options, 'value'), true)) {
            return;
        }

        $this->SetTimerInterval($resetTimer, 0);
        $this->SetValue($ident, 0);
    }

    private function ensureIntegerSelectorVariable(string $ident, string $name, string $profile, int $pos): bool
    {
        $existingId = @$this->GetIDForIdent($ident);
        $hadIntegerVariable = false;
        if ($existingId > 0) {
            $existingType = IPS_VariableExists($existingId)
                ? (IPS_GetVariable($existingId)['VariableType'] ?? -1)
                : -1;
            $hadIntegerVariable = $existingType === VARIABLETYPE_INTEGER;
            // Bis alpha 0.4 war Playlist eine String-Variable des Moduls; nur diese wird migriert.
            $isLegacyPlaylist = $ident === 'Playlist' && $existingType === VARIABLETYPE_STRING;
            if (!$hadIntegerVariable && !$isLegacyPlaylist) {
                $message = sprintf(
                    $this->Translate('The ident %s is used by an incompatible object. The selection variable was not created.'),
                    $ident
                );
                $this->SendDebug($ident, $message, 0);
                $this->LogMessage($message, KL_WARNING);
                return false;
            }
            if ($isLegacyPlaylist && !IPS_DeleteVariable($existingId)) {
                $message = sprintf($this->Translate('The legacy variable %s could not be replaced.'), $ident);
                $this->SendDebug($ident, $message, 0);
                $this->LogMessage($message, KL_ERROR);
                return false;
            }
        }

        $profile = trim($profile);
        $validProfile = '';
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            $pp = IPS_GetVariableProfile($profile);
            if (($pp['ProfileType'] ?? -1) === VARIABLETYPE_INTEGER) {
                $validProfile = $profile;
            }
        }

        $this->MaintainVariable($ident, $name, VARIABLETYPE_INTEGER, $validProfile, $pos, true);
        $this->EnableAction($ident);

        $variableId = @$this->GetIDForIdent($ident);
        return !$hadIntegerVariable
            && $variableId > 0
            && IPS_VariableExists($variableId)
            && (IPS_GetVariable($variableId)['VariableType'] ?? -1) === VARIABLETYPE_INTEGER
            && ($existingId <= 0 || $variableId !== $existingId);
    }

    private function assertIntegerVariable(string $ident): void
    {
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId <= 0
            || !IPS_VariableExists($variableId)
            || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
            throw new Exception(sprintf(
                $this->Translate('The selection variable %s is not available.'),
                $ident
            ));
        }
    }

    private function resolveUriFromMap(int $itemId, string $mapProperty, string $refreshCommand): string
    {
        if ($itemId <= 0) {
            throw new Exception($this->Translate('Invalid item_id'));
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

        $result = $this->sendApiRequest($refreshCommand);

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

        throw new Exception(sprintf($this->Translate('URI not found for item_id=%s'), $itemId));
    }

    private function PlayMediaUri(string $uri): void
    {
        if ($uri === '') {
            throw new Exception($this->Translate('Media URI is empty'));
        }

        $queueId = $this->getQueueIdForPlayer();
        $this->sendApiRequest('player_queues/play_media', [
            'queue_id' => $queueId,
            'media'    => $uri,
            'option'   => 'replace'
        ]);
    }

    private function resolvePlaylistFromMap(int $itemId): array
    {
        if ($itemId <= 0) {
            throw new Exception($this->Translate('Invalid item_id'));
        }

        $map = json_decode(trim($this->ReadPropertyString('PlaylistMap')), true);
        if (!is_array($map)) {
            $map = [];
        }

        $key = (string)$itemId;
        $legacyEntry = null;
        if (isset($map[$key])) {
            $entry = $this->normalizePlaylistMapEntry($map[$key]);
            if ($entry !== null) {
                if (!is_string($map[$key])) {
                    return $entry;
                }
                $legacyEntry = $entry;
            }
        }

        try {
            $result = $this->sendApiRequest('music/playlists/library_items', ['summary' => false]);
        } catch (Throwable $e) {
            if ($legacyEntry !== null) {
                $this->SendDebug('Playlist', 'Provider-Auflösung fehlgeschlagen: ' . $e->getMessage(), 0);
                return $legacyEntry;
            }
            throw $e;
        }
        if (is_array($result) && $this->isList($result)) {
            foreach ($result as $playlist) {
                if (!is_array($playlist) || (int)($playlist['item_id'] ?? 0) !== $itemId) {
                    continue;
                }
                $entry = $this->normalizePlaylistMapEntry([
                    'uri'               => $playlist['uri'] ?? null,
                    'provider_mappings' => $playlist['provider_mappings'] ?? []
                ]);
                if ($entry !== null) {
                    return $entry;
                }
            }
        }
        if ($legacyEntry !== null) {
            $this->SendDebug('Playlist', 'Keine Provider-Daten für item_id=' . $itemId . '; gespeicherte URI wird verwendet', 0);
            return $legacyEntry;
        }

        throw new Exception(sprintf($this->Translate('URI not found for item_id=%s'), $itemId));
    }

    private function normalizePlaylistMapEntry($entry): ?array
    {
        if (is_string($entry) && trim($entry) !== '') {
            return ['uri' => trim($entry), 'provider_mappings' => []];
        }
        if (!is_array($entry) || !is_string($entry['uri'] ?? null) || trim($entry['uri']) === '') {
            return null;
        }

        return [
            'uri'               => trim($entry['uri']),
            'provider_mappings' => is_array($entry['provider_mappings'] ?? null)
                ? $entry['provider_mappings']
                : []
        ];
    }

    private function PlayPlaylist(array $playlist): void
    {
        $builtinItemId = $this->getRefreshableBuiltinPlaylistItemId($playlist);
        if ($builtinItemId === null) {
            $this->PlayMediaUri($playlist['uri']);
            return;
        }

        $queueId = $this->getQueueIdForPlayer();
        $this->playRefreshedPlaylist($queueId, $builtinItemId, 'builtin');
    }

    private function getRefreshableBuiltinPlaylistItemId(array $playlist): ?string
    {
        $refreshableItemIds = ['random_tracks', 'random_album', 'random_artist'];
        foreach ($playlist['provider_mappings'] as $mapping) {
            if (!is_array($mapping)
                || !is_string($mapping['item_id'] ?? null)
                || !in_array($mapping['item_id'], $refreshableItemIds, true)) {
                continue;
            }
            if (($mapping['provider_instance'] ?? null) === 'builtin'
                && ($mapping['provider_domain'] ?? null) === 'builtin') {
                return $mapping['item_id'];
            }
        }
        return null;
    }

    private function playRefreshedPlaylist(string $queueId, string $itemId, string $provider): void
    {
        try {
            $response = $this->SendDataToParent(json_encode([
                'DataID'   => self::CONNECTION_REQUEST,
                'Command'  => 'PlayRefreshedPlaylist',
                'QueueID'  => $queueId,
                'ItemID'   => $itemId,
                'Provider' => $provider
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            throw new Exception($this->Translate('PlayRefreshedPlaylist could not be created or sent.'));
        }

        if (!is_string($response) || trim($response) === '') {
            throw new Exception($this->Translate('PlayRefreshedPlaylist did not return a valid response.'));
        }

        try {
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new Exception($this->Translate('PlayRefreshedPlaylist returned invalid JSON.'));
        }
        if (!is_array($result)) {
            throw new Exception($this->Translate('PlayRefreshedPlaylist response is not a JSON object.'));
        }
        if (($result['success'] ?? false) !== true) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : [];
            $code = is_string($error['code'] ?? null) && trim($error['code']) !== ''
                ? trim($error['code'])
                : 'UNKNOWN_ERROR';
            $message = is_string($error['message'] ?? null) && trim($error['message']) !== ''
                ? trim($error['message'])
                : $this->Translate('PlayRefreshedPlaylist failed.');
            throw new Exception($code . ': ' . $message);
        }
    }
}
