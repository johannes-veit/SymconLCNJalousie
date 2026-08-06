# Erste Schritte – für Einsteiger

Diese Anleitung führt Sie ohne Git-Vorkenntnisse vom vorbereiteten Ordner bis zur Installation in Symcon.

## A. Repository auf Ihrem Computer anlegen

1. Laden Sie das vorbereitete ZIP-Paket herunter.
2. Entpacken Sie es an einen leicht auffindbaren Ort, zum Beispiel auf den Desktop.
3. Öffnen Sie **GitHub Desktop**.
4. Melden Sie sich oben rechts mit Ihrem GitHub-Konto an, falls noch nicht geschehen.
5. Wählen Sie **File → New repository**.
6. Tragen Sie bei **Name** genau `SymconLCNJalousie` ein.
7. Wählen Sie bei **Local path** den Ordner `Dokumente\GitHub` oder einen anderen gewünschten Hauptordner.
8. Lassen Sie die Optionen für README, Git Ignore und Lizenz leer beziehungsweise auf **None**, weil diese Dateien schon vorbereitet sind.
9. Klicken Sie auf **Create repository**.
10. Öffnen Sie in GitHub Desktop **Repository → Show in Explorer**.
11. Öffnen Sie zusätzlich den entpackten vorbereiteten Ordner.
12. Kopieren Sie **den gesamten Inhalt** des vorbereiteten Ordners in den von GitHub Desktop erzeugten Repository-Ordner. Kopieren Sie nicht noch einmal den äußeren Ordner, sondern dessen Inhalt: `library.json`, `README.md`, `LCNJalousie`, `LCNJalousieKonfigurator`, `docs` usw.
13. Wechseln Sie zurück zu GitHub Desktop. Links müssen nun viele neue Dateien unter **Changes** erscheinen.

## B. Ersten Stand speichern und öffentlich veröffentlichen

1. Unten links bei **Summary** tragen Sie ein: `Update Symcon LCN Jalousie 0.1.24`.
2. Klicken Sie auf **Commit to main**.
3. Klicken Sie oben auf **Publish repository**.
4. Kontrollieren Sie den Namen `SymconLCNJalousie`.
5. Tragen Sie als Beschreibung ein: `LCN Jalousiesteuerung für Symcon 9.0`.
6. Deaktivieren Sie die Option **Keep this code private**. Das Repository wird dadurch öffentlich.
7. Klicken Sie auf **Publish repository**.
8. Wählen Sie anschließend **Repository → View on GitHub**.
9. Prüfen Sie im Browser, ob neben dem Repository-Namen **Public** steht.

## Fehlerbehebung: „module.json fehlt“

Wenn Symcon meldet, der Ordner `modules` sei ein ungültiges Modul, liegen die Modulordner eine Ebene zu tief. Im Hauptverzeichnis des Repositorys müssen `LCNJalousie` und `LCNJalousieKonfigurator` direkt neben `library.json` liegen. Ein Sammelordner `modules` ist in einer Symcon-Modulbibliothek nicht zulässig.

## C. Modul in Symcon installieren

1. Kopieren Sie im Browser die Repository-Adresse. Sie sieht ungefähr so aus: `https://github.com/IHR-NAME/SymconLCNJalousie`.
2. Öffnen Sie die Symcon-Konsole.
3. Wechseln Sie in die logische Baumansicht zu **Kern Instanzen**.
4. Öffnen Sie die Instanz **Modules** beziehungsweise **Module Control**.
5. Klicken Sie auf **+**.
6. Fügen Sie die Repository-Adresse ein. Falls Symcon die Endung verlangt, verwenden Sie `https://github.com/IHR-NAME/SymconLCNJalousie.git`.
7. Bestätigen Sie. Danach stehen die beiden Module im Dialog **Instanz hinzufügen** bereit.

## D. Erste Jalousieinstanz anlegen

1. Wählen Sie **Instanz hinzufügen**.
2. Wählen Sie beim Hersteller **LCN Jalousie** das Gerät **LCN Jalousie verwalten**.
3. Legen Sie den Konfigurator unter einer Kategorie `Jalousiesteuerung` an.
4. Öffnen Sie den Konfigurator.
5. Wählen Sie die Zeile **Neue LCN-Jalousie** und erstellen Sie die Instanz.
6. Benennen Sie die Instanz, zum Beispiel `Jalousie Wohnzimmer`.
7. Öffnen Sie die neue Instanz.

## E. Pflichtfelder ausfüllen

Arbeiten Sie die Bereiche von oben nach unten ab:

