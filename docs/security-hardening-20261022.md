# Security hardening review — 2026-10-22

Scope: application-owned PHP endpoints, authentication, session safety, customer
and supplier CRUD, uploads, backup destinations, payment rate-limiting IP
resolution and private repository artifacts. This review does **not** claim a
penetration test or a live server vulnerability assessment.

## Critical findings and remediations

1. Customer/supplier create/update/delete endpoints were callable without
   account login or CSRF verification. They now require authenticated POST +
   valid CSRF, and the legacy forms and AJAX deletes submit the same token.
2. The login and public payment rate limiters trusted unverified X-Forwarded-For
   and Client-IP. They now use the socket REMOTE_ADDR unless it belongs to the
   explicit comma-separated `MEMAR_TRUSTED_PROXIES` environment allowlist.
   The proxy must overwrite X-Forwarded-For, not append untrusted user input.
3. A SQL backup and three end-user store images were accidentally committed
   to the Git repository. They were removed **from the current branch tree**
   and .gitignore now prevents repeat commits. IMPORTANT: Git commits/history
   and already downloaded copies continue exposing the historic files. If
   the repository was public, assume the SQL dump and images were exposed.
   An authorized, coordinated history purge and affected-credential rotation,
   plus an affected-data incident review, are required. Never force-push or
   erase history automatically without backup and coordination.
4. Database access previously depended on a checked-in root/no-password
   configuration. Production connections now REQUIRE these server-side envs:
   `MEMAR_DB_USER`, `MEMAR_DB_PASSWORD`. Optional settings:
   `MEMAR_DB_HOST` (default 127.0.0.1), `MEMAR_DB_NAME` (retains previous
   installed DB name). Only `CI=true` automated tests may use a blank
   root password. Deploy the environment and **least-privileged separate DB
   account** before installing this branch; otherwise the application returns
   503 rather than connecting insecurely. Do not use root in production.
5. Backup folder names now target `../memar_private_backups/<name>` outside
   webroot, and absolute paths under the public document root are rejected.
   Former files remain in the original old location, and must be transferred
   by an administrator and removed from the public tree after verification.
   Apache .htaccess denies downloads of SQL/ZIP/etc and executable uploads;
   it is supplemental only and **not an Nginx protection**.
6. Username-only legacy sessions are not accepted. Authenticated sessions
   periodically recheck that their user still exists, is active, and has the
   same password hash. The session used to change a password remains valid;
   old sessions are revoked within a minute. Baseline defensive headers
   (nosniff, framing, referrer and permissions) are applied centrally.

## Nginx / hosting restrictions required (not part of PHP app)

Apache: make sure `AllowOverride` honors the .htaccess files. Nginx/LiteSpeed/
Caddy must have equivalent deny rules for `/backups/`, SQL files, hidden
configuration files, executable files inside `/assets/uploads/`, and directory
indexing. Ideally set `MEMAR_MEDIA_STORAGE` and all backup destinations
outside the public webroot with restrictive ownership/permissions.

Never publicly expose Git metadata (`/.git/`), logs, DB dumps, source-only test
fixtures, or production secrets. Configure HTTPS and Secure cookies at TLS
termination, and verify app host/proxy headers cannot be spoofed.

## Pre-deploy gate

- Provision and verify production `MEMAR_DB_USER` / `MEMAR_DB_PASSWORD` in
  environment or web-server pool settings **before** deploying new db.php.
- Confirm private backup directory is writable; move existing backups safely
  from their prior webroot destination if any. Verify SQL/ZIP URLs return 403/404.
- Confirm production branding logo/signature/stamp images still exist, or
  upload new local copies through the admin UI. The three historic image assets
  were removed from version control and are not restored by Git checkout.
- Force-expire and renew all compromised database/application credentials as
  indicated by the historical SQL exposure. Evaluate historical records and
  remove the old Git objects with an explicitly approved repository rewrite.
- Run PHP/JS lint, MySQL and payment/currency regressions, authenticated HTTP
  CRUD/CSRF tests, browser responsive tests and restore-to-staging drills.
  This branch must NOT be automatically merged to `main` until hosting
  configuration and the exposure-remediation gate are approved.

## Non-goals / residual risks

This patch does not implement a new permissions/roles system, encrypt all
historic client/stamp assets, audit all third-party library CVEs, certify
payment PCI compliance, or guarantee immunity to vulnerabilities. Database
restoration remains a privileged/destructive administrative operation; it
must only be used for validated archives in a dedicated staging environment.
