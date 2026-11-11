<?php

declare(strict_types = 1);

use Modules\Core\Infrastructure\Env;

/*
 * Application Initialization — zero-dependency bootstrap.
 *
 * This file performs basic framework setup:
 * 1. Composer Autoloader (development only)
 * 2. Simple PSR-4 Autoloader (zero-dependency fallback)
 * 3. Project Root Constant
 * 4. Environment Loading
 * 5. Server Header Hardening
 * 6. Error Handling
 * 7. Session Setup
 * 8. Timezone
 */

// ============================================================================
// 1. COMPOSER AUTOLOADER (development only)
// ============================================================================

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// ============================================================================
// 2. SIMPLE PSR-4 AUTOLOADER (zero-dependency fallback)
// ============================================================================

spl_autoload_register(static function (string $class): void {
    $prefixMap = [
        'Discovery\\' => [__DIR__ . '/Discovery/'],
        'Modules\\'   => [__DIR__ . '/Modules/'],
        'Seo\\'       => [__DIR__ . '/Seo/'],
        'Shell\\'     => [__DIR__ . '/Shell/'],
        'Templates\\' => [__DIR__ . '/Templates/'],
    ];

    foreach ($prefixMap as $prefix => $directories) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = str_replace('\\', '/', $relativeClass) . '.php';

        foreach ($directories as $directory) {
            $path = $directory . $file;
            if (is_file($path)) {
                require_once $path;

                return;
            }
        }
    }
});

// ============================================================================
// 3. PROJECT ROOT CONSTANT
// ============================================================================

/**
 * Absolute path to the project root directory.
 * Defined once at bootstrap so all path resolution can use it without
 * fragile dirname(__DIR__, N) traversal.
 */
define('APP_BASE_PATH', dirname(__DIR__));

// ============================================================================
// 4. LOAD ENVIRONMENT
// ============================================================================

Env::loadImmutable('');

// ============================================================================
// 5. SERVER HEADER HARDENING
// ============================================================================

// Remove X-Powered-By — the PHP version is a fingerprint for known-CVE
// targeting (audit finding F-4). The built-in dev server always sends it;
// header_remove() strips it for every SAPI before any output. Production
// servers should also set expose_php = Off in php.ini.
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

// ============================================================================
// 6. ERROR HANDLING
// ============================================================================

$isDebug = Env::bool('APP_DEBUG', false);

if ($isDebug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (is_dir($logDir) || mkdir($logDir, 0o755, true)) {
        ini_set('error_log', $logDir . '/php-error.log');
    }
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Register global error handler — converts PHP errors to ErrorException
// so they are caught by the exception handler below.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        // Error level not included in error_reporting — let PHP handle it
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Register global exception handler — logs uncaught exceptions and shows a
// generic error page in production or details in debug mode.
set_exception_handler(static function (\Throwable $e): void {
    // Always log the exception
    error_log(sprintf(
        '[FATAL] %s: %s in %s:%d%s',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL . $e->getTraceAsString(),
    ));

    $isDebug = Env::bool('APP_DEBUG', false);
    $message = $isDebug
        ? '<h1>Uncaught Exception</h1>'
        . '<p><strong>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</strong></p>'
        . '<p>File: ' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ':' . $e->getLine() . '</p>'
        . '<pre style="background:#f5f5f5;padding:10px;overflow:auto;">' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>'
        : '<h1>Server Error</h1><p>Something went wrong. Please try again later.</p>';

    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo $message;
    } else {
        fwrite(STDERR, '[FATAL] Uncaught exception: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, '  in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    }

    exit(1);
});

// ============================================================================
// 7. SESSION SETUP
// ============================================================================

if (file_exists(__DIR__ . '/../config/session.php')) {
    require_once __DIR__ . '/../config/session.php';
}

// Session is started lazily — middleware (CsrfMiddleware, AuthMiddleware)
// and services (login/register actions) call session_start() only when they
// actually need the session. This avoids session file I/O on public pages.

// ============================================================================
// 8. TIMEZONE
// ============================================================================

$timezone = Env::get('APP_TIMEZONE', 'Europe/Bratislava') ?? 'Europe/Bratislava';
date_default_timezone_set($timezone);
