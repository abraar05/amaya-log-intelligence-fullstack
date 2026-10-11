<?php
declare(strict_types=1);

/**
 * Installer. Run once from a browser or CLI, then delete.
 * Safe to re-run: every statement is idempotent.
 */

$config = require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Migrator;
use App\Domain\AuthService;
use App\Http\App;

$db  = App::db();
$log = [];
$done = false;
$error = null;

try {
    $log = (new Migrator($db, $config))->migrate();
    $done = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
    error_log('[amaya] install failed: ' . $e->getMessage());
}

$isCli = (PHP_SAPI === 'cli');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install — Amaya Log Intelligence</title>
<style>
body{margin:0;padding:32px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:#f6f8fb;color:#172334}
.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e8edf3;border-radius:16px;padding:28px}
h1{margin:0 0 6px;font-size:22px}
p{color:#657187}
pre{background:#0f172a;color:#c7d2e4;padding:16px;border-radius:10px;overflow:auto;font-size:12.5px;line-height:1.6}
.ok{color:#14856b;font-weight:600}
.bad{color:#b42318;font-weight:600}
.warn{background:#fff8e6;border:1px solid #f5d78e;border-radius:10px;padding:14px;margin-top:18px}
a.btn{display:inline-block;margin-top:16px;background:#3458d4;color:#fff;text-decoration:none;padding:10px 18px;border-radius:9px;font-weight:600;font-size:14px}
code{background:#f2f5fa;padding:2px 6px;border-radius:5px;font-size:13px}
</style></head><body><div class="card">
<h1>Amaya Log Intelligence — installer</h1>
<?php if ($error): ?>
  <p class="bad">Installation failed: <?= htmlspecialchars($error) ?></p>
  <p>Check the database credentials in <code>config.php</code> and confirm the database exists and the user has permission to create tables.</p>
<?php elseif ($done): ?>
  <p class="ok">✓ Schema installed and seeded.</p>
  <pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
  <div class="warn">
    <strong>Next steps:</strong>
    <ol style="margin:8px 0 0;padding-left:20px">
      <li>Create your first account: <code>php bin/console.php user:create &lt;username&gt;</code></li>
      <li>Delete this file: <code>rm public/install.php</code></li>
      <li>Ensure the site is served over HTTPS</li>
      <li>Sign in at <code>/login.php</code></li>
    </ol>
  </div>
  <a class="btn" href="login.php">Go to sign in</a>
<?php else: ?>
  <p>Installer is running…</p>
<?php endif; ?>
</div></body></html>