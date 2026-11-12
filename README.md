# SmartHotel MVP

A lightweight PHP 8 and MySQL starter for day-to-day hotel front-desk work. HTML structures the pages, CSS styles them, and JavaScript previews the booking total; PHP validates and saves the data; MySQL stores it.

## Included in this MVP

- Operations dashboard with room occupancy, arrivals, pending bookings, and actual checkout payments recorded today.
- Visual room map and room rate/readiness management.
- Guest directory populated from bookings.
- Date-overlap checks, stay-price calculation, pending/confirmed reservations, check-in, checkout, and payment recording.
- Official public hotel website at `stay.php`, with live room availability, room rates, admin-managed photos/video, and the existing 50% PayPal advance-payment booking flow.
- Admin-managed website slideshow, per-room photos, and short hotel introduction videos.
- Responsive interface, prepared SQL statements, output escaping, and CSRF tokens.
- English, French, and Kiswahili interface translations with a language choice saved in the current browser.
- Personal in-app notifications with an unread-count bell; notifications are private to the staff member who performed each booking, room, payment, or account action.
- Self-service staff profiles for name, email, password, and profile photo updates.
- Animated hotel-building loading screen with receptionist, housekeeping, chef, concierge, and manager stages on internal navigation and form submissions; supports reduced motion.
- Individual staff accounts with admin-only staff management, role-based page/action permissions, temporary passwords, and forced password changes.
- Admin-issued staff registration links bound to an email and role, single-use, and expiring after 48 hours.
- Session hardening, CSRF protection, security headers, idle expiry, session revocation after resets/deactivation, and sign-in throttling.

## Setup with XAMPP

1. Copy this folder into `C:\xampp\htdocs\hotel management`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Import `database/schema.sql` using phpMyAdmin or the MySQL command line.
4. If needed, set database connection environment variables (`HOTEL_DB_HOST`, `HOTEL_DB_PORT`, `HOTEL_DB_NAME`, `HOTEL_DB_USER`, `HOTEL_DB_PASSWORD`) or update the local defaults in `config.php`.
5. Create the first administrator from a terminal: `php .\scripts\create-admin.php admin@example.com "Hotel Owner"`. The script is command-line-only and prints a random temporary password once; save it securely and change it at first sign-in.
6. Open `http://localhost/hotel%20management/` to see the official public hotel website and room-booking page. Staff can sign in from its **Staff sign in** link or open `login.php` directly; after login they are redirected to the staff dashboard.

The default connection expects a local XAMPP MySQL server at `127.0.0.1:3306`, database `hotel_management`, user `root`, and an empty password.

### Migrate an existing installation

Back up the database first. For a database created from the original rooms/bookings/guests/payments schema, apply migrations `001` through `009` once each in order (for example, in phpMyAdmin). They add staff/security features, online bookings and PayPal checkout, website media, encrypted PayPal settings, a localhost-only test payment method, and manually reviewed Mobile Money submissions without deleting existing reservation data. Migration 004 is retained for upgrade compatibility. Do not rerun migrations or re-import the updated schema over a live database.

Admins can manage the public slideshow, attach room photos to rooms, and upload short hotel videos from **Website media** in the staff workspace. Images must be JPEG, PNG, or WebP up to 5 MB; videos must be MP4 or WebM up to 20 MB. For video uploads on XAMPP, set PHP's `upload_max_filesize` to at least `20M` and `post_max_size` to at least `24M`, then restart Apache. Uploaded files are stored under `uploads/website-media`; PHP execution is denied in that directory. Hide media to remove it from the public booking page without deleting it.

### Public bookings and PayPal

The guest booking page is `stay.php`; guests select available dates and rooms, then choose PayPal Checkout when configured or submit an Airtel Money transaction reference for staff verification. PayPal bookings are confirmed only after the server verifies the completed capture details. Airtel Money requests remain pending until staff independently confirm receipt and approve the booking. Unpaid PayPal holds expire after 30 minutes; manual Mobile Money holds expire after 24 hours. Successful PayPal payments received after a hold expires are recorded and sent to staff for review. At checkout, staff collect only the unpaid balance, recorded in RWF.

