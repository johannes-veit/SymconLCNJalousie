# Update 0.1.25 – Adressprüfung und stabile Startbestätigung

## Reale LCN-Adresse statt Instanzname

Symcon verwendet bei `LCN_SendCommand()` die intern gespeicherten Werte `Segment` und `Target`. Der sichtbare Instanzname ist für die Zieladresse nicht maßgeblich. Version 0.1.25 liest deshalb die tatsächliche LCN-Adresse aller ausgewählten Module aus und zeigt sie im Konfigurationsformular sowie in der Diagnose an.

Die Steuerung wird mit Status 214 gesperrt, wenn:

- ein Instanzname eine Adresse wie `(000,011)` enthält, intern aber ein anderes Target gespeichert ist,
- zwei aktive Symcon-LCN-Modulinstanzen über denselben Splitter auf dieselbe reale Segment-/Target-Adresse zeigen,
- identische TS-KURZ-Befehle über dieselbe reale Senderoute von mehreren Jalousieinstanzen verwendet werden.

Eine Korrektur von Segment/Target verändert den Routing-Fingerprint und hebt eine alte Routingsperre automatisch auf. Eine unveränderte, bereits physisch widerlegte Route muss weiterhin über die zweistufige TS-Abnahme erneut freigegeben werden.

## Startbestätigung

Ein fehlender Relaisstart ist bei weiterhin ausgeschalteten Relais kein gefährlicher Motorzustand. Deshalb gilt nun:

1. Die erste Bestätigungsfrist beginnt weiterhin erst nach erfolgreich angenommenem LCN-Telegramm.
2. Bleibt die Relaismeldung aus, wird das ausgewählte Aktormodul abgefragt.
3. Ist die Statusabfrage vorübergehend nicht möglich, läuft trotzdem ein zweites vollständiges Bestätigungsfenster.
4. Bleiben beide ausgewählten Relais AUS, wird nur der Auftrag verworfen. Die Instanz bleibt betriebsbereit und eine gültige Positionsreferenz bleibt erhalten.
5. Ein verspäteter realer Start innerhalb des Schutzfensters wird weiterhin erkannt und kontrolliert beendet; nur dann wird die Referenz wegen unklarem Startzeitpunkt ungültig.

Die alte verriegelnde Meldung `Keine reale Relaisbestaetigung innerhalb der eingestellten Zeit.` wird beim Update automatisch entfernt, wenn beide ausgewählten Relais nachweislich AUS sind. Andere Fehler und aktive Relais werden nicht automatisch quittiert. Eine bereits ungültige Referenz wird dabei nicht künstlich wiederhergestellt.

## Fremdstart-Erkennung

Eine zeitgleich betätigte externe GT8-Taste darf nicht allein aufgrund ihres Zeitstempels eine falsche Routingsperre auslösen. Ein Fremdstart wird deshalb erst dauerhaft gesperrt, wenn:

- genau eine Symcon-START-Transaktion offen war,
- die Fremdmeldung in deren Bestätigungsfenster lag,
- eine frische Statusabfrage beide ausgewählten Relais des Senders weiterhin als AUS bestätigt,
- Senderoute, Richtung und TS-Befehl noch exakt zum offenen Auftrag gehören.

## Skriptstand

Controller, Worker, Healthcheck und Diagnose tragen nun die Kennung **V11.8**. Beim Übernehmen werden ältere generierte Skripte ersetzt; die Diagnose erkennt einen verbliebenen alten Skriptstand.

Die Sanft-Stopp-, Positions-, Referenz-, externe Autostopp- und Mehrinstanzberechnung aus 0.1.23/0.1.24 wurde nicht verändert.
