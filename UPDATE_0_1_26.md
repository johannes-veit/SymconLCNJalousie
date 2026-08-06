# Update 0.1.26 – stabile Visualisierungsübertragung

## Hintergrund

Die Meldung `ClientException: Failed to fetch, uri=/api/` entsteht, wenn die HTML-SDK-Kachel den Symcon-API-Endpunkt während eines Bedienbefehls vorübergehend nicht erreicht. Das kann beispielsweise bei einem kurzen Verbindungsabbruch, einem Neustart, einem Wechsel zwischen WLAN und Mobilfunk oder einer neu geladenen Visualisierung auftreten.

Das Modul kann die Erreichbarkeit des Symcon-Servers nicht garantieren. Es verhindert nun aber, dass ein solcher Transportfehler zu unkontrollierten Folgeaktionen oder mehrfach gesendeten Toggle-Befehlen führt.

## Änderungen

- Asynchrone API-Fehler und nicht behandelte Promise-Ablehnungen werden innerhalb der Kachel abgefangen.
- Die rohe ClientException wird, soweit der jeweilige Symcon-Client den Fehler an die Kachel weitergibt, durch eine verständliche lokale Warnung ersetzt.
- Solange ein Bedienbefehl noch keine Serverrückmeldung erhalten hat, sind weitere Nicht-STOP-Befehle gesperrt.
- Sehr schnelle Doppelklicks innerhalb von 350 ms werden verworfen.
- STOP bleibt verfügbar, sofern der Server ihn laut letztem Zustand freigegeben hat.
- Nach acht Sekunden ohne Rückmeldung wird die lokale Übertragungssperre gelöst und der Nutzer zur Kontrolle des realen Relaiszustands aufgefordert.
- Ein fehlgeschlagener oder unklar bestätigter Befehl wird niemals automatisch erneut gesendet. Das ist bei LCN-KURZ-/Toggle-Befehlen wichtig, weil eine Wiederholung einen bereits geschalteten Ausgang wieder umschalten könnte.
- Offline-/Online-Ereignisse der Visualisierung werden angezeigt.

## Unverändert

Die Controllerlogik, reale Relaisbestätigung, LCN-Adressprüfung, Referenzpersistenz, externe GT8-Priorität, Mehrinstanz-Sendesperre, Endlagen-Autostopp und Sanft-Stopp-Berechnung entsprechen Version 0.1.25.

## Nach dem Update

Die Instanzkonfiguration muss nicht neu angelegt werden. Nach dem Modulupdate die Konfiguration einmal übernehmen oder die Visualisierung neu laden, damit die aktualisierte HTML-Kachel verwendet wird.
