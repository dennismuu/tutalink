# LertFind Kenya

LertFind is a PHP 8.1+ and MySQL/MariaDB application for tutoring and home-service discovery, registration, booking requests, admin-set service pricing, role-scoped administration, and booking support.

## Run locally with XAMPP

1. Copy the project into `C:\xampp\htdocs\TUTALINK`.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Open phpMyAdmin at `http://localhost/phpmyadmin`, import `database/tutalink.sql`, and confirm the `tutalink` database was created.
4. The default local connection is `127.0.0.1`, database `tutalink`, user `root`, and an empty password. For a different MySQL account, set `TUTALINK_DB_HOST`, `TUTALINK_DB_NAME`, `TUTALINK_DB_USER`, and `TUTALINK_DB_PASSWORD` in the Apache process environment before restarting Apache.
5. From PowerShell, create the first administrator:

   ```powershell
   cd C:\xampp\htdocs\TUTALINK
   php scripts\create_super_admin.php
   ```

   The command prompts for the initial Super Admin details. Public registration cannot create administrator accounts. Administrators sign in at `http://localhost/TUTALINK/Admin/login.php`; regular users sign in at `http://localhost/TUTALINK/Pages/login.php`.
6. Visit `http://localhost/TUTALINK/`.

For an existing database created from an older version of this project, apply `database/migrations/001_paystack_provider-approval.sql` through `database/migrations/012_levels_locations_materials.sql` exactly once each, in numerical order, in phpMyAdmin. Migration 008 creates private provider-document records and returns previously approved providers to pending review. Migration 009 adds fixed home-service prices, laundry basket counts, and service-start codes. Migration 010 adds a role slug column to users and backfills it from the roles table. Migration 011 converts unpaid home-service cart checkouts to provider requests, so acceptance comes before payment. Migration 012 adds tutor teaching levels, session-location types, and paid revision-material records. Do not run these migrations after importing the current `database/tutalink.sql`; the fresh-install schema already includes these changes.

The schema uses `IF NOT EXISTS` and seeds role, category, and default-commission rows without replacing existing records. It does not create demo users or sample bookings.
Tutoring subjects start at an editable KES 500 per student per hour baseline in both the fresh-install schema and migration 005. Administrators set a fixed price for each home service and a per-basket price for Laundry. Existing databases require admins to enter these new home-service prices before those services become bookable.

## Container deployment

The root `Dockerfile` builds this existing PHP application for a Docker host such as Clever Cloud's Docker runtime; it does not make the application deployable on Vercel. The container runs Apache on port `8080` and includes PHP 8.2, PDO MySQL, cURL, mbstring, fileinfo, and URL rewriting.

Build locally with `docker build -t tutalink .`. Run it with the required database and production settings supplied at runtime (never build secrets into the image), and mount persistent storage at `/var/lib/tutalink-private`. The `TUTALINK_PRIVATE_STORAGE_DIR` setting points document and revision-material storage there. The image intentionally excludes the database dump, local uploads, and environment files.

Set `TUTALINK_ENV=production`, `TUTALINK_APP_URL` to the public HTTPS site URL, the `TUTALINK_DB_HOST`, `TUTALINK_DB_NAME`, `TUTALINK_DB_USER`, and `TUTALINK_DB_PASSWORD` values, `TUTALINK_PAYOUT_ENCRYPTION_KEY`, Paystack credentials, and mail sender settings in the host's runtime environment. Import and migrate the database separately. This image does not include an SMTP relay; configure a mail transport supported by the host before relying on verification or notification email.

**Clever Cloud Docker storage limitation:** its Docker runtime does not support persistent volumes or FS Buckets. Uploaded provider documents and revision materials stored on the container filesystem will be lost on redeploy/replacement. Do not launch file-upload features on Clever Cloud Docker until storage is moved to persistent object storage (such as Clever Cloud Cellar) or you choose a runtime with persistent storage. The Docker image can be used on another Docker host with a persistent volume mounted at `/var/lib/tutalink-private`.

## Implemented flows

