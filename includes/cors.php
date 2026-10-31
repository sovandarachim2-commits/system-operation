<?php
/**
 * Centralized CORS handling for the OrderShadow backend.
 *
 * - Origins are matched EXACTLY against $API_ALLOWED_ORIGINS (config.php).
 *   No wildcards, no suffix matching, never "*".
 * - Must run before session_start()/authentication so OPTIONS preflight
 *   requests are answered without touching the session or database.
 * - Headers are sent at the very start of the request, so every
 *   application-generated response (api_json, api_error, 401/403 from
 *   require_login, etc.) carries the same CORS headers.
 * - Credentials are allowed because the report frontend authenticates with
 *   the PHP session cookie and/or the signed report token.
 */

const CORS_ALLOWED_METHODS = 'GET, POST, PUT, DELETE, OPTIONS';
const CORS_ALLOWED_HEADERS = 'Authorization, Content-Type, Accept, X-Requested-With, Cache-Control, X-Report-Token, X-Frontend-URL';
// Chromium caps preflight caching at 2 hours; larger values are ignored.
const CORS_MAX_AGE = 7200;

function cors_allowed_origins(): array
{
    $origins = $GLOBALS['API_ALLOWED_ORIGINS'] ?? [];
    if (!is_array($origins)) {
        return [];
    }
    $clean = [];
    foreach ($origins as $origin) {
        $origin = rtrim(trim((string)$origin), '/');
        if ($origin !== '' && $origin !== '*') {
            $clean[] = $origin;
        }
    }
    return array_values(array_unique($clean));
}

function cors_origin_is_allowed(string $origin): bool
{
    return $origin !== '' && in_array($origin, cors_allowed_origins(), true);
}

function cors_handle_request(): void
{
    static $handled = false;
    if ($handled || PHP_SAPI === 'cli') {
        return;
    }
    $handled = true;

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = cors_origin_is_allowed($origin);

    // Responses differ per Origin, so shared caches must key on it.
    header('Vary: Origin', false);

    if ($allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }

    if ($method !== 'OPTIONS') {
        // Disallowed origins get no CORS headers: the browser will refuse to
        // expose the response. Authentication still applies as normal.
        return;
    }

    if ($origin !== '' && !$allowed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"success":false,"message":"Origin not allowed."}';
        exit;
    }

    if ($allowed) {
        header('Access-Control-Allow-Methods: ' . CORS_ALLOWED_METHODS);
        header('Access-Control-Allow-Headers: ' . CORS_ALLOWED_HEADERS);
        header('Access-Control-Max-Age: ' . CORS_MAX_AGE);
    }
    http_response_code(204);
    exit;
}