1. **LCN-Sendemodul** – das Modul, auf dem die virtuellen Tasten liegen, zum Beispiel M22. Ab 0.1.25 zeigt das Formular zusätzlich die intern gespeicherte Adresse aus `Segment` und `Target`. Diese muss mit der Adresse im Namen und mit dem realen LCN-Modul übereinstimmen.
2. **LCN-Aktormodul** – das Modul mit den Motorrelais, zum Beispiel M93.
3. **Relaisstatus AUF** – echte Boolean-Statusvariable des AUF-Relais.
4. **Relaisstatus AB** – echte Boolean-Statusvariable des AB-Relais.
5. **GT8 LANG AUF** – Boolean-Status eines simulierten Ausgangs 3. Dieser Ausgang darf von einem beliebigen freien UPU stammen.
6. **GT8 LANG ZU** – Boolean-Status eines simulierten Ausgangs 4. Dieser Ausgang darf ebenfalls von einem beliebigen freien UPU stammen.
7. Prüfen Sie in LCN-PRO, dass der jeweilige fremde Ausgang als zweites Ziel der korrekten GT8-Taste am Haupt-UPU programmiert ist.
8. Tragen Sie die getrennten Gesamtzeiten für `0 % AUF → 100 % ZU` und `100 % ZU → 0 % AUF` ein.
9. **TS-Datenfelder noch nicht bestätigen.** Erst LCN-PRO und PCHK-Busmonitor prüfen.
10. Klicken Sie auf **Übernehmen**.
11. Das Modul zeigt den noch fehlenden Punkt als Instanzstatus an.

## F. TS-Datenfelder sicher freigeben

1. Prüfen Sie in LCN-PRO, welche Tabelle und welche Taste für AUF und AB verwendet werden.
2. Senden Sie die Befehle zunächst mit Busmonitor und ohne unbeaufsichtigten Motorbetrieb.
3. Bestätigen Sie, dass AUF nur die AUF-Taste und AB nur die AB-Taste auslöst.
4. Erst danach setzen Sie im Modul den Haken **TS-Belegung bestätigt**.
5. Klicken Sie auf **Übernehmen**.
6. Der Instanzstatus muss anschließend **Konfiguration vollständig – Laufzeit freigegeben** anzeigen.

## G. Erster sicherer Funktionstest

1. Sorgen Sie für freie Sicht auf die Jalousie und halten Sie eine lokale Stopmöglichkeit bereit.
2. Prüfen Sie zuerst die lokale LCN-KURZ-Bedienung ohne Symcon.
3. Prüfen Sie, dass AUF und AB niemals gleichzeitig aktiv werden.
4. Starten Sie in Symcon eine kurze Fahrt und beobachten Sie die echten Relaisvariablen.
5. Testen Sie STOP.
6. Testen Sie erst danach Endlagen, Lamellenfahrt und ShakeFree.
7. Dokumentieren Sie Abweichungen, bevor eine Automatik freigegeben wird.

## H. Spätere Änderungen veröffentlichen

1. Ändern oder ersetzen Sie Dateien im lokalen Repository-Ordner.
2. GitHub Desktop zeigt sie unter **Changes**.
3. Schreiben Sie eine kurze Summary, zum Beispiel `Fix configuration validation`.
4. Klicken Sie auf **Commit to main**.
5. Klicken Sie auf **Push origin**.
6. In Symcon öffnen Sie Module Control und wählen **Auf Aktualisierung prüfen**.


## Fehler „Konfiguration ist nicht vom Typ Objekt“

Dieser Fehler betraf Version 0.1.2. In Version 0.1.3 wird die Initialkonfiguration des Konfigurators korrekt als JSON-Objekt `{}` ausgegeben. Nach dem Update in der Symcon-Modulverwaltung kann die Zeile „Neue LCN-Jalousie“ über „Alle erstellen“ angelegt werden.
## Kachel und erste Referenz

Nach der Installation beziehungsweise dem Update auf 0.1.24 die Jalousieinstanz einmal mit **Übernehmen** neu anwenden und die Kachel-Visualisierung mit `Strg + F5` neu laden. Die Instanz zeigt anschließend die korrigierte HTML-SDK-Kachel: Behang und Lamellen mit fluchtenden Dreispalten-Layouts aus runden Tasten, mittiger Grafik und rechtem Slider, ShakeFree nach Endlage ZU sowie dem kompakten Laufstatus. Der Instanzname wird von Symcon selbst dargestellt und innerhalb der Kachel nicht wiederholt.