- Public tutoring and home-service catalogues with provider profile browsing. Tutoring discovery starts with a learner level and only shows tutors who teach that level and are not handling another unfinished request.
- Admin-managed catalogue subjects and services, with in-use entries archived by hiding rather than deleting records referenced by providers or bookings.
- Admin booking conversations with per-admin unread message badges; opening a conversation marks its current messages as read for that administrator. Customers and providers can open booking conversations in a viewport-aware dashboard popover.
- Account registration for Student / Parent, Customer, Tutor, and Service Provider. Tutor registration supports multiple teaching levels. Provider registration collects qualifications, location, service mode, offered categories, and National ID and qualification-certificate uploads. Providers remain pending until an administrator verifies both documents and approves the profile; they can access their dashboard while pending, but are not listed for new requests until approved and available. Tutors do not set prices.
- Hashed passwords, single-use email-verification tokens, verified-email-only login, duplicate-email checks, CSRF-protected forms, session regeneration on login, and module-specific Admin 1 / Admin 2 permissions. Registration and tutor booking requests send email notifications; unverified users can request a fresh verification link.
- Guest browsing and cart retention through login. One cart can contain multiple services from the same provider and module.
- Provider accept / decline actions; decline alerts admins, and admins can send a new request to a matching provider.
- Tutoring and home-service carts display the selected provider and itemized prices. Both send a request to the selected provider first; payment is enabled only after acceptance. Tutoring uses rate × hours × students and records the learner level and session location. Home services use a fixed admin-set price, with Laundry calculated as price per basket × up to 10 baskets.
- Paystack checkout settles accepted bookings in KES after the provider has accepted the request. Payment is server-verified before the booking is marked paid, and each booking keeps its own price, commission, and provider share.
- If a provider declines a real paid booking, the system creates a refund request. An admin must process and record the refund through Paystack before reassigning the booking; the customer then pays the reassigned booking again.
- Admins set hourly prices for tutoring and fixed per-service prices for home services. Laundry's admin-set price is per basket. Providers do not set catalogue prices.
- Administrators have a dedicated booking list and analytics dashboard. They can upload private revision materials and set/update prices; students can download a resource only after a verified Paystack payment or a local simulated payment.
- Home-service providers move paid bookings through On the way → In progress. After work, the provider marks it complete and the customer confirms completion in their dashboard; only then is the booking completed and the customer can rate the provider. When the provider marks On the way, the customer receives a 6-digit code by email when delivery is configured and can view it in the dashboard; the provider must verify the customer's code before starting work.
- Paid tutoring bookings unlock the booking conversation and generate a one-time class code for the student. The tutor enters that code to start the lesson.
- For tutoring and home services, both parties must confirm completion before a provider can request withdrawal. An authorized admin approves the provider's earnings, the provider submits encrypted mobile-money or bank details, and the admin records the manual payout only after paying the provider outside LertFind. The configured commission is deducted before calculating the provider's payout. In local mode, demo payments can use the same withdrawal statuses to demonstrate the workflow, but the admin release is status-only and never transfers money; use test-only payout details. Older direct-settled payments are not eligible.
- Students can submit tutoring refund requests for admin review. Admin approval records the review only; the refund must still be processed through the original payment provider and marked processed in LertFind.
- Booking status history, in-app notifications and paid-booking conversations, general or booking-linked complaints, and module-scoped admin complaint management.
- Provider approval and availability controls, provider ratings/reviews, payment records and booking/payment reports.
- Super Admin user management with separate filters for ID, name, email, phone, role, status, booking count, and joined date.
- Provider identity documents are restricted to PDF/JPEG/PNG files up to 10 MB and stored outside the web root in `C:\xampp\lertfind-private\provider-documents` by default. Administrators with provider-management permission can securely download and review them. Enable PHP `fileinfo`, and set `upload_max_filesize` and `post_max_size` to at least 10 MB (with `post_max_size` slightly higher to account for multipart overhead).
- Revision materials are restricted to PDF/JPEG/PNG files up to 25 MB and stored privately in `C:\xampp\lertfind-private\revision-materials`. Enable PHP `fileinfo`, and set `upload_max_filesize` and `post_max_size` to at least 25 MB (with `post_max_size` slightly higher to account for multipart overhead).
- Local landing-page photos are stored in `images/` and served from this application.
- Paystack checkout initialization in KES, platform collection for provider payouts, verified callbacks/webhooks, and idempotent booking settlement.

## Configure Paystack

