# Security — exposed database credential and encryption key (K-01)

> **Status: the leak is out of the repository's current tree, but the values
> are still in public git history. Rotating them is the only fix, and that is a
> host-side action.** This document is the runbook. Everything that can be done
> from inside the repository is already done (see “What was changed” below).

## 1. What was exposed

Since the first commit of this repository, two files were tracked with real
values despite their own header comments saying they were ignored:

| File | Contents |
|---|---|
| `application/config/database.local.php` | MySQL host, user, **password**, database name of the production host |
| `application/config/config.local.php` | the 64-character `encryption_key`, and `legacy_password_login = TRUE` |

The repository `joaqimmaftah-png/wmsv2` is **public** on GitHub. The files were
present in commit `2931961` (the grafted root of the history) and in every
commit built on it.

**Assume both values are public and have been copied.** Public repositories are
continuously scraped by automated tooling; “nobody knows the URL” is not a
control.

The database **user name** also appears in the README, `SYSTEM.md`, two
install-time help screens and the runtime log (K-03). A user name alone does
not grant access, but combined with a leaked password it identifies exactly
what to try.

## 2. What was changed in the repository (done)

- Both files were **removed from the index** with `git rm --cached`. They remain
  on disk, so the running site keeps working; they are no longer part of any
  new commit.
- A `.gitignore` now excludes `application/config/*.local.php` (the `.example`
  templates stay tracked), `.env*`, runtime logs and user uploads.
- `application/config/database.local.php.example` and
  `config.local.php.example` are the only copies in git, and contain
  placeholders only.
- `database/.htaccess` now denies web access to the SQL dump. `application/` and
  `system/` already had that guard; `database/` did not, so on a server that
  serves the app, `https://<host>/database/yzzme_43038742_aml.sql` was a
  downloadable copy of the staff table.
- `scripts/purge-secrets.sh` performs the history rewrite safely (mirror clone,
  verify, no push) for when you are ready to do it.

**Not done, and not doable from here:** changing the password on your MySQL
server, replacing the key on your server, and force-pushing rewritten history
to GitHub.

## 3. Rotate the database password — do this first

The password is the only leaked value that grants access to data.

### 3.1 Change it

On cPanel:

1. **MySQL Databases** → find the user (e.g. `yzzme_43038742`) →
   **Change Password**.
2. Generate a long random password (32+ characters, letters/digits/symbols).

On a server where the database user may run `ALTER USER`:

```sql
ALTER USER 'yzzme_43038742'@'localhost' IDENTIFIED BY '<new-password>';
FLUSH PRIVILEGES;
```

On cPanel, prefer the panel: the account user usually cannot edit
`mysql.user` directly.

### 3.2 Update the application

On the server only — never in git:

```bash
# either edit the file …
cp application/config/database.local.php.example application/config/database.local.php
nano   application/config/database.local.php
```

```php
$db['default']['hostname'] = 'sql309.yzz.me';
$db['default']['username'] = 'yzzme_43038742';
$db['default']['password'] = '<new-password>';
$db['default']['database'] = 'yzzme_43038742_aml';
```

… or use the environment variables `WMS_DB_HOST`, `WMS_DB_USER`,
`WMS_DB_PASS`, `WMS_DB_NAME` (see `application/config/database.php`). The local
file wins when both are present.

### 3.3 Verify

1. The site loads, and a screen that reads the database (Dashboard) shows data.
2. The **old** password is rejected:

   ```bash
   mysql -h sql309.yzz.me -u yzzme_43038742 -p -e 'SELECT 1'   # old password -> access denied
   ```
3. **Nothing else** uses that database user. Check other applications on the
   same hosting account, cron jobs, backup scripts, phpMyAdmin saved logins,
   `.env` files in other projects, and any monitoring tool configured with it.
   Each one needs the new password.

### 3.4 Check for use of the leaked credential

The repository has been public since it was created. Look for evidence:

- cPanel → **Access Logs**: requests for `/application/config/database.local.php`
  (the file is PHP and exits immediately unless `BASEPATH` is defined, so it
  would not disclose source) and for `/database/yzzme_43038742_aml.sql`
  (this one *was* served — a download of the staff table would appear here).
- MySQL/cPanel logs: connections from unexpected hosts.
- Data review: recent rows in `users`, `ci_sessions`, `settings`, and
  `material_transactions` you cannot account for; new database users or grants.

