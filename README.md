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
- vier echte Boolean-Statusvariablen: Relais AUF, Relais ZU, GT8 LANG AUF, GT8 LANG ZU. Die GT8-LANG-Variablen dürfen von beliebigen freien UPU-Ausgängen 3/4 stammen.
- geprüfte LCN-PRO-Verriegelung
- mit PCHK/LCN-Busmonitor bestätigte TS-Datenfelder

### Freie Wahl der simulierten GT8-LANG-Ausgänge

Die Statusvariablen für GT8 LANG AUF/ZU müssen nicht vom Haupt-UPU des GT8, vom TS-Sendemodul oder vom Relaisaktor stammen. Ausgang 3 beziehungsweise 4 darf auf einem beliebigen freien LCN-UPU liegen. Entscheidend ist die LCN-PRO-Programmierung: Der fremde simulierte Ausgang muss als **zweites Ziel der korrekten GT8-Taste am Haupt-UPU** eingetragen sein. Das Modul prüft deshalb nur Variablentyp und Eindeutigkeit, nicht die Zugehörigkeit zum TS-Sendemodul.

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

**0.1.25 – öffentliche Beta.** Der Runtime-Kern basiert auf der tiefengeprüften V11.3-Skriptfassung. Das Modul automatisiert deren Aufbau und Konfiguration. Vor einem produktiven Motorbetrieb bleiben reale Tests mit Symcon 9.0, PCHK/PCK, LCN-Bus, Relais, Motor und Endlagen zwingend.

## Relaisbestätigung und parallele Bedienung ab Version 0.1.25

Die Bestätigungszeit beginnt erst, wenn Symcon das jeweilige LCN-Telegramm tatsächlich angenommen hat. Konfigurationsprüfung, Warten auf die globale Bus-Sendesperre und der einstellbare Telegrammabstand verbrauchen daher keine Bestätigungszeit mehr. Das verhindert insbesondere bei vielen gleichzeitig eingebundenen Jalousien falsche sofortige Startfehler.

Bleibt die erwartete Relaismeldung aus, fragt das Modul das konfigurierte Aktormodul einmal gezielt ab. Sind beide ausgewählten Relais weiterhin AUS, wird der Auftrag ohne dauerhafte Fehlerverriegelung beendet und für verspätete Starts überwacht. Bei einem STOP ist eine Wiederholung nur zulässig, wenn beide ausgewählten Relaisvariablen nach einer frischen Statusabfrage geantwortet haben und eines davon weiterhin EIN meldet. Diese verifizierte Wiederholung erfolgt höchstens einmal.

Alle Instanzen teilen eine globale LCN-Sendesperre und eine kurze Transaktionssperre für noch nicht real bestätigte Toggle-Befehle. Sobald das Relais der ersten Instanz den Start oder STOP bestätigt, wird die Transaktionssperre gelöst; bereits gestartete unterschiedliche Jalousien dürfen daher gleichzeitig weiterfahren. Doppelt verwendete Motorrelais-, GT8- und identische TS-KURZ-Zuordnungen werden bereits bei der Konfiguration gesperrt. Startet nach einem Symcon-Telegramm dennoch das Relais einer anderen Instanz, wird der Sender mit einer eindeutigen TS-Routingmeldung verriegelt und die tatsächlich fahrende Jalousie sicher als externe Fahrt überwacht. Die Software kann eine falsche LCN-PRO-Zuordnung nicht vor dem ersten realen Telegramm erkennen; deshalb bleibt die Busmonitor-Abnahme jeder AUF-/ZU-Zuordnung zwingend.


## Externe Endlagen, Referenz und Mehrinstanzbetrieb ab Version 0.1.25

- Eine gültige Referenz bleibt beim Start einer neuen Fahrt aktiv; die Position wird während der Fahrt weitergerechnet.
- LCN-/GT8-Bedienung hat gegenüber gleichzeitig eintreffenden Visualisierungszielen Vorrang. Symcon übernimmt eine erkannte externe Fahrt nicht als eigenen Zwischenzielauftrag.
- Nach sicherer Endlagenzeit wird 0 % beziehungsweise 100 % automatisch referenziert. Das externe Relais bleibt noch bis zum Ablauf des konfigurierten Kalibrierfensters aktiv und wird danach genau einmal über den KURZ-Befehl seiner real bestätigten Richtung ausgeschaltet.
- Worker und Healthcheck prüfen diese beiden Deadlines unabhängig voneinander.
- Ab Version 0.1.27 wird nur noch der kurze Telegrammversand global serialisiert. Zwischen zwei Telegrammen liegen mindestens 100 ms; offene Relaisbestätigungen anderer Instanzen blockieren den nächsten Start nicht. Mehrere korrekt getrennte Jalousien dürfen daher innerhalb weniger Sekunden gestartet werden und anschließend gleichzeitig fahren.
- Meldet während einer offenen Symcon-Transaktion ausschließlich eine andere Jalousie ein aktives Relais, wird der Sender mit einem TS-Routingfehler gesperrt. Die fahrende Instanz bleibt unter Endlagen-/Autostopp-Überwachung.



