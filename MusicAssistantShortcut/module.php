<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApiClient.php';
require_once __DIR__ . '/../libs/MusicAssistantArtworkClient.php';
require_once __DIR__ . '/../libs/MusicAssistantSelectionOptions.php';

class MusicAssistantShortcut extends IPSModule
{
    use MusicAssistantApiClient;
    use MusicAssistantArtworkClient;
    use MusicAssistantSelectionOptions;

    private const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';
    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';
    // Typ des Eintrags => Ident der Auswahlvariable und Profileigenschaft im Player sowie Bibliotheksabfrage
    private const MEDIA_TYPES = [
        'radio'    => ['ident' => 'Radio', 'profileProperty' => 'RadioProfile', 'library' => 'music/radios/library_items'],
        'playlist' => ['ident' => 'Playlist', 'profileProperty' => 'PlaylistProfile', 'library' => 'music/playlists/library_items']
    ];
    // Bevorzugte Bildtypen aus metadata.images; danach wird jedes andere Bild mit gültiger proxy_id genommen.
    private const PREFERRED_IMAGE_TYPES = ['thumb', 'logo'];
    // Bilder werden als Data-URL an die Kachel übertragen; die Grenze entspricht der des Player-Covers.
    private const IMAGE_MAX_BYTES = 524288;
    private const IMAGE_MESSAGES = [MM_UPDATE, MM_CHANGEFILE, MM_DELETE];
    private const STATUS_INVALID_PLAYER = 201;
    private const STATUS_FOREIGN_PLAYER = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('PlayerInstanceID', 0);
        $this->RegisterPropertyString('Entries', '[]');

        $this->RegisterTimer('LoadImages', 0, 'IPS_RequestAction($_IPS["TARGET"], "LoadImages", false);');
        $this->SetBuffer('ParentInstanceId', '0');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // Nur neu geladene Playlist-/Radio-Profile betreffen die Beschriftungen der Kachel.
        $this->SetReceiveDataFilter('.*"Event":"profiles_updated".*');
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('LoadImages', 0);
        $this->registerParentStatusMessage();

        $entries = $this->readEntries();
        $this->registerCustomImageMessages($entries ?? []);
        // Bei unlesbarer Eintragsliste bleiben vorhandene Bilder erhalten.
        if ($entries !== null) {
            $this->removeUnusedImageMedia($entries);
        }

