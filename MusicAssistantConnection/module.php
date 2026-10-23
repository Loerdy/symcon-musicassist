<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/MusicAssistantApi.php';

class MusicAssistantConnection extends IPSModule
{
    use MusicAssistantApi;

    private const WEBSOCKET_MODULE = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const WEBSOCKET_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const WEBSOCKET_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';
    private const CONNECTION_REQUEST = '{246666E8-C78A-0E3D-5857-9AB5F5873E2E}';
    private const CONNECTION_EVENT = '{3D0660F0-556B-7070-DA8E-CD7C6D19595C}';
    private const PLAYER_MODULE_ID = '{70DC85BD-4828-B5F2-12E4-AC8B6A173B36}';
    private const MAX_ARTWORK_SIZE = 5242880;
    private const STATE_SEMAPHORE_TIMEOUT = 5000;
    private const STATE_SEMAPHORE_ERROR = 1701;
    private const AUTH_MAX_ATTEMPTS = 3;
    private const AUTH_RETRY_DELAY_SECONDS = 5;
    private const API_COMMANDS = [
        'players/cmd/previous',
        'players/cmd/stop',
        'players/cmd/play',
        'players/cmd/pause',
        'players/cmd/next',
        'players/cmd/volume_set',
        'players/cmd/group_volume_up',
        'players/cmd/group_volume_down',
        'players/cmd/volume_mute',
        'players/all',
        'player_queues/get_active_queue',
        'player_queues/shuffle',
        'player_queues/repeat',
        'player_queues/play_media',
        'music/playlists/library_items',
        'music/playlists/playlist_tracks',
        'music/radios/library_items',
        'music/get_library_item',
        'music/favorites/add_item',
        'music/favorites/remove_item'
    ];

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '127.0.0.1');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');
        $this->SetBuffer('MsgId', '0');
        $this->SetBuffer('GreetingUrl', '');
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->SetBuffer('AuthAttempts', '0');
        $this->SetBuffer('AuthRetryAt', '0');
        $this->SetBuffer('AuthRetryGeneration', '');
        $this->SetBuffer('ConnectionGeneration', '0');
        $this->SetBuffer('ParentInstanceId', '0');
        $this->SetBuffer('ParentReconnectPending', '0');
        $this->SetBuffer('ParentReconnectAttempted', '0');
        $this->withStateLock(function (): void {
            $this->invalidateQueueStateFromState();
            $this->SetBuffer('ResyncPlayers', '{}');
        });
        $this->SetBuffer('ResyncGeneration', '');
        $this->RegisterTimer('CheckConnection', 0, 'MASS_CheckConnection($_IPS["TARGET"]);');
        $this->RequireParent(self::WEBSOCKET_MODULE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->registerParentStatusMessage();
        $this->RegisterMessage($this->InstanceID, FM_CHILDREMOVED);
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->resetAuthRetryState();
        $this->SetStatus($this->isConfigured() ? 104 : 201);
        $this->SetTimerInterval('CheckConnection', $this->isConfigured() ? 5000 : 0);
        try {
            $this->invalidateQueueState();
        } catch (Throwable $e) {
            if (!$this->isStateSemaphoreTimeout($e)) {
                $this->LogMessage('ApplyChanges: ' . $e->getMessage(), KL_ERROR);
                throw $e;
            }
            if ($this->isConfigured() && $this->HasActiveParent()) {
                $this->SetBuffer('ParentReconnectPending', '1');
            }
            $this->SendDebug('State', 'ApplyChanges konnte den Connection-State nicht invalidieren', 0);
            return;
        }

        // Eine bekannte, unveränderte Sitzung kann z. B. nach einem Tokenwechsel weiterverwendet werden.
        if ($this->isConfigured() && $this->HasActiveParent()
            && $this->GetBuffer('GreetingUrl') === $this->webSocketUrl()
            && (int)$this->GetBuffer('ConnectionGeneration') > 0
            && $this->GetBuffer('AuthMessageId') === '' && $this->GetStatus() !== 102) {
            $this->authenticate();
        } elseif ($this->isConfigured() && $this->HasActiveParent()
            && $this->GetBuffer('ParentReconnectAttempted') !== '1') {
            $this->SetBuffer('ParentReconnectPending', '1');
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === FM_CHILDREMOVED && $SenderID === $this->InstanceID) {
            $this->cleanupRegisteredPlayers();
            return;
        }

        if ($Message !== IM_CHANGESTATUS
            || $SenderID !== (int)$this->GetBuffer('ParentInstanceId')) {
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->handleConnectionLost();
            return;
        }

        if (!$this->isConfigured()) {
            return;
        }

        $hasCurrentSession = $this->GetBuffer('GreetingUrl') === $this->webSocketUrl()
            && (int)$this->GetBuffer('ConnectionGeneration') > 0;
        if (!$hasCurrentSession
            && $this->GetBuffer('ParentReconnectAttempted') !== '1'
            && $this->GetBuffer('ParentReconnectPending') !== '1') {
            $this->SetBuffer('ParentReconnectPending', '1');
        }
    }

    public function GetConfigurationForParent(): string
    {
        return json_encode(['URL' => $this->webSocketUrl()]);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Server'],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port'],
                ['type' => 'PasswordTextBox', 'name' => 'Token', 'caption' => 'Token'],
                ['type' => 'Label', 'caption' => 'WebSocket-Anmeldung und Echtzeitaktualisierung von Player-Lautstärke und Mute.']
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Authentifiziert'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Warte auf Verbindung / Server-Begrüßung'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Nicht konfiguriert: Host, Port und Token prüfen'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Authentifizierung fehlgeschlagen'],
                ['code' => 203, 'icon' => 'error', 'caption' => 'Authentifizierung: Senden fehlgeschlagen oder Zeitüberschreitung']
            ]
        ]);
    }

    public function ReceiveData($JSONString): string
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || ($packet['DataID'] ?? '') !== self::WEBSOCKET_RX
            || !is_string($packet['Buffer'] ?? null)) {
            return '';
        }
        $payload = utf8_decode($packet['Buffer']);
        $message = json_decode($payload, true);
        if (!is_array($message)) {
            $this->SendDebug('WebSocket', 'Ungültige JSON-Nachricht', 0);
            return '';
        }
        // Nie Rohdaten, Auth-Requests, Server-Fehlertexte oder Benutzerdaten protokollieren.
        if (!isset($message['event']) && !isset($message['message_id'])
            && is_string($message['server_id'] ?? null)
            && is_string($message['server_version'] ?? null)
            && is_int($message['schema_version'] ?? null)
            && is_int($message['min_supported_schema_version'] ?? null)) {
            $this->withStateLock(function (): void {
                $generation = (int)$this->GetBuffer('ConnectionGeneration') + 1;
                $this->SetBuffer('ConnectionGeneration', (string)$generation);
                $this->SetBuffer('GreetingUrl', $this->webSocketUrl());
                $this->SetBuffer('AuthMessageId', '');
                $this->SetBuffer('AuthGeneration', '');
                $this->SetBuffer('AuthStarted', '0');
                $this->resetAuthRetryState();
                $this->invalidateQueueStateFromState();
            });
            $this->SetBuffer('ParentReconnectPending', '0');
            $this->SetBuffer('ParentReconnectAttempted', '0');
            $this->SetStatus(104);
            $this->SendDebug('WebSocket', 'Server-Begrüßung empfangen', 0);
            $this->authenticate();
            return '';
        }

        if (($message['event'] ?? null) === 'player_updated') {
            $objectId = $message['object_id'] ?? null;
            $data = $message['data'] ?? null;
            if ($this->GetStatus() === 102 && is_string($objectId) && $objectId !== '' && is_array($data)) {
                $this->SendDataToChildren(json_encode([
                    'DataID' => self::CONNECTION_EVENT,
                    'Event' => 'player_updated',
                    'ObjectID' => $objectId,
                    'Data' => $data
                ], JSON_THROW_ON_ERROR));
                $this->SendDebug('player_updated', 'Weitergeleitet für ObjectID=' . $objectId, 0);
                if ($this->queueRelevantPlayerDataChanged($objectId, $data)) {
                    $this->requestActiveQueue($objectId);
                }
            }
            return '';
        }

        if (($message['event'] ?? null) === 'queue_updated') {
            $queueId = $message['object_id'] ?? null;
            $data = $message['data'] ?? null;
            if ($this->GetStatus() === 102 && is_string($queueId) && $queueId !== '' && is_array($data)) {
                $this->SendDebug('queue_updated', 'Empfangen für QueueID=' . $queueId, 0);
                $queuePlayers = $this->withStateLock(function (): array {
                    return $this->readJsonBuffer('QueuePlayers');
                });
                $playerIds = $queuePlayers[$queueId] ?? [];
                if (is_array($playerIds) && count($playerIds) > 0) {
                    $playerIds = array_values(array_filter($playerIds, 'is_string'));
                    $this->SendDataToChildren(json_encode([
                        'DataID' => self::CONNECTION_EVENT,
                        'Event' => 'queue_updated',
                        'ObjectID' => $queueId,
                        'PlayerIDs' => $playerIds,
                        'Data' => $data
                    ], JSON_THROW_ON_ERROR));
                    $this->SendDebug('queue_updated', 'Weitergeleitet an ' . count($playerIds) . ' Player', 0);
                }
            }
            return '';
        }

        if (($message['event'] ?? null) === 'media_item_updated') {
            $objectId = $message['object_id'] ?? null;
            $data = $message['data'] ?? null;
            if ($this->GetStatus() === 102 && is_string($objectId) && $objectId !== '' && is_array($data)) {
                $this->SendDataToChildren(json_encode([
                    'DataID' => self::CONNECTION_EVENT,
                    'Event' => 'media_item_updated',
                    'ObjectID' => $objectId,
                    'Data' => $data
                ], JSON_THROW_ON_ERROR));
                $this->SendDebug('media_item_updated', 'Medienaktualisierung weitergeleitet', 0);
            }
            return '';
        }

        $messageId = $message['message_id'] ?? null;
        if (is_string($messageId) && $this->handleQueueResponse($messageId, $message)) {
            return '';
        }

        $pending = $this->GetBuffer('AuthMessageId');
        $authGeneration = $this->GetBuffer('AuthGeneration');
        if ($pending === '' || ($message['message_id'] ?? null) !== $pending
            || $authGeneration === ''
            || $authGeneration !== $this->GetBuffer('ConnectionGeneration')) {
            return ''; // Events und fremde/veraltete Antworten werden in Phase 2 ignoriert.
        }
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->SetBuffer('AuthRetryAt', '0');
        if (!array_key_exists('error_code', $message) && !array_key_exists('error', $message)
            && ($message['result']['authenticated'] ?? null) === true) {
            $this->prepareResyncGeneration();
            $this->SetStatus(102);
            $this->SendDebug('Authentication', 'Authentifizierung bestätigt', 0);
            $this->cleanupRegisteredPlayers();
            $this->resolveRegisteredPlayers();
        } else {
            $this->SetStatus(202);
            $this->SendDebug('Authentication', 'Authentifizierung abgelehnt', 0);
        }
        return '';
    }

    public function ForwardData($JSONString): string
    {
        $request = json_decode($JSONString, true);
        if (!is_array($request) || ($request['DataID'] ?? null) !== self::CONNECTION_REQUEST) {
            return json_encode(['success' => false]);
        }

        $command = $request['Command'] ?? null;
        if ($command === 'ApiRequest') {
            return $this->handleApiRequest($request);
        }
        if ($command === 'PlayRefreshedPlaylist') {
            return $this->handlePlayRefreshedPlaylist($request);
        }
        if ($command === 'ArtworkRequest') {
            return $this->handleArtworkRequest($request);
        }
        if (!in_array($command, ['RegisterPlayer', 'UnregisterPlayer'], true)
            || !is_string($request['PlayerID'] ?? null)
            || trim($request['PlayerID']) === '') {
            return json_encode(['success' => false]);
        }

        $playerId = trim($request['PlayerID']);
        if ($command === 'UnregisterPlayer') {
            $this->unregisterPlayer($playerId);
            return json_encode(['success' => true]);
        }

        $isNew = $this->withStateLock(function () use ($playerId): bool {
            $players = $this->readJsonBuffer('RegisteredPlayers');
            $isNew = !isset($players[$playerId]);
            $players[$playerId] = true;
            $this->writeJsonBuffer('RegisteredPlayers', $players);
            return $isNew;
        });
        if ($isNew) {
            $this->SendDebug('Registration', 'Player registriert: ' . $playerId, 0);
        }
        if ($this->GetStatus() === 102) {
            $this->startPlayerResync($playerId);
        }
        return json_encode(['success' => true]);
    }

    private function handleApiRequest(array $request): string
    {
        $allowedFields = ['DataID', 'Command', 'ApiCommand', 'Params'];
        if (count(array_diff(array_keys($request), $allowedFields)) > 0
            || !is_string($request['ApiCommand'] ?? null)
            || trim($request['ApiCommand']) === '') {
            return $this->apiErrorResponse('INVALID_REQUEST', 'Ungültige API-Anfrage.');
        }

        $apiCommand = trim($request['ApiCommand']);
        if (!in_array($apiCommand, self::API_COMMANDS, true)) {
            return $this->apiErrorResponse('COMMAND_NOT_ALLOWED', 'API-Command ist nicht freigegeben.');
        }

        $params = $request['Params'] ?? [];
        if (!is_array($params)
            || (count($params) > 0 && array_keys($params) === range(0, count($params) - 1))) {
            return $this->apiErrorResponse('INVALID_REQUEST', 'Params muss ein JSON-Objekt sein.');
        }

        try {
            $this->SendDebug(
                'ApiRequest',
                'Command=' . $apiCommand,
                0
            );
            $response = $this->maCall($apiCommand, $params, 20000, false);
            if (($response['success'] ?? false) !== true) {
                $this->SendDebug('ApiRequest', 'Music-Assistant-Fehler für Command: ' . $apiCommand, 0);
                return $this->apiErrorResponse('API_ERROR', 'Music-Assistant-Anfrage fehlgeschlagen.');
            }
            return json_encode([
                'success'   => true,
                'result'    => $response['result'] ?? null,
                'http_code' => (int)($response['http_code'] ?? 0)
            ], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            $this->SendDebug('ApiRequest', 'HTTP-Anfrage fehlgeschlagen für Command: ' . $apiCommand, 0);
            return $this->apiErrorResponse('API_ERROR', 'Music-Assistant-Anfrage fehlgeschlagen.');
        }
    }

    private function handlePlayRefreshedPlaylist(array $request): string
    {
        $allowedFields = ['DataID', 'Command', 'QueueID', 'ItemID', 'Provider'];
        $queueId = is_string($request['QueueID'] ?? null) ? trim($request['QueueID']) : '';
        $itemId = is_string($request['ItemID'] ?? null) ? trim($request['ItemID']) : '';
        $provider = is_string($request['Provider'] ?? null) ? trim($request['Provider']) : '';
        if (count(array_diff(array_keys($request), $allowedFields)) > 0
            || $queueId === ''
            || strlen($queueId) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $queueId)
            || !in_array($itemId, ['random_tracks', 'random_album', 'random_artist'], true)
            || $provider !== 'builtin') {
            return $this->apiErrorResponse('INVALID_REQUEST', 'Ungültige Anfrage für die aktualisierte Playlist-Wiedergabe.');
        }

        try {
            $response = $this->maCall('music/playlists/playlist_tracks', [
                'item_id'                        => $itemId,
                'provider_instance_id_or_domain' => $provider,
                'force_refresh'                  => true
            ], 20000, false);
            $tracks = $response['result'] ?? null;
            if (($response['success'] ?? false) !== true
                || !is_array($tracks)
                || count($tracks) === 0
                || array_keys($tracks) !== range(0, count($tracks) - 1)) {
                return $this->apiErrorResponse('PLAYLIST_REFRESH_FAILED', 'Die Zufallsplaylist konnte nicht vollständig aktualisiert werden.');
            }

            $trackUris = [];
            foreach ($tracks as $track) {
                if (!is_array($track)
                    || !is_string($track['uri'] ?? null)
                    || trim($track['uri']) === '') {
                    return $this->apiErrorResponse('PLAYLIST_REFRESH_FAILED', 'Die Zufallsplaylist enthält einen ungültigen Titel.');
                }
                $trackUris[] = trim($track['uri']);
            }

            $playResponse = $this->maCall('player_queues/play_media', [
                'queue_id' => $queueId,
                'media'    => $trackUris,
                'option'   => 'replace'
            ], 20000, false);
            if (($playResponse['success'] ?? false) !== true) {
                return $this->apiErrorResponse('PLAY_MEDIA_FAILED', 'Die aktualisierte Zufallsplaylist konnte nicht gestartet werden.');
            }

            $this->SendDebug('PlayRefreshedPlaylist', 'Titel an Queue übergeben: ' . count($trackUris), 0);
            return json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            $this->SendDebug('PlayRefreshedPlaylist', 'Aktualisierung oder Wiedergabe fehlgeschlagen', 0);
            return $this->apiErrorResponse('PLAY_REFRESHED_PLAYLIST_FAILED', 'Die Zufallsplaylist konnte nicht aktualisiert und gestartet werden.');
        }
    }

    private function handleArtworkRequest(array $request): string
    {
        $allowedFields = ['DataID', 'Command', 'ProxyID'];
        if (count(array_diff(array_keys($request), $allowedFields)) > 0
            || !is_string($request['ProxyID'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $request['ProxyID']) !== 1) {
            return $this->apiErrorResponse('INVALID_REQUEST', 'Ungültige Artwork-Anfrage.');
        }
        if (!$this->isConfigured()) {
            return $this->apiErrorResponse('NOT_CONFIGURED', 'Music Assistant ist nicht konfiguriert.');
        }

        $authenticatedRetry = false;
        try {
            $proxyId = $request['ProxyID'];
            $response = $this->downloadArtwork($proxyId, false);
            if (in_array($response['http_code'], [401, 403], true)
                && trim($this->ReadPropertyString('Token')) !== '') {
                $authenticatedRetry = true;
                $response = $this->downloadArtwork($proxyId, true);
            }
            if ($response['http_code'] !== 200) {
                $message = 'Imageproxy HTTP-Status=' . $response['http_code'];
                if ($authenticatedRetry) {
                    $message = 'Authentifizierter Wiederholungsversuch fehlgeschlagen; ' . $message;
                }
                $this->SendDebug('Artwork', $message, 0);
                return $this->apiErrorResponse('IMAGE_ERROR', 'Cover konnte nicht geladen werden.');
            }
            if (!in_array($response['content_type'], ['image/jpeg', 'image/png'], true)) {
                $message = 'Imageproxy lieferte unerwarteten Content-Type: '
                    . ($response['content_type'] === '' ? '(leer)' : $response['content_type']);
                if ($authenticatedRetry) {
                    $message = 'Authentifizierter Wiederholungsversuch fehlgeschlagen; ' . $message;
                }
                $this->SendDebug('Artwork', $message, 0);
                return $this->apiErrorResponse('IMAGE_ERROR', 'Cover konnte nicht geladen werden.');
            }
            if ($response['body'] === '') {
                $message = 'Imageproxy lieferte leere Bilddaten';
                if ($authenticatedRetry) {
                    $message = 'Authentifizierter Wiederholungsversuch fehlgeschlagen; ' . $message;
                }
                $this->SendDebug('Artwork', $message, 0);
                return $this->apiErrorResponse('IMAGE_ERROR', 'Cover konnte nicht geladen werden.');
            }
            if (strlen($response['body']) > self::MAX_ARTWORK_SIZE) {
                $message = 'Imageproxy überschritt die maximale Bildgröße';
                if ($authenticatedRetry) {
                    $message = 'Authentifizierter Wiederholungsversuch fehlgeschlagen; ' . $message;
                }
                $this->SendDebug('Artwork', $message, 0);
                return $this->apiErrorResponse('IMAGE_ERROR', 'Cover konnte nicht geladen werden.');
            }
            $validSignature = $response['content_type'] === 'image/jpeg'
                ? substr($response['body'], 0, 3) === "\xFF\xD8\xFF"
                : substr($response['body'], 0, 8) === "\x89PNG\r\n\x1A\n";
            if (!$validSignature) {
                $message = 'Imageproxy-Signatur passt nicht zum Content-Type ' . $response['content_type'];
                if ($authenticatedRetry) {
                    $message = 'Authentifizierter Wiederholungsversuch fehlgeschlagen; ' . $message;
                }
                $this->SendDebug('Artwork', $message, 0);
                return $this->apiErrorResponse('IMAGE_ERROR', 'Cover konnte nicht geladen werden.');
            }

            $this->SendDebug('Artwork', 'Cover über Imageproxy geladen', 0);
            return json_encode([
                'success'      => true,
                'proxy_id'     => $proxyId,
                'content_type' => $response['content_type'],
                'content'      => base64_encode($response['body'])
            ], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (!in_array($message, [
                'Timeout beim Erwerb des HTTP-Semaphors',
                'Imageproxy konnte nicht initialisiert werden.',
                'Imageproxy überschritt die maximale Bildgröße'
            ], true) && preg_match('/^cURL-Verbindungs- oder Übertragungsfehler; Fehlernummer=\d+$/D', $message) !== 1) {
                $message = 'Unerwarteter Fehler beim Imageproxy-Abruf; Typ=' . get_class($e);
            }
            if ($authenticatedRetry) {
                $message = 'Authentifizierter Wiederholungsversuch fehlgeschlagen; ' . $message;
            }
            $this->SendDebug('Artwork', $message, 0);
            return $this->apiErrorResponse('IMAGE_ERROR', 'Cover konnte nicht geladen werden.');
        }
    }

    private function downloadArtwork(string $proxyId, bool $authenticated): array
    {
        $host = trim($this->ReadPropertyString('Host'));
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        $url = sprintf(
            'http://%s:%d/imageproxy/%s?size=512&fmt=jpg',
            $host,
            $this->ReadPropertyInteger('Port'),
            $proxyId
        );
        $headers = ['Accept: image/jpeg, image/png'];
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . trim($this->ReadPropertyString('Token'));
        }

        if (!IPS_SemaphoreEnter($this->maSemaphoreName(), 15000)) {
            throw new Exception('Timeout beim Erwerb des HTTP-Semaphors');
        }
        try {
            $body = '';
            $sizeExceeded = false;
            $maxArtworkSize = self::MAX_ARTWORK_SIZE;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new Exception('Imageproxy konnte nicht initialisiert werden.');
            }
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET           => true,
                CURLOPT_HTTPHEADER        => $headers,
                CURLOPT_TIMEOUT_MS        => 20000,
                CURLOPT_CONNECTTIMEOUT_MS => 5000,
                CURLOPT_FOLLOWLOCATION    => false,
                CURLOPT_MAXFILESIZE       => self::MAX_ARTWORK_SIZE,
                CURLOPT_WRITEFUNCTION     => static function ($curl, string $chunk) use (
                    &$body,
                    &$sizeExceeded,
                    $maxArtworkSize
                ): int {
                    $chunkLength = strlen($chunk);
                    if (strlen($body) + $chunkLength > $maxArtworkSize) {
                        $sizeExceeded = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return $chunkLength;
                }
            ]);
            $transferSucceeded = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
            $curlErrorNumber = curl_errno($ch);
            curl_close($ch);
        } finally {
            IPS_SemaphoreLeave($this->maSemaphoreName());
        }

        if ($sizeExceeded || $curlErrorNumber === CURLE_FILESIZE_EXCEEDED) {
            throw new Exception('Imageproxy überschritt die maximale Bildgröße');
        }
        if ($transferSucceeded === false) {
            throw new Exception('cURL-Verbindungs- oder Übertragungsfehler; Fehlernummer=' . $curlErrorNumber);
        }
        $contentType = trim(explode(';', $contentType, 2)[0]);
        return [
            'http_code'   => $httpCode,
            'content_type' => $contentType,
            'body'         => $body
        ];
    }

    private function apiErrorResponse(string $code, string $message): string
    {
        return json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ], JSON_UNESCAPED_SLASHES);
    }

    public function CheckConnection(): void
    {
        if (!$this->isConfigured() || !$this->HasActiveParent()) {
            $this->handleConnectionLost();
            return;
        }
        if ($this->GetBuffer('ParentReconnectPending') === '1') {
            $this->reconnectParent();
            return;
        }
        $started = (int)$this->GetBuffer('AuthStarted');
        $now = time();
        if ($started > 0 && $now - $started >= 15) {
            $generation = $this->GetBuffer('AuthGeneration');
            $this->SetBuffer('AuthMessageId', '');
            $this->SetBuffer('AuthGeneration', '');
            $this->SetBuffer('AuthStarted', '0');
            $this->SetStatus(203);
            $this->SendDebug('Authentication', 'Keine Bestätigung innerhalb von 15 Sekunden', 0);
            $this->scheduleAuthRetry($generation);
        }
        $retryAt = (int)$this->GetBuffer('AuthRetryAt');
        if ($retryAt > 0 && $now >= $retryAt
            && $this->GetBuffer('AuthMessageId') === ''
            && $this->GetBuffer('AuthRetryGeneration') === $this->GetBuffer('ConnectionGeneration')) {
            $this->authenticate();
        }
        $this->expireQueueRequests();
    }

    private function authenticate(): void
    {
        if ($this->GetStatus() === 102) {
            $this->SetBuffer('AuthRetryAt', '0');
            return;
        }
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->invalidateQueueState();
        if (!$this->isConfigured()) {
            $this->SetStatus(201);
            return;
        }
        $generation = $this->GetBuffer('ConnectionGeneration');
        if ($this->GetBuffer('AuthRetryGeneration') !== $generation) {
            $this->resetAuthRetryState();
        }
        $attempts = (int)$this->GetBuffer('AuthAttempts');
        if ($attempts >= self::AUTH_MAX_ATTEMPTS) {
            $this->SetBuffer('AuthRetryAt', '0');
            $this->SetStatus(203);
            $this->SendDebug('Authentication', 'Maximale Anzahl automatischer Versuche erreicht', 0);
            return;
        }
        $this->SetBuffer('AuthAttempts', (string)($attempts + 1));
        $this->SetBuffer('AuthRetryAt', '0');
        $this->SetStatus(104);
        try {
            $id = 'auth-' . bin2hex(random_bytes(16));
            $this->SetBuffer('AuthMessageId', $id);
            $this->SetBuffer('AuthGeneration', $this->GetBuffer('ConnectionGeneration'));
            $this->SetBuffer('AuthStarted', (string)time());
            // json_encode erzeugt hier ASCII (auch für Unicode im Token): Simpel-TX ist verlustfrei.
            $payload = json_encode([
                'message_id' => $id,
                'command' => 'auth',
                'args' => ['token' => trim($this->ReadPropertyString('Token'))]
            ], JSON_THROW_ON_ERROR);
            $result = @$this->SendDataToParent(json_encode([
                'DataID' => self::WEBSOCKET_TX,
                'Buffer' => $payload
            ], JSON_THROW_ON_ERROR));
            if ($result === false) {
                throw new Exception('Send failed');
            }
        } catch (Throwable $e) {
            $this->SetBuffer('AuthMessageId', '');
            $this->SetBuffer('AuthGeneration', '');
            $this->SetBuffer('AuthStarted', '0');
            $this->SetStatus(203);
            $this->SendDebug('Authentication', 'Auth-Nachricht konnte nicht gesendet werden', 0);
            $this->scheduleAuthRetry($generation);
        }
    }

    private function resetAuthRetryState(): void
    {
        $this->SetBuffer('AuthAttempts', '0');
        $this->SetBuffer('AuthRetryAt', '0');
        $this->SetBuffer('AuthRetryGeneration', $this->GetBuffer('ConnectionGeneration'));
    }

    private function scheduleAuthRetry(string $generation): void
    {
        if ($generation === '' || $generation !== $this->GetBuffer('ConnectionGeneration')) {
            return;
        }
        if ((int)$this->GetBuffer('AuthAttempts') >= self::AUTH_MAX_ATTEMPTS) {
            $this->SetBuffer('AuthRetryAt', '0');
            $this->SendDebug('Authentication', 'Maximale Anzahl automatischer Versuche erreicht', 0);
            return;
        }
        $this->SetBuffer('AuthRetryGeneration', $generation);
        $this->SetBuffer('AuthRetryAt', (string)(time() + self::AUTH_RETRY_DELAY_SECONDS));
        $this->SendDebug('Authentication', 'Wiederholung in frühestens fünf Sekunden vorgesehen', 0);
    }

    private function registerParentStatusMessage(): void
    {
        $previousParentId = (int)$this->GetBuffer('ParentInstanceId');
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);

        if ($previousParentId > 0 && $previousParentId !== $parentId) {
            $this->UnregisterMessage($previousParentId, IM_CHANGESTATUS);
        }
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, IM_CHANGESTATUS);
        }
        if ($previousParentId !== $parentId) {
            $this->SetBuffer('GreetingUrl', '');
            $this->SetBuffer('ParentReconnectAttempted', '0');
        }
        $this->SetBuffer('ParentInstanceId', (string)$parentId);
    }

    private function handleConnectionLost(): void
    {
        $wasConnected = $this->GetBuffer('GreetingUrl') !== ''
            || $this->GetBuffer('AuthMessageId') !== ''
            || $this->GetStatus() === 102;

        $this->SetBuffer('GreetingUrl', '');
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->resetAuthRetryState();
        $this->invalidateQueueState();
        $this->SetStatus($this->isConfigured() ? 104 : 201);

        if ($wasConnected) {
            $this->SendDebug('WebSocket', 'Verbindung verloren; Sitzung invalidiert', 0);
        }
    }

    private function reconnectParent(): void
    {
        $parentId = (int)$this->GetBuffer('ParentInstanceId');
        $this->SetBuffer('ParentReconnectPending', '0');
        $this->SetBuffer('ParentReconnectAttempted', '1');
        $this->handleConnectionLost();

        if ($parentId <= 0 || !IPS_ApplyChanges($parentId)) {
            $this->SetStatus(203);
            $this->SendDebug('WebSocket', 'Kontrollierter Reconnect konnte nicht gestartet werden', 0);
            return;
        }
        $this->SendDebug('WebSocket', 'Kontrollierter Reconnect nach Modulinitialisierung gestartet', 0);
    }

    private function resolveRegisteredPlayers(): void
    {
        $registeredPlayers = $this->withStateLock(function (): array {
            return $this->readJsonBuffer('RegisteredPlayers');
        });
        foreach (array_keys($registeredPlayers) as $playerId) {
            if (is_string($playerId) && $playerId !== '') {
                $this->startPlayerResync($playerId);
            }
        }
    }

    private function cleanupRegisteredPlayers(): void
    {
        $validPlayerIds = [];
        foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE_ID) as $playerInstanceId) {
            if (!IPS_InstanceExists($playerInstanceId)) {
                continue;
            }
            $playerInstance = IPS_GetInstance($playerInstanceId);
            if ((int)($playerInstance['ConnectionID'] ?? 0) !== $this->InstanceID) {
                continue;
            }
            $playerId = trim((string)IPS_GetProperty($playerInstanceId, 'PlayerID'));
            if ($playerId !== '') {
                $validPlayerIds[$playerId] = true;
            }
        }

        $registeredPlayers = $this->withStateLock(function (): array {
            return $this->readJsonBuffer('RegisteredPlayers');
        });
        foreach (array_keys($registeredPlayers) as $registeredPlayerId) {
            $registeredPlayerId = (string)$registeredPlayerId;
            if ($registeredPlayerId !== '' && !isset($validPlayerIds[$registeredPlayerId])) {
                $this->unregisterPlayer($registeredPlayerId);
            }
        }
    }

    private function startPlayerResync(string $playerId): void
    {
        try {
            $reservation = $this->withStateLock(function () use ($playerId): ?array {
                $generation = $this->GetBuffer('ConnectionGeneration');
                $registeredPlayers = $this->readJsonBuffer('RegisteredPlayers');
                if ($this->GetStatus() !== 102 || $generation === ''
                    || $this->GetBuffer('ResyncGeneration') !== $generation
                    || !isset($registeredPlayers[$playerId])) {
                    return null;
                }

                $resyncPlayers = $this->readJsonBuffer('ResyncPlayers');
                if (is_array($resyncPlayers[$playerId] ?? null)) {
                    $state = $resyncPlayers[$playerId];
                    if (($state['Player'] ?? null) !== null && ($state['Queue'] ?? null) !== null) {
                        return null;
                    }
                    $resyncPlayers[$playerId]['Starting'] = (int)($state['Starting'] ?? 0) + 1;
                    $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);
                    return [
                        'Generation' => $generation,
                        'StartPlayer' => ($state['Player'] ?? null) === null,
                        'StartQueue' => ($state['Queue'] ?? null) === null
                    ];
                }
                $resyncPlayers[$playerId] = ['Player' => null, 'Queue' => null, 'Starting' => 1];
                $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);
                return ['Generation' => $generation, 'StartPlayer' => true, 'StartQueue' => true];
            });
        } catch (Throwable $e) {
            $this->SendDebug('Resync', 'Resync konnte nicht reserviert werden: ' . $playerId, 0);
            return;
        }
        if ($reservation === null) {
            return;
        }
        $generation = $reservation['Generation'];
        $this->SendDebug('Resync', 'Start für Player: ' . $playerId, 0);
        try {
            if ($reservation['StartPlayer']) {
                $this->requestPlayerSnapshot($playerId);
            }
            if ($reservation['StartQueue']) {
                $this->requestActiveQueue($playerId, true);
            }
        } finally {
            $this->releaseResyncReservation($playerId, $generation);
        }
    }

    private function requestPlayerSnapshot(string $playerId): bool
    {
        $messageId = '';
        $generation = null;
        try {
            $messageId = 'player-' . bin2hex(random_bytes(16));
            $reservation = $this->withStateLock(function () use ($messageId, $playerId): ?array {
                $generation = $this->GetBuffer('ConnectionGeneration');
                $registeredPlayers = $this->readJsonBuffer('RegisteredPlayers');
                if ($this->GetStatus() !== 102 || $generation === ''
                    || $this->GetBuffer('ResyncGeneration') !== $generation
                    || !isset($registeredPlayers[$playerId])) {
                    return null;
                }
                $pending = $this->readJsonBuffer('PendingQueueRequests');
                foreach ($pending as $request) {
                    if (is_array($request) && ($request['Generation'] ?? null) === $generation
                        && ($request['PlayerID'] ?? null) === $playerId
                        && ($request['Type'] ?? null) === 'GetPlayerSnapshot') {
                        return ['Generation' => $generation, 'Send' => false];
                    }
                }
                $pending[$messageId] = [
                    'Generation' => $generation,
                    'Type' => 'GetPlayerSnapshot',
                    'PlayerID' => $playerId,
                    'Started' => time()
                ];
                $this->writeJsonBuffer('PendingQueueRequests', $pending);
                return ['Generation' => $generation, 'Send' => true];
            });
            if ($reservation === null) {
                return false;
            }
            $generation = $reservation['Generation'];

            if (!$reservation['Send']) {
                return true;
            }
            $this->sendWebSocketCommand($messageId, 'players/get', ['player_id' => $playerId]);
            return true;
        } catch (Throwable $e) {
            if ($messageId !== '') {
                $this->tryRemovePendingRequest($messageId);
            }
            $this->SendDebug('Resync', 'Player-Snapshot konnte nicht gestartet werden: ' . $playerId, 0);
            return false;
        }
    }

    private function requestActiveQueue(string $playerId, bool $resync = false): bool
    {
        $messageId = '';
        $generation = null;
        try {
            $messageId = 'queue-' . bin2hex(random_bytes(16));
            $reservation = $this->withStateLock(function () use ($messageId, $playerId, $resync): ?array {
                $players = $this->readJsonBuffer('RegisteredPlayers');
                $generation = $this->GetBuffer('ConnectionGeneration');
                if ($this->GetStatus() !== 102 || !isset($players[$playerId]) || $generation === '') {
                    return null;
                }

                $pending = $this->readJsonBuffer('PendingQueueRequests');
                foreach ($pending as $pendingMessageId => $request) {
                    if (is_array($request) && ($request['Generation'] ?? null) === $generation
                        && ($request['PlayerID'] ?? null) === $playerId
                        && in_array($request['Type'] ?? null, ['GetActiveQueue', 'GetActiveQueueResync'], true)) {
                        if ($resync && ($request['Type'] ?? null) === 'GetActiveQueue') {
                            $pending[$pendingMessageId]['Type'] = 'GetActiveQueueResync';
                            $this->writeJsonBuffer('PendingQueueRequests', $pending);
                        }
                        return ['Generation' => $generation, 'Send' => false];
                    }
                }

                $pending[$messageId] = [
                    'Generation' => $generation,
                    'Type' => $resync ? 'GetActiveQueueResync' : 'GetActiveQueue',
                    'PlayerID' => $playerId,
                    'Started' => time()
                ];
                $this->writeJsonBuffer('PendingQueueRequests', $pending);
                return ['Generation' => $generation, 'Send' => true];
            });
            if ($reservation === null) {
                return false;
            }
            $generation = $reservation['Generation'];

            if (!$reservation['Send']) {
                return true;
            }
            $this->sendWebSocketCommand(
                $messageId,
                'player_queues/get_active_queue',
                ['player_id' => $playerId]
            );
            $this->SendDebug('Active Queue', 'Angefordert: ' . $playerId, 0);
            return true;
        } catch (Throwable $e) {
            if ($messageId !== '') {
                $this->tryRemovePendingRequest($messageId);
            }
            if ($generation !== null) {
                $this->tryRemoveQueueMapping($playerId, $generation);
            }
            $this->SendDebug('Active Queue', 'Anfrage fehlgeschlagen: ' . $playerId, 0);
            return false;
        }
    }

    private function handleQueueResponse(string $messageId, array $message): bool
    {
        $request = $this->withStateLock(function () use ($messageId, $message): ?array {
            $pending = $this->readJsonBuffer('PendingQueueRequests');
            $request = $pending[$messageId] ?? null;
            if (!is_array($request)) {
                return null;
            }
            unset($pending[$messageId]);
            $this->writeJsonBuffer('PendingQueueRequests', $pending);
            $request['CurrentGeneration'] = $this->GetBuffer('ConnectionGeneration');
            $playerId = $request['PlayerID'] ?? null;
            $type = $request['Type'] ?? null;
            if (is_string($playerId)
                && ($request['Generation'] ?? null) === $request['CurrentGeneration']
                && $this->GetBuffer('ResyncGeneration') === $request['CurrentGeneration']) {
                $result = $message['result'] ?? null;
                $hasError = array_key_exists('error_code', $message) || array_key_exists('error', $message);
                $step = null;
                $success = false;
                if ($type === 'GetPlayerSnapshot') {
                    $step = 'Player';
                    $success = !$hasError && is_array($result) && ($result['player_id'] ?? null) === $playerId;
                } elseif ($type === 'GetActiveQueueResync') {
                    $step = 'Queue';
                    $success = !$hasError && $this->isValidPlayerQueue($result);
                }
                if ($step !== null) {
                    $resyncPlayers = $this->readJsonBuffer('ResyncPlayers');
                    if (is_array($resyncPlayers[$playerId] ?? null)
                        && array_key_exists($step, $resyncPlayers[$playerId])) {
                        $resyncPlayers[$playerId][$step] = $success;
                        $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);
                        $request['ResyncState'] = $resyncPlayers[$playerId];
                    }
                }
            }
            return $request;
        });
        if (!is_array($request)) {
            return false;
        }

        $playerId = $request['PlayerID'] ?? null;
        $type = $request['Type'] ?? null;
        if (!is_string($playerId)
            || ($request['Generation'] ?? null) !== ($request['CurrentGeneration'] ?? null)) {
            return true;
        }

        $result = $message['result'] ?? null;
        $hasError = array_key_exists('error_code', $message) || array_key_exists('error', $message);
        if ($type === 'GetPlayerSnapshot') {
            if ($hasError || !is_array($result) || ($result['player_id'] ?? null) !== $playerId) {
                $this->SendDebug('Resync', 'Ungültiger Player-Snapshot: ' . $playerId, 0);
                $this->logResyncStepResult($playerId, 'Player', false, $request['ResyncState'] ?? null);
                return true;
            }
            if (!$this->isCurrentGeneration((string)$request['Generation'])) {
                return true;
            }
            $this->SendDataToChildren(json_encode([
                'DataID' => self::CONNECTION_EVENT,
                'Event' => 'player_updated',
                'ObjectID' => $playerId,
                'Data' => $result,
                'Source' => 'resync'
            ], JSON_THROW_ON_ERROR));
            $this->SendDebug('Resync', 'Player-Snapshot verarbeitet: ' . $playerId, 0);
            $this->logResyncStepResult($playerId, 'Player', true, $request['ResyncState'] ?? null);
            return true;
        }

        if (!in_array($type, ['GetActiveQueue', 'GetActiveQueueResync'], true)) {
            return true;
        }
        $isResync = $type === 'GetActiveQueueResync';
        if ($hasError || !$this->isValidPlayerQueue($result)) {
            $this->tryRemoveQueueMapping($playerId, (string)$request['Generation']);
            $this->SendDebug('Active Queue', 'Keine gültige Queue für: ' . $playerId, 0);
            if ($isResync) {
                $this->logResyncStepResult($playerId, 'Queue', false, $request['ResyncState'] ?? null);
            }
            return true;
        }

        $queueId = trim($result['queue_id']);
        if (!$this->setQueueMapping($playerId, $queueId, (string)$request['Generation'])) {
            return true;
        }
        if (!$isResync) {
            $this->SendDataToChildren(json_encode([
                'DataID' => self::CONNECTION_EVENT,
                'Event' => 'queue_updated',
                'ObjectID' => $queueId,
                'PlayerIDs' => [$playerId],
                'Data' => $result
            ], JSON_THROW_ON_ERROR));
            $this->SendDebug('Active Queue', 'Queue-Snapshot verarbeitet: ' . $queueId . ' für PlayerID=' . $playerId, 0);
            return true;
        }
        if ($isResync) {
            if (!$this->isCurrentGeneration((string)$request['Generation'])) {
                return true;
            }
            $this->SendDebug('Resync', 'Active Queue: ' . $playerId . ' -> ' . $queueId, 0);
            $this->SendDataToChildren(json_encode([
                'DataID' => self::CONNECTION_EVENT,
                'Event' => 'queue_updated',
                'ObjectID' => $queueId,
                'PlayerIDs' => [$playerId],
                'Data' => $result,
                'Source' => 'resync'
            ], JSON_THROW_ON_ERROR));
            $this->SendDebug('Resync', 'Queue-Snapshot verarbeitet: ' . $queueId, 0);
            $this->logResyncStepResult($playerId, 'Queue', true, $request['ResyncState'] ?? null);
        }
        return true;
    }

    private function sendWebSocketCommand(string $messageId, string $command, array $args): void
    {
        $payload = json_encode([
            'message_id' => $messageId,
            'command' => $command,
            'args' => $args
        ], JSON_THROW_ON_ERROR);
        $result = @$this->SendDataToParent(json_encode([
            'DataID' => self::WEBSOCKET_TX,
            'Buffer' => $payload
        ], JSON_THROW_ON_ERROR));
        if ($result === false) {
            throw new Exception('Send failed');
        }
    }

    private function prepareResyncGeneration(): void
    {
        $this->withStateLock(function (): void {
            $generation = $this->GetBuffer('ConnectionGeneration');
            if ($this->GetBuffer('ResyncGeneration') !== $generation) {
                $this->SetBuffer('ResyncGeneration', $generation);
                $this->SetBuffer('ResyncPlayers', '{}');
            }
        });
    }

    private function completeResyncStep(
        string $playerId,
        string $step,
        bool $success,
        string $generation
    ): void
    {
        $state = $this->withStateLock(function () use ($playerId, $step, $success, $generation): ?array {
            if ($this->GetBuffer('ResyncGeneration') !== $generation
                || $this->GetBuffer('ConnectionGeneration') !== $generation) {
                return null;
            }
            $resyncPlayers = $this->readJsonBuffer('ResyncPlayers');
            if (!is_array($resyncPlayers[$playerId] ?? null)
                || !array_key_exists($step, $resyncPlayers[$playerId])) {
                return null;
            }
            $resyncPlayers[$playerId][$step] = $success;
            $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);
            return $resyncPlayers[$playerId];
        });
        if ($state === null) {
            return;
        }
        $this->logResyncStepResult($playerId, $step, $success, $state);
    }

    private function logResyncStepResult(string $playerId, string $step, bool $success, $state): void
    {
        if (!is_array($state)) {
            return;
        }
        if (!$success) {
            $this->SendDebug('Resync', $step . '-Schritt fehlgeschlagen: ' . $playerId, 0);
        }
        if ($state['Player'] === null || $state['Queue'] === null) {
            return;
        }
        if ($state['Player'] === true && $state['Queue'] === true) {
            $this->SendDebug('Resync', 'Abgeschlossen für Player: ' . $playerId, 0);
        } else {
            $this->SendDebug('Resync', 'Beendet mit Fehler für Player: ' . $playerId, 0);
        }
    }

    private function isValidPlayerQueue($result): bool
    {
        return is_array($result)
            && is_string($result['queue_id'] ?? null) && trim($result['queue_id']) !== ''
            && is_bool($result['active'] ?? null)
            && is_string($result['display_name'] ?? null)
            && is_bool($result['available'] ?? null)
            && is_int($result['items'] ?? null);
    }

    private function setQueueMapping(string $playerId, string $queueId, string $generation): bool
    {
        $result = $this->withStateLock(function () use ($playerId, $queueId, $generation): array {
            $registeredPlayers = $this->readJsonBuffer('RegisteredPlayers');
            if ($this->GetBuffer('ConnectionGeneration') !== $generation
                || !isset($registeredPlayers[$playerId])) {
                return ['updated' => false, 'removed' => false];
            }
            $playerQueues = $this->readJsonBuffer('PlayerQueues');
            $queuePlayers = $this->readJsonBuffer('QueuePlayers');
            $removed = $this->removeQueueMappingFromState($playerId, $playerQueues, $queuePlayers);
            $playerQueues[$playerId] = $queueId;
            $queuePlayers[$queueId] = array_values(array_unique(array_merge(
                is_array($queuePlayers[$queueId] ?? null) ? $queuePlayers[$queueId] : [],
                [$playerId]
            )));
            $this->writeJsonBuffer('PlayerQueues', $playerQueues);
            $this->writeJsonBuffer('QueuePlayers', $queuePlayers);
            return ['updated' => true, 'removed' => $removed];
        });
        if ($result['removed']) {
            $this->SendDebug('Active Queue', 'Queue-Zuordnung entfernt: ' . $playerId, 0);
        }
        if (!$result['updated']) {
            return false;
        }
        $this->SendDebug('Active Queue', $playerId . ' -> ' . $queueId, 0);
        return true;
    }

    private function removeQueueMapping(string $playerId, ?string $generation = null): void
    {
        $removed = $this->withStateLock(function () use ($playerId, $generation): bool {
            if ($generation !== null && $this->GetBuffer('ConnectionGeneration') !== $generation) {
                return false;
            }
            $playerQueues = $this->readJsonBuffer('PlayerQueues');
            $queuePlayers = $this->readJsonBuffer('QueuePlayers');
            $removed = $this->removeQueueMappingFromState($playerId, $playerQueues, $queuePlayers);
            if ($removed) {
                $this->writeJsonBuffer('PlayerQueues', $playerQueues);
                $this->writeJsonBuffer('QueuePlayers', $queuePlayers);
            }
            return $removed;
        });
        if ($removed) {
            $this->SendDebug('Active Queue', 'Queue-Zuordnung entfernt: ' . $playerId, 0);
        }
    }

    private function unregisterPlayer(string $playerId): void
    {
        $removedMapping = $this->withStateLock(function () use ($playerId): bool {
            $players = $this->readJsonBuffer('RegisteredPlayers');
            unset($players[$playerId]);
            $this->writeJsonBuffer('RegisteredPlayers', $players);

            $pending = $this->readJsonBuffer('PendingQueueRequests');
            foreach ($pending as $messageId => $request) {
                if (is_array($request) && ($request['PlayerID'] ?? null) === $playerId) {
                    unset($pending[$messageId]);
                }
            }
            $this->writeJsonBuffer('PendingQueueRequests', $pending);

            $hints = $this->readJsonBuffer('QueueHints');
            unset($hints[$playerId]);
            $this->writeJsonBuffer('QueueHints', $hints);
            $resyncPlayers = $this->readJsonBuffer('ResyncPlayers');
            unset($resyncPlayers[$playerId]);
            $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);

            $playerQueues = $this->readJsonBuffer('PlayerQueues');
            $queuePlayers = $this->readJsonBuffer('QueuePlayers');
            $removedMapping = $this->removeQueueMappingFromState($playerId, $playerQueues, $queuePlayers);
            if ($removedMapping) {
                $this->writeJsonBuffer('PlayerQueues', $playerQueues);
                $this->writeJsonBuffer('QueuePlayers', $queuePlayers);
            }
            return $removedMapping;
        });
        if ($removedMapping) {
            $this->SendDebug('Active Queue', 'Queue-Zuordnung entfernt: ' . $playerId, 0);
        }
        $this->SendDebug('Registration', 'Player abgemeldet: ' . $playerId, 0);
    }

    private function expireQueueRequests(): void
    {
        $expired = $this->withStateLock(function (): array {
            $pending = $this->readJsonBuffer('PendingQueueRequests');
            $playerQueues = $this->readJsonBuffer('PlayerQueues');
            $queuePlayers = $this->readJsonBuffer('QueuePlayers');
            $expired = [];
            $mappingChanged = false;
            foreach ($pending as $messageId => $request) {
                if (!is_array($request) || (int)($request['Started'] ?? 0) <= time() - 15) {
                    $expired[] = $request;
                    unset($pending[$messageId]);
                    if (is_array($request)
                        && is_string($request['PlayerID'] ?? null)
                        && ($request['Type'] ?? null) !== 'GetPlayerSnapshot'
                        && ($request['Generation'] ?? null) === $this->GetBuffer('ConnectionGeneration')) {
                        $mappingChanged = $this->removeQueueMappingFromState(
                            $request['PlayerID'],
                            $playerQueues,
                            $queuePlayers
                        ) || $mappingChanged;
                    }
                }
            }
            if (count($expired) > 0) {
                $this->writeJsonBuffer('PendingQueueRequests', $pending);
            }
            if ($mappingChanged) {
                $this->writeJsonBuffer('PlayerQueues', $playerQueues);
                $this->writeJsonBuffer('QueuePlayers', $queuePlayers);
            }
            return $expired;
        });

        foreach ($expired as $request) {
            if (!is_array($request) || !is_string($request['PlayerID'] ?? null)) {
                continue;
            }
            $playerId = $request['PlayerID'];
            $type = $request['Type'] ?? null;
            if ($type === 'GetPlayerSnapshot') {
                $this->SendDebug('Resync', 'Player-Snapshot Zeitüberschreitung: ' . $playerId, 0);
                $this->tryCompleteResyncStep(
                    $playerId,
                    'Player',
                    false,
                    (string)($request['Generation'] ?? '')
                );
            } else {
                $this->SendDebug('Active Queue', 'Zeitüberschreitung: ' . $playerId, 0);
                if ($type === 'GetActiveQueueResync') {
                    $this->tryCompleteResyncStep(
                        $playerId,
                        'Queue',
                        false,
                        (string)($request['Generation'] ?? '')
                    );
                }
            }
        }
    }

    private function queueRelevantPlayerDataChanged(string $playerId, array $data): bool
    {
        return $this->withStateLock(function () use ($playerId, $data): bool {
            $registeredPlayers = $this->readJsonBuffer('RegisteredPlayers');
            if (!isset($registeredPlayers[$playerId])) {
                return false;
            }
            $allHints = $this->readJsonBuffer('QueueHints');
            $playerHints = is_array($allHints[$playerId] ?? null) ? $allHints[$playerId] : [];
            $changed = false;
            foreach (['synced_to', 'active_group', 'active_source', 'group_members'] as $field) {
                if (array_key_exists($field, $data)) {
                    if (!array_key_exists($field, $playerHints) || $playerHints[$field] !== $data[$field]) {
                        $changed = true;
                    }
                    $playerHints[$field] = $data[$field];
                }
            }
            $allHints[$playerId] = $playerHints;
            $this->writeJsonBuffer('QueueHints', $allHints);
            return $changed;
        });
    }

    private function invalidateQueueState(): void
    {
        $this->withStateLock(function (): void {
            $this->invalidateQueueStateFromState();
        });
    }

    private function invalidateQueueStateFromState(): void
    {
        $this->SetBuffer('RegisteredPlayers', '{}');
        $this->SetBuffer('PendingQueueRequests', '{}');
        $this->SetBuffer('PlayerQueues', '{}');
        $this->SetBuffer('QueuePlayers', '{}');
        $this->SetBuffer('QueueHints', '{}');
    }

    private function releaseResyncReservation(string $playerId, string $generation): void
    {
        try {
            $this->withStateLock(function () use ($playerId, $generation): void {
                if ($this->GetBuffer('ConnectionGeneration') !== $generation
                    || $this->GetBuffer('ResyncGeneration') !== $generation) {
                    return;
                }
                $resyncPlayers = $this->readJsonBuffer('ResyncPlayers');
                if (!is_array($resyncPlayers[$playerId] ?? null)) {
                    return;
                }
                $resyncPlayers[$playerId]['Starting'] = max(
                    0,
                    (int)($resyncPlayers[$playerId]['Starting'] ?? 1) - 1
                );
                $pending = $this->readJsonBuffer('PendingQueueRequests');
                $hasPendingRequest = false;
                foreach ($pending as $request) {
                    if (is_array($request)
                        && ($request['Generation'] ?? null) === $generation
                        && ($request['PlayerID'] ?? null) === $playerId) {
                        $hasPendingRequest = true;
                        break;
                    }
                }
                if ($resyncPlayers[$playerId]['Starting'] === 0
                    && !$hasPendingRequest
                    && ($resyncPlayers[$playerId]['Player'] ?? null) === null
                    && ($resyncPlayers[$playerId]['Queue'] ?? null) === null) {
                    unset($resyncPlayers[$playerId]);
                }
                $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);
            });
        } catch (Throwable $e) {
            if (!$this->isStateSemaphoreTimeout($e)) {
                $this->LogMessage('Resync-Reservierung: ' . $e->getMessage(), KL_ERROR);
                throw $e;
            }
            $this->SendDebug('Resync', 'Resync-Reservierung konnte nicht freigegeben werden: ' . $playerId, 0);
        }
    }

    private function tryRemovePendingRequest(string $messageId): void
    {
        try {
            $this->removePendingRequest($messageId);
        } catch (Throwable $e) {
            if (!$this->isStateSemaphoreTimeout($e)) {
                $this->LogMessage('Pending-Request-Bereinigung: ' . $e->getMessage(), KL_ERROR);
                throw $e;
            }
            $this->SendDebug('State', 'Pending Request konnte nicht bereinigt werden', 0);
        }
    }

    private function tryRemoveQueueMapping(string $playerId, string $generation): void
    {
        try {
            $this->removeQueueMapping($playerId, $generation);
        } catch (Throwable $e) {
            if (!$this->isStateSemaphoreTimeout($e)) {
                $this->LogMessage('Queue-Mapping-Bereinigung: ' . $e->getMessage(), KL_ERROR);
                throw $e;
            }
            $this->SendDebug('State', 'Queue-Zuordnung konnte nicht bereinigt werden: ' . $playerId, 0);
        }
    }

    private function tryCompleteResyncStep(
        string $playerId,
        string $step,
        bool $success,
        string $generation
    ): void
    {
        try {
            $this->completeResyncStep($playerId, $step, $success, $generation);
        } catch (Throwable $e) {
            if (!$this->isStateSemaphoreTimeout($e)) {
                $this->LogMessage('Resync-Status: ' . $e->getMessage(), KL_ERROR);
                throw $e;
            }
            $this->SendDebug('Resync', $step . '-Schritt konnte nicht gespeichert werden: ' . $playerId, 0);
        }
    }

    private function isCurrentGeneration(string $generation): bool
    {
        try {
            return $this->withStateLock(function () use ($generation): bool {
                return $this->GetBuffer('ConnectionGeneration') === $generation;
            });
        } catch (Throwable $e) {
            if (!$this->isStateSemaphoreTimeout($e)) {
                $this->LogMessage('Generationsprüfung: ' . $e->getMessage(), KL_ERROR);
                throw $e;
            }
            $this->SendDebug('State', 'Generation konnte vor Weiterleitung nicht geprüft werden', 0);
            return false;
        }
    }

    private function removePendingRequest(string $messageId): void
    {
        $this->withStateLock(function () use ($messageId): void {
            $pending = $this->readJsonBuffer('PendingQueueRequests');
            if (isset($pending[$messageId])) {
                unset($pending[$messageId]);
                $this->writeJsonBuffer('PendingQueueRequests', $pending);
            }
        });
    }

    private function removeQueueMappingFromState(
        string $playerId,
        array &$playerQueues,
        array &$queuePlayers
    ): bool {
        $oldQueueId = $playerQueues[$playerId] ?? null;
        if (!is_string($oldQueueId)) {
            return false;
        }
        unset($playerQueues[$playerId]);
        if (is_array($queuePlayers[$oldQueueId] ?? null)) {
            $queuePlayers[$oldQueueId] = array_values(array_filter(
                $queuePlayers[$oldQueueId],
                static fn($id): bool => $id !== $playerId
            ));
            if (count($queuePlayers[$oldQueueId]) === 0) {
                unset($queuePlayers[$oldQueueId]);
            }
        }
        return true;
    }

    private function stateSemaphoreName(): string
    {
        return 'MA_STATE_' . (string)$this->InstanceID;
    }

    private function isStateSemaphoreTimeout(Throwable $error): bool
    {
        return $error instanceof RuntimeException && $error->getCode() === self::STATE_SEMAPHORE_ERROR;
    }

    private function withStateLock(callable $operation)
    {
        if (!IPS_SemaphoreEnter($this->stateSemaphoreName(), self::STATE_SEMAPHORE_TIMEOUT)) {
            $this->SendDebug('State', 'Semaphore konnte nicht innerhalb des Zeitlimits erworben werden', 0);
            throw new RuntimeException(
                'Connection-State ist vorübergehend gesperrt.',
                self::STATE_SEMAPHORE_ERROR
            );
        }
        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($this->stateSemaphoreName());
        }
    }

    private function readJsonBuffer(string $name): array
    {
        $rawValue = trim($this->GetBuffer($name));
        if ($rawValue === '') {
            $this->SendDebug('State', 'JSON-Buffer ' . $name . ': Inhalt ist leer', 0);
            throw new UnexpectedValueException('JSON-Buffer ' . $name . ' ist nicht initialisiert.');
        }

        try {
            $rootValue = json_decode($rawValue, false, 512, JSON_THROW_ON_ERROR);
            if (is_object($rootValue)) {
                return json_decode($rawValue, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (JsonException $e) {
            $this->SendDebug('State', 'JSON-Buffer ' . $name . ': ungültiges JSON', 0);
            throw new UnexpectedValueException('JSON-Buffer ' . $name . ' enthält ungültiges JSON.', 0, $e);
        }

        if (is_array($rootValue) && count($rootValue) === 0) {
            return [];
        }

        $errorType = is_array($rootValue) ? 'nichtleeres JSON-Array' : 'skalarer JSON-Wert';
        $this->SendDebug('State', 'JSON-Buffer ' . $name . ': ' . $errorType, 0);
        throw new UnexpectedValueException('JSON-Buffer ' . $name . ' enthält einen unerwarteten Wurzeltyp.');
    }

    private function writeJsonBuffer(string $name, array $value): void
    {
        $this->SetBuffer($name, json_encode(count($value) === 0 ? new stdClass() : $value, JSON_THROW_ON_ERROR));
    }

    private function isConfigured(): bool
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        return $host !== '' && !preg_match('~[\s/@?#]~', $host)
            && $port > 0 && $port <= 65535 && trim($this->ReadPropertyString('Token')) !== '';
    }

    private function webSocketUrl(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        return sprintf('ws://%s:%d/ws', $host, $this->ReadPropertyInteger('Port'));
    }
}
