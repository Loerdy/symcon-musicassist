# Music Assistant for IP-Symcon

[Deutsch](#music-assistant-für-ip-symcon) | [English](#music-assistant-for-ip-symcon)

Diese IP-Symcon-Library bindet einen [Music-Assistant](https://www.music-assistant.io/)-Server in IP-Symcon ein. Eine zentrale Connection übernimmt die Kommunikation. Der Configurator erkennt die verfügbaren Music-Assistant-Player und legt passende Player-Instanzen an.

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

---

# Music Assistant for IP-Symcon

[Deutsch](#music-assistant-für-ip-symcon) | [English](#music-assistant-for-ip-symcon)

This IP-Symcon library integrates a [Music Assistant](https://www.music-assistant.io/) server with IP-Symcon. A central connection handles communication. The configurator discovers the available Music Assistant players and creates the corresponding player instances.

## Requirements

- IP-Symcon version 7.0 or later
- an accessible Music Assistant server
- the hostname or IP address, port, and access token of the Music Assistant server
- network access from the IP-Symcon system to the Music Assistant HTTP API and WebSocket

The repository does not specify a particular Music Assistant version.

## Included Modules

### MusicAssistant Connection

The `MusicAssistant Connection` is the central connection to Music Assistant. It provides the following functions for the configurator and player instances:

- authenticated HTTP/API requests
- an authenticated WebSocket connection for real-time events
- processing of `player_updated` and `queue_updated`
- mapping players to their active queues
- automatic reconnection
- full player resynchronization after a successful connection
- centralized retrieval of cover artwork through the Music Assistant image proxy

Control commands are sent through the HTTP API. Status changes are received through the WebSocket and forwarded to the connected player instances.

### MusicAssistant Configurator

The `MusicAssistant Configurator` retrieves the player list from Music Assistant and displays the name, PlayerID, provider, availability, and status of the corresponding IP-Symcon instance.

The native IP-Symcon configurator supports:

- discovery of regular players and sync groups
- detection of existing player instances
- creation of individual player instances or all missing instances
- opening the configuration of existing instances
- hiding and showing existing players
- deletion of an existing IP-Symcon player instance

Deleting an instance removes only the IP-Symcon instance. The player remains in Music Assistant and can be created again afterward.

The form buttons can also create or update playlist and radio profiles from the Music Assistant library. The profile names are stored persistently and passed to newly created players.

### MusicAssistant Player

A `MusicAssistant Player` instance represents a regular Music Assistant player or a sync group. It provides playback controls, volume, mute, shuffle, repeat, playlists, radio stations, current metadata, and cover artwork.

The player is connected to its actual `MusicAssistant Connection`. When a player is created through the configurator, its PlayerID, connection, and playlist/radio profiles are assigned automatically.

## Features

- Previous, Stop, Play, Pause, and Next
- volume from 0 to 100 using a slider
- group volume up and down
- mute
- shuffle
- repeat modes `off`, `one`, and `all`
- playback of synchronized playlists
- playback of synchronized radio stations
- display of title, artist, and album
- cover artwork as an IP-Symcon media object
- regular players and Music Assistant sync groups
- WebSocket-based real-time updates
- automatic full resynchronization after a successful reconnection

## Installation

The module can be installed from this GitHub repository through the IP-Symcon module management:

```text
https://github.com/Loerdy/symcon-musicassist.git
```

1. In IP-Symcon, open **Core Instances → Modules** and add the repository.
2. Select the desired branch:
   - `main` for stable, tested releases
   - `beta` for the current development and testing version
3. Update or install the modules.

## Setup

1. Create a **Music Assistant Connection** instance.
2. Enter **Server**, **Port**, and **Token** in its configuration and apply the changes. The default port is `8095`.
3. Create a **MusicAssistant Configurator** instance and connect it to the same `MusicAssistant Connection`.
4. Optionally select a category under **Target folder for new players** (`Zielordner für neue Player`).
5. Review the players discovered by Music Assistant and use the native **Create** or **Create all** functions to create the desired instances.
6. If required, run **Load playlists (create/update profile)** and **Load radio stations (create/update profile)**.

New player instances are connected directly to the configurator's connection.

## Player Variables

| Ident | Name | Type | Controllable | Function |
|---|---|---:|:---:|---|
| `Playlist` | Playlist | Integer | yes | Select a synchronized playlist |
| `Radio` | Radio | Integer | yes | Select a synchronized radio station |
| `Transport` | Wiedergabe | Integer | yes | Previous, Stop, Play, Pause, and Next |
| `Shuffle` | Shuffle | Boolean | yes | Enable or disable shuffle |
| `Repeat` | Repeat | String | yes | Repeat mode `off`, `one`, or `all` |
| `Mute` | Mute | Boolean | yes | Mute control |
| `VolumeLevel` | Volume | Integer | yes | Volume from 0 to 100 |
| `VolumeUp` | Volume + | Integer | yes | Increase group volume |
| `VolumeDown` | Volume | Integer | yes | Decrease group volume |
| `NowTitle` | Titel | String | no | Title of the current queue item |
| `NowArtist` | Interpret | String | no | Artist of the current queue item |
| `NowAlbum` | Album | String | no | Album of the current queue item |

The player also creates an image media object with the ident `Cover`.

## Sync Groups

Regular players and sync groups are supported. The active queue is mapped independently of the PlayerID, keeping playback status and metadata synchronized for groups as well.

For sync groups, the module uses the group volume provided by Music Assistant when the group does not report its own volume. Group volume can also be adjusted using the volume up and down buttons.

## Script Commands

`$InstanceID` is the ID of the corresponding player or configurator instance. All public commands use the common module prefix `MASS`.

### Player

```php
MASS_SetShuffle($InstanceID, bool $enabled);
MASS_SetRepeat($InstanceID, string $mode);
MASS_SetMute($InstanceID, bool $muted);
MASS_SetVolumeLevel($InstanceID, int $level);
MASS_GroupVolumeUp($InstanceID);
MASS_GroupVolumeDown($InstanceID);
```

`MASS_SetRepeat()` supports the values `off`, `one`, and `all`. The calling code must pass a value from `0` to `100` to `MASS_SetVolumeLevel()`; the public command does not clamp the value itself.

Example:

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

Example:

```php
$configuratorId = 23456;

MASS_SyncPlaylistsProfile($configuratorId);
MASS_SyncRadiosProfile($configuratorId);
```

## Status Updates

Control commands are sent to Music Assistant through the HTTP API. The connection receives status changes through the WebSocket and forwards them to the player instances. These include `player_updated` for player states and `queue_updated` for playback, metadata, and cover artwork.

After every successful connection, the current player state and active queue are read again in full. Periodic player-state polling is not used.

## Cover Artwork

The cover artwork for the current track is loaded from Music Assistant and provided as an IP-Symcon media object.

- The cover remains visible during playback and while paused.
- In the `idle` state, it is hidden and stale playback metadata is removed.
- If no cover is available for the current track, no stale image is displayed.

## Branches

| Branch | Purpose |
|---|---|
| `main` | Stable, tested releases |
| `beta` | Development and testing version |

## Versioning

The [`v1.0.0`](https://github.com/Loerdy/symcon-musicassist/tree/v1.0.0) tag marks the first published stable version.
