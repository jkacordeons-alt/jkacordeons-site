<?php
/**
 * Bling OAuth2 Callback — JK Acordeons
 * Troca o authorization code por access_token automaticamente
 */

// Suas credenciais do Bling App
$client_id = '03fff514c8b98b898090d543fc055b2b817fbfa3';
$client_secret = '389d9a1738ed463e9e6699ca989199bb09591d4b0464d4202f5b1590c40d';
$redirect_uri = 'https://jkacordeons.com.br/bling_callback.php';

// Pega o code da URL
$code = isset($_GET['code']) ? $_GET['code'] : null;
$state = isset($_GET['state']) ? $_GET['state'] : '';

if (!$code) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>⚠️ Código de autorização não encontrado</h2><p>Acesse pela URL de autorização do Bling.</p></body></html>';
    exit;
}

// Trocar code por access_token
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
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect_uri
    ]),
    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h2>❌ Erro de conexão</h2><p>' . htmlspecialchars($error) . '</p></body></html>';
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !isset($data['access_token'])) {
    $errMsg = isset($data['error']['message']) ? $data['error']['message'] : (isset($data['error_description']) ? $data['error_description'] : 'Erro desconhecido');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;background:#1a1a2e;color:#eee">';
    echo '<h2>❌ Erro ao obter token</h2>';
    echo '<p style="color:#e74c3c">' . htmlspecialchars($errMsg) . '</p>';
    echo '<p style="color:#888;font-size:13px">HTTP ' . $httpCode . '</p>';
    echo '<p style="margin-top:30px"><a href="https://jkacordeons.com.br/admin.html" style="color:#3498db">← Voltar ao Admin</a></p>';
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
    'created_at' => time(),
    'expires_at' => time() + (isset($data['expires_in']) ? $data['expires_in'] : 21600)
];

// Salvar no servidor
$tokenFile = __DIR__ . '/bling_token.json';
file_put_contents($tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT));

// Página de sucesso que passa o token pro admin via localStorage
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bling Conectado — JK Acordeons</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#1a1a2e;color:#eee;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#16213e;border-radius:16px;padding:40px;max-width:480px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.icon{font-size:64px;margin-bottom:16px}
h1{font-size:22px;margin-bottom:8px;color:#2ecc71}
.sub{color:#95a5a6;font-size:14px;margin-bottom:24px}
.token-info{background:rgba(46,204,113,.08);border:1px solid rgba(46,204,113,.2);border-radius:8px;padding:14px;margin-bottom:20px;text-align:left;font-size:13px;line-height:1.8}
.token-info strong{color:#2ecc71}
.btn{display:inline-block;padding:12px 32px;background:#e74c3c;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:15px;transition:opacity .2s}
.btn:hover{opacity:.85}
.note{margin-top:16px;font-size:11px;color:#7f8c8d}
</style>
</head>
<body>
<div class="card">
  <div class="icon">✅</div>
  <h1>Bling conectado com sucesso!</h1>
  <p class="sub">Sua conta do Bling foi autorizada para o JK Acordeons Admin</p>
  <div class="token-info">
    <strong>Token:</strong> <?php echo substr($data['access_token'], 0, 20) . '...'; ?><br>
    <strong>Expira em:</strong> <?php echo round(($tokenData['expires_in'])/3600, 1); ?> horas<br>
    <strong>Escopos:</strong> <?php echo htmlspecialchars($tokenData['scope'] ?: 'todos solicitados'); ?>
  </div>
  <a href="admin.html" class="btn" id="btnVoltar">← Voltar ao Admin</a>
  <p class="note">O token foi salvo no servidor e no admin local.</p>
</div>
<script>
// Salvar token no localStorage do admin
try {
  var blingConfig = JSON.parse(localStorage.getItem('jk_bling_config') || '{}');
  blingConfig.access_token = <?php echo json_encode($data['access_token']); ?>;
  blingConfig.refresh_token = <?php echo json_encode($tokenData['refresh_token']); ?>;
  blingConfig.token_expires = <?php echo json_encode($tokenData['expires_at'] * 1000); ?>;
  blingConfig.scope = <?php echo json_encode($tokenData['scope']); ?>;
  blingConfig.connected_at = new Date().toISOString();
  localStorage.setItem('jk_bling_config', JSON.stringify(blingConfig));
} catch(e) {
  console.error('Erro ao salvar token:', e);
}
</script>
</body>
</html>
