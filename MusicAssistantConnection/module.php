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
    private const MAX_ARTWORK_SIZE = 5242880;
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
        'music/radios/library_items'
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
        $this->SetBuffer('ConnectionGeneration', '0');
        $this->SetBuffer('ParentInstanceId', '0');
        $this->SetBuffer('ParentReconnectPending', '0');
        $this->SetBuffer('ParentReconnectAttempted', '0');
        $this->SetBuffer('RegisteredPlayers', '{}');
        $this->SetBuffer('PendingQueueRequests', '{}');
        $this->SetBuffer('PlayerQueues', '{}');
        $this->SetBuffer('QueuePlayers', '{}');
        $this->SetBuffer('QueueHints', '{}');
        $this->SetBuffer('ResyncGeneration', '');
        $this->SetBuffer('ResyncPlayers', '{}');
        $this->RegisterTimer('CheckConnection', 0, 'MAC_CheckConnection($_IPS["TARGET"]);');
        $this->RequireParent(self::WEBSOCKET_MODULE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->registerParentStatusMessage();
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->invalidateQueueState();
        $this->SetStatus($this->isConfigured() ? 104 : 201);
        $this->SetTimerInterval('CheckConnection', $this->isConfigured() ? 5000 : 0);

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
            $generation = (int)$this->GetBuffer('ConnectionGeneration') + 1;
            $this->SetBuffer('ConnectionGeneration', (string)$generation);
            $this->SetBuffer('GreetingUrl', $this->webSocketUrl());
            $this->SetBuffer('AuthMessageId', '');
            $this->SetBuffer('AuthGeneration', '');
            $this->SetBuffer('AuthStarted', '0');
            $this->invalidateQueueState();
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
                if ($this->isPlayerRegistered($objectId)
                    && $this->queueRelevantPlayerDataChanged($objectId, $data)) {
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
                $queuePlayers = $this->readJsonBuffer('QueuePlayers');
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
        if (!array_key_exists('error_code', $message) && !array_key_exists('error', $message)
            && ($message['result']['authenticated'] ?? null) === true) {
            $this->prepareResyncGeneration();
            $this->SetStatus(102);
            $this->SendDebug('Authentication', 'Authentifizierung bestätigt', 0);
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

        $players = $this->readJsonBuffer('RegisteredPlayers');
        $isNew = !isset($players[$playerId]);
        $players[$playerId] = true;
        $this->writeJsonBuffer('RegisteredPlayers', $players);
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

        try {
            $proxyId = $request['ProxyID'];
            $response = $this->downloadArtwork($proxyId, false);
            if (in_array($response['http_code'], [401, 403], true)
                && trim($this->ReadPropertyString('Token')) !== '') {
                $response = $this->downloadArtwork($proxyId, true);
            }
            if ($response['http_code'] !== 200
                || $response['content_type'] !== 'image/jpeg'
                || $response['body'] === ''
                || strlen($response['body']) > self::MAX_ARTWORK_SIZE
                || substr($response['body'], 0, 3) !== "\xFF\xD8\xFF") {
                $this->SendDebug('Artwork', 'Imageproxy lieferte kein gültiges JPEG', 0);
                return $this->apiErrorResponse('IMAGE_ERROR', 'Cover konnte nicht geladen werden.');
            }

            $this->SendDebug('Artwork', 'Cover über Imageproxy geladen', 0);
            return json_encode([
                'success'      => true,
                'proxy_id'     => $proxyId,
                'content_type' => 'image/jpeg',
                'content'      => base64_encode($response['body'])
            ], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            $this->SendDebug('Artwork', 'Imageproxy-Anfrage fehlgeschlagen', 0);
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
        $headers = ['Accept: image/jpeg'];
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . trim($this->ReadPropertyString('Token'));
        }

        if (!IPS_SemaphoreEnter($this->maSemaphoreName(), 15000)) {
            throw new Exception('MA API semaphore timeout');
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
            curl_close($ch);
        } finally {
            IPS_SemaphoreLeave($this->maSemaphoreName());
        }

        if ($transferSucceeded === false || $sizeExceeded) {
            throw new Exception('Imageproxy-Anfrage fehlgeschlagen.');
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
        if ($started > 0 && time() - $started >= 15) {
            $this->SetBuffer('AuthMessageId', '');
            $this->SetBuffer('AuthGeneration', '');
            $this->SetBuffer('AuthStarted', '0');
            $this->SetStatus(203);
            $this->SendDebug('Authentication', 'Keine Bestätigung innerhalb von 15 Sekunden', 0);
        }
        $this->expireQueueRequests();
    }

    private function authenticate(): void
    {
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->invalidateQueueState();
        if (!$this->isConfigured()) {
            $this->SetStatus(201);
            return;
        }
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
        }
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
        foreach (array_keys($this->readJsonBuffer('RegisteredPlayers')) as $playerId) {
            if (is_string($playerId) && $playerId !== '') {
                $this->startPlayerResync($playerId);
            }
        }
    }

    private function isPlayerRegistered(string $playerId): bool
    {
        return isset($this->readJsonBuffer('RegisteredPlayers')[$playerId]);
    }

    private function startPlayerResync(string $playerId): void
    {
        $generation = $this->GetBuffer('ConnectionGeneration');
        if ($this->GetStatus() !== 102 || $generation === ''
            || $this->GetBuffer('ResyncGeneration') !== $generation
            || !$this->isPlayerRegistered($playerId)) {
            return;
        }

        $resyncPlayers = $this->readJsonBuffer('ResyncPlayers');
        if (isset($resyncPlayers[$playerId])) {
            return;
        }
        $resyncPlayers[$playerId] = ['Player' => null, 'Queue' => null];
        $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);
        $this->SendDebug('Resync', 'Start für Player: ' . $playerId, 0);
        $this->requestPlayerSnapshot($playerId);
        $this->requestActiveQueue($playerId, true);
    }

    private function requestPlayerSnapshot(string $playerId): void
    {
        $generation = $this->GetBuffer('ConnectionGeneration');
        $messageId = '';
        try {
            $messageId = 'player-' . bin2hex(random_bytes(16));
            $pending = $this->readJsonBuffer('PendingQueueRequests');
            $pending[$messageId] = [
                'Generation' => $generation,
                'Type' => 'GetPlayerSnapshot',
                'PlayerID' => $playerId,
                'Started' => time()
            ];
            $this->writeJsonBuffer('PendingQueueRequests', $pending);
            $this->sendWebSocketCommand($messageId, 'players/get', ['player_id' => $playerId]);
        } catch (Throwable $e) {
            $pending = $this->readJsonBuffer('PendingQueueRequests');
            if ($messageId !== '') {
                unset($pending[$messageId]);
                $this->writeJsonBuffer('PendingQueueRequests', $pending);
            }
            $this->completeResyncStep($playerId, 'Player', false);
        }
    }

    private function requestActiveQueue(string $playerId, bool $resync = false): void
    {
        $players = $this->readJsonBuffer('RegisteredPlayers');
        $generation = $this->GetBuffer('ConnectionGeneration');
        if ($this->GetStatus() !== 102 || !isset($players[$playerId]) || $generation === '') {
            return;
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
                return;
            }
        }

        $messageId = '';
        try {
            $messageId = 'queue-' . bin2hex(random_bytes(16));
            $pending[$messageId] = [
                'Generation' => $generation,
                'Type' => $resync ? 'GetActiveQueueResync' : 'GetActiveQueue',
                'PlayerID' => $playerId,
                'Started' => time()
            ];
            $this->writeJsonBuffer('PendingQueueRequests', $pending);
            $this->sendWebSocketCommand(
                $messageId,
                'player_queues/get_active_queue',
                ['player_id' => $playerId]
            );
            $this->SendDebug('Active Queue', 'Angefordert: ' . $playerId, 0);
        } catch (Throwable $e) {
            if ($messageId !== '') {
                unset($pending[$messageId]);
            }
            $this->writeJsonBuffer('PendingQueueRequests', $pending);
            $this->removeQueueMapping($playerId);
            $this->SendDebug('Active Queue', 'Anfrage fehlgeschlagen: ' . $playerId, 0);
            if ($resync) {
                $this->completeResyncStep($playerId, 'Queue', false);
            }
        }
    }

    private function handleQueueResponse(string $messageId, array $message): bool
    {
        $pending = $this->readJsonBuffer('PendingQueueRequests');
        $request = $pending[$messageId] ?? null;
        if (!is_array($request)) {
            return false;
        }
        unset($pending[$messageId]);
        $this->writeJsonBuffer('PendingQueueRequests', $pending);

        $playerId = $request['PlayerID'] ?? null;
        $type = $request['Type'] ?? null;
        if (!is_string($playerId)
            || ($request['Generation'] ?? null) !== $this->GetBuffer('ConnectionGeneration')) {
            return true;
        }

        $result = $message['result'] ?? null;
        $hasError = array_key_exists('error_code', $message) || array_key_exists('error', $message);
        if ($type === 'GetPlayerSnapshot') {
            if ($hasError || !is_array($result) || ($result['player_id'] ?? null) !== $playerId) {
                $this->SendDebug('Resync', 'Ungültiger Player-Snapshot: ' . $playerId, 0);
                $this->completeResyncStep($playerId, 'Player', false);
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
            $this->completeResyncStep($playerId, 'Player', true);
            return true;
        }

        if (!in_array($type, ['GetActiveQueue', 'GetActiveQueueResync'], true)) {
            return true;
        }
        $isResync = $type === 'GetActiveQueueResync';
        if ($hasError || !$this->isValidPlayerQueue($result)) {
            $this->removeQueueMapping($playerId);
            $this->SendDebug('Active Queue', 'Keine gültige Queue für: ' . $playerId, 0);
            if ($isResync) {
                $this->completeResyncStep($playerId, 'Queue', false);
            }
            return true;
        }

        $queueId = trim($result['queue_id']);
        $this->setQueueMapping($playerId, $queueId);
        if ($isResync) {
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
            $this->completeResyncStep($playerId, 'Queue', true);
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
        $generation = $this->GetBuffer('ConnectionGeneration');
        if ($this->GetBuffer('ResyncGeneration') !== $generation) {
            $this->SetBuffer('ResyncGeneration', $generation);
            $this->SetBuffer('ResyncPlayers', '{}');
        }
    }

    private function completeResyncStep(string $playerId, string $step, bool $success): void
    {
        $resyncPlayers = $this->readJsonBuffer('ResyncPlayers');
        if (!is_array($resyncPlayers[$playerId] ?? null)
            || !array_key_exists($step, $resyncPlayers[$playerId])) {
            return;
        }
        $resyncPlayers[$playerId][$step] = $success;
        $this->writeJsonBuffer('ResyncPlayers', $resyncPlayers);
        if (!$success) {
            $this->SendDebug('Resync', $step . '-Schritt fehlgeschlagen: ' . $playerId, 0);
        }
        if ($resyncPlayers[$playerId]['Player'] === null
            || $resyncPlayers[$playerId]['Queue'] === null) {
            return;
        }
        if ($resyncPlayers[$playerId]['Player'] === true
            && $resyncPlayers[$playerId]['Queue'] === true) {
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

    private function setQueueMapping(string $playerId, string $queueId): void
    {
        $this->removeQueueMapping($playerId);
        $playerQueues = $this->readJsonBuffer('PlayerQueues');
        $queuePlayers = $this->readJsonBuffer('QueuePlayers');
        $playerQueues[$playerId] = $queueId;
        $queuePlayers[$queueId] = array_values(array_unique(array_merge(
            is_array($queuePlayers[$queueId] ?? null) ? $queuePlayers[$queueId] : [],
            [$playerId]
        )));
        $this->writeJsonBuffer('PlayerQueues', $playerQueues);
        $this->writeJsonBuffer('QueuePlayers', $queuePlayers);
        $this->SendDebug('Active Queue', $playerId . ' -> ' . $queueId, 0);
    }

    private function removeQueueMapping(string $playerId): void
    {
        $playerQueues = $this->readJsonBuffer('PlayerQueues');
        $oldQueueId = $playerQueues[$playerId] ?? null;
        if (!is_string($oldQueueId)) {
            return;
        }
        unset($playerQueues[$playerId]);
        $queuePlayers = $this->readJsonBuffer('QueuePlayers');
        if (is_array($queuePlayers[$oldQueueId] ?? null)) {
            $queuePlayers[$oldQueueId] = array_values(array_filter(
                $queuePlayers[$oldQueueId],
                static fn($id): bool => $id !== $playerId
            ));
            if (count($queuePlayers[$oldQueueId]) === 0) {
                unset($queuePlayers[$oldQueueId]);
            }
        }
        $this->writeJsonBuffer('PlayerQueues', $playerQueues);
        $this->writeJsonBuffer('QueuePlayers', $queuePlayers);
        $this->SendDebug('Active Queue', 'Queue-Zuordnung entfernt: ' . $playerId, 0);
    }

    private function unregisterPlayer(string $playerId): void
    {
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
        $this->removeQueueMapping($playerId);
        $this->SendDebug('Registration', 'Player abgemeldet: ' . $playerId, 0);
    }

    private function expireQueueRequests(): void
    {
        $pending = $this->readJsonBuffer('PendingQueueRequests');
        $changed = false;
        foreach ($pending as $messageId => $request) {
            if (!is_array($request) || (int)($request['Started'] ?? 0) <= time() - 15) {
                if (is_array($request) && is_string($request['PlayerID'] ?? null)) {
                    $playerId = $request['PlayerID'];
                    $type = $request['Type'] ?? null;
                    if ($type === 'GetPlayerSnapshot') {
                        $this->SendDebug('Resync', 'Player-Snapshot Zeitüberschreitung: ' . $playerId, 0);
                        $this->completeResyncStep($playerId, 'Player', false);
                    } else {
                        $this->removeQueueMapping($playerId);
                        $this->SendDebug('Active Queue', 'Zeitüberschreitung: ' . $playerId, 0);
                        if ($type === 'GetActiveQueueResync') {
                            $this->completeResyncStep($playerId, 'Queue', false);
                        }
                    }
                }
                unset($pending[$messageId]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->writeJsonBuffer('PendingQueueRequests', $pending);
        }
    }

    private function queueRelevantPlayerDataChanged(string $playerId, array $data): bool
    {
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
    }

    private function invalidateQueueState(): void
    {
        $this->SetBuffer('RegisteredPlayers', '{}');
        $this->SetBuffer('PendingQueueRequests', '{}');
        $this->SetBuffer('PlayerQueues', '{}');
        $this->SetBuffer('QueuePlayers', '{}');
        $this->SetBuffer('QueueHints', '{}');
    }

    private function readJsonBuffer(string $name): array
    {
        $value = json_decode($this->GetBuffer($name), true);
        return is_array($value) ? $value : [];
    }

    private function writeJsonBuffer(string $name, array $value): void
    {
        $this->SetBuffer($name, json_encode($value, JSON_THROW_ON_ERROR));
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
