# SmartHotel MVP

A lightweight PHP 8 and MySQL starter for day-to-day hotel front-desk work. HTML structures the pages, CSS styles them, and JavaScript previews the booking total; PHP validates and saves the data; MySQL stores it.

## Included in this MVP

- Operations dashboard with room occupancy, arrivals, pending bookings, and actual checkout payments recorded today.
- Visual room map and room rate/readiness management.
- Guest directory populated from bookings.
- Date-overlap checks, stay-price calculation, pending/confirmed reservations, check-in, checkout, and payment recording.
- Responsive interface, prepared SQL statements, output escaping, and CSRF tokens.
- Animated hotel-building loading screen with receptionist, housekeeping, chef, concierge, and manager stages on internal navigation and form submissions; supports reduced motion.
- Individual staff accounts with admin-only staff management, role-based page/action permissions, temporary passwords, and forced password changes.
- Session hardening, CSRF protection, security headers, idle expiry, session revocation after resets/deactivation, and sign-in throttling.

## Setup with XAMPP

1. Copy this folder into `C:\xampp\htdocs\hotel management`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Import `database/schema.sql` using phpMyAdmin or the MySQL command line.
4. If needed, set database connection environment variables (`HOTEL_DB_HOST`, `HOTEL_DB_PORT`, `HOTEL_DB_NAME`, `HOTEL_DB_USER`, `HOTEL_DB_PASSWORD`) or update the local defaults in `config.php`.
5. Create the first administrator from a terminal: `php .\scripts\create-admin.php admin@example.com "Hotel Owner"`. The script is command-line-only and prints a random temporary password once; save it securely and change it at first sign-in.
6. Open `http://localhost/hotel%20management/` and sign in with that admin email and temporary password.

The default connection expects a local XAMPP MySQL server at `127.0.0.1:3306`, database `hotel_management`, user `root`, and an empty password.

### Migrate an existing installation

Back up the database first. For a database created from the original rooms/bookings/guests/payments schema, apply `migrations/001_expand_staff_roles.sql` once (for example, in phpMyAdmin). It adds staff/security tables, expands the role and room-status enums, and creates room assignments without deleting existing users or reservations; existing role and room data are preserved. Do not re-import the updated schema over a live database as a substitute for this migration. If there is no administrator account afterward, create one with the CLI setup command above.

## Staff roles

- **Admin / Owner:** full access and staff-role administration.
- **Manager:** full operational dashboard, room/bookings/guest management, and operational staff management. Managers cannot create or change administrator, manager, or accountant accounts.
- **Receptionist:** guest directory, bookings, check-in/out, and front-desk dashboard.
- **Housekeeper:** sees only rooms assigned by an admin/manager; can move assigned rooms between cleaning and cleaned. No rates or payment data.
- **Maintenance Officer:** can view room readiness and mark rooms for maintenance or cleaned; no rates or payment data.
- **Chef, Restaurant Cashier, Concierge, Security Guard:** role accounts can be created, but their restaurant, guest-request, and visitor-log modules are not included yet.
- **Accountant:** sees recorded revenue and read-only booking totals without guest contact details. Expense and salary reporting is not included yet.

Admins and managers create operational staff from **Staff**. Only admins can create manager, accountant, or admin accounts. Each new account receives a cryptographically generated temporary password shown only once and must change it at first login. Disabling an account or resetting its password revokes its active sessions. The last active administrator cannot be disabled from the interface.

## Database structure and next modules

`rooms` stores room number, type, floor, current nightly price, and readiness (`available`, `cleaning`, `cleaned`, `maintenance`). Occupancy is derived from active check-ins. `room_assignments` limits housekeepers to rooms assigned by management. `guests` stores contact and ID/passport details. `bookings` links a guest to a room, dates, a price snapshot, total, and lifecycle status. `payments` records checkout amount, method, and timestamp.

The schema also includes `users` for staff access, `login_attempts` for throttling repeated sign-in requests, and `audit_logs` for successful logins, sign-outs, password changes, and staff account changes.

Recommended next modules for a production hotel product:
- `menu_items`, `orders`, and `order_items` for restaurant service and room charges.
- `stock_items` and `stock_movements` for inventory and low-stock alerts.
- `expenses` for operating-cost and profit reporting.
- Salaries/payroll, expense approval, settings, visitor logs, guest requests, and maintenance tickets.
- Invoice/receipt templates, PDF generation, and printing.
- Online booking, notifications, and offline synchronization after defining their security and conflict-resolution requirements.

## Security and MVP limitations

Use HTTPS in any deployment outside local development; secure session cookies and HSTS are enabled automatically when HTTPS is detected. Set a dedicated database account/password through the `HOTEL_DB_*` environment variables instead of using XAMPP's local `root` account, restrict database/network access, and arrange backups and server-side log monitoring. Apache rules block direct access to configuration, schema, include, and setup-script files; configure equivalent deny rules if deploying on another web server. This is a practical security baseline, not a guarantee of security or a substitute for a deployment-specific review. Settings, expense approvals, salary data, restaurant, PDF invoices, automated SMS/WhatsApp, online reservations, and offline synchronization are not included. Checkout currently records the complete room-stay amount as one payment; deposits, partial payments, taxes, and add-on charges need a later billing module.
