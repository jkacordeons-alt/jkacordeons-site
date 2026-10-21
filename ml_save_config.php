<?php
/**
 * Salva as credenciais do app ML no servidor
 * Para uso pelo ml_api.php (proxy) e ml_callback.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['app_id']) || empty($input['client_secret'])) {
    http_response_code(400);
    echo json_encode(['error' => 'app_id e client_secret são obrigatórios']);
    exit;
}

$config = [
    'app_id' => $input['app_id'],
    'client_secret' => $input['client_secret'],
    'redirect_uri' => isset($input['redirect_uri']) ? $input['redirect_uri'] : 'https://jkacordeons.com.br/ml_callback.php',
    'code_verifier' => isset($input['code_verifier']) ? $input['code_verifier'] : null,
    'saved_at' => date('Y-m-d H:i:s')
];

$configFile = __DIR__ . '/ml_config.json';
file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

echo json_encode(['ok' => true, 'message' => 'Configuração salva']);
