# MusicAssistant für IP-Symcon

Dieses Modul verbindet IP-Symcon mit MusicAssistant über die Web-API.

## Funktionen

- Steuerung von MusicAssistant-Playern
- Wiedergabe von Playlisten
- Anzeige des aktuellen Titels
- Lautstärkeregelung
- Play/Pause/Weiter/Überspringen

## Installation

1. **Modul herunterladen**
   ```bash
   cd /usr/ipsymcon/modules/
   git clone https://github.com/Loerdy/symcon-musicassist.git
   ```

2. **In IP-Symcon einbinden**
   - IP-Symcon Konsole öffnen
   - Unter "Module hinzufügen" nach "MusicAssistant" suchen
   - Modul installieren

3. **Konfiguration**
   - Neue Instanz des MusicAssistant-Moduls erstellen
   - Host (IP-Adresse des MusicAssistant-Servers) eintragen
   - Port (Standard: 8095) anpassen falls nötig
   - Optional: API-Token eintragen

## Verwendung

1. **Player-Instanzen erstellen**
   - Im Konfigurationsformular werden verfügbare Player angezeigt
   - Mit "Instanz erstellen" werden Player-Instanzen angelegt

2. **Steuerung**
   - Jede Player-Instanz enthält Steuerelemente für Wiedergabe
   - Playlisten werden als Buttons dargestellt
   - Aktueller Titel und Cover werden angezeigt

## Fehlerbehebung

- **Keine Verbindung möglich**
  - Prüfe die Netzwerkverbindung
  - Firewall-Einstellungen überprüfen
  - Logs in IP-Symcon einsehen

- **Player werden nicht angezeigt**
  - MusicAssistant-Server läuft?
  - API-Zugriff aktiviert?
  - Korrekte IP/Port eingetragen?

## Lizenz

[GNU General Public License v3.0](LICENSE)
