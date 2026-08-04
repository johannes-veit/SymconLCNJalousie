# Update 0.1.12 – Notfall-Hotfix ShakeFree / lokale LCN-Bedienung

## Behobene Fehler

- Ein Starttimeout der ShakeFree-Gegenfahrt konnte die Instanz in Phase FEHLER halten.
- In diesem Zustand wurde jeder spätere reale Relaisstart automatisch durch einen erneuten KURZ-Befehl gestoppt. Dadurch konnte die lokale LCN-Bedienung blockiert werden.
- Fehlerzustände greifen jetzt niemals mehr aktiv in lokale/externe LCN-Fahrten ein.
- Starttimeouts enden nach dem Spätstart-Schutz wieder im Stillstand und bleiben nur als Warntext sichtbar.
- Vor der ShakeFree-Gegenfahrt gibt es eine konfigurierbare Umschaltpause (Standard 500 ms). Die Gegenfahrtdauer bleibt unverändert 6500 ms.
- Fehler können bei beiden Relais AUS über Konfigurationsmenü oder Kachel quittiert werden. Dabei wird kein LCN-Befehl gesendet.

## Sofortmaßnahme vor dem Update

1. In der Modulkonfiguration den Haken **TS-Belegung bestätigt** entfernen und **Übernehmen** drücken. Dadurch werden Relais-/GT8-Ereignisse sowie Worker- und Healthcheck-Timer deaktiviert.
2. Prüfen, dass beide Motorrelais AUS sind.
3. ShakeFree ausgeschaltet lassen.
4. Update installieren und danach Konfiguration wieder freigeben.

## Test nach dem Update

- Lokale AUF-/AB-/STOP-Bedienung muss auch bei angezeigtem Fehler unbeeinflusst bleiben.
- Fehler quittieren nur im Stillstand.
- ShakeFree zunächst beaufsichtigt testen.
