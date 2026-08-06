# Changelog

## 0.1.23 – 2026-08-06

- Referenzverlust beim Start eines neuen Fahrbefehls behoben: Eine gültige Referenz bleibt während normaler Symcon- und externer Fahrten erhalten und wird an sicher erreichten Endlagen aktualisiert. Nur nachweislich positionsunsichere Abläufe verwerfen sie noch.
- Aktuelle Zwischenposition und zuletzt sichere Referenz-Endlage werden getrennt persistiert. `ApplyChanges`, Neustart und Fehlerquittierung setzen die Positionsanzeige nicht mehr auf die letzte Endlage zurück.
- Externe LCN-/GT8-Fahrten werden nach sicher berechneter Endlage referenziert. Bleibt das ausgewählte Relais danach während des Kalibrierfensters aktiv, sendet Symcon genau einen richtungsgebundenen KURZ-Befehl und überwacht die reale AUS-Bestätigung.
- Worker und Healthcheck sichern externe Endlagen- und Autostopp-Deadlines unabhängig voneinander ab. Der Healthcheck bleibt bei aktiviertem Modul auch während einer Fehlerverriegelung aktiv; Visualisierungsbefehle bleiben gesperrt.
- Instanzübergreifende Toggle-Transaktionen eingeführt: Solange ein Start-/STOP-Telegramm noch keine reale Relaisbestätigung hat, darf keine andere Jalousieinstanz einen weiteren Toggle senden. Nach der Bestätigung wird die Sperre sofort gelöst, sodass mehrere unterschiedliche Jalousien gleichzeitig fahren dürfen.
- Alte Befehls-Sperren werden bei `ApplyChanges`, Kernelstart und erkanntem `hrtime()`-Neustart verworfen. Ein Sendefehler oder eine fehlgeschlagene reine Busabstandspause kann kein unkontrolliertes Wiederholungstelegramm erzeugen.
- Startet während einer eindeutig offenen Symcon-Transaktion das Relais einer anderen Instanz, wird dies dem Sender als TS-Routingabweichung gemeldet. Der Sender wird für weitere Visualisierungsbefehle verriegelt; die tatsächlich fahrende Jalousie wird als externe Fahrt sicher bis Endlage und Autostopp überwacht.
- Doppelte Relais-, GT8- und identische TS-KURZ-Zuordnungen bleiben bereits bei der Konfigurationsprüfung gesperrt. Fahrbefehle werden weiterhin ausschließlich aus den Properties der aufgerufenen Instanz gebildet.
- Regressionen erweitert auf 35 statische Ablauf-Invarianten, fünf gezielte Übergangsmodelle, vier kombinierte Bedienfälle und 100.000 randomisierte Modelloperationen.

## 0.1.22 – 2026-08-06

- Externe LCN-Priorität, berührungslose Endlagenreferenzierung und stabilere Mischbedienung.


## 0.1.21 – 2026-08-05

- Sporadische falsche Startfehler behoben: Die Frist für die reale Relaisbestätigung beginnt erst nach erfolgreicher Rückkehr von `LCN_SendCommand`, nicht mehr vor Konfigurationsprüfung, Bus-Sendesperre und tatsächlicher Telegrammübernahme.
- Alle Jalousieinstanzen verwenden eine globale LCN-Sendesperre mit einstellbarem Mindestabstand (`CommandSpacingMs`, Standard 100 ms). Parallele Befehle werden serialisiert, statt gleichzeitig in PCHK/LCN eingespeist zu werden.
- Startbestätigung zweistufig: Nach Ablauf der ersten Frist wird das exakt ausgewählte Aktormodul einmal abgefragt. Bleiben beide ausgewählten Relais real AUS, wird nur der Auftrag verworfen und ein Spätstart-Schutz aktiviert; die Instanz wird nicht unnötig fehlerverriegelt.
- Startsendefehler bei real ausgeschalteten Relais werden ebenfalls ohne dauerhafte Verriegelung verworfen und durch das Spätstart-Schutzfenster abgesichert. STOP-Fehler bei aktivem Relais bleiben sicherheitskritisch.
- Ausbleibende Relais-AUS-Bestätigung weiter abgesichert: Erst nach einer vollständig frischen Statusantwort beider ausgewählter Relais und weiterhin bestätigtem EIN-Zustand ist genau eine verifizierte STOP-Wiederholung zulässig. Ohne vollständige frische Rückmeldung wird kein blindes Toggle gesendet.
- Falsche reale Richtung nach einem Startauftrag wird erkannt, über das tatsächlich aktive ausgewählte Relais kontrolliert gestoppt und anschließend als Zuordnungsfehler verriegelt.
- Doppelte Relais-, GT8- und TS-KURZ-Zuordnungen bleiben gesperrt; fremde oder veraltete Relaisereignisse werden weiterhin verworfen.
- Diagnose um Sendetimestamp, frische Start-/Stoppstatusflags, verifizierte STOP-Wiederholung und globalen Telegrammabstand erweitert.
- Zusätzliche Regressionstests decken schnelle Gegenbefehle, mehrfaches STOP, Start ohne Rückmeldung, verspäteten Start, falsche Richtung, unvollständige Statusantwort, einmalige verifizierte STOP-Wiederholung, Kalibrierfenster-Unterbrechung, Fremdereignisse und parallele Instanzsendungen ab.
- Sanft-Stopp-Fahrwegmodell aus 0.1.18 und Neustartvalidierung aus 0.1.19 bleiben unverändert.