An admin can manage PayPal from **PayPal settings** in the staff workspace. Before the admin enters credentials, the hosting administrator must set `PAYPAL_SETTINGS_ENCRYPTION_KEY` to a base64-encoded random 32-byte key in the hosting control panel’s environment-variable settings. Generate one securely with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`; keep a protected backup of the key, since losing it makes the saved PayPal Secret unreadable. Restart PHP/the application after setting the key. Then the admin signs in, opens **PayPal settings**, enters the PayPal REST app Client ID and Secret, receiver email, currency, RWF exchange rate, and mode, and saves. The PayPal Secret is encrypted in the database; it is never shown again in the form. This is not the PayPal website password. The account owner creates the REST app in PayPal Developer → Apps & Credentials. Use Sandbox credentials and mode for testing, then enter Live app credentials and select Live when ready.

For local UI testing without PayPal, set `HOTEL_PAYMENT_TEST_MODE=1` in the local PHP/Apache environment and restart Apache. The simulation is only enabled when both the configured app URL and current request are plain-HTTP localhost/127.0.0.1/::1. The booking page labels it as a simulation, creates a `test` payment record, and does not transfer money. Test payments are excluded from dashboard revenue. Never enable this setting on a public host. To use the guest Airtel Money option, the guest pays outside the website using hotel-provided instructions and submits the transaction reference. A staff member must verify the payment in the Airtel merchant account before approving the booking; the site does not contact Airtel or verify the transaction automatically. Manual-payment holds expire after 24 hours. Apply migrations `008_local_test_payments.sql` and `009_manual_mobile_money_submissions.sql` to existing databases; fresh installations include both features in `database/schema.sql`.

Alternatively, the host may configure the following environment variables; saved dashboard settings take precedence:

- `PAYPAL_CLIENT_ID` and `PAYPAL_CLIENT_SECRET` — the server-side PayPal REST app credentials. Never expose the secret or commit either credential.
- `PAYPAL_RECEIVER_EMAIL` — the PayPal business account email that should receive booking payments. The order and completed capture are checked against this address.
- `PAYPAL_CURRENCY` — a supported two-decimal checkout currency (`USD`, `EUR`, `GBP`, `CAD`, `AUD`, `NZD`, `CHF`, `SGD`, or `HKD`).
- `PAYPAL_RWF_PER_UNIT` — the hotel’s configured exchange rate, expressed as RWF per one unit of the PayPal currency. For example, a value of `1500` means 1 USD is treated as 1,500 RWF when `PAYPAL_CURRENCY=USD`. Set and maintain this rate yourself; the application does not fetch or assume an exchange rate.
- `PAYPAL_MODE` — `sandbox` (default) or `live`.
- `HOTEL_APP_URL` — the public HTTPS base URL for this installation, without a trailing slash. The local XAMPP HTTP URL intentionally disables PayPal checkout.

The PayPal REST credentials must belong to a merchant account allowed to receive payments in its registered country. The configured payee must also be authorized by that app. PayPal and card availability, including Visa, depends on PayPal account/country eligibility and is not guaranteed by this application. Confirm the account can receive funds and the selected currency in PayPal before switching `PAYPAL_MODE=live`. Use sandbox credentials and a publicly reachable HTTPS URL to test end-to-end first. Existing migration 004 records are retained for compatibility; new booking deposits are processed only through PayPal.

## Staff roles

- **Admin / Owner:** full access and staff-role administration.
- **Manager:** full operational dashboard, room/bookings/guest management, and operational staff management. Managers cannot create or change administrator, manager, or accountant accounts.
- **Receptionist:** guest directory, bookings, check-in/out, and front-desk dashboard.
- **Housekeeper:** sees only rooms assigned by an admin/manager; can move assigned rooms between cleaning and cleaned. No rates or payment data.
- **Maintenance Officer:** can view room readiness and mark rooms for maintenance or cleaned; no rates or payment data.
- **Chef, Restaurant Cashier, Concierge, Security Guard:** role accounts can be created, but their restaurant, guest-request, and visitor-log modules are not included yet.
- **Accountant:** sees recorded revenue and read-only booking totals without guest contact details. Expense and salary reporting is not included yet.

Admins and managers can directly create operational staff from **Staff** using a temporary password. Admins can also generate a one-time registration link for a specific email and role; the link can be sent manually using WhatsApp, expires after 48 hours, and lets the invited person set their own password. Creating links for an email revokes any earlier unused link for that email. An invite never lets a registrant choose their email or role, and there is no open public signup. Set `HOTEL_APP_URL` to the public HTTPS base URL in a deployed environment so generated links point to the correct site. Disabling an account or resetting its password revokes its active sessions. The last active administrator cannot be disabled from the interface.

## Database structure and next modules

`rooms` stores room number, type, floor, current nightly price, and readiness (`available`, `cleaning`, `cleaned`, `maintenance`). Occupancy is derived from active check-ins. `room_assignments` limits housekeepers to rooms assigned by management. `website_media` stores admin-managed public slideshow images, room photos, and hotel videos with visibility and display order. `guests` stores contact, email, and ID/passport details. `bookings` links a guest to a room, dates, a price snapshot, total, booking source, and lifecycle status. `payments` records deposits and checkout balances in RWF plus provider capture currency and amount; `online_payment_attempts` tracks PayPal orders, converted and RWF amounts, references, and verification state. `notifications` stores private activity notices and read state for their user; the bell count only includes that user’s unread items. Profile photos are restricted to JPEG, PNG, and WebP files up to 2 MB.

The schema also includes `users` for staff access and their profile photo, `login_attempts` for throttling repeated sign-in requests, and `audit_logs` for successful logins, sign-outs, password changes, and staff account changes.

Recommended next modules for a production hotel product:
- `menu_items`, `orders`, and `order_items` for restaurant service and room charges.
- `stock_items` and `stock_movements` for inventory and low-stock alerts.
- `expenses` for operating-cost and profit reporting.
- Salaries/payroll, expense approval, settings, visitor logs, guest requests, and maintenance tickets.
- Invoice/receipt templates, PDF generation, and printing.
- Offline synchronization after defining its security and conflict-resolution requirements.

## Security and MVP limitations

Use HTTPS in any deployment outside local development; secure session cookies and HSTS are enabled automatically when HTTPS is detected. Set a dedicated database account/password through the `HOTEL_DB_*` environment variables instead of using XAMPP's local `root` account, restrict database/network access, and arrange backups and server-side log monitoring. Apache rules block direct access to configuration, schema, include, and setup-script files; configure equivalent deny rules if deploying on another web server. This is a practical security baseline, not a guarantee of security or a substitute for a deployment-specific review. Settings, expense approvals, salary data, restaurant, PDF invoices, automated SMS/WhatsApp, and offline synchronization are not included. Public PayPal payments require eligible merchant credentials, a configured receiver and exchange rate, an externally reachable HTTPS callback, and provider-side testing before live launch; taxes and add-on charges are not included.