        $status = $this->configurationStatus();
        $this->SetStatus($status);
        if ($status === IS_ACTIVE && IPS_GetKernelRunlevel() === KR_READY) {
            $this->scheduleMissingImages();
        }
        $this->updateVisualization();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === IM_CHANGESTATUS && $SenderID === (int)$this->GetBuffer('ParentInstanceId')) {
            // Nach einer Wiederverbindung fehlende Bilder nachladen, statt periodisch zu wiederholen.
            if ($this->HasActiveParent() && $this->configurationStatus() === IS_ACTIVE) {
                $this->scheduleMissingImages();
            }
            return;
        }
        if (in_array($Message, self::IMAGE_MESSAGES, true)) {
            if ($Message === MM_DELETE && $this->configurationStatus() === IS_ACTIVE) {
                $this->scheduleMissingImages();
            }
            $this->updateVisualization();
        }
    }

    public function ReceiveData($JSONString): string
    {
        $packet = json_decode($JSONString, true);
        if (is_array($packet)
            && ($packet['DataID'] ?? null) === self::CONNECTION_EVENT
            && ($packet['Event'] ?? null) === 'profiles_updated') {
            $this->updateVisualization();
        }
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type'         => 'SelectInstance',
                    'name'         => 'PlayerInstanceID',
                    'caption'      => 'Player',
                    'validModules' => [self::PLAYER_MODULE_ID],
                    'onChange'     => 'IPS_RequestAction($id, "LoadPlayerOptions", $PlayerInstanceID);'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'The entries are taken from the playlist and radio profiles of the selected player.'
                ],
                [
                    'type'        => 'List',
                    'name'        => 'Entries',
                    'caption'     => 'Entries',
                    'rowCount'    => 8,
                    'add'         => true,
                    'delete'      => true,
                    'changeOrder' => true,
                    'columns'     => $this->buildEntryColumns($this->ReadPropertyInteger('PlayerInstanceID'))
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Without an own image, the image from the Music Assistant library is used. Own images must be JPEG or PNG media objects of at most 512 KB.'
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Without an image, the label is always shown.'
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Reload images from Music Assistant',
                    'onClick' => 'IPS_RequestAction($id, "RefreshImages", true);'
                ]
            ],
            'status' => [
                ['code' => self::STATUS_INVALID_PLAYER, 'icon' => 'error', 'caption' => 'The selected player does not exist or is not a Music Assistant player.'],
                ['code' => self::STATUS_FOREIGN_PLAYER, 'icon' => 'error', 'caption' => 'The selected player is not connected to the same Music Assistant Connection.']
            ]
        ];

        return json_encode($form);
    }

    public function GetVisualizationTile(): string
    {
        $template = @file_get_contents(__DIR__ . '/module.html');
        if (!is_string($template)) {
            $this->SendDebug('Visualization', 'HTML-Vorlage konnte nicht geladen werden', 0);
            return '';
        }

        try {
            $initialState = json_encode(
                $this->buildVisualizationState(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Initialzustand konnte nicht erstellt werden: ' . $e->getMessage(), 0);
            $initialState = '{"entries":[]}';
        }

        return str_replace('__MUSIC_ASSISTANT_INITIAL_STATE__', $initialState, $template);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Play':
                $this->playEntry((int)$Value);
                break;

            case 'LoadPlayerOptions':
                $this->UpdateFormField('Entries', 'columns', json_encode($this->buildEntryColumns((int)$Value)));
                break;

            case 'LoadImages':
                $this->loadImages(false);
                break;

            case 'RefreshImages':
                $this->loadImages(true);
                break;

            default:
                throw new Exception(sprintf($this->Translate('Unknown action: %s'), $Ident));
        }
    }

    private function configurationStatus(): int
    {
        $playerId = $this->ReadPropertyInteger('PlayerInstanceID');
        if ($playerId <= 0) {
            return IS_INACTIVE;
        }
        if (!$this->isMusicAssistantPlayer($playerId)) {
            return self::STATUS_INVALID_PLAYER;
        }
        $connectionId = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($connectionId <= 0 || (int)(IPS_GetInstance($playerId)['ConnectionID'] ?? 0) !== $connectionId) {
            return self::STATUS_FOREIGN_PLAYER;
        }
        return IS_ACTIVE;
    }

    private function isMusicAssistantPlayer(int $instanceId): bool
    {
        return $instanceId > 0
            && IPS_InstanceExists($instanceId)
            && (IPS_GetInstance($instanceId)['ModuleInfo']['ModuleID'] ?? null) === self::PLAYER_MODULE_ID;
    }

    private function registerParentStatusMessage(): void
    {
        $previousParentId = (int)$this->GetBuffer('ParentInstanceId');
        $parentId = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($previousParentId > 0 && $previousParentId !== $parentId) {
            $this->UnregisterMessage($previousParentId, IM_CHANGESTATUS);
        }
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, IM_CHANGESTATUS);
        }
        $this->SetBuffer('ParentInstanceId', (string)$parentId);
    }

    private function playEntry(int $index): void
    {
        $entries = $this->readEntries() ?? [];
        if (!isset($entries[$index])) {
            $this->SendDebug('Play', 'Unbekannter Eintrag: ' . $index, 0);
            return;
        }
        if ($this->configurationStatus() !== IS_ACTIVE) {
            $this->SendDebug('Play', 'Konfiguration ist nicht vollständig gültig', 0);
            return;
        }
        $entry = $entries[$index];
        $options = $this->readPlayerOptions($this->ReadPropertyInteger('PlayerInstanceID'));
        if (!isset($options[$entry['type']][$entry['itemId']])) {
            $message = sprintf(
                $this->Translate('The entry %s is no longer offered by the player.'),
                $entry['type'] . ':' . $entry['itemId']
            );
            $this->SendDebug('Play', $message, 0);
            $this->LogMessage($message, KL_WARNING);
            return;
        }

        // Der Player löst Playlist bzw. Sender selbst auf und protokolliert Fehler beim Start.
        IPS_RequestAction(
            $this->ReadPropertyInteger('PlayerInstanceID'),
            self::MEDIA_TYPES[$entry['type']]['ident'],
            $entry['itemId']
        );
        $this->SendDebug('Play', 'Gestartet: ' . $entry['type'] . ':' . $entry['itemId'], 0);
    }

    /**
     * @return array<int, array{type: string, itemId: int, label: string, showLabel: bool, imageId: int}>|null null bei unlesbarer Liste
     */
    private function readEntries(): ?array
    {
        $rows = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($rows)) {
            $this->SendDebug('Entries', 'Eintragsliste ist kein gültiges JSON', 0);
            return null;
        }

        $entries = [];
        foreach (array_values($rows) as $row) {
            $item = is_array($row) && is_string($row['item'] ?? null) ? $row['item'] : '';
            if (preg_match('/^(radio|playlist):([1-9][0-9]*)$/D', $item, $match) !== 1) {
                continue;
            }
            $entries[] = [
                'type'      => $match[1],
                'itemId'    => (int)$match[2],
                'label'     => is_string($row['label'] ?? null) ? trim($row['label']) : '',
                // Einträge aus Schritt 2 haben das Feld noch nicht und zeigen weiterhin die Beschriftung.
                'showLabel' => ($row['showLabel'] ?? true) !== false,
                'imageId'   => is_int($row['image'] ?? null) ? $row['image'] : 0
            ];
        }
        return $entries;
    }

    /**
     * @return array<string, array<int, string>> Typ => (Eintrags-ID => Name)
     */
    private function readPlayerOptions(int $playerId): array
    {
        $result = ['radio' => [], 'playlist' => []];
        if (!$this->isMusicAssistantPlayer($playerId)) {
            return $result;
        }
        foreach (self::MEDIA_TYPES as $type => $definition) {
            $variableId = @IPS_GetObjectIDByIdent($definition['ident'], $playerId);
            $options = $this->readSelectionOptionsOfVariable(
                is_int($variableId) ? $variableId : 0,
                (string)@IPS_GetProperty($playerId, $definition['profileProperty'])
            ) ?? [];
            foreach ($options as $option) {
                $result[$type][$option['value']] = $option['label'];
            }
        }
        return $result;
    }

    private function buildEntryColumns(int $playerId): array
    {
        $selectOptions = [['caption' => '-', 'value' => '']];
        $captions = ['radio' => $this->Translate('Radio'), 'playlist' => $this->Translate('Playlist')];
        foreach ($this->readPlayerOptions($playerId) as $type => $options) {
            foreach ($options as $itemId => $label) {
                $selectOptions[] = ['caption' => $captions[$type] . ': ' . $label, 'value' => $type . ':' . $itemId];
            }
        }

        return [
            [
                'caption' => 'Entry',
                'name'    => 'item',
                'width'   => '320px',
                'add'     => '',
                'edit'    => ['type' => 'Select', 'options' => $selectOptions]
            ],
            [
                'caption' => 'Label (optional)',
                'name'    => 'label',
                'width'   => 'auto',
                'add'     => '',
                'edit'    => ['type' => 'ValidationTextBox']
            ],
            [
                'caption' => 'Show label',
                'name'    => 'showLabel',
                'width'   => '150px',
                'add'     => true,
                'edit'    => ['type' => 'CheckBox']
            ],
            [
                'caption' => 'Own image (optional)',
                'name'    => 'image',
                'width'   => '220px',
                'add'     => 0,
                'edit'    => ['type' => 'SelectMedia']
            ]
        ];
    }

    // --------- Bilder ---------

    private function imageIdent(array $entry): string
    {
        return 'Image_' . substr(hash('sha256', $entry['type'] . ':' . $entry['itemId']), 0, 16);
    }

    private function registerCustomImageMessages(array $entries): void
    {
        $wanted = [];
        foreach ($entries as $entry) {
            if ($entry['imageId'] > 0 && IPS_MediaExists($entry['imageId'])) {
                $wanted[$entry['imageId']] = true;
            }
        }
        foreach ($this->GetMessageList() as $senderId => $messages) {
            if (isset($wanted[$senderId])) {
                continue;
            }
            foreach (array_intersect(self::IMAGE_MESSAGES, $messages) as $message) {
                $this->UnregisterMessage($senderId, $message);
            }
        }
        foreach (array_keys($wanted) as $mediaId) {
            foreach (self::IMAGE_MESSAGES as $message) {
                $this->RegisterMessage($mediaId, $message);
            }
        }
    }

    // Entfernt nur selbst angelegte Bilder (Ident Image_*), deren Eintrag nicht mehr existiert.
    private function removeUnusedImageMedia(array $entries): void
    {
        $wanted = [];
        foreach ($entries as $entry) {
            $wanted[$this->imageIdent($entry)] = true;
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $ident = (string)($object['ObjectIdent'] ?? '');
            if (($object['ObjectType'] ?? -1) !== OBJECTTYPE_MEDIA
                || !str_starts_with($ident, 'Image_')
                || isset($wanted[$ident])) {
                continue;
            }
            if (!IPS_DeleteMedia($childId, true)) {
                $this->SendDebug('Images', 'Nicht mehr benötigtes Bild konnte nicht entfernt werden: ' . $ident, 0);
            }
        }
    }

    // Plant das Nachladen nur, wenn einem Eintrag ohne eigenes Bild noch ein Bild aus Music Assistant fehlt.
    private function scheduleMissingImages(): void
    {
        foreach ($this->readEntries() ?? [] as $entry) {
            if ($this->readCustomImage($entry['imageId']) === '' && $this->readOwnImage($this->imageIdent($entry)) === '') {
                $this->SetTimerInterval('LoadImages', 100);
                return;
            }
        }
    }

    private function loadImages(bool $force): void
    {
        $this->SetTimerInterval('LoadImages', 0);
        if ($this->configurationStatus() !== IS_ACTIVE) {
            return;
        }

        $pending = [];
        foreach ($this->readEntries() ?? [] as $entry) {
            $ident = $this->imageIdent($entry);
            if ($this->readCustomImage($entry['imageId']) !== ''
                || (!$force && $this->readOwnImage($ident) !== '')) {
                continue;
            }
            $pending[$ident] = $entry;
        }

        $libraries = [];
        $changed = false;
        foreach ($pending as $ident => $entry) {
            if (!array_key_exists($entry['type'], $libraries)) {
                try {
                    $libraries[$entry['type']] = $this->readLibraryImageIds($entry['type']);
                } catch (Throwable $e) {
                    $this->SendDebug('Images', 'Bibliothek konnte nicht gelesen werden: ' . $e->getMessage(), 0);
                    $libraries[$entry['type']] = [];
                }
            }
            $name = $entry['type'] . ':' . $entry['itemId'];
            $proxyId = $libraries[$entry['type']][$entry['itemId']] ?? null;
            if ($proxyId === null) {
                $this->SendDebug('Images', 'Kein Bild in Music Assistant für ' . $name, 0);
                continue;
            }
            try {
                $image = $this->validateArtworkResponse($this->requestArtwork($proxyId), $proxyId);
                if (strlen((string)base64_decode($image['content'], true)) > self::IMAGE_MAX_BYTES) {
                    throw new Exception('Bild ist größer als ' . self::IMAGE_MAX_BYTES . ' Bytes');
                }
                $this->storeOwnImage($ident, $image['content'], $image['contentType']);
                $changed = true;
                $this->SendDebug('Images', 'Bild geladen für ' . $name, 0);
            } catch (Throwable $e) {
                // Ein bereits vorhandenes Bild bleibt bei Fehlern erhalten.
                $this->SendDebug('Images', 'Bild für ' . $name . ' konnte nicht geladen werden: ' . $e->getMessage(), 0);
            }
        }
        if ($changed) {
            $this->updateVisualization();
        }
    }

    /**
     * @return array<int, string> Eintrags-ID => proxy_id
     */
    private function readLibraryImageIds(string $type): array
    {
        $result = $this->sendApiRequest(self::MEDIA_TYPES[$type]['library'], ['summary' => false]);
        if (!is_array($result) || !$this->isList($result)) {
            throw new Exception('Antwort ist keine Liste');
        }

        $imageIds = [];
        $unexpectedFormats = 0;
        foreach ($result as $item) {
            $rawItemId = is_array($item) ? ($item['item_id'] ?? null) : null;
            if ((!is_string($rawItemId) && !is_int($rawItemId))
                || preg_match('/^[1-9][0-9]*$/D', (string)$rawItemId) !== 1) {
                continue;
            }
            $images = $item['metadata']['images'] ?? null;
            $proxyId = $this->selectImageProxyId(is_array($images) ? $images : [], $unexpectedFormats);
            if ($proxyId !== null) {
                $imageIds[(int)$rawItemId] = $proxyId;
            }
        }
        if ($unexpectedFormats > 0) {
            $this->SendDebug('Images', $unexpectedFormats . ' Bild-ID(s) mit unerwartetem Format übersprungen', 0);
        }
        return $imageIds;
    }

    private function selectImageProxyId(array $images, int &$unexpectedFormats): ?string
    {
        $candidates = [];
        foreach ($images as $image) {
            if (!is_array($image) || !is_string($image['proxy_id'] ?? null)) {
                continue;
            }
            // Die Connection akzeptiert nur dieses Format (Schutz vor Pfadmanipulation in der Imageproxy-URL).
            if (preg_match('/^[a-f0-9]{64}$/D', $image['proxy_id']) !== 1) {
                $unexpectedFormats++;
                continue;
            }
            $priority = array_search($image['type'] ?? null, self::PREFERRED_IMAGE_TYPES, true);
            $candidates[] = [$priority === false ? count(self::PREFERRED_IMAGE_TYPES) : $priority, $image['proxy_id']];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        return $candidates[0][1];
    }

    private function storeOwnImage(string $ident, string $content, string $contentType): void
    {
        $mediaId = @$this->GetIDForIdent($ident);
        if ($mediaId > 0 && !IPS_MediaExists($mediaId)) {
            throw new Exception('Ident ' . $ident . ' wird von einem anderen Objekt verwendet');
        }
        $mediaFile = 'media/MusicAssistantShortcut_' . $this->InstanceID . '_' . substr($ident, 6)
            . ($contentType === 'image/png' ? '.png' : '.jpg');
        if ($mediaId <= 0) {
            $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
            $configured = $mediaId > 0
                && IPS_SetParent($mediaId, $this->InstanceID)
                && IPS_SetIdent($mediaId, $ident)
                && IPS_SetName($mediaId, $ident)
                && IPS_SetHidden($mediaId, true)
                && IPS_SetMediaCached($mediaId, false)
                && IPS_SetMediaFile($mediaId, $mediaFile, false);
            if (!$configured) {
                if ($mediaId > 0 && !IPS_DeleteMedia($mediaId, true)) {
                    $this->SendDebug('Images', 'Unvollständiges Bild-Medium konnte nicht entfernt werden', 0);
                }
                throw new Exception('Bild-Medium konnte nicht angelegt werden');
            }
        } elseif (ltrim(str_replace('\\', '/', (string)(IPS_GetMedia($mediaId)['MediaFile'] ?? '')), '/') !== $mediaFile
            && !IPS_SetMediaFile($mediaId, $mediaFile, false)) {
            throw new Exception('Dateiformat des Bild-Mediums konnte nicht angepasst werden');
        }
        if (!IPS_SetMediaContent($mediaId, $content)) {
            throw new Exception('Bild konnte nicht gespeichert werden');
        }
    }

    private function readOwnImage(string $ident): string
    {
        $mediaId = @$this->GetIDForIdent($ident);
        return $mediaId > 0 ? $this->readImageDataUrl($mediaId) : '';
    }

    private function readCustomImage(int $mediaId): string
    {
        if ($mediaId <= 0) {
            return '';
        }
        $dataUrl = $this->readImageDataUrl($mediaId);
        if ($dataUrl === '') {
            $this->SendDebug('Images', 'Eigenes Bild ' . $mediaId . ' fehlt oder ist kein JPEG/PNG bis 512 KB', 0);
        }
        return $dataUrl;
    }

    private function readImageDataUrl(int $mediaId): string
    {
        if (!IPS_MediaExists($mediaId)) {
            return '';
        }
        $media = IPS_GetMedia($mediaId);
        if (($media['MediaType'] ?? -1) !== MEDIATYPE_IMAGE || (int)($media['MediaSize'] ?? 0) > self::IMAGE_MAX_BYTES) {
            return '';
        }
        $content = IPS_GetMediaContent($mediaId);
        $signature = $content !== '' ? base64_decode(substr($content, 0, 16), true) : false;
        if (!is_string($signature)) {
            return '';
        }
        if (substr($signature, 0, 3) === "\xFF\xD8\xFF") {
            return 'data:image/jpeg;base64,' . $content;
        }
        if (substr($signature, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return 'data:image/png;base64,' . $content;
        }
        return '';
    }

    // --------- Kachel ---------

    private function buildVisualizationState(): array
    {
        $options = $this->readPlayerOptions($this->ReadPropertyInteger('PlayerInstanceID'));
        $tiles = [];
        foreach ($this->readEntries() ?? [] as $index => $entry) {
            $name = $options[$entry['type']][$entry['itemId']] ?? null;
            $image = $this->readCustomImage($entry['imageId']);
            if ($image === '') {
                $image = $this->readOwnImage($this->imageIdent($entry));
            }
            $tiles[] = [
                'index'     => $index,
                'type'      => $entry['type'],
                'label'     => $entry['label'] !== '' ? $entry['label'] : (string)$name,
                'available' => $name !== null,
                'showLabel' => $entry['showLabel'],
                'image'     => $image
            ];
        }
        return ['entries' => $tiles];
    }

    private function updateVisualization(): void
    {
        try {
            $this->UpdateVisualizationValue(json_encode($this->buildVisualizationState(), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Kachelzustand konnte nicht übertragen werden: ' . $e->getMessage(), 0);
        }
    }
}