## 0.1.20 – 2026-08-05

- Rennbedingung bei schnellem Gegenbefehl geschlossen: Ein bereits gesendeter richtungsabhängiger Toggle-STOP wird bis zur realen AUS-Bestätigung niemals erneut gesendet.
- Folgeaufträge während der STOP-Bestätigung werden gespeichert und erst nach dem bestätigten Stillstand gestartet. Dies deckt insbesondere ZU unmittelbar nach Erreichen der oberen Endlage ab.
- Bei ausbleibender Start- oder AUS-Rückmeldung wird das ausgewählte Aktormodul einmal gezielt per Statusabfrage aktualisiert. Danach verriegelt das Modul ohne zweiten Toggle.
- Kalibrierfenster sicher umgestellt: Nach 100 % ZU erfolgt der STOP sofort; das 30-s-Fenster beginnt erst nach bestätigtem Relais AUS und läuft bei beiden Relais AUS. Neue Fahrbefehle können es sicher unterbrechen.
- Sichere Hardwarebindung ergänzt: Fahrbefehle stammen direkt aus den Properties der jeweiligen Instanz; fremde oder veraltete Relaisereignisse werden verworfen.
- Doppelte Motorrelais-, GT8- oder TS-KURZ-Zuordnungen zwischen Jalousieinstanzen werden erkannt und mit Status 213 gesperrt.
- Diagnose um aktuelle Relaiswerte, Phasen-/Auftragszustand, erwartete Richtung, STOP-/Pending-Zustand und Statusabfrage-Wiederholungen erweitert.
- Neustartbehandlung aus 0.1.19 und Sanft-Stopp-Fahrwegmodell aus 0.1.18 bleiben erhalten.

## 0.1.19 – 2026-08-04

- Neustartvalidierung korrigiert: Während `KR_INIT` werden gespeicherte LCN-Instanz-IDs nur strukturell geprüft; der vorübergehende Fremdinstanzstatus und noch nicht registrierte LCN-PHP-Funktionen erzeugen keine falsche Meldung „Konfiguration unvollständig“.
- Nach `IPS_KERNELSTARTED` wird die vollständige Laufzeitprüfung automatisch erneut ausgeführt. Statusänderungen der ausgewählten LCN-Sende-, Aktor- und GT8-Quellmodule lösen ebenfalls eine erneute Prüfung aus.
- Für verzögert startende LCN/PCHK-Instanzen gilt eine 30-sekündige Startkulanz. Die Instanz bleibt dabei als konfiguriert sichtbar, Bedienung und Ereignisse sind bis zur erfolgreichen Prüfung sicher gesperrt.
- Der vorhandene Healthcheck wiederholt die Laufzeitprüfung automatisch. Sobald das Sendemodul bereit ist, wird die Instanz ohne erneutes Speichern der unveränderten Konfiguration freigegeben und der Controller initialisiert.
- Eine nur vorübergehend nicht verfügbare LCN-Laufzeit setzt keinen Fehlerstatus „Konfiguration unvollständig“. Die Instanz bleibt mit Status 102 als gespeichert vollständig gekennzeichnet; Ereignisse und Bedienbefehle bleiben trotzdem bis zur Laufzeitfreigabe gesperrt.
- Gespeicherte Modul-Properties und eine persistente Positionsreferenz werden durch vorübergehende Startzustände nicht gelöscht oder verändert. Ein echter statischer Konfigurationsfehler wird weiterhin sofort angezeigt.
- Die Sanft-Stopp- und Fahrwegberechnung aus 0.1.18 blieb unverändert.

