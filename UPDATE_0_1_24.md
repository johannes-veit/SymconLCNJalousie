# Update 0.1.24

Diese Version korrigiert die Behandlung eines eindeutig erkannten TS-Fremdstarts.

## Ursache

`LCN_SendCommand()` adressiert das ausgewählte LCN-Sendemodul und das dort programmierte virtuelle Tastenfeld. Aktormodul und Relaisstatusvariablen sind Rückmeldungen; sie ändern das physische Ziel des TS-Befehls nicht. Reagiert eine andere Jalousie, ist die Kombination aus Sendemodul und TS-KURZ in LCN-PRO falsch zugeordnet.

## Neues Schutzverhalten

- Fremde Relaisantworten werden während der laufenden Startbestätigung sofort ausgewertet.
- Der Sender wird mit einem ausführlichen TS-Routingfehler verriegelt.
- Die exakt widerlegte Kombination bleibt auch nach Fehlerquittierung gesperrt.
- Zum Freigeben muss entweder Sendemodul/TS geändert werden oder die TS-Bestätigung zunächst deaktiviert und gespeichert, anschließend nach Busmonitor-Prüfung wieder aktiviert und gespeichert werden.
- Die Positionsreferenz bleibt bei einem fehlgeleiteten, nicht durch das eigene Relais bestätigten Auftrag unverändert.

## Wichtig

Die Software kann eine physische LCN-PRO-Tastenbelegung erst nach der ersten realen Relaisreaktion prüfen. Vor der produktiven Freigabe müssen AUF und ZU jeder Instanz im Busmonitor einzeln gegen das gewünschte Motorrelais geprüft werden.
