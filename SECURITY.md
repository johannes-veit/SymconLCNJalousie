# Security Policy

Dieses Projekt steuert motorische Antriebe. Sicherheitsrelevante Fehler bitte nicht nur als öffentliche Diskussion veröffentlichen, sondern dem Repository-Eigentümer direkt melden.

Vor jeder produktiven Nutzung sind die LCN-PRO-Verriegelung, Relaiszuordnung, TS-Befehle, Endlagen und Stopfunktion am realen System zu prüfen. Das Modul ersetzt keine elektrische oder mechanische Sicherheitseinrichtung.

## Lokale LCN-Bedienung hat Vorrang

Die Motorverriegelung und die lokale Bedienung müssen unabhängig von Symcon in LCN-PRO funktionieren. Die Modulinstanz kann über **Symcon-Steuerung aktiv** bewusst deaktiviert werden. Im inaktiven oder verriegelten Fehlerzustand müssen alle Modulereignisse und Timer aus sein und das Modul darf keine LCN-Befehle senden.

## Fehlerverriegelung

Ab Version 0.1.13 führt ein Laufzeit- oder Aufbaufehler zu einer persistenten Fehlerverriegelung. Die Symcon-Steuerung bleibt bis zur bewussten Quittierung inaktiv. Eine Quittierung ist nur zulässig, wenn beide realen Motorrelais AUS melden. Nach der Quittierung ist eine erneute Endlagenreferenz erforderlich.

## Kalibrierfenster

Nach einer vollständigen ZU-Fahrt auf 100 % darf Symcon während des konfigurierten Kalibrierfensters weder automatisch stoppen noch die Gegenrichtung einschalten. Ein manueller STOP bleibt als Notbedienung möglich. ShakeFree darf erst nach Ablauf dieses Fensters starten.
