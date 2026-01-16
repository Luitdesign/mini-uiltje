# Mini Uiltje (website edition)

This is a plain PHP + MySQL website (no Docker). It lets you:

- Login (admin + users)
- Upload ING transactions CSV exports
- Browse transactions by month
- Categorize (manual override)
- Review queue (unconfirmed / uncategorized)
- Monthly results: income vs spending (+ optional transfer categories)

## Requirements

- PHP 8.1+ with PDO MySQL enabled
- MySQL 8.x or MariaDB 10.4+
- A web server (Apache/Nginx) or Synology Web Station

## 1) Setup database

Create a database and a user, for example:

- DB name: `mini_uiltje`
- DB user: `mini_uiltje`
- DB password: choose a strong password

## 2) Configure the app

Copy the sample config:

- `config/config.sample.php` ➜ `config/config.php`

Edit `config/config.php` and set DB host, db name, user, password.

## 3) Set the webroot

Point your web server / virtual host to the `public/` folder.

Example paths:
- document root: `/path/to/mini-uiltje-website/public`

## 4) Install (first run)

Open:

- `/install.php`

This creates the tables and creates the first admin user.

Then login at:
- `/login.php`

## Useful endpoints

- `/health` (no login)
- `/health?db=1` (checks DB)
- `/version` (no login)

## Notes

- Transactions are deduped using a stable hash, so overlapping exports won't duplicate entries.
- Re-importing the exact same file is blocked.
- Version shown in UI comes from the `VERSION` file (and optionally `APP_COMMIT` if set as an env var).
