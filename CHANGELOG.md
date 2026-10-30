# Changelog

## Unreleased

- Neues Modul `MusicAssistant Shortcut` (Schnellzugriff): Eine Kachel mit einem oder mehreren Buttons startet hinterlegte Radiosender oder Playlists auf einem gewählten Player. Die Einträge stammen aus den Playlist- und Radio-Profilen des Players. Jeder Button zeigt ein eigenes Bild, das Bild aus der Music-Assistant-Bibliothek oder ein neutrales Symbol; Bilder aus Music Assistant werden einmalig geladen und dauerhaft gespeichert. Die Beschriftung lässt sich je Eintrag ausblenden, etwa wenn der Name bereits im Logo steht; die Buttons verteilen sich so auf die Kachel, dass sie möglichst groß und quadratisch werden. Ohne Beschriftung füllt das Bild den Button bis zum abgerundeten Rand.
- Die internen Timer von Connection und Player rufen ihre Aufgaben jetzt über `IPS_RequestAction` auf, wie es die Symcon-Vorlage vorsieht. Bestehende Instanzen übernehmen das automatisch, spätestens beim nächsten Neustart von IP-Symcon. Die bisherigen öffentlichen Funktionen (`MASS_CheckConnection`, `MASS_ResetPlaylistSelection`, `MASS_ResetRadioSelection`, `MASS_ProcessFavoriteResolution`, `MASS_ProcessCoverDownload`, `MASS_ProcessGroupMemberVolumeRefresh`) bleiben für eigene Skripte erhalten.
- Beim Start von IP-Symcon meldet die Connection keinen Fehler „InstanceInterface is not available“ mehr. Sie prüft die Konfiguration des WebSocket-Clients erst, wenn IP-Symcon vollständig gestartet ist, und bestimmt ihren Status danach neu.

## v2.2.1 — 2026-10-29

- Die HTML-Kachel zeigt das Standardcover jetzt auch dann sofort, wenn auf einen Titel oder Sender mit JPEG-Cover einer ohne Cover folgt; bisher blieb das vorherige Bild bis zum Neuladen der Seite stehen.
- Den Gruppen-Mute nutzt der Player als Ersatz nur noch, wenn Music Assistant ihn als Gruppe meldet (Player-Typ `group`); die nicht dokumentierte Erkennung am Präfix `syncgroup_` der Player-ID entfällt.
- Der Configurator wird nicht mehr bei jedem Player- und Queue-Ereignis der Connection aufgerufen, da er diese Ereignisse nicht auswertet; das spart unnötige PHP-Aufrufe.
- Beim Start von IP-Symcon übernimmt der Configurator fehlende Profilnamen und Playlist-/Radio-Zuordnungen nicht mehr, bevor alle Instanzen geladen sind; die Übernahme erfolgt bei der ersten Verwendung, sodass ein noch unvollständiger Instanzbestand keine falschen Werte dauerhaft speichern kann.
- Neu angelegte Cover-Medien erhalten die Position 150 statt 140 und teilen sie sich damit nicht mehr mit der Favoriten-Variable; bestehende Cover und vom Benutzer gewählte Positionen bleiben unverändert.
- Die Library verweist auf das GitHub-Repository statt auf die Music-Assistant-API-Dokumentation. Die Mindestversion bleibt IP-Symcon 8.2, mit der das Modul entwickelt und getestet wird.
- Intern wurde der Player in mehrere Teilbereiche aufgeteilt und die Bibliothek mit automatisierten Tests abgesichert; am Verhalten ändert sich dadurch nichts.

## v2.2.0 — 2026-10-28

