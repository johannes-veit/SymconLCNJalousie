# Update auf 0.1.10 – Theme-Hotfix

## Behobener Fehler

Auf dem Smartphone erschien die Kachel passend zur hellen Symcon-Visualisierung, auf dem Desktop jedoch dunkel. Ursache war eine Heuristik, die Hintergrundfarben übergeordneter Browser-/Frame-Elemente auswertete und dabei auf dem Desktop einen dunklen Bereich der Oberfläche erfasste.

## Neue Theme-Logik

Die Kachel nutzt ausschließlich die von Symcon bereitgestellten CSS-Variablen:

- `--accent-color`
- `--content-color`
- `--card-color`

Damit folgt sie auf Smartphone und Desktop derselben Visualisierungseinstellung.

## Installation

1. Paket entpacken.
2. Inhalt in das lokale GitHub-Repository kopieren und vorhandene Dateien ersetzen.
3. In GitHub Desktop committen und `Push origin` ausführen.
4. In Symcon unter **Kern Instanzen → Module** nach Aktualisierungen suchen und Version 0.1.10 installieren.
5. Jalousieinstanz öffnen und **Übernehmen** anklicken.
6. Visualisierung auf Desktop und Smartphone vollständig neu laden; im Browser bei Bedarf `Strg + F5`.

Eine erneute Referenzfahrt ist für dieses reine Visualisierungsupdate nicht erforderlich.
