# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What it is

PikaRelay is a plain-PHP web relay on the public internet:
- **PikaReader** timing units push status and tag reads to it and poll it for commands.
- **PikaDownloader** clients poll it for reads and queue commands for the readers.

Key documents:
- `project.md`: the original spec.
- `docs/API.md`: the wire contract. Keep it in sync with any API change.
- `README.md`: install and operations.

Sibling Java projects (each has its own CLAUDE.md):
- `../PikaReader`: its READ and STATUS JSON shapes are what the relay stores.
- `../PikaDownloader`: its relay client should mimic `LocalReader`. Its old `RemoteReader` protocol (`mac`, CSV `rfid`, `status/`, `data/since/`) is legacy and deliberately **not** supported.

## Commands

No PHP or Composer is installed system-wide on this machine. Use the copies in `.tools/` (git-ignored):

```sh
.tools/php/php .tools/composer.phar install
.tools/php/php vendor/bin/phpunit                          # all tests (~0.2 s)
.tools/php/php vendor/bin/phpunit --filter testCommandLifecycle
PHP=.tools/php/php tools/lint.sh                           # php -l + PHPCompatibility (7.4-8.4)
PHP=.tools/php/php tools/smoke.sh                          # end-to-end over HTTP via php -S
sh tools/build-release.sh v1.2.0                           # build/pikarelay-1.2.0.zip from HEAD
```

- `.tools/php/php` is a static PHP 8.4 build from static-php-cli. There is no PHP 7.4 binary, so 7.4 compatibility is enforced by `phpcs.xml` (PHPCompatibility 10, `testVersion 7.4-8.4`).
- `composer.json` pins `config.platform.php` to 7.4.33 so that dev dependencies (PHPUnit 9.6) still install for 7.4.
- Release zips get `src/VERSION` (the tag minus a leading `v`) from `build-release.sh`. `PikaRelay\Version` reads it for the Admin page and falls back to `dev`. Publishing a GitHub release runs `.github/workflows/release.yml`, which builds and attaches the zip.

## Hard constraints

- **PHP 7.4 through 8.4.** Do not use:
  - `match`, nullsafe `?->`, union or `mixed` types
  - constructor promotion, named arguments
  - trailing commas in parameter lists
  - `catch` without a variable
  - `str_contains` / `str_starts_with`, enums, `readonly`
  - implicitly nullable parameters (`Foo $x = null`; write `?Foo $x = null`)

  Run `tools/lint.sh` after changes.
- **No runtime dependencies.** The deployed app is the repo minus the `export-ignore` paths in `.gitattributes`. `vendor/` is dev-only and must never be needed at runtime.
- **PDO numbers.** PDO on PHP 7.4 returns SQLite numbers as strings, so always cast numeric columns with `(int)`.
- **Two databases.** All SQL must run on both SQLite and MySQL/MariaDB.
  - Put dialect differences in `Db/Database` (`insertIgnoreSql`, `transaction`) or `Db/Schema`.
  - The reads table is `tag_reads`, because `READS` is a reserved word in MySQL.
  - `rfid_reader` and `antenna` default to `-1` rather than NULL, so the unique index works.
- **Validate, don't sanitize.** Bad input gets a 4xx (usually 422) and nothing is stored; batches are all-or-nothing. All validation rules live in `Http/Validator`.
- **Only `index.php` is a web entry point.**
  - `src/`, `config/` and `data/` must never be served. Apache handles this with `.htaccess`; nginx and lighttpd use the examples in `server-configs/`.
  - Templates escape every value with `$e()`.
  - The CSP forbids inline scripts, so JavaScript goes in `assets/app.js`.

## Architecture

