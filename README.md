# Zeiterfassung

Schlankes Zeiterfassungs-Tool in PHP (ohne Framework) mit SQLite-Datenbank und ein bisschen Vanilla-JS für die Stoppuhr-UI.

## Features

- **Stoppuhr / Ein-Ausstechen** für die tägliche Arbeitszeit
- **Letzte 7 Tage** direkt sichtbar, Zeiten inline editier- und löschbar
- **Arbeitszeit nachtragen** ohne Stoppuhr (Start + Ende)
- **Aufträge** mit eigener Stoppuhr, Auftragsname und Notiz
- **Zeitkonto** mit Soll/Ist/Saldo; Urlaubs- und Krankheitstage zählen nicht als Soll
- **Kalender** für Urlaub und Krankheit, für alle Benutzer einsehbar (Notizen nur für Betroffene und Admins)
- **Änderungs-Protokoll** aller Zeit- und Abwesenheitsänderungen
- **Footer** mit Text und Links (z.B. Datenschutz, Impressum), im Admin-Panel einstellbar
- **Login** mit Session (bleibt 30 Tage ohne Aktivität gültig, `SESSION_LIFETIME` in `includes/config.php`), Passwort-Hashes (`password_hash`/`password_verify`)
- **Admin-Panel**: Benutzer anlegen / löschen / Passwort setzen / Admin-Rechte verwalten
- **CSV-Export** (Arbeitszeit oder Aufträge), filterbar nach Benutzer und Zeitraum
- **CSRF-Schutz** auf allen POST-Routen

## Voraussetzungen

- PHP **8.1+** mit aktivierter `pdo_sqlite`-Extension
- Schreibrechte auf den `data/`-Ordner

## Schnellstart (PHP-Builtin-Server)

```bash
php -S 0.0.0.0:8080 -t public
```

Im Browser öffnen: <http://localhost:8080>

Beim ersten Aufruf erscheint die Erst-Einrichtung — dort den Admin-Account anlegen.

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
  admin.php         # Benutzerverwaltung + CSV-Filter
  admin_export.php  # CSV-Download
  logout.php
  api/              # AJAX-Endpunkte (POST + JSON)
  assets/           # CSS + Vanilla JS
includes/           # PHP-Helfer (db, auth, csrf, layout, helpers, config)
data/               # app.sqlite + sessions/ (auto-erstellt)
```

## Datenbank

SQLite-Datei wird beim ersten Start automatisch unter `data/app.sqlite` angelegt.
Tabellen: `users`, `work_sessions`, `job_entries`, `absences`, `settings`, `audit_log`.
Bestehende Datenbanken werden beim nächsten Aufruf automatisch migriert.

Backup = Kopie dieser Datei. Reset = Datei löschen, beim nächsten Aufruf neu einrichten.

## Zeitzone

Server läuft in `Europe/Berlin` (siehe `includes/config.php`). Beim Wechsel die Konstante anpassen.

## CSV-Format

- Trennzeichen: `;`  (Excel-freundlich)
- Encoding: UTF-8 mit BOM
- Spalten Arbeitszeit: Benutzer, Start, Ende, Dauer (Min)
- Spalten Aufträge: Benutzer, Auftrag, Start, Ende, Dauer (Min), Notiz
