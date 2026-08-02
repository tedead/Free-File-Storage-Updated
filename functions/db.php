<?php
declare(strict_types=1);

/**
 * Database access (Microsoft SQL Server via PDO_SQLSRV).
 *
 * The application calls stored procedures only -- there is no SQL text in any
 * PHP file in this project. Everything goes through db_call(), which builds a
 * "{CALL ffs.Name(?, ?, ...)}" prepared statement and binds the arguments.
 *
 * Requires the Microsoft PHP driver:
 *   - php_pdo_sqlsrv extension (PECL / Microsoft download)
 *   - Microsoft ODBC Driver 17 or 18 for SQL Server
 * See README for the install steps on Windows.
 */

/**
 * The shared connection, opened on first use.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException(
            'The pdo_sqlsrv extension is not loaded. See README.md for installation steps.'
        );
    }

    $cfg = config('db');

    $dsn = sprintf(
        'sqlsrv:Server=%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s;APP=FreeFileStorage',
        sqlsrv_server_spec($cfg['host'], (int) $cfg['port']),
        $cfg['name'],
        $cfg['encrypt'] ? 'yes' : 'no',
        $cfg['trust_cert'] ? 'yes' : 'no'
    );

    // An empty username means Windows authentication: the web server's service
    // account connects and no password exists to be leaked.
    $user = $cfg['user'] !== '' ? $cfg['user'] : null;
    $pass = $cfg['user'] !== '' ? $cfg['pass'] : null;

    $pdo = new PDO($dsn, $user, $pass, [
        // Turn every driver error into an exception. The original used
        // "or die(mysqli_error())", which printed connection internals to the
        // browser and carried on running the rest of the page.
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    return $pdo;
}

/**
 * Build the Server= portion of the DSN.
 *
 * A port is only appended for a plain host talking TCP. It must be omitted for:
 *
 *   - LocalDB, e.g. (localdb)\MSSQLLocalDB -- connects over a named pipe
 *   - a named instance, e.g. .\SQLEXPRESS -- the SQL Browser service resolves
 *     the name to a dynamically assigned port
 *
 * Passing "host\instance,1433" to either fails, because the port and the
 * instance name are two competing ways of saying the same thing.
 */
function sqlsrv_server_spec(string $host, int $port): string
{
    $isNamedInstance = str_contains($host, '\\');
    $isLocalDb       = stripos($host, '(localdb)') === 0;

    if ($port <= 0 || $isNamedInstance || $isLocalDb) {
        return $host;
    }

    return $host . ',' . $port;
}

/**
 * Execute a stored procedure.
 *
 * @param string               $procedure Unqualified name within the ffs schema.
 * @param array<int, mixed>    $in        Positional input parameters.
 * @param array<int, array>    $out       Output parameters, keyed by the 0-based
 *                                        position they occupy in the full
 *                                        parameter list. Each entry is
 *                                        ['type' => PDO::PARAM_*, 'length' => int].
 * @param array<int, mixed>   &$outValues Receives output values, same keys.
 */
function db_call(
    string $procedure,
    array $in = [],
    array $out = [],
    ?array &$outValues = null
): PDOStatement {
    // Guard the one place a name reaches the SQL text. Nothing user-supplied is
    // ever passed here, but an allowlist pattern means it cannot become an
    // injection point if that ever changes.
    if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $procedure)) {
        throw new InvalidArgumentException("Illegal procedure name: {$procedure}");
    }

    $total = count($in) + count($out);
    $sql   = sprintf(
        '{CALL ffs.%s(%s)}',
        $procedure,
        implode(', ', array_fill(0, $total, '?'))
    );

    $stmt = db()->prepare($sql);

    // Interleave inputs and outputs back into their declared positions.
    $inValues  = array_values($in);
    $inCursor  = 0;
    $bound     = [];

    for ($i = 0; $i < $total; $i++) {
        $position = $i + 1;   // PDO parameters are 1-based

        if (isset($out[$i])) {
            // Initialise to a value of the bound type. pdo_sqlsrv rejects a
            // NULL-initialised output parameter with "invalid PHP type"; the
            // procedure is still free to return NULL back into it.
            $bound[$i] = $out[$i]['init']
                ?? ($out[$i]['type'] === PDO::PARAM_INT ? 0 : '');
            $stmt->bindParam(
                $position,
                $bound[$i],
                $out[$i]['type'] | PDO::PARAM_INPUT_OUTPUT,
                $out[$i]['length']
            );
            continue;
        }

        $value = $inValues[$inCursor];
        $inCursor++;
        $stmt->bindValue($position, $value, pdo_type_of($value));
    }

    $stmt->execute();

    if ($out !== []) {
        // SQL Server only populates output parameters once every result set has
        // been consumed. Drain them before reading the values back.
        try {
            while ($stmt->nextRowset()) {
                // no-op: we just need to advance past each rowset
            }
        } catch (PDOException) {
            // Procedures with SET NOCOUNT ON and no SELECT produce no rowset at
            // all, and the driver reports that by throwing rather than
            // returning false. Nothing to drain, so nothing to do.
        }

        $stmt->closeCursor();
        $outValues = $bound;
    }

    return $stmt;
}

/**
 * Call a procedure and return all rows.
 *
 * @return array<int, array<string, mixed>>
 */
function db_rows(string $procedure, array $in = []): array
{
    return db_call($procedure, $in)->fetchAll();
}

/**
 * Call a procedure and return the first row, or null.
 *
 * @return array<string, mixed>|null
 */
function db_row(string $procedure, array $in = []): ?array
{
    $stmt = db_call($procedure, $in);
    $row  = $stmt->fetch();
    $stmt->closeCursor();

    return $row === false ? null : $row;
}

/**
 * Call a procedure for its side effect only.
 */
function db_exec(string $procedure, array $in = []): void
{
    db_call($procedure, $in)->closeCursor();
}

/**
 * Map a PHP value to the PDO type the driver should bind it as.
 */
function pdo_type_of(mixed $value): int
{
    return match (true) {
        is_int($value)  => PDO::PARAM_INT,
        is_bool($value) => PDO::PARAM_BOOL,
        $value === null => PDO::PARAM_NULL,
        default         => PDO::PARAM_STR,
    };
}
