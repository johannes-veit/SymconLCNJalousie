# Update 0.1.23

## Behobene Fehler

- Eine gültige Referenz wird beim Start eines neuen Fahrbefehls nicht mehr gelöscht.
- Die aktuelle Zwischenposition bleibt bei ApplyChanges und Neustart erhalten; die letzte sichere Referenz-Endlage wird davon getrennt gespeichert.
- Externe LCN-/GT8-Fahrten werden nach sicherer Endlage plus Kalibrierfenster automatisch über den real aktiven Richtungsbefehl ausgeschaltet.
- Der externe Endlagenablauf besitzt zwei unabhängige Sicherungen: 1-s-Worker und Healthcheck. Das gilt auch bei einer Fehlerverriegelung, solange das Modul grundsätzlich aktiviert ist.
- Noch nicht bestätigte Toggle-Telegramme verschiedener Jalousieinstanzen werden serialisiert. Nach realer Startbestätigung dürfen mehrere Jalousien gleichzeitig fahren.
- Alte Befehls-Sperren überleben weder Kernelneustart noch ApplyChanges.
- Reagiert auf den Befehl einer Instanz ausschließlich das Relais einer anderen Instanz, wird der Sender mit einer eindeutigen TS-Routingmeldung verriegelt.

## Wichtige Abnahme

Nach dem Update jede Instanz einmal einzeln in beide Richtungen im LCN-Busmonitor prüfen. Symcon kann doppelte IDs und identische TS-Datenfelder erkennen, aber eine physisch falsch programmierte LCN-PRO-Tastenzuordnung erst anhand der realen Relaisantwort nach dem ersten Telegramm feststellen.

Die Referenz muss nicht pauschal neu aufgebaut werden. Eine bereits gültige Referenz und die aktuelle Positionsanzeige werden übernommen.
