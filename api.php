<?php
/**
 * API JK Acordeons Admin — v2
 * Autenticação por hash + persistência robusta
 */

// === CONFIG ===
$DATA_DIR  = __DIR__ . '/data';
$DATA_FILE = $DATA_DIR . '/admin_data.json';
$AUTH_FILE = $DATA_DIR . '/auth.json';
$API_KEY   = 'jk2024acordeons';

// Credenciais iniciais (hash gerado na primeira execução)
$DEFAULT_USER = 'jorgealexandrekotsko5@gmail.com';
$DEFAULT_PASS = 'Ad125478963*';

// === HEADERS ===
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Auth-Token');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// === DIRETÓRIO ===
if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0755, true);
}
// Proteger pasta data
$htaccess = $DATA_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\nOptions -Indexes\n");
}
// index.html vazio também
$indexHtml = $DATA_DIR . '/index.html';
if (!file_exists($indexHtml)) {
    file_put_contents($indexHtml, '');
}

// === AUTH SETUP (cria hash na primeira execução) ===
function getAuthData() {
    global $AUTH_FILE, $DEFAULT_USER, $DEFAULT_PASS;
    if (file_exists($AUTH_FILE)) {
        $data = json_decode(file_get_contents($AUTH_FILE), true);
        if ($data && isset($data['user'])) return $data;
    }
    // Primeira execução — criar arquivo de autenticação
    $data = [
        'user' => $DEFAULT_USER,
        'pass_hash' => password_hash($DEFAULT_PASS, PASSWORD_BCRYPT),
        'created' => date('c'),
        'login_attempts' => 0,
        'locked_until' => null
    ];
    file_put_contents($AUTH_FILE, json_encode($data, JSON_PRETTY_PRINT));
    return $data;
}

