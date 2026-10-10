<?php

declare(strict_types=1);

// The server behind the remote $ref tests: documents of tests/Fixtures/Remote/docs, plus a few failures.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
if ($path === '/moved.yaml') {
    header('Location: /docs/money.yaml', true, 301);

    return true;
}

if ($path === '/drip.yaml') {
    header('Content-Type: application/yaml');
    for ($i = 0; $i < 6; $i++) {
        echo 'a';
        flush();
        usleep(500000);
    }

    return true;
}

if ($path === '/headers.yaml') {
    for ($i = 0; $i < 2000; $i++) {
        header('X-Filler-' . $i . ': ' . str_repeat('x', 30));
    }

    echo 'A: {}';

    return true;
}

if ($path === '/no-location.yaml') {
    http_response_code(302);

    return true;
}

if ($path === '/big.json') {
    header('Content-Type: application/json');
    echo '{"x": "' . str_repeat('a', 2048) . '"}';

    return true;
}

$file = __DIR__ . $path;
if (strncmp($path, '/docs/', 6) !== 0 || !is_file($file)) {
    http_response_code(404);
    echo 'not found';

    return true;
}

header('Content-Type: ' . (substr($file, -5) === '.json' ? 'application/json' : 'application/yaml'));
readfile($file);

return true;
