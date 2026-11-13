<?php

declare(strict_types = 1);

/*
 * The fixture server for CurlTransportTest, run by `php -S`.
 *
 * Six answers, one per transport behaviour worth pinning: a JSON body with a
 * header, a body larger than the cap, a slow answer, a redirect the transport
 * must not follow, an echo of method/body/headers, and a 404. It binds to
 * neither a real API nor the network beyond loopback — the test allowlists
 * 127.0.0.1 explicitly, which is the only way SsrfGuard lets it through.
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('X-Test: restadmin');

if ('/json' === $path) {
    header('Content-Type: application/json');
    echo '{"ok":true}';

    return;
}

if ('/large' === $path) {
    header('Content-Type: text/plain');
    echo str_repeat('x', 4096);

    return;
}

if ('/slow' === $path) {
    usleep(1_500_000);
    echo 'late';

    return;
}

if ('/redirect' === $path) {
    http_response_code(302);
    header('Location: /json');

    return;
}

if ('/echo' === $path) {
    header('Content-Type: application/json');
    echo json_encode([
        'method'       => $method,
        'body'         => file_get_contents('php://input'),
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'accept'       => $_SERVER['HTTP_ACCEPT'] ?? null,
    ], JSON_THROW_ON_ERROR);

    return;
}

http_response_code(404);
echo 'not found';
