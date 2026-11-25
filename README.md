# PikaRelay

PikaRelay is a small PHP web relay for timing systems. PikaReader units on the internet post their status and tag reads to it, and PikaDownloader clients anywhere else collect the reads and send commands back to the readers (start, rewind, change profile). Nothing needs a direct network path to the reader.

- Plain PHP 7.4–8.4 with no libraries to install. It runs on shared hosting under Apache, nginx or lighttpd.
- Uses SQLite (a single file) or MySQL/MariaDB.
- **Secure mode** requires an API key and secret from every reader and client. **Insecure mode** is open to anyone who knows the URL.
- The admin web page shows reader health and manages API keys and old data.

The API is documented in [docs/API.md](docs/API.md).

## Requirements

- PHP 7.4 or newer, 64-bit, with PDO and either `pdo_sqlite` or `pdo_mysql`.
- A web server that runs PHP. HTTPS is strongly recommended: API secrets and the admin password are sent with every request.

## Installing

1. **Upload the files.** Download a release zip (attached to each GitHub release), or build one with `tools/build-release.sh`, and upload the `pikarelay` folder to your web host. It can be the site root or a sub-folder such as `/relay`.
2. **Make two folders writable by PHP:**
   - `config/`, where setup writes `config/config.php`
   - `data/`, but only if you keep the SQLite database there

   Use a folder **outside** the web root for the SQLite file if your host allows it.
3. **Block private folders from the web.** The relay must not serve `config/`, `data/` or `src/`.
   - **Apache:** the included `.htaccess` files do this, as long as `AllowOverride` is enabled.
   - **nginx** and **lighttpd** ignore `.htaccess`. Add the rules from `server-configs/`.

   The Admin page shows a warning if it can still download a probe file from these folders.
4. **Run setup right away.** Open the base URL in a browser. You get the setup page, which asks for:
   - the admin user name and password
   - the database
   - secure or insecure mode

   Until setup is done, **anyone** who finds the URL can complete it.

   Setup checks the database connection, creates the tables, writes the config file and then disables itself.
5. **Create API keys.** In secure mode, log in, open **Admin** and create:
   - a **reader** key for each PikaReader
   - a **client** key for each PikaDownloader

   The secret is shown only once.

### Pretty URLs vs `index.php/`

- **With URL rewriting** (the `.htaccess` file or the server-config examples), the API lives at paths like `https://relay.example.com/readerList`.
- **Without rewriting**, every path also works after `index.php`, e.g. `https://relay.example.com/index.php/readerList`.

### Keeping the config outside the web root

PikaRelay reads its config from the path in the `PIKA_CONFIG` environment variable when that variable is set; otherwise it uses `config/config.php`. On Apache you can set it with `SetEnv PIKA_CONFIG /home/you/pikarelay-config.php`, as long as PHP can write to that folder during setup.

## Admin

- **Readers:** each reader's last status, as a green, yellow or red dot like PikaDownloader's, plus whether it is reading, its profile, battery, read count and newest read.
- **Admin:**
  - turn secure mode on or off
  - command lifetime, longest rewind range, and read settle time
  - create and delete API keys
  - delete a reader or readers not heard from in N days
  - purge reads older than N days, and remove acknowledged or expired commands
- **Password:** change the admin user name and password.

### Updating

The **Software update** card on the Admin page shows the installed version and checks [GitHub](https://github.com/PikaTimer/PikaRelay/releases) for a newer release. It checks at most every 12 hours; use **Check for updates** to check now. From the card you can:

- **Update to x.y.z:** download the release's `pikarelay-x.y.z.zip` from GitHub and install it. GitHub's SHA-256 checksum is verified when GitHub provides one.
- **Upload and install:** install a `pikarelay-x.y.z.zip` you downloaded yourself. Use the zip attached to the release, not GitHub's "Source code" zip.
- **Restore:** put back the code from one of the last 3 automatic backups.

Every update first backs up the current code to `data/backups/`, or to `backups/` in the config folder if `data/` is not writable. `config/config.php` and your data are never touched.

Self-update needs PHP to be able to write to the install folder, `src/` and `assets/`, and needs PHP's zip extension. Checking GitHub also needs the curl extension, or `allow_url_fopen` with openssl. When the host does not allow this, the card says so. In that case, update manually: download the release zip, then upload the contents of its `pikarelay` folder over the installation, keeping `config/config.php` and `data/`.

### Forgotten admin password

1. Edit `config/config.php` and set `'admin_hash' => ''`.
2. Log in with any user name and a **blank** password.
3. You are sent straight to the page for setting a new user name and password.

The config file is created with mode `0600`. If your FTP account cannot edit it, ask your host, or delete the file and run setup again. Running setup again keeps existing data when you point it at the same database. API keys survive too, but their secrets stop working: they are hashed with a per-install `pepper` that a new setup replaces.

## Development

Development tools are not needed on the server. PHPUnit and the PHP 7.4 compatibility checks are Composer dev dependencies.

```sh
composer install            # PHPUnit, PHP_CodeSniffer, PHPCompatibility
vendor/bin/phpunit          # unit + in-process API tests (SQLite in a temp dir)
vendor/bin/phpunit --filter testCommandLifecycle
tools/lint.sh               # php -l on every file + PHPCompatibility for PHP 7.4-8.4
tools/smoke.sh              # end-to-end over HTTP with PHP's built-in server
php -S 127.0.0.1:8080 index.php   # run locally (setup writes config/config.php)
tools/build-release.sh      # build/pikarelay-<version>.zip from HEAD (version = tag, minus a leading v)
```

Publishing a GitHub release runs `.github/workflows/release.yml`, which builds the zip from the release tag and attaches it to the release. The zip carries the version in `src/VERSION`, and the Admin page shows it at the bottom (`dev` when running from a git checkout).

To run the PHPUnit suite against MySQL or MariaDB instead of SQLite, set these environment variables. **Every table in that database is dropped.**

- `PIKA_TEST_DB=mysql`
- `PIKA_TEST_MYSQL_HOST`, `PIKA_TEST_MYSQL_PORT`, `PIKA_TEST_MYSQL_NAME`, `PIKA_TEST_MYSQL_USER`, `PIKA_TEST_MYSQL_PASSWORD`

## License

Copyright (C) 2026 John Garner

PikaRelay is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. It is distributed WITHOUT ANY WARRANTY. See [LICENSE](LICENSE) for the full text.
