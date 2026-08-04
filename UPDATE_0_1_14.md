# Update auf Version 0.1.14

## Inhalt

Version 0.1.14 ergänzt getrennte Gesamtzeiten für AUF und ZU, präzisiert ShakeFree als **ShakeFree nach Endlage ZU**, beschreibt das Kalibrierfenster zusätzlich als **Zeitverzögerung** und erlaubt GT8-LANG-Ereignisvariablen von beliebigen freien LCN-UPU.

## Vor dem Update notieren

Notieren Sie die aktuell eingestellten Werte:

- bisherige Gesamtlaufzeit: wird als Gesamtzeit `100 % ZU → 0 % AUF` weiterverwendet,
- bisherige reine Behanglaufzeit: wird als Gesamtzeit `0 % AUF → 100 % ZU` weiterverwendet,
- Wendezeit,
- Referenzreserve,
- maximale Fahrt.

Die vorhandenen Property-IDs bleiben aus Kompatibilitätsgründen erhalten. Daher gehen die eingestellten Werte beim Update nicht verloren.

## Neue Bedeutung der beiden Zeitfelder

- **Gesamtlaufzeit 100 % ZU → 0 % AUF** enthält die vollständige Lamellenwendung.
- **Gesamtlaufzeit 0 % AUF → 100 % ZU** beschreibt die vollständige Schließfahrt ab oberer Endlage.

Das Modul berechnet daraus:

- Behanglaufzeit AUF = Gesamtzeit 100→0 minus Wendezeit,
- Behanglaufzeit ZU = Gesamtzeit 0→100.

## GT8-LANG-Ausgänge

Der simulierte Ausgang 3 oder 4 darf von einem beliebigen freien UPU stammen. Er muss nicht mit dem Haupt-UPU, TS-Sendemodul oder Aktormodul identisch sein.

In LCN-PRO muss jedoch gelten:

1. richtige GT8-Taste am Haupt-UPU öffnen,
2. zweites Ziel dieser Taste anlegen,
3. beliebiges freies UPU als Ziel wählen,
4. dort Ausgang 3 beziehungsweise 4 mit LANG umschalten,
5. KURZ und LOS im zweiten Ziel unprogrammiert lassen.

## Installation

1. ZIP entpacken.
2. Inhalt in das vorhandene GitHub-Repository kopieren.
3. Dateien ersetzen.
4. In GitHub Desktop committen, zum Beispiel mit `Add directional travel times and clarify GT8 sources`.
5. `Push origin`.
6. In Symcon unter **Kern Instanzen → Modules** auf Aktualisierung prüfen.
7. Version 0.1.14 installieren.
8. Jalousieinstanz öffnen und **Übernehmen** anklicken.
9. Beide Richtungs-Gesamtzeiten kontrollieren.
10. Nach dem Update steht `Position gültig` aus Sicherheitsgründen auf AUS; eine neue Endlagenreferenz ist erforderlich.
11. Eine Referenzfahrt und anschließend je eine vollständige Fahrt AUF und ZU beaufsichtigt prüfen.

## Sicherheit

ShakeFree bleibt ausschließlich **nach Endlage ZU** aktiv. Die Zeitverzögerung / das Kalibrierfenster läuft nach jeder vollständigen Symcon-Fahrt auf 100 % ZU, auch wenn ShakeFree ausgeschaltet ist.
