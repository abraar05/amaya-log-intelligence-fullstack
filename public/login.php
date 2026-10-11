<?php
declare(strict_types=1);

/**
 * Sign in / sign out.
 */

$config = require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Domain\AuthService;
use App\Http\App;
use App\Http\Response;
use App\Http\Session;

Session::start($config);

$base = Session::baseUrl($config);

if (Session::user(App::db()) !== null) {
    Response::redirect($base . '/index.php');
}

$error   = null;
$notice  = Session::flash();
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db   = App::db();
    $auth = new AuthService(
        $db,
        (int) $config->get('security.max_login_attempts', 5),
        (int) $config->get('security.lockout_seconds', 900)
    );

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // CSRF: token was minted at render time in the same session.
    $token = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $token) || $token === '') {
        $error = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter both a username and a password.';
    } else {
        $result = $auth->attempt($username, $password, App::clientIp($config));
        if ($result['ok']) {
            Session::login((int) $result['user']['id']);
            Response::redirect($base . '/index.php');
        }
        $error = (string) $result['error'];
    }
}

$_SESSION['csrf'] = bin2hex(random_bytes(32));
$base = Session::baseUrl($config);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — Amaya Log Intelligence</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
background:linear-gradient(160deg,#f6f8fb 0%,#eef1ff 100%);color:#172334;
font-family:'DM Sans',system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
.card{width:100%;max-width:400px;background:#fff;border:1px solid #e8edf3;border-radius:18px;
padding:34px 30px;box-shadow:0 10px 40px rgba(26,45,75,.07)}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:26px}
.mark{width:42px;height:42px;border-radius:12px;background:#1e2c51;color:#fff;display:grid;place-items:center;
font:800 15px 'Manrope',sans-serif;letter-spacing:.5px;flex-shrink:0}
.brand b{display:block;font:800 15px 'Manrope',sans-serif;letter-spacing:1.6px}
.brand small{display:block;color:#8590a4;font-size:9px;font-weight:700;letter-spacing:1.5px;margin-top:2px}
h1{font-size:21px;margin:0 0 6px}
.sub{color:#748195;font-size:13px;margin:0 0 24px}
label{display:block;font-size:12px;font-weight:600;color:#45536b;margin-bottom:6px}
.field{margin-bottom:16px}
input[type=text],input[type=password]{width:100%;padding:11px 13px;border:1px solid #dfe5ee;border-radius:10px;
font-size:14px;font-family:inherit;background:#fbfcfe;transition:border-color .15s,box-shadow .15s}
input:focus{outline:0;border-color:#3458d4;box-shadow:0 0 0 3px rgba(52,88,212,.12);background:#fff}
button{width:100%;padding:12px;border:0;border-radius:10px;background:#3458d4;color:#fff;
font:600 14px 'DM Sans',sans-serif;cursor:pointer;transition:background .15s}
button:hover{background:#2c49b8}
button:active{transform:translateY(1px)}
.alert{padding:11px 13px;border-radius:10px;font-size:13px;margin-bottom:18px}
.alert.err{background:#fdecec;color:#9b2226;border:1px solid #f5c6c6}
.alert.ok{background:#e7f6f0;color:#0f6b55;border:1px solid #bfe6d7}
.note{margin-top:20px;padding-top:18px;border-top:1px solid #eef1f6;color:#8590a4;font-size:11.5px;line-height:1.6}
.note code{background:#f2f5fa;padding:1px 5px;border-radius:4px;font-size:11px}
</style>
</head>
<body>
<main class="card">
  <div class="brand"><div class="mark">A</div><span><b>AMAYA</b><small>LOG INTELLIGENCE</small></span></div>
  <h1>Sign in</h1>
  <p class="sub">Operational reporting workspace</p>

  <?php if ($error): ?><div class="alert err" role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="alert ok"><?= htmlspecialchars($notice, ENT_QUOTES) ?></div><?php endif; ?>

  <form method="post" autocomplete="on" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
    <div class="field">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>"
             autocomplete="username" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button type="submit">Sign in</button>
  </form>

  <div class="note">
    Accounts are created by an administrator with
    <code>php bin/console.php user:create</code>. Sessions expire after
    <?= (int) $config->get('security.session_lifetime', 7200) / 60 ?> minutes of inactivity.
  </div>
</main>
</body>
</html>