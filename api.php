<?php
/**
 * API simples para persistência de dados - JK Acordeons Admin
 * Salva/carrega dados em arquivo JSON no servidor
 */

// Configuração
$DATA_DIR = __DIR__ . '/data';
$DATA_FILE = $DATA_DIR . '/admin_data.json';
$API_KEY = 'jk2024acordeons'; // Chave simples de autenticação

// Headers CORS (permite acesso de qualquer origem durante desenvolvimento)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Criar diretório de dados se não existir
if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0755, true);
}

// Proteger diretório com .htaccess
$htaccess = $DATA_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

// Verificar autenticação
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if ($apiKey !== $API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Chave de API inválida']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'load':
        // Carregar dados
        if (file_exists($DATA_FILE)) {
            $data = file_get_contents($DATA_FILE);
            echo $data;
        } else {
            echo json_encode(['produtos' => [], 'meta_config' => null, 'updated' => null]);
        }
        break;

    case 'save':
        // Salvar dados
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON inválido: ' . json_last_error_msg()]);
            exit;
        }

        // Adicionar timestamp
        $data['updated'] = date('c');

        // Backup antes de sobrescrever
        if (file_exists($DATA_FILE)) {
            $backupFile = $DATA_DIR . '/backup_' . date('Y-m-d_His') . '.json';
            copy($DATA_FILE, $backupFile);

            // Manter apenas os últimos 10 backups
            $backups = glob($DATA_DIR . '/backup_*.json');
            if (count($backups) > 10) {
                usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
                $toDelete = array_slice($backups, 0, count($backups) - 10);
                foreach ($toDelete as $old) {
                    unlink($old);
                }
            }
        }

        $bytes = file_put_contents($DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($bytes === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao salvar arquivo']);
        } else {
            echo json_encode(['ok' => true, 'bytes' => $bytes, 'updated' => $data['updated']]);
        }
        break;

    case 'status':
        // Status simples para verificar se API está funcionando
        echo json_encode([
            'ok' => true,
            'has_data' => file_exists($DATA_FILE),
            'size' => file_exists($DATA_FILE) ? filesize($DATA_FILE) : 0,
            'updated' => file_exists($DATA_FILE) ? date('c', filemtime($DATA_FILE)) : null
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida. Use: load, save, status']);
        break;
}