- Das Configurator-Formular bleibt bei fehlerhaften Profilnamen oder Playlist-/Radio-Zuordnungen nutzbar; vorhandene Player werden weiter angezeigt, die Neuerstellung ist bis zur Behebung deaktiviert und der Grund wird im Formular angezeigt.
- Schlägt die Einrichtung eines neu erstellten Players fehl, entfernt der Configurator auch dessen bereits angelegtes Cover-Medium, sodass keine unvollständige Instanz zurückbleibt.
- Numerische Music-Assistant-Player-IDs werden im internen Zustand der Connection zuverlässig verarbeitet und beschädigen die Registrierung und Resynchronisierung nicht mehr.
- Muss der WebSocket-Client nach dem Anlegen der Connection oder einer Änderung von Server oder Port die neue Konfiguration noch übernehmen oder ist er nicht aktiv, zeigt die Connection dies mit einem eigenen Status und einem Hinweis im Formular an, statt dauerhaft „Warte auf Verbindung“ zu melden. Der Benutzer bestätigt die Änderung mit einem Klick auf „WebSocket-Client einrichten und aktivieren“ in der Connection oder wie bisher im WebSocket-Client.
- Ist der WebSocket-Client nicht aktiv oder nicht verbunden, meldet die Connection dies einmalig im Debug.
- Der Configurator listet auch Player-Instanzen seiner Connection, die Music Assistant nicht (mehr) meldet, nicht erreichbar ist oder die doppelt angelegt wurden, damit sie geöffnet oder gelöscht werden können.
- Das Cover-Medium wird nicht mehr abhängig vom Wiedergabestatus ein- und ausgeblendet; ohne Wiedergabe wird nur sein Inhalt geleert, sodass Mediaplayer-Kachel und Player-Kachel kein Bild zeigen. Über die Sichtbarkeit bestimmt nach dem Anlegen allein der Benutzer. Ein durch frühere Versionen ausgeblendetes Cover wird nach dem Update einmalig wieder eingeblendet.
- Die Lautstärkeregler der Gruppenmitglieder werden nach dem Anlegen nicht mehr umbenannt, wenn sich der Name in Music Assistant ändert; benutzerdefinierte Namen bleiben damit unangetastet. Die Player-Kachel zeigt weiterhin den aktuellen Namen.
- Beim Laden von Playlists und Radios ändert der Configurator keine Eigenschaften bestehender Player mehr. Er meldet die Aktualisierung über die Connection; die Player übernehmen neue Einträge aus dem Profil, aktualisieren ihre Kachel und ermitteln die Playlist bzw. den Sender beim Abspielen selbst.
- Ein Configurator ohne verbundene Connection meldet beim Anlegen keine Fehler mehr; Profilnamen und Zuordnungen werden nach dem Verbinden bei der ersten Verwendung übernommen.
- Fehler- und Statusmeldungen von Configurator und Connection sind vollständig übersetzbar; bei englischer Oberfläche erscheinen sie auf Englisch, bei deutscher Oberfläche unverändert auf Deutsch.
- Die Dokumentationslinks der Module verweisen auf die jeweiligen Abschnitte der README; die Einrichtungsanleitung beschreibt den Schritt im WebSocket-Client und die Auswahl der Zielkategorie beim Erstellen.
- Shuffle und Repeat werden direkt an den Player gesendet und kommen ohne vorherige Queue-Abfrage schneller an; sie wirken damit auf die aktuell laufende Wiedergabe des Players.
- Kann ein Gruppen-Player nicht direkt stummgeschaltet werden, wird der dokumentierte Gruppen-Mute von Music Assistant verwendet, statt die Mitglieder einzeln anzusprechen.
- Die HTML-Kachel erhält das Cover nur noch, wenn es sich geändert hat; Lautstärke-, Status- und andere Aktualisierungen übertragen damit deutlich weniger Daten. Neu geöffnete Kacheln zeigen das Cover weiterhin sofort.
- Bedienbefehle wie Wiedergabe, Lautstärke und Mute warten nicht mehr auf die interne Sperre der Gruppenlautstärken.
- Ein vorübergehend gesperrter interner Zustand der Connection führt im Verbindungs-Timer nicht mehr zu Skriptfehlern; die betroffene Prüfung wird im nächsten Lauf wiederholt. Bei inaktivem WebSocket-Client wird der Zustand nur noch einmal zurückgesetzt statt alle fünf Sekunden.
- Fehler beim Erhöhen oder Verringern der Lautstärke werden wie bei den übrigen Aktionen im Meldungsfenster protokolliert.
- Fehler beim Initialisieren einer HTTP-Anfrage und beim Speichern der Radio-Zuordnung werden kontrolliert gemeldet; die unter PHP 8.5 veraltete Funktion `curl_close()` wird nicht mehr verwendet.
- Belegt ein inkompatibles Objekt den Ident `Playlist` oder `Radio`, wird es nicht mehr gelöscht; der Player meldet den Konflikt und überspringt die Auswahlvariable. Die Migration der früheren String-Variable `Playlist` bleibt erhalten.

## v2.1.2 — 2026-10-27

