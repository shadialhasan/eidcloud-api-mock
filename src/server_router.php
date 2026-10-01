<?php

declare(strict_types=1);

// Embedded router script for PHP built-in web server: php -S localhost:8080 server_router.php

require_once __DIR__ . '/autoload.php';

use EidCloud\ApiMock\MockServer;
use EidCloud\ApiMock\Simulator\ChaosSimulator;

$specPath = getenv('EIDCLOUD_MOCK_SPEC') ?: 'openapi.json';
$delayMs = (int) (getenv('EIDCLOUD_MOCK_DELAY') ?: 0);
$errorRate = (float) (getenv('EIDCLOUD_MOCK_ERROR_RATE') ?: 0.0);

if (!file_exists($specPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'SpecFileNotFound', 'path' => $specPath]);
    exit;
}

// Persist storage across requests using a simple temp JSON file or session if desired
static $serverInstance = null;
if ($serverInstance === null) {
    $chaos = new ChaosSimulator($delayMs, $errorRate);
    $serverInstance = new MockServer($specPath, null, $chaos);
}

// Extract request details
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$headers = getallheaders() ?: [];
$body = file_get_contents('php://input');

$response = $serverInstance->handle($method, $uri, $headers, $body, true);

http_response_code($response['statusCode']);
foreach ($response['headers'] as $name => $value) {
    header("{$name}: {$value}");
}

if (is_array($response['body'])) {
    echo json_encode($response['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo $response['body'];
}