## 0.1.18 – 2026-08-04

- Sanft-Stopp auf ein positionsabhängiges Fahrwegmodell umgestellt: Aus richtungsabhängiger Behanglaufzeit `T` und Sanft-Stopp-Zeit `S` wird der Endzonenanteil mit `S / (2*T - S)` berechnet.
- Die berechnete Endzone gilt für jede Fahrt in Richtung der jeweiligen Endlage. Außerhalb der Zone bleibt die Geschwindigkeit konstant; innerhalb wird nur der bis zur Zielposition tatsächlich durchfahrene Anteil der linearen Verzögerung berücksichtigt.
- Zwischenziele erhalten keine eigene Ziel-Abbremsung. Liegt ein Ziel jedoch physisch in der Endzone, berücksichtigt die Zeitberechnung die dort bereits reduzierte Geschwindigkeit: am Zonenbeginn 0 ms, an der Endlage die vollständige Sanft-Stopp-Zeit.
- Konfigurationsformular und Diagnose zeigen die berechneten Prozentbereiche für AUF und ZU an.
- Controller und Worker verwenden dieselbe Vorwärts-/Rückwärtskennlinie. Regressionstests prüfen unter anderem eine synthetische 5-%-Endzone mit Zielen 95/96/97/98/99/100 %, beide Richtungen, deaktivierten Sanft-Stopp und die mathematische Invertierbarkeit.
- Nach dem Update wird die Positionsreferenz einmalig verworfen, da sich die Zeit-Weg-Kennlinie gegenüber 0.1.15–0.1.17 geändert hat.

## 0.1.17 – 2026-08-04

- Sanft-Stopp-Modell korrigiert: Die lineare Verzögerung wird nur bei echten Endlagen-/Referenzaufträgen auf 0 % AUF beziehungsweise 100 % ZU verwendet.
- Zwischenpositionen werden auch innerhalb der letzten Prozent ohne künstliche Ziel-Abbremsung mit voller Geschwindigkeit berechnet und durch den Symcon-STOP beendet.
- Die volle Fahrgeschwindigkeit wird weiterhin aus der gemessenen Endlagenlaufzeit abzüglich der dreieckigen Sanft-Stopp-Fläche abgeleitet; dadurch bleibt die Prozentberechnung im übrigen Fahrbereich genauer.
- Controller und 1-s-Worker verwenden wieder dieselbe, auftragsabhängige Berechnung. Zusätzliche Regressionstests prüfen Zwischenziel, Endlagenziel, Vorwärts-/Rückwärtskennlinie und deaktivierten Sanft-Stopp.
- Gegenüber 0.1.15 bleiben Sicherheits-, Referenz-, Relais- und Ablaufsteuerung unverändert; geändert wurde ausschließlich die richtungsabhängige Zeit-/Positionsberechnung.

## 0.1.16 – 2026-08-04

- Richtungsabhängigen Sanft-Stopp für den Behang ergänzt: `Sanft-Stopp vor Endlage AUF` und `Sanft-Stopp vor Endlage ZU`, jeweils standardmäßig 4.500 ms und separat einstellbar.
- Die Geschwindigkeitsreduzierung wirkt ausschließlich unmittelbar vor der angefahrenen Endlage und fällt innerhalb der eingestellten Zeit linear von voller Geschwindigkeit auf 0.
- Positionsfortschreibung und Zielzeitberechnung verwenden dieselbe nichtlineare Weg-Zeit-Kennlinie. Zwischenpositionen außerhalb der Endzonen werden dadurch genauer berechnet.
- Fahrten zu Zwischenpositionen innerhalb einer Sanft-Stopp-Zone werden über die inverse Kennlinie terminiert, statt weiterhin eine konstante Geschwindigkeit anzunehmen.
- Diagnose und Repository-Prüfung um Konfiguration, Plausibilitätsprüfung und mathematische Vorwärts-/Rückwärtsprüfung des Sanft-Stopp-Modells erweitert.
- Eine bestehende gültige Endlagenreferenz bleibt beim Update erhalten; die neue Kennlinie ändert die physische Bedeutung von 0 % und 100 % nicht.