- Kurzzeitige Verkleinerungen von Playergruppen werden vor dem Entfernen ihrer Lautstärkeregler durch einen verzögerten zweiten Snapshot bestätigt.
- Fehler bei Transport-, Shuffle- und Repeat-Aktionen werden kontrolliert protokolliert, ohne den bisherigen Variablenwert zu verändern.
- Die WebSocket-Datenkonvertierung verwendet keine unter PHP 8.2 veraltete Funktion mehr.
- Ungeklammerte IPv6-Adressen werden auch für HTTP-API-Aufrufe korrekt als URL-Host formatiert.
- Playlist- und Radio-Auswahl schließen sich in der HTML-Player-Kachel bereits während des Wiedergabestarts gegenseitig aus.
- Playlist- und Radio-Auswahlen bleiben in der HTML-Player-Kachel bereits während des Wiedergabestarts sichtbar und werden gegen ausbleibende Bestätigungen abgesichert.
- Die HTML-Player-Kachel zeigt eine erfolgreich gestartete Playlist- oder Radio-Auswahl bis zur bestehenden zeitgesteuerten Rücksetzung an.
- Ungültige Playlist- und Radio-Auswahlwerte werden nach Konfigurationsänderungen auf den Neutralwert zurückgesetzt, ohne die Wiedergabe zu beeinflussen.
- Playlist- und Radio-Synchronisierungen prüfen Profiländerungen, verhindern parallele Läufe und aktualisieren weitere Player auch dann, wenn einzelne Player fehlschlagen.
- Neue Player werden über die native Configurator-Erstellung direkt in der dort ausgewählten Zielkategorie angelegt; die separate Zielkategorie und nachträgliche Platzierung entfallen.
- `CreatePlayer()` erstellt ohne explizite Kategorie in der Wurzel und unterstützt optional weiterhin eine validierte konkrete Kategorie-ID.
- Rein numerische Music-Assistant-Player-IDs bleiben in der Gruppenlautstärkeverarbeitung einschließlich Update-Puffer als Strings erhalten.
- Playlist- und Radio-Zuordnungen des Configurators werden persistent gespeichert und bei bestehenden Installationen konfliktgeprüft aus verbundenen Playern übernommen.
- Der Configurator bricht die Playlist- oder Radio-Synchronisierung bei einem gleichnamigen Variablenprofil mit falschem Datentyp ab, ohne das vorhandene Profil zu löschen oder zu verändern.
- Die Favoritenauflösung des aktuellen Titels erfolgt ereignisgesteuert außerhalb der WebSocket-Ereignisverarbeitung und verwirft veraltete Ergebnisse nach Titel- oder Konfigurationswechseln.
- Cover-Downloads erfolgen ereignisgesteuert außerhalb der WebSocket-Ereignisverarbeitung und übernehmen keine veralteten Ergebnisse nach Titel- oder Konfigurationswechseln.
- Gruppen-Snapshots für dynamische Lautstärkeregler werden ereignisgesteuert außerhalb der WebSocket-Ereignisverarbeitung geladen; neuere Lautstärkeereignisse bleiben dabei erhalten.

## v2.1.1 — 2026-10-23

- Die Mindestversion wurde aufgrund der Verwendung moderner Variablenpräsentationen auf IP-Symcon 8.0 angehoben.
- Gegenüber v2.1.0 gibt es keine funktionalen Änderungen.

## v2.1.0 — 2026-10-23

- Player können optional eine responsive HTML-Kachel mit Cover, Titel, Interpret, Wiedergabestatus und Bedienelementen für Transport, Lautstärke, Mute, Shuffle, Repeat und Favoriten verwenden.
- Die HTML-Kachel unterstützt kompakte und erweiterte Layouts, Playlist- und Radio-Auswahl sowie individuelle Lautstärkeregler für aktuelle Mitglieder einer Playergruppe.
- Coverabhängige Hintergründe, ein optimiertes Standardcover und die Farben des aktiven IP-Symcon-Themes sorgen für eine konsistente Darstellung in hellen und dunklen Designs.
- Monochrome SVG-Symbole und aktive Bedienelemente verwenden die IP-Symcon-Akzentfarbe und werden auch unter iOS einheitlich dargestellt.
- Player können den Favoritenstatus des aktuellen Titels anzeigen und ändern; die native Schalterdarstellung verwendet ein Herzsymbol.
- Aktualisierte Playlist- und Radio-Zuordnungen werden an bestehende Player derselben Music-Assistant-Verbindung übertragen und bei ungültigen API-Antworten nicht verworfen.
- Die Builtin-Zufallsplaylists für Tracks, Alben und Künstler werden innerhalb der Connection aktualisiert und gestartet, ohne vollständige Trackdaten an den Player zu übertragen.
- Player- und Queue-Zustände sowie Gruppenlautstärken werden bei Ereignissen und nach einer Wiederverbindung zuverlässig synchronisiert.
- Authentifizierungs- und Snapshot-Anfragen werden bei vorübergehenden Fehlern begrenzt wiederholt; Generationen-, Message-ID- und Semaphore-Prüfungen schützen die Wiederverbindung und Resynchronisierung vor konkurrierenden Antworten.
- Visualisierungsaktualisierungen und Lautstärkeregler wurden gegen unnötige Aktualisierungen und springende Werte stabilisiert.
- Explizite API-Antworten mit `result: null` werden korrekt als Null-Ergebnis verarbeitet.

## 2.0.2 — 2026-10-21

- Persistente Konfiguration wird validiert und bei ungültigen Daten unverändert erhalten.
- WebSocket-Zustand, konkurrierende Zugriffe und die Resynchronisierung der Player wurden abgesichert.
- JSON-State-Buffer werden validiert, ohne beschädigte Daten stillschweigend als leeren Zustand zu behandeln.
- Die Cover-Verarbeitung unterstützt JPEG und PNG, verwendet ein Standard-Cover und bereinigt das Cover im Queue-Zustand `idle`.
- Benutzerdefinierte Variablenprofile und Icons bleiben bei Aktualisierungen erhalten.
- Konflikte mit vorhandenen Variablenprofilen werden behandelt, ohne diese Profile zu löschen.
- Die Bezeichnung der Variable `VolumeDown` wurde auf `Volume -` korrigiert.
- Die API-Debug-Protokollierung schützt Request-Parameter und protokolliert Response-Daten nur bei expliziter Aktivierung.
- Repeat-Modi und Lautstärkewerte werden vor dem API-Aufruf validiert.
