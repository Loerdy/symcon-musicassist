<?php
declare(strict_types=1);

class MusicAssistantConnection extends IPSModule
{
    private const WEBSOCKET_MODULE = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const WEBSOCKET_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const WEBSOCKET_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '127.0.0.1');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');
        $this->SetBuffer('GreetingUrl', '');
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
        $this->SetBuffer('ConnectionGeneration', '0');
        $this->SetBuffer('ParentInstanceId', '0');
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
        $this->SetStatus($this->isConfigured() ? 104 : 201);
        $this->SetTimerInterval('CheckConnection', $this->isConfigured() ? 5000 : 0);

        // Ein Tokenwechsel braucht bei unverändertem, verbundenem Parent keine neue Begrüßung.
        if ($this->isConfigured() && $this->HasActiveParent()
            && $this->GetBuffer('GreetingUrl') === $this->webSocketUrl()
            && $this->GetBuffer('AuthMessageId') === '' && $this->GetStatus() !== 102) {
            $this->authenticate();
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
                ['type' => 'Label', 'caption' => 'Phase 1: Anmeldung am WebSocket; keine Weiterleitung an Player.']
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
        $message = json_decode($packet['Buffer'], true);
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
            $this->SetStatus(104);
            $this->SendDebug('WebSocket', 'Server-Begrüßung empfangen', 0);
            $this->authenticate();
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
            $this->SetStatus(102);
            $this->SendDebug('Authentication', 'Authentifizierung bestätigt', 0);
        } else {
            $this->SetStatus(202);
            $this->SendDebug('Authentication', 'Authentifizierung abgelehnt', 0);
        }
        return '';
    }

    public function ForwardData($JSONString): string
    {
        // Die interne Request-Schnittstelle ist reserviert, aber noch nicht freigeschaltet.
        return json_encode(['success' => false, 'error' => 'Phase 1: keine Child-Anfragen unterstützt']);
    }

    public function CheckConnection(): void
    {
        if (!$this->isConfigured() || !$this->HasActiveParent()) {
            $this->handleConnectionLost();
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
    }

    private function authenticate(): void
    {
        $this->SetBuffer('AuthMessageId', '');
        $this->SetBuffer('AuthGeneration', '');
        $this->SetBuffer('AuthStarted', '0');
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
        $this->SetStatus($this->isConfigured() ? 104 : 201);

        if ($wasConnected) {
            $this->SendDebug('WebSocket', 'Verbindung verloren; Sitzung invalidiert', 0);
        }
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
