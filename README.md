# Zeiterfassung

Schlankes Zeiterfassungs-Tool in PHP (ohne Framework) mit SQLite-Datenbank und ein bisschen Vanilla-JS für die Stoppuhr-UI.

## Features

- **Stoppuhr / Ein-Ausstechen** für die tägliche Arbeitszeit, mit **Pause** und **Art** (Büro, Homeoffice, Außendienst)
- **Letzte 7 Tage** direkt sichtbar, Zeiten inline editier- und löschbar
- **Arbeitszeit nachtragen** ohne Stoppuhr (Start + Ende)
- **Aufträge** mit eigener Stoppuhr (mehrere gleichzeitig möglich), Kunde als Eingabefeld mit Vorschlägen (neue Namen werden automatisch als Kunde angelegt), Art (Fernwartung, Vor-Ort, Werkstatt), Auftragsname und Notiz; filterbar nach Kunde und Monat, sortierbar nach Datum oder Kunde; Häkchen „Abgerechnet“
- **Kunden** mit Kundennummer, Adresse, Kontakt und Notiz; Monatsübersicht je Kunde mit Aufträgen und Material
- **Material je Kunde** (Datum, Bezeichnung, Menge, Einheit, Einkaufspreis, Notiz, abgerechnet), erfassbar unter Aufträge und auf der Kundenseite
- **PDF-Leistungsnachweis** je Kunde und Monat mit Aufträgen, Summen je Art und Material (ohne externe Bibliothek), als Variante für den Kunden oder intern mit Einkaufspreisen
- **Name** je Benutzer (z.B. für PDF-Exporte), unter Mein Konto und in der Verwaltung
- **Zeitkonto** mit Soll/Ist/Saldo; Urlaubs- und Krankheitstage zählen nicht als Soll
- **Kalender** für Urlaub und Krankheit, für alle Benutzer einsehbar (Notizen nur für Betroffene und Admins)
- **Änderungs-Protokoll** aller Zeit- und Abwesenheitsänderungen
- **Footer** mit Text und Links (z.B. Datenschutz, Impressum), im Admin-Panel einstellbar
- **Login** mit Session (bleibt 30 Tage ohne Aktivität gültig, `SESSION_LIFETIME` in `includes/config.php`), Passwort-Hashes (`password_hash`/`password_verify`)
- **Rollen** Benutzer, Manager und Admin (siehe unten)
- **Mein Konto**: eigenes Passwort ändern
- **Verwaltung**: Benutzer anlegen / löschen / Passwort setzen / Rolle vergeben
- **CSV-Export** (Arbeitszeit oder Aufträge), filterbar nach Benutzer und Zeitraum
- **CSRF-Schutz** auf allen POST-Routen

## Rollen

| | Benutzer | Manager | Admin |
|---|---|---|---|
| Eigene Arbeitszeit, Aufträge, Zeitkonto | ✓ | ✓ | ✓ |
| Teamkalender ansehen, eigene Abwesenheiten | ✓ | ✓ | ✓ |
| Eigenes Passwort ändern | ✓ | ✓ | ✓ |
| Zeiten, Aufträge und Zeitkonto aller Benutzer ansehen und bearbeiten | | ✓ | ✓ |
| Alle Kalenderdetails, Abwesenheiten für andere eintragen | | ✓ | ✓ |
| Kunden ansehen, Material eintragen, eigene Aufträge einem Kunden zuordnen | ✓ | ✓ | ✓ |
| Kunden anlegen, bearbeiten, deaktivieren; PDF-Export je Kunde | | ✓ | ✓ |
| Benutzer verwalten | | ✓ (außer Admins) | ✓ |
| CSV-Export, Protokoll aller Benutzer | | ✓ | ✓ |
| Footer und weitere Server-Einstellungen | | | ✓ |

Bestehende Admins werden beim Update automatisch zur Rolle Admin, alle anderen zu Benutzer.

## Voraussetzungen

- PHP **8.1+** mit aktivierter `pdo_sqlite`-Extension
- Schreibrechte auf den `data/`-Ordner

