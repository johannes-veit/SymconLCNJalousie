# Changelog

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