Solange `Position gültig` ausgeschaltet ist, sind 0 %/0 % nur Initialwerte und keine bestätigte reale Stellung. Führen Sie als ersten Abgleich im Modul eine **Referenzfahrt AUF** oder **Referenzfahrt AB** aus. Nach dem kontrollierten Fahrtende setzt das Modul 0 %/0 % beziehungsweise 100 %/100 % und schaltet `Position gültig` ein. Ist die Position noch unbekannt, behandelt das Modul den ersten Symcon-Endlagenauftrag auf 0 % oder 100 % automatisch als volle Referenzfahrt mit der passenden richtungsabhängigen Gesamtzeit plus Referenzreserve. Die explizite Referenzfahrt ist für den Erstabgleich trotzdem am klarsten und bewusst auswählbar.




## Neustart nach Backup ab Version 0.1.19

Beim Erstellen eines Symcon-Backups wird der Dienst kurz neu gestartet. Die Jalousieinstanz übernimmt ihre zuletzt gespeicherten Einstellungen unverändert. Während LCN/PCHK noch startet, kann die Kurzinfo vorübergehend **bereit · Startprüfung** anzeigen; die Bedienung bleibt in diesem Moment sicher gesperrt. Sobald Sendemodul, Aktormodul und LCN-Funktionen verfügbar sind, wird die Instanz automatisch freigegeben. Die Konfiguration muss nicht erneut gespeichert werden.

## Sicherheit ab Version 0.1.13

- Lassen Sie **Symcon-Steuerung aktiv** während der Konfiguration ausgeschaltet, wenn die lokale LCN-Funktion zuerst allein geprüft werden soll.
- Nach 100 % ZU wird zuerst genau ein ZU-STOP gesendet und auf beide ausgewählten Relais AUS gewartet. Erst danach läuft die **Zeitverzögerung / das Kalibrierfenster** standardmäßig 30.000 ms bei ausgeschalteten Relais; es läuft auch bei ausgeschaltetem ShakeFree.
- **ShakeFree nach Endlage ZU** erst nach einer vollständigen ZU-Fahrt ohne ShakeFree und einer erfolgreichen Prüfung dieser Zeitverzögerung aktivieren.
- Prüfen Sie die getrennten Gesamtzeiten für AUF und ZU. Die AUF-Gesamtzeit enthält die vollständige Lamellenwendung.
- Bei **Fehler verriegelt** bleiben Visualisierungsaufträge gesperrt. Reale LCN-/GT8-Fahrten werden weiterhin beobachtet und nach sicherer Endlage plus Kalibrierfenster automatisch ausgeschaltet, solange **Symcon-Steuerung aktiv** eingeschaltet ist. Quittieren Sie erst bei beiden Relais AUS.


## Referenz und Relais-AUS ab Version 0.1.15

- Eine bestätigte Referenz wird zusätzlich als persistentes Modulattribut gespeichert und bleibt bei Fahrtstart, normalem Übernehmen, Rebuild, Neustart, Fehlerverriegelung und Quittierung erhalten. Die aktuelle Zwischenposition wird getrennt von der letzten Referenz-Endlage bewahrt.
- Bei direktem Update von 0.1.13 ist wegen der neuen getrennten Richtungszeiten einmalig eine neue Endlagenreferenz nötig.
- 0 % AUF beziehungsweise 100 % ZU werden nach der jeweiligen Gesamtzeit plus Referenzreserve gesetzt.
- Nach jeder automatischen Endlage wird der aktive Richtungsbefehl genau einmal gestoppt und beide realen Relais müssen AUS bestätigen.
- Nach ShakeFree wird zusätzlich der Lamellen-ZU-Nachlauf nach der vollständigen Wendezeit gestoppt und ebenfalls auf beide Relais AUS geprüft.
- Stellen Sie den Healthcheck für die unabhängige STOP-Überwachung auf 10 Sekunden.


## Relais- und Schnellwechsel-Sicherheit ab Version 0.1.20

- Prüfen Sie nach dem Update jede Instanz auf Status **102 – aktiv**. Status 213 meldet eine doppelte Motorrelais-, GT8- oder TS-Zuordnung zu einer anderen Jalousieinstanz.
- Ein schneller Gegenbefehl unmittelbar nach einer Endlagenfahrt wird gespeichert, solange der bereits gesendete STOP noch auf die reale AUS-Rückmeldung wartet. Der STOP wird nicht erneut getoggelt. Nach bestätigtem Stillstand startet die Gegenfahrt automatisch.
- Nach 100 % ZU muss das ZU-Relais unmittelbar nach dem berechneten Fahrtende und der Referenzreserve ausgeschaltet werden. Das anschließende Kalibrierfenster läuft mit beiden Relais AUS.
- Bleibt ein ausgewähltes Relais nach dem einmaligen STOP aktiv, fragt das Modul den Status des konfigurierten Aktormoduls einmal erneut ab. Es sendet keinen zweiten STOP, sondern verriegelt anschließend sicher.
- Die Fehlerquittierung selbst sendet keinen TS- oder Motorbefehl. Bewegt sich dabei eine andere Jalousie, sind deren eigene Ereignisse sowie die LCN-PRO-TS-Zuordnung separat zu prüfen.

