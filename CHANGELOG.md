# Changelog

## Unreleased

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
