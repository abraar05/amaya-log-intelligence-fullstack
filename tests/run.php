<?php
declare(strict_types=1);

/**
 * Tiny test harness (no Composer / PHPUnit available in this environment).
 * Usage: php83 tests/run.php
 */

require __DIR__ . '/../app/Core/Autoloader.php';
App\Core\Autoloader::register(__DIR__ . '/../app');

use App\Core\Config;
use App\Core\Database;
use App\Domain\AuthService;
use App\Domain\CsvReader;
use App\Domain\GoogleSheetsClient;
use App\Domain\HeaderMapper;
use App\Domain\Imei;
use App\Domain\QualityScanner;
use App\Domain\Reconciler;

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public static function ok(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            self::$pass++;
            echo "  \033[32m✓\033[0m {$label}\n";
        } else {
            self::$fail++;
            self::$failures[] = self::$group . ' → ' . $label . ($detail ? " ({$detail})" : '');
            echo "  \033[31m✗ {$label}\033[0m" . ($detail ? "  \033[33m{$detail}\033[0m" : '') . "\n";
        }
    }

    public static function eq($expected, $actual, string $label): void
    {
        self::ok(
            $expected === $actual,
            $label,
            $expected === $actual ? '' : 'expected ' . json_encode($expected) . ', got ' . json_encode($actual)
        );
    }

    public static function summary(): int
    {
        $total = self::$pass + self::$fail;
        echo "\n" . str_repeat('─', 58) . "\n";
        if (self::$fail === 0) {
            echo "\033[32m\033[1m ALL {$total} TESTS PASSED \033[0m\n";
            return 0;
        }
        echo "\033[31m\033[1m " . self::$fail . " FAILED \033[0m of {$total}\n";
        foreach (self::$failures as $f) {
            echo "  \033[31m•\033[0m {$f}\n";
        }
        return 1;
    }
}

/**
 * Create a fully-migrated SQLite database, exactly as the installer would.
 */
