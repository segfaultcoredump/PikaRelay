# PikaRelay

Web Based Relay for PikaReader

Objective is to allow PikaReader systems to be able to use this web based relay to asynchronously transmit data to PikaDownloader clients.

PikaReader systems post their status and tag reads to the relay and poll it for commands. PikaDownloader clients poll the relay for reads and status and queue commands for the readers. Neither side needs a direct network path to the other.

* PikaReader source code is in ../PikaReader. Its READ and STATUS JSON shapes are what the relay stores.
* PikaDownloader source code is in ../PikaDownloader. Its relay client should behave like `LocalReader`.
* The wire contract is in [docs/API.md](docs/API.md). Install and operations are in [README.md](README.md).

## API

| Path | Who | Purpose |
|---|---|---|
| `/` | browser | Admin login, or (logged in) a table of readers with status dot, reading, profile, battery, read count and last update |
| `PUT /readerStatus/<reader id>` | reader | Store the latest STATUS JSON as-is |
| `GET /readerStatus/<reader id>` | reader, client | Latest status, plus `updated`, `lastRead` and `serverTime` |
| `GET /readerList` | client | Known readers with name, last update, reading, battery, last read |
| `POST /putReads/<reader id>` | reader | Upload a JSON array of reads (max 2000 per request) |
| `GET /getReads/<reader id>/<since>` | client | Reads the relay **received** after `<since>` (live polling) |
| `GET /getReads/<reader id>/<from>/<to>` | client | Stored reads whose **read time** is in `[from, to)` (rewind from the relay) |
| `POST /commands/<reader id>` | client | Queue a command for a reader |
| `GET /commands/<reader id>` | reader | Pending commands |
| `DELETE /commands/<reader id>/<command id>` | reader | Acknowledge a command |
| `/admin` | admin | Settings, API keys, purge reads / commands, delete readers |
| `/setPassword` | admin | Change the admin user name and password |
| `/setup` | anyone, until done | First-run setup |

Every path also works after `index.php/` (e.g. `/index.php/readerList`) for servers without URL rewriting, and the relay can live in a sub-folder.

---

## Notes
* System should be able to support php v7.4 -> 8.4 running on either apache httpd, lightHttpd, or nginx.
* This is an internet facing system. The scripts should validate all input received. If inappropriate commands or data is posted, the system should not attempt to sanitize it but should reject it with a 422 or similar error.
* Requests and posted data from PikaReader clients is in a basic json format. The keys may change over time but will never contain escape characters. Requests from PikaDownloader clients use a fixed vocabulary that is defined in advance.

In "secure mode," readers and clients will need to provide an API key / secret to post or read data. In insecure mode, all requests to post or read data are unauthenticated. Admin access always requires an admin username / password which is stored in the config file.

If a user forgets the admin password, they can delete the password hash from the config file. The system will then accept a blank password and immediately prompt to set a new user name and password.

If the system is running in secure mode, the admin page will allow the administrator to create API keys and secrets for both PikaReader systems and PikaDownloader clients.
The create function will default to displaying a suggested key/secret but the user can change it to any 4+ character string before saving.
The administrator can create multiple keys and secrets.
The system employs a basic RBAC control in that PikaReader keys can be used to post a reader status, post reads, retrieve / acknowledge commands. PikaDownloader keys/secrets can be used to read reads and post commands for a reader.

## Setup Script
Upon initial upload of the scripts to the remote server, users can go to the base URL and be presented with a
setup page. Here they can enter the admin user name and password, database connection information, and whether the system should run in "secure mode" or "insecure mode".

The setup script will validate the database connection information, create the database tables, create the configuration file, disable the setup script, and put the admin login page in its place.

---

## Implementation decisions

These were settled while building the first version.