function saveAuthData($data) {
    global $AUTH_FILE;
    file_put_contents($AUTH_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// === RATE LIMITING (brute force protection) ===
function checkRateLimit(&$auth) {
    if ($auth['locked_until'] && time() < $auth['locked_until']) {
        $wait = $auth['locked_until'] - time();
        http_response_code(429);
        echo json_encode(['error' => 'Muitas tentativas. Tente novamente em ' . ceil($wait / 60) . ' minuto(s).', 'locked' => true]);
        exit;
    }
    // Reset se o lock expirou
    if ($auth['locked_until'] && time() >= $auth['locked_until']) {
        $auth['login_attempts'] = 0;
        $auth['locked_until'] = null;
        saveAuthData($auth);
    }
}

function recordFailedLogin(&$auth) {
    $auth['login_attempts'] = ($auth['login_attempts'] ?? 0) + 1;
    // Lockout progressivo: 5 tentativas = 5min, 10 = 15min, 15+ = 30min
    if ($auth['login_attempts'] >= 15) {
        $auth['locked_until'] = time() + 1800;
    } elseif ($auth['login_attempts'] >= 10) {
        $auth['locked_until'] = time() + 900;
    } elseif ($auth['login_attempts'] >= 5) {
        $auth['locked_until'] = time() + 300;
    }
    saveAuthData($auth);
}

// === SESSION TOKENS ===
function generateToken() {
    return bin2hex(random_bytes(32));
}

function getActiveSessions() {
    global $DATA_DIR;
    $file = $DATA_DIR . '/sessions.json';
    if (file_exists($file)) {
        $sessions = json_decode(file_get_contents($file), true);
        if (is_array($sessions)) return $sessions;
    }
    return [];
}

function saveActiveSessions($sessions) {
    global $DATA_DIR;
    file_put_contents($DATA_DIR . '/sessions.json', json_encode($sessions));
}

function createSession() {
    $token = generateToken();
    $sessions = getActiveSessions();
    // Limpar sessões expiradas (24h)
    $now = time();
    $sessions = array_filter($sessions, function($s) use ($now) {
        return ($now - $s['created']) < 86400;
    });
    $sessions[$token] = [
        'created' => $now,
        'last_used' => $now,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    saveActiveSessions($sessions);
    return $token;
}

function validateSession($token) {
    if (!$token || strlen($token) < 32) return false;
    $sessions = getActiveSessions();
    if (!isset($sessions[$token])) return false;
    $session = $sessions[$token];
    // Expirar após 24h
    if ((time() - $session['created']) > 86400) {
        unset($sessions[$token]);
        saveActiveSessions($sessions);
        return false;
    }
    // Atualizar last_used
    $sessions[$token]['last_used'] = time();
    saveActiveSessions($sessions);
    return true;
}

function destroySession($token) {
    $sessions = getActiveSessions();
    unset($sessions[$token]);
    saveActiveSessions($sessions);
}

// === VERIFICAR AUTENTICAÇÃO ===
$action = $_GET['action'] ?? '';

// Ações que NÃO precisam de sessão (apenas API key)
$publicActions = ['login', 'status'];
// Ações que precisam de sessão válida
$protectedActions = ['load', 'save', 'change_password', 'logout'];

if (in_array($action, $protectedActions)) {
    // Verificar token de sessão
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (!validateSession($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Sessão inválida ou expirada. Faça login novamente.', 'session_expired' => true]);
        exit;
    }
} else {
    // Verificar API key para ações públicas
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
    if ($apiKey !== $API_KEY) {
        http_response_code(401);
        echo json_encode(['error' => 'Chave de API inválida']);
        exit;
    }
}

// === AÇÕES ===
switch ($action) {

    case 'login':
        $input = json_decode(file_get_contents('php://input'), true);
        $user = trim($input['user'] ?? '');
        $pass = $input['pass'] ?? '';

        $auth = getAuthData();
        checkRateLimit($auth);

        if (strtolower($user) === strtolower($auth['user']) && password_verify($pass, $auth['pass_hash'])) {
            // Login OK — resetar tentativas
            $auth['login_attempts'] = 0;
            $auth['locked_until'] = null;
            $auth['last_login'] = date('c');
            saveAuthData($auth);

            $token = createSession();
            echo json_encode([
                'ok' => true,
                'token' => $token,
                'user' => $auth['user'],
                'expires_in' => 86400
            ]);
        } else {
            recordFailedLogin($auth);
            $remaining = max(0, 5 - ($auth['login_attempts'] ?? 0));
            http_response_code(401);
            echo json_encode([
                'error' => 'E-mail ou senha incorretos',
                'attempts_remaining' => $remaining > 0 ? $remaining : null,
                'locked' => ($auth['locked_until'] && time() < $auth['locked_until'])
            ]);
        }
        break;

    case 'logout':
        $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        destroySession($token);
        echo json_encode(['ok' => true]);
        break;

    case 'change_password':
        $input = json_decode(file_get_contents('php://input'), true);
        $currentPass = $input['current_pass'] ?? '';
        $newPass = $input['new_pass'] ?? '';

        if (strlen($newPass) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Nova senha deve ter pelo menos 8 caracteres']);
            exit;
        }

        $auth = getAuthData();
        if (!password_verify($currentPass, $auth['pass_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Senha atual incorreta']);
            exit;
        }

        $auth['pass_hash'] = password_hash($newPass, PASSWORD_BCRYPT);
        $auth['pass_changed'] = date('c');
        saveAuthData($auth);

        // Invalidar todas as sessões (forçar novo login)
        saveActiveSessions([]);

        echo json_encode(['ok' => true, 'message' => 'Senha alterada com sucesso']);
        break;

    case 'load':
        if (file_exists($DATA_FILE)) {
            $data = file_get_contents($DATA_FILE);
            echo $data;
        } else {
            echo json_encode(['produtos' => [], 'meta_config' => null, 'updated' => null]);
        }
        break;

    case 'save':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON inválido: ' . json_last_error_msg()]);
            exit;
        }

        $data['updated'] = date('c');

        // Backup antes de sobrescrever
        if (file_exists($DATA_FILE)) {
            $backupFile = $DATA_DIR . '/backup_' . date('Y-m-d_His') . '.json';
            copy($DATA_FILE, $backupFile);

            // Manter últimos 20 backups
            $backups = glob($DATA_DIR . '/backup_*.json');
            if (count($backups) > 20) {
                usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
                $toDelete = array_slice($backups, 0, count($backups) - 20);
                foreach ($toDelete as $old) {
                    unlink($old);
                }
            }
        }

        $bytes = file_put_contents($DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        if ($bytes === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao salvar arquivo']);
        } else {
            echo json_encode(['ok' => true, 'bytes' => $bytes, 'updated' => $data['updated']]);
        }
        break;

    case 'status':
        echo json_encode([
            'ok' => true,
            'has_data' => file_exists($DATA_FILE),
            'size' => file_exists($DATA_FILE) ? filesize($DATA_FILE) : 0,
            'updated' => file_exists($DATA_FILE) ? date('c', filemtime($DATA_FILE)) : null,
            'version' => '2.0'
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida']);
        break;
}
