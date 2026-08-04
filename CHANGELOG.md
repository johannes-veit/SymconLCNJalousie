# Changelog

## 0.1.13 – 2026-08-04

- Einstellbares 30-s-Kalibrierfenster nach jeder vollständig von Symcon ausgeführten ZU-Fahrt auf 100 % ergänzt. Während dieser Zeit sendet Symcon weder STOP noch einen Gegenbefehl; ShakeFree startet frühestens danach.
- Neue Phase `Kalibrierfenster` mit timerbasierter Überwachung; keine lange blockierende PHP-Wartezeit.
- Neue Eigenschaft `Symcon-Steuerung aktiv`: Im ausgeschalteten Zustand sind Ereignisse und Timer deaktiviert und die lokale LCN-Bedienung bleibt frei.
- Fehlerverriegelung ergänzt: Laufzeit- und Aufbaufehler versetzen die Instanz bis zur bewussten Quittierung in einen inaktiven Zustand.
- Im verriegelten Fehlerzustand werden keine automatischen LCN-Befehle mehr gesendet; ShakeFree wird abgeschaltet und die Positionsreferenz verworfen.
- Fehlerquittierung nur bei real bestätigten Relais AUS; die Quittierung selbst sendet keinen LCN-Befehl.
- Visualisierung um Kalibrier-Restzeit, deaktivierte Bedienelemente während des Kalibrierfensters und klaren Fehler-/Inaktiv-Hinweis erweitert.

## 0.1.12 – 2026-08-04

- Notfall-Hotfix: Fehlerphase blockiert lokale LCN-Bedienung nicht mehr.
- ShakeFree-Umschaltpause (Standard 500 ms).
- Fehlerquittierung ohne LCN-Befehl.
- Starttimeout kehrt nach Spätstart-Schutz in den Stillstand zurück.

## 0.1.11 – 2026-08-04

- Behang- und Lamellenkachel auf ein identisches, fluchtendes Dreispalten-Layout umgestellt: runde Tasten links, dynamische Grafik mittig, vertikaler Slider rechts.
- Grafische Behangposition ergänzt; 0 % zeigt den geöffneten, 100 % den vollständig geschlossenen Behang.
- Lamellen-Schnellwahl auf drei gleich große runde Tasten `AUF`, `MITTE`, `ZU` mit Pfeil-/Gleichheits-Symbolen umgestellt.
- Beide Slider und beide Grafiken verwenden identische Größen, insbesondere bei untereinander angeordneten Smartphone-Kacheln.
- Alle Bedienknöpfe verwenden weiterhin die Symcon-Akzentfarbe in Grün/Türkis.
- Befehls-Kranz korrigiert: Er zeigt nur den aktuell laufenden beziehungsweise gerade angeforderten Auftrag und wird nach Abschluss automatisch entfernt.
- Der Modulzustand liefert dafür Auftragstyp und Zielwerte an die HTML-Kachel.
- Theme-Hotfix aus 0.1.10 übernommen: Farben stammen ausschließlich aus `--accent-color`, `--content-color` und `--card-color` der Symcon-Visualisierung.
- Interne Modulversionskonstante auf 0.1.11 angehoben und automatische Versionskonsistenzprüfung ergänzt.

## 0.1.10 – 2026-08-04

- Theme-Umschaltung der HTML-Kachel korrigiert: Die Kachel nutzt nun direkt die von Symcon bereitgestellten CSS-Variablen `--accent-color`, `--content-color` und `--card-color`.
- Fehlerhafte Helligkeits-Heuristik über Parent-Frame-Hintergründe entfernt; dadurch kann die Desktop-Visualisierung nicht mehr versehentlich als dunkel erkannt werden, obwohl die Symcon-Visualisierung auf hell steht.
- Betriebssystem- und Browser-Dark-Mode haben keinen eigenen Einfluss mehr; maßgeblich ist ausschließlich das Theme der jeweils geöffneten Symcon-Visualisierung.

## 0.1.9 – 2026-08-04

- HTML-SDK-Fehler behoben: SVG-Elemente werden nicht mehr über die nur lesbare Eigenschaft `className` verändert.
- Mittlere Statuskachel entfernt; der kompakte Laufstatus bleibt unten in der Kachel erhalten.
- Behangbedienung auf drei gleich große runde Tasten `AUF`, `STOP`, `ZU` plus vertikalen Slider reduziert.
- Lamellenbereich mit grafischer, dynamischer Lamellenstellung, vertikalem Slider und den Schnellwahltasten 0/50/100 % neu angeordnet.
- Beide Slider zeigen 0 % oben und 100 % unten; die Prozentwerte stehen unterhalb der unteren Beschriftung `ZU`.
- Der Instanzname wird nicht mehr innerhalb der HTML-Kachel wiederholt.
- Theme-Erkennung folgt der tatsächlichen Symcon-Visualisierung statt der Dark-Mode-Einstellung des Smartphones; bei nicht lesbarem Parent gilt ein heller Fallback.
- ShakeFree bleibt als kleiner Ein/Aus-Schalter erhalten.
- Reine Visualisierungsupdates verwerfen eine bereits gültige Positionsreferenz nicht mehr; nur der Wechsel von einem Stand vor 0.1.7 erzwingt eine Neureferenzierung.

## 0.1.8 – 2026-08-04