function testDatabase(): Database
{
    $path = tempnam(sys_get_temp_dir(), 'amaya');
    $db = Database::fromPdo(new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
    $migrator = new \App\Core\Migrator($db, Config::load(__DIR__ . '/../public/config.php'));
    $migrator->migrate();
    $GLOBALS['__test_dbs'][] = $path;
    return $db;
}

/* ============ 1. IMEI normalization ============ */
T::group('IMEI normalization');
T::ok(Imei::normalize('  868436084232096 ') === '868436084232096', 'trims surrounding whitespace');
T::ok(Imei::normalize('868 436 084 232 096') === '868436084232096', 'strips display spaces');
T::ok(Imei::normalize('868-436-084-232-096') === '868436084232096', 'strips hyphens');
T::ok(Imei::normalize('868.436.084') === '868436084', 'strips dots');
T::ok(Imei::normalize("\u{00A0}123\u{00A0}") === '123', 'strips non-breaking space');
T::ok(Imei::normalize("\u{200B}123\u{200B}") === '123', 'strips zero-width space');
T::ok(Imei::normalize(null) === '', 'null becomes empty string');
T::ok(Imei::normalize('000123') === '000123', 'leading zeroes preserved');
T::ok(Imei::looksValid('868436084232096'), '15-digit IMEI is valid');
T::ok(Imei::looksValid('86843608423209'), '14-digit identifier passes the advisory shape check');
T::ok(Imei::looksValid('123') === false, '3-digit value is not IMEI-shaped');
T::ok(!Imei::hasReference(''), 'empty reference counts as absent');
T::ok(!Imei::hasReference('  '), 'whitespace-only reference counts as absent');
T::ok(Imei::hasReference('TR-1'), 'real reference detected');
T::eq(Imei::STATUS_DUPLICATE, Imei::classify(2, true), 'two rows => duplicate even with reference');
T::eq(Imei::STATUS_DUPLICATE, Imei::classify(3, false), 'three rows => duplicate');
T::eq(Imei::STATUS_MATCHED, Imei::classify(1, true), 'one row with reference => matched');
T::eq(Imei::STATUS_UNMATCHED, Imei::classify(1, false), 'one row without reference => unmatched');

/* ============ 2. CSV parsing ============ */
T::group('CSV parsing');
$rows = CsvReader::parse("a,b\n1,2\n");
T::eq(2, count($rows), 'simple CSV row count');
T::eq('1', $rows[1][0], 'simple CSV first cell');

$rows = CsvReader::parse("a,b\n\"x, with comma\",\"say \"\"hi\"\"\"\n");
T::eq('x, with comma', $rows[1][0], 'quoted field keeps embedded comma');
T::eq('say "hi"', $rows[1][1], 'escaped quotes decoded');

$rows = CsvReader::parse("\xEF\xBB\xBFimei,device\n123,Phone\n");
T::eq('imei', $rows[0][0], 'UTF-8 BOM stripped from first header');

$rows = CsvReader::parse("a,b\r\n1,2\r\n");
T::eq(2, count($rows), 'CRLF line endings handled');

$rows = CsvReader::parse("a,b\n1,2");
T::eq(2, count($rows), 'missing trailing newline still flushes last row');

$rows = CsvReader::parse("a,b\n\n1,2\n\n");
T::eq(2, count($rows), 'blank lines dropped');

$rows = CsvReader::parse("a,b,c\n1,2\n");
T::eq(2, count($rows[1]), 'short row has only the cells present');
T::eq('2', $rows[1][1], 'short row trailing value present');

$rows = CsvReader::parse("a;;b\n1;2;3\n", ';');
T::eq(3, count($rows[0]), 'semicolon-delimited header splits into 3');
T::eq('3', $rows[1][2], 'semicolon-delimited row value');

T::eq("\t", CsvReader::detectDelimiter("a\tb\tc"), 'tab delimiter detection');
T::eq('|', CsvReader::detectDelimiter("a|b|c|d"), 'pipe delimiter detection');
T::eq(',', CsvReader::detectDelimiter("single"), 'default delimiter when none found');

/* ============ 3. Header mapping ============ */
T::group('Header mapping');
$m = HeaderMapper::map(['IMEI', 'Device', 'Invoice']);
T::ok(isset($m['imei'], $m['device_model'], $m['invoice_number']), 'exact header match');

$m = HeaderMapper::map(['Serial Number', 'Device Name', 'Invoice No']);
T::ok(isset($m['imei'], $m['device_model'], $m['invoice_number']), 'multi-word aliases match');

$m = HeaderMapper::map(['  IMEI  ', 'imei_no', 'Device']);
T::ok(isset($m['imei']), 'case and padding tolerant');

$m = HeaderMapper::map(['Date', 'Qty', 'Warehouse', 'Remarks']);
T::ok(isset($m['record_date'], $m['quantity'], $m['warehouse'], $m['notes']), 'logistics aliases match');

$m = HeaderMapper::map(['Column A', 'Column B']);
T::ok(!isset($m['imei']), 'no false-positive mapping');

$m = HeaderMapper::map(['IMEI', 'Serial']);
T::ok(isset($m['imei']), 'serial alone maps to imei');
T::ok(!isset($m['serial_number']) || $m['serial_number'] !== $m['imei'], 'serial not double-mapped when same column');

$row = HeaderMapper::extract(['123', 'Phone', 'INV-1'], ['imei' => 0, 'device_model' => 1, 'invoice_number' => 2]);
T::eq('123', $row['imei'], 'extract pulls imei');
T::eq('', $row['notes'], 'unmapped field is empty string');

$row = HeaderMapper::extract([], ['imei' => 0]);
T::eq('', $row['imei'], 'missing column index yields empty string');

/* ============ 4. Reconciliation ============ */
T::group('Reconciliation engine');
$recs = [
    ['imei' => '111', 'invoice_number' => 'A'],
    ['imei' => '222', 'invoice_number' => ''],
    ['imei' => '111', 'invoice_number' => 'B'],
    ['imei' => '333', 'invoice_number' => 'C'],
];
$r = Reconciler::run($recs);
T::eq(1, $r['kpis']['duplicate_imeis'], 'one duplicate group detected');
T::eq(1, $r['kpis']['duplicate_extra_rows'], 'duplicate excess rows correct');
T::eq(1, $r['kpis']['matched_records'], 'one matched');
T::eq(1, $r['kpis']['unmatched_imeis'], 'one unmatched');
T::eq(50.0, $r['kpis']['match_rate'], 'match rate excludes duplicates (1/2)');
T::eq(3, $r['kpis']['total_unique_imeis'], 'unique identifier count');

$r = Reconciler::run([]);
T::eq(0.0, $r['kpis']['match_rate'], 'empty input => zero rate, no division error');
T::eq(false, $r['kpis']['meets_target'], 'empty input does not meet target');

$r = Reconciler::run([['imei' => '111', 'invoice_number' => 'A']]);
T::eq(100.0, $r['kpis']['match_rate'], 'all matched => 100%');
T::eq(true, $r['kpis']['meets_target'], '100% meets 98% target');

$r = Reconciler::run([['imei' => '111-222', 'invoice_number' => 'A'], ['imei' => '111222', 'invoice_number' => 'B']]);
T::eq(1, $r['kpis']['duplicate_imeis'], 'formatted and plain form collide as duplicate');

$r = Reconciler::run([['imei' => '111', 'invoice_number' => ''], ['imei' => '222', 'invoice_number' => '']]);
T::eq(0.0, $r['kpis']['match_rate'], 'no references => 0%');

$r = Reconciler::run([['imei' => '  ', 'invoice_number' => 'A'], ['imei' => '111', 'invoice_number' => 'A']]);
T::eq(1, $r['kpis']['total_unique_imeis'], 'blank identifier excluded from grouping');
T::eq(100.0, $r['kpis']['match_rate'], 'blank identifier does not deflate rate');

$r = Reconciler::run([['imei' => '111', 'invoice_number' => 'A']], 100);
T::eq(0, $r['kpis']['duplicate_imeis'], 'single clean row has no duplicates');

/* ============ 5. Quality scanner ============ */
T::group('Quality scanner');
$q = QualityScanner::scan([], []);
T::eq(null, $q['score'], 'no records => null score');

$recs = [['imei' => '111', 'imei_norm' => '111', 'invoice_number' => 'A', 'department' => 'Logistics', 'record_date' => '2026-01-01']];
$q = QualityScanner::scan($recs, [['name' => 'S', 'last_synced_at' => gmdate('Y-m-d H:i:s')]], 60);
T::eq(100, $q['score'], 'clean data scores 100');

$recs = [['imei' => '', 'imei_norm' => '', 'invoice_number' => '', 'department' => '', 'record_date' => '']];
$q = QualityScanner::scan($recs, [], 60);
T::ok($q['score'] < 100, 'empty rows reduce score');
T::eq(2, count($q['issues']), 'two categories apply (identifier + completeness)');
T::ok(!in_array('Missing invoice reference', array_column($q['issues'], 'title'), true),
    'a row with no identifier is not also counted as missing an invoice');
T::eq(100 - (2 * 6), $q['score'], 'score equals 100 minus two categories');

$recs = [['imei' => '111', 'imei_norm' => '111', 'invoice_number' => 'A', 'department' => 'L', 'record_date' => 'd']];
$q = QualityScanner::scan($recs, [['name' => 'Old', 'last_synced_at' => '2020-01-01 00:00:00']], 60);
T::ok(count($q['issues']) === 1, 'stale source is exactly one category');
T::ok(str_contains($q['issues'][0]['description'], 'Old'), 'stale issue names the source');

$recs = [['imei' => '1', 'imei_norm' => '1', 'invoice_number' => 'A', 'department' => 'L', 'record_date' => 'd']];
$q = QualityScanner::scan($recs, [['name' => 'Never', 'last_synced_at' => null]], 60);
T::ok(count($q['issues']) === 1, 'never-synced source flagged');
T::ok(str_contains($q['issues'][0]['description'], 'never synced'), 'never-synced is described');

T::ok(is_int($q['penalty_each']), 'score weighting is disclosed');

/* ============ 6. Google Sheets URL handling ============ */
T::group('Google Sheets client');
$p = GoogleSheetsClient::parseUrl('https://docs.google.com/spreadsheets/d/1AbC-_d123/edit?gid=99#gid=99');
T::eq('1AbC-_d123', $p['id'], 'spreadsheet id parsed');
T::eq('99', $p['gid'], 'gid parsed');
T::eq(null, GoogleSheetsClient::parseUrl('https://example.com/nope'), 'non-sheet URL returns null');
$p2 = GoogleSheetsClient::parseUrl('https://docs.google.com/spreadsheets/d/XYZ/edit#gid=0');
T::eq('0', $p2['gid'], 'gid from fragment alone');
T::ok(str_contains(GoogleSheetsClient::exportUrl('ABC', '5'), 'gid=5'), 'export URL includes gid');
T::ok(str_contains(GoogleSheetsClient::exportUrl('ABC'), 'format=csv'), 'export URL requests CSV');

/* ============ 7. Auth ============ */
T::group('Authentication');
$tmp = tempnam(sys_get_temp_dir(), 'amaya');
$db = Database::fromPdo(new PDO('sqlite:' . $tmp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
foreach (\App\Core\Schema::ddl('sqlite') as $sql) { $db->exec($sql); }
foreach (\App\Core\Schema::indexDdl('sqlite') as $sql) { $db->exec($sql); }

$auth = new AuthService($db, 5, 900);
$hash = $auth->hash('CorrectHorse42');
T::ok(password_verify('CorrectHorse42', $hash), 'password verifies against hash');
T::ok(!password_verify('wrong', $hash), 'wrong password rejected');
T::ok($auth->verify('CorrectHorse42', $hash), 'service verify works');
T::ok(strlen($hash) > 50, 'hash is not plaintext');
T::ok(!str_contains($hash, 'CorrectHorse42'), 'hash does not leak the password');

$uid = $auth->createUser('tester', 'CorrectHorse42', 'admin');
T::ok($uid > 0, 'user created');
$res = $auth->attempt('tester', 'CorrectHorse42', '127.0.0.1');
T::eq(true, $res['ok'], 'valid login succeeds');
$res = $auth->attempt('tester', 'wrong', '127.0.0.1');
T::eq(false, $res['ok'], 'invalid login rejected');
T::ok(!str_contains($res['error'], 'not found'), 'error does not reveal user existence');

$auth->createUser('admin', 'CorrectHorse42', 'admin');
for ($i = 0; $i < 5; $i++) { $auth->attempt('admin', 'bad', '10.0.0.5'); }
$res = $auth->attempt('admin', 'CorrectHorse42', '10.0.0.5');
T::eq(false, $res['ok'], 'locked out after 5 failures even with correct password');

T::eq(null, AuthService::passwordProblem('Str0ngEnough', 10), 'compliant password accepted');
T::ok(AuthService::passwordProblem('short', 10) !== null, 'short password rejected');
T::ok(AuthService::passwordProblem('alllettersnodigits', 10) !== null, 'letters-only rejected');
T::ok(AuthService::passwordProblem('12345678901', 10) !== null, 'digits-only rejected');

/* ============ 8. Database / repository ============ */
T::group('Repository');
$repo = new \App\Domain\Repository($db);
\App\Domain\Seed::departments($db, ['Logistics', 'Sales']);
\App\Domain\Seed::sourceLinks($db);
$before = count($repo->departments());
\App\Domain\Seed::departments($db, ['Logistics', 'Sales']); // idempotent
T::eq($before, count($repo->departments()), 'department seeding is idempotent');
T::eq(2, count($repo->departments()), 'two departments present');
T::eq(9, (int) $db->fetchValue('SELECT COUNT(*) FROM source_links'), 'sheet-2 link registry seeded');
T::eq(1, (int) $db->fetchValue("SELECT COUNT(*) FROM departments WHERE name = 'Logistics'"), 'no duplicate Logistics row');

$sid = $repo->createSource(['name' => 'Test Source', 'source_type' => 'Manual CSV upload', 'department_id' => $repo->departmentIdByName('Logistics')]);
T::ok($sid > 0, 'source created');
$batch = $repo->createBatch($sid, 'test.csv', ['imei', 'invoice_number'], 2);
$inserted = $repo->insertRecords($batch, $sid, [
    ['imei' => '001122334455667', 'imei_norm' => '001122334455667', 'invoice_number' => 'INV-1'],
    ['imei' => '0088', 'imei_norm' => '0088', 'invoice_number' => ''],
]);
T::eq(2, $inserted, 'records inserted');
T::eq(2, $repo->recordCount(), 'record count correct');
T::eq('001122334455667', (string) $db->fetchValue('SELECT imei FROM records ORDER BY id LIMIT 1'), 'leading-zero IMEI round-trips through DB');

$recs = $repo->recordsForReconciliation();
T::eq(2, count($recs), 'records loaded for reconciliation');
T::eq('Test Source', $recs[0]['source_name'], 'source name joined');

$r = Reconciler::run($recs);
$repo->applyMatchStatuses($r['statuses']);
T::eq('matched', $db->fetchValue('SELECT match_status FROM records WHERE imei_norm = ?', ['001122334455667']), 'status persisted for matched');
T::eq('unmatched', $db->fetchValue('SELECT match_status FROM records WHERE imei_norm = ?', ['0088']), 'status persisted for unmatched');

$repo->applyMatchStatuses([]);
T::eq(2, $repo->recordCount(), 'empty status map is a no-op');

$repo->audit('tester', 'CSV import', '2 rows');
T::eq(1, count($repo->auditLog()), 'audit entry written');

/* ============ 9. Import service end-to-end ============ */
T::group('Import service (end-to-end)');
$db2Path = tempnam(sys_get_temp_dir(), 'amaya2');
$db2 = Database::fromPdo(new PDO('sqlite:' . $db2Path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
foreach (\App\Core\Schema::ddl('sqlite') as $sql) { $db2->exec($sql); }
foreach (\App\Core\Schema::indexDdl('sqlite') as $sql) { $db2->exec($sql); }
\App\Domain\Seed::departments($db2, ['Logistics']);
$repo2 = new \App\Domain\Repository($db2);
$svc   = new \App\Domain\ImportService($db2, $repo2);

$res = $svc->importCsv('', 'empty.csv');
T::eq(false, $res['ok'], 'empty CSV rejected');
$res = $svc->importCsv("imei,invoice\n", 'headeronly.csv');
T::eq(false, $res['ok'], 'header-only CSV rejected');
$res = $svc->importCsv("name,city\nA,B\n", 'nomatch.csv');
T::eq(false, $res['ok'], 'CSV without IMEI column rejected');
T::ok(str_contains($res['error'], 'IMEI'), 'missing-column error explains the problem');

$csv = "imei,device_model,invoice_number,department,record_date\n"
     . "868436084232096,\"Redmi Note 13, Pro\",INV-1,Logistics,2026-10-10\n"
     . "868436084232097,Xiaomi 14,,Logistics,2026-10-11\n"
     . "868436084232096,Duplicate,INV-2,Logistics,2026-10-11\n"
     . ",No identifier,INV-3,Logistics,2026-10-12\n";
$res = $svc->importCsv($csv, 'real.csv');
T::eq(true, $res['ok'], 'valid CSV imports');
T::eq(4, $res['total'], 'all body rows counted');
T::eq(3, $res['inserted'], 'identifier-less row skipped');
T::eq(1, $res['skipped'], 'skipped count reported');
T::eq(1, $res['reconciliation']['duplicate_imeis'], 'duplicate found after import');
T::eq(1, $res['reconciliation']['unmatched_imeis'], 'unmatched found after import');
T::eq(0.0, $res['reconciliation']['match_rate'], 'no fully matched unique ids');

$csv2 = "imei,invoice_number\n868436084232096,INV-9\n";
$res2 = $svc->importCsv($csv2, 'second.csv');
T::eq(1, $res2['inserted'], 'second import inserts');
T::eq(1, (int) $db2->fetchValue("SELECT rows_inserted FROM import_batches WHERE filename = 'second.csv'"), 'second batch row recorded');

$res3 = $svc->importCsv("imei,invoice_number\n001122334455667,INV-X\n", 'zeros.csv');
T::eq('001122334455667', (string) $db2->fetchValue("SELECT imei FROM records WHERE imei_norm = '001122334455667'"), 'CSV leading zeroes preserved end-to-end');

/* ============ 10. Large / stress input ============ */
T::group('Stress');
$big = "imei,invoice_number,department,record_date\n";
for ($i = 0; $i < 5000; $i++) {
    $big .= sprintf("868%09d,%s,Logistics,2026-10-10\n", $i, $i % 3 === 0 ? '' : 'INV-' . $i);
}
$db3Path = tempnam(sys_get_temp_dir(), 'amaya3');
$db3 = Database::fromPdo(new PDO('sqlite:' . $db3Path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
foreach (\App\Core\Schema::ddl('sqlite') as $sql) { $db3->exec($sql); }
foreach (\App\Core\Schema::indexDdl('sqlite') as $sql) { $db3->exec($sql); }
$repo3 = new \App\Domain\Repository($db3);
$svc3  = new \App\Domain\ImportService($db3, $repo3);
$start = microtime(true);
$res4 = $svc3->importCsv($big, 'big.csv');
$elapsed = microtime(true) - $start;
T::eq(5000, $res4['inserted'], '5000-row import succeeds');
T::ok($elapsed < 30, sprintf('5000-row import completes quickly (%.2fs)', $elapsed));
// 1 in 3 rows has no reference => 3333/5000 = 66.7% matched, all IMEIs unique.
T::eq(0, $res4['reconciliation']['duplicate_imeis'], '5000 unique IMEIs => no duplicates');
T::eq(66.7, $res4['reconciliation']['match_rate'], 'match rate 3333/5000 = 66.7%');
T::eq(1667, $res4['reconciliation']['unmatched_imeis'], 'unmatched count is 1-in-3');

/* ============ 11. Schema portability ============ */
T::group('Schema portability');
$mysql = \App\Core\Schema::ddl('mysql');
T::ok(count($mysql) > 0, 'mysql DDL generated');
T::ok(str_contains(implode(' ', $mysql), 'AUTO_INCREMENT'), 'mysql DDL uses AUTO_INCREMENT');
T::ok(!str_contains(implode(' ', $mysql), 'AUTOINCREMENT'), 'mysql DDL has no SQLite AUTOINCREMENT');
$sqlite = \App\Core\Schema::ddl('sqlite');
T::ok(str_contains(implode(' ', $sqlite), 'AUTOINCREMENT'), 'sqlite DDL uses AUTOINCREMENT');
$all = array_merge(\App\Core\Schema::ddl('sqlite'), \App\Core\Schema::indexDdl('sqlite'));
foreach ($all as $stmt) { $db->exec($stmt); }
T::ok(true, 'schema statements are re-runnable');

$m1 = new \App\Core\Migrator($db, Config::load(__DIR__ . '/../public/config.php'));
$m2 = new \App\Core\Migrator($db, Config::load(__DIR__ . '/../public/config.php'));
$m1->migrate();
T::ok(count($m2->migrate()) > 0, 'migrator runs twice without fatal error');
T::eq(6, (int) $db->fetchValue('SELECT COUNT(*) FROM departments'), 'migrate() seeds departments idempotently');
// This DB already holds a manually-created "Test Source" plus the 3 registered
// spreadsheets, so expect 4.
T::eq(4, (int) $db->fetchValue('SELECT COUNT(*) FROM data_sources'), 'three spreadsheets + test source registered');
T::eq(2, (int) $db->fetchValue("SELECT COUNT(*) FROM data_sources WHERE last_status = 'private'"), 'two sheets marked private');
T::eq(1, (int) $db->fetchValue("SELECT COUNT(*) FROM data_sources WHERE last_status = 'connected'"), 'one sheet marked connected');
$m3 = new \App\Core\Migrator($db, Config::load(__DIR__ . '/../public/config.php'));
$m3->migrate();
T::eq(4, (int) $db->fetchValue('SELECT COUNT(*) FROM data_sources'), 'spreadsheet seeding is idempotent');

foreach (($GLOBALS["__test_dbs"] ?? []) as $p) { @unlink($p); }
@unlink($tmp); @unlink($db2Path); @unlink($db3Path);
exit(T::summary());