## Modulupdate, Fehlerquittierung und Referenzerhalt ab Version 0.1.27

Während eines Modulupdates werden die alten Laufzeitereignisse und Timer vor dem Skriptneuaufbau kurz angehalten. Dadurch kann kein Relaisereignis einen Controller gegen eine nur teilweise aktualisierte Modulfunktion ausführen. Nach erfolgreichem Aufbau werden Konfiguration und reale Relaiswerte automatisch neu geprüft.

Reine Update-/Initialisierungsfehler werden automatisch aufgehoben, wenn beide ausgewählten Motorrelais AUS sind und die vollständige Prüfung wieder erfolgreich ist. Eine manuelle Quittierung bleibt bewusst erforderlich bei echten Sicherheitsfehlern, insbesondere bei weiterhin aktivem Relais nach STOP, gleichzeitig aktiven Richtungsrelais oder bestätigtem TS-Routingfehler.

Eine gültige Referenz und die aktuelle Zwischenposition sind persistent und werden bei einem normalen Update übernommen. Auch eine Fehlerquittierung allein löscht sie nicht. Eine neue Endlagenreferenz ist nur erforderlich, wenn die Referenz bereits vorher ungültig war, ein altes inkompatibles Positionsmodell migriert wird oder ein Bewegungsbeginn/-verlauf tatsächlich nicht sicher zeitlich bestimmt werden konnte. Eine in einer älteren Version bereits gelöschte Referenz wird nicht künstlich aus einem möglicherweise veralteten Prozentwert rekonstruiert.

## Reale LCN-Adressen ab Version 0.1.25

Das Modul zeigt für Sendemodul, Aktormodul und GT8-Quellmodule die tatsächlich in Symcon gespeicherten Werte `Segment` und `Target` an. Ein Name wie `EG Büro Säule (000,011)` ist nur eine Beschriftung; gesendet wird an die interne Adresse. Stimmen Name und Adresse nicht überein oder existieren zwei aktive LCN-Modulinstanzen mit derselben realen Adresse am selben Splitter, wird die Jalousiesteuerung vor dem ersten Telegramm gesperrt.

Bei ausbleibender Startmeldung stehen nun zwei vollständige Bestätigungsfenster zur Verfügung. Bleiben beide ausgewählten Relais AUS, wird nur der Auftrag verworfen; die Instanz und eine vorhandene Referenz bleiben erhalten. Eine dauerhafte TS-Routingsperre erfolgt erst nach einer frischen Statusbestätigung der ausgewählten Relais.

## Lizenz

MIT – siehe [LICENSE](LICENSE).
## Eigene Symcon-Kachel und Referenzierung

Ab Version 0.1.11 nutzt die Geräteinstanz das offizielle Symcon HTML-SDK für eine eigene, interaktive Jalousiekachel. Behang und Lamellen sind identisch aufgebaut: links drei gleich große runde Tasten, mittig eine dynamische grafische Statusanzeige und rechts ein vertikaler Slider. Beim Behang lauten die Tasten `AUF`, `STOP`, `ZU`; bei den Lamellen `AUF`, `MITTE`, `ZU`. Beide Slider zeigen 0 % oben und 100 % unten. Der kompakte Laufstatus und der Schalter **ShakeFree nach Endlage ZU** bleiben sichtbar. Der optische Tastenkranz kennzeichnet nur einen laufenden Auftrag und verschwindet nach dessen Abschluss automatisch. Die Kachel verwendet Symcon-Icons über `/icons.js`, übernimmt Akzent-, Text- und Kartenfarbe direkt aus der geöffneten Symcon-Visualisierung und sendet Benutzeraktionen ausschließlich über `requestAction()` an die Modulinstanz.

Ab Version 0.1.26 behandelt die Kachel vorübergehende API-Verbindungsabbrüche defensiv. Während ein Bedienbefehl noch keine Serverrückmeldung erhalten hat, werden weitere Nicht-STOP-Befehle kurz gesperrt. Ein unklar übertragener LCN-Toggle wird niemals automatisch wiederholt. Nach einem Fehler fordert die Kachel stattdessen zur Kontrolle des realen Relaiszustands auf. Damit werden schnelle Doppelklicks und unkontrollierte Wiederholungen bei `ClientException: Failed to fetch, uri=/api/` vermieden; die Erreichbarkeit des Symcon-Servers selbst kann das Modul jedoch nicht herstellen.

