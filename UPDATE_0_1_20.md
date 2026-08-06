# Update 0.1.20 – Relais-, Toggle- und Schnellwechsel-Sicherheit

Version 0.1.20 behebt eine Rennbedingung bei schnell aufeinanderfolgenden Fahrbefehlen sowie die dauerhaft aktive ZU-Ansteuerung während des bisherigen Kalibrierfensters.

## Ursache des sporadischen Bestätigungsfehlers

Nach einer berechneten Endlage wurde der richtungsabhängige KURZ-Befehl bereits einmal als STOP gesendet. Traf vor der realen Relais-AUS-Rückmeldung ein neuer oder entgegengesetzter Auftrag ein, konnte der alte Code denselben KURZ-Befehl ein zweites Mal senden. Da die LCN-Taste als Toggle arbeitet, konnte dieses zweite Telegramm das Relais wieder einschalten. Der neue Auftrag wartete anschließend auf eine andere Richtung und endete mit „Keine reale Relaisbestätigung innerhalb der eingestellten Zeit“.

Das konnte besonders bei einem schnellen ZU-Befehl direkt nach Erreichen von 0 % AUF auftreten. Das 30-s-Kalibrierfenster selbst existiert nur nach 100 % ZU; an der oberen Endlage war die noch laufende STOP-/AUS-Bestätigung die relevante Übergangsphase.

## Korrekturen

- Ein richtungsabhängiger STOP wird pro Ablauf exakt einmal gesendet.
- Neue Aufträge während der AUS-Bestätigung werden nur vorgemerkt. Nach der echten AUS-Meldung starten sie automatisch.
- Bei verzögerter Rückmeldung wird einmal `LCN_RequestStatus` für das konfigurierte Aktormodul ausgelöst. Es wird kein zweiter Toggle gesendet.
- Nach 100 % ZU wird das ZU-Relais sofort gestoppt. Das Kalibrierfenster beginnt erst nach bestätigtem Relais AUS und läuft vollständig stromlos.
- Fahrbefehle verwenden unmittelbar die Properties der eigenen Modulinstanz. Veraltete interne Kopien können keine fremde Hardwarezuordnung mehr liefern.
- Doppelte Motorrelais-, GT8- oder TS-Zuordnungen zwischen Jalousieinstanzen werden erkannt. Die betroffenen Instanzen erhalten Status 213 und senden keine Fahrbefehle.
- Relaisereignisse werden nur verarbeitet, wenn die auslösende Variable exakt einer der zwei in der Instanz ausgewählten Motorrelaisvariablen entspricht.
- Diagnoseausgabe ergänzt aktuelle Relaiswerte, Phase, Auftrag, erwartete Richtung, STOP-Zustand, Folgeauftrag und Statusabfrage-Wiederholungen.

## Update und Kontrolle

1. Modul auf Version 0.1.20 aktualisieren und jede Instanz einmal öffnen.
2. Prüfen, dass keine Instanz Status 213 meldet. Bei einem Konflikt die genannten doppelten IDs oder TS-Befehle korrigieren.
3. AUF und ZU jeder Jalousie einzeln testen und im Busmonitor kontrollieren, dass der jeweilige TS-Befehl ausschließlich den vorgesehenen Aktor bedient.
4. Einen schnellen Gegenbefehl direkt nach Erreichen von 0 % AUF testen. Erwartung: AUF wird einmal gestoppt, nach bestätigtem AUS startet ZU; keine Fehlerverriegelung.
5. Eine vollständige Fahrt auf 100 % ZU testen. Erwartung: ZU wird am Fahrtende ausgeschaltet; das anschließende Kalibrierfenster läuft bei beiden Relais AUS.
6. Bei weiterhin sporadischen Rückmeldungsfehlern die Diagnose direkt nach dem Fehler sichern. Entscheidend sind `runtime.relayUpValue`, `runtime.relayDownValue`, `runtime.phase`, `runtime.expectedDirection`, `runtime.stopRequested` und die beiden Retry-Felder.

Die LCN-PRO-Verriegelung, Endlagen und reale Relaisabschaltung bleiben die maßgeblichen Sicherheitsebenen. Vor produktivem Betrieb ist ein Test an der konkreten Anlage erforderlich.
