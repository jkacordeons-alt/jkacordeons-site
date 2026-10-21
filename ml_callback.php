<?php
/**
 * Mercado Livre OAuth2 Callback — JK Acordeons
 * Troca o authorization code por access_token automaticamente
 *
 * IMPORTANTE: As credenciais (app_id e client_secret) são recebidas
 * do ml_config.json salvo pelo admin. Diferente do Bling, o ML não usa Basic auth.
 */

// Carregar credenciais do app
$configFile = __DIR__ . '/ml_config.json';
if (!file_exists($configFile)) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;background:#1a1a2e;color:#eee"><h2>⚠️ Configuração não encontrada</h2><p>Salve as credenciais do Mercado Livre no Admin primeiro.</p><p style="margin-top:30px"><a href="admin.html" style="color:#ffe600">← Voltar ao Admin</a></p></body></html>';
    exit;
}
$config = json_decode(file_get_contents($configFile), true);
$app_id = isset($config['app_id']) ? $config['app_id'] : '';
$client_secret = isset($config['client_secret']) ? $config['client_secret'] : '';
$redirect_uri = isset($config['redirect_uri']) ? $config['redirect_uri'] : 'https://jkacordeons.com.br/ml_callback.php';

if (!$app_id || !$client_secret) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;background:#1a1a2e;color:#eee"><h2>⚠️ Credenciais incompletas</h2><p>Configure o App ID e Client Secret no Admin.</p><p style="margin-top:30px"><a href="admin.html" style="color:#ffe600">← Voltar ao Admin</a></p></body></html>';
    exit;
}

// Pega o code da URL
$code = isset($_GET['code']) ? $_GET['code'] : null;

if (!$code) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;background:#1a1a2e;color:#eee"><h2>⚠️ Código de autorização não encontrado</h2><p>Acesse pela URL de autorização do Mercado Livre.</p></body></html>';
    exit;
}

// Trocar code por access_token
$ch = curl_init('https://api.mercadolibre.com/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ],
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $app_id,
        'client_secret' => $client_secret,
        'code' => $code,
        'redirect_uri' => $redirect_uri,
        'code_verifier' => isset($config['code_verifier']) ? $config['code_verifier'] : ''
    ]),
    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;background:#1a1a2e;color:#eee"><h2>❌ Erro de conexão</h2><p>' . htmlspecialchars($error) . '</p></body></html>';
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !isset($data['access_token'])) {
    $errMsg = isset($data['message']) ? $data['message'] : (isset($data['error']) ? $data['error'] : 'Erro desconhecido');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;background:#1a1a2e;color:#eee">';
    echo '<h2>❌ Erro ao obter token</h2>';
    echo '<p style="color:#e74c3c">' . htmlspecialchars($errMsg) . '</p>';
    echo '<p style="color:#888;font-size:13px">HTTP ' . $httpCode . '</p>';
    echo '<p style="margin-top:30px"><a href="admin.html" style="color:#ffe600">← Voltar ao Admin</a></p>';
    echo '</body></html>';
    exit;
}

// Sucesso! Salvar token em arquivo local (seguro no servidor)
$tokenData = [
    'access_token' => $data['access_token'],
    'refresh_token' => isset($data['refresh_token']) ? $data['refresh_token'] : null,
    'token_type' => isset($data['token_type']) ? $data['token_type'] : 'Bearer',
    'expires_in' => isset($data['expires_in']) ? $data['expires_in'] : 21600,
    'scope' => isset($data['scope']) ? $data['scope'] : '',
    'user_id' => isset($data['user_id']) ? $data['user_id'] : null,
    'created_at' => time(),
    'expires_at' => time() + (isset($data['expires_in']) ? $data['expires_in'] : 21600)
];

// Salvar no servidor
$tokenFile = __DIR__ . '/ml_token.json';
file_put_contents($tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT));

// Página de sucesso que passa o token pro admin via localStorage
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mercado Livre Conectado — JK Acordeons</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#1a1a2e;color:#eee;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#16213e;border-radius:16px;padding:40px;max-width:480px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.icon{font-size:64px;margin-bottom:16px}
h1{font-size:22px;margin-bottom:8px;color:#ffe600}
.sub{color:#95a5a6;font-size:14px;margin-bottom:24px}
.token-info{background:rgba(255,230,0,.08);border:1px solid rgba(255,230,0,.2);border-radius:8px;padding:14px;margin-bottom:20px;text-align:left;font-size:13px;line-height:1.8}
.token-info strong{color:#ffe600}
.btn{display:inline-block;padding:12px 32px;background:#ffe600;color:#333;text-decoration:none;border-radius:8px;font-weight:700;font-size:15px;transition:opacity .2s}
.btn:hover{opacity:.85}
.note{margin-top:16px;font-size:11px;color:#7f8c8d}
</style>
</head>
<body>
<div class="card">
  <div class="icon">✅</div>
  <h1>Mercado Livre conectado!</h1>
  <p class="sub">Sua conta do ML foi autorizada para o JK Acordeons Admin</p>
  <div class="token-info">
    <strong>Token:</strong> <?php echo substr($data['access_token'], 0, 20) . '...'; ?><br>
    <strong>Expira em:</strong> <?php echo round(($tokenData['expires_in'])/3600, 1); ?> horas<br>
    <strong>User ID:</strong> <?php echo htmlspecialchars($tokenData['user_id'] ?: 'N/A'); ?>
  </div>
  <a href="admin.html" class="btn" id="btnVoltar">← Voltar ao Admin</a>
  <p class="note">O token foi salvo no servidor e no admin local.</p>
</div>
<script>
// Salvar token no localStorage do admin
try {
  var mlConfig = JSON.parse(localStorage.getItem('jk_ml_config') || '{}');
  mlConfig.access_token = <?php echo json_encode($data['access_token']); ?>;
  mlConfig.refresh_token = <?php echo json_encode($tokenData['refresh_token']); ?>;
  mlConfig.token_expires = <?php echo json_encode($tokenData['expires_at'] * 1000); ?>;
  mlConfig.seller_id = <?php echo json_encode($tokenData['user_id']); ?>;
  mlConfig.connected_at = new Date().toISOString();
  localStorage.setItem('jk_ml_config', JSON.stringify(mlConfig));
} catch(e) {
  console.error('Erro ao salvar token ML:', e);
}
</script>
</body>
</html>
