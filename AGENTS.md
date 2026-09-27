# IP-Symcon Music Assistant Module

Dieses Repository enthält eine IP-Symcon Library zur Integration von
Music Assistant.

Ziel ist eine saubere, wartbare Integration von Music Assistant in
IP-Symcon.

Bestehende funktionierende Implementierungen sollen nicht ohne
nachvollziehbaren Grund verändert oder ersetzt werden.


## Projektstruktur

Das Repository enthält aktuell folgende wesentliche Komponenten:

### MusicAssistantConfigurator

Pfad:

`MusicAssistantConfigurator/`

Dateien:

- `module.json`
- `module.php`

Dieses Modul dient zur Konfiguration bzw. Erkennung und Einrichtung
der Music-Assistant-Komponenten in IP-Symcon.


### MusicAssistantPlayer

Pfad:

`MusicAssistantPlayer/`

Dateien:

- `module.json`
- `module.php`

Dieses Modul repräsentiert einen Music-Assistant-Player innerhalb
von IP-Symcon.


### Gemeinsame Music Assistant API

Pfad:

`libs/MusicAssistantApi.php`

Diese Klasse enthält gemeinsam verwendete Funktionen für die
Kommunikation mit Music Assistant.

Gemeinsam benötigte Music-Assistant-API-Funktionalität soll nach
Möglichkeit zentral in dieser Klasse implementiert werden und nicht
dupliziert in den einzelnen Symcon-Modulen.

Bestehende öffentliche Methoden und Schnittstellen dieser Klasse
nicht ohne Prüfung ihrer Verwendung verändern.


### Library

Die Datei

`library.json`

beschreibt die IP-Symcon Library.

Änderungen an Library- und Modul-Metadaten nur durchführen, wenn sie
für die jeweilige Änderung tatsächlich erforderlich sind.


## IP-Symcon-Dokumentation

Für alle Fragen zur IP-Symcon-API und zur Entwicklung von
IP-Symcon-Modulen ist die offizielle Symcon-Dokumentation maßgeblich.

Einstiegspunkt für KI-Agenten:

https://www.symcon.de/de/llms.txt

Für PHP-Funktionen zuerst den offiziellen Funktionsindex verwenden:

https://www.symcon.de/de/llms/function-index.md

Bei Bedarf den dort enthaltenen Links zur Detaildokumentation folgen.

Insbesondere die Dokumentation zu folgenden Themen berücksichtigen:

- SDK für PHP-Module
- Module
- Konfigurationsformulare
- PHP-Funktionen
- Variablen und Profile
- Instanzen
- Timer
- Nachrichten
- Debug-Ausgaben


## Regeln für Symcon-Code

Keine IP-Symcon-Funktionen oder APIs erfinden.

Insbesondere bei Funktionen wie:

- `IPS_*`
- `SetValue*`
- `GetValue*`
- `RegisterVariable*`
- `RegisterProperty*`
- `RegisterTimer`
- `SetTimerInterval`
- `SendDebug`

Funktionsnamen, Parameter und Rückgabewerte anhand der offiziellen
IP-Symcon-Dokumentation prüfen.

Bei Unsicherheit zuerst die offizielle Symcon-Dokumentation
konsultieren.

Keine vermeintlichen Symcon-Funktionen auf Basis von Vermutungen
implementieren.


## IP-Symcon Module

Bei Änderungen insbesondere die für IP-Symcon-Module relevanten
Strukturen berücksichtigen:

- `library.json`
- `module.json`
- `module.php`
- `form.json`, falls vorhanden
- `locale.json`, falls vorhanden

Die vorhandene Struktur des Projekts beibehalten, sofern keine
technische Notwendigkeit für eine Änderung besteht.


## Modul-Lifecycle

Bei Änderungen an `module.php` die Lifecycle-Methoden von
IP-Symcon berücksichtigen.

Insbesondere:

- `Create()`
- `ApplyChanges()`
- `Destroy()`

Vor Änderungen an deren Verhalten die aktuelle Symcon-Dokumentation
prüfen.

Initialisierung und Registrierung von Properties, Variablen und
Timern sollen an den von IP-Symcon vorgesehenen Stellen erfolgen.


## PHP

PHP-Code kompatibel mit der vom Projekt unterstützten
IP-Symcon-Version halten.

Bestehenden Coding-Stil beibehalten.

Keine zusätzlichen Abhängigkeiten einführen, wenn sie nicht
erforderlich sind.

Bestehende Methoden und Klassen nicht ohne technischen Grund
umbenennen.

Vorhandene öffentliche Schnittstellen möglichst kompatibel halten.

Fehlerbehandlung und Logging über die bereits im Projekt vorhandenen
Mechanismen umsetzen.


## Music Assistant

Music-Assistant-spezifische Kommunikation und Datenstrukturen nicht
auf Basis von Vermutungen implementieren.

Zunächst die bestehende Implementierung in

`libs/MusicAssistantApi.php`

analysieren.

Vorhandene API-Aufrufe und Datenstrukturen als Ausgangspunkt
verwenden.

Falls für eine Änderung Informationen zur Music-Assistant-API fehlen,
dies ausdrücklich angeben, anstatt Endpunkte, Events, Parameter oder
Antwortstrukturen zu erfinden.


## Gemeinsamer Code

Funktionalität, die sowohl vom `MusicAssistantConfigurator` als auch
vom `MusicAssistantPlayer` benötigt wird, soll nach Möglichkeit in
einer gemeinsamen Implementierung unter `libs/` liegen.

Code nicht unnötig zwischen den Modulen duplizieren.

Vor einer Verschiebung bestehender Funktionen prüfen, ob dadurch
bestehende Schnittstellen oder Abläufe verändert werden.


## Änderungen am Projekt

Vor größeren Änderungen:

1. Bestehenden Code analysieren.
2. Betroffene Dateien und Funktionen identifizieren.
3. Abhängigkeiten zwischen den Modulen prüfen.
4. Bestehendes Verhalten berücksichtigen.
5. Erst danach Änderungen durchführen.

Änderungen möglichst klein, nachvollziehbar und auf die gestellte
Aufgabe begrenzt halten.

Keine umfangreichen Refactorings durchführen, wenn diese für die
eigentliche Aufgabe nicht erforderlich sind.


## Prüfung nach Änderungen

Nach Änderungen:

1. PHP-Code auf Syntaxfehler prüfen.
2. Auf offensichtliche Regressionsfehler prüfen.
3. Verwendete IP-Symcon-Funktionen gegen die offizielle Dokumentation prüfen.
4. Prüfen, ob bestehende öffentliche Methoden weiterhin funktionieren.
5. Prüfen, ob Änderungen Auswirkungen auf andere Module haben.
6. Unbeabsichtigte Änderungen vermeiden.

Wenn keine automatisierten Tests vorhanden sind, geeignete manuelle
Tests für IP-Symcon nennen.


## Verhalten bei Unsicherheit

Wenn Informationen fehlen oder eine Implementierung nicht eindeutig
aus der vorhandenen Codebasis bzw. Dokumentation hervorgeht:

- keine APIs erfinden,
- keine Parameter vermuten,
- keine vorhandene Implementierung ohne Grund ersetzen.

Stattdessen die fehlende Information benennen und erklären, was für
eine sichere Implementierung noch benötigt wird.