<?php
declare(strict_types=1);

/**
 * JSON API. All mutating requests require a session, a CSRF token, and
 * (for destructive actions) an admin/analyst role.
 */

$config = require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Domain\AuthService;
use App\Domain\GoogleSheetsClient;
use App\Domain\ImportService;
use App\Domain\QualityScanner;
use App\Domain\Reconciler;
use App\Domain\Repository;
use App\Domain\Seed;
use App\Http\App;
use App\Http\Response;
use App\Http\Session;

Session::start($config);
$user = Session::requireLogin($config);
$db   = App::db();
$repo = new Repository($db);

$action = (string) ($_GET['action'] ?? '');

// ---------- CSRF for mutating verbs ----------
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $sent  = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    $known = (string) ($_SESSION['csrf'] ?? '');
    if ($known === '' || !hash_equals($known, $sent)) {
        Response::error('Invalid or expired CSRF token. Reload the page.', 419);
    }
}

$role       = (string) $user['role'];
$isAdmin    = $role === 'admin';
$canWrite   = $isAdmin || $role === 'analyst';
$targetPct  = (int) $config->get('app.target_percent', 98);

try {
    switch ($action) {

        // ---------- reads ----------
        case 'session':
            Response::json([
                'ok'      => true,
                'user'    => [
                    'id'       => (int) $user['id'],
                    'username' => $user['username'],
                    'role'     => $role,
                ],
                'csrf'    => $_SESSION['csrf'] ?? '',
                'app'     => (string) $config->get('app.name', 'Amaya Log Intelligence'),
            ]);
            break;

        case 'overview':
            $recs  = $repo->recordsForReconciliation();
            $rec   = Reconciler::run($recs, $targetPct);
            $qual  = QualityScanner::scan($recs, $repo->sources(), (int) $config->get('app.stale_minutes', 60));
            Response::json([
                'ok'            => true,
                'records'       => count($recs),
                'reconciliation'=> $rec['kpis'],
                'quality'       => $qual,
                'sources'       => count($repo->sources()),
                'departments'   => count($repo->departments()),
                'activity'      => $repo->auditLog(12),
            ]);
            break;

        case 'sources':
            Response::json(['ok' => true, 'sources' => $repo->sources()]);
            break;

        case 'records':
            $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 200)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $rows = $db->fetchAll(
                'SELECT r.id, r.imei, r.device_model, r.invoice_number, r.department,
                        r.record_date, r.match_status, r.source_id, ds.name AS source_name
                 FROM records r LEFT JOIN data_sources ds ON ds.id = r.source_id
                 ORDER BY r.id DESC LIMIT ? OFFSET ?',
                [$limit, $offset]
            );
            Response::json(['ok' => true, 'records' => $rows, 'total' => $repo->recordCount()]);
            break;

        case 'reconciliation':
            $recs = $repo->recordsForReconciliation();
            $rec  = Reconciler::run($recs, $targetPct);
            Response::json([
                'ok'            => true,
                'kpis'          => $rec['kpis'],
                'duplicates'    => array_slice($rec['duplicates'], 0, 100),
                'unmatched'     => array_slice($rec['unmatched'], 0, 100),
            ]);
            break;

        case 'quality':
            $recs = $repo->recordsForReconciliation();
            $qual = QualityScanner::scan($recs, $repo->sources(), (int) $config->get('app.stale_minutes', 60));
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
                $repo->audit($user['username'], 'Quality scan',
                    $qual['score'] === null ? 'no records' : $qual['score'] . '% / ' . count($qual['issues']) . ' issues',
                    $qual['issues'] ? 'Review' : 'Success');
            }
            Response::json(['ok' => true, 'quality' => $qual]);
            break;

        case 'links':
            Response::json(['ok' => true, 'links' => $db->fetchAll('SELECT * FROM source_links ORDER BY sort_order')]);
            break;

        case 'activity':
            Response::json(['ok' => true, 'activity' => $repo->auditLog((int) ($_GET['limit'] ?? 100))]);
            break;

        // ---------- writes ----------
        case 'source.create':
            if (!$canWrite) { Response::error('Insufficient permissions', 403); }
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') { Response::error('Source name is required', 422); }
            $deptId = $repo->departmentIdByName((string) ($_POST['department'] ?? ''));
            $id = $repo->createSource([
                'name'          => $name,
                'department_id' => $deptId,
                'source_type'   => (string) ($_POST['source_type'] ?? 'Manual CSV upload'),
                'source_url'    => trim((string) ($_POST['source_url'] ?? '')) ?: null,
                'sheet_gid'     => trim((string) ($_POST['sheet_gid'] ?? '')) ?: null,
                'last_status'   => 'pending',
            ]);
            $repo->audit($user['username'], 'Source registered', $name);
            Response::json(['ok' => true, 'id' => $id], 201);
            break;

        case 'source.delete':
            if (!$isAdmin) { Response::error('Administrator role required', 403); }
            $id = (int) ($_POST['id'] ?? 0);
            $src = $repo->source($id);
            if (!$src) { Response::error('Source not found', 404); }
            $repo->deleteSource($id);
            $repo->audit($user['username'], 'Source removed', (string) $src['name']);
            Response::json(['ok' => true]);
            break;

        case 'import.csv':
            if (!$canWrite) { Response::error('Insufficient permissions', 403); }
            if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                Response::error('No file was uploaded', 422);
            }
            $tmp  = (string) $_FILES['file']['tmp_name'];
            $name = (string) $_FILES['file']['name'];
            $size = (int) $_FILES['file']['size'];
            $max  = (int) ($config->get('app.max_upload_bytes', 8 * 1024 * 1024));
            if ($size > $max) {
                Response::error('File exceeds the ' . round($max / 1048576, 1) . ' MB upload limit', 413);
            }
            $contents = file_get_contents($tmp);
            if ($contents === false) { Response::error('Could not read the uploaded file', 500); }

            $sourceId = isset($_POST['source_id']) ? (int) $_POST['source_id'] : null;
            $svc = new ImportService($db, $repo);
            $result = $svc->importCsv($contents, $name, $sourceId, $targetPct);
            if (!$result['ok']) {
                $repo->audit($user['username'], 'CSV import failed', $name . ': ' . $result['error'], 'Warning');
                Response::error($result['error'], 422, ['headers' => $result['headers'] ?? null]);
            }
            $repo->audit($user['username'], 'CSV import',
                $name . ' — ' . $result['inserted'] . ' inserted, ' . $result['skipped'] . ' skipped');
            Response::json(['ok' => true] + $result);
            break;

        case 'import.sheet':
            if (!$canWrite) { Response::error('Insufficient permissions', 403); }
            $sid = (int) ($_POST['source_id'] ?? 0);
            $src = $repo->source($sid);
            if (!$src) { Response::error('Source not found', 404); }

            $token = (string) $config->get('google.access_token', '');
            $client = new GoogleSheetsClient($token !== '' ? $token : null);
            $parsed = GoogleSheetsClient::parseUrl((string) $src['source_url']);
            if ($parsed === null) { Response::error('This source has no valid Google Sheets URL', 422); }

            $fetched = $client->fetchCsv($parsed['id'], $src['sheet_gid'] ?? $parsed['gid']);
            if (!$fetched['ok']) {
                $repo->touchSource($sid, 'private');
                $repo->audit($user['username'], 'Sheet sync failed', $src['name'] . ': ' . $fetched['error'], 'Warning');
                Response::error($fetched['error'], 502);
            }

            $svc = new ImportService($db, $repo);
            $result = $svc->importCsv($fetched['csv'], $src['name'], $sid, $targetPct);
            if (!$result['ok']) {
                Response::error($result['error'], 422);
            }
            $repo->touchSource($sid, 'connected');
            $repo->audit($user['username'], 'Sheet sync', $src['name'] . ' — ' . $result['inserted'] . ' rows');
            Response::json(['ok' => true] + $result);
            break;

        case 'settings.update':
            if (!$isAdmin) { Response::error('Administrator role required', 403); }
            // Settings live in config.php on disk; this endpoint only records intent.
            $repo->audit($user['username'], 'Settings updated', json_encode($_POST) ?: '');
            Response::json(['ok' => true]);
            break;

        default:
            Response::error('Unknown action', 404);
    }
} catch (Throwable $e) {
    error_log('[amaya] api error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($config->isDebug()) {
        Response::error($e->getMessage(), 500);
    }
    Response::error('Unexpected server error', 500);
}