# Supabase Setup

## What this implementation stores where

### Public / static content (kept in `data/site-content.json`, mirrored to Supabase)
- Site settings, hero, products, categories, navigation, footer, FAQ, news,
  reviews, celebs, diamond shapes, coupons, attribute profiles
- Lives in the `app_state.key = 'site_content'` JSONB row. The same JSON is
  cached on disk so the storefront doesn't round-trip to the database for
  every page render.

### Private / sensitive data (Supabase **only** — never written to disk)
| Table | Holds |
| --- | --- |
| `customers` | Customer accounts, password hashes, saved addresses, wishlist, order stats |
| `orders` | Full order history incl. shipping address, payments, refunds, items |
| `newsletter_subscribers` | Email list and consent flags |
| `appointments` | Single-row `config` + `bookings` array for the showroom scheduler |
| `admin_users` | Super admin + employee admin accounts (one table, `role` column) |
| `admin_requests` | Employee → super admin approval queue |
| `cart_sessions` | Persistent guest / customer cart snapshots |
| `media_assets` | URL + metadata for hosting-stored uploads |

### Login lockout state (Supabase only)
- `app_state.key = 'admin_login_attempts'`
- `app_state.key = 'employee_admin_login_attempts'`

Actual uploaded files stay on hosting disk under the configured uploads path. Supabase stores only URLs and metadata for those files.

## Required server configuration
- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`
- `SUPABASE_SERVICE_ROLE_KEY`

Optional:
- `AZURONN_UPLOADS_ROOT`
- `AZURONN_UPLOADS_PUBLIC_BASE_URL`
- `AZURONN_ADMIN_USERNAME`
- `AZURONN_ADMIN_PASSWORD_HASH`
- `AZURONN_EMPLOYEE_ADMIN_USERNAME`
- `AZURONN_EMPLOYEE_ADMIN_PASSWORD_HASH`
- `STRIPE_SECRET_KEY`
- `STRIPE_PUBLISHABLE_KEY`
- `STRIPE_WEBHOOK_SECRET`

You can provide these either as real server environment variables or in `data/runtime-config.php` (use `data/runtime-config.example.php` as the template). That file lives under the protected `data/` directory and is easier on shared hosting when env vars are inconvenient.

The code can fall back to the publishable key for requests, but for a real production cutover of private data you should set `SUPABASE_SERVICE_ROLE_KEY`. Without it, private table reads/writes are expected to fail unless you open permissive policies in Supabase.

## Initial setup

1. Open the Supabase SQL Editor.
2. Run `supabase/schema.sql`. This creates the public blob tables, all six
   private tables, indexes, RLS, and `updated_at` triggers.
3. Add the required server configuration on hosting (preferred shared-hosting
   method: `data/runtime-config.php`).
4. Run the health check:

   ```bash
   php scripts/supabase-health-check.php
   ```

5. Run the seed script to upload the public blob:

   ```bash
   php scripts/supabase-sync.php
   ```

6. Run the private-data migration script. This reads everything that used to
   live in JSON, uploads it to the new private tables, then **deletes** the
   private JSON files from disk. Aborts before any destructive step if any
   Supabase write fails:

   ```bash
   php scripts/supabase-private-migrate.php
   ```

7. Log in to the admin panel and save a small change to verify write access.

## Storage model

- Images and videos upload to hosting storage under `UPLOADS_ROOT_PATH`.
- When no path is configured, the application uses an `azuronn-media`
  directory beside the deployed checkout. For a normal Hostinger document
  root such as `/home/USER/domains/DOMAIN/public_html`, the default is
  `/home/USER/domains/DOMAIN/azuronn-media`.
- The public `/assets/uploads/admin/<filename>` URL is routed through
  `media.php`, so the storage directory does not need to be web-accessible.
- Do not configure `UPLOADS_ROOT_PATH` inside the Git deployment directory.
  If persistent storage is not writable, uploads intentionally fail instead
  of being saved somewhere a later deployment can delete.
- Before the first deployment of this storage model, copy any current runtime
  files from `assets/uploads/admin/` to the persistent directory. Git/Supabase
  metadata contains only their URLs and cannot restore file contents that a
  previous Hostinger deployment already removed.
- New JPG, PNG, and WebP uploads larger than 900 KB (or 2200 px) are resized
  and encoded as WebP when the PHP GD/WebP extension is available. GIF and
  video files are kept unchanged.
- Database rows store:
  - public URL
  - local file path
  - mime type
  - file size
  - media type

This avoids filling the free Supabase plan with large media files.

## Notes

- This implementation keeps the current PHP auth/session model.
- If Supabase is unreachable, the code falls back to the local JSON/file store
  for static data so the site does not hard-fail during rollout.
- Private-data tables (`customers`, `orders`, `newsletter_subscribers`,
  `appointments`, `admin_users`, `admin_requests`) refuse to fall back to JSON.
  Without Supabase, those features stop working — by design, the JSON copy is
  intentionally no longer present after migration.
- A passing health check requires `SUPABASE_SERVICE_ROLE_KEY`. With only the
  publishable key, the app can report readiness but cannot guarantee secure
  private writes.
- The `appointments.lock` file may stay on disk after migration: it's a
  process-local mutex used by `appointments_with_lock()` and contains no
  customer data. The migration script will also leave the lock file behind
  if the `appointments` table isn't reachable yet so an in-flight write
  isn't lost.

## Rollback

To roll back, restore the JSON files from a backup taken before
`supabase-private-migrate.php` ran. The migration only deletes files; it does
not drop Supabase rows. If the script was never run, the JSON files still
hold everything.

After restoring JSON, the code in `includes/*.php` is wired to read from
Supabase first and fall back to JSON, so a partial migration is safe — until
the destructive step runs.
