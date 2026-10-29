<?php
declare(strict_types=1);

// Favoritenstatus des aktuellen Titels im MusicAssistantPlayer; Konstanten liegen in der Klasse.
trait MusicAssistantPlayerFavorite
{
    private function runFavoriteResolution(): void
    {
        $this->SetTimerInterval('ResolveFavorite', 0);

        try {
            $job = $this->withFavoriteLock(function (): ?array {
                if ($this->GetBuffer('RunningFavoriteResolution') !== '') {
                    return null;
                }
                $pending = $this->readPendingFavoriteResolution();
                if ($pending === null) {
                    return null;
                }
                $this->SetBuffer('RunningFavoriteResolution', (string)$pending['generation']);
                return $pending;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Auflösungsauftrag konnte nicht gestartet werden: ' . $e->getMessage(), 0);
            return;
        }
        if ($job === null) {
            return;
        }

        $updateVisualization = false;
        try {
            if ($this->GetStatus() !== IS_ACTIVE
                || !$this->ReadPropertyBoolean('ShowFavorite')
                || !$this->HasActiveParent()
                || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                return;
            }

            $resolvedState = $this->resolveFavoriteLibraryState($job['state']);
            $updateVisualization = $this->withFavoriteLock(function () use ($job, $resolvedState): bool {
                $pending = $this->readPendingFavoriteResolution();
                $currentState = $this->readCurrentFavoriteTrack();
                if ($pending === null
                    || $pending['player_id'] !== $job['player_id']
                    || $pending['generation'] !== $job['generation']
                    || $this->GetStatus() !== IS_ACTIVE
                    || !$this->ReadPropertyBoolean('ShowFavorite')
                    || !$this->HasActiveParent()
                    || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']
                    || !$this->favoriteStatesIdentifySameTrack($currentState, $job['state'])) {
                    return false;
                }
                $this->SetBuffer('PendingFavoriteResolution', '{}');
                $this->writeCurrentFavoriteTrack($resolvedState);
                $this->updateFavoriteValue($resolvedState['favorite']);
                return true;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Library-Track konnte nicht aufgelöst werden: ' . $e->getMessage(), 0);
        } finally {
            try {
                $restart = $this->withFavoriteLock(function () use ($job): bool {
                    $pending = $this->readPendingFavoriteResolution();
                    if ($pending !== null && $pending['generation'] === $job['generation']) {
                        $this->SetBuffer('PendingFavoriteResolution', '{}');
                        $pending = null;
                    }
                    if ($this->GetBuffer('RunningFavoriteResolution') === (string)$job['generation']) {
                        $this->SetBuffer('RunningFavoriteResolution', '');
                    }
                    return $pending !== null;
                });
                if ($restart) {
                    $this->SetTimerInterval('ResolveFavorite', 100);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Favorite', 'Auflösungsauftrag konnte nicht abgeschlossen werden: ' . $e->getMessage(), 0);
            }
        }

        if ($updateVisualization) {
            $this->updateVisualization();
        }
    }

    private function updateFavoriteFromQueueItem($currentItem): void
    {
        $schedule = $this->withFavoriteLock(function () use ($currentItem): bool {
            if ($currentItem === null) {
                $this->clearCurrentFavoriteTrackUnlocked();
                return false;
            }
            if (!is_array($currentItem)
                || !is_string($currentItem['queue_item_id'] ?? null)
                || trim($currentItem['queue_item_id']) === '') {
                $this->clearCurrentFavoriteTrackUnlocked();
                return false;
            }

            $queueItemId = trim($currentItem['queue_item_id']);
            $currentState = $this->readCurrentFavoriteTrack();
            if ($currentState !== null && $currentState['queue_item_id'] === $queueItemId) {
                return !$currentState['resolved'] && $this->queueFavoriteResolutionUnlocked($currentState);
            }

            $mediaItem = $currentItem['media_item'] ?? null;
            if (!is_array($mediaItem)
                || ($mediaItem['media_type'] ?? null) !== 'track'
                || !is_string($mediaItem['item_id'] ?? null)
                || trim($mediaItem['item_id']) === ''
                || !is_string($mediaItem['provider'] ?? null)
                || trim($mediaItem['provider']) === ''
                || !is_string($mediaItem['uri'] ?? null)
                || trim($mediaItem['uri']) === '') {
                $this->clearCurrentFavoriteTrackUnlocked();
                return false;
            }

            $state = [
                'queue_item_id'   => $queueItemId,
                'source_uri'      => trim($mediaItem['uri']),
                'source_item_id'  => trim($mediaItem['item_id']),
                'source_provider' => trim($mediaItem['provider']),
                'library_item_id' => '',
                'library_uri'     => '',
                'favorite'        => false,
                'resolved'        => false
            ];
            if ($state['source_provider'] === 'library' && is_bool($mediaItem['favorite'] ?? null)) {
                $state['library_item_id'] = $state['source_item_id'];
                $state['library_uri'] = $state['source_uri'];
                $state['favorite'] = $mediaItem['favorite'];
                $state['resolved'] = true;
                $this->invalidatePendingFavoriteResolutionUnlocked();
            } else {
                $this->writeCurrentFavoriteTrack($state);
                $this->updateFavoriteValue(false);
                return $this->queueFavoriteResolutionUnlocked($state);
            }
            $this->writeCurrentFavoriteTrack($state);
            $this->updateFavoriteValue($state['favorite']);
            return false;
        });
        if ($schedule) {
            $this->SetTimerInterval('ResolveFavorite', 100);
        }
    }

    private function processFavoriteMediaItemUpdate(array $packet): void
    {
        if (!is_string($packet['ObjectID'] ?? null) || !is_array($packet['Data'] ?? null)) {
            return;
        }
        $schedule = $this->withFavoriteLock(function () use ($packet): bool {
            $state = $this->readCurrentFavoriteTrack();
            if ($state === null) {
                return false;
            }
            $objectId = trim($packet['ObjectID']);
            $data = $packet['Data'];
            $eventUri = is_string($data['uri'] ?? null) ? trim($data['uri']) : '';
            $eventItemId = is_string($data['item_id'] ?? null) ? trim($data['item_id']) : '';
            $eventProvider = is_string($data['provider'] ?? null) ? trim($data['provider']) : '';
            $matches = $objectId === $state['source_uri']
                || ($state['library_uri'] !== '' && $objectId === $state['library_uri'])
                || $eventUri === $state['source_uri']
                || ($state['library_uri'] !== '' && $eventUri === $state['library_uri'])
                || ($eventItemId === $state['source_item_id'] && $eventProvider === $state['source_provider'])
                || ($state['library_item_id'] !== '' && $eventItemId === $state['library_item_id'])
                || $this->favoriteEventHasSourceMapping($data, $state);
            if (!$matches || (($data['media_type'] ?? 'track') !== 'track')) {
                return false;
            }

            if (is_bool($data['favorite'] ?? null)
                && $eventItemId !== ''
                && ($eventProvider === 'library'
                    || $objectId === $state['library_uri']
                    || $eventUri === $state['library_uri'])) {
                $state['library_item_id'] = $eventItemId;
                if ($eventUri !== '') {
                    $state['library_uri'] = $eventUri;
                }
                $state['favorite'] = $data['favorite'];
                $state['resolved'] = true;
                $this->invalidatePendingFavoriteResolutionUnlocked();
            } else {
                return $this->queueFavoriteResolutionUnlocked($state);
            }
            $this->writeCurrentFavoriteTrack($state);
            $this->updateFavoriteValue($state['favorite']);
            return false;
        });
        if ($schedule) {
            $this->SetTimerInterval('ResolveFavorite', 100);
        }
    }

    private function favoriteEventHasSourceMapping(array $mediaItem, array $state): bool
    {
        if (!is_array($mediaItem['provider_mappings'] ?? null)) {
            return false;
        }
        foreach ($mediaItem['provider_mappings'] as $mapping) {
            if (is_array($mapping)
                && ($mapping['item_id'] ?? null) === $state['source_item_id']
                && (($mapping['provider_instance'] ?? null) === $state['source_provider']
                    || ($mapping['provider_domain'] ?? null) === $state['source_provider'])) {
                return true;
            }
        }
        return false;
    }

    private function setCurrentTrackFavorite(bool $favorite): void
    {
        if (!$this->ReadPropertyBoolean('ShowFavorite')) {
            throw new Exception($this->Translate('Favorite control is disabled.'));
        }
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        $snapshot = $this->withFavoriteLock(function () use ($favorite): array {
            $state = $this->readCurrentFavoriteTrack();
            if ($state === null || !$state['resolved'] || $state['source_uri'] === '') {
                throw new Exception($this->Translate('The current track could not be identified unambiguously.'));
            }
            if ($favorite === $state['favorite']) {
                $this->updateFavoriteValue($state['favorite']);
                return [];
            }
            return [
                'generation' => (int)$this->GetBuffer('FavoriteResolutionGeneration'),
                'state'      => $state
            ];
        });
        if ($snapshot === []) {
            return;
        }
        $state = $snapshot['state'];

        if ($favorite) {
            $this->sendApiRequest('music/favorites/add_item', ['item' => $state['source_uri']]);
        } else {
            if ($state['library_item_id'] === '') {
                throw new Exception($this->Translate('The library ID of the current track is not available.'));
            }
            $this->sendApiRequest('music/favorites/remove_item', [
                'media_type'     => 'track',
                'library_item_id' => $state['library_item_id']
            ]);
        }

        $updatedState = $this->resolveFavoriteLibraryState($state);
        if ($updatedState['favorite'] !== $favorite) {
            throw new Exception($this->Translate('The changed favorite status could not be confirmed.'));
        }
        $this->withFavoriteLock(function () use ($playerId, $snapshot, $state, $updatedState): void {
            if (trim($this->ReadPropertyString('PlayerID')) !== $playerId
                || (int)$this->GetBuffer('FavoriteResolutionGeneration') !== $snapshot['generation']
                || !$this->favoriteStatesIdentifySameTrack($this->readCurrentFavoriteTrack(), $state)) {
                throw new Exception($this->Translate('The current track changed while updating the favorite status.'));
            }
            $this->invalidatePendingFavoriteResolutionUnlocked();
            $this->writeCurrentFavoriteTrack($updatedState);
            $this->updateFavoriteValue($updatedState['favorite']);
        });
    }

    private function resolveFavoriteLibraryState(array $state): array
    {
        $libraryItem = $this->sendApiRequest('music/get_library_item', [
            'media_type'                    => 'track',
            'item_id'                       => $state['source_item_id'],
            'provider_instance_id_or_domain' => $state['source_provider']
        ]);
        if ($libraryItem === null) {
            $state['library_item_id'] = '';
            $state['library_uri'] = '';
            $state['favorite'] = false;
            $state['resolved'] = true;
            return $state;
        }
        if (!is_array($libraryItem)
            || ($libraryItem['media_type'] ?? null) !== 'track'
            || !is_string($libraryItem['item_id'] ?? null)
            || trim($libraryItem['item_id']) === ''
            || !is_bool($libraryItem['favorite'] ?? null)) {
            throw new Exception($this->Translate('Music Assistant did not return a complete library track.'));
        }
        $state['library_item_id'] = trim($libraryItem['item_id']);
        $state['library_uri'] = is_string($libraryItem['uri'] ?? null)
            ? trim($libraryItem['uri'])
            : '';
        $state['favorite'] = $libraryItem['favorite'];
        $state['resolved'] = true;
        return $state;
    }

    private function queueFavoriteResolutionUnlocked(array $state): bool
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if ($playerId === '') {
            return false;
        }
        $pending = $this->readPendingFavoriteResolution();
        if ($pending !== null
            && $pending['player_id'] === $playerId
            && $this->favoriteStatesIdentifySameTrack($pending['state'], $state)) {
            if ($this->GetBuffer('RunningFavoriteResolution') !== (string)$pending['generation']) {
                return true;
            }
        }

        $generation = $this->nextFavoriteResolutionGenerationUnlocked();
        $this->SetBuffer('PendingFavoriteResolution', json_encode([
            'generation' => $generation,
            'player_id'  => $playerId,
            'state'      => $state
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return true;
    }

    private function readPendingFavoriteResolution(): ?array
    {
        try {
            $pending = json_decode(
                trim($this->GetBuffer('PendingFavoriteResolution')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Auflösungsauftrag enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($pending) || count($pending) === 0) {
            return null;
        }
        if (!is_int($pending['generation'] ?? null)
            || $pending['generation'] <= 0
            || !is_string($pending['player_id'] ?? null)
            || trim($pending['player_id']) === ''
            || !is_array($pending['state'] ?? null)
            || !$this->favoriteStatesIdentifySameTrack($pending['state'], $pending['state'])) {
            $this->SendDebug('Favorite', 'Auflösungsauftrag hat eine ungültige Struktur', 0);
            return null;
        }
        return $pending;
    }

    private function favoriteStatesIdentifySameTrack(?array $left, array $right): bool
    {
        if ($left === null) {
            return false;
        }
        foreach (['queue_item_id', 'source_uri', 'source_item_id', 'source_provider'] as $key) {
            if (!is_string($left[$key] ?? null)
                || !is_string($right[$key] ?? null)
                || $left[$key] === ''
                || $left[$key] !== $right[$key]) {
                return false;
            }
        }
        return true;
    }

    private function nextFavoriteResolutionGenerationUnlocked(): int
    {
        $generation = (int)$this->GetBuffer('FavoriteResolutionGeneration') + 1;
        $this->SetBuffer('FavoriteResolutionGeneration', (string)$generation);
        return $generation;
    }

    private function invalidatePendingFavoriteResolutionUnlocked(): void
    {
        $this->nextFavoriteResolutionGenerationUnlocked();
        $this->SetBuffer('PendingFavoriteResolution', '{}');
    }

    private function readCurrentFavoriteTrack(): ?array
    {
        try {
            $state = json_decode(trim($this->GetBuffer('CurrentFavoriteTrack')), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->SendDebug('Favorite', 'Aktuelle Trackkennung enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($state) || count($state) === 0) {
            return null;
        }
        foreach (['queue_item_id', 'source_uri', 'source_item_id', 'source_provider', 'library_item_id', 'library_uri'] as $key) {
            if (!is_string($state[$key] ?? null)) {
                $this->SendDebug('Favorite', 'Aktuelle Trackkennung hat eine ungültige Struktur', 0);
                return null;
            }
        }
        if ($state['queue_item_id'] === ''
            || $state['source_uri'] === ''
            || $state['source_item_id'] === ''
            || $state['source_provider'] === ''
            || !is_bool($state['favorite'] ?? null)
            || !is_bool($state['resolved'] ?? null)) {
            $this->SendDebug('Favorite', 'Aktuelle Trackkennung ist unvollständig', 0);
            return null;
        }
        return $state;
    }

    private function writeCurrentFavoriteTrack(array $state): void
    {
        $this->SetBuffer('CurrentFavoriteTrack', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function clearCurrentFavoriteTrack(): void
    {
        $this->withFavoriteLock(function (): void {
            $this->clearCurrentFavoriteTrackUnlocked();
        });
    }

    private function clearCurrentFavoriteTrackUnlocked(): void
    {
        $this->invalidatePendingFavoriteResolutionUnlocked();
        $this->SetBuffer('CurrentFavoriteTrack', '{}');
        $this->updateFavoriteValue(false);
    }

    private function updateFavoriteValue(bool $favorite): void
    {
        $variableId = @$this->GetIDForIdent('Favorite');
        if ($variableId > 0
            && IPS_VariableExists($variableId)
            && (bool)$this->GetValue('Favorite') !== $favorite) {
            $this->SetValue('Favorite', $favorite);
        }
    }

    private function withFavoriteLock(callable $operation)
    {
        $semaphore = 'MA_FAVORITE_' . (string)$this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, self::FAVORITE_SEMAPHORE_TIMEOUT)) {
            throw new RuntimeException($this->Translate('The favorite semaphore could not be acquired.'));
        }
        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }
}
