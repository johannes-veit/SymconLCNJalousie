# Update 0.1.18 – positionsabhängiger Sanft-Stopp-Fahrweg

Version 0.1.18 bildet den Sanft-Stopp als festen Fahrwegabschnitt vor jeder Endlage ab. Der Abschnitt wird getrennt für AUF und ZU aus der jeweiligen Behanglaufzeit und der eingestellten Sanft-Stopp-Zeit berechnet.

## Berechnung

Für die richtungsabhängige Behanglaufzeit `T` und die Sanft-Stopp-Zeit `S` gilt:

```text
Sanft-Stopp-Fahrweg [%] = 100 × S / (2 × T − S)
```

Der Faktor entsteht, weil die Geschwindigkeit während der Zeit `S` linear von voller Geschwindigkeit auf 0 sinkt. Der in dieser Phase gefahrene Weg entspricht daher einer Dreiecksfläche mit der halben Vollgeschwindigkeitsstrecke.

Beispiel: Ergibt die Rechnung 5 %, liegen die Endzonen bei **0–5 % AUF** und **95–100 % ZU**.

- Ziel 95 % ZU: Zonenbeginn, noch keine Sanft-Stopp-Zeit
- Ziel 96 % ZU: kleiner Anteil der Sanft-Stopp-Phase
- Ziel 97/98/99 % ZU: zunehmend größerer Anteil
- Ziel 100 % ZU: vollständige eingestellte Sanft-Stopp-Zeit

Für AUF gilt das spiegelbildlich von 5 % bis 0 %.

## Wichtig

Das Modul simuliert keine zusätzliche Abbremsung vor einer Zwischenposition. Es berücksichtigt lediglich, dass der Motor innerhalb der physischen Endzone bereits langsamer fährt. Außerhalb der Endzone bleibt die Zeit-Weg-Zuordnung linear.

## Updatehinweise

1. Modul auf Version 0.1.18 aktualisieren und **Übernehmen** wählen.
2. Die Werte **Sanft-Stopp AUF** und **Sanft-Stopp ZU** prüfen; Standard jeweils 4.500 ms.
3. Im Konfigurationsformular oder in der Diagnose die berechneten Prozentgrenzen kontrollieren.
4. Eine neue Endlagenreferenz ausführen. Version 0.1.18 verwirft die bisherige Referenz einmalig, weil sich die Zeit-Weg-Kennlinie geändert hat.
5. Reale Testfahrten zu einer Position unmittelbar vor der Endzone, mehreren Positionen innerhalb der Endzone und zur Endlage durchführen.

Die Relais-, STOP-, Referenzreserve-, Kalibrierfenster- und Fehlerverriegelungslogik aus Version 0.1.15 bleibt unverändert.
