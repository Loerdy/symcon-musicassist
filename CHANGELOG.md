# Changelog

## Unreleased

- Playergruppen können optional ereignisgesteuerte Lautstärkeregler für ihre aktuellen Mitglieder bereitstellen.
- Aktualisierte Playlist-Zuordnungen werden an bestehende Player derselben Music-Assistant-Verbindung übertragen.
- Die Builtin-Zufallsplaylist wird innerhalb der Connection aktualisiert und gestartet, ohne vollständige Trackdaten an den Player zu übertragen.
- Player können optional den Favoritenstatus des aktuellen Titels anzeigen und ändern.
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
