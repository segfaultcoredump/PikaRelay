<?php
/*
 * Copyright (C) 2026 John Garner
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * @var int      $now
 * @var bool     $secure
 * @var bool     $https
 * @var array    $settings
 * @var array    $keys
 * @var array    $readers
 * @var int      $readCount
 * @var int      $pendingCommands
 * @var int      $oldCommands
 * @var string   $suggestedKey
 * @var string   $suggestedSecret
 * @var string   $version
 * @var array    $update   see AdminController::updateInfo()
 * @var string   $csrf
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var callable $time
 * @var callable $ago
 */
?>
<h1>Admin</h1>

<?php if ($secure && !$https): ?>
<p class="flash flash-error">This page was loaded over plain HTTP. In secure mode, API secrets and your admin password cross the network unencrypted. Use HTTPS.</p>
<?php endif; ?>
<p id="exposure-warning" class="flash flash-error" hidden
   data-probes="<?= $asset('config/probe.txt') ?> <?= $asset('data/probe.txt') ?>">
  Warning: the web server serves files from the config or data folder, so the database or config file may be downloadable.
  Apply the server rules in <code>server-configs/</code> (Apache uses the included <code>.htaccess</code> files).
</p>

<section class="card">
  <h2>Settings</h2>
  <form method="post" action="<?= $url('/admin/settings') ?>" class="grid-form">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label class="check">
      <input type="checkbox" name="secure_mode" value="1" <?= $secure ? 'checked' : '' ?>>
      Secure mode: readers and clients need an API key and secret
    </label>
    <label>Command lifetime (seconds)
      <input type="number" name="command_ttl_sec" min="10" max="86400" value="<?= $e($settings['command_ttl_sec']) ?>" required>
    </label>
    <label>Longest rewind range (days)
      <input type="number" name="max_rewind_days" min="1" max="365" value="<?= $e($settings['max_rewind_days']) ?>" required>
    </label>
    <label>Read settle time (ms)
      <input type="number" name="settle_ms" min="0" max="60000" value="<?= $e($settings['settle_ms']) ?>" required>
    </label>
    <div><button type="submit">Save settings</button></div>
  </form>
</section>

<section class="card">
  <h2>API keys</h2>
  <?php if (!$secure): ?>
  <p class="notice">Insecure mode is on, so keys are not checked. You can still create keys now and switch to secure mode later.</p>
  <?php endif; ?>
  <p class="muted">Reader keys let a PikaReader post status and reads and fetch and acknowledge commands. Client keys let PikaDownloader read status and reads and post commands.</p>
  <?php if ($keys): ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Label</th><th>Role</th><th>Key</th><th>Created</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
      <tr>
        <td><?= $k['label'] === '' ? '<span class="muted">-</span>' : $e($k['label']) ?></td>
        <td><?= $e($k['role']) ?></td>
        <td><code><?= $e($k['key']) ?></code></td>
        <td><?= $time($k['createdMs']) ?></td>
        <td><?= $k['lastUsedMs'] === null ? '<span class="muted">never</span>' : $e($ago($k['lastUsedMs'])) ?></td>
        <td>
          <form method="post" action="<?= $url('/admin/deleteKey') ?>" class="inline" data-confirm="Delete API key <?= $e($k['key']) ?>? Devices using it will stop working.">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="id" value="<?= $e($k['id']) ?>">
            <button type="submit" class="danger small">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <h3>Create a key</h3>
  <form method="post" action="<?= $url('/admin/createKey') ?>" class="grid-form">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Label
      <input type="text" name="label" maxlength="100" placeholder="e.g. Finish line reader">
    </label>
    <label>Role
      <select name="role" required>
        <option value="reader">Reader (PikaReader)</option>
        <option value="client">Client (PikaDownloader)</option>
      </select>
    </label>
    <label>Key
      <input type="text" name="api_key" value="<?= $e($suggestedKey) ?>" minlength="4" maxlength="128" pattern="[!-~]{4,128}" spellcheck="false" required>
    </label>
    <label>Secret
      <input type="text" name="api_secret" value="<?= $e($suggestedSecret) ?>" minlength="4" maxlength="128" pattern="[!-~]{4,128}" spellcheck="false" required>
    </label>
    <div><button type="submit">Create key</button></div>
  </form>
  <p class="muted small">The suggested key and secret are random. You can replace them with any 4-128 printable characters without spaces.</p>
</section>

