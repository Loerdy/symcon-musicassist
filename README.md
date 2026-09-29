# Music Assistant für IP-Symcon

Diese IP-Symcon-Library bindet einen [Music-Assistant](https://www.music-assistant.io/)-Server in IP-Symcon ein. Eine zentrale Connection übernimmt die Kommunikation; ein Configurator erkennt die verfügbaren Music-Assistant-Player und legt passende Player-Instanzen an.

## Voraussetzungen

- IP-Symcon ab Version 7.0
- ein erreichbarer Music-Assistant-Server
- Hostname oder IP-Adresse, Port und Zugriffstoken des Music-Assistant-Servers
- Netzwerkzugriff des IP-Symcon-Systems auf die HTTP-API und den WebSocket von Music Assistant

Eine bestimmte Music-Assistant-Version ist im Repository nicht festgelegt.

## Enthaltene Module

### MusicAssistant Connection

Die `MusicAssistant Connection` ist die zentrale Verbindung zu Music Assistant. Sie stellt für Configurator und Player folgende Funktionen bereit:

- authentifizierte HTTP/API-Anfragen
- authentifizierte WebSocket-Verbindung für Echtzeitereignisse
- Verarbeitung von `player_updated` und `queue_updated`
- Zuordnung von Playern zu ihren aktiven Queues
- automatische Wiederverbindung
- vollständige Resynchronisierung der Player nach erfolgreichem Verbindungsaufbau
- zentraler Abruf von Cover-Artwork über den Music-Assistant-Image-Proxy

Steuerbefehle laufen über die HTTP-API. Zustandsänderungen werden über den WebSocket empfangen und an die verbundenen Player-Instanzen verteilt.

### MusicAssistant Configurator

Der `Music Assistant Konfigurator` liest die Playerliste aus Music Assistant und zeigt Name, Player-ID, Provider, Verfügbarkeit und den Status der zugehörigen IP-Symcon-Instanz an.

Der native IP-Symcon-Configurator unterstützt:

- Erkennung normaler Player und Syncgroups
- Erkennung bereits vorhandener Player-Instanzen
- Erstellen einzelner oder aller noch fehlenden Player-Instanzen
- Öffnen der Konfiguration vorhandener Instanzen
- Ausblenden und erneutes Anzeigen bereits angelegter Player
- Löschen einer vorhandenen IP-Symcon-Player-Instanz

Beim Löschen wird nur die IP-Symcon-Instanz entfernt. Der Player in Music Assistant bleibt bestehen und kann anschließend erneut angelegt werden.

Über die Formularbuttons können außerdem Playlist- und Radio-Profile aus der Music-Assistant-Bibliothek erstellt beziehungsweise aktualisiert werden. Die verwendeten Profilnamen werden persistent verwaltet und an neu erzeugte Player weitergegeben.

### MusicAssistant Player

Eine `MusicAssistant Player`-Instanz repräsentiert einen normalen Music-Assistant-Player oder eine Syncgroup. Sie bietet Wiedergabesteuerung, Lautstärke, Mute, Shuffle, Repeat, Playlists, Radiosender sowie aktuelle Metadaten und Cover-Artwork.

Der Player ist mit seiner tatsächlichen `MusicAssistant Connection` verbunden. Beim Anlegen über den Configurator werden Player-ID, Connection und die Playlist-/Radio-Profile automatisch zugeordnet.

## Funktionen

- Previous, Stop, Play, Pause und Next
- Lautstärke von 0 bis 100 über einen Slider
- Gruppenlautstärke lauter/leiser
- Mute
- Shuffle
- Repeat mit `off`, `one` und `all`
- Wiedergabe synchronisierter Playlists
- Wiedergabe synchronisierter Radiosender
- Anzeige von Titel, Interpret und Album
- Cover-Artwork als IP-Symcon-Medienobjekt
- normale Player und Music-Assistant-Syncgroups
- WebSocket-basierte Echtzeitaktualisierung
- automatische vollständige Resynchronisierung nach erfolgreicher Neuverbindung

## Installation

Das Modul kann über die IP-Symcon-Modulverwaltung aus diesem GitHub-Repository installiert werden:

```text
https://github.com/Loerdy/symcon-musicassist.git
```

1. In IP-Symcon unter **Kern Instanzen → Modules** das Repository hinzufügen.
2. Den gewünschten Branch auswählen:
   - `main` für stabile, getestete Releases
   - `beta` für den aktuellen Entwicklungs- und Teststand
3. Die Module aktualisieren beziehungsweise installieren.

## Einrichtung

1. Eine Instanz **Music Assistant Connection** anlegen.
2. In deren Konfiguration die Felder **Server**, **Port** und **Token** ausfüllen und übernehmen. Der Standardport ist `8095`.
3. Eine Instanz **Music Assistant Konfigurator** anlegen und mit derselben `MusicAssistant Connection` verbinden.
4. Optional unter **Zielordner für neue Player** eine Kategorie auswählen.
5. In der Playerliste die von Music Assistant erkannten Player prüfen und die gewünschten Instanzen über die nativen Funktionen **Erstellen** oder **Alle erstellen** anlegen.
6. Bei Bedarf **Playlists laden (Profil erstellen/aktualisieren)** und **Radios laden (Profil erstellen/aktualisieren)** ausführen.

Neu angelegte Player werden direkt mit der Connection des Configurators verbunden.

## Player-Variablen

| Ident | Name | Typ | Bedienbar | Funktion |
|---|---|---:|:---:|---|
| `Playlist` | Playlist | Integer | ja | Auswahl einer synchronisierten Playlist |
| `Radio` | Radio | Integer | ja | Auswahl eines synchronisierten Radiosenders |
| `Transport` | Wiedergabe | Integer | ja | Previous, Stop, Play, Pause und Next |
| `Shuffle` | Shuffle | Boolean | ja | Zufallswiedergabe ein- oder ausschalten |
| `Repeat` | Repeat | String | ja | Wiederholmodus `off`, `one` oder `all` |
| `Mute` | Mute | Boolean | ja | Stummschaltung |
| `VolumeLevel` | Volume | Integer | ja | Lautstärke 0 bis 100 |
| `VolumeUp` | Volume + | Integer | ja | Gruppenlautstärke erhöhen |
| `VolumeDown` | Volume | Integer | ja | Gruppenlautstärke verringern |
| `NowTitle` | Titel | String | nein | Titel des aktuellen Queue-Eintrags |
| `NowArtist` | Interpret | String | nein | Interpret des aktuellen Queue-Eintrags |
| `NowAlbum` | Album | String | nein | Album des aktuellen Queue-Eintrags |

Zusätzlich legt der Player ein Bild-Medienobjekt mit dem Ident `Cover` an.

## Syncgroups

Normale Player und Syncgroups werden unterstützt. Die aktive Queue wird unabhängig von der Player-ID korrekt zugeordnet, sodass Wiedergabestatus und Metadaten auch bei Gruppen synchron bleiben.

Bei Syncgroups verwendet das Modul die von Music Assistant bereitgestellte Gruppenlautstärke, sofern für die Gruppe keine eigene Lautstärke gemeldet wird. Die Gruppenlautstärke kann außerdem über die Schaltflächen für lauter und leiser gesteuert werden.

## Skriptbefehle

`$InstanceID` ist jeweils die ID der passenden Player- beziehungsweise Configurator-Instanz. Alle öffentlichen Befehle verwenden das einheitliche Modulpräfix `MASS`.

### Player

```php
MASS_SetShuffle($InstanceID, bool $enabled);
MASS_SetRepeat($InstanceID, string $mode);
MASS_SetMute($InstanceID, bool $muted);
MASS_SetVolumeLevel($InstanceID, int $level);
MASS_GroupVolumeUp($InstanceID);
MASS_GroupVolumeDown($InstanceID);
```

Für `MASS_SetRepeat()` sind die Werte `off`, `one` und `all` vorgesehen. Für `MASS_SetVolumeLevel()` muss der aufrufende Code einen Wert von `0` bis `100` übergeben; der öffentliche Befehl begrenzt den Wert nicht selbst.

Beispiel:

```php
$playerId = 12345;

MASS_SetVolumeLevel($playerId, 35);
MASS_SetMute($playerId, false);
MASS_SetShuffle($playerId, true);
MASS_SetRepeat($playerId, 'all');
MASS_GroupVolumeUp($playerId);
```

### Configurator

```php
MASS_SyncPlaylistsProfile($InstanceID);
MASS_SyncRadiosProfile($InstanceID);
```

Beispiel:

```php
$configuratorId = 23456;

MASS_SyncPlaylistsProfile($configuratorId);
MASS_SyncRadiosProfile($configuratorId);
```

## Statusaktualisierung

Steuerbefehle werden über die HTTP-API an Music Assistant gesendet. Statusänderungen empfängt die Connection über den WebSocket und gibt sie an die Player-Instanzen weiter. Dazu gehören insbesondere `player_updated` für Playerzustände und `queue_updated` für Wiedergabe, Metadaten und Cover.

Nach jedem erfolgreichen Verbindungsaufbau werden der aktuelle Playerzustand und die aktive Queue vollständig neu eingelesen. Ein periodisches Player-State-Polling wird nicht verwendet.

## Cover

Das Cover des aktuellen Titels wird von Music Assistant geladen und als IP-Symcon-Medienobjekt bereitgestellt.

- Bei Wiedergabe und Pause bleibt das Cover sichtbar.
- Im Zustand `idle` wird es ausgeblendet; nicht mehr aktuelle Wiedergabemetadaten werden entfernt.
- Ist für den aktuellen Titel kein Cover verfügbar, wird kein veraltetes Bild angezeigt.

## Branches

| Branch | Zweck |
|---|---|
| `main` | Stabile, getestete Releases |
| `beta` | Entwicklungs- und Teststand |

## Versionierung

Der Tag [`v1.0.0`](https://github.com/Loerdy/symcon-musicassist/tree/v1.0.0) kennzeichnet den ersten veröffentlichten Stable-Stand.
