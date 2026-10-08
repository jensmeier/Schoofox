# Schulmanager SymDo für IP-Symcon

Eigenständiges IP-Symcon-Modul für **Schulmanager Online** mit Anbindung an den vorhandenen **SymDo Stundenplan**, **SymDo Hausaufgaben** und **SymDo/OpenCalendar**.

## Version 1.2 / Build 3

Neu:

- Klassenarbeiten/Prüfungen können mit **genauer Schulstunde und Uhrzeit** in einen beschreibbaren SymDo/OpenCalendar-Kalender synchronisiert werden.
- Zuordnung zum richtigen SymDo-Familienmitglied; damit erscheint der Termin in der richtigen Personenzeile.
- Doppelte Kalendereinträge werden vermieden; Änderungen und entfernte zukünftige Prüfungen werden nachgezogen.
- Noten/Zensuren werden über den aktuellen Schulmanager-Endpunkt `get-grading-information-for-student` gelesen.
- Solange noch keine Noten vorhanden sind, zeigt die Schulseite: **„Noch keine Noten im Schuljahr …“**.
- Sobald Noten vorhanden sind: Anzeige nach Fach und Bewertungsblock (z. B. schriftlich/mündlich), mit rechnerischem Blockdurchschnitt.
- Ein Fach-Gesamtdurchschnitt wird nur angezeigt, wenn Schulmanager eine Gewichtung der Bewertungsblöcke liefert.
- Elternbriefe werden mit Detailtext und Anhangsnamen zwischengespeichert und auf der **Schulmanager Schulseite** aufklappbar dargestellt.
- Roh-JSON bleibt intern und wird in der Visualisierung ausgeblendet.

## Bereits vorhanden

- Schulmanager-Login nur lesend
- mehrere Kinder unter einem Elternkonto
- Stundenplan → SymDo
- Vertretungen → SymDo
- Unterrichtsentfall → SymDo
- Veranstaltungen → SymDo
- Klassenarbeiten im importierten Stundenplan markieren
- Hausaufgaben → normale SymDo-Hausaufgabenliste
- Prüfungen bis 8 Wochen voraus
- Wochenvorlage aus regulären Schulmanager-Stunden
- automatische Aktualisierung, Standard **30 Minuten**
- Schutz gegen wiederholte Fehlanmeldungen

## Installation / Update

1. Den Inhalt des ZIPs in das GitHub-Repository hochladen. `library.json` muss direkt im Hauptverzeichnis liegen.
2. In IP-Symcon bei der Modulverwaltung **Auf Aktualisierung prüfen** und aktualisieren.
3. Nach dem Update auf 1.2 IP-Symcon einmal neu starten.
4. Instanz **Schulmanager SymDo** öffnen und **Übernehmen**.

## SymDo koppeln

1. Das richtige **SymDo-Gateway** auswählen.
2. **Mit SymDo verbinden** drücken.
3. **SymDo-Verbindung testen**.
4. Kinderzuordnung prüfen.

## Hausaufgaben

Die Schulmanager-Hausaufgaben werden in den normalen SymDo-Hausaufgabenbestand übernommen. Abhaken in SymDo bleibt erhalten; Text- oder Datumsänderungen werden nachgezogen.

## Prüfungen im Kalender

1. **Prüfungen lesen** aktivieren.
2. **Prüfungen mit exakter Schulstunde in den Kalender synchronisieren** aktivieren.
3. Einen **beschreibbaren Kalender** auswählen.
4. **Jetzt abrufen und übernehmen** drücken.

Beispiel:

- Mathematik, 22.10., 3. Stunde → Kalendertermin 09:15–10:00
- Deutsch, 27.10., 3. Stunde → 09:15–10:00
- Englisch, 10.11., 1. Stunde → 07:30–08:15

Die Uhrzeiten werden direkt aus `startClassHour`/`endClassHour` übernommen; falls nötig wird auf das Stundenschema der Klasse zurückgegriffen.

## Noten

Das Modul liest das laufende Schuljahr. Solange keine Noten vorhanden sind, wird kein Fehler angezeigt.

Wenn Schulmanager später Noten liefert:

- Anzeige je Fach
- getrennte Bewertungsblöcke, z. B. schriftlich / mündlich
- Durchschnitt je Block
- Fach-Gesamtdurchschnitt nur bei vorhandener Schulmanager-Gewichtung

Die angezeigten Durchschnitte sind rechnerische Werte und ersetzen keine offizielle Zeugnisberechnung.

## Elternbriefe

Die neuesten Elternbriefe erscheinen auf der **Schulmanager Schulseite**. Ein Brief kann aufgeklappt werden; Text und vorhandene Anhangsnamen werden angezeigt. Die Schulmanager-Daten werden weiterhin nur gelesen.

## Zwei Kinder

Sind beide Kinder unter demselben Elternkonto, reicht eine Modulinstanz. Jedes Schulmanager-Kind wird einem SymDo-Kind zugeordnet. Hausaufgaben, Kalendertermine, Noten und Elternbriefe werden getrennt dargestellt.

## Sicherheit

- Schulmanager wird ausschließlich gelesen.
- Schreibzugriffe gehen nur an deine eigenen SymDo-/OpenCalendar-Daten.
- Das Passwort liegt als IP-Symcon-Instanzeigenschaft und wird im Formular als Passwortfeld behandelt.
- Nach wiederholten Loginfehlern pausiert die automatische Anmeldung sechs Stunden.