## Stabilitätsprüfung nach Update auf Version 0.1.21

1. In jeder Instanz kontrollieren, dass keine doppelte Relais-, GT8- oder TS-KURZ-Zuordnung gemeldet wird.
2. Den neuen **Mindestabstand zwischen LCN-Telegrammen aller Jalousieinstanzen** zunächst auf 100 ms belassen.
3. Mehrere Jalousien kurz nacheinander anfahren. Die Startbestätigung darf erst nach dem tatsächlich gesendeten Telegramm beginnen; ein nur ausgebliebener Start darf die Instanz nicht dauerhaft verriegeln.
4. Aus 0 % AUF unmittelbar ZU anfordern. Erwartung: Der alte AUF-STOP wird nicht doppelt getoggelt; ZU startet erst nach bestätigtem Relais AUS.
5. Eine Endlagenfahrt ZU ausführen. Erwartung: Das ZU-Relais wird am Fahrtende ausgeschaltet. Falls die erste AUS-Bestätigung fehlt, wird der Status frisch abgefragt und nur bei weiterhin bestätigtem EIN genau ein STOP wiederholt.
6. AUF und ZU jeder Instanz einzeln im LCN-Busmonitor prüfen. Ein eindeutiger TS-Datensatz muss ausschließlich die vorgesehene Jalousie schalten.

## Abnahme nach Update auf Version 0.1.25

1. Jede Jalousie einzeln aus 0 % nach ZU und aus 100 % nach AUF starten. `Position gültig` muss während der gesamten Fahrt eingeschaltet bleiben und die Positionsanzeige fortlaufen.
2. Eine Jalousie extern über GT8 bis zur Endlage fahren lassen. Erwartung: Endlage wird automatisch referenziert; nach dem Kalibrierfenster wird das weiterhin aktive Richtungsrelais einmal ausgeschaltet.
3. Dieselbe Prüfung bei zuvor ungültiger Referenz wiederholen. Vor der sicheren Endlage bleibt die Position ungültig, danach wird sie automatisch gültig.
4. Zwei oder mehr verschiedene Jalousien kurz nacheinander über die Visualisierung starten. Die Telegramme werden bis zur jeweiligen Startbestätigung nacheinander gesendet; anschließend dürfen die Motoren gleichzeitig laufen.
5. Während einer externen GT8-Fahrt ein Visualisierungsziel derselben Instanz auslösen. Der Visualisierungsauftrag muss verworfen werden; die externe Fahrt behält Vorrang.
6. Vor dem Busmonitortest prüfen, dass keine zwei aktiven Symcon-LCN-Modulinstanzen dieselben Werte `Segment`/`Target` am selben Splitter besitzen und dass der angezeigte Instanzname keine andere Adresse nennt.
7. Im Busmonitor jede Instanz mit AUF und ZU prüfen. Falls der Befehl einer Instanz das Relais einer anderen Instanz startet, muss der Sender nach frischer Prüfung seiner beiden ausgewählten Relais einen TS-Routingfehler melden. Die reale Sendemoduladresse oder die LCN-PRO-Zuordnung des TS-Datenfelds ist dann falsch.
8. Nach einem Neustart und nach erneutem **Übernehmen** kontrollieren, dass Referenzstatus und aktuelle Zwischenposition unverändert bleiben.



## Schnelle Mehrfachbedienung und Updates ab Version 0.1.27

Mehrere unterschiedliche Jalousien dürfen direkt nacheinander gestartet werden. Das Modul reiht nur die kurzen LCN-Telegramme mit mindestens 100 ms Abstand ein; nach dem jeweiligen Telegramm und der realen Relaismeldung laufen die Motoren unabhängig gleichzeitig. Eine Gruppenbedienung muss daher nicht mehrere Sekunden zwischen den einzelnen Jalousien warten.

Bei einem normalen Modulupdate bleiben eine gültige Referenz und die aktuelle Position erhalten. Die alten Ereignisse und Timer werden während des Neuaufbaus kurz angehalten und danach automatisch wieder aktiviert. Sind beide Motorrelais AUS, werden reine Update-/Initialisierungsfehler automatisch bereinigt. Echte Relais-, STOP- oder Routingfehler werden aus Sicherheitsgründen weiterhin nicht automatisch quittiert.

Ist die Referenz bereits vor dem Update ungültig, kann sie nicht allein aus dem zuletzt angezeigten Prozentwert sicher wiederhergestellt werden. In diesem Fall ist weiterhin eine vollständige Endlagenfahrt nötig.

