# Performance Management System (PMS) — PHP + MySQL

## Features
**Admin**
- Define KPIs/objectives (category, description, how measured, target, default weight)
- Define a clear rating scale per KPI (0/1 up to 3–10) with a label and observable description for every rating
- Manage users (admin / manager / employee), with a manager (reviewer) for each person
- Create **appraisal cycles**: choose the KPIs, set per-cycle weights (must total 100%), open, close, reopen, export results to CSV
- Opening a cycle creates an appraisal for every active employee/manager, reviewer = their manager

**Employees / managers**
1. Employee completes a **self-review** (rating + evidence per KPI), saves drafts, submits
2. Manager reviews, rates each KPI, submits → **weighted final score (%) and performance band** are calculated
3. Employee sees the result (and manager ratings) only after completion

Scoring: each rating is normalised to its own scale (rating − min) / (max − min), then weighted-averaged across KPIs and shown as a percentage. Bands are set in `config.php` (`score_bands`).

## Requirements
PHP 8.0+ with `pdo_mysql`, MySQL 5.7+ / 8.x or MariaDB 10.3+ (InnoDB, utf8mb4).

## Install
1. Edit the `db` section of `config.php` (or set `PMS_DB_HOST`, `PMS_DB_PORT`, `PMS_DB_NAME`, `PMS_DB_USER`, `PMS_DB_PASS`).
2. Change the default admin email/password in `config.php`.
3. Run the installer once (browser: `http://localhost:8000/install.php`, or CLI):
   ```bash
   php install.php
   ```
   It creates the database (if your MySQL user may), the tables from `database/schema.sql`, and the first admin.
   To do it manually: `mysql -u root -p -e "CREATE DATABASE pms CHARACTER SET utf8mb4"` then `mysql -u root -p pms < database/schema.sql`.
4. **Delete `install.php`.**
5. Start: `php -S localhost:8000` and sign in.

## Typical flow
Users → add managers and employees (set each employee's manager) → KPIs → Appraisal cycles → New cycle → Open → people complete reviews → Close → Export CSV.

## Notes
- The cycle stores a snapshot of each KPI's title, category, scale and weight. A KPI's scale range is locked once it is in an open/closed cycle; KPIs used in a cycle can't be deleted (deactivate instead).
- Employees are never deleted, only deactivated, to keep appraisal history.
- Use HTTPS in production and block web access to `includes/` and `database/` (`.htaccess` included for Apache; Nginx: `location ~ ^/(includes|database)/ { deny all; }`).
- This replaces the earlier SQLite version; there is no data migration (the old version only held test KPIs).