### Platform
* **Plain PHP, no runtime libraries.** The deployed app is the repo minus the dev files listed as `export-ignore` in `.gitattributes`. Composer is used only for dev tools (PHPUnit, PHP_CodeSniffer + PHPCompatibility).
* **Databases:** SQLite (single file) or MySQL/MariaDB, chosen at setup. All SQL runs on both.
* **Single entry point:** `index.php`. `src/`, `config/` and `data/` must never be served: `.htaccess` files handle Apache, and `server-configs/` has nginx and lighttpd examples. The Admin page warns if it can download a probe file from those folders.
* **Releases:** `tools/build-release.sh` builds `build/pikarelay-<version>.zip` from git `HEAD`. `build/` is git-ignored. It stamps the version (the tag without a leading `v`) into `src/VERSION`, which `Version` reads and the Admin page shows. Publishing a GitHub release runs `.github/workflows/release.yml`, which builds the zip and attaches it to the release.
* **Self-update** (`src/Update/`): the Admin page checks the latest GitHub release (cached 12 h in the `meta` table). It can install that release's zip, an uploaded release zip, or one of the last 3 automatic backups. It never writes `config/config.php` or the data folder, and falls back to manual-update instructions when the files are not writable.

### Authentication
* **API credentials** are sent as `X-Pika-Key` and `X-Pika-Secret` headers. Secrets are stored as an HMAC-SHA256 with a per-install `pepper`, never in plain text, and are shown only once at creation.
* **Roles:** each key is either `reader` (PikaReader) or `client` (PikaDownloader). Both may read `GET /readerStatus`.
* **Admin:** a single account stored in the config file (bcrypt hash). Session cookie with CSRF tokens on every form, a 4-hour idle timeout, and login throttling (5 failures per 15 minutes per IP).
* **Password recovery:** set `'admin_hash' => ''` in the config file, log in with a blank password, and set a new user name and password.

### Reads
* **New API only.** PikaDownloader's legacy `RemoteReader` protocol (`mac`, CSV `rfid`, `status/`, `data/since/`) is deliberately not supported.
* **Validation:** `chip` (1–64 hex/decimal digits, as a string) and `epochMilli` (integer) are required; `reader`, `antenna` and `rssi` are typed if present; other keys are allowed with plain values only. A batch is all-or-nothing.
* **De-duplication:** a read is unique by (reader id, chip, epochMilli, reader, antenna). Re-uploading a read is harmless.
* **Stable output:** each read is stored as normalized JSON text and always returned byte-identically, so clients can de-duplicate overlapping pages by equality.
* **`getReads/<id>/<since>` filters on server receive time**, not read time, so late uploads (a reader that was offline, or a rewind) are still delivered. Only reads older than the *settle time* (default 2 s) are returned, pages hold at most 10,000 reads, and a page never splits a millisecond.
* **`getReads/<id>/<from>/<to>`** was added so a client can rewind from the relay by read time without involving the reader.

### Commands
* **Fixed vocabulary:** `start`, `rewind {from, to}`, `setProfile {profile}` and `forceSetProfile {profile}`. Anything else is rejected with 422.
* **No stop command.** A remote reader must not be stoppable through the relay.
* **`forceSetProfile`** tells the reader to stop reading, set the profile, and immediately restart reading. A plain `setProfile` is refused by the reader while it is reading.
* **`rewind`** asks the reader to re-upload reads in `[from, to)` through `putReads`. The range is limited to `max_rewind_days` (default 7).
* **Expiry:** commands expire after the command lifetime (default 600 s), so a reader that comes online later never runs a stale command. The reader acknowledges a command with DELETE once it has acted on it; the relay does not track success.
* Commands can only be queued for a reader that has already contacted the relay.

### Admin page
* Turn secure mode on or off; set command lifetime, maximum rewind range and read settle time.
* Create and delete API keys.
* Delete a reader, or readers not heard from in N days.
* Purge reads older than N days; remove acknowledged or expired commands.

### Configuration
* The config is a PHP file returning an array, at `config/config.php` or the path in the `PIKA_CONFIG` environment variable (so it can live outside the web root).
* Setup creates it with an exclusive create, which is what disables setup. It is written with mode `0600`.

### Testing
* PHPUnit unit and in-process API tests (SQLite by default, MySQL via environment variables).
* `tools/smoke.sh` runs an end-to-end check over HTTP with PHP's built-in server.
* `tools/lint.sh` runs `php -l` and PHPCompatibility for PHP 7.4–8.4.

### Known limitations
* Until setup is completed, anyone who reaches the URL can complete it, so run setup right after uploading.
* On some hosts the FTP account cannot edit a `0600` config file for password recovery; the host must help, or the config can be deleted and setup run again (data is kept, but API secrets must be reissued because the pepper changes).
* Not yet tested against a real MySQL server, a real PHP 7.4 runtime, or real Apache/nginx/lighttpd installs.
