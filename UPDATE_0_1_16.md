# Update auf Version 0.1.16 – richtungsabhängiger Sanft-Stopp

> **Hinweis:** Die Behandlung wurde in 0.1.18 präzisiert: Sanft-Stopp ist positionsabhängig. Zwischenziele außerhalb der berechneten Endzone fahren vollständig mit voller Geschwindigkeit; Zwischenziele innerhalb der Endzone berücksichtigen den bis zu ihrer Position durchfahrenen Anteil der Verzögerung.

Version 0.1.16 ergänzt die gemessene lineare Geschwindigkeitsreduzierung unmittelbar vor den Endlagen.

## Neue Einstellungen

Im Abschnitt **4. Laufzeiten** stehen zwei getrennte Werte zur Verfügung:

- **Sanft-Stopp vor Endlage AUF (0 %)** – Standard: 4.500 ms
- **Sanft-Stopp vor Endlage ZU (100 %)** – Standard: 4.500 ms

`0 ms` deaktiviert die Korrektur für die jeweilige Richtung. Der Wert muss kleiner als die zugehörige reine Behanglaufzeit sein.

## Berechnungsmodell

Außerhalb der Endzonen rechnet das Modul weiterhin mit voller, konstanter Geschwindigkeit. Erst während der eingestellten letzten Fahrzeit vor der angefahrenen Endlage wird die Geschwindigkeit linear bis auf 0 reduziert.

Die Kennlinie wird an zwei Stellen identisch verwendet:

1. laufende Fortschreibung der angezeigten Behangposition,
2. Berechnung der erforderlichen Fahrzeit bis zu einer gewählten Zwischenposition.

Dadurch werden insbesondere Prozentwerte und Stopppunkte außerhalb beziehungsweise am Beginn der Endzonen genauer.

## Update

1. Repository-Inhalt durch Version 0.1.16 ersetzen.
2. In `library.json` kontrollieren:

   ```json
   "version": "0.1.16",
   "build": 17
   ```

3. In Symcon das Modul aktualisieren.
4. Die Jalousieinstanz öffnen und **Übernehmen** wählen.
5. Die beiden Sanft-Stopp-Werte prüfen; voreingestellt sind jeweils 4.500 ms.
6. Eine vollständige AUF- und ZU-Fahrt beobachten und die Werte bei Bedarf getrennt feinjustieren.

Eine bereits gültige Endlagenreferenz bleibt erhalten. Für eine messtechnische Kontrolle empfiehlt sich dennoch eine vollständige Fahrt zu beiden Endlagen.
