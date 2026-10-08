# SymDo – Schulmanager Online (lokaler Test-Patch)

Basis: `da8ter/SymDo-Family-Organizer`, Branch `SymDo-Beta`, Commit
`30683218eba8aaa5e77523ba881109f20d20c3ee`.

## Zweck

Erweitert **SymDo - Gateway → Schule** um **Schulmanager Online**. Die Integration ist
rein lesend gegenüber Schulmanager und kann mehrere Konten sowie mehrere Kinder je
Konto zuordnen.

Enthalten:
- Stundenplan/Vertretungen/Entfall/Veranstaltungen → vorhandenes `STPL_ImportSlots()`
- Hausaufgaben → vorhandener SymDo-Hausaufgabenbestand, Quelle `schulmanager`
- Klassenarbeiten/Prüfungen → vorhandene SymDo-Prüfungsdarstellung
- Elternbriefe → neue Briefe erkennen; optional über den vorhandenen KI-Eingang auswerten
- 1–3 Schulmanager-Konten, mehrere Kinder pro Konto
- Schutz gegen wiederholte Fehlanmeldungen
- Knopf **Wochenvorlage aus Schulmanager übernehmen**

## Empfohlenes Abrufintervall

**30 Minuten** als Standard. Das ist ein guter Kompromiss für Vertretungsänderungen,
ohne das Elternkonto unnötig oft anzumelden. 15 Minuten ist als Mindestwert möglich;
60 Minuten ist für sehr ruhige Schulen ebenfalls sinnvoll.

Ein Abruf meldet sich je Schulmanager-Konto nur einmal an und verarbeitet danach alle
zugeordneten Kinder dieses Kontos.

## Installation zum Testen

1. Vorher den aktuellen SymDo-Modulordner sichern.
2. `SymDoGateway/libs/Schulmanager.php` in den gleichnamigen Ordner der installierten
   SymDo-Bibliothek kopieren.
3. `module.php.patch` auf `SymDoGateway/module.php` anwenden. Alternativ die im Patch
   gezeigten sechs kleinen Einfügungen manuell übernehmen.
4. SymDo-Bibliothek/Modul neu laden bzw. den Symcon-Kernel einmal neu starten.
5. **SymDo - Gateway → Schule → Schulmanager Online** öffnen.
6. Konto aktivieren, Benutzername/Passwort eintragen und **Verbindung testen**.
7. **Kinder abrufen** drücken.
8. Pro Kind das SymDo-Familienmitglied und die vorhandene Stundenplan-Instanz auswählen.
9. Optional **Wochenvorlage aus Schulmanager übernehmen** drücken.
10. Danach **Jetzt abrufen und übernehmen** testen; anschließend den 30-Minuten-Timer aktiv lassen.

## Sicherheit / Verhalten

- Schulmanager wird nur gelesen; es gibt keine schreibenden Schulmanager-Aufrufe.
- Der erste Elternbrief-Abruf setzt nur einen Ausgangsstand. Alte Briefe werden nicht
  massenhaft an die KI geschickt.
- Bei drei Loginfehlern pausiert das betroffene Konto für sechs Stunden.
- Passwörter liegen als Symcon-Instanzeigenschaft wie bei der vorhandenen WebUntis-
  Integration.
- Die verwendete Schulmanager-Schnittstelle ist intern und nicht offiziell dokumentiert;
  Änderungen am Schulmanager können Anpassungen erforderlich machen.
- Ein späteres Update aus dem Module Store kann lokale Änderungen überschreiben. Für
  dauerhaften Betrieb sollte die Änderung in einen eigenen Fork/Branch bzw. upstream PR.

## Bekannte Einschränkung dieses ersten Patches

Die Prüfungsablage benutzt zunächst die bereits vorhandene SymDo-WebUntis-
Prüfungslogik. Das ist für einen Schulmanager-only-Haushalt passend. Wer für dasselbe Kind
parallel WebUntis und Schulmanager aktiviert, sollte die Prüfungen vorerst nur aus einer
Quelle beziehen, bis die Prüfungsspeicherung vollständig quellengetrennt ist.
