<?php

declare(strict_types=1);

// Testersatz für den WebSocket Client der SymconStubs: zeichnet gesendete Nachrichten auf und
// liefert empfangene Nachrichten wie der Kernel an IPSModule-Kinder (UTF-8-kodierte Bytefolge).
class WebSocketClient extends IPSModule
{
    private $sentMessages = [];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('URL', '');
        $this->RegisterPropertyBoolean('Active', false);
    }

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (($data['DataID'] ?? null) !== '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}' || !is_string($data['Buffer'] ?? null)) {
            throw new Exception('Unsupported data received');
        }
        $this->sentMessages[] = mb_convert_encoding($data['Buffer'], 'ISO-8859-1', 'UTF-8');
        return '';
    }

    public function ReceiveText(string $Text)
    {
        $this->SendDataToChildren(json_encode([
            'DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}',
            'Buffer' => mb_convert_encoding($Text, 'UTF-8', 'ISO-8859-1')
        ]));
    }

    public function GetSentMessages()
    {
        return $this->sentMessages;
    }

    public function ClearSentMessages()
    {
        $this->sentMessages = [];
    }
}
