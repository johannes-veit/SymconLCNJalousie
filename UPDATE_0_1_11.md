# Update auf 0.1.11 – fluchtendes Kachellayout und Befehlsfeedback

## Änderungen

- Beide Bedienkacheln besitzen dieselbe Anordnung:
  - links drei gleich große runde Tasten,
  - mittig die grafische Statusanzeige,
  - rechts der vertikale Slider.
- Behang: `AUF`, `STOP`, `ZU`.
- Lamellen: `AUF`, `MITTE`, `ZU`.
- Grafische Behangposition ergänzt.
- Behang- und Lamellengrafik, Tastenspalte und Slider haben identische Maße. Auf dem Smartphone fluchten die untereinander angeordneten Kacheln dadurch exakt.
- Alle Tasten verwenden die Symcon-Akzentfarbe Grün/Türkis.
- Der Kranz um eine Taste kennzeichnet nur einen laufenden Auftrag. Nach Abschluss wird er automatisch entfernt.
- Der kompakte Laufstatus und der ShakeFree-Schalter bleiben erhalten.
- Der Theme-Hotfix aus 0.1.10 ist enthalten. Die Kachel verwendet ausschließlich die Symcon-CSS-Variablen `--accent-color`, `--content-color` und `--card-color`.

## Aktualisierung über GitHub Desktop

1. ZIP-Paket entpacken.
2. In GitHub Desktop das Repository `SymconLCNJalousie` auswählen.
3. **Repository → Show in Explorer** öffnen.
4. Den gesamten Inhalt dieses Ordners in das lokale Repository kopieren und vorhandene Dateien ersetzen.
5. In `library.json` kontrollieren:

   ```json
   "version": "0.1.11",
   "build": 12
   ```

6. In GitHub Desktop als Summary eintragen:

   ```text
   Align blind and slat tile layout
   ```

7. **Commit to main** und danach **Push origin** anklicken.
8. In Symcon **Kern Instanzen → Module** öffnen.
9. **Auf Aktualisierung prüfen** und Version 0.1.11 installieren.
10. Jalousieinstanz öffnen und einmal **Übernehmen** anklicken.
11. Visualisierung auf Desktop und Smartphone vollständig neu laden; im Desktop-Browser bei Bedarf `Strg + F5`.

## Referenzierung

Dieses Update ändert nur Visualisierung und Visualisierungsstatusdaten. Eine bereits gültige Positionsreferenz wird nicht verworfen und eine erneute Referenzfahrt ist allein wegen dieses Updates nicht erforderlich.

## Sichtprüfung

- Desktop und Smartphone zeigen das in der Visualisierung gewählte helle Design.
- Behang und Lamellen fluchten bei untereinander angeordneten Kacheln.
- 0 % befindet sich bei beiden Slidern oben, 100 % unten.
- Beim Drücken erscheint ein Kranz um die jeweilige Taste.
- Nach abgeschlossenem Auftrag verschwindet der Kranz wieder.
- Laufstatus und ShakeFree bleiben bedienbar und sichtbar.
