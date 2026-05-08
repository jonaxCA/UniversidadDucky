# Universidad Ducky — Library Management System

A complete, ISO 9001:2015-compliant library management system for Universidad Ducky. Built in vanilla PHP with MariaDB, it handles the full lifecycle of an academic library: catalog management, multi-role access, loans and returns, fine calculation, waitlists, bibliographic cards (Dewey + Cutter classification), institutional reports, and a mobile-friendly catalog browse experience for students and faculty.

## Features

### Catalog & Inventory
- Book catalog with editorials, categories, ISBN-10/13 validation, and per-copy inventory tracking
- Cover image upload (local file) or external URL
- Auto-generated bibliographic cards in ISBD format with Dewey Decimal classification and Cutter author codes
- Search and filter by title, author, ISBN, genre, and library location

### Loans & Returns
- Full loan workflow with role-based borrowing limits (student / faculty / staff)
- Automatic due-date calculation pulled from system configuration
- Renewals with configurable maximum count
- Waitlist for unavailable titles
- Return processing with condition tracking (good / damaged / lost)
- Race-condition-safe transactions (`SELECT ... FOR UPDATE`)
- Duplicate-loan prevention per title per user

### Fines & Treasury
- Automatic fine generation on overdue returns at a configurable daily rate
- Replacement-cost calculation for lost books (configurable multiplier on purchase price)
- Fine management dashboard with payment receipt tracking
- CSV export of pending fines for institutional treasury

### Reports
- Users with pending fines (Servicios Escolares)
- Monthly fine breakdown — generated, collected, pending (Tesorería)
- Top 10 most-borrowed books
- Loans due in the next N days
- Overdue loans without fine record (data integrity check)
- All reports exportable as CSV (UTF-8 BOM, Excel-compatible)

### Security & Compliance
- Role-based access control (administrador, bibliotecario, profesor, alumno)
- Bcrypt password hashing (cost 12)
- Password reset flow with single-use, time-limited tokens (1-hour expiry)
- ISO 9001:2015 audit log capturing every create / update / delete / loan / return / renewal / payment
- Session regeneration on login, HttpOnly + SameSite=Strict cookies
- PDO prepared statements throughout — no raw SQL concatenation
- Self-locking installer (`setup.php` → `setup.lock` after first run)

### Mobile Experience
- The student/faculty journey (login → catalog → book details) is fully responsive and optimized for phones
- Single-column layout, touch-friendly controls, anti-zoom input sizing for iOS
- Administrative tools remain desktop-focused

## Tech Stack

- **Backend:** PHP 8.0+ with PDO
- **Database:** MariaDB 10.6+ (InnoDB, utf8mb4_unicode_ci)
- **Frontend:** Vanilla HTML/CSS/JS, Inter font, Font Awesome 6
- **Server:** Apache (XAMPP, LAMP, or any standard PHP/MariaDB host)

## User Roles

| Role            | Capabilities                                                                 |
| --------------- | ---------------------------------------------------------------------------- |
| `administrador` | Full system access: users, settings, audit log, all modules                  |
| `bibliotecario` | Catalog, loans, returns, fines, reports                                      |
| `profesor`      | Browse catalog, view own loans/fines, request waitlist                       |
| `alumno`        | Browse catalog, view own loans/fines, request waitlist                       |

## Project Structure

```
UniversidadDucky/
├── includes/           # auth.php · db.php · functions.php
├── sql/
│   └── universidad_ducky.sql   # Single-file schema (DB + tables + indexes + seed config)
├── images/             # Static assets
├── uploads/covers/     # User-uploaded book covers (auto-created)
├── *.php               # Pages (one file per route)
├── style.css           # Single global stylesheet
└── setup.php           # First-run installer (auto-locks after success)
```

---

## Deployment on XAMPP (Windows)

1. **Clone or unzip** this repo into `C:\xampp\htdocs\UniversidadDucky\`.

2. **Start XAMPP** Control Panel and ensure both `Apache` and `MySQL` are running.
   > If port `3306` conflicts with another MariaDB/MySQL service, edit `C:\xampp\mysql\bin\my.ini` and change `port=` under `[client]` and `[mysqld]` to `3307`. Then update `DB_PORT` in `includes/db.php` and `$port` in `setup.php` accordingly.

3. **Open the installer** in your browser:
   ```
   http://localhost/UniversidadDucky/setup.php
   ```
   - Leave the MariaDB root password field empty (XAMPP default)
   - Set the initial admin email and password
   - Check **"Include test data"** to seed 8 demo books, 7 demo users, and sample loans
   - Click **Inicializar sistema**

4. **Log in** at `http://localhost/UniversidadDucky/`
   - Default admin: `admin@ducky.edu` / `Admin1234!`
   - Demo users (if seeded): `alumno1@ducky.edu.mx`, `prof1@ducky.edu.mx`, `biblioteca1@ducky.edu.mx` — all with password `Demo1234!`

### Mobile Access (Same Wi-Fi)

To open the site from a phone on the same network:
1. Run `ipconfig` and copy your IPv4 address (e.g. `192.168.1.45`)
2. Add an inbound firewall rule allowing TCP ports 80 & 443 (Private profile only)
3. From your phone: `http://192.168.1.45/UniversidadDucky/`

### Notes

- `setup.php` self-locks after a successful run by creating `setup.lock`. To reinstall, delete that file and the `universidad_ducky` database.
- For production, change `DB_PASS` in `includes/db.php` and remove `setup.php` from the server.
- The single SQL file at `sql/universidad_ducky.sql` can also be imported directly via phpMyAdmin or `mysql -u root -P 3307 < sql/universidad_ducky.sql`.
