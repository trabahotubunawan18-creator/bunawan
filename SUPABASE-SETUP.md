# Moving Trabaho Tu Bunawan from XAMPP/MySQL to Supabase (PostgreSQL)

## 1. Create the tables in Supabase
1. Supabase dashboard -> **SQL Editor** -> New query.
2. Paste all of `database/schema.sql` -> **Run**.
3. (Optional, to keep your current records) paste `database/data.sql` -> **Run**.
   This file has real names/emails/phones/password hashes. It is git-ignored: do not upload it to GitHub.

## 2. Connect the site
Supabase -> **Connect** -> **Session pooler** (port 5432). Do NOT use the "Transaction pooler" (port 6543): the site uses prepared statements.

* On your PC (XAMPP): open `includes/config.local.php` and fill in DB_HOST, DB_USER, DB_PASS.
  In `C:\xampp\php\php.ini` make sure these two lines have no `;` in front, then restart Apache:
  `extension=pdo_pgsql` and `extension=pgsql`
* On a web host: set the same names as environment variables
  (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSLMODE=require, BASE_URL='' , SITE_URL, APP_DEBUG=0,
  SMTP_USER, SMTP_PASS, PRIVATE_DIR).

## 3. Publishing
* GitHub Pages cannot run PHP. Put the repo on a PHP host (Render, Railway, Fly.io, a VPS, shared hosting...). Supabase is only the database.
* Uploaded files (resumes, ID photos, payment proofs, logos) are saved on the server's disk, not in Supabase.
  Use a host with a persistent disk and point `PRIVATE_DIR` at it, or they disappear on redeploy.
* Never commit `includes/config.local.php` or `database/data.sql` (already in `.gitignore`).
