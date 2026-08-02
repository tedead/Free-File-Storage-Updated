<?php
declare(strict_types=1);

/**
 * Connection diagnostic. Run from the command line:
 *
 *     php tools\check-db.php
 *
 * Checks each layer in order and stops at the first failure, translating the
 * driver's error into the thing that is actually wrong. Connecting to a SQL
 * Server across a network has several independent ways to fail -- TCP/IP
 * disabled, firewall, SQL Browser not running, wrong auth mode, missing
 * database, missing permissions -- and they all surface as similar-looking
 * ODBC errors.
 *
 * Safe to run: it only reads, and never prints the password.
 */

/*
 * Command line only. Refuse to run over HTTP.
 *
 * This has to come before anything else. Everything below prints the database
 * host, the database name and the login name -- exactly the reconnaissance an
 * attacker wants -- and a directory deny rule is not enough to rely on: it is
 * server configuration, it differs between Apache and IIS, and the PHP built-in
 * server ignores it entirely. A diagnostic that should only ever run from a
 * shell needs to enforce that in its own code.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions/util.php';
require_once __DIR__ . '/../functions/db.php';

function line(string $s = ''): void { echo $s . PHP_EOL; }
function ok(string $s): void   { line('  [ ok ] ' . $s); }
function bad(string $s): void  { line('  [FAIL] ' . $s); }
function info(string $s): void { line('         ' . $s); }

$cfg = config('db');

line();
line('Free File Storage - database connection check');
line(str_repeat('=', 60));
line();
line('Target');
info('host       : ' . $cfg['host']);
info('port       : ' . ($cfg['port'] > 0 ? (string) $cfg['port'] : '(none - named instance or LocalDB)'));
info('database   : ' . $cfg['name']);
info('auth       : ' . ($cfg['user'] !== '' ? "SQL login '{$cfg['user']}'" : 'Windows authentication'));
info('encrypt    : ' . ($cfg['encrypt'] ? 'yes' : 'no'));
info('trust_cert : ' . ($cfg['trust_cert'] ? 'yes (NOT verified)' : 'no (verified)'));
line();

// ---------------------------------------------------------------- placeholders
line('1. Configuration filled in');
$unfilled = [];
foreach (['host' => $cfg['host'], 'pass' => $cfg['pass']] as $k => $v) {
    if (str_contains((string) $v, 'CHANGE')) {
        $unfilled[] = $k;
    }
}
if ($unfilled !== []) {
    bad('config.local.php still has placeholder values: ' . implode(', ', $unfilled));
    info('Edit config.local.php and set them, then run this again.');
    line();
    exit(1);
}
ok('no placeholders left');
line();

// ---------------------------------------------------------------- extension
line('2. Driver');
if (!extension_loaded('pdo_sqlsrv')) {
    bad('pdo_sqlsrv is not loaded in this PHP (' . PHP_VERSION . ')');
    info('ini in use: ' . (php_ini_loaded_file() ?: 'none'));
    info('Note WAMP has a separate ini per PHP version, and another for Apache');
    info('(phpForApache.ini). Both need the extension line.');
    line();
    exit(1);
}
ok('pdo_sqlsrv loaded (PHP ' . PHP_VERSION . ')');
$drivers = PDO::getAvailableDrivers();
ok('PDO drivers: ' . implode(', ', $drivers));
line();

// ---------------------------------------------------------------- TCP reach
line('3. Network reachability');
$hostOnly = preg_replace('/\\\\.*$/', '', $cfg['host']);
$probePort = $cfg['port'] > 0 ? $cfg['port'] : 1433;

if (stripos($cfg['host'], '(localdb)') === 0) {
    info('LocalDB uses a named pipe, not TCP - skipping this check');
} else {
    $sock = @fsockopen($hostOnly, $probePort, $errNo, $errStr, 5.0);
    if ($sock === false) {
        bad("cannot open TCP {$hostOnly}:{$probePort} - {$errStr} ({$errNo})");
        info('Likely one of:');
        info('  - TCP/IP disabled for the instance (SQL Server Configuration');
        info('    Manager -> Protocols -> TCP/IP -> Enabled, then restart it)');
        info('  - Windows Firewall on the server is blocking the port');
        info('  - wrong host/IP, or the machine is off');
        if ($cfg['port'] > 0) {
            info('  - a NAMED instance: set port to 0 and make sure the');
            info('    SQL Browser service is running (UDP 1434)');
        }
        line();
        exit(1);
    }
    fclose($sock);
    ok("TCP {$hostOnly}:{$probePort} is open");
}
line();

// ---------------------------------------------------------------- connect
line('4. Authentication and database');
try {
    $pdo = db();
    ok('connected');
    ok('server version : ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    $who = $pdo->query('SELECT SUSER_SNAME() AS login, USER_NAME() AS dbuser, DB_NAME() AS db')->fetch();
    ok("connected as login '{$who['login']}' / db user '{$who['dbuser']}' on '{$who['db']}'");
} catch (PDOException $e) {
    $m = $e->getMessage();
    bad('connection failed');
    info($m);
    line();
    info('Interpretation:');
    if (stripos($m, 'Login failed') !== false) {
        info('  Wrong username or password, OR the server is set to Windows-only');
        info('  authentication. A SQL login needs Mixed Mode: Server Properties');
        info('  -> Security -> "SQL Server and Windows Authentication mode",');
        info('  then restart the SQL Server service.');
    } elseif (stripos($m, 'Cannot open database') !== false) {
        info('  Reached the server and logged in, but the database is missing.');
        info('  Run sql\\01_schema.sql, 02_procedures.sql, 03_security.sql on it.');
    } elseif (stripos($m, 'certificate') !== false || stripos($m, 'SSL') !== false) {
        info('  TLS problem. The server certificate is not trusted by this');
        info('  machine. Either install a trusted certificate on the server, or');
        info('  set trust_cert => true temporarily (encrypted but unverified).');
    } elseif (stripos($m, 'Encryption not supported') !== false) {
        info('  The server has no certificate configured at all. Set');
        info('  encrypt => false, but only if the connection is local.');
    } else {
        info('  See the driver message above.');
    }
    line();
    exit(1);
}
line();

// ---------------------------------------------------------------- objects
line('5. Schema objects');
$procs = ['GetUserForLogin','TouchLastLogin','UpdatePasswordHash','CreateUser',
          'ListUserFiles','AddFile','GetUserFile','DeleteFile'];
$found = $pdo->query(
    "SELECT name FROM sys.procedures WHERE SCHEMA_NAME(schema_id) = 'ffs'"
)->fetchAll(PDO::FETCH_COLUMN);

$missing = array_diff($procs, $found);
if ($missing !== []) {
    bad('missing procedures: ' . implode(', ', $missing));
    info('Run sql\\02_procedures.sql against this database.');
    line();
    exit(1);
}
ok('all 8 ffs.* procedures present');
line();

// ---------------------------------------------------------------- permissions
line('6. Least-privilege check');
try {
    $pdo->query('SELECT TOP 1 UserName FROM dbo.users')->fetch();
    line('  [warn] this login CAN read dbo.users directly');
    info('Expected for a sysadmin/dba login used for setup. The application');
    info('should run as ffs_app, which is denied direct table access and can');
    info('only execute the ffs.* procedures. See sql\\03_security.sql.');
} catch (PDOException) {
    ok('direct SELECT on dbo.users is denied - least privilege is in effect');
}

try {
    $stmt = $pdo->prepare('{CALL ffs.GetUserForLogin(?)}');
    $stmt->execute(['__connection_check__']);
    $stmt->closeCursor();
    ok('can EXECUTE ffs.GetUserForLogin');
} catch (PDOException $e) {
    bad('cannot execute the procedures: ' . $e->getMessage());
    info('Run sql\\03_security.sql to grant EXECUTE ON SCHEMA::ffs.');
    line();
    exit(1);
}
line();

line(str_repeat('=', 60));
line('All checks passed. The application should work.');
line();
exit(0);
