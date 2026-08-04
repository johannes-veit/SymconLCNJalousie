# Update 0.1.17 – Sanft-Stopp nur an Endlagen

> **Überholt durch 0.1.18:** Die Annahme, Zwischenziele innerhalb der Endzone würden ebenfalls mit voller Geschwindigkeit erreicht, war nicht korrekt. Ab 0.1.18 wird die physische, positionsabhängige Endzone berücksichtigt. Es gibt weiterhin keine zusätzliche Ziel-Abbremsung; innerhalb der Endzone ist die Fahrgeschwindigkeit jedoch bereits reduziert.

Version 0.1.17 korrigiert die in 0.1.16 eingeführte Sanft-Stopp-Berechnung.

## Verhalten

- Ziel **0 % AUF**: Die letzten, separat konfigurierten Millisekunden werden linear von voller Geschwindigkeit bis 0 modelliert.
- Ziel **100 % ZU**: Die letzten, separat konfigurierten Millisekunden werden linear von voller Geschwindigkeit bis 0 modelliert.
- Ziel **1–99 %**: Der Behang fährt rechnerisch mit voller Geschwindigkeit bis zum Ziel; erst dort sendet Symcon STOP. Es wird keine Sanft-Stopp-Phase vor einer Zwischenposition simuliert.
- Standardwerte: `Sanftstopp_AUF_ms = 4500` und `Sanftstopp_ZU_ms = 4500`. Mit `0 ms` ist die jeweilige Korrektur deaktiviert.

Die volle Fahrgeschwindigkeit wird aus der gemessenen Endlagenlaufzeit berechnet. Da während des Sanft-Stopps nur die halbe Wegfläche zurückgelegt wird, entspricht die Vollgeschwindigkeits-Zeit für 100 % Weg der konfigurierten Laufzeit minus der halben Sanft-Stopp-Zeit.

Nach dem Update wird ein kontrollierter Test mit mehreren Zwischenpositionen sowie beiden Endlagen empfohlen. Eine vorhandene Endlagenreferenz bleibt erhalten, weil 0 % und 100 % unverändert definiert sind.
