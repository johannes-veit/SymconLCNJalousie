# Update 0.1.29 – Visualisierungsreaktion und schnelle Folgebefehle

V0.1.29 baut direkt auf der kompakten V0.1.28 auf. Es werden keine Konfigurations-, Relais-, Fahrzeit-, Referenz- oder Migrationsregeln geaendert.

## Korrekturen

- Der Initialzustand der HTML-SDK-Kachel wird vor dem ersten Render serverseitig eingesetzt. Dadurch gibt es keinen sichtbaren Zwischenzustand mit 0 % und deaktivierter Bedienung.
- Eine laufende HTML-SDK-Anfrage sperrt die Bedienelemente nicht mehr optisch.
- Schnelle unterschiedliche Sollbefehle werden lokal zusammengefasst. Waehrend ein Befehl noch auf die Serverbestaetigung wartet, wird der letzte Folgebefehl gepuffert statt verworfen.
- Gleiche Doppelklicks werden weiterhin entprellt.
- STOP hat Vorrang und loescht wartende Folgeziele.
- Bei `Failed to fetch` oder einem anderen unklaren Transportfehler gibt es keine automatische Wiederholung.

## Sicherheit

Die Backend-Zustandsmaschine und alle LCN-Telegramm-/Relaispruefungen entsprechen V0.1.28.
