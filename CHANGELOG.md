# Änderungsprotokoll

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