Die direkten Statusvariablen `Position`, `Drehgrad` und `Position gültig` bleiben erhalten, damit die Werte auch in der Listenansicht und für Automationen verfügbar sind. `Position` verwendet 0 % = vollständig offen und 100 % = vollständig geschlossen; `Drehgrad` verwendet 0 % in AUF-Richtung und 100 % in AB-Richtung.

Ab Version 0.1.15 wird eine gültige Endlagenreferenz doppelt persistent gespeichert: als sichtbare Statusvariable und zusätzlich als Modulattribut. Version 0.1.24 trennt dabei die zuletzt sicher erreichte Referenz-Endlage von der aktuellen Zwischenposition. Ein normales **Übernehmen**, ein Objektbaum-Rebuild, ein Neustart, eine Fehlerverriegelung oder eine Fehlerquittierung löscht die Referenz nicht und setzt die Positionsanzeige nicht auf die letzte Endlage zurück. Die Diagnosevariablen `Letzte Referenz-Endlage` und `Letzte Referenzierung` zeigen den letzten sicheren Abgleich. Eine Referenz wird nur noch bei einem ausdrücklich inkompatiblen Modellupdate oder einem nachweislich positionsunsicheren Bewegungsablauf verworfen.

Ist `Position gültig` noch `false`, wird der erste Symcon-Endlagenauftrag auf 0 % oder 100 % automatisch als vollständige Referenzfahrt ausgeführt. Die Dauer ist richtungsspezifisch: Gesamtzeit 100→0 beziehungsweise 0→100 plus Referenzreserve, begrenzt durch `MaxFahrt`. Nach Ablauf der Reserve sendet das Modul genau einmal den richtungsabhängigen STOP und wartet auf die reale AUS-Bestätigung beider ausgewählter Relais. Externe LCN-/GT8-Fahrten werden ebenfalls verfolgt: Nach sicherer Endlagenzeit wird die Referenz aktualisiert; bleibt das Relais anschließend noch aktiv, wird es erst nach Ablauf des Kalibrierfensters mit genau einem richtungsgebundenen KURZ-Befehl ausgeschaltet und auf reale AUS-Bestätigung überwacht.

Der STOP-Taster der Kachel ist kein separater LCN-STOP-Befehl. Der Controller wertet die realen Relaisrückmeldungen aus und sendet den KURZ-Befehl der tatsächlich aktiven Richtung erneut, sodass das aktive LCN-Relais ausgeschaltet wird.



## Verhalten nach Symcon-Neustart

Ab Version 0.1.19 wird eine gespeicherte, vollständige Konfiguration während des Symcon-Starts nicht mehr wegen der vorübergehend noch inaktiven LCN/PCHK-Instanzen als fehlerhaft markiert. Während `KR_INIT` prüft das Modul nur die dauerhaft gespeicherten IDs und deren strukturelle Zuordnung. Nach `IPS_KERNELSTARTED` folgt automatisch die vollständige Laufzeitprüfung. Benötigt das LCN-Sendemodul noch einige Sekunden, bleibt die Instanz mit Status 102 als konfiguriert sichtbar; die Bedienung und Ereignisse sind bis zur Freigabe trotzdem gesperrt. Der Healthcheck und Statusänderungen der beteiligten LCN-Instanzen wiederholen die Prüfung automatisch; ein erneutes Öffnen und Speichern der unveränderten Konfiguration ist nicht erforderlich. Die gespeicherten Eigenschaften und eine gültige Positionsreferenz bleiben erhalten.



## Eindeutige Relais- und TS-Zuordnung ab Version 0.1.20

Fahrbefehle werden nicht mehr aus kopierten Laufzeitvariablen, sondern unmittelbar aus den unveränderlichen Properties der jeweiligen Modulinstanz gebildet. Vor jedem Befehl werden das konfigurierte Sendemodul, die beiden ausgewählten Relaisvariablen und der bestätigte TS-KURZ-Befehl erneut validiert. Instanzen mit gemeinsam verwendeten Motorrelaisvariablen, GT8-Ereignisvariablen oder demselben TS-KURZ-Befehl auf demselben Sendemodul werden mit Status 213 gesperrt. Ein Befehl kann damit nicht aufgrund einer veralteten internen Kopie auf die Zuordnung einer anderen Jalousie wechseln. Die tatsächliche LCN-PRO-Programmierung des TS-Befehls muss trotzdem einmalig am Busmonitor gegen genau den gewünschten Aktor geprüft werden.

## Symcon-Themefarben

Die HTML-Kachel übernimmt Akzent-, Text- und Kartenfarbe direkt aus `--accent-color`, `--content-color` und `--card-color` der jeweils geöffneten Symcon-Visualisierung. Betriebssystem- oder Browser-Dark-Mode werden nicht separat ausgewertet.


