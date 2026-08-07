# Update 0.1.28 – Kompaktspeicher mit sicherem Rollback

## Ziel

V0.1.28 reduziert die durch jede Jalousieinstanz erzeugte Variablenzahl, ohne Bedienung, Sicherheitslogik oder Diagnosefunktionen zu entfernen. Die funktionale Fahrlogik entspricht V0.1.27.

## Variablenreduktion

Pro bestehender Instanz entfallen nach erfolgreicher Migration:

- 35 Konfigurations-Spiegelvariablen aus `01 Konfiguration`
- 43 interne Zustandsvariablen aus `05 Intern`
- Summe: **78 Variablen pro Jalousie**

Die sichtbaren Instanz-, Bedien-, Istwert-, Referenz-, Fehler- und Abnahmevariablen bleiben erhalten. Zwölf Jalousien sparen damit **936 Symcon-Variablen**.

## Automatische Einmalmigration von V0.1.27

Die Migration erfolgt in einer abgesicherten Reihenfolge:

0. Symcons Modul-Migrationsphase ergänzt die neuen V0.1.28-Attribute, ohne vorhandene Properties oder Referenzattribute zu überschreiben.
1. Wartungsmodus aktivieren; neue Symcon-Bedienaufrufe senden kein LCN-Telegramm.
2. Exklusive Instanzsperre abwarten, damit kein bereits laufender Controller/Worker Legacy-Werte parallel verändert.
3. Aktuelle Properties, Legacy-Konfiguration, internen Zustand, Referenz, aktuelle Position und Fehlerspeicher erfassen.
4. Persistent gespeicherten Rollback-Snapshot erzeugen und per SHA-256 verifizieren.
5. Internen Zustand in den neuen Kompaktbuffer übernehmen und per Roundtrip prüfen.
6. Objektbaum, Skripte, Ereignisse, Hardwarebindung, Konfiguration und Visualisierung vollständig neu prüfen.
7. Nur bei beiden realen Motorrelais AUS die 78 **vom Modul bekannten** Legacy-Variablen entfernen. Benutzerdefinierte Variablen in denselben Kategorien werden nicht gelöscht. Bei aktiver Fahrt wird dieser Schritt automatisch bis zum nächsten sicheren Healthcheck vertagt.
8. Migration als abgeschlossen markieren.

Eine gültige Referenz wird durch die Migration nicht verworfen.

## Fehler während der Migration

Vor der verifizierten Übernahme wird nichts gelöscht. Tritt während der nicht atomaren Objektlöschung ein Fehler auf, rekonstruiert das Modul automatisch die vollständige V0.1.27-Legacy-Struktur. Dabei wird der **aktuelle** Kompaktzustand zurückgeschrieben, sodass eine zwischenzeitlich fortgeschrittene Fahrt nicht auf den alten Snapshotzustand zurückgesetzt wird.

Kann eine bereits laufende Jalousiesteuerung innerhalb von 30 Sekunden nicht exklusiv angehalten werden, bricht die Migration vor jeder Legacy-Löschung sicher ab.

## Rollback auf V0.1.27

Der Rollback ist vorbereitet und bewusst zweistufig:

1. Beide ausgewählten realen Relais müssen AUS sein.
2. In V0.1.28 **Rollback auf V0.1.27 vorbereiten** ausführen.
3. Erfolgsmeldung abwarten. Das Modul rekonstruiert 35 + 43 Legacy-Variablen aus der **aktuellen** Konfiguration und dem **aktuellen** Runtime-Zustand.
4. Erst danach die Modulbibliothek auf V0.1.27 zurücksetzen.

Die vorhandenen Jalousieinstanzen, LCN-IDs, Laufzeiten und Referenzen müssen dabei nicht neu eingepflegt werden. Ein vollständiges Symcon-Backup vor dem Update bleibt die zusätzliche Rückfallebene.

## Prüfungen

Die Freigabeprüfung umfasst PHP-/JSON-/JavaScript-Syntax, Repository-Validierung, Neustart- und Update-Lifecycle, Relaiszustände, LCN-Adressprüfung, Startbestätigung, Visualisierungstransport, Bedienfolgen, Mehrinstanzbetrieb, 100.000 randomisierte Modelloperationen sowie einen eigenen Migrations-/Rollbacktest inklusive simuliertem Teil-Löschfehler.

Der abschließende reale Test an Symcon/PCHK/LCN/Relais/Motor bleibt erforderlich.