<section class="card">
  <h2>Readers</h2>
  <?php if (!$readers): ?>
  <p class="muted">No readers yet.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Reader</th><th>First seen</th><th>Last status</th><th>Newest read</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($readers as $r): ?>
      <tr>
        <td><strong><?= $e($r['id']) ?></strong></td>
        <td><?= $time($r['firstSeenMs']) ?></td>
        <td><?= $e($ago($r['statusMs'])) ?></td>
        <td><?= $time($r['lastReadMs']) ?></td>
        <td>
          <form method="post" action="<?= $url('/admin/deleteReader') ?>" class="inline" data-confirm="Delete reader <?= $e($r['id']) ?> and all of its reads and commands?">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="id" value="<?= $e($r['id']) ?>">
            <button type="submit" class="danger small">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <form method="post" action="<?= $url('/admin/deleteStaleReaders') ?>" class="row-form" data-confirm="Delete every reader not heard from in this many days, with their reads and commands?">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Delete readers not heard from in
      <input type="number" name="days" min="1" max="3650" value="30" required> days
    </label>
    <button type="submit" class="danger">Delete old readers</button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Maintenance</h2>
  <p><?= $e(number_format($readCount)) ?> reads stored. <?= $e($pendingCommands) ?> pending commands, <?= $e($oldCommands) ?> acknowledged or expired.</p>
  <form method="post" action="<?= $url('/admin/purgeReads') ?>" class="row-form" data-confirm="Delete reads received more than this many days ago? This cannot be undone.">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label>Delete reads received more than
      <input type="number" name="days" min="0" max="3650" value="30" required> days ago
    </label>
    <button type="submit" class="danger">Delete old reads</button>
  </form>
  <form method="post" action="<?= $url('/admin/purgeCommands') ?>" class="row-form">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <span>Remove acknowledged and expired commands</span>
    <button type="submit">Remove old commands</button>
  </form>
</section>

<section class="card" id="software-update">
  <h2>Software update</h2>
  <p>
    Installed version: <strong><?= $e($update['current']) ?></strong>.
    <?php if ($update['release'] !== null): ?>
      Latest release: <strong><?= $e($update['release']->version) ?></strong>
      (<a href="<?= $e($update['release']->htmlUrl) ?>" rel="noopener noreferrer" target="_blank">release notes</a>)<?php if ($update['checkedMs'] !== null): ?>, checked <?= $e($ago($update['checkedMs'])) ?><?php endif; ?>.
    <?php elseif ($update['checkedMs'] !== null): ?>
      <span class="muted">Last check (<?= $e($ago($update['checkedMs'])) ?>): <?= $e($update['error']) ?></span>
    <?php endif; ?>
  </p>
  <?php if ($update['httpProblem'] !== null): ?>
  <p class="muted"><?= $e($update['httpProblem']) ?> Updates can still be installed by uploading a release zip.</p>
  <?php else: ?>
  <form method="post" action="<?= $url('/admin/checkUpdate') ?>" class="row-form">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit">Check for updates</button>
  </form>
  <?php endif; ?>

  <?php if ($update['problems']): ?>
  <div class="notice">
    <p><strong>This server cannot update PikaRelay automatically.</strong></p>
    <ul>
      <?php foreach ($update['problems'] as $problem): ?>
      <li><?= $e($problem) ?></li>
      <?php endforeach; ?>
    </ul>
    <p>To update, you will need to update the PHP scripts manually: download the <code>pikarelay-x.y.z.zip</code> file from the
      <a href="<?= $e($update['releasesUrl']) ?>" rel="noopener noreferrer" target="_blank">releases page</a>
      and upload the contents of its <code>pikarelay</code> folder over this installation. Keep your <code>config/config.php</code> and <code>data/</code> folder.</p>
  </div>
  <?php else: ?>
    <?php if ($update['newer']): ?>
    <form method="post" action="<?= $url('/admin/installUpdate') ?>" class="row-form"
          data-confirm="Download PikaRelay <?= $e($update['release']->version) ?> from GitHub and install it? The current version is backed up first.">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($update['release']->version) ?>">
      <span>Version <?= $e($update['release']->version) ?> is available.</span>
      <button type="submit">Update to <?= $e($update['release']->version) ?></button>
    </form>
    <?php endif; ?>
    <h3>Install from a file</h3>
    <form method="post" action="<?= $url('/admin/uploadUpdate') ?>" enctype="multipart/form-data" class="row-form"
          data-confirm="Install the uploaded release over this installation? The current version is backed up first.">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label>Release zip (pikarelay-x.y.z.zip, at most <?= $e($update['maxUpload']) ?>)
        <input type="file" name="package" accept=".zip,application/zip" required>
      </label>
      <button type="submit">Upload and install</button>
    </form>
    <?php if ($update['backups']): ?>
    <h3>Backups</h3>
    <p class="muted">Taken automatically before each update. The newest <?= $e(count($update['backups'])) ?> are kept.</p>
    <ul class="backups">
      <?php foreach ($update['backups'] as $b): ?>
      <li>
        <form method="post" action="<?= $url('/admin/restoreUpdate') ?>" class="inline"
              data-confirm="Restore PikaRelay <?= $e($b['version']) ?> from this backup?">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="backup" value="<?= $e($b['file']) ?>">
          Version <strong><?= $e($b['version']) ?></strong>, backed up <?= $time($b['createdMs']) ?>
          <button type="submit" class="small">Restore</button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  <?php endif; ?>
</section>

<p class="version muted small">PikaRelay <?= $e($version) ?></p>
