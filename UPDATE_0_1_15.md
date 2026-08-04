# Update auf Version 0.1.15 – persistente Referenz und Relais-AUS-Abschluss

Version 0.1.15 enthält die Änderungen aus 0.1.14 (getrennte Gesamtzeiten für AUF/ZU, frei wählbare simulierte UPU-Ausgänge und präzisierte ShakeFree-Beschreibung) und behebt zusätzlich einen Referenzverlust beim Statusabgleich.

## Wichtigste Änderungen

- Eine gültige Endlagenreferenz wird sichtbar und zusätzlich als persistentes Modulattribut gespeichert.
- Ein normales **Übernehmen**, ein Objektbaum-Rebuild oder eine LCN-Statusabfrage löscht die Referenz nicht mehr.
- 0 % AUF und 100 % ZU werden jeweils nach der passenden Richtungs-Gesamtzeit **plus Referenzreserve** gesetzt.
- Unreferenzierte Endlagenfahrten verwenden die jeweilige Richtungs-Gesamtzeit plus Reserve; `MaxFahrt` ist nur die obere Sicherheitsgrenze.
- Bei 100 % ZU wird die Referenz vor dem zusätzlichen Kalibrierfenster gespeichert.
- Nach ShakeFree wird die AUF-Gegenfahrt gestoppt, anschließend die Lamelle mit ZU zurückgestellt und auch dieser ZU-Befehl nach der berechneten Wendezeit gestoppt.
- Ruhe wird nur erreicht, wenn beide realen Relais AUS bestätigen.
- Der Healthcheck dient als zweite Deadline-/STOP-Sicherung, falls der 1-s-Worker nicht ausführt. Empfohlener Wert: 10 s.

## Update über GitHub Desktop

1. ZIP-Paket entpacken.
2. In GitHub Desktop das Repository `SymconLCNJalousie` auswählen.
3. **Repository → Show in Explorer** öffnen.
4. Den gesamten Inhalt des Ordners `SymconLCNJalousie_V0_1_15` in das bestehende Repository kopieren.
5. Vorhandene Dateien ersetzen; `.git` nicht löschen.
6. In `library.json` prüfen:

   ```json
   "version": "0.1.15",
   "build": 16
   ```

7. Commit-Nachricht: `Persist end references and verify relay off`
8. **Commit to main**, danach **Push origin**.
9. In Symcon **Kern Instanzen → Module** öffnen.
10. **Auf Aktualisierung prüfen** und Version 0.1.15 installieren.
11. Die Jalousieinstanz öffnen und einmal **Übernehmen** wählen.

## Einstellungen kontrollieren

- **Gesamtlaufzeit 100 % ZU → 0 % AUF**, einschließlich vollständiger Lamellenwendung
- **Gesamtlaufzeit 0 % AUF → 100 % ZU**
- **Referenzreserve**
- **Maximale überwachte Fahrt** mindestens längere Richtungs-Gesamtzeit plus Reserve
- **Zeitverzögerung / Kalibrierfenster nach 100 % ZU**, mindestens 30.000 ms
- **ShakeFree nach Endlage ZU** zunächst AUS lassen, bis der Endlagen-/Kalibrierablauf ohne ShakeFree geprüft ist
- **Healthcheck / unabhängige STOP-Überwachung** auf 10 s setzen

## Referenztest

Da Version 0.1.14 das Bewegungsmodell auf getrennte Richtungszeiten umgestellt hat, wird beim direkten Update von 0.1.13 einmalig eine neue Referenz erforderlich.

1. ShakeFree AUS.
2. Eine vollständige Fahrt auf 0 % AUF ausführen.
3. Nach Gesamtzeit plus Referenzreserve muss `Position gültig = Ein`, `Letzte Referenz-Endlage = 0 % AUF` und ein Zeitstempel sichtbar sein.
4. Beide Relais müssen nach dem STOP AUS melden.
5. Anschließend vollständig auf 100 % ZU fahren.
6. Nach Gesamtzeit plus Referenzreserve muss `Letzte Referenz-Endlage = 100 % ZU` erscheinen.
7. Während des Kalibrierfensters bleibt ZU aktiv; nach Ablauf muss der ZU-KURZ-STOP gesendet und beide Relais AUS bestätigt werden.
8. `Letzte Bestätigung: beide Relais AUS` muss aktualisiert sein.

## ShakeFree erst danach testen

Bei aktivem ShakeFree ist die Reihenfolge:

1. 100 % ZU plus Referenzreserve
2. Kalibrier-/Zeitverzögerungsfenster
3. ZU-STOP und reale Relais-AUS-Bestätigung
4. Umschaltpause
5. AUF-Gegenfahrt für `ShakeFree_ms`
6. AUF-STOP und reale Relais-AUS-Bestätigung
7. Lamellen-ZU-Nachlauf bis 100 %
8. ZU-STOP und abschließende reale Relais-AUS-Bestätigung

Das Modul sendet bei ausbleibender AUS-Bestätigung keinen zweiten automatischen Toggle. Stattdessen wird es verriegelt, damit die lokale LCN-Bedienung frei bleibt.
