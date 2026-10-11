<?php
declare(strict_types=1);

/**
 * Administrative CLI.
 *
 * Usage:
 *   php bin/console.php migrate
 *   php bin/console.php user:create <username> [role] [--email=…]
 *   php bin/console.php user:list
 *   php bin/console.php preload:sheets          # import every readable sheet
 *   php bin/console.php preload:links            # refresh the link registry
 *   php bin/console.php reconcile                # recompute + persist statuses
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Core\Config;
use App\Core\Migrator;
use App\Domain\AuthService;
use App\Domain\GoogleSheetsClient;
use App\Domain\ImportService;
use App\Domain\QualityScanner;
use App\Domain\Reconciler;
use App\Domain\Repository;
use App\Domain\Seed;
use App\Http\App;

$argv   = $_SERVER['argv'];
$args   = array_slice($argv, 1);
$cmd    = $args[0] ?? 'help';
$opts   = [];
$pos    = [];
foreach (array_slice($args, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $parts = explode('=', substr($a, 2), 2);
        $opts[$parts[0]] = $parts[1] ?? true;
    } else {
        $pos[] = $a;
    }
}

$db = App::db();
$out = static function (string $line = ''): void { fwrite(STDOUT, $line . PHP_EOL); };

switch ($cmd) {

    case 'migrate':
        foreach ((new Migrator($db, App::config()))->migrate() as $line) {
            $out('  ' . $line);
        }
        $out('✓ Migration complete.');
        break;

    case 'user:create':
        $username = $pos[0] ?? null;
        $role     = $pos[1] ?? 'admin';
        $email    = isset($opts['email']) && is_string($opts['email']) ? $opts['email'] : null;

        if (!$username) { $out('Usage: user:create <username> [admin|analyst|viewer] [--email=…]'); exit(1); }
        if (!in_array($role, ['admin', 'analyst', 'viewer'], true)) { $out('Invalid role. Use admin, analyst, or viewer.'); exit(1); }

        if (!$isTty = true) { /* noop */ }
        $password = (string) ($opts['password'] ?? '');
        if ($password === '') {
            $password = (string) readline("Password for {$username}: ");
            if ($password === '') { $out('Password cannot be empty.'); exit(1); }
            $confirm = (string) readline("Confirm password: ");
            if ($password !== $confirm) { $out('Passwords do not match.'); exit(1); }
        }

        $problem = AuthService::passwordProblem($password, (int) App::config()->get('security.password_min', 10));
        if ($problem !== null) { $out($problem); exit(1); }

        $existing = $db->fetchValue('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing !== null) { $out("User '{$username}' already exists."); exit(1); }

        $auth = new AuthService($db);
        $id = $auth->createUser($username, $password, $role, $email);
        $out("✓ Created user #{$id}: {$username} ({$role})");
        break;

    case 'user:list':
        $rows = $db->fetchAll('SELECT id, username, role, active, last_login_at FROM users ORDER BY id');
        if (!$rows) { $out('No users yet. Create one with: user:create <username>'); break; }
        $out(sprintf('%-4s %-22s %-9s %-7s %s', 'ID', 'USERNAME', 'ROLE', 'ACTIVE', 'LAST LOGIN'));
        foreach ($rows as $r) {
            $out(sprintf('%-4d %-22s %-9s %-7s %s', $r['id'], $r['username'], $r['role'], $r['active'] ? 'yes' : 'no', $r['last_login_at'] ?? 'never'));
        }
        break;

    case 'preload:sheets':
        $token = (string) App::config()->get('google.access_token', '');
        $client = new GoogleSheetsClient($token !== '' ? $token : null);
        $repo = new Repository($db);
        $svc  = new ImportService($db, $repo);

        $out('Reading registered spreadsheets…');
        $anyFailed = false;
        foreach ($repo->sources() as $src) {
            if (empty($src['source_url']) || !str_contains((string) $src['source_url'], 'docs.google.com')) {
                continue;
            }
            $parsed = GoogleSheetsClient::parseUrl((string) $src['source_url']);
            if (!$parsed) { continue; }

            $fetched = $client->fetchCsv($parsed['id'], $src['sheet_gid'] ?? $parsed['gid']);
            if (!$fetched['ok']) {
                $anyFailed = true;
                $repo->touchSource((int) $src['id'], 'private');
                $out(sprintf('  ✗ %-32s %s', $src['name'], $fetched['error']));
                continue;
            }
            $result = $svc->importCsv($fetched['csv'], (string) $src['name'], (int) $src['id']);
            if (!$result['ok']) {
                $anyFailed = true;
                $out(sprintf('  ✗ %-32s %s', $src['name'], $result['error']));
                continue;
            }
            $repo->touchSource((int) $src['id'], 'connected');
            $repo->audit('cli', 'Sheet preload', $src['name'] . ' — ' . $result['inserted'] . ' rows');
            $out(sprintf('  ✓ %-32s %d rows imported', $src['name'], $result['inserted']));
        }
        $out($anyFailed
            ? 'Finished with errors — private sheets need a service-account token (google.access_token).'
            : 'Finished.');
        break;

    case 'preload:links':
        $db->run('DELETE FROM source_links');
        Seed::sourceLinks($db);
        $out('✓ Link registry refreshed (' . count(Seed::SOURCE_LINKS) . ' entries).');
        break;

    case 'reconcile':
        $repo = new Repository($db);
        $recs = $repo->recordsForReconciliation();
        $rec  = Reconciler::run($recs, (int) App::config()->get('app.target_percent', 98));
        $repo->applyMatchStatuses($rec['statuses']);
        $q = QualityScanner::scan($recs, $repo->sources(), (int) App::config()->get('app.stale_minutes', 60));
        $out('Reconciliation');
        foreach ($rec['kpis'] as $k => $v) {
            $out(sprintf('  %-22s %s', $k, is_bool($v) ? ($v ? 'yes' : 'no') : $v));
        }
        $out('Quality: ' . ($q['score'] === null ? 'n/a' : $q['score'] . '%') . ' — ' . count($q['issues']) . ' issue categories');
        break;

    case 'stats':
        $repo = new Repository($db);
        $out('Records: ' . $repo->recordCount());
        $out('Batches: ' . $repo->batchCount());
        $out('Sources: ' . count($repo->sources()));
        $out('Users:   ' . (int) $db->fetchValue('SELECT COUNT(*) FROM users'));
        break;

    case 'help':
    default:
        $out('Amaya Log Intelligence — console');
        $out('');
        $out('  migrate                  Create/refresh tables and seed baseline data');
        $out('  user:create <user> [role] Create an account (role: admin|analyst|viewer)');
        $out('  user:list                List accounts');
        $out('  preload:sheets           Import every readable Google Sheets source');
        $out('  preload:links            Refresh the system link registry');
        $out('  reconcile                Recompute and persist match statuses');
        $out('  stats                    Show row counts');
        break;
}