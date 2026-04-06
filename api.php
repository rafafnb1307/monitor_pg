<?php
// ============================================
// api.php — compatível com PHP 7.4+
// ============================================
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
}

try {
    if      ($action === 'register' && $method === 'POST')   handleRegister($body);
    elseif  ($action === 'login'    && $method === 'POST')   handleLogin($body);
    elseif  ($action === 'logout'   && $method === 'POST')   handleLogout();
    elseif  ($action === 'me'       && $method === 'GET')    handleMe();
    elseif  ($action === 'payments' && $method === 'GET')    handleList();
    elseif  ($action === 'payments' && $method === 'POST')   handleCreate($body);
    elseif  ($action === 'payments' && $method === 'PUT')    handleUpdate($body);
    elseif  ($action === 'payments' && $method === 'DELETE') handleDelete($body);
    elseif  ($action === 'settings' && $method === 'GET')    handleGetSettings();
    elseif  ($action === 'settings' && $method === 'POST')   handleSaveSettings($body);
    else    respond(['ok' => false, 'error' => 'Ação inválida']);
} catch (Exception $e) {
    respond(['ok' => false, 'error' => $e->getMessage()]);
}

// ── Helpers ───────────────────────────────────────────────────────────────

function respond($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────

function handleRegister($b) {
    $nome  = trim(isset($b['name'])  ? $b['name']  : (isset($b['nome'])  ? $b['nome']  : ''));
    $email = strtolower(trim(isset($b['email']) ? $b['email'] : ''));
    $senha = isset($b['password']) ? $b['password'] : (isset($b['senha']) ? $b['senha'] : '');

    if (!$nome || !$email || !$senha)
        respond(['ok' => false, 'error' => 'Preencha nome, e-mail e senha.']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(['ok' => false, 'error' => 'E-mail inválido.']);
    if (strlen($senha) < 6)
        respond(['ok' => false, 'error' => 'Senha deve ter ao menos 6 caracteres.']);

    $db = db();
    $st = $db->prepare('SELECT id FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch())
        respond(['ok' => false, 'error' => 'E-mail já cadastrado.']);

    $hash = password_hash($senha, PASSWORD_BCRYPT);
    $st   = $db->prepare('INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)');
    $st->execute([$nome, $email, $hash]);
    $uid = (int) $db->lastInsertId();

    $db->prepare('INSERT IGNORE INTO configuracoes (usuario_id, global_days_before) VALUES (?, 2)')
       ->execute([$uid]);

    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $uid;
    $_SESSION['user_nome'] = $nome;

    respond(['ok' => true, 'user' => ['id' => $uid, 'name' => $nome, 'email' => $email]]);
}

function handleLogin($b) {
    $email = strtolower(trim(isset($b['email']) ? $b['email'] : ''));
    $senha = isset($b['password']) ? $b['password'] : (isset($b['senha']) ? $b['senha'] : '');

    if (!$email || !$senha)
        respond(['ok' => false, 'error' => 'Informe e-mail e senha.']);

    $st = db()->prepare('SELECT id, nome, senha FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || !password_verify($senha, $user['senha']))
        respond(['ok' => false, 'error' => 'E-mail ou senha incorretos.']);

    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_nome'] = $user['nome'];

    respond(['ok' => true, 'user' => ['id' => $user['id'], 'name' => $user['nome'], 'email' => $email]]);
}

function handleLogout() {
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    respond(['ok' => true]);
}

function handleMe() {
    startSession();
    if (empty($_SESSION['user_id']))
        respond(['ok' => false, 'authed' => false]);

    $st = db()->prepare('SELECT id, nome, email FROM usuarios WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u) respond(['ok' => false, 'authed' => false]);

    respond(['ok' => true, 'authed' => true, 'user' => [
        'id'    => (int) $u['id'],
        'name'  => $u['nome'],
        'email' => $u['email'],
    ]]);
}

// ── Pagamentos ────────────────────────────────────────────────────────────

function handleList() {
    $uid = authUser();
    $st  = db()->prepare('SELECT * FROM pagamentos WHERE usuario_id = ? ORDER BY ano, mes, dia');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int)   $r['id'];
        $r['amount']     = (float) $r['amount'];
        $r['dia']        = (int)   $r['dia'];
        $r['mes']        = (int)   $r['mes'];
        $r['ano']        = (int)   $r['ano'];
        $r['alert_days'] = $r['alert_days'] !== null ? (int) $r['alert_days'] : null;
        $r['pago']       = (bool)  $r['pago'];
        unset($r['usuario_id']);
    }
    respond(['ok' => true, 'payments' => $rows]);
}

function handleCreate($b) {
    $uid       = authUser();
    $nome      = trim(isset($b['nome']) ? $b['nome'] : '');
    $amount    = (float) (isset($b['amount'])    ? $b['amount']    : 0);
    $dia       = (int)   (isset($b['dia'])       ? $b['dia']       : 0);
    $mes       = (int)   (isset($b['mes'])       ? $b['mes']       : 0);
    $ano       = (int)   (isset($b['ano'])       ? $b['ano']       : 0);
    $categoria = trim(isset($b['categoria']) ? $b['categoria'] : 'outros');
    $alertDays = (isset($b['alert_days']) && $b['alert_days'] !== '' && $b['alert_days'] !== null)
                 ? (int) $b['alert_days'] : null;

    if (!$nome || $amount <= 0 || $dia < 1 || $dia > 31 || $mes < 0 || $mes > 11 || $ano < 2020)
        respond(['ok' => false, 'error' => 'Dados inválidos.']);

    $st = db()->prepare(
        'INSERT INTO pagamentos (usuario_id, nome, amount, dia, mes, ano, categoria, alert_days, pago)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $st->execute([$uid, $nome, $amount, $dia, $mes, $ano, $categoria, $alertDays]);

    respond(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}

function handleUpdate($b) {
    $uid = authUser();
    $id  = (int) (isset($b['id']) ? $b['id'] : 0);
    if (!$id) respond(['ok' => false, 'error' => 'ID inválido.']);

    $st = db()->prepare('SELECT id FROM pagamentos WHERE id = ? AND usuario_id = ?');
    $st->execute([$id, $uid]);
    if (!$st->fetch()) respond(['ok' => false, 'error' => 'Pagamento não encontrado.']);

    $fields = [];
    $params = [];

    if (isset($b['pago']))      { $fields[] = 'pago=?';      $params[] = $b['pago'] ? 1 : 0; }
    if (isset($b['nome']))      { $fields[] = 'nome=?';      $params[] = trim($b['nome']); }
    if (isset($b['amount']))    { $fields[] = 'amount=?';    $params[] = (float) $b['amount']; }
    if (isset($b['dia']))       { $fields[] = 'dia=?';       $params[] = (int) $b['dia']; }
    if (isset($b['mes']))       { $fields[] = 'mes=?';       $params[] = (int) $b['mes']; }
    if (isset($b['ano']))       { $fields[] = 'ano=?';       $params[] = (int) $b['ano']; }
    if (isset($b['categoria'])) { $fields[] = 'categoria=?'; $params[] = trim($b['categoria']); }
    if (array_key_exists('alert_days', $b)) {
        $fields[] = 'alert_days=?';
        $params[] = ($b['alert_days'] !== null && $b['alert_days'] !== '') ? (int) $b['alert_days'] : null;
    }

    if (!$fields) respond(['ok' => false, 'error' => 'Nada para atualizar.']);

    $params[] = $id;
    db()->prepare('UPDATE pagamentos SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    respond(['ok' => true]);
}

function handleDelete($b) {
    $uid = authUser();
    $id  = (int) (isset($b['id']) ? $b['id'] : 0);
    if (!$id) respond(['ok' => false, 'error' => 'ID inválido.']);

    $st = db()->prepare('DELETE FROM pagamentos WHERE id = ? AND usuario_id = ?');
    $st->execute([$id, $uid]);
    if ($st->rowCount() === 0)
        respond(['ok' => false, 'error' => 'Pagamento não encontrado.']);

    respond(['ok' => true]);
}

// ── Configurações ─────────────────────────────────────────────────────────

function handleGetSettings() {
    $uid = authUser();
    $st  = db()->prepare('SELECT global_days_before, notif_enabled FROM configuracoes WHERE usuario_id = ?');
    $st->execute([$uid]);
    $cfg = $st->fetch();
    if (!$cfg) {
        db()->prepare('INSERT IGNORE INTO configuracoes (usuario_id, global_days_before) VALUES (?, 2)')
            ->execute([$uid]);
        $cfg = ['global_days_before' => 2, 'notif_enabled' => 0];
    }
    respond(['ok' => true, 'settings' => [
        'globalDaysBefore' => (int)  $cfg['global_days_before'],
        'notifEnabled'     => (bool) $cfg['notif_enabled'],
    ]]);
}

function handleSaveSettings($b) {
    $uid   = authUser();
    $days  = max(0, (int) (isset($b['globalDaysBefore']) ? $b['globalDaysBefore'] : 2));
    $notif = !empty($b['notifEnabled']) ? 1 : 0;

    db()->prepare(
        'INSERT INTO configuracoes (usuario_id, global_days_before, notif_enabled)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE global_days_before = VALUES(global_days_before),
                                 notif_enabled = VALUES(notif_enabled)'
    )->execute([$uid, $days, $notif]);

    respond(['ok' => true]);
}