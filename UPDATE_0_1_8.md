# Update auf Version 0.1.8 – eigene Jalousiekachel

## Inhalt

Version 0.1.8 ergänzt eine eigene HTML-SDK-Kachel mit:

- Behang-Slider und Tasten AUF 0 %, MITTE 50 %, ZU 100 %
- Lamellen-Slider und Tasten AUF 0 %, MITTE 50 %, ZU 100 %
- STOP-Taster
- ShakeFree-Schalter
- Laufstatus und Referenzanzeige

Die Fahrlogik und die LCN-Sicherheitsfunktionen bleiben unverändert. STOP wird weiterhin zustandsabhängig über den KURZ-Befehl der real aktiven Richtung ausgeführt.

## Aktualisierung

1. Den Inhalt dieses Verzeichnisses in das lokale GitHub-Repository kopieren und vorhandene Dateien ersetzen.
2. In GitHub Desktop committen, beispielsweise mit `Add interactive Symcon shutter tile`.
3. `Push origin` ausführen.
4. In Symcon unter **Kern Instanzen → Module** auf **Auf Aktualisierung prüfen** klicken und Version 0.1.8 installieren.
5. Die Jalousieinstanz öffnen und **Übernehmen** anklicken.
6. Den Visualisierungsreiter neu laden; bei Bedarf `Strg + F5` verwenden.

## Erwartetes Ergebnis

Die bisher leere Instanzkachel wird durch die eigene Bedienoberfläche ersetzt. Bestehende Variablen, Zuordnungen, Referenzwerte und Laufzeiten bleiben erhalten.

## Sicherheitstest

- STOP bei einer kurzen beaufsichtigten AUF-Fahrt prüfen.
- STOP bei einer kurzen beaufsichtigten ZU-Fahrt prüfen.
- Sicherstellen, dass nie beide Relais gleichzeitig aktiv sind.
- Zwischenpositionen erst nach gültiger Referenz testen.
