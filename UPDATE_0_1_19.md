# Update 0.1.19 – stabile Konfiguration nach Symcon-Neustart

Version 0.1.19 behebt eine falsche Statusmeldung nach einem Backup- oder Dienstneustart. Bisher konnte die Jalousieinstanz während der Symcon-Initialisierung prüfen, bevor das ausgewählte LCN-Sendemodul seinen endgültigen Status 102 und die LCN-Funktionen registriert hatte. Die gespeicherte Auswahl war korrekt, wurde aber vorübergehend als fehlend oder ungültig bewertet.

## Neues Startverhalten

1. Während `KR_INIT` werden die gespeicherten IDs und Objektzuordnungen strukturell geprüft.
2. Die Motorbedienung bleibt bis zur vollständigen Laufzeitprüfung gesperrt.
3. Nach `IPS_KERNELSTARTED` werden Sendemodul, Aktormodul, GT8-Quellmodule und LCN-Funktionen automatisch erneut geprüft.
4. Verzögert startende LCN/PCHK-Instanzen erhalten bis zu 30 Sekunden Startkulanz.
5. Eine vorübergehend fehlende LCN-Laufzeit erzeugt keinen Fehlerstatus für die gespeicherte Konfiguration; die Instanz bleibt Status 102, während Bedienung und Ereignisse sicher gesperrt sind.
6. Der vorhandene Healthcheck und Instanzstatusänderungen führen weitere automatische Prüfungen aus.
7. Sobald alle Abhängigkeiten bereit sind, wird die Instanz ohne erneutes Speichern freigegeben und initialisiert.

## Erhaltene Daten

- Alle Modul-Properties bleiben unverändert gespeichert.
- Die ausgewählten Instanz- und Variablen-IDs werden nicht zurückgesetzt.
- Eine persistente Positionsreferenz wird durch den vorübergehenden Startzustand nicht gelöscht.
- Die Sanft-Stopp-Kennlinie aus 0.1.18 bleibt unverändert.

## Update

1. Modul auf Version 0.1.19 aktualisieren.
2. Einmal **Übernehmen** wählen, damit der neue Modulstand aktiv ist.
3. Anschließend einen kontrollierten Symcon-Neustart durchführen.
4. Die Instanz darf kurz **bereit · Startprüfung** anzeigen und muss danach selbständig auf **bereit** wechseln.
5. Es darf kein erneutes Speichern der unveränderten Konfiguration notwendig sein.