- Eigene interaktive Symcon-Kachel über das offizielle HTML-SDK ergänzt.
- Behang: vertikaler Slider sowie Schnellwahltasten `AUF 0 %`, `MITTE 50 %`, `ZU 100 %`.
- Lamellen: vertikaler Slider sowie Schnellwahltasten `AUF 0 %`, `MITTE 50 %`, `ZU 100 %`.
- Großer STOP-Taster: Der vorhandene Controller ermittelt die reale aktive Relaisrichtung und sendet den zugehörigen LCN-KURZ-Befehl erneut.
- ShakeFree direkt in der Kachel über einen kleinen Ein/Aus-Schalter bedienbar.
- Laufstatusanzeige mit `fährt AUF`, `fährt ZU`, `GESTOPPT`, `Geöffnet 100%`, `Geschlossen 100%` und Fehlerstatus.
- Referenzstatus, aktuelle Prozentwerte und eine animierte Behang-/Lamellendarstellung integriert.
- Laufzeitaktualisierung erfolgt über `UpdateVisualizationValue()`; Bedienung ausschließlich über den abgesicherten HTML-SDK-Kanal `requestAction()`.
- Symcon-Icons werden über `/icons.js` eingebunden.

## 0.1.7 – 2026-08-04

- Gemessenes Bewegungsmodell ergänzt: 0 % nach AB ohne Vorlauf, 100 % nach AUF mit 6,5 s Wendezeit, Zwischenposition gleiche Richtung mit 6,0 s Sanftanlauf, Gegenrichtung mit vollständiger Wendezeit.
- Neue Konfiguration `Sanftanlauf_ms`; Positionsreferenz wird nach dem Modellupdate sicher verworfen.

## 0.1.6

- Native Symcon-Rollladenkachel: direkte Statusvariablen `Position` und `Drehgrad` unter der Modulinstanz.
- `RequestAction()` leitet Bedienungen der Kachel sicher an den vorhandenen Controller weiter.
- Position und Lamellenwinkel werden während der Fahrt laufend mit der Kachel synchronisiert.
- Direkte Statusvariable `Position gültig` und Instanzzusammenfassung zeigen an, ob eine Referenz vorliegt.
- Lamellenziele aus der Kachel können nun stufenlos von 0 bis 100 % gewählt werden; GT8-LANG bleibt bei 50 %. Ohne gültige Referenz werden Lamellenfahrten standardmäßig abgewiesen.
- Ist die Position noch unbekannt, wird der erste Endlagenauftrag auf 0 % oder 100 % automatisch als volle Referenzfahrt mit `MaxFahrt_ms` ausgeführt. Erst nach bestätigtem STOP wird die Position gültig. Eine explizite Referenzfahrt bleibt ebenfalls verfügbar.

# Änderungsprotokoll

## 0.1.5 – 2026-08-04

- Kritische Zuordnungsprüfung korrigiert: LCN-Relais- und Ausgangsvariablen werden jetzt über die physische Symcon-Instanzverbindung (`ConnectionID`) bis zur gewählten LCN-Modulinstanz geprüft.
- Die logische Objektbaum-Position (`IPS_GetParent`) wird nicht mehr fälschlich als alleinige Hardware-Verbindungskette interpretiert.
- Statusmeldungen für ungültige Relais- und GT8-Zuordnungen präzisiert.
- Repository-Prüfung um die Kontrolle der `ConnectionID`-basierten Zuordnungslogik erweitert.

## 0.1.3 – 2026-08-04

- Kritischen Konfiguratorfehler behoben: `create.configuration` wird nun als JSON-Objekt `{}` statt als Array `[]` ausgegeben.
- Vorhandene Instanzkonfigurationen werden ohne assoziative Decodierung übernommen, damit auch leere Konfigurationen Objekte bleiben.
- Repository-Prüfung um eine Kontrolle des Konfigurator-Konfigurationstyps erweitert.

## 0.1.2 – 2026-08-04

- Kritischer Instanzerstellungsfehler behoben: Der Klassenname des Konfigurators stimmt jetzt exakt mit dem `name`-Feld aus `module.json` überein (`LCNJalousieKonfigurator`).
- Modulordner in `LCNJalousieKonfigurator` umbenannt, passend zum Klassennamen.
- Repository-Prüfung erweitert: Modulname, PHP-Klassenname und Modulordner werden nun gegeneinander geprüft.

# Changelog

## 0.1.1 – 2026-08-04

- Symcon-kompatible Repository-Struktur korrigiert.
- Modulordner `LCNJalousie` und `LCNJalousieKonfigurator` liegen nun direkt im Repository-Hauptverzeichnis.
- Ungültigen Sammelordner `modules` entfernt.
- Repository-Prüfung erweitert, damit dieser Strukturfehler künftig automatisch erkannt wird.

## 0.1.0 – 2026-08-04

- Erste öffentliche Beta.
- Gerätemodul mit vollständig automatisch erzeugtem V11.3-Objektbaum.
- Konfigurationsformular mit Pflichtfeldern, Plausibilitätsprüfung und Statuscodes.
- Konfigurator zum Anlegen mehrerer Jalousieinstanzen.
- Runtime-Skripte, Ereignisse, Links, Profile und Startwerte werden automatisch angelegt und aktualisiert.
- GitHub Actions und lokale Repository-Prüfung ergänzt.
