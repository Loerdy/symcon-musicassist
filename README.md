# Music Assistant für IP-Symcon

[Deutsch](#music-assistant-für-ip-symcon) | [English](#music-assistant-for-ip-symcon)

Diese IP-Symcon-Library bindet einen [Music-Assistant](https://www.music-assistant.io/)-Server in IP-Symcon ein. Eine zentrale Connection übernimmt die Kommunikation. Der Configurator erkennt die verfügbaren Music-Assistant-Player und legt passende Player-Instanzen an.

## Voraussetzungen

- IP-Symcon ab Version 8.2 (mit dieser Version wird das Modul entwickelt und getestet)
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

Im Bereich **Diagnose** der Connection steht ein Bericht mit den Versionen von Library, IP-Symcon, PHP und Music Assistant, dem Verbindungsstatus und den verbundenen Player-Instanzen. Er enthält weder Token, Adressen noch Player-Namen oder -IDs und kann für Fehlermeldungen direkt in einen Forumsbeitrag kopiert werden.

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

Über die Formularbuttons können außerdem Playlist- und Radio-Profile aus der Music-Assistant-Bibliothek erstellt beziehungsweise aktualisiert werden. Die verwendeten Profilnamen werden persistent verwaltet und an neu erzeugte Player weitergegeben. Bestehende Player werden dabei nicht verändert: Sie übernehmen neue Einträge direkt aus dem Profil, ermitteln die zugehörige Playlist bzw. den Sender beim Abspielen selbst aus Music Assistant und aktualisieren ihre Kachel nach dem Laden automatisch.

### MusicAssistant Player

Eine `MusicAssistant Player`-Instanz repräsentiert einen normalen Music-Assistant-Player oder eine Syncgroup. Sie bietet Wiedergabesteuerung, Lautstärke, Mute, Shuffle, Repeat, Playlists, Radiosender sowie aktuelle Metadaten und Cover-Artwork.

Der Player ist mit seiner tatsächlichen `MusicAssistant Connection` verbunden. Beim Anlegen über den Configurator werden Player-ID, Connection und die Playlist-/Radio-Profile automatisch zugeordnet.

### MusicAssistant Shortcut

Eine `MusicAssistant Shortcut`-Instanz (Schnellzugriff) stellt eine Kachel mit einem oder mehreren Buttons bereit. Ein Tippen auf einen Button startet den hinterlegten Radiosender bzw. die hinterlegte Playlist auf dem gewählten Player.

- Als Player wird eine `MusicAssistant Player`-Instanz gewählt. Sie muss an derselben `MusicAssistant Connection` hängen wie der Schnellzugriff; andernfalls zeigt die Instanz einen Fehlerstatus und startet nichts.
- Die Einträge stammen aus den Playlist- und Radio-Profilen des gewählten Players, die der Configurator pflegt. Nach dem Wechsel des Players stehen dessen Einträge sofort zur Auswahl.
- Eine optionale Beschriftung ersetzt den Namen aus dem Profil.
- Über „Beschriftung anzeigen“ lässt sich die Beschriftung je Eintrag ausblenden, etwa wenn der Name bereits im Logo steht. Das Bild füllt den Button dann bis zum abgerundeten Rand. Ohne Bild und bei nicht verfügbaren Einträgen bleibt die Beschriftung immer sichtbar.
- Mit einem Eintrag zeigt die Kachel einen einzelnen Button, mit mehreren Einträgen ein Raster. Die quadratischen Buttons verteilen sich so auf die Kachel, dass sie möglichst groß werden; Logos werden dabei nicht verzerrt oder beschnitten.
- Gestartet wird über die Auswahl des Players; Auflösung des Senders bzw. der Playlist und Fehlerprotokollierung übernimmt der Player.
- Wird ein Eintrag aus dem Profil entfernt, zeigt die Kachel ihn als nicht verfügbar an.
- Jeder Button zeigt ein Bild: ein optional gewähltes eigenes Bild, sonst das Bild des Senders bzw. der Playlist aus der Music-Assistant-Bibliothek, sonst ein neutrales Symbol.
- Bilder aus Music Assistant werden einmalig geladen und als ausgeblendete Medienobjekte unter der Instanz gespeichert. Die Kachel zeigt sie damit sofort, auch nach einem Neustart oder wenn Music Assistant nicht erreichbar ist. Neu geladen wird nur für neue Einträge oder über „Bilder aus Music Assistant neu laden“; schlägt das fehl, bleibt das bisherige Bild erhalten.
- Ein eigenes Bild wird zuerst in der Konsole als Medienobjekt angelegt (Objekt hinzufügen → Medium → Bild, JPEG oder PNG bis 512 KB) und dann in der Spalte „Eigenes Bild“ gewählt. Wird das Medium geändert, aktualisiert sich die Kachel sofort; eigene Medien werden vom Modul nie verändert oder gelöscht.

## Funktionen

- Previous, Stop, Play, Pause und Next
- Lautstärke von 0 bis 100 über einen Slider
- Gruppenlautstärke lauter/leiser
- Mute
- Shuffle
- Repeat mit `off`, `one` und `all`
- optional: Sleep-Timer mit 15, 30, 45, 60, 90 oder 120 Minuten und Anzeige der Endzeit; auch in der Music-Assistant-App gesetzte Timer werden angezeigt
- optional: Crossfade und Autoplay der aktuellen Warteschlange ein- und ausschalten
- die eigene HTML-Kachel zeigt die aktivierten Optionen als zweite Schalterzeile; die Endzeit des Sleep-Timers steht hinter dem Wiedergabestatus
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
3. Den WebSocket-Client einrichten: In der Connection auf **WebSocket-Client einrichten und aktivieren** klicken. Alternativ den übergeordneten **WebSocket-Client** öffnen, die von der Connection vorgegebene Konfiguration über **Änderungen übernehmen** bestätigen und den Client aktivieren. Solange das aussteht, zeigt die Connection den Status **WebSocket-Client: Konfiguration übernehmen und aktivieren**. Dieser Schritt ist auch nach jeder Änderung von Server oder Port nötig.
4. Eine Instanz **Music Assistant Konfigurator** anlegen und mit derselben `MusicAssistant Connection` verbinden.
5. In der Playerliste die von Music Assistant erkannten Player prüfen und die gewünschten Instanzen über die nativen Funktionen **Erstellen** oder **Alle erstellen** anlegen. Die Zielkategorie wird dabei im Erstellungsdialog von IP-Symcon ausgewählt.
6. Bei Bedarf **Playlists laden (Profil erstellen/aktualisieren)** und **Radios laden (Profil erstellen/aktualisieren)** ausführen.

Neu angelegte Player werden direkt mit der Connection des Configurators verbunden. Player-Instanzen dieser Connection, die Music Assistant nicht (mehr) meldet, erscheinen ebenfalls in der Liste und können dort geöffnet oder gelöscht werden.

## Player-Variablen

| Ident | Name | Typ | Bedienbar | Funktion |
|---|---|---:|:---:|---|
| `Playlist` | Playlist | Integer | ja | Auswahl einer synchronisierten Playlist |
| `Radio` | Radio | Integer | ja | Auswahl eines synchronisierten Radiosenders |
| `Transport` | Wiedergabe | Integer | ja | Previous, Stop, Play, Pause und Next |
| `Shuffle` | Shuffle | Boolean | ja | Zufallswiedergabe ein- oder ausschalten |
| `Repeat` | Repeat | Integer | ja | Standardprofil `~Repeat` |
| `Mute` | Mute | Boolean | ja | Stummschaltung |
| `VolumeLevel` | Volume | Integer | ja | Lautstärke 0 bis 100 |
| `VolumeUp` | Volume + | Integer | ja | Gruppenlautstärke erhöhen |
| `VolumeDown` | Volume - | Integer | ja | Gruppenlautstärke verringern |
| `NowTitle` | Titel | String | nein | Titel des aktuellen Queue-Eintrags |
| `NowArtist` | Interpret | String | nein | Interpret des aktuellen Queue-Eintrags |
| `NowAlbum` | Album | String | nein | Album des aktuellen Queue-Eintrags |

Zusätzlich legt der Player ein Bild-Medienobjekt mit dem Ident `Cover` an.

### Profilkompatibilität der Player-Variablen

Einige Player-Variablen verwenden bewusst weiterhin klassische IP-Symcon-Standardprofile. Die native Media-Player-Kachel erkennt `Transport` über `~PlaybackPreviousNext`, `Shuffle` über `~Shuffle`, `NowTitle` über `~Song` und `NowArtist` über `~Artist` und ordnet ihnen damit die jeweilige Funktion zu. Ein Wechsel dieser Variablen auf direkte Präsentationen kann die Erkennung und Integration in die native Kachel beeinträchtigen.

`Playlist` und `Radio` verwenden weiterhin dynamisch erzeugte Profile, da deren Assoziationen von der bestehenden Modullogik und der eigenen HTML-Kachel für die synchronisierte Medienauswahl benötigt werden. Die Verwendung dieser klassischen und dynamischen Profile ist daher eine bewusste Kompatibilitätsentscheidung und kein Fehler.

## Syncgroups

Normale Player und Syncgroups werden unterstützt. Die aktive Queue wird unabhängig von der Player-ID korrekt zugeordnet, sodass Wiedergabestatus und Metadaten auch bei Gruppen synchron bleiben.

Bei Syncgroups verwendet das Modul die von Music Assistant bereitgestellte Gruppenlautstärke, sofern für die Gruppe keine eigene Lautstärke gemeldet wird. Die Gruppenlautstärke kann außerdem über die Schaltflächen für lauter und leiser gesteuert werden.

Optional können für die aktuellen Mitglieder einer Playergruppe individuelle Lautstärkeregler eingeblendet werden. Die Regler werden bei Änderungen der Gruppenzusammensetzung automatisch ergänzt oder entfernt und über Player-Ereignisse aktualisiert.

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

`MASS_SetRepeat()` entfernt führende und nachfolgende Leerzeichen, normalisiert den Modus auf Kleinschreibung und akzeptiert ausschließlich `off`, `one` und `all`. `MASS_SetVolumeLevel()` akzeptiert ausschließlich Werte von `0` bis `100`. Ungültige Werte werden von beiden Befehlen mit einer `InvalidArgumentException` abgelehnt; die Lautstärke wird nicht automatisch begrenzt.

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

Das Cover des aktuellen Titels wird von Music Assistant im JPEG- oder PNG-Format geladen und als IP-Symcon-Medienobjekt bereitgestellt.

- Solange kein aktuelles Cover verfügbar ist, wird ein Standard-Cover angezeigt, sodass kein veraltetes Bild sichtbar bleibt.
- Im Zustand `idle` wird das Medienobjekt geleert, sodass die Mediaplayer-Kachel leer bleibt und die Player-Kachel ihren Platzhalter zeigt; nicht mehr aktuelle Wiedergabemetadaten werden entfernt.
- Ob das Medienobjekt angezeigt wird, bestimmt der Benutzer über **Objekt anzeigen**. Das Modul legt es eingeblendet an und ändert die Sichtbarkeit danach nicht mehr.

## Player-Lifecycle

Entfernte oder nicht mehr vorhandene Player werden von der `MusicAssistant Connection` zentral erkannt und bereinigt. Dabei werden auch die zugehörigen Registrierungs-, Queue- und Resync-Zustände entfernt. Die bisherige Abmeldung durch den Player während des Löschens ist nicht mehr erforderlich; dadurch wird insbesondere die bei Modul-Updates beobachtete Warnung `InstanceInterface is not available` vermieden.

Neu über den IP-Symcon-Configurator angelegte Player registrieren sich über `FM_CONNECT` zuverlässig bei ihrer Connection. Anschließend wird der normale Resync ausgeführt, sodass Player- und Queue-Daten korrekt befüllt werden. Das Entfernen bestehender Player erkennt die Connection über `FM_CHILDREMOVED`.

## Branches

| Branch | Zweck |
|---|---|
| `main` | Stabile, getestete Releases |
| `beta` | Entwicklungs- und Teststand |

## Versionierung

Aktuelle Stable-Version: **v2.4.0**.

Der Tag [`v1.0.0`](https://github.com/Loerdy/symcon-musicassist/tree/v1.0.0) kennzeichnet den ersten veröffentlichten Stable-Stand.

---

# Music Assistant for IP-Symcon

[Deutsch](#music-assistant-für-ip-symcon) | [English](#music-assistant-for-ip-symcon)

This IP-Symcon library integrates a [Music Assistant](https://www.music-assistant.io/) server with IP-Symcon. A central connection handles communication. The configurator discovers the available Music Assistant players and creates the corresponding player instances.

## Requirements

- IP-Symcon version 8.2 or later (the module is developed and tested with this version)
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

The connection's **Diagnostics** section shows a report with the versions of the library, IP-Symcon, PHP and Music Assistant, the connection status and the connected player instances. It contains no token, addresses, player names or player IDs and can be copied directly into a forum post when reporting problems.

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

The form buttons can also create or update playlist and radio profiles from the Music Assistant library. The profile names are stored persistently and passed to newly created players. Existing players are not modified: they pick up new entries directly from the profile, resolve the matching playlist or station from Music Assistant when playing, and refresh their tile automatically after loading.

### MusicAssistant Player

A `MusicAssistant Player` instance represents a regular Music Assistant player or a sync group. It provides playback controls, volume, mute, shuffle, repeat, playlists, radio stations, current metadata, and cover artwork.

The player is connected to its actual `MusicAssistant Connection`. When a player is created through the configurator, its PlayerID, connection, and playlist/radio profiles are assigned automatically.

### MusicAssistant Shortcut

A `MusicAssistant Shortcut` instance provides a tile with one or more buttons. Tapping a button starts the configured radio station or playlist on the selected player.

- A `MusicAssistant Player` instance is selected as player. It must be attached to the same `MusicAssistant Connection` as the shortcut; otherwise the instance shows an error status and starts nothing.
- The entries are taken from the playlist and radio profiles of the selected player, which are maintained by the configurator. After changing the player, its entries are available immediately.
- An optional label replaces the name from the profile.
- "Show label" hides the label per entry, for example when the name is already part of the logo. The image then fills the button up to its rounded edge. Without an image and for unavailable entries, the label is always shown.
- With one entry, the tile shows a single button; with several entries, a grid. The square buttons are arranged so that they are as large as possible; logos are neither distorted nor cropped.
- Playback is started through the player's selection; the player resolves the station or playlist and logs errors.
- If an entry is removed from the profile, the tile shows it as unavailable.
- Each button shows an image: an optionally selected own image, otherwise the station or playlist image from the Music Assistant library, otherwise a neutral icon.
- Images from Music Assistant are loaded once and stored as hidden media objects below the instance. The tile therefore shows them immediately, even after a restart or while Music Assistant is unreachable. Images are only reloaded for new entries or through "Reload images from Music Assistant"; if this fails, the previous image is kept.
- An own image is first created as a media object in the console (Add object → Media → Image, JPEG or PNG up to 512 KB) and then selected in the "Own image" column. When the media object changes, the tile updates immediately; own media objects are never modified or deleted by the module.

## Features

- Previous, Stop, Play, Pause, and Next
- volume from 0 to 100 using a slider
- group volume up and down
- mute
- shuffle
- repeat modes `off`, `one`, and `all`
- optional: sleep timer with 15, 30, 45, 60, 90, or 120 minutes and display of the end time; timers set in the Music Assistant app are shown as well
- optional: switch crossfade and autoplay of the current queue on and off
- the custom HTML tile shows the enabled options as a second row of buttons; the sleep timer end appears next to the playback status
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
3. Set up the WebSocket Client: click **Set up and activate WebSocket Client** in the connection. Alternatively open the parent **WebSocket Client**, confirm the configuration provided by the connection with **Apply changes** and activate the client. Until this is done, the connection shows the status **WebSocket Client: Apply configuration and activate**. This step is also required after every change of server or port.
4. Create a **MusicAssistant Configurator** instance and connect it to the same `MusicAssistant Connection`.
5. Review the players discovered by Music Assistant and use the native **Create** or **Create all** functions to create the desired instances. The target category is selected in the IP-Symcon creation dialog.
6. If required, run **Load playlists (create/update profile)** and **Load radio stations (create/update profile)**.

New player instances are connected directly to the configurator's connection. Player instances of this connection that Music Assistant no longer reports are also listed and can be opened or deleted there.

## Player Variables

| Ident | Name | Type | Controllable | Function |
|---|---|---:|:---:|---|
| `Playlist` | Playlist | Integer | yes | Select a synchronized playlist |
| `Radio` | Radio | Integer | yes | Select a synchronized radio station |
| `Transport` | Wiedergabe | Integer | yes | Previous, Stop, Play, Pause, and Next |
| `Shuffle` | Shuffle | Boolean | yes | Enable or disable shuffle |
| `Repeat` | Repeat | Integer | yes | Standard profile `~Repeat` |
| `Mute` | Mute | Boolean | yes | Mute control |
| `VolumeLevel` | Volume | Integer | yes | Volume from 0 to 100 |
| `VolumeUp` | Volume + | Integer | yes | Increase group volume |
| `VolumeDown` | Volume - | Integer | yes | Decrease group volume |
| `NowTitle` | Titel | String | no | Title of the current queue item |
| `NowArtist` | Interpret | String | no | Artist of the current queue item |
| `NowAlbum` | Album | String | no | Album of the current queue item |

The player also creates an image media object with the ident `Cover`.

### Player Variable Profile Compatibility

Some player variables deliberately continue to use classic IP-Symcon standard profiles. The native Media Player tile recognizes `Transport` through `~PlaybackPreviousNext`, `Shuffle` through `~Shuffle`, `NowTitle` through `~Song`, and `NowArtist` through `~Artist`, allowing it to assign the corresponding function to each variable. Replacing these profiles with direct presentations can impair detection and integration in the native tile.

`Playlist` and `Radio` continue to use dynamically generated profiles because their associations are required by the existing module logic and the custom HTML tile for synchronized media selection. Using these classic and dynamic profiles is therefore a deliberate compatibility decision, not an error.

## Sync Groups

Regular players and sync groups are supported. The active queue is mapped independently of the PlayerID, keeping playback status and metadata synchronized for groups as well.

For sync groups, the module uses the group volume provided by Music Assistant when the group does not report its own volume. Group volume can also be adjusted using the volume up and down buttons.

Individual volume controls can optionally be shown for the current members of a player group. The controls are added or removed automatically when group membership changes and are updated through player events.

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

`MASS_SetRepeat()` trims leading and trailing whitespace, normalizes the mode to lowercase, and accepts only `off`, `one`, and `all`. `MASS_SetVolumeLevel()` accepts only values from `0` to `100`. Both commands reject invalid values with an `InvalidArgumentException`; the volume is not clamped automatically.

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

The cover artwork for the current track is loaded from Music Assistant in JPEG or PNG format and provided as an IP-Symcon media object.

- A default cover is displayed until current artwork is available, preventing stale artwork from remaining visible.
- In the `idle` state, the media object is cleared, so the media player tile stays empty and the player tile shows its placeholder; stale playback metadata is removed.
- Whether the media object is displayed is up to the user via **Show object**. The module creates it visible and does not change its visibility afterwards.

## Player Lifecycle

The `MusicAssistant Connection` centrally detects and cleans up players that have been removed or no longer exist. Their associated registration, queue, and resynchronization state is removed as well. The player no longer needs to unregister itself while it is being deleted; this specifically avoids the `InstanceInterface is not available` warning observed during module updates.

Players newly created through the IP-Symcon configurator reliably register with their connection through `FM_CONNECT`. The normal resynchronization then runs, ensuring that player and queue data are populated correctly. The connection detects the removal of existing players through `FM_CHILDREMOVED`.

## Branches

| Branch | Purpose |
|---|---|
| `main` | Stable, tested releases |
| `beta` | Development and testing version |

## Versioning

Current stable version: **v2.4.0**.

The [`v1.0.0`](https://github.com/Loerdy/symcon-musicassist/tree/v1.0.0) tag marks the first published stable version.
