<?php

declare(strict_types=1);

class MusicAssistantPlayer extends IPSModule
{
    // Eigenschaften
    private $playerId = '';
    private $displayName = '';
    private $apiBaseUrl = '';
    private $apiToken = '';

    public function Create()
    {
        parent::Create();
        
        // Eigenschaften
        $this->RegisterPropertyString('PlayerID', '');
        $this->RegisterPropertyString('DisplayName', '');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');
        
        // Timer für regelmäßige Aktualisierung
        $this->RegisterTimer('UpdateStatus', 2000, 'MUSICP_UpdateStatus($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        // Eigenschaften auslesen
        $this->playerId = $this->ReadPropertyString('PlayerID');
        $this->displayName = $this->ReadPropertyString('DisplayName');
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $this->apiToken = $this->ReadPropertyString('Token');
        $this->apiBaseUrl = "http://$host:$port/api";
        
        // Variablen erstellen
        $this->CreateVariables();
        
        // Status aktualisieren
        $this->UpdateStatus();
    }

    private function CreateVariables()
    {
        // Statusvariablen
        $this->RegisterVariableString('CurrentTitle', 'Aktueller Titel', '~HTMLBox', 0);
        $this->RegisterVariableString('CurrentArtist', 'Künstler', '', 1);
        $this->RegisterVariableString('CurrentAlbum', 'Album', '', 2);
        $this->RegisterVariableString('CoverURL', 'Cover', '~HTMLBox', 3);
        
        // Steuerungsvariablen
        $this->RegisterVariableBoolean('Power', 'Power', '~Switch', 10);
        $this->EnableAction('Power');
        
        $this->RegisterVariableInteger('Volume', 'Lautstärke', '~Intensity.100', 11);
        $this->EnableAction('Volume');
        
        $this->RegisterVariableBoolean('Mute', 'Stumm', '~Switch', 12);
        $this->EnableAction('Mute');
        
        // Wiedergabesteuerung
        $this->RegisterVariableBoolean('PlayPause', 'Play/Pause', '~PlayPause', 20);
        $this->EnableAction('PlayPause');
        
        $this->RegisterVariableBoolean('Next', 'Nächster Titel', '~Next', 21);
        $this->EnableAction('Next');
        
        $this->RegisterVariableBoolean('Previous', 'Vorheriger Titel', '~Previous', 22);
        $this->EnableAction('Previous');
    }

    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'Power':
                $this->SetPower($value);
                break;
            case 'Volume':
                $this->SetVolume($value);
                break;
            case 'Mute':
                $this->SetMute($value);
                break;
            case 'PlayPause':
                $this->TogglePlayPause();
                break;
            case 'Next':
                $this->Next();
                break;
            case 'Previous':
                $this->Previous();
                break;
        }
    }

    public function UpdateStatus()
    {
        try {
            // Player-Status abrufen
            $playerStatus = $this->SendRequest('GET', 'players/' . $this->playerId . '/state');
            
            if ($playerStatus) {
                // Aktuelle Wiedergabe aktualisieren
                $this->UpdateNowPlaying($playerStatus);
                
                // Steuerungsvariablen aktualisieren
                $this->SetValue('Power', $playerStatus['powered']);
                $this->SetValue('Volume', $playerStatus['volume_level'] * 100);
                $this->SetValue('Mute', $playerStatus['muted']);
                $this->SetValue('PlayPause', $playerStatus['state'] === 'playing');
                
                $this->SetStatus(102); // OK
                return true;
            }
        } catch (Exception $e) {
            $this->SetStatus(202); // Fehler
            $this->SendDebug('UpdateStatus Error', $e->getMessage(), 0);
            return false;
        }
    }

    private function UpdateNowPlaying($status)
    {
        $currentTrack = $status['current_item'] ?? null;
        
        if ($currentTrack) {
            $title = $currentTrack['name'] ?? 'Unbekannter Titel';
            $artist = $currentTrack['artists'][0]['name'] ?? 'Unbekannter Künstler';
            $album = $currentTrack['album']['name'] ?? 'Unbekanntes Album';
            $coverUrl = $currentTrack['image_url'] ?? '';
            
            $this->SetValue('CurrentTitle', $title);
            $this->SetValue('CurrentArtist', $artist);
            $this->SetValue('CurrentAlbum', $album);
            
            // Cover anzeigen
            $coverHtml = $coverUrl 
                ? '<img src="' . $coverUrl . '" style="max-width: 100%; max-height: 200px;">'
                : 'Kein Cover verfügbar';
            $this->SetValue('CoverURL', $coverHtml);
            
            // Aktuellen Titel als HTML anzeigen
            $nowPlaying = '<div style="font-weight: bold; font-size: 14px;">' . htmlspecialchars($title) . '</div>';
            $nowPlaying .= '<div style="font-size: 12px;">' . htmlspecialchars($artist) . '</div>';
            $nowPlaying .= '<div style="font-size: 11px; color: #888;">' . htmlspecialchars($album) . '</div>';
            
            $this->SetValue('CurrentTitle', $nowPlaying);
        } else {
            $this->SetValue('CurrentTitle', 'Keine Wiedergabe');
            $this->SetValue('CurrentArtist', '');
            $this->SetValue('CurrentAlbum', '');
            $this->SetValue('CoverURL', 'Kein Titel ausgewählt');
        }
    }

    // Player-Steuerungsfunktionen
    public function SetPower(bool $power)
    {
        $this->SendRequest('POST', 'players/' . $this->playerId . '/power', ['power' => $power]);
        $this->UpdateStatus();
    }

    public function SetVolume(int $volume)
    {
        $volume = max(0, min(100, $volume)); // Auf 0-100% begrenzen
        $this->SendRequest('POST', 'players/' . $this->playerId . '/volume', [
            'volume_level' => $volume / 100
        ]);
    }

    public function SetMute(bool $mute)
    {
        $this->SendRequest('POST', 'players/' . $this->playerId . '/mute', ['mute' => $mute]);
    }

    public function TogglePlayPause()
    {
        $currentState = $this->GetValue('PlayPause');
        $this->SendRequest('POST', 'players/' . $this->playerId . '/play', ['play' => !$currentState]);
        $this->UpdateStatus();
    }

    public function Next()
    {
        $this->SendRequest('POST', 'players/' . $this->playerId . '/next');
        $this->UpdateStatus();
    }

    public function Previous()
    {
        $this->SendRequest('POST', 'players/' . $this->playerId . '/previous');
        $this->UpdateStatus();
    }

    // Hilfsfunktion für API-Anfragen
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

// Globale Funktion für den Player
function MUSICP_UpdateStatus($instanceId)
{
    $instance = new MusicAssistantPlayer($instanceId);
    return $instance->UpdateStatus();
}
