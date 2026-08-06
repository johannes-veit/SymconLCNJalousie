# Security Policy

Dieses Projekt steuert motorische Antriebe. Sicherheitsrelevante Fehler bitte nicht nur als öffentliche Diskussion veröffentlichen, sondern dem Repository-Eigentümer direkt melden.

Vor jeder produktiven Nutzung sind die LCN-PRO-Verriegelung, Relaiszuordnung, TS-Befehle, Endlagen und Stopfunktion am realen System zu prüfen. Das Modul ersetzt keine elektrische oder mechanische Sicherheitseinrichtung.

## Lokale LCN-Bedienung hat Vorrang

Die Motorverriegelung und die lokale Bedienung müssen unabhängig von Symcon in LCN-PRO funktionieren. Die Modulinstanz kann über **Symcon-Steuerung aktiv** bewusst deaktiviert werden. Im inaktiven oder verriegelten Fehlerzustand müssen alle Modulereignisse und Timer aus sein und das Modul darf keine LCN-Befehle senden.

## Fehlerverriegelung

Ab Version 0.1.13 führt ein Laufzeit- oder Aufbaufehler zu einer persistenten Fehlerverriegelung. Die Symcon-Steuerung bleibt bis zur bewussten Quittierung inaktiv. Eine Quittierung ist nur zulässig, wenn beide realen Motorrelais AUS melden. Nach der Quittierung ist eine erneute Endlagenreferenz erforderlich.

## Kalibrierfenster

Nach einer vollständigen ZU-Fahrt auf 100 % muss Symcon den aktiven ZU-Befehl genau einmal stoppen und das reale Ausschalten beider ausgewählter Relais bestätigen. Das konfigurierte Kalibrierfenster darf erst danach und ausschließlich bei Relais AUS laufen. Ein neuer Fahrbefehl darf das Fenster beenden; ShakeFree darf erst nach dessen ungestörtem Ablauf starten.


## Toggle-STOP und Hardwarebindung

LCN-KURZ-Befehle wirken als Toggle. Ein bereits gesendeter STOP darf deshalb bis zur realen AUS-Bestätigung nicht wiederholt werden. Neue Aufträge werden in diesem Zeitraum nur vorgemerkt. Bleibt die Rückmeldung aus, darf das Modul höchstens eine Statusabfrage an das ausgewählte Aktormodul senden und muss anschließend ohne zweiten Toggle verriegeln.

Jede Instanz darf nur ihre dauerhaft konfigurierten Relais-, GT8- und TS-Zuordnungen verwenden. Doppelte Motorrelaisvariablen, doppelte GT8-Ereignisvariablen oder derselbe TS-KURZ-Befehl auf demselben Sendemodul müssen die betroffenen Instanzen sperren. Unabhängig davon bleibt eine reale Busmonitor-Prüfung der LCN-PRO-Zuordnung erforderlich.
