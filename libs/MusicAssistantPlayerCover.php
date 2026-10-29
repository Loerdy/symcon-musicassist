<?php
declare(strict_types=1);

// Cover-Medium, Standardcover und Cover-Download des MusicAssistantPlayer; Konstanten liegen in der Klasse.
trait MusicAssistantPlayerCover
{
    private function runCoverDownload(): void
    {
        $this->SetTimerInterval('DownloadCover', 0);

        try {
            $job = $this->withCoverLock(function (): ?array {
                if ($this->GetBuffer('RunningCoverDownload') !== '') {
                    return null;
                }
                $pending = $this->readPendingCoverDownload();
                if ($pending === null
                    || $this->ReadAttributeString('CoverTargetProxyID') !== $pending['proxy_id']) {
                    return null;
                }
                $this->SetBuffer('RunningCoverDownload', (string)$pending['generation']);
                return $pending;
            });
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Cover-Download konnte nicht gestartet werden: ' . $e->getMessage(), 0);
            return;
        }
        if ($job === null) {
            return;
        }

        $updateVisualization = false;
        try {
            if ($this->GetStatus() !== IS_ACTIVE
                || !$this->HasActiveParent()
                || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']) {
                return;
            }

            $response = $this->requestArtwork($job['proxy_id']);
            $content = $response['content'] ?? null;
            $contentType = $response['content_type'] ?? null;
            if (($response['proxy_id'] ?? null) !== $job['proxy_id']
                || !in_array($contentType, ['image/jpeg', 'image/png'], true)
                || !is_string($content)) {
                throw new Exception($this->Translate('Artwork response is invalid.'));
            }
            $binary = base64_decode($content, true);
            $validSignature = is_string($binary) && $binary !== '' && ($contentType === 'image/jpeg'
                ? substr($binary, 0, 3) === "\xFF\xD8\xFF"
                : substr($binary, 0, 8) === "\x89PNG\r\n\x1A\n");
            if (!$validSignature) {
                throw new Exception($this->Translate('Artwork signature does not match the content type.'));
            }

            $updateVisualization = $this->withCoverLock(function () use ($job, $content, $contentType): bool {
                $pending = $this->readPendingCoverDownload();
                if ($pending === null
                    || $pending['generation'] !== $job['generation']
                    || $pending['player_id'] !== $job['player_id']
                    || $pending['proxy_id'] !== $job['proxy_id']
                    || $this->GetStatus() !== IS_ACTIVE
                    || !$this->HasActiveParent()
                    || trim($this->ReadPropertyString('PlayerID')) !== $job['player_id']
                    || $this->ReadAttributeString('CoverTargetProxyID') !== $job['proxy_id']) {
                    return false;
                }
                $mediaId = $this->ensureCoverMedia();
                if ($mediaId <= 0) {
                    throw new Exception($this->Translate('Cover media is not available.'));
                }
                $this->ensureCoverMediaFormat($mediaId, $contentType);
                if (!IPS_SetMediaContent($mediaId, $content)) {
                    throw new Exception($this->Translate('Cover media could not be updated.'));
                }
                $this->markCoverChanged();
                if (!$this->WriteAttributeString('CoverProxyID', $job['proxy_id'])) {
                    throw new Exception($this->Translate('Cover cache could not be saved.'));
                }
                if (!$this->WriteAttributeBoolean('CoverIsDefault', false)) {
                    throw new Exception($this->Translate('Cover status could not be saved.'));
                }
                $this->SetBuffer('PendingCoverDownload', '{}');
                return true;
            });
            if ($updateVisualization) {
                $this->SendDebug('Artwork', 'Cover aktualisiert', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Cover konnte nicht aktualisiert werden: ' . $e->getMessage(), 0);
        } finally {
            try {
                $restart = $this->withCoverLock(function () use ($job): bool {
                    $pending = $this->readPendingCoverDownload();
                    if ($pending !== null && $pending['generation'] === $job['generation']) {
                        $this->SetBuffer('PendingCoverDownload', '{}');
                        $pending = null;
                    }
                    if ($this->GetBuffer('RunningCoverDownload') === (string)$job['generation']) {
                        $this->SetBuffer('RunningCoverDownload', '');
                    }
                    return $pending !== null;
                });
                if ($restart) {
                    $this->SetTimerInterval('DownloadCover', 100);
                }
            } catch (Throwable $e) {
                $this->SendDebug('Artwork', 'Cover-Download konnte nicht abgeschlossen werden: ' . $e->getMessage(), 0);
            }
        }

        if ($updateVisualization) {
            $this->updateVisualization();
        }
    }

    private function updateCoverFromQueueItem($currentItem, $state): void
    {
        if ($currentItem === null) {
            $this->clearCover();
            return;
        }

        $proxyId = '';
        if (is_array($currentItem) && is_array($currentItem['image'] ?? null)
            && is_string($currentItem['image']['proxy_id'] ?? null)) {
            $candidate = trim($currentItem['image']['proxy_id']);
            if (preg_match('/^[a-f0-9]{64}$/D', $candidate) === 1) {
                $proxyId = $candidate;
            }
        }
        if ($proxyId === '') {
            $this->withCoverLock(function (): void {
                $this->invalidatePendingCoverDownloadUnlocked();
                if ($this->ReadAttributeString('CoverTargetProxyID') !== ''
                    && !$this->WriteAttributeString('CoverTargetProxyID', '')) {
                    throw new Exception($this->Translate('Cover target could not be reset.'));
                }
            });
        }
        $mediaId = $this->ensureCoverMedia();
        if ($mediaId <= 0) {
            throw new Exception($this->Translate('Cover media is not available.'));
        }
        if ($proxyId === '') {
            $this->showDefaultCover($mediaId, '');
            return;
        }

        $schedule = $this->withCoverLock(function () use ($mediaId, $proxyId, $state): bool {
            if ($this->ReadAttributeString('CoverTargetProxyID') !== $proxyId) {
                $this->invalidatePendingCoverDownloadUnlocked();
                if (!$this->WriteAttributeString('CoverTargetProxyID', $proxyId)) {
                    throw new Exception($this->Translate('Cover target could not be saved.'));
                }
                $this->setDefaultCover($mediaId);
            }
            if ($this->ReadAttributeString('CoverProxyID') === $proxyId
                && !$this->ReadAttributeBoolean('CoverIsDefault')
                && $this->coverMediaHasContent($mediaId)) {
                $this->invalidatePendingCoverDownloadUnlocked();
                return false;
            }
            if ($this->ReadAttributeBoolean('CoverIsDefault') || !$this->coverMediaHasContent($mediaId)) {
                $this->setDefaultCover($mediaId);
            }
            return $this->queueCoverDownloadUnlocked($proxyId, $state);
        });
        if ($schedule) {
            $this->SetTimerInterval('DownloadCover', 100);
        }
    }

    private function showDefaultCover(int $mediaId, string $targetProxyId): void
    {
        $this->withCoverLock(function () use ($mediaId, $targetProxyId): void {
            if ($this->ReadAttributeString('CoverTargetProxyID') !== $targetProxyId
                && !$this->WriteAttributeString('CoverTargetProxyID', $targetProxyId)) {
                throw new Exception($this->Translate('Cover target could not be saved.'));
            }
            $this->setDefaultCover($mediaId);
        });
    }

    private function setDefaultCover(int $mediaId): void
    {
        $content = $this->loadDefaultCoverContent();
        $this->ensureCoverMediaFormat($mediaId, 'image/png');
        $currentContent = $this->coverMediaHasContent($mediaId)
            ? IPS_GetMediaContent($mediaId)
            : '';
        if ($currentContent !== $content) {
            if (!IPS_SetMediaContent($mediaId, $content)) {
                throw new Exception($this->Translate('Default cover could not be set.'));
            }
            $this->markCoverChanged();
        }
        if (!$this->ReadAttributeBoolean('CoverIsDefault')
            && !$this->WriteAttributeBoolean('CoverIsDefault', true)) {
            throw new Exception($this->Translate('Cover status could not be saved.'));
        }
    }

    private function loadDefaultCoverContent(): string
    {
        $file = __DIR__ . '/../MusicAssistantPlayer/default-cover.png';
        if (!is_file($file) || !is_readable($file)) {
            throw new Exception($this->Translate('Default cover file is not available.'));
        }

        $binary = @file_get_contents($file);
        $imageInfo = is_string($binary) && $binary !== ''
            ? @getimagesizefromstring($binary)
            : false;
        if (!is_string($binary)
            || substr($binary, 0, 8) !== "\x89PNG\r\n\x1A\n"
            || !is_array($imageInfo)
            || ($imageInfo['mime'] ?? '') !== 'image/png') {
            throw new Exception($this->Translate('Default cover file is not a valid PNG file.'));
        }
        if (strlen($binary) > self::TILE_COVER_MAX_BYTES) {
            throw new Exception($this->Translate('Default cover file is too large for the HTML tile.'));
        }

        return base64_encode($binary);
    }

    private function ensureCoverMediaFormat(int $mediaId, string $contentType): void
    {
        $extension = $contentType === 'image/png' ? 'png' : 'jpg';
        $mediaFile = 'media/MusicAssistantCover_' . $this->InstanceID . '.' . $extension;
        $media = IPS_GetMedia($mediaId);
        $currentFile = str_replace('\\', '/', (string)($media['MediaFile'] ?? ''));
        $normalizedFile = ltrim($currentFile, '/');
        if ($normalizedFile === $mediaFile
            || substr($normalizedFile, -strlen('/' . $mediaFile)) === '/' . $mediaFile) {
            return;
        }
        if (!IPS_SetMediaFile($mediaId, $mediaFile, false)) {
            throw new Exception($this->Translate('Cover file format could not be updated.'));
        }
        // Die andere Datei kann bereits ein Bild enthalten (z. B. das Standardcover); die Kachel muss neu laden.
        $this->markCoverChanged();
    }

    private function queueCoverDownloadUnlocked(string $proxyId, $state): bool
    {
        $playerId = trim($this->ReadPropertyString('PlayerID'));
        if ($playerId === '') {
            return false;
        }
        $pending = $this->readPendingCoverDownload();
        if ($pending !== null
            && $pending['player_id'] === $playerId
            && $pending['proxy_id'] === $proxyId) {
            return $this->GetBuffer('RunningCoverDownload') !== (string)$pending['generation'];
        }

        $generation = $this->nextCoverDownloadGenerationUnlocked();
        $this->SetBuffer('PendingCoverDownload', json_encode([
            'generation' => $generation,
            'player_id'  => $playerId,
            'proxy_id'   => $proxyId,
            'state'      => $state
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return true;
    }

    private function readPendingCoverDownload(): ?array
    {
        try {
            $pending = json_decode(
                trim($this->GetBuffer('PendingCoverDownload')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Download-Auftrag enthält ungültiges JSON', 0);
            return null;
        }
        if (!is_array($pending) || count($pending) === 0) {
            return null;
        }
        if (!is_int($pending['generation'] ?? null)
            || $pending['generation'] <= 0
            || !is_string($pending['player_id'] ?? null)
            || trim($pending['player_id']) === ''
            || !is_string($pending['proxy_id'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $pending['proxy_id']) !== 1
            || !array_key_exists('state', $pending)) {
            $this->SendDebug('Artwork', 'Download-Auftrag hat eine ungültige Struktur', 0);
            return null;
        }
        return $pending;
    }

    private function nextCoverDownloadGenerationUnlocked(): int
    {
        $generation = (int)$this->GetBuffer('CoverDownloadGeneration') + 1;
        $this->SetBuffer('CoverDownloadGeneration', (string)$generation);
        return $generation;
    }

    private function invalidatePendingCoverDownloadUnlocked(): void
    {
        $this->nextCoverDownloadGenerationUnlocked();
        $this->SetBuffer('PendingCoverDownload', '{}');
    }

    private function requestArtwork(string $proxyId): array
    {
        $response = $this->SendDataToParent(json_encode([
            'DataID' => self::CONNECTION_REQUEST,
            'Command' => 'ArtworkRequest',
            'ProxyID' => $proxyId
        ], JSON_THROW_ON_ERROR));
        if (!is_string($response) || trim($response) === '') {
            throw new Exception($this->Translate('ArtworkRequest did not return a valid response.'));
        }
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            throw new Exception($this->Translate('ArtworkRequest failed.'));
        }
        return $result;
    }

    private function ensureCoverMedia(): int
    {
        $mediaId = @$this->GetIDForIdent('Cover');
        if ($mediaId > 0) {
            if (!IPS_MediaExists($mediaId)) {
                $this->SendDebug('Artwork', 'Ident Cover wird bereits von einem anderen Objekttyp verwendet', 0);
                return 0;
            }
            return $mediaId;
        }

        $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
        if ($mediaId <= 0) {
            $this->SendDebug('Artwork', 'Cover-Medium konnte nicht angelegt werden', 0);
            return 0;
        }
        $configured = IPS_SetParent($mediaId, $this->InstanceID);
        $configured = IPS_SetIdent($mediaId, 'Cover') && $configured;
        $configured = IPS_SetName($mediaId, 'Cover') && $configured;
        $configured = IPS_SetPosition($mediaId, 140) && $configured;
        $configured = IPS_SetMediaCached($mediaId, false) && $configured;
        $configured = IPS_SetMediaFile(
            $mediaId,
            'media/MusicAssistantCover_' . $this->InstanceID . '.jpg',
            false
        ) && $configured;
        // Die Sichtbarkeit wird nur beim Anlegen vorgegeben und danach nicht mehr verändert (Store-Reviewrichtlinie Punkt 11).
        $configured = IPS_SetHidden($mediaId, false) && $configured;
        if (!$configured) {
            $this->SendDebug('Artwork', 'Cover-Medium konnte nicht vollständig konfiguriert werden', 0);
            if (!IPS_DeleteMedia($mediaId, true)) {
                $this->SendDebug('Artwork', 'Unvollständiges Cover-Medium konnte nicht entfernt werden', 0);
            }
            return 0;
        }
        // Ein neu angelegtes (leeres) Medium ersetzt ein eventuell gelöschtes; die Kachel muss das erfahren.
        $this->markCoverChanged();
        return $mediaId;
    }

    // Abweichung nach Store-Reviewrichtlinie Punkt 7: Bis v2.1.2 hat das Modul das Cover-Medium selbst ein- und
    // ausgeblendet. Ein dabei ausgeblendetes Medium wird nach dem Update genau einmal wieder eingeblendet; danach
    // bestimmt allein der Benutzer über die Sichtbarkeit.
    private function migrateCoverVisibility(): void
    {
        if ($this->ReadAttributeBoolean('CoverVisibilityMigrated')) {
            return;
        }
        $mediaId = @$this->GetIDForIdent('Cover');
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId)) {
            return;
        }
        if ((IPS_GetObject($mediaId)['ObjectIsHidden'] ?? false) === true && !IPS_SetHidden($mediaId, false)) {
            $this->SendDebug('Artwork', 'Cover-Medium konnte nicht einmalig eingeblendet werden', 0);
            return;
        }
        $this->WriteAttributeBoolean('CoverVisibilityMigrated', true);
    }

    private function markCoverChanged(): void
    {
        $this->SetBuffer('CoverRevision', (string)((int)$this->GetBuffer('CoverRevision') + 1));
    }

    private function clearCover(): void
    {
        $mediaId = $this->ensureCoverMedia();
        if ($mediaId <= 0) {
            $this->withCoverLock(function (): void {
                $this->invalidatePendingCoverDownloadUnlocked();
            });
            return;
        }
        $this->withCoverLock(function () use ($mediaId): void {
            $this->invalidatePendingCoverDownloadUnlocked();
            $hadProxyId = $this->ReadAttributeString('CoverProxyID') !== '';
            if ($hadProxyId && !$this->WriteAttributeString('CoverProxyID', '')) {
                throw new Exception($this->Translate('Cover cache could not be reset.'));
            }
            if ($this->ReadAttributeString('CoverTargetProxyID') !== ''
                && !$this->WriteAttributeString('CoverTargetProxyID', '')) {
                throw new Exception($this->Translate('Cover status could not be reset.'));
            }
            // Ohne Wiedergabe wird nur der Inhalt geleert; die Sichtbarkeit bleibt beim Benutzer (Store-Reviewrichtlinie
            // Punkt 11). Die Mediaplayer-Kachel bleibt damit leer, die Player-Kachel zeigt ihren Platzhalter.
            $hadContent = $this->coverMediaHasContent($mediaId);
            if ($hadContent) {
                if (!IPS_SetMediaContent($mediaId, '')) {
                    throw new Exception($this->Translate('Cover media could not be cleared.'));
                }
                $this->markCoverChanged();
            }
            if ($this->ReadAttributeBoolean('CoverIsDefault')
                && !$this->WriteAttributeBoolean('CoverIsDefault', false)) {
                throw new Exception($this->Translate('Cover status could not be reset.'));
            }
            if ($hadContent || $hadProxyId) {
                $this->SendDebug('Artwork', 'Cover geleert', 0);
            }
        });
    }

    private function withCoverLock(callable $operation)
    {
        $semaphore = 'MA_COVER_' . (string)$this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, self::COVER_SEMAPHORE_TIMEOUT)) {
            throw new Exception($this->Translate('The cover semaphore could not be acquired.'));
        }
        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function coverMediaHasContent(int $mediaId): bool
    {
        $media = IPS_GetMedia($mediaId);
        return is_array($media)
            && ($media['MediaIsAvailable'] ?? false) === true
            && (int)($media['MediaSize'] ?? 0) > 0;
    }
}
