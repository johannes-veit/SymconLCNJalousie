# Update 0.1.27 – Mehrfachstart und Updatepersistenz

## Mehrere Jalousien in kurzer Folge

Mehrere unterschiedliche Jalousieinstanzen können innerhalb weniger Sekunden gestartet werden. Der globale Schutz umfasst nur noch den eigentlichen Telegrammversand. Zwischen zwei LCN-Telegrammen werden mindestens 100 ms eingehalten; danach warten die Instanzen unabhängig auf ihre jeweils eigenen realen Relaisrückmeldungen. Die Motoren dürfen parallel laufen.

Die bisherige synchrone Wartezeit auf die Relaisbestätigung einer anderen Instanz wurde entfernt. Dadurch blockiert ein langsames oder ausgebliebenes Relaisfeedback nicht länger den API-Aufruf einer weiteren Jalousie und begünstigt keine unnötigen `Failed to fetch`-Meldungen.

## Verhalten während eines Modulupdates

Vor dem Neuaufbau der Laufzeitskripte werden Relais-/GT8-Ereignisse sowie Worker und Healthcheck kurz deaktiviert. Bedienaufrufe während dieser Wartungsphase werden ohne LCN-Telegramm verworfen. Nach dem Aufbau folgt die normale Validierung und Statussynchronisierung.

Reine Update-/Initialisierungsverriegelungen werden automatisch entfernt, wenn:

- die Konfiguration wieder vollständig gültig ist,
- die LCN-Laufzeit verfügbar ist,
- beide ausgewählten Motorrelais sicher AUS melden.

Nicht automatisch quittiert werden echte Sicherheitsfehler wie aktive Relais nach STOP, beide Richtungen gleichzeitig oder ein bestätigter TS-Routingfehler.

Wenn `LCN_RequestStatus` bei unverändert ausgeschalteten Relais keine neuen OnUpdate-Ereignisse liefert, übernimmt das Modul den bereits in Symcon vorhandenen AUS-Zustand. Dadurch entsteht nach einem Routineupdate keine Quittierungsanforderung für alle Jalousien.

## Referenz

Eine gültige Referenz und die aktuelle Zwischenposition bleiben bei normalen Updates erhalten. Eine neue Referenzfahrt ist nicht routinemäßig erforderlich. Sie bleibt notwendig, wenn die Referenz schon vor dem Update ungültig war, ein inkompatibles Altmodell migriert wird oder ein Bewegungsablauf tatsächlich positionsunsicher war. Eine in einer alten Version bereits gelöschte Referenz wird nicht aus einem möglicherweise veralteten Prozentwert erfunden.

## Nach dem Update

Die Visualisierung einmal vollständig neu laden. Bei stromlosen Relais sollten reine alte Updatefehler automatisch verschwinden. Bleibt eine Instanz verriegelt, handelt es sich entweder um einen nicht als transient eingestuften Sicherheitsfehler oder um eine weiterhin nicht bestandene Konfigurations-/Laufzeitprüfung; die Diagnose zeigt den konkreten Grund.