1. Enable PHP cURL in XAMPP's `php.ini` (`extension=curl`) and restart Apache.
2. Create/configure a Paystack account and set its server-side secret key and your app's base URL in Apache's environment. For local XAMPP, add a private config file at `C:\xampp\apache\conf\extra\httpd-tutalink-paystack.conf`, scope it to the app, and include it from `httpd.conf`:

   ```apache
   <Directory "C:/xampp/htdocs/TUTALINK">
       SetEnv PAYSTACK_SECRET_KEY "sk_test_REPLACE_WITH_YOUR_TEST_SECRET"
       SetEnv TUTALINK_APP_URL "http://localhost/TUTALINK"
   </Directory>
   ```

   Add `Include conf/extra/httpd-tutalink-paystack.conf` to `httpd.conf`, then restart Apache from the XAMPP Control Panel. Set `PAYSTACK_WEBHOOK_SECRET` only when configuring a webhook secret.
   Never place secret keys in PHP files, HTML, JavaScript, or a public repository. With `TUTALINK_ENV=local`, a valid test secret and app URL enable real Paystack test checkout; otherwise the local payment simulator remains available. Local mode rejects live secret keys. The hosted-checkout integration uses the secret key server-side; a public key is not needed. For production, set `TUTALINK_ENV=production`, use a public HTTPS `TUTALINK_APP_URL`, and protect credentials in the server's secret/environment configuration.
3. Configure the Paystack webhook to send events to `https://YOUR-DOMAIN/Pages/paystack_webhook.php`. The endpoint accepts signed `charge.success` events and independently verifies the full checkout total before marking bookings paid and notifying the selected provider. Localhost is not publicly reachable; use a secure HTTPS tunnel only for development webhook testing.
4. Tutoring and home-service payments are collected into the platform account for subsequent manual provider payout after both parties confirm completion. Configure the platform's settlement and payout procedures before accepting live payments.
5. Set `TUTALINK_PAYOUT_ENCRYPTION_KEY` to a long, random server-side secret before enabling production payout-detail submissions. Keep it stable and backed up securely; changing it makes stored provider payout destinations unreadable.
6. Test with Paystack test credentials: admin service pricing, multi-service same-provider totals, tutoring student counts, checkout before provider dispatch, provider accept/decline, refund-before-reassignment, home-service status updates, paid-only messaging, class-code generation/start, both completion confirmations, admin withdrawal approval, provider payout details, manual payout recording, callback, webhook, and duplicate delivery. Confirm tutoring and home-service payments settle to the platform account before switching to live mode.

The checkout supports Paystack's `card` and `mobile_money` channels where enabled for the merchant account. This is not a direct Safaricom M-Pesa STK Push or bank-transfer API integration; do not promise either until the required merchant capabilities and integrations are confirmed. In `TUTALINK_ENV=local`, tutoring checkout and accepted home-service bookings offer simulated M-Pesa/mobile money, card, and bank transfer choices. These only update local booking and payment records; no payment request is sent, no sensitive credentials are collected, and no money is transferred. Local demo payments can also proceed through the provider withdrawal and admin status-recording steps, but no cash is sent. Simulated payment and demo withdrawal are disabled outside local mode and must never be treated as a real charge or payout. The client-provided Annex B does not include the full service-by-service descriptions or rates, so the catalogue still needs the client's written schedule before it can be considered complete.

## Production checklist

- Serve exclusively over HTTPS and set `TUTALINK_ENV=production`; verify secure cookie/session settings and use production database credentials with least-privilege access.
- Set `TUTALINK_APP_URL` to the public HTTPS application base URL. Configure PHP `mail()` with a trusted outbound mail provider (or SMTP relay), a verified sender via `TUTALINK_MAIL_FROM` and `TUTALINK_MAIL_FROM_NAME` (for example, `no-reply@your-domain` and `LertFind`), and the domain's SPF/DKIM/DMARC records. Test actual inbox delivery for account verification and tutor-request notifications before launch; PHP accepting a message does not guarantee inbox delivery. Local mode uses the same email flow and no longer provides a manual verification bypass. Tutor profiles also require admin approval, availability, and an admin-set subject price before they can be booked.
- Rotate the initial Super Admin password, restrict administrative accounts, back up the database, and confirm Paystack KES settlement behavior with the merchant account before accepting live payments.

The named home-service catalogue entries from the project brief are seeded. “Home Organisation” is marked as coming soon.