## Schnellstart (PHP-Builtin-Server)

```bash
php -S 0.0.0.0:8080 -t public
```

Im Browser öffnen: <http://localhost:8080>

Beim ersten Aufruf erscheint die Erst-Einrichtung — dort den Admin-Account anlegen.

## Update auf eine neue Version

**Wichtig: Der Ordner `data/` enthält alle Daten (`data/app.sqlite`) und darf beim Update nie überschrieben oder gelöscht werden.**

1. Vorher eine Kopie von `data/app.sqlite` an einem sicheren Ort ablegen.
2. Nur den Code aktualisieren:
   - mit Git: `git pull` im Projektordner (der Inhalt von `data/` ist nicht im Repository und bleibt unberührt), oder
   - per FTP/Upload: nur die Ordner `public/` und `includes/` (und `README.md`) hochladen und ersetzen, **nicht** den ganzen Projektordner und **nicht** `data/`.
3. Seite aufrufen. Die Datenbank wird beim ersten Aufruf automatisch umgestellt. Vorher legt die App selbst eine Sicherung unter `data/backups/app-v<alte Version>-<Datum>.sqlite` an. Die Umstellung läuft in einer Transaktion: Schlägt sie fehl, bleibt die Datenbank unverändert.

Falls nach einem Update die Erst-Einrichtung erscheint, wurde `data/app.sqlite` beim Hochladen ersetzt oder gelöscht: nichts eingeben, sondern die gesicherte Datei zurück nach `data/app.sqlite` kopieren.

## Deployment (Apache / Nginx)

- DocumentRoot auf `public/` setzen.
- Sicherstellen, dass `data/` (und der gesamte Projekt-Root oberhalb von `public/`) **nicht** über das Web erreichbar sind. Bei Apache hilft das mitgelieferte `.htaccess`. Bei Nginx einfach den Server-Block nur auf `public/` zeigen lassen.
- Sessions liegen unter `data/sessions/` (wird automatisch angelegt, `data/` muss beschreibbar sein).

## Verzeichnisstruktur

```
public/             # DocumentRoot
  index.php         # Login
  setup.php         # Erst-Einrichtung
  dashboard.php     # Stoppuhr + letzte 7 Tage
  balance.php       # Zeitkonto
  calendar.php      # Urlaubs-/Krankheitskalender
  log.php           # Änderungs-Protokoll
  jobs.php          # Auftragszeiten + Notizen
  customers.php     # Kunden, Monatsübersicht, Material
  customer_pdf.php  # PDF-Leistungsnachweis je Kunde und Monat
  account.php       # Mein Konto (Passwort ändern)
  admin.php         # Verwaltung: Benutzer, Footer, CSV-Filter
  admin_export.php  # CSV-Download
  logout.php
  api/              # AJAX-Endpunkte (POST + JSON)
  assets/           # CSS + Vanilla JS
includes/           # PHP-Helfer (db, auth, csrf, layout, helpers, config, customers, pdf)
data/               # app.sqlite + sessions/ (auto-erstellt)
```

## Datenbank

SQLite-Datei wird beim ersten Start automatisch unter `data/app.sqlite` angelegt.
Tabellen: `users`, `work_sessions`, `job_entries`, `customers`, `materials`, `absences`, `settings`, `audit_log`.
Bestehende Datenbanken werden beim nächsten Aufruf automatisch migriert.

Backup = Kopie dieser Datei. Vor jeder Schema-Umstellung legt die App automatisch eine Kopie unter `data/backups/` an. Reset = Datei löschen, beim nächsten Aufruf neu einrichten.

## Zeitzone

Server läuft in `Europe/Berlin` (siehe `includes/config.php`). Beim Wechsel die Konstante anpassen.

## CSV-Format

- Trennzeichen: `;`  (Excel-freundlich)
- Encoding: UTF-8 mit BOM
- Spalten Arbeitszeit: Mitarbeiter, Art, Start, Ende, Dauer (Min)
- Spalten Aufträge: Mitarbeiter, Kunde, Art, Auftrag, Start, Ende, Dauer (Min), Notiz, Abgerechnet
