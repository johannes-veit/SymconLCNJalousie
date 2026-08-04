# Symcon LCN Jalousie

GitHub-fertige Modulbibliothek für **Symcon 9.0**. Eine Modulinstanz legt den vollständigen V11.3-Objektbaum, die Variablen, Skripte, Ereignisse und Links automatisch an. Der Benutzer wählt im Modulmenü nur die vorhandenen LCN-Instanzen und Statusvariablen aus und trägt die gemessenen Zeiten sowie die bestätigten TS-Datenfelder ein.

> **Sicherheitsprinzip:** Die gegenseitige Verriegelung der Motorrelais und die lokale Bedienung bleiben zwingend in LCN-PRO. Das Symcon-Modul sendet nur virtuelle KURZ-Tastenbefehle und wertet reale Relaisrückmeldungen aus.

## Enthaltene Module

- **LCN Jalousie** – eine Instanz pro Jalousie
- **LCN Jalousie Konfigurator** – komfortables Anlegen weiterer Instanzen

## Repository-Struktur

Die beiden Modulordner liegen **direkt im Hauptverzeichnis** des Repositorys. Symcon behandelt jeden normalen Ordner im Hauptverzeichnis als mögliches Modul. Deshalb darf es keinen Sammelordner `modules` geben.

```text
SymconLCNJalousie/
├─ library.json
├─ LCNJalousie/
│  ├─ module.json
│  └─ module.php
├─ LCNJalousieKonfigurator/
│  ├─ module.json
│  └─ module.php
├─ docs/
├─ tests/
└─ .github/
```

## Mindestvoraussetzungen

- Symcon 9.0
- eingerichtete LCN-Verbindung in Symcon
- vorhandene LCN-Modulinstanzen für Sendemodul und Aktor
- vier echte Boolean-Statusvariablen: Relais AUF, Relais AB, GT8 LANG AUF, GT8 LANG AB
- geprüfte LCN-PRO-Verriegelung
- mit PCHK/LCN-Busmonitor bestätigte TS-Datenfelder

## Installation

1. Repository öffentlich auf GitHub veröffentlichen.
2. In Symcon **Kern Instanzen → Modules** öffnen.
3. Über **+** die Repository-URL eintragen: `https://github.com/DEIN-BENUTZERNAME/SymconLCNJalousie.git`
4. Instanz **LCN Jalousie Konfigurator** hinzufügen.
5. Im Konfigurator eine neue **LCN Jalousie** anlegen.
6. Alle Pflichtfelder der Jalousieinstanz ausfüllen und **Übernehmen** wählen.

Eine ausführliche Anleitung steht in [ERSTE_SCHRITTE.md](ERSTE_SCHRITTE.md).

## Objektbaum

![Symcon Objektbaum](docs/images/Symcon_Objektbaum_V11_3.png)

## Entwicklungsstand

**0.1.6 – öffentliche Beta.** Der Runtime-Kern basiert auf der tiefengeprüften V11.3-Skriptfassung. Das Modul automatisiert deren Aufbau und Konfiguration. Vor einem produktiven Motorbetrieb bleiben reale Tests mit Symcon 9.0, PCHK/PCK, LCN-Bus, Relais, Motor und Endlagen zwingend.

## Lizenz

MIT – siehe [LICENSE](LICENSE).
## Eigene Symcon-Kachel und Referenzierung

Ab Version 0.1.8 nutzt die Geräteinstanz das offizielle Symcon HTML-SDK für eine eigene, interaktive Jalousiekachel. Sie enthält für Behang und Lamellen jeweils einen Slider und die Schnellwahltasten 0/50/100 %, einen großen STOP-Taster, einen ShakeFree-Schalter, den Referenzstatus und eine laufend aktualisierte Fahrstatusanzeige. Die Kachel verwendet die in Symcon bereitgestellten Icons über `/icons.js`; Benutzeraktionen gelangen ausschließlich über `requestAction()` zur Modulinstanz.

Die direkten Statusvariablen `Position`, `Drehgrad` und `Position gültig` bleiben erhalten, damit die Werte auch in der Listenansicht und für Automationen verfügbar sind. `Position` verwendet 0 % = vollständig offen und 100 % = vollständig geschlossen; `Drehgrad` verwendet 0 % in AUF-Richtung und 100 % in AB-Richtung.

Nach einem Neustart oder einer Neuinitialisierung ist die angezeigte Zahl zunächst nur der gespeicherte Rechenwert und `Position gültig` steht auf `false`. Ist `Position gültig` noch `false`, wird der erste Endlagenauftrag auf 0 % oder 100 % automatisch als volle Referenzfahrt ausgeführt: unabhängig vom gespeicherten Rechenwert läuft er mit der maximal überwachten Fahrzeit bis zur gewählten Endlage. Erst nach bestätigtem STOP setzt das Modul den Endwert und `Position gültig` auf `true`. Lokale/externe Fahrten werden verfolgt, setzen aber ohne eindeutig überwachten Symcon-Endlagenauftrag keine neue Referenz.

Der STOP-Taster der Kachel ist kein separater LCN-STOP-Befehl. Der Controller wertet die realen Relaisrückmeldungen aus und sendet den KURZ-Befehl der tatsächlich aktiven Richtung erneut, sodass das aktive LCN-Relais ausgeschaltet wird.