## 0.1.15 – 2026-08-04

- Kritischen Referenzverlust behoben: Der LCN-Statusabgleich und ein normales `ApplyChanges` setzen `Position referenziert` nicht mehr pauschal auf `false`.
- Gültige Referenz zusätzlich persistent als Modulattribute gespeichert (`ReferenceValid`, Endlage, Lamelle, Zeitstempel und Grund). Sichtbare Statusvariable und Attribut werden beim Aufbau synchronisiert.
- Neue Diagnosewerte `Letzte Referenz-Endlage`, `Letzte Referenzierung` und `Letzte Bestätigung: beide Relais AUS` ergänzt.
- Endlagenreferenz wird bei 0 % AUF und 100 % ZU jeweils nach der richtungsspezifischen Gesamtzeit plus Referenzreserve gesetzt. Unreferenzierte Endlagenfahrten verwenden nicht mehr pauschal `MaxFahrt`, sondern die passende Richtungs-Gesamtzeit plus Reserve; `MaxFahrt` bleibt die obere Sicherheitsgrenze.
- Bei 100 % ZU wird die Referenz vor dem zusätzlichen Kalibrierfenster gespeichert. Das Kalibrierfenster und ein eventuell anschließendes ShakeFree ändern die Referenz nicht, solange kein Fehler auftritt.
- ShakeFree-Abschluss explizit überwacht: AUF-Gegenfahrt wird gestoppt, danach wird der Lamellen-ZU-Nachlauf gestartet und ebenfalls nach seiner berechneten Wendezeit gestoppt. Abschluss erst nach real bestätigtem Relais-AUS.
- `J_FinishIdle()` verweigert den Ruhezustand, solange mindestens ein Motorrelais aktiv ist. Der Ablauf verriegelt dann fehlerhaft, statt einen stromführenden Zustand als beendet zu melden.
- Healthcheck als zweite Deadline-/STOP-Sicherung erweitert und Vorgabewert für neue Instanzen auf 10 s gesetzt. Falls der 1-s-Worker ausfällt, löst der Healthcheck die fällige Deadline beziehungsweise den bestehenden STOP-Timeout aus.
- Nach jedem bestätigten realen Fahrtende wird der Zeitpunkt der Relais-AUS-Bestätigung protokolliert.
- Dokumentation zu getrennten Richtungszeiten, frei wählbaren simulierten UPU-Ausgängen, Kalibrierverzögerung und Relais-AUS-Sicherheit zusammengeführt.

## 0.1.14 – 2026-08-04

- Bezeichnung in Modulmenü, Objektbaum und HTML-Kachel auf **ShakeFree nach Endlage ZU** präzisiert.
- Das Kalibrierfenster wird zusätzlich ausdrücklich als **Zeitverzögerung nach 100 % ZU** beschrieben; es läuft nach jeder vollständigen Symcon-ZU-Fahrt unabhängig vom ShakeFree-Schalter.
- Separate Gesamtzeiten für beide Fahrtrichtungen eingeführt, ohne bestehende Property-Werte zu verlieren:
  - `0 % AUF → 100 % ZU`
  - `100 % ZU → 0 % AUF` einschließlich vollständiger Lamellenwendung.
- Positions- und Zielzeitberechnung auf richtungsabhängige Behanglaufzeiten umgestellt. Für AUF wird die Wendezeit aus der Gesamtzeit 100→0 herausgerechnet; für ZU wird die Gesamtzeit 0→100 direkt verwendet.
- Maximalfahrzeit wird gegen die längere der beiden Richtungs-Gesamtzeiten plus Referenzreserve geprüft.
- GT8-LANG-Zuordnung entkoppelt: Die simulierten Ausgänge 3/4 dürfen von beliebigen freien UPU stammen. Die frühere, fachlich falsche Bindung an das TS-Sendemodul wurde aus der Validierung entfernt.
- Modulhinweis ergänzt: Der frei gewählte simulierte Ausgang muss in LCN-PRO als zweites Ziel der korrekten GT8-Taste am Haupt-UPU programmiert sein.
- Bestehende Positionsreferenz wird beim Wechsel auf 0.1.14 vorsorglich ungültig, da frühere Zwischenpositionswerte noch aus dem symmetrischen Zeitmodell stammen können.
- Diagnose und Repository-Prüfung an die neuen Richtungszeiten und die freie GT8-Ereignisquelle angepasst.

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
