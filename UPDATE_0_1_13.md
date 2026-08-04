# Update auf Version 0.1.13

## Zweck dieser Version

Version 0.1.13 ergänzt zwei sicherheitsrelevante Funktionen:

1. **Kalibrierfenster vor ShakeFree:** Nach einer von Symcon vollständig ausgeführten ZU-Fahrt auf 100 % bleibt die ZU-Ansteuerung zunächst für standardmäßig 30 Sekunden unverändert bestehen. In dieser Zeit sendet Symcon weder STOP noch einen Gegenbefehl. Erst danach wird die ZU-Ansteuerung beendet und – sofern ShakeFree weiterhin aktiviert ist – die ShakeFree-Gegenfahrt ausgeführt.
2. **Fehlerverriegelung:** Bei einem Laufzeit- oder Aufbaufehler wird die Symcon-Steuerung vollständig inaktiv. Ereignisse und Timer werden deaktiviert und es werden keine weiteren LCN-Befehle gesendet. Die lokale LCN-Bedienung bleibt frei. Die Instanz kann erst bei beiden Relais AUS quittiert und wieder aktiviert werden.

Zusätzlich besitzt die Instanz jetzt die Eigenschaft **Symcon-Steuerung aktiv**. Damit kann der Benutzer die Symcon-Automatik bewusst aus- und später wieder einschalten, ohne die lokale LCN-Funktion zu verändern.

## Wichtige Verhaltensänderung

Das Kalibrierfenster gilt **nach jeder von Symcon vollständig ausgeführten ZU-Fahrt auf 100 %**, unabhängig davon, ob ShakeFree eingeschaltet ist. Dadurch beendet Symcon die ZU-Ansteuerung nicht sofort an der berechneten Endlage.

Ablauf bei einer vollständigen ZU-Fahrt:

1. Reale ZU-Fahrt bis zur berechneten Position 100 %.
2. Phase **Kalibrierfenster** für standardmäßig 30.000 ms.
3. Während des Fensters kein automatischer STOP und kein Gegenbefehl.
4. Nach Ablauf wird der aktive ZU-KURZ-Befehl erneut gesendet und das reale Abschalten des Relais abgewartet.
5. Nur wenn ShakeFree zu diesem Zeitpunkt aktiviert ist, folgt nach der kurzen Umschaltpause die konfigurierte ShakeFree-Gegenfahrt.

Ein manueller STOP bleibt als Notbedienung möglich. Er beendet das Kalibrierfenster bewusst; danach wird ShakeFree für diesen Auftrag verworfen.

## Update über GitHub Desktop

1. ZIP-Datei entpacken.
2. In GitHub Desktop das Repository `SymconLCNJalousie` auswählen.
3. **Repository → Show in Explorer** öffnen.
4. Den gesamten Inhalt des entpackten Ordners in das vorhandene Repository kopieren.
5. Vorhandene Dateien ersetzen; den versteckten Ordner `.git` nicht löschen.
6. In `library.json` kontrollieren:

   ```json
   "version": "0.1.13",
   "build": 14
   ```

7. Commit-Nachricht:

   ```text
   Add calibration window and fault latch
   ```

8. **Commit to main** und anschließend **Push origin**.
9. In Symcon **Kern Instanzen → Module** öffnen.
10. **Auf Aktualisierung prüfen** und Version 0.1.13 installieren.
11. Die Jalousieinstanz öffnen und **Übernehmen** wählen.

## Neue Einstellungen

Im Abschnitt **1. Allgemein**:

- **Symcon-Steuerung aktiv**
  - EIN: Modul darf bei vollständiger Konfiguration steuern.
  - AUS: Ereignisse und Timer sind inaktiv; das Modul sendet keine LCN-Befehle. Die lokale LCN-Bedienung bleibt verfügbar.

Im Abschnitt **4. Laufzeiten**:

- **Kalibrierfenster nach 100 % ZU vor ShakeFree**
  - Vorgabe: `30000 ms`
  - erlaubter Bereich: `30000 … 120000 ms`

## Verhalten bei Fehlern

Bei einem internen Fehler oder einer fehlenden Relaisbestätigung:

- Instanzstatus: **Fehler verriegelt – Symcon inaktiv bis zur Quittierung**
- alle Modulereignisse inaktiv
- Worker- und Healthcheck-Timer aus
- keine automatische Wiederholung eines LCN-Tastenbefehls
- ShakeFree aus
- Positionsreferenz ungültig
- lokale LCN-Bedienung bleibt unbeeinflusst

### Fehler quittieren

1. Jalousie lokal in einen sicheren Stillstand bringen.
2. Im Objektbaum prüfen: Relais AUF = AUS und Relais AB = AUS.
3. Im Modulmenü **Fehler quittieren (nur bei Relais AUS)** wählen.
4. Die Instanz initialisiert sich neu und fordert den LCN-Status an.
5. Vor Zwischenpositionen erneut eine Endlage referenzieren.

Die Quittierung sendet selbst keinen Motorbefehl.

## Testreihenfolge

ShakeFree erst nach erfolgreichem Grundtest aktivieren:

1. **Symcon-Steuerung aktiv = EIN**, ShakeFree = AUS.
2. Lokale LCN-Bedienung AUF/STOP/ZU/STOP prüfen.
3. Symcon-Fahrt auf eine Zwischenposition und STOP prüfen.
4. Vollständige ZU-Fahrt mit ShakeFree AUS ausführen.
5. Kontrollieren, dass nach Erreichen von 100 % die Anzeige **Kalibrierfenster 30 s** erscheint und das ZU-Relais währenddessen aktiv bleibt.
6. Nach Ablauf muss das ZU-Relais abschalten; es darf keine Gegenfahrt stattfinden.
7. Erst danach ShakeFree einschalten und denselben Ablauf beaufsichtigt wiederholen.
8. Erwartung nach dem Kalibrierfenster: ZU-Relais AUS, kurze Umschaltpause, AUF-Relais für die konfigurierte ShakeFree-Zeit EIN, anschließend AUS.

Während des ersten Tests muss die lokale LCN-Bedienung erreichbar sein. Bei ungewöhnlichem Motorverhalten, gleichzeitig aktiven Relais oder einer nicht endenden Fahrt ist die Motorversorgung sicher abzuschalten und ShakeFree wieder zu deaktivieren.
