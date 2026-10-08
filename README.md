# Schulmanager SymDo für IP-Symcon

Eigenständiges IP-Symcon-Modul für **Schulmanager Online** mit Anbindung an **SymDo Stundenplan**, **SymDo Hausaufgaben** und **SymDo/OpenCalendar**.

## Version 1.3.1 / Build 5


Neu in 1.3.1:

- Layout-Fix für die IP-Symcon Kachel: Inhalt berücksichtigt jetzt die von Symcon vorgegebenen oberen/seitlichen/unteren Kachelränder
- der interne Titel wird automatisch ausgeblendet, wenn Symcon bereits den Instanznamen als Kacheltitel anzeigt – dadurch kein doppeltes „Klassenseiten“ mehr
- der Aktualisieren-Knopf liegt nicht mehr unter dem Symcon-Titel; auf kleinen Kacheln wird er platzsparend nur als ↻ angezeigt
- auf schmalen Kacheln wird die Prüfungs-Tabelle kompakter dargestellt
- Hauptbereich und Elternbriefe behalten getrennte, stabile Scrollbereiche

Neu in 1.3:

- eigene HTML-Kachel **Klassenseiten** direkt aus dem Schulmanager-Modul
- für **beliebig viele zugeordnete Kinder**: Umschaltknöpfe oben in der Kachel
- pro Kind getrennt: **Noten**, **nächste Prüfungen**, **Elternbriefe**
- Elternbriefe: standardmäßig nur die **neuesten 10 pro Kind**
- Elternbriefbereich mit **eigenem Scrollbalken** und einstellbarer Höhe
- Brieftext aufklappbar; vorhandene Anhangsnamen werden angezeigt
- Anzahl sichtbarer Prüfungen einstellbar
- Aktualisieren-Knopf in der Kachel optional
- bisherige große Variable **Schulmanager Schulseite** wird in der Visualisierung ausgeblendet; sie bleibt intern als Fallback erhalten
- Knopf in den Einstellungen, um die Instanz ausdrücklich in **SymDo - Klassenseiten** umzubenennen

## Datenfluss

- Stundenplan / Vertretung / Entfall / Veranstaltung → **SymDo - Stundenplan**
- Hausaufgaben → **SymDo - Hausaufgaben**
- Prüfungen → optional mit genauer Schulstunde in **SymDo/OpenCalendar**
- Noten + Prüfungsübersicht + Elternbriefe → **eigene Klassenseiten-Kachel**

## Mehrere Kinder

Sind mehrere Kinder unter demselben Schulmanager-Elternkonto hinterlegt, reicht **eine Modulinstanz**. Nach **Kinder abrufen** werden alle gefundenen Kinder in der Zuordnung angeboten. Es gibt kein festes Zwei-Kinder-Limit.

In der Kachel erscheinen die Kinder oben als Umschaltknöpfe, z. B.:

`Paul | Tom | Anna`

Jedes Kind erhält seine eigene Ansicht mit Noten, Prüfungen und den zugeordneten Elternbriefen.

Hat ein weiteres Kind einen **anderen Schulmanager-Zugang / eine andere Schule**, wird dafür eine zweite Modulinstanz verwendet.

## Einstellungen – Klassenseite / Anzeige

- Überschrift in der Kachel
- Anzahl sichtbarer Prüfungen
- Anzahl der neuesten Elternbriefe je Kind (Standard 10)
- Höhe des Elternbrief-Scrollbereichs
- Aktualisieren-Knopf ein/aus
- Instanzname auf **SymDo - Klassenseiten** setzen

## Installation / Update

1. Inhalt des ZIPs in dein GitHub-Repository hochladen. `library.json` liegt direkt im Hauptverzeichnis.
2. In IP-Symcon bei der Modulverwaltung **Auf Aktualisierung prüfen** und aktualisieren.
3. IP-Symcon nach dem Update auf 1.3 einmal neu starten.
4. Instanz öffnen und **Übernehmen**.
5. Unter **Klassenseite / Anzeige** die Darstellung einstellen.
6. Optional **Instanzname auf „SymDo - Klassenseiten“ setzen** drücken.
7. Die bisherige leere originale SymDo-Klassenseiten-Kachel kann in der Visualisierung ausgeblendet werden; stattdessen diese Schulmanager-Instanz als Kachel verwenden.

## Bereits vorhanden

- Schulmanager-Login nur lesend
- mehrere Kinder unter einem Elternkonto
- Stundenplan → SymDo
- Vertretungen / Entfall / Veranstaltungen → SymDo
- Hausaufgaben → normale SymDo-Hausaufgabenliste
- Prüfungen bis 8 Wochen voraus
- Prüfungen mit exakter Stunde → OpenCalendar
- Noten über `get-grading-information-for-student`
- Wochenvorlage aus Schulmanager
- automatische Aktualisierung, Standard **30 Minuten**
- Schutz gegen wiederholte Fehlanmeldungen

## Sicherheit

- Schulmanager wird ausschließlich gelesen.
- Schreibzugriffe erfolgen nur in die eigenen SymDo-/OpenCalendar-Daten.
- Das Schulmanager-Passwort wird im Konfigurationsformular als Passwortfeld behandelt.
- Nach wiederholten Loginfehlern pausiert die automatische Anmeldung sechs Stunden.
