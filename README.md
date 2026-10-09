# SymDo Schul-Integrationen

IP-Symcon-Erweiterungen für SymDo.

## Schulmanager SymDo

Bestehende Schulmanager-Anbindung für Stundenplan, Hausaufgaben, Prüfungen, Elternbriefe und Klassenseite.

## SchoolFox SymDo

Eigene Klassenseite für ein SchoolFox-Kind (z. B. Tom):

- liest SchoolFox-Mitteilungen read-only
- zeigt standardmäßig die letzten 10 Mitteilungen mit eigenem Scrollbereich
- liest die Fächer ausschließlich aus dem ausgewählten `SymDo - Stundenplan`
- manuelle Noten je Fach direkt in der Kachel
- Note anklicken = ändern oder löschen
- einfacher Durchschnitt je Fach
- alte Noten bleiben erhalten, wenn ein Fach später nicht mehr im Stundenplan steht
- Funktionswächter für neue, von SchoolFox selbst gelieferte Hinweise auf Stundenplan/Noten
- erkannte neue SchoolFox-Funktionen werden nur gemeldet, nicht automatisch aktiviert

### Einrichtung

1. Modulbibliothek in IP-Symcon aktualisieren.
2. Instanz `SchoolFox SymDo` anlegen.
3. SchoolFox-Benutzername und Passwort eintragen.
4. `SymDo - Stundenplan` auswählen und Kind auf `Tom` setzen.
5. `Verbindung testen`, danach `Jetzt aktualisieren`.
6. Optional den Instanznamen per Knopf auf `SymDo - Klassenseiten` setzen.

SchoolFox wird ausschließlich gelesen. Manuelle Noten liegen lokal in IP-Symcon und werden nicht zu SchoolFox geschrieben.
