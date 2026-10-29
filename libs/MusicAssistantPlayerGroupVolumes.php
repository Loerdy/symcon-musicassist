<?php
declare(strict_types=1);

// Lautstärkeregler der Gruppenmitglieder im MusicAssistantPlayer; Konstanten liegen in der Klasse.
trait MusicAssistantPlayerGroupVolumes
{
    private function runGroupMemberVolumeRefresh(): void
    {
        $this->SetTimerInterval('RefreshGroupMemberVolumes', 0);

        try {
            $job = $this->withGroupMemberVolumeLock(function (): ?array {
                if ($this->GetBuffer('RunningGroupMemberVolumeRefresh') !== '') {
                    return null;
                }
                $pending = $this->readPendingGroupMemberVolumeRefresh();
                if ($pending === null) {
                    return null;
                }
                $this->SetBuffer('RunningGroupMemberVolumeRefresh', (string)$pending['generation']);
                return $pending;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Aktualisierung konnte nicht gestartet werden: ' . $e->getMessage(), 0);
            return;
        }
        if ($job === null) {
            return;
        }

        $updated = false;
        $httpSnapshotLoaded = false;
        try {
            if ($this->GetStatus() !== IS_ACTIVE
                || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')
                || !$this->HasActiveParent()
                || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                return;
            }

            $players = $this->sendApiRequest('players/all', [
                'return_unavailable' => true,
                'return_disabled' => true
            ]);
            // isList() akzeptiert eine leere Liste; wie bisher gilt sie hier aber nicht als vollständiger Snapshot.
            if (!is_array($players) || $players === [] || !$this->isList($players)) {
                throw new UnexpectedValueException($this->Translate('players/all did not return a complete player list.'));
            }
            $playersById = [];
            foreach ($players as $player) {
                if (!is_array($player) || !is_string($player['player_id'] ?? null)
                    || trim($player['player_id']) === '') {
                    continue;
                }
                $playersById[$this->groupMemberPlayerKey(trim($player['player_id']))] = $player;
            }
            $groupKey = $this->groupMemberPlayerKey($job['player_id']);
            $snapshotGroup = $playersById[$groupKey] ?? null;
            $snapshotHasMembers = is_array($snapshotGroup) && array_key_exists('group_members', $snapshotGroup);
            $snapshotMemberIdsValid = $snapshotHasMembers
                && $this->validateGroupMemberIds($snapshotGroup['group_members'], $job['player_id']) !== null;
            $httpSnapshotLoaded = $job['confirmation_member_ids'] === null
                || (is_array($snapshotGroup)
                    && is_string($snapshotGroup['type'] ?? null)
                    && ($snapshotGroup['type'] !== 'group' || $snapshotMemberIdsValid));

            $updated = $this->withGroupMemberVolumeLock(function () use ($job, $playersById): bool {
                $pending = $this->readPendingGroupMemberVolumeRefresh();
                if ($pending === null
                    || $pending['generation'] !== $job['generation']
                    || $pending['player_id'] !== $job['player_id']
                    || $pending['member_ids'] !== $job['member_ids']
                    || $this->GetStatus() !== IS_ACTIVE
                    || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')
                    || !$this->HasActiveParent()
                    || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                    return false;
                }

                if ($job['member_ids'] === null) {
                    $groupKey = $this->groupMemberPlayerKey($job['player_id']);
                    if (!isset($playersById[$groupKey])) {
                        throw new UnexpectedValueException($this->Translate('The configured player is missing from players/all.'));
                    }
                    $group = $playersById[$groupKey];
                    if (!is_string($group['type'] ?? null)) {
                        throw new UnexpectedValueException($this->Translate('The group snapshot does not contain a valid player type.'));
                    }
                    if ($group['type'] !== 'group') {
                        $changed = $this->removeGroupMemberVolumeVariablesUnlocked();
                        $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
                        $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
                        return $changed;
                    }
                    if (!array_key_exists('group_members', $group)) {
                        throw new UnexpectedValueException($this->Translate('The group snapshot does not contain a complete member list.'));
                    }
                    $memberIds = $this->validateGroupMemberIds($group['group_members'], $job['player_id']);
                    if ($memberIds === null) {
                        throw new UnexpectedValueException($this->Translate('The group snapshot contains invalid member IDs.'));
                    }
                } else {
                    $memberIds = $job['member_ids'];
                }

                $volumeUpdates = $this->readGroupMemberVolumeUpdates();
                $members = [];
                foreach ($memberIds as $memberId) {
                    $memberKey = $this->groupMemberPlayerKey($memberId);
                    if (!isset($playersById[$memberKey])) {
                        throw new UnexpectedValueException($this->Translate('A group member is missing from players/all.'));
                    }
                    $member = $playersById[$memberKey];
                    $name = is_string($member['name'] ?? null) ? trim($member['name']) : '';
                    $volume = $member['volume_level'] ?? null;
                    if (isset($volumeUpdates[$memberKey])
                        && $volumeUpdates[$memberKey]['generation'] > $job['volume_generation']) {
                        $volume = $volumeUpdates[$memberKey]['volume_level'];
                    }
                    if ($name === '' || ($volume !== null && (!is_int($volume) || $volume < 0 || $volume > 100))) {
                        throw new UnexpectedValueException($this->Translate('A group member contains incomplete player data.'));
                    }
                    $members[] = [
                        'player_id' => $memberId,
                        'name' => $name,
                        'volume_level' => $volume
                    ];
                }
                $existingMap = $this->readGroupMemberVolumeMap();
                $existingMemberIds = is_array($existingMap) ? array_column($existingMap, 'player_id') : [];
                $desiredMemberIds = array_column($members, 'player_id');
                $normalizedDesiredMemberIds = $desiredMemberIds;
                sort($normalizedDesiredMemberIds);
                $removedMemberIds = array_values(array_diff($existingMemberIds, $desiredMemberIds));
                if (count($removedMemberIds) > 0
                    && $job['confirmation_member_ids'] !== $normalizedDesiredMemberIds) {
                    $this->queueGroupMemberVolumeRefreshUnlocked(null, true, $normalizedDesiredMemberIds, 1);
                    return false;
                }
                $this->reconcileGroupMemberVolumeVariables($members);
                $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
                $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
                return true;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Aktualisierung fehlgeschlagen: ' . $e->getMessage(), 0);
            if (!$httpSnapshotLoaded
                && $job['confirmation_member_ids'] !== null
                && $job['confirmation_attempt'] < self::GROUP_MEMBER_REMOVAL_CONFIRMATION_ATTEMPTS) {
                try {
                    $this->withGroupMemberVolumeLock(function () use ($job): bool {
                        $pending = $this->readPendingGroupMemberVolumeRefresh();
                        if ($pending === null || $pending['generation'] !== $job['generation']) {
                            return false;
                        }
                        return $this->queueGroupMemberVolumeRefreshUnlocked(
                            null,
                            true,
                            $job['confirmation_member_ids'],
                            $job['confirmation_attempt'] + 1
                        );
                    });
                } catch (Throwable $retryError) {
                    $this->SendDebug(
                        'Group member volumes',
                        'Bestätigung konnte nicht erneut geplant werden: ' . $retryError->getMessage(),
                        0
                    );
                }
            }
        } finally {
            try {
                $restartDelay = $this->withGroupMemberVolumeLock(function () use ($job): int {
                    $pending = $this->readPendingGroupMemberVolumeRefresh();
                    if ($pending !== null && $pending['generation'] === $job['generation']) {
                        $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
                        $pending = null;
                    }
                    if ($this->GetBuffer('RunningGroupMemberVolumeRefresh') === (string)$job['generation']) {
                        $this->SetBuffer('RunningGroupMemberVolumeRefresh', '');
                    }
                    if ($pending === null) {
                        return 0;
                    }
                    return $pending['confirmation_member_ids'] === null
                        ? 100
                        : self::GROUP_MEMBER_REMOVAL_CONFIRMATION_DELAY;
                });
                if ($restartDelay > 0) {
                    $this->SetTimerInterval('RefreshGroupMemberVolumes', $restartDelay);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Group member volumes', 'Aktualisierung konnte nicht abgeschlossen werden: ' . $e->getMessage(), 0);
            }
        }

        if ($updated) {
            $this->updateVisualization();
        }
    }

    private function updateGroupMemberVolumesFormVisibility(): void
    {
        $this->UpdateFormField(
            'ShowGroupMemberVolumes',
            'visible',
            $this->isConfiguredPlayerKnownGroup()
        );
    }

    private function requestGroupMemberVolumeAction(string $ident, $value): bool
    {
        // Andere Bedienbefehle sollen nicht auf die Gruppenlautstärke-Sperre warten.
        if (!str_starts_with($ident, 'MemberVolume_') || !$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
            return false;
        }
        $playerId = $this->withGroupMemberVolumeLock(function () use ($ident, $value): ?string {
            if (!$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
                return null;
            }
            $map = $this->readGroupMemberVolumeMap();
            if ($map === null || !isset($map[$ident])) {
                return null;
            }
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId !== $map[$ident]['variable_id'] || !IPS_VariableExists($variableId)
                || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                return null;
            }
            if (!is_int($value) || $value < 0 || $value > 100) {
                throw new InvalidArgumentException($this->Translate('Group member volume must be between 0 and 100.'));
            }
            return $map[$ident]['player_id'];
        });
        if ($playerId === null) {
            return false;
        }

        $this->sendApiRequest('players/cmd/volume_set', [
            'player_id' => $playerId,
            'volume_level' => $value
        ]);
        return true;
    }

    private function processGroupMemberPlayerUpdate(string $objectId, array $data, bool $isResync): void
    {
        $groupPlayerId = trim($this->ReadPropertyString('PlayerID'));
        if ($objectId === $groupPlayerId) {
            if (($data['type'] ?? null) !== 'group') {
                if ($isResync && array_key_exists('type', $data)) {
                    $this->removeGroupMemberVolumeVariables();
                }
                return;
            }
            if (!array_key_exists('group_members', $data)) {
                return;
            }
            $memberIds = $this->validateGroupMemberIds($data['group_members'], $groupPlayerId);
            if ($memberIds === null) {
                return;
            }
            sort($memberIds);
            $schedule = $this->withGroupMemberVolumeLock(function () use ($memberIds, $isResync): bool {
                $map = $this->readGroupMemberVolumeMap();
                if ($map === null) {
                    return false;
                }
                $knownMemberIds = array_column($map, 'player_id');
                sort($knownMemberIds);
                $pending = $this->readPendingGroupMemberVolumeRefresh();
                if ($pending !== null && $pending['confirmation_member_ids'] === $memberIds) {
                    return false;
                }
                if (!$isResync && $knownMemberIds === $memberIds) {
                    if ($pending !== null && ($pending['confirmation_member_ids'] !== null
                        || ($pending['member_ids'] !== null && $pending['member_ids'] !== $memberIds))) {
                        $this->invalidatePendingGroupMemberVolumeRefreshUnlocked();
                    }
                    return false;
                }
                return $this->queueGroupMemberVolumeRefreshUnlocked($memberIds, $isResync);
            });
            if ($schedule) {
                $this->SetTimerInterval('RefreshGroupMemberVolumes', 100);
            }
            return;
        }

        $updated = $this->withGroupMemberVolumeLock(function () use ($objectId, $data): bool {
            $map = $this->readGroupMemberVolumeMap();
            if ($map === null) {
                return false;
            }
            $volume = $data['volume_level'] ?? null;
            if (!is_int($volume) || $volume < 0 || $volume > 100) {
                return false;
            }
            $pending = $this->readPendingGroupMemberVolumeRefresh();
            $isPendingMember = $pending !== null
                && ($pending['member_ids'] === null || in_array($objectId, $pending['member_ids'], true));
            foreach ($map as $ident => $entry) {
                if ($entry['player_id'] !== $objectId) {
                    continue;
                }
                $this->recordGroupMemberVolumeUpdateUnlocked($objectId, $volume);
                $variableId = @$this->GetIDForIdent($ident);
                if ($variableId !== $entry['variable_id'] || !IPS_VariableExists($variableId)
                    || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER
                ) {
                    return false;
                }
                $this->SetValue($ident, $volume);
                return true;
            }
            if ($isPendingMember) {
                $this->recordGroupMemberVolumeUpdateUnlocked($objectId, $volume);
            }
            return false;
        });
        if ($updated) {
            $this->updateVisualization();
        }
    }

    private function refreshGroupMemberVolumes(): void
    {
        $groupPlayerId = trim($this->ReadPropertyString('PlayerID'));
        if ($groupPlayerId === '' || $this->GetStatus() !== IS_ACTIVE || !$this->HasActiveParent()) {
            return;
        }
        $schedule = $this->withGroupMemberVolumeLock(function (): bool {
            if (!$this->ReadPropertyBoolean('ShowGroupMemberVolumes')) {
                return false;
            }
            return $this->queueGroupMemberVolumeRefreshUnlocked(null, true);
        });
        if ($schedule) {
            $this->SetTimerInterval('RefreshGroupMemberVolumes', 100);
        }
    }

    private function queueGroupMemberVolumeRefreshUnlocked(
        ?array $memberIds,
        bool $forceRefresh,
        ?array $confirmationMemberIds = null,
        int $confirmationAttempt = 0
    ): bool
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if ($playerId === '') {
            return false;
        }
        if ($memberIds !== null) {
            sort($memberIds);
        }
        if ($confirmationMemberIds !== null) {
            sort($confirmationMemberIds);
        }
        $pending = $this->readPendingGroupMemberVolumeRefresh();
        if ($pending !== null
            && $pending['player_id'] === $playerId
            && $pending['member_ids'] === $memberIds
            && $pending['confirmation_member_ids'] === $confirmationMemberIds
            && $pending['confirmation_attempt'] === $confirmationAttempt
            && ($pending['force_refresh'] || !$forceRefresh)) {
            return $this->GetBuffer('RunningGroupMemberVolumeRefresh') !== (string)$pending['generation'];
        }

        $generation = $this->nextGroupMemberVolumeRefreshGenerationUnlocked();
        $this->SetBuffer('PendingGroupMemberVolumeRefresh', json_encode([
            'generation'        => $generation,
            'player_id'         => $playerId,
            'member_ids'        => $memberIds,
            'force_refresh'     => $forceRefresh,
            'confirmation_member_ids' => $confirmationMemberIds,
            'confirmation_attempt' => $confirmationAttempt,
            'volume_generation' => (int)$this->GetBuffer('GroupMemberVolumeUpdateGeneration')
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return true;
    }

    private function readPendingGroupMemberVolumeRefresh(): ?array
    {
        try {
            $pending = json_decode(
                trim($this->GetBuffer('PendingGroupMemberVolumeRefresh')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Aktualisierungsauftrag enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($pending) || count($pending) === 0) {
            return null;
        }
        $memberIds = $pending['member_ids'] ?? null;
        $confirmationMemberIds = $pending['confirmation_member_ids'] ?? null;
        $confirmationAttempt = $pending['confirmation_attempt'] ?? 0;
        if (!is_int($pending['generation'] ?? null)
            || $pending['generation'] <= 0
            || !is_string($pending['player_id'] ?? null)
            || trim($pending['player_id']) === ''
            || ($memberIds !== null && $this->validateGroupMemberIds($memberIds, $pending['player_id']) === null)
            || ($confirmationMemberIds !== null
                && $this->validateGroupMemberIds($confirmationMemberIds, $pending['player_id']) === null)
            || ($confirmationMemberIds !== null && $memberIds !== null)
            || !is_int($confirmationAttempt)
            || ($confirmationMemberIds === null && $confirmationAttempt !== 0)
            || ($confirmationMemberIds !== null
                && ($confirmationAttempt < 1
                    || $confirmationAttempt > self::GROUP_MEMBER_REMOVAL_CONFIRMATION_ATTEMPTS))
            || !is_bool($pending['force_refresh'] ?? null)
            || !is_int($pending['volume_generation'] ?? null)
            || $pending['volume_generation'] < 0) {
            $this->SendDebug('Group member volumes', 'Aktualisierungsauftrag hat eine ungültige Struktur', 0);
            return null;
        }
        if ($memberIds !== null) {
            sort($memberIds);
        }
        if ($confirmationMemberIds !== null) {
            sort($confirmationMemberIds);
        }
        return [
            'generation'        => $pending['generation'],
            'player_id'         => trim($pending['player_id']),
            'member_ids'        => $memberIds,
            'force_refresh'     => $pending['force_refresh'],
            'confirmation_member_ids' => $confirmationMemberIds,
            'confirmation_attempt' => $confirmationAttempt,
            'volume_generation' => $pending['volume_generation']
        ];
    }

    private function nextGroupMemberVolumeRefreshGenerationUnlocked(): int
    {
        $generation = (int)$this->GetBuffer('GroupMemberVolumeRefreshGeneration') + 1;
        $this->SetBuffer('GroupMemberVolumeRefreshGeneration', (string)$generation);
        return $generation;
    }

    private function invalidatePendingGroupMemberVolumeRefreshUnlocked(): void
    {
        $this->nextGroupMemberVolumeRefreshGenerationUnlocked();
        $this->SetBuffer('PendingGroupMemberVolumeRefresh', '{}');
    }

    private function recordGroupMemberVolumeUpdateUnlocked(string $playerId, int $volume): void
    {
        $generation = (int)$this->GetBuffer('GroupMemberVolumeUpdateGeneration') + 1;
        $this->SetBuffer('GroupMemberVolumeUpdateGeneration', (string)$generation);
        $updates = $this->readGroupMemberVolumeUpdates();
        $updates[$this->groupMemberPlayerKey($playerId)] = [
            'player_id'     => $playerId,
            'generation'   => $generation,
            'volume_level' => $volume
        ];
        $this->SetBuffer('GroupMemberVolumeUpdates', json_encode($updates, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function readGroupMemberVolumeUpdates(): array
    {
        try {
            $updates = json_decode(
                trim($this->GetBuffer('GroupMemberVolumeUpdates')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Lautstärkeaktualisierungen enthalten ungültiges JSON', 0);
            return [];
        }
        if (!is_array($updates)) {
            return [];
        }
        foreach ($updates as $playerKey => $update) {
            if (!is_string($playerKey) || !is_array($update)
                || !is_string($update['player_id'] ?? null) || trim($update['player_id']) === ''
                || $playerKey !== $this->groupMemberPlayerKey(trim($update['player_id']))
                || !is_int($update['generation'] ?? null) || $update['generation'] <= 0
                || !is_int($update['volume_level'] ?? null)
                || $update['volume_level'] < 0 || $update['volume_level'] > 100) {
                unset($updates[$playerKey]);
            }
        }
        return $updates;
    }

    private function validateGroupMemberIds($value, string $groupPlayerId): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $memberIds = [];
        $memberKeys = [];
        foreach ($value as $memberId) {
            if (!is_string($memberId) || trim($memberId) === '') {
                return null;
            }
            $memberId = trim($memberId);
            $memberKey = $this->groupMemberPlayerKey($memberId);
            if ($memberId !== $groupPlayerId && !isset($memberKeys[$memberKey])) {
                $memberIds[] = $memberId;
                $memberKeys[$memberKey] = true;
            }
        }
        return $memberIds;
    }

    private function reconcileGroupMemberVolumeVariables(array $members): void
    {
        $existingMap = $this->readGroupMemberVolumeMap();
        if ($existingMap === null) {
            throw new UnexpectedValueException($this->Translate('The group volume mapping is invalid.'));
        }

        $desired = [];
        foreach ($members as $member) {
            $ident = $this->groupMemberVolumeIdent($member['player_id']);
            if (isset($desired[$ident])
                || (isset($existingMap[$ident]) && $existingMap[$ident]['player_id'] !== $member['player_id'])) {
                throw new UnexpectedValueException($this->Translate('Collision in the variable mapping for group members.'));
            }
            $objectId = @$this->GetIDForIdent($ident);
            if (isset($existingMap[$ident]) && $objectId > 0
                && $objectId !== $existingMap[$ident]['variable_id']) {
                throw new UnexpectedValueException($this->Translate('The ident of a group volume control is used by another object.'));
            }
            if (!isset($existingMap[$ident]) && $objectId > 0) {
                throw new UnexpectedValueException($this->Translate('The ident of a group volume control is already in use.'));
            }
            $desired[$ident] = [
                'player_id' => $member['player_id'],
                'name' => $member['name'],
                'volume_level' => $member['volume_level']
            ];
        }

        $newMap = [];
        $position = 200;
        foreach ($desired as $ident => $member) {
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId <= 0) {
                $this->MaintainVariable(
                    $ident,
                    $member['name'],
                    VARIABLETYPE_INTEGER,
                    '~Volume',
                    $position,
                    true
                );
                $variableId = @$this->GetIDForIdent($ident);
            }
            if ($variableId <= 0 || !IPS_VariableExists($variableId)
                || (IPS_GetVariable($variableId)['VariableType'] ?? -1) !== VARIABLETYPE_INTEGER) {
                throw new UnexpectedValueException($this->Translate('The group volume variable could not be created safely.'));
            }

            // Der Variablenname wird nur beim Anlegen gesetzt (Store-Reviewrichtlinie Punkt 11); die Kachel
            // verwendet weiterhin den aktuellen Namen aus Music Assistant aus der Zuordnung.
            $this->EnableAction($ident);
            if (is_int($member['volume_level'])) {
                $this->SetValue($ident, $member['volume_level']);
            }
            $newMap[$ident] = [
                'player_id' => $member['player_id'],
                'name' => $member['name'],
                'variable_id' => $variableId
            ];
            $position += 10;
        }

        foreach ($existingMap as $ident => $entry) {
            if (isset($newMap[$ident])) {
                continue;
            }
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === $entry['variable_id'] && IPS_VariableExists($variableId)
                && (IPS_GetVariable($variableId)['VariableType'] ?? -1) === VARIABLETYPE_INTEGER) {
                $this->UnregisterVariable($ident);
            }
            if (@$this->GetIDForIdent($ident) > 0) {
                $newMap[$ident] = $entry;
            }
        }
        $this->writeGroupMemberVolumeMap($newMap);
    }

    private function removeGroupMemberVolumeVariables(): void
    {
        $this->SetTimerInterval('RefreshGroupMemberVolumes', 0);
        $updated = $this->withGroupMemberVolumeLock(function (): bool {
            $this->invalidatePendingGroupMemberVolumeRefreshUnlocked();
            $this->SetBuffer('GroupMemberVolumeUpdates', '{}');
            return $this->removeGroupMemberVolumeVariablesUnlocked();
        });
        if ($updated) {
            $this->updateVisualization();
        }
    }

    private function removeGroupMemberVolumeVariablesUnlocked(): bool
    {
        $map = $this->readGroupMemberVolumeMap();
        if ($map === null) {
            return false;
        }
        $remaining = [];
        foreach ($map as $ident => $entry) {
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === $entry['variable_id'] && IPS_VariableExists($variableId)
                && (IPS_GetVariable($variableId)['VariableType'] ?? -1) === VARIABLETYPE_INTEGER) {
                $this->UnregisterVariable($ident);
            }
            if (@$this->GetIDForIdent($ident) > 0) {
                $remaining[$ident] = $entry;
            }
        }
        if ($remaining !== $map) {
            $this->writeGroupMemberVolumeMap($remaining);
            return true;
        }
        return false;
    }

    private function withGroupMemberVolumeLock(callable $operation)
    {
        $semaphore = 'MA_GROUP_MEMBER_VOLUMES_' . (string)$this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, self::GROUP_MEMBER_VOLUME_SEMAPHORE_TIMEOUT)) {
            throw new RuntimeException($this->Translate('The group volume semaphore could not be acquired.'));
        }
        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function groupMemberVolumeIdent(string $playerId): string
    {
        return 'MemberVolume_' . hash('sha256', $playerId);
    }

    private function groupMemberPlayerKey(string $playerId): string
    {
        return 'PlayerID:' . $playerId;
    }

    private function readGroupMemberVolumeMap(): ?array
    {
        $rawValue = trim($this->ReadAttributeString('GroupMemberVolumeMap'));
        try {
            $map = json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($map)) {
            $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung hat einen ungültigen Wurzeltyp', 0);
            return null;
        }

        $playerIds = [];
        foreach ($map as $ident => $entry) {
            if (!is_string($ident) || !is_array($entry)
                || !is_string($entry['player_id'] ?? null) || trim($entry['player_id']) === ''
                || !is_string($entry['name'] ?? null) || trim($entry['name']) === ''
                || !is_int($entry['variable_id'] ?? null) || $entry['variable_id'] <= 0) {
                $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung hat eine ungültige Struktur', 0);
                return null;
            }
            $playerId = trim($entry['player_id']);
            $playerKey = $this->groupMemberPlayerKey($playerId);
            if ($ident !== $this->groupMemberVolumeIdent($playerId) || isset($playerIds[$playerKey])) {
                $this->SendDebug('Group member volumes', 'Gespeicherte Zuordnung ist nicht eindeutig', 0);
                return null;
            }
            $map[$ident] = [
                'player_id' => $playerId,
                'name' => trim($entry['name']),
                'variable_id' => $entry['variable_id']
            ];
            $playerIds[$playerKey] = true;
        }
        return $map;
    }

    private function writeGroupMemberVolumeMap(array $map): void
    {
        if (!$this->WriteAttributeString(
            'GroupMemberVolumeMap',
            json_encode(count($map) === 0 ? new stdClass() : $map, JSON_THROW_ON_ERROR)
        )) {
            throw new RuntimeException($this->Translate('The group volume mapping could not be saved.'));
        }
    }
}
