# Performance Management System (PMS) — PHP + MySQL

Digital version of the performance scorecard sheet.

## Scorecard structure
**Perspective → Objective → Strategic priority → KPI (measure)**

Perspectives (fixed, in this order): Finance/Stewardship · Customer-Operations/Membership · Business Process · Organizational Capacity.

Each KPI has: measure text (E), key actions/initiatives (D), target (F), weight (H) and its own **rating scale** (e.g. 1–5) where every rating has a label and a description of what it means.
During an appraisal, the sheet's remaining columns are filled in: **Actual (G)**, **Actual perf. (I)** = the rating, **Weighted score (J) = H × I**, **Comments (K)**. Every perspective gets a **sub-total** row, and there is a grand total.

Final score % = Σ(weight × rating) ÷ Σ(weight × highest rating) × 100, mapped to a performance band (`score_bands` in `config.php`).

## Roles and flow
- **Admin**: Objectives & priorities → KPIs → Users → Appraisal cycles (choose KPIs, set weights that total 100, open/close, export CSV) → Scorecard page shows the sheet layout and rating guide.
- **Employee**: self-review (actual, rating, evidence per KPI) → submit.
- **Manager**: reviews, rates each KPI → submit → weighted scores and final score are calculated; the completed appraisal is shown in the sheet layout.
- Opening a cycle creates an appraisal for every active employee/manager; the reviewer is their manager.

## Requirements
PHP 8.0+ with `pdo_mysql`; MySQL 5.7+/8 or MariaDB 10.3+ (InnoDB, utf8mb4).

## Install (fresh database)
1. Edit the `db` section of `config.php` (or set `PMS_DB_HOST`, `PMS_DB_PORT`, `PMS_DB_NAME`, `PMS_DB_USER`, `PMS_DB_PASS`) and the default admin.
2. Run `php install.php` once (or open `/install.php`). It creates the database (if allowed), the tables from `database/schema.sql`, and the first admin.
3. **Delete `install.php`**, then `php -S localhost:8000` and sign in.

**Upgrading from the previous build:** the table layout changed (objectives, priorities, new KPI columns). Drop the old `pms` database and install again.

## Notes
- A cycle keeps a snapshot of each KPI (text, target, weight, scale). A KPI's scale range is locked once it is in an open/closed cycle; KPIs used in a cycle can't be deleted (deactivate instead).
- To rename a perspective, change it in `config.php` **and** the ENUMs in `database/schema.sql`.
- Use HTTPS in production and block web access to `includes/` and `database/` (`.htaccess` included for Apache).
