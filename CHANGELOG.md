# Changelog

## 0.1.29 – 2026-08-07

- HTML-SDK-Kachel startet direkt mit dem serverseitig gespeicherten Istzustand; der kurze Default-Flash `0 % / inaktiv` nach Bedienaktionen entfällt.
- Bedienfelder werden waehrend einer laufenden `/api/`-Uebertragung nicht mehr optisch deaktiviert.
- Schnelle Folgebefehle derselben Jalousie werden nicht mehr verworfen: Solange ein Aufruf laeuft, wird der jeweils letzte Sollbefehl lokal gepuffert und nach der naechsten eindeutigen Servermeldung gesendet.
- Identische Doppelklicks bleiben kurz entprellt; unterschiedliche schnelle Zielwechsel werden akzeptiert.
- STOP bleibt als Sicherheitsbefehl sofort absetzbar und verwirft lokal wartende Folgeziele.
- Bei unklarem API-Transportfehler wird der wartende Folgebefehl bewusst verworfen und niemals automatisch wiederholt.
- Fahr-, Relais-, GT8-, Referenz-, Soft-Stop-, Kompaktspeicher- und Rollbacklogik aus V0.1.28 unveraendert.

## 0.1.28 – 2026-08-07

- Kompakte Speicherarchitektur eingeführt, ohne die Fahr-, Relais-, GT8-, Soft-Stop-, Referenz-, Mehrinstanz- oder Visualisierungslogik der V0.1.27 funktional zu verändern.
- 35 reine Konfigurations-Spiegelvariablen werden direkt durch Modul-Properties ersetzt; 43 interne Zustandsvariablen liegen in einem kompakten Modulbuffer. Dadurch entfallen nach erfolgreicher Migration exakt 78 Symcon-Variablen pro Jalousieinstanz.
- Einstufige, transaktional abgesicherte Migration: Vor jeder Bereinigung wird pro Instanz ein persistenter V0.1.27-Rollback-Snapshot erstellt, per SHA-256 verifiziert und der neue Runtime-Speicher per Roundtrip geprüft.
- Neue V0.1.28-Persistenzattribute werden über die offizielle Modul-Migrationsphase (`Migrate`) ergänzt, sodass ein Repository-Update keinen Dienstneustart benötigt und bestehende Attribute/Properties unverändert übernommen werden.
- Legacy-Variablen werden erst nach vollständig erfolgreichem Objektbaumaufbau, Skriptneuaufbau, Konfigurationsprüfung, Initialisierung und Visualisierung entfernt und niemals während eines aktiven Motorrelais gelöscht. Gelöscht werden ausschließlich die 78 bekannten Modul-Idents; benutzerdefinierte Variablen in denselben Kategorien bleiben erhalten.
- Teilweiser Löschfehler wird abgefangen: Die vollständige V0.1.27-Legacy-Struktur wird automatisch rekonstruiert; ein inzwischen fortgeschriebener aktueller Runtime-Zustand wird dabei nicht auf den alten Snapshot zurückgesetzt.
- ApplyChanges erhält vor der Migration eine exklusive Instanzsperre. Bereits laufende Controller-/Worker-Aufrufe müssen beendet sein, bevor Legacy-Objekte verändert werden; bei einem 30-s-Sperrfehler wird die Migration sicher ohne Löschung abgebrochen.
- Rollback-Schaltfläche ergänzt: Bei beiden realen Relais AUS wird die von V0.1.27 erwartete Konfigurations-/Internstruktur aus aktueller Konfiguration und aktuellem Runtime-Zustand wiederhergestellt. Danach kann V0.1.27 ohne Neueinrichtung installiert werden.
- Gültige Referenz, aktuelle Position, Lamellenposition, Fehlerspeicher, LCN-Zuordnungen und alle Properties bleiben beim Update erhalten. Eine normale erfolgreiche Migration erfordert keine neue Referenzfahrt.
- Diagnose erweitert um Speicherschema, Migrationsstatus, Snapshot-Prüfung, Rollbackstatus und verbliebene Legacy-Variablen.
- Runtime-Skriptkennung auf V12.0 erhöht.

## 0.1.27 – 2026-08-06

