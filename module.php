<?php
declare(strict_types=1);

// Modul-Informationen
define('MUSIC_ASSISTANT_GUID', '{7F10CABF-4C5E-D431-8B4E-2528CEF4AE89}');
define('MUSIC_ASSISTANT_PREFIX', 'MUSIC');

class MusicAssistant extends IPSModule
{
    // API-Client-Initialisierung
    private $apiBaseUrl = '';
    private $apiToken = '';

    public function Create()
    {
        parent::Create();
        
        // Eigenschaften für die Konfiguration
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyString('PlayerInstances', '[]');
        
        // Timer für regelmäßige Aktualisierung
        $this->RegisterTimer('UpdateData', 5000, 'MUSIC_UpdateData($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        // API-URL setzen
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $this->apiBaseUrl = "http://$host:$port/api";
        $this->apiToken = $this->ReadPropertyString('Token');
        
        // Konfigurationsformular anzeigen
        $this->UpdateForm();
    }

    // ... (Rest der Methoden werden fortgesetzt)
    
    private function SendRequest($method, $endpoint, $data = null)
    {
        $url = $this->apiBaseUrl . '/' . ltrim($endpoint, '/');
        $ch = curl_init();
        
        $headers = ['Content-Type: application/json'];
        if (!empty($this->apiToken)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 5
        ]);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL Fehler: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Fehler $httpCode: " . $response);
        }
        
        return $response ? json_decode($response, true) : true;
    }
}

// Globale Funktionen
function MUSIC_UpdateData($instanceId)
{
    $instance = IPS_GetInstance($instanceId);
    if ($instance['InstanceStatus'] == 102) { // 102 = aktiv
        MA_UpdateStatus($instanceId);
    }
}

function MUSIC_SaveConfiguration($instanceId)
{
    $instance = IPS_GetInstance($instanceId);
    if ($instance['InstanceStatus'] == 102) { // 102 = aktiv
        IPS_ApplyChanges($instanceId);
    }
}

function MUSIC_TestConnection($instanceId)
{
    $instance = new MusicAssistant($instanceId);
    return $instance->TestConnection();
}

function MUSIC_UpdatePlayers($instanceId)
{
    $instance = new MusicAssistant($instanceId);
    $instance->UpdateForm();
}

function MUSIC_CreatePlayerInstance($instanceId, $playerData)
{
    $instance = new MusicAssistant($instanceId);
    return $instance->CreatePlayerInstance($playerData);
}
