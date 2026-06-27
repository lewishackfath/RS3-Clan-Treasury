<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Auth\ApiAuth;
use Treasury\Http\Response;
use Treasury\Services\BalanceService;
use Treasury\Services\IdempotencyService;
use Treasury\Services\PaymentRequestService;
use Treasury\Services\PayoutRequestService;
use Treasury\Support\Env;

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Request body must be valid JSON');
    }

    return $data;
}

function send_cors_headers(): void
{
    $allowed = Env::get('CORS_ALLOWED_ORIGINS', '');
    if ($allowed === '') {
        return;
    }

    $origins = array_map('trim', explode(',', $allowed));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Admin-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
}

function with_idempotency($context, string $rawBody, callable $callback): void
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if ($key === '') {
        $payload = $callback();
        Response::json($payload, 201);
        return;
    }

    if (strlen($key) > 180) {
        throw new InvalidArgumentException('Idempotency-Key must be 180 characters or fewer');
    }

    $hash = hash('sha256', $rawBody);
    $service = new IdempotencyService();
    $existing = $service->getExisting($context, $key, $hash);
    if ($existing) {
        Response::json($existing['response_body'], $existing['response_code']);
        return;
    }

    $payload = $callback();
    $service->save($context, $key, $hash, 201, $payload);
    Response::json($payload, 201);
}

send_cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    if ($script && str_starts_with($uri, $script)) {
        $path = substr($uri, strlen($script));
    } else {
        $path = $uri;
    }

    $path = '/' . trim($path, '/');
    $rawBody = file_get_contents('php://input') ?: '';
    $body = $rawBody === '' ? [] : json_decode($rawBody, true);
    if ($rawBody !== '' && !is_array($body)) {
        throw new InvalidArgumentException('Request body must be valid JSON');
    }

    if ($path === '/api/v1/me' && $method === 'GET') {
        $context = ApiAuth::requireContext();
        Response::json([
            'app' => [
                'id' => $context->appId,
                'slug' => $context->appSlug,
                'name' => $context->appName,
            ],
            'scopes' => $context->scopes,
        ]);
        exit;
    }

    if ($path === '/api/v1/balances' && $method === 'GET') {
        ApiAuth::requireContext('balances:read');
        Response::json((new BalanceService())->summary());
        exit;
    }

    if (($path === '/api/v1/money-in-requests' || $path === '/api/v1/payment-requests') && $method === 'POST') {
        $context = ApiAuth::requireContext('payments:create');
        with_idempotency($context, $rawBody, fn() => (new PaymentRequestService())->create($context, $body));
        exit;
    }

    if (preg_match('#^/api/v1/(?:money-in-requests|payment-requests)/([0-9a-fA-F-]{36})$#', $path, $m) && $method === 'GET') {
        ApiAuth::requireContext('payments:read');
        Response::json((new PaymentRequestService())->getByUuid($m[1]));
        exit;
    }

    if (preg_match('#^/api/v1/(?:money-in-requests|payment-requests)/([0-9a-fA-F-]{36})/receive$#', $path, $m) && $method === 'POST') {
        $context = ApiAuth::requireContext('payments:receive');
        Response::json((new PaymentRequestService())->receiveFromApi($m[1], $context, $body));
        exit;
    }

    if (preg_match('#^/api/v1/(?:money-in-requests|payment-requests)/by-source/([^/]+)/([^/]+)$#', $path, $m) && $method === 'GET') {
        $context = ApiAuth::requireContext('payments:read');
        Response::json((new PaymentRequestService())->getBySource($context->appId, rawurldecode($m[1]), rawurldecode($m[2])));
        exit;
    }

    if (($path === '/api/v1/money-out-requests' || $path === '/api/v1/payout-requests') && $method === 'POST') {
        $context = ApiAuth::requireContext('payouts:create');
        with_idempotency($context, $rawBody, fn() => (new PayoutRequestService())->create($context, $body));
        exit;
    }

    if (preg_match('#^/api/v1/(?:money-out-requests|payout-requests)/([0-9a-fA-F-]{36})$#', $path, $m) && $method === 'GET') {
        ApiAuth::requireContext('payouts:read');
        Response::json((new PayoutRequestService())->getByUuid($m[1]));
        exit;
    }

    if (preg_match('#^/api/v1/(?:money-out-requests|payout-requests)/by-source/([^/]+)/([^/]+)$#', $path, $m) && $method === 'GET') {
        $context = ApiAuth::requireContext('payouts:read');
        Response::json((new PayoutRequestService())->getBySource($context->appId, rawurldecode($m[1]), rawurldecode($m[2])));
        exit;
    }

    Response::error('Endpoint not found', 404);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    if ($status < 400 || $status > 599) {
        $status = $e instanceof InvalidArgumentException ? 422 : 500;
    }

    $payload = [];
    if (Env::bool('APP_DEBUG', false)) {
        $payload['exception'] = get_class($e);
        $payload['file'] = $e->getFile();
        $payload['line'] = $e->getLine();
    }

    Response::error($e->getMessage(), $status, $payload);
}