- Schnelle Mehrinstanzstarts entkoppelt: Eine noch offene Relaisbestätigung einer anderen Jalousie blockiert das nächste Telegramm nicht mehr synchron.
- LCN-Telegramme werden weiterhin über eine globale Sendesperre und nun mit einem festen Mindestabstand von 100 ms eingespeist; anschließend dürfen die Motoren unabhängig parallel laufen.
- Lange 15-s-Wartephasen innerhalb eines Visualisierungs-/API-Aufrufs entfernt, wodurch schnelle Gruppenbedienungen keine unnötigen `/api/`-Timeouts mehr begünstigen.
- Während `ApplyChanges` werden alte Relais-/GT8-Ereignisse sowie Worker/Healthcheck vor dem Skriptneuaufbau angehalten. Controlleraufrufe gegen eine halb aktualisierte Modulfunktion werden ohne LCN-Befehl verworfen.
- Reine Update-/Initialisierungsverriegelungen werden nach erfolgreichem Neuaufbau automatisch entfernt, sofern Konfiguration und Laufzeitprüfung gültig sind und beide ausgewählten Motorrelais sicher AUS melden.
- Echte Motor-, STOP-, Doppelrelais- und TS-Routingfehler bleiben weiterhin manuell quittierungspflichtig.
- Liefert `LCN_RequestStatus` bei unverändert ausgeschalteten Relais kein neues OnUpdate-Ereignis, wird der sichere AUS-Zustand übernommen, statt alle Instanzen nach einem Update zu verriegeln.
- Eine gültige Referenz und die aktuelle Zwischenposition bleiben bei normalen Updates erhalten. Eine neue Referenzfahrt ist nur bei bereits ungültiger Referenz, inkompatiblem Altmodell oder nachweislich positionsunsicherem Bewegungsablauf erforderlich.
- Runtime-Skriptkennung auf V11.9 erhöht.

## 0.1.26 – 2026-08-06

- HTML-SDK-Bedienung gegen vorübergehende `/api/`-Verbindungsabbrüche gehärtet.
- Asynchrone `Failed to fetch`-/`ClientException`-Fehler werden in der Kachel abgefangen und als lokale Warnung dargestellt.
- Während einer noch unbestätigten Übertragung werden weitere Nicht-STOP-Befehle kurz gesperrt; schnelle Doppelklicks erzeugen keine zusätzlichen Toggle-Telegramme.
- Bei fehlender API-Rückmeldung endet die lokale Wartesperre nach acht Sekunden kontrolliert. Der unsichere Befehl wird ausdrücklich nicht automatisch wiederholt.
- Offline-/Online-Wechsel werden erkannt. Nach einer Unterbrechung fordert die Kachel zur Prüfung des realen Relaiszustands auf.
- Relais-, Referenz-, Adress-, Mehrinstanz- und Startbestätigungslogik aus 0.1.25 unverändert übernommen.

## 0.1.25 – 2026-08-06

- Tatsächliche LCN-Segment-/Target-Adressen werden aus den Symcon-Instanzkonfigurationen gelesen und in Konfiguration sowie Diagnose angezeigt.
- Abweichungen zwischen einem im Namen angegebenen `(Segment,Target)` und der internen Adresse werden mit Status 214 gesperrt.
- Doppelte aktive LCN-Modulinstanzen auf derselben realen Adresse sowie identische TS-KURZ-Befehle auf derselben realen Senderoute werden instanzübergreifend erkannt.
- Routing-Fingerprint um reale Sender- und Aktoradresse erweitert; eine Target-Korrektur hebt eine alte Routingsperre automatisch auf.
- Startbestätigung erhält immer ein zweites vollständiges Bestätigungsfenster, auch wenn eine aktive LCN-Statusabfrage vorübergehend nicht möglich ist.
- Ausbleibender Start bei weiterhin ausgeschalteten Relais verwirft nur den Auftrag, erhält eine gültige Referenz und erzeugt keine dauerhafte Fehlerverriegelung.
- Alte verriegelnde Startbestätigungsfehler aus früheren Versionen werden beim Update nur dann automatisch entfernt, wenn beide ausgewählten Relais sicher AUS sind.
- Fremdstart wird erst nach frischer Bestätigung beider ausgewählter Senderrelais als dauerhaftes TS-Routingproblem gesperrt; zufällige zeitgleiche GT8-Bedienung löst nicht allein durch den Zeitstempel eine Sperre aus.
- Runtime-Skriptkennung auf V11.8 erhöht, damit alte generierte Skripte eindeutig erkannt und ersetzt werden.

## 0.1.24 – 2026-08-06

- Eindeutig erkannte Fremdstarts werden bereits während der Startbestätigung verarbeitet und verriegeln den Sender sofort.
- Die tatsächlich gesendete Kombination aus Sendemodul, Richtung und TS-KURZ wird in der Fehlermeldung ausgegeben.
- Eine physisch widerlegte Sendemodul-/TS-Zuordnung bleibt gesperrt und kann nicht durch bloßes Quittieren erneut ausgelöst werden.
- Freigabe erst nach geänderter Hardwarebindung oder zweistufiger erneuter TS-Abnahme: Bestätigung deaktivieren/speichern, Busmonitor prüfen, Bestätigung aktivieren/speichern.
- Eine vorhandene Positionsreferenz wird durch einen nicht bestätigten beziehungsweise fehlgeleiteten Start nicht verändert.

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
