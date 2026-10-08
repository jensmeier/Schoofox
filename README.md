# Schulmanager SymDo für IP-Symcon

Eigenständiges IP-Symcon-Modul für **Schulmanager Online** mit Anbindung an den vorhandenen **SymDo Stundenplan**.

## Funktionen

- Schulmanager-Login nur lesend
- mehrere Kinder unter einem Elternkonto
- Stundenplan → SymDo
- Vertretungen → SymDo
- Unterrichtsentfall → SymDo
- Veranstaltungen → SymDo
- Klassenarbeiten im importierten Stundenplan markieren
- Hausaufgaben lesen und direkt in die normale SymDo-Hausaufgabenliste synchronisieren
- Prüfungen bis 8 Wochen voraus lesen
- Elternbriefliste lesen
- Wochenvorlage aus regulären Schulmanager-Stunden erzeugen
- automatische Aktualisierung, Standard **30 Minuten**
- Schutz gegen wiederholte Fehlanmeldungen

## Installation

1. Den **Inhalt dieses ZIP-Ordners** in ein eigenes GitHub-Repository hochladen. Wichtig: `library.json` muss direkt im Hauptverzeichnis des Repositories liegen.
2. In IP-Symcon **Module Control / Modulverwaltung** öffnen.
3. Repository-URL eintragen, z. B. `https://github.com/jensmeier/Schoofox`.
4. Modul installieren bzw. aktualisieren.
5. Neue Instanz **Schulmanager SymDo** anlegen.
6. Zugangsdaten eintragen und **Übernehmen**.
7. **Verbindung testen**.
8. **Kinder abrufen**.
9. In der Zuordnung pro Schulmanager-Kind den Kindnamen aus der vorhandenen SymDo-Stundenplaninstanz eintragen.
10. Vorhandene **SymDo Stundenplan**-Instanz auswählen.
11. Erst **Trockenlauf**, danach **Jetzt abrufen und übernehmen**.
12. Optional einmalig **Wochenvorlage aus Schulmanager übernehmen**.

## Zwei Kinder

Sind beide Kinder unter demselben Schulmanager-Elternkonto, reicht **eine Modulinstanz**. Beide Kinder werden mit nur einer Anmeldung abgerufen.

Falls ein zweites Kind einen anderen Schulmanager-Zugang bzw. eine andere Schule verwendet, einfach eine **zweite Instanz von Schulmanager SymDo** anlegen.

## SymDo-Hausaufgaben

Ab Version 1.1 kann das Modul die Schulmanager-Hausaufgaben über die vorhandene SymDo-App-API in den normalen SymDo-Hausaufgabenbestand übernehmen. Dafür:

1. im Modul die richtige **SymDo-Gateway**-Instanz auswählen,
2. **Mit SymDo verbinden** einmal anklicken,
3. Verbindung testen,
4. **Hausaufgaben in die normale SymDo-Hausaufgabenliste übernehmen** aktiviert lassen,
5. **Jetzt abrufen und übernehmen** ausführen.

Das Schulmanager-Modul erscheint im SymDo-Gateway als gekoppeltes Gerät **„Schulmanager Sync“**. Abhaken in SymDo bleibt erhalten; Änderungen am Text oder Datum aus Schulmanager werden nachgezogen. Verschwundene, noch offene Schulmanager-Aufgaben werden entfernt. Erledigte Aufgaben bleiben als Verlauf erhalten.

Die Roh-JSON-Variablen für Hausaufgaben, Prüfungen und Elternbriefe werden ab 1.1 im Objektbaum intern weitergeführt, aber für die Visualisierung ausgeblendet.

## Hinweis zur SymDo-Anbindung

Der aktuelle Stundenplan wird über die öffentliche Funktion `STPL_ImportSlots()` direkt in SymDo geschrieben. Hausaufgaben laufen über die vorhandene, gekoppelte SymDo-App-API. Prüfungen und Elternbriefe bleiben vorerst in der Schulmanager-Übersicht. Das originale SymDo-Gateway muss dafür nicht verändert werden.

## Sicherheit

- Es gibt keine schreibenden Aufrufe an Schulmanager.
- Das Passwort wird als IP-Symcon-Instanzeigenschaft gespeichert; im Formular wird es als Passwortfeld angezeigt.
- Nach wiederholten Loginfehlern pausiert die automatische Anmeldung sechs Stunden.
- Schulmanager verwendet hierfür interne Web-Endpunkte; diese können sich ändern.

## Update 1.1

Bei einer bereits vorhandenen Modulinstanz IP-Symcon nach dem Modulupdate einmal neu starten, damit die neuen Eigenschaften und internen Attribute registriert werden. Danach die Instanz öffnen, das SymDo-Gateway wählen und einmal koppeln.
