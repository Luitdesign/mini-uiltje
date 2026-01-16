# Financial Web App (MVP)

A small PHP + MySQL web app to:
- Login
- Upload ING-style CSV exports
- View transactions per month/year
- Categorize transactions
- View monthly summary split into Income vs Spending

## 1) Requirements
- PHP 8.1+ (PDO MySQL enabled)
- MySQL / MariaDB
- A web server (Apache/Nginx) with document root set to `public/`

## 2) Setup
1. Create a database (empty).
2. Copy config:
   - `config/config.sample.php` -> `config/config.php`
   - Fill in DB credentials.
3. Point your webserver document root to: `financial_webapp_mvp/public`
4. Open in browser:
   - `/install.php`
   - Create tables + create the first admin user.
5. Delete `/public/install.php` after install.

## 3) CSV format supported
This MVP is designed around ING NL CSV exports (semicolon separated, quoted), with headers like:
- Datum (YYYYMMDD)
- Naam / Omschrijving
- Af Bij
- Bedrag (EUR)
- Mutatiesoort
- Mededelingen
- Saldo na mutatie

If you later upload other bank formats, we can add importers.

## 4) Notes
- Import uses a transaction hash to avoid duplicates.
- Amounts are stored as a signed number: `Af` => negative, `Bij` => positive.

## 5) Rules maker + auto categories
- Rules are evaluated in ascending `position`. Lower numbers run first.
- Only `active_from` is used for rule activation (no end date).
- Matching is case-insensitive and ignores all whitespace (we lowercase + remove spaces before comparing).
- Regex rules run against the normalized (lowercase, no whitespace) field value.
- Auto categories never auto-confirm; manual categories always win.
