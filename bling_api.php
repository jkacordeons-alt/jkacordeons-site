<?php
/**
 * Bling API Proxy — JK Acordeons
 * Faz chamadas à API do Bling no servidor (evita CORS)
 *
 * Uso: bling_api.php?endpoint=/produtos&method=GET
 *      bling_api.php?endpoint=/nfe&method=POST  (com body JSON)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$client_id = '03fff514c8b98b898090d543fc055b2b817fbfa3';
$client_secret = '389d9a1738ed463e9e6699ca989199bb09591d4b0464d4202f5b1590c40d';

// Carregar token
$tokenFile = __DIR__ . '/bling_token.json';
if (!file_exists($tokenFile)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token não encontrado. Autorize o app primeiro.']);
    exit;
}

$tokenData = json_decode(file_get_contents($tokenFile), true);

// Verificar se token expirou e tentar refresh
if (time() >= $tokenData['expires_at']) {
    if (!$tokenData['refresh_token']) {
        http_response_code(401);
        echo json_encode(['error' => 'Token expirado. Re-autorize o app.']);
        exit;
    }

    // Refresh token
    $auth = base64_encode($client_id . ':' . $client_secret);
    $ch = curl_init('https://www.bling.com.br/Api/v3/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $auth
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token']
        ]),
        CURLOPT_TIMEOUT => 15
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $newData = json_decode($resp, true);
        $tokenData['access_token'] = $newData['access_token'];
        $tokenData['refresh_token'] = isset($newData['refresh_token']) ? $newData['refresh_token'] : $tokenData['refresh_token'];
        $tokenData['expires_at'] = time() + (isset($newData['expires_in']) ? $newData['expires_in'] : 21600);
        $tokenData['refreshed_at'] = time();
        file_put_contents($tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT));
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Refresh token falhou. Re-autorize o app.', 'bling_response' => json_decode($resp, true)]);
        exit;
    }
}

// Parâmetros da requisição
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : null;
$method = isset($_GET['method']) ? strtoupper($_GET['method']) : 'GET';

if (!$endpoint) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro endpoint é obrigatório']);
    exit;
}

// Montar URL da API
$baseUrl = 'https://www.bling.com.br/Api/v3';
$url = $baseUrl . $endpoint;

// Se tem query params no endpoint, preservar
if (isset($_GET['params'])) {
    $url .= (strpos($url, '?') !== false ? '&' : '?') . $_GET['params'];
}

// Fazer requisição ao Bling
$ch = curl_init($url);
$headers = [
    'Authorization: Bearer ' . $tokenData['access_token'],
    'Accept: application/json'
];

$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => $method
];

if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $body = file_get_contents('php://input');
    if ($body) {
        $opts[CURLOPT_POSTFIELDS] = $body;
        $headers[] = 'Content-Type: application/json';
    }
}

$opts[CURLOPT_HTTPHEADER] = $headers;
curl_setopt_array($ch, $opts);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de conexão: ' . $error]);
    exit;
}

// Retornar resposta do Bling com mesmo status code
http_response_code($httpCode);

// Adicionar info do token na resposta (para o admin atualizar localStorage)
$parsed = json_decode($response, true);
if (is_array($parsed)) {
    $parsed['_token_info'] = [
        'expires_at' => $tokenData['expires_at'] * 1000, // ms para JS
        'valid' => time() < $tokenData['expires_at']
    ];
    echo json_encode($parsed);
} else {
    echo $response;
}
