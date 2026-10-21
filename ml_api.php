<?php
/**
 * Mercado Livre API Proxy — JK Acordeons
 * Faz chamadas à API do ML no servidor (evita CORS)
 *
 * Uso: ml_api.php?endpoint=/users/me&method=GET
 *      ml_api.php?endpoint=/items&method=POST  (com body JSON)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Endpoint de debug
if (isset($_GET['endpoint']) && $_GET['endpoint'] === '/debug') {
    $tokenFile = __DIR__ . '/ml_token.json';
    $configFile = __DIR__ . '/ml_config.json';
    echo json_encode([
        'dir' => __DIR__,
        'token_file' => $tokenFile,
        'token_exists' => file_exists($tokenFile),
        'config_exists' => file_exists($configFile),
        'dir_writable' => is_writable(__DIR__),
        'files_in_dir' => array_values(array_filter(scandir(__DIR__), function($f){ return strpos($f,'ml_')===0; }))
    ]);
    exit;
}

// Se receber token via POST header (fallback para salvar token do JS)
if (isset($_GET['endpoint']) && $_GET['endpoint'] === '/save_token') {
    $body = json_decode(file_get_contents('php://input'), true);
    if ($body && isset($body['access_token'])) {
        $tokenFile = __DIR__ . '/ml_token.json';
        $tokenData = [
            'access_token' => $body['access_token'],
            'refresh_token' => isset($body['refresh_token']) ? $body['refresh_token'] : null,
            'token_type' => 'Bearer',
            'expires_at' => isset($body['token_expires']) ? intval($body['token_expires']/1000) : time()+21600,
            'user_id' => isset($body['seller_id']) ? $body['seller_id'] : null,
            'created_at' => time()
        ];
        $ok = file_put_contents($tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT));
        echo json_encode(['ok' => $ok !== false, 'path' => $tokenFile]);
        exit;
    }
    echo json_encode(['error' => 'Dados inválidos']);
    exit;
}

// Carregar token
$tokenFile = __DIR__ . '/ml_token.json';
if (!file_exists($tokenFile)) {
    http_response_code(401);
    echo json_encode(['error' => 'Token não encontrado. Autorize o app primeiro.', 'path' => $tokenFile, 'dir_writable' => is_writable(__DIR__)]);
    exit;
}

$tokenData = json_decode(file_get_contents($tokenFile), true);

// Carregar credenciais do app
$configFile = __DIR__ . '/ml_config.json';
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$app_id = isset($config['app_id']) ? $config['app_id'] : '';
$client_secret = isset($config['client_secret']) ? $config['client_secret'] : '';

// Verificar se token expirou e tentar refresh
if (time() >= $tokenData['expires_at']) {
    if (!$tokenData['refresh_token'] || !$app_id || !$client_secret) {
        http_response_code(401);
        echo json_encode(['error' => 'Token expirado. Re-autorize o app.']);
        exit;
    }

    // Refresh token
    $ch = curl_init('https://api.mercadolibre.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $app_id,
            'client_secret' => $client_secret,
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
        echo json_encode(['error' => 'Refresh token falhou. Re-autorize o app.', 'ml_response' => json_decode($resp, true)]);
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
$baseUrl = 'https://api.mercadolibre.com';
$url = $baseUrl . $endpoint;

// Se tem query params no endpoint, preservar
if (isset($_GET['params'])) {
    $url .= (strpos($url, '?') !== false ? '&' : '?') . $_GET['params'];
}

// Fazer requisição ao ML
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

// Retornar resposta do ML com mesmo status code
http_response_code($httpCode);

// Adicionar info do token na resposta
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