If anything looks wrong, treat it as an incident: preserve the logs, rotate
again, and audit the data.

## 4. Replace the encryption key

CodeIgniter's Encryption library is **never loaded** by this application
(no `$this->encrypt()` / `decrypt()` call exists in `application/`), and
database-backed sessions are not encrypted with it. A new key therefore needs
**no re-encryption** — nothing was encrypted with the old one.

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Put the value in `application/config/config.local.php` on the server:

```php
$config['encryption_key'] = '<64 hex characters>';
```

or set `WMS_ENCRYPTION_KEY`. Remove the old line. If you ever start using the
Encryption library, treat every value encrypted before this rotation as
compromised.

`legacy_password_login = TRUE` also appeared in that file. It is a separate
finding (K-02) — the flag is now the default in `config.php`, so nothing changed
by removing the local file. Do not flip it to `FALSE` until every account that
still has an MD5 hash has signed in once (each sign-in upgrades the hash to
bcrypt), or those users will be locked out.

## 5. Purge the values from git history

Rotating makes the old values worthless; the purge stops future clones from
carrying them.

### 5.1 Preferred: the script in this repository

```bash
# once, if git-filter-repo is not installed:
python3 -m pip install --user git-filter-repo

scripts/purge-secrets.sh https://github.com/joaqimmaftah-png/wmsv2.git
```

It clones the repository into a temporary directory, removes both paths from
every commit on every ref, verifies the result, and **prints** the push command.
It never touches your working copy and never pushes.

### 5.2 Manually

```bash
git clone --mirror https://github.com/joaqimmaftah-png/wmsv2.git wms-rewrite.git
cd wms-rewrite.git
git filter-repo --invert-paths \
  --path application/config/database.local.php \
  --path application/config/config.local.php
git log --all --oneline -- application/config/*.local.php    # must print nothing
git push --mirror https://github.com/joaqimmaftah-png/wmsv2.git
```

Without `git filter-repo`, substitute:

```bash
git filter-branch --force --prune-empty --index-filter \
  'git rm --cached --ignore-unmatch \
     application/config/database.local.php application/config/config.local.php' \
  --tag-name-filter cat -- --all
```

### 5.3 What a rewrite does and does not achieve

- Every commit id changes. Collaborators must delete their clones and re-clone.
  Open pull requests can be reopened from the rewritten branch (`#2` is one).
- GitHub keeps unreachable commits addressable **by SHA** until it garbage
  collects them. After the force-push, ask GitHub Support for a cache purge /
  removal of unreachable objects.
- Forks (none today), CI caches, release archives and third-party mirrors keep
  their own copies. This is why rotation, not the rewrite, is the fix.
- If the repository was ever public and you consider the history unrecoverable,
  deleting and recreating the repository is the only way to be certain — it
  also discards its PRs and issues.

## 6. Prevent recurrence

- `.gitignore` now covers the local configuration files, logs, uploads and
  extra dumps. `git status` should never show a `.local.php` file as untracked.
- Optional pre-commit guard (per clone):

  ```bash
  git config core.hooksPath .githooks
  ```

  `.githooks/pre-commit` refuses a commit that stages a local config, an
  `.env` file, or a SQL dump.
- On GitHub: **Settings → Code security** — confirm *Secret scanning* and
  *Push protection* are enabled for the repository. Public repositories get
  secret scanning for free; push protection blocks a credential before it is
  pushed.
- While K-02 is open (the production dump with MD5 hashes is still tracked),
  consider setting the repository to **private**:

  ```bash
  gh repo edit joaqimmaftah-png/wmsv2 --visibility private
  ```

  This is a mitigation, not a fix: the history already contains the values.

## 7. Checklist

- [ ] MySQL password changed on the host
- [ ] `database.local.php` (server only) updated and site verified
- [ ] Old password rejected by the server
- [ ] Other consumers of the database user found and updated
- [ ] Access logs checked for the config file and the SQL dump
- [ ] Encryption key replaced in `config.local.php`
- [ ] History rewritten and force-pushed — or consciously deferred
- [ ] GitHub Support asked to purge unreachable objects
- [ ] Secret scanning push protection confirmed on
- [ ] Team told to re-clone