**Request flow.** `index.php` → `src/bootstrap.php` (autoloader for `PikaRelay\` → `src/`) → `App::handle(Request)` → `Response`.
- `App` routes on the first path segment. Segments in `App::API_ROOTS` are the JSON API, with JSON errors. Everything else is the HTML admin UI.
- With no config file, every UI path goes to `Web/SetupController`, and the API returns 503.
- Errors: handlers throw `Http/HttpException(status, message)`; its message is shown to the caller. Any other `Throwable` is logged and becomes a generic 500; a `PDOException` becomes a 503.

**Request paths and links.** `Request::fromGlobals` derives the route from `REQUEST_URI` relative to `SCRIPT_NAME`, so an install can live in a sub-folder and works with or without URL rewriting (`/base/index.php/route`). Build every link with `$request->url()` or `$request->assetUrl()`; never hard-code `/`.

**Config** (`Config`): a PHP file returning an array.
- Location: `config/config.php`, or the path in the `PIKA_CONFIG` env var. Tests and the smoke script use the env var.
- `Config::create` uses exclusive create, which is what disables setup.
- `save()` writes atomically (temp file + rename) and invalidates opcache.
- An empty `admin_hash` is password-recovery mode.

**Auth.**
- `Auth/ApiAuth`: in secure mode, checks the `X-Pika-Key` / `X-Pika-Secret` headers against the `api_keys` table. The secret is compared as `hash_hmac(sha256, secret, config.pepper)`, and each key has a role, `reader` or `client`.
- `Auth/AdminAuth`: the single admin account in the config. Uses a PHP session plus a CSRF token on every POST, and throttles failed logins (5 per 15 minutes per IP, tracked in the `login_attempts` table).

**Reads** (`Api/ReadsController`). Most of the design subtlety is here.
- Each read is stored once (insert-ignore on the unique index), and its normalized JSON goes in `data`. Responses are built from those stored strings, so a read is always byte-identical; PikaDownloader de-duplicates on full equality.
- `getReads/{id}/{since}` pages by **receive time**: `received_ms`, shared by every read in an upload batch.
  - It only returns rows older than `settle_ms`.
  - A truncated page never splits a millisecond: `trimPage` drops the trailing millisecond and the cursor resumes just before it.
  - Batches are capped at 2000 and pages at 10,000. `App`'s constructor takes these limits so tests can shrink them.
- `getReads/{id}/{from}/{to}` pages by read time (`epoch_milli`) in the same way.

**Commands** (`Api/CommandsController`).
- The vocabulary is fixed in `Validator::command`: `start`, `rewind {from,to}`, `setProfile {profile}` and `forceSetProfile {profile}`.
- There is deliberately **no stop**: remote readers must not be stoppable.
- Commands expire after `command_ttl_sec`, and the reader acknowledges them with DELETE.

**Web UI** (`Web/AdminController`, `Web/View`, `src/views/*.php`). Server-rendered templates inside `layout.php`. `View` provides the helpers `$e`, `$url`, `$asset`, `$time` and `$ago`.

**Self-update** (`src/Update/`).
- `GitHubReleases` (behind the `ReleaseSource` interface) reads the latest upstream release and its `pikarelay-<ver>.zip` asset. `UpdateChecker` caches the result in the `meta` table.
- `Package` validates a release zip: everything under `pikarelay/`, safe relative paths, no symlinks, size and entry limits, and a valid `src/VERSION`.
- `Installer` runs these steps in order: lock, pre-flight writability check, backup zip, stage `.pikanew-*` files, rename them over the live files (`index.php` last), then prune obsolete files in `src/` and `assets/`.
- It never writes `config/config.php` or anything in `config/` and `data/` except the shipped `.htaccess`, `index.html` and `probe.txt` (`Installer::isProtected`).
- `problems()` lists the reasons self-update is impossible, including a `.git` folder. So the repo itself always shows the manual-update notice.
- `AppTestCase` installs a `FakeReleaseSource`, so tests never call GitHub. Point `App::setInstaller` at a temp folder (`tests/ReleaseZip`) to test real installs.

**Tests.** Tests extend `tests/AppTestCase`, which runs `App` in-process with a `FixedClock`, a temp SQLite database, and two keys:
- `reader-key` / `reader-secret`
- `client-key` / `client-secret`

`call($method, $path, $body, 'reader'|'client'|null)` sends a request as that role. Set `PIKA_TEST_DB=mysql` plus the `PIKA_TEST_MYSQL_*` variables to run the suite against MySQL.
