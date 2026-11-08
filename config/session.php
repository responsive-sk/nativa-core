<?php

declare(strict_types = 1);

/**
 * Session configuration.
 *
 * Sessions are started LAZILY — middleware (CSRF, auth) and services call
 * session_start() only when they actually need the session.
 */

use Modules\Core\Infrastructure\Env;

$sessionName = Env::get('SESSION_NAME', 'nativa_core') ?? 'nativa_core';
$sessionLifetime = Env::int('SESSION_LIFETIME', 1209600);

$sessionSecureValue = Env::get('SESSION_SECURE');
$sessionSecure = (null === $sessionSecureValue || '' === $sessionSecureValue)
    ? Env::isHttps()
    : Env::bool('SESSION_SECURE', false);

ini_set('session.name', $sessionName);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', $sessionSecure ? '1' : '0');
ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
ini_set('session.auto_start', '0');

// Session storage directory.
$sessionPath = APP_BASE_PATH . '/storage/sessions';
if (!is_dir($sessionPath)) {
    if (!@mkdir($sessionPath, 0o775, true) && !is_dir($sessionPath)) {
        error_log('[session] Failed to create session save path: ' . $sessionPath . ' — falling back to system temp.');
        $sessionPath = sys_get_temp_dir();
    }
}
ini_set('session.save_path', $sessionPath);

// NOTE: session_start() is intentionally NOT called here.
