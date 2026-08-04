# Update auf 0.1.7 – korrigiertes Bewegungsmodell

Version 0.1.7 trennt die Lamellenbewegung vom tatsächlichen Beginn der Behangbewegung.

## Vorgabewerte

- Volle Wendezeit: 6500 ms
- Sanftanlauf aus Zwischenposition bei gleicher Richtung: 6000 ms
- Reine Behanglaufzeit 0–100 %: 175500 ms
- Gesamtlaufzeit untere Endlage → obere Endlage: 182000 ms

## Modell

| Startzustand | Richtung | Beginn der Behangbewegung |
|---|---|---:|
| obere Endlage 0 % | AB | 0 ms |
| untere Endlage 100 % | AUF | 6500 ms |
| Zwischenposition, Lamellen bereits passend | gleiche Richtung | 6000 ms |
| Zwischenposition, Lamellen entgegengesetzt | Gegenrichtung | 6500 ms |

Bei einer Lamellenstellung zwischen den Endwerten verwendet das Modul konservativ den längeren Wert aus 6000 ms Sanftanlauf und der noch erforderlichen anteiligen Lamellen-Wendezeit.

## Aktualisierung

1. Inhalt dieses Repositorys in den bestehenden lokalen GitHub-Ordner kopieren.
2. In GitHub Desktop committen und `Push origin` ausführen.
3. In Symcon unter `Kern Instanzen → Modules` nach Aktualisierungen suchen.
4. Version 0.1.7 installieren.
5. Jalousieinstanz öffnen und `Übernehmen` wählen. Das Modul setzt dabei `Position gültig` absichtlich auf Aus.
6. Im Abschnitt `4. Laufzeiten` den neuen Wert `Sanftanlauf aus Zwischenposition` auf 6000 ms kontrollieren.
7. Vor weiteren Zielpositionsfahrten neu referenzieren und die vier Startfälle beaufsichtigt prüfen.
