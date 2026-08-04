# Erste Schritte – für Einsteiger

Diese Anleitung führt Sie ohne Git-Vorkenntnisse vom vorbereiteten Ordner bis zur Installation in Symcon.

## A. Repository auf Ihrem Computer anlegen

1. Laden Sie das vorbereitete ZIP-Paket herunter.
2. Entpacken Sie es an einen leicht auffindbaren Ort, zum Beispiel auf den Desktop.
3. Öffnen Sie **GitHub Desktop**.
4. Melden Sie sich oben rechts mit Ihrem GitHub-Konto an, falls noch nicht geschehen.
5. Wählen Sie **File → New repository**.
6. Tragen Sie bei **Name** genau `SymconLCNJalousie` ein.
7. Wählen Sie bei **Local path** den Ordner `Dokumente\GitHub` oder einen anderen gewünschten Hauptordner.
8. Lassen Sie die Optionen für README, Git Ignore und Lizenz leer beziehungsweise auf **None**, weil diese Dateien schon vorbereitet sind.
9. Klicken Sie auf **Create repository**.
10. Öffnen Sie in GitHub Desktop **Repository → Show in Explorer**.
11. Öffnen Sie zusätzlich den entpackten vorbereiteten Ordner.
12. Kopieren Sie **den gesamten Inhalt** des vorbereiteten Ordners in den von GitHub Desktop erzeugten Repository-Ordner. Kopieren Sie nicht noch einmal den äußeren Ordner, sondern dessen Inhalt: `library.json`, `README.md`, `LCNJalousie`, `LCNJalousieKonfigurator`, `docs` usw.
13. Wechseln Sie zurück zu GitHub Desktop. Links müssen nun viele neue Dateien unter **Changes** erscheinen.

## B. Ersten Stand speichern und öffentlich veröffentlichen

1. Unten links bei **Summary** tragen Sie ein: `Fix configurator class name 0.1.2`.
2. Klicken Sie auf **Commit to main**.
3. Klicken Sie oben auf **Publish repository**.
4. Kontrollieren Sie den Namen `SymconLCNJalousie`.
5. Tragen Sie als Beschreibung ein: `LCN Jalousiesteuerung für Symcon 9.0`.
6. Deaktivieren Sie die Option **Keep this code private**. Das Repository wird dadurch öffentlich.
7. Klicken Sie auf **Publish repository**.
8. Wählen Sie anschließend **Repository → View on GitHub**.
9. Prüfen Sie im Browser, ob neben dem Repository-Namen **Public** steht.

## Fehlerbehebung: „module.json fehlt“

Wenn Symcon meldet, der Ordner `modules` sei ein ungültiges Modul, liegen die Modulordner eine Ebene zu tief. Im Hauptverzeichnis des Repositorys müssen `LCNJalousie` und `LCNJalousieKonfigurator` direkt neben `library.json` liegen. Ein Sammelordner `modules` ist in einer Symcon-Modulbibliothek nicht zulässig.

## C. Modul in Symcon installieren

1. Kopieren Sie im Browser die Repository-Adresse. Sie sieht ungefähr so aus: `https://github.com/IHR-NAME/SymconLCNJalousie`.
2. Öffnen Sie die Symcon-Konsole.
3. Wechseln Sie in die logische Baumansicht zu **Kern Instanzen**.
4. Öffnen Sie die Instanz **Modules** beziehungsweise **Module Control**.
5. Klicken Sie auf **+**.
6. Fügen Sie die Repository-Adresse ein. Falls Symcon die Endung verlangt, verwenden Sie `https://github.com/IHR-NAME/SymconLCNJalousie.git`.
7. Bestätigen Sie. Danach stehen die beiden Module im Dialog **Instanz hinzufügen** bereit.

## D. Erste Jalousieinstanz anlegen

1. Wählen Sie **Instanz hinzufügen**.
2. Suchen Sie nach `LCN Jalousie Konfigurator`.
3. Legen Sie den Konfigurator unter einer Kategorie `Jalousiesteuerung` an.
4. Öffnen Sie den Konfigurator.
5. Wählen Sie die Zeile **Neue LCN-Jalousie** und erstellen Sie die Instanz.
6. Benennen Sie die Instanz, zum Beispiel `Jalousie Wohnzimmer`.
7. Öffnen Sie die neue Instanz.

## E. Pflichtfelder ausfüllen

Arbeiten Sie die Bereiche von oben nach unten ab:

1. **LCN-Sendemodul** – das Modul, auf dem die virtuellen Tasten liegen, zum Beispiel M22.
2. **LCN-Aktormodul** – das Modul mit den Motorrelais, zum Beispiel M93.
3. **Relaisstatus AUF** – echte Boolean-Statusvariable des AUF-Relais.
4. **Relaisstatus AB** – echte Boolean-Statusvariable des AB-Relais.
5. **GT8 LANG AUF** – simulierter Ausgang 3.
6. **GT8 LANG AB** – simulierter Ausgang 4.
7. Laufzeiten kontrollieren und bei Bedarf auf Ihre gemessenen Werte ändern.
8. **TS-Datenfelder noch nicht bestätigen.** Erst LCN-PRO und PCHK-Busmonitor prüfen.
9. Klicken Sie auf **Übernehmen**.
10. Das Modul zeigt den noch fehlenden Punkt als Instanzstatus an.

## F. TS-Datenfelder sicher freigeben

1. Prüfen Sie in LCN-PRO, welche Tabelle und welche Taste für AUF und AB verwendet werden.
2. Senden Sie die Befehle zunächst mit Busmonitor und ohne unbeaufsichtigten Motorbetrieb.
3. Bestätigen Sie, dass AUF nur die AUF-Taste und AB nur die AB-Taste auslöst.
4. Erst danach setzen Sie im Modul den Haken **TS-Belegung bestätigt**.
5. Klicken Sie auf **Übernehmen**.
6. Der Instanzstatus muss anschließend **Konfiguration vollständig – Laufzeit freigegeben** anzeigen.

## G. Erster sicherer Funktionstest

1. Sorgen Sie für freie Sicht auf die Jalousie und halten Sie eine lokale Stopmöglichkeit bereit.
2. Prüfen Sie zuerst die lokale LCN-KURZ-Bedienung ohne Symcon.
3. Prüfen Sie, dass AUF und AB niemals gleichzeitig aktiv werden.
4. Starten Sie in Symcon eine kurze Fahrt und beobachten Sie die echten Relaisvariablen.
5. Testen Sie STOP.
6. Testen Sie erst danach Endlagen, Lamellenfahrt und ShakeFree.
7. Dokumentieren Sie Abweichungen, bevor eine Automatik freigegeben wird.

## H. Spätere Änderungen veröffentlichen

1. Ändern oder ersetzen Sie Dateien im lokalen Repository-Ordner.
2. GitHub Desktop zeigt sie unter **Changes**.
3. Schreiben Sie eine kurze Summary, zum Beispiel `Fix configuration validation`.
4. Klicken Sie auf **Commit to main**.
5. Klicken Sie auf **Push origin**.
6. In Symcon öffnen Sie Module Control und wählen **Auf Aktualisierung prüfen**.
