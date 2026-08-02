<?php
declare(strict_types=1);

/**
 * Small helpers shared by every page.
 */

/**
 * Escape for HTML output.
 *
 * The original called htmlspecialchars() on *input* at the point of
 * registration, which is the wrong end: it stored "O'Brien" as "O&#039;Brien"
 * in the database, corrupting the data, while doing nothing for values that
 * arrived from anywhere else. Values that came back out of the database --
 * filenames, most importantly -- were echoed raw, so a file named
 * <img src=x onerror=alert(1)>.txt executed for anyone viewing the list.
 *
 * Store what the user typed; escape at the moment of output. Every echo of a
 * dynamic value in this project goes through this function.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect and stop.
 *
 * The "and stop" is the point. upload.php sent a Location header when the user
 * was not logged in but never called exit, so PHP carried on and rendered the
 * whole dashboard -- including the file listing -- into a response the browser
 * usually, but not always, discarded. curl -i showed everything.
 */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/**
 * A UUID v4 from the CSPRNG.
 *
 * Replaces com_create_guid(), which only exists on Windows with the COM
 * extension enabled and returns a value with no documented entropy guarantee.
 * File identifiers appear in URLs here, so they need to be unguessable.
 */
function uuid4(): string
{
    $bytes = random_bytes(16);

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);   // version 4
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);   // variant 1

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * True if the string is a well-formed UUID.
 *
 * Used to reject junk before it reaches a UNIQUEIDENTIFIER parameter, so a
 * malformed id shows a clean "not found" instead of a driver conversion error.
 */
function is_uuid(string $value): bool
{
    return (bool) preg_match(
        '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i',
        $value
    );
}

/**
 * Human-readable byte count.
 */
function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    $power = min($power, count($units) - 1);
    $value = $bytes / (1024 ** $power);

    return ($power === 0)
        ? $bytes . ' B'
        : sprintf('%.1f %s', $value, $units[$power]);
}

/**
 * Format a UTC timestamp from the database for display.
 */
function format_date(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('j M Y, H:i');
    } catch (Exception) {
        return '';
    }
}
