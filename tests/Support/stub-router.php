<?php

// Роутер встроенного сервера для StubServer: возвращает эхо запроса в JSON.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/status/(\d{3})$#', $path, $m))
    http_response_code((int)$m[1]);

if (substr($path, -5) === '/text') {
    header('Content-Type: text/plain');
    echo 'plain text';
    return true;
}

$headers = [];
foreach ($_SERVER as $name => $value)
    if (strncmp($name, 'HTTP_', 5) === 0)
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;

if (isset($_SERVER['CONTENT_TYPE']))
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];

header('Content-Type: application/json');

echo json_encode([
    'method'  => $_SERVER['REQUEST_METHOD'],
    'path'    => $path,
    'query'   => $_GET,
    'post'    => $_POST,
    'body'    => file_get_contents('php://input'),
    'headers' => $headers,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

return true;