## Kalibrierfenster, Aktivschalter und Fehlerverriegelung

Ab Version 0.1.13 besitzt jede Instanz die Eigenschaft **Symcon-Steuerung aktiv**. Wird sie ausgeschaltet, sendet das Modul keine Symcon-Telegramme; die realen Relaisereignisse bleiben zur Statusanzeige beobachtbar und die lokale LCN-Bedienung bleibt verfügbar. Der automatische externe Endlagen-STOP ist bei bewusst deaktiviertem Modul gesperrt.

Nach einer vollständig von Symcon ausgeführten ZU-Fahrt auf 100 % sendet das Modul sofort genau einmal den richtungsabhängigen STOP und wartet auf die reale AUS-Bestätigung beider ausgewählter Motorrelais. Erst bei bestätigtem Stillstand beginnt die eingestellte **Zeitverzögerung / das Kalibrierfenster** (Vorgabe 30 Sekunden). Während dieses Fensters bleiben beide Relais AUS. Ist **ShakeFree nach Endlage ZU** eingeschaltet, folgt die Gegenfahrt erst nach dem ungestörten Ablauf. Ein neuer Fahrbefehl beendet das Kalibrierfenster und darf sofort sicher weiterverarbeitet werden.

## Richtungsabhängige Laufzeiten

Die beiden vollständigen Fahrtrichtungen werden getrennt konfiguriert:

- **Gesamtlaufzeit 0 % AUF → 100 % ZU**: vollständige Schließfahrt ab oberer Endlage.
- **Gesamtlaufzeit 100 % ZU → 0 % AUF**: vollständige Öffnungsfahrt einschließlich der vollen Lamellenwendung.

Für Zwischenpositionen leitet das Modul daraus zwei unterschiedliche Behanggeschwindigkeiten ab. In Richtung AUF wird die volle Wendezeit von der konfigurierten Gesamtzeit 100→0 abgezogen; in Richtung ZU wird die konfigurierte Gesamtzeit 0→100 direkt als reine Behanglaufzeit verwendet. Sanftanlauf und Rest-Wendezeit werden anschließend abhängig vom tatsächlichen Startzustand zusätzlich berücksichtigt.

Ab Version 0.1.16 kann die Sanft-Stopp-Phase vor 0 % AUF und vor 100 % ZU getrennt eingestellt werden; der Standardwert beträgt jeweils 4.500 ms. Version 0.1.18 berechnet daraus einen positionsabhängigen Fahrwegabschnitt. Bei Behanglaufzeit `T` und Sanft-Stopp-Zeit `S` beträgt der Endzonenanteil `S / (2*T - S)`. Außerhalb dieser Endzone fährt der Behang rechnerisch mit voller Geschwindigkeit. Innerhalb der Endzone wird die bereits verringerte Geschwindigkeit berücksichtigt: Ein Ziel direkt am Zonenbeginn enthält noch keinen Sanft-Stopp-Anteil, Ziele näher an der Endlage zunehmend mehr und 0 % beziehungsweise 100 % die vollständige Sanft-Stopp-Zeit. Dabei entsteht keine zusätzliche Abbremsung vor einem Zwischenziel; das Modell bildet ausschließlich das physische, positionsabhängige Fahrprofil ab.

Bei einem Laufzeit- oder Aufbaufehler werden Visualisierungs- und Automatikaufträge verriegelt. Reale Relaisereignisse und der Healthcheck bleiben bei aktiviertem Modul jedoch verfügbar, damit eine lokale LCN-/GT8-Fahrt weiterhin erkannt, an der Endlage referenziert und nach dem Kalibrierfenster sicher ausgeschaltet werden kann. Eine Quittierung ist erst möglich, wenn beide realen Relais AUS melden; die Quittierung selbst sendet keinen Motorbefehl und verändert eine gültige Positionsreferenz nicht.

Jeder automatische Endlagen-, ShakeFree- und Lamellenablauf besitzt einen eindeutigen Relais-AUS-Abschluss. Der aktive Richtungsbefehl wird genau einmal als KURZ-STOP gesendet; anschließend wartet das Modul auf die reale AUS-Bestätigung der beiden in dieser Instanz ausgewählten Relais. Trifft während dieser Bestätigung ein neuer oder entgegengesetzter Auftrag ein, wird er nur vorgemerkt und erst nach bestätigtem Stillstand gestartet. Derselbe Toggle-STOP wird niemals ein zweites Mal gesendet, weil er das Relais sonst wieder einschalten könnte. Bei verzögerter Rückmeldung fragt das Modul einmal den Status des ausgewählten Aktormoduls neu ab; danach verriegelt es ohne weiteren Toggle. Der zyklische Healthcheck (Vorgabe 10 s) bleibt die zweite Deadline-Sicherung.
