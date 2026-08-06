# Update 0.1.21 – Stabilität bei Relaisbestätigung und parallelen Befehlen

Version 0.1.21 behebt sporadische falsche Startfehler bei vielen eingebundenen Jalousien und verstärkt die Abschaltsicherung bei fehlender Relais-AUS-Rückmeldung.

## Ursache des sporadischen Startfehlers

Die Startbestätigungsfrist wurde bisher gesetzt, bevor die vollständige Konfiguration geprüft, eine Sendesperre abgewartet und `LCN_SendCommand` tatsächlich ausgeführt wurde. Bei parallelen Bedienungen konnte dadurch ein wesentlicher Teil der Frist bereits abgelaufen sein, bevor das Telegramm den LCN-Bus erreicht hatte.

Ab 0.1.21 wird die Frist ausschließlich nach erfolgreicher Telegrammübernahme gestartet. Alle Jalousieinstanzen senden außerdem über eine gemeinsame globale Sperre mit einem standardmäßigen Mindestabstand von 100 ms.

## Verhalten bei fehlender Startbestätigung

1. Das Modul wartet auf die reale Rückmeldung der zwei in dieser Instanz ausgewählten Relaisvariablen.
2. Bleibt sie aus, wird genau einmal der Status des ausgewählten Aktormoduls abgefragt.
3. Bleiben beide Relais AUS, wird der Auftrag sicher verworfen. Die Instanz bleibt betriebsbereit und überwacht noch das Spätstart-Schutzfenster.
4. Startet ein Relais verspätet, wird die reale Richtung einmal gestoppt und die Positionsreferenz wegen des unbekannten Startzeitpunkts verworfen.
5. Startet das andere als das erwartete Relais, wird diese reale Richtung kontrolliert gestoppt und danach ein Zuordnungsfehler verriegelt.

## Verhalten bei fehlender AUS-Bestätigung

Nach dem ersten STOP wird nicht blind nochmals getoggelt. Zunächst wird eine frische Statusantwort beider ausgewählter Relais verlangt. Nur wenn beide Antworten eingetroffen sind und eines der ausgewählten Relais weiterhin EIN meldet, sendet das Modul genau eine verifizierte STOP-Wiederholung. Bleibt das Relais danach weiterhin aktiv, wird die Instanz verriegelt und muss lokal sicher ausgeschaltet werden.

## Kontrolle nach dem Update

- Alle Instanzen auf Status 102 beziehungsweise auf mögliche Zuordnungskonflikte prüfen.
- Den Telegrammabstand zunächst auf 100 ms belassen.
- Schnelle Gegenfahrt direkt nach 0 % AUF testen.
- Vollständige Fahrt auf 100 % ZU testen und reale Relais-AUS-Meldung kontrollieren.
- Mehrere Jalousien kurz nacheinander bedienen.
- Für jede Instanz AUF und ZU im Busmonitor einzeln verifizieren.

Die Software kann nur die konfigurierten Variablen und TS-Datensätze auf Eindeutigkeit prüfen. Ob ein TS-Datensatz in LCN-PRO tatsächlich ausschließlich den vorgesehenen Motor schaltet, muss weiterhin an der realen Anlage abgenommen werden.
