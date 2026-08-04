# Update auf Version 0.1.9 – korrigierte Visualisierung

## Inhalt

Version 0.1.9 behebt den Fehler `Cannot set property className of #<SVGElement> which has only a getter` und ordnet die Kachel neu:

- keine mittlere Statuskachel mehr,
- Behang links: runde Tasten **AUF**, **STOP**, **ZU** und vertikaler Slider,
- Lamellen rechts: dynamische Lamellengrafik, Slider sowie **AUF 0 %**, **MITTE 50 %**, **ZU 100 %**,
- 0 % liegt bei beiden Slidern oben, 100 % unten,
- Prozentwerte stehen unterhalb der Beschriftung **ZU**,
- kleiner Laufstatus bleibt unten sichtbar,
- ShakeFree bleibt als kleiner Schalter sichtbar,
- der Instanzname wird nicht in der HTML-Kachel wiederholt,
- das Farbschema wird aus der Symcon-Visualisierung abgeleitet und nicht mehr aus dem Smartphone-Systemmodus.

## GitHub Desktop

1. ZIP entpacken.
2. In GitHub Desktop **Repository → Show in Explorer** öffnen.
3. Den gesamten Inhalt des entpackten Ordners in das vorhandene Repository kopieren und Dateien ersetzen.
4. In `library.json` prüfen: `"version": "0.1.9"`, `"build": 10`.
5. Summary: `Fix tile layout and Symcon theme handling`.
6. **Commit to main**, danach **Push origin**.

## Symcon

1. **Kern Instanzen → Module** öffnen.
2. **Auf Aktualisierung prüfen** und Version 0.1.9 installieren.
3. Jalousieinstanz schließen und neu öffnen.
4. **Übernehmen** anklicken.
5. Visualisierung mit `Strg + F5` neu laden.

Die fehlende Positionsreferenz ist unabhängig vom behobenen Renderfehler. Nach dem Update bleibt eine erforderliche Referenzfahrt weiterhin erforderlich.
