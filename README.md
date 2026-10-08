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
- Hausaufgaben lesen
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

## Hinweis zur SymDo-Anbindung

Der aktuelle Stundenplan wird über die öffentliche Funktion `STPL_ImportSlots()` direkt in SymDo geschrieben. Hausaufgaben, Prüfungen und Elternbriefe liegen in dieser eigenständigen Version zusätzlich als Statusvariablen/HTML-Übersicht im Modul vor. Dadurch muss das originale SymDo-Gateway nicht verändert werden und ein SymDo-Update überschreibt diese Integration nicht.

## Sicherheit

- Es gibt keine schreibenden Aufrufe an Schulmanager.
- Das Passwort wird als IP-Symcon-Instanzeigenschaft gespeichert; im Formular wird es als Passwortfeld angezeigt.
- Nach wiederholten Loginfehlern pausiert die automatische Anmeldung sechs Stunden.
- Schulmanager verwendet hierfür interne Web-Endpunkte; diese können sich ändern.
