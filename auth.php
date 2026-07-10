<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

/* ══════════════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════════════ */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function auth_redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function auth_flash(string $msg, string $type = 'ok'): void {
    $_SESSION['_auth_flash'] = ['msg' => $msg, 'type' => $type];
}

function auth_get_flash(): ?array {
    if (!empty($_SESSION['_auth_flash'])) {
        $f = $_SESSION['_auth_flash'];
        unset($_SESSION['_auth_flash']);
        return $f;
    }
    return null;
}

function auth_csrf_field(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
}

function auth_verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Requisição inválida.');
    }
}

function make_token(): string {
    return bin2hex(random_bytes(32));
}

function create_session(mysqli $db, int $reader_id): void {
    $token     = make_token();
    $expires   = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30); // 30 dias
    $st = mysqli_prepare($db,
        'INSERT INTO reader_sessions (reader_id, token, expires_at) VALUES (?,?,?)');
    mysqli_stmt_bind_param($st, 'iss', $reader_id, $token, $expires);
    mysqli_execute($st);
    mysqli_stmt_close($st);

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    setcookie('rsid', $token, [
        'expires'  => time() + 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function destroy_session(mysqli $db): void {
    $token = $_COOKIE['rsid'] ?? '';
    if ($token !== '') {
        $st = mysqli_prepare($db, 'DELETE FROM reader_sessions WHERE token = ?');
        mysqli_stmt_bind_param($st, 's', $token);
        mysqli_execute($st);
        mysqli_stmt_close($st);
        setcookie('rsid', '', time() - 3600, '/');
    }
}

function reader_by_id(mysqli $db, int $id): ?array {
    $st = mysqli_prepare($db,
        'SELECT id, username, email, email_verified, trusted_at, new_email FROM readers WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) mysqli_free_result($res);
    mysqli_stmt_close($st);
    return $row ?: null;
}

/* ══════════════════════════════════════════════════════════════════════
   RENDER WRAPPER
══════════════════════════════════════════════════════════════════════ */

function auth_wrap(string $title, string $body, ?array $flash, string $site_name): void {
    $GLOBALS['_settings'] = load_settings($GLOBALS['db']);
    $accent = $GLOBALS['_settings']['accent_color'] ?? '#2e7d52';
    ?>
<!DOCTYPE html>
<html lang="pt" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?> — <?= h($site_name) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    :root { --accent: <?= h($accent) ?>; }
    .auth-wrap { max-width: 420px; margin: 3rem auto; padding: 0 1rem; }
    .auth-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 2rem; }
    .auth-card h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: 1.5rem; }
    .auth-field { margin-bottom: 1.1rem; }
    .auth-field label { display: block; font-size: .85rem; color: var(--text-muted); margin-bottom: .3rem; }
    .auth-field input { width: 100%; box-sizing: border-box; padding: .55rem .75rem;
      border: 1px solid var(--border); border-radius: 8px; font-size: 1rem;
      background: var(--bg); color: var(--text); }
    .auth-field input:focus { outline: none; border-color: var(--accent); }
    .auth-btn { display: block; width: 100%; padding: .65rem; border: none; border-radius: 8px;
      background: var(--accent); color: #fff; font-size: 1rem; font-weight: 600;
      cursor: pointer; margin-top: 1.2rem; }
    .auth-btn:hover { opacity: .9; }
    .auth-links { margin-top: 1.2rem; font-size: .85rem; color: var(--text-muted); text-align: center; }
    .auth-links a { color: var(--accent); }
    .auth-flash { padding: .7rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .9rem; }
    .auth-flash-ok  { background: #d1fae5; color: #065f46; }
    .auth-flash-err { background: #fee2e2; color: #991b1b; }
    .auth-section-title { font-size: .8rem; font-weight: 600; color: var(--text-muted);
      text-transform: uppercase; letter-spacing: .05em; margin: 1.5rem 0 .8rem; }
    .auth-hint { font-size: .78rem; color: var(--text-muted); margin-top: .3rem; }
    .back-link { display: inline-block; font-size: .85rem; color: var(--text-muted); margin-bottom: 1.5rem; }
    .back-link:hover { color: var(--accent); }
  </style>
  <script>
    (function(){
      var t = localStorage.getItem('stories_theme') || 'light';
      document.documentElement.setAttribute('data-theme', t);
    }());
  </script>
</head>
<body>
<div class="auth-wrap">
  <a href="index.php" class="back-link">← <?= h($site_name) ?></a>
  <div class="auth-card">
    <h1><?= h($title) ?></h1>
    <?php if ($flash): ?>
    <div class="auth-flash auth-flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <?= $body ?>
  </div>
</div>
</body>
</html>
<?php
}

/* ══════════════════════════════════════════════════════════════════════
   ROTEAMENTO
══════════════════════════════════════════════════════════════════════ */

$GLOBALS['_settings'] = load_settings($db);
$site_name = $GLOBALS['_settings']['site_name'] ?: SITE_NAME;
$action    = trim($_GET['a'] ?? '');
$reader    = current_reader($db);
$flash     = auth_get_flash();

/* ── Logout ──────────────────────────────────────────────────────────── */
if ($action === 'logout') {
    destroy_session($db);
    auth_redirect('index.php');
}

/* ── Registro ────────────────────────────────────────────────────────── */
if ($action === 'register') {
    if ($reader) auth_redirect('auth.php?a=profile');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        auth_verify_csrf();
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $confirm  = $_POST['confirm']       ?? '';

        $err = null;
        if ($username === '' || $email === '' || $password === '') {
            $err = 'Preencha todos os campos.';
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $err = 'Username deve ter entre 3 e 50 caracteres.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            $err = 'Username só pode conter letras, números, _ e -.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'E-mail inválido.';
        } elseif (strlen($password) < 6) {
            $err = 'Senha deve ter pelo menos 6 caracteres.';
        } elseif ($password !== $confirm) {
            $err = 'As senhas não coincidem.';
        } else {
            // Verificar duplicatas
            $st = mysqli_prepare($db, 'SELECT id FROM readers WHERE username = ? OR email = ? LIMIT 1');
            mysqli_stmt_bind_param($st, 'ss', $username, $email);
            mysqli_execute($st);
            $res = mysqli_stmt_get_result($st);
            $dup = mysqli_fetch_assoc($res);
            mysqli_free_result($res);
            mysqli_stmt_close($st);
            if ($dup) $err = 'Username ou e-mail já cadastrado.';
        }

        if (!$err) {
            $hash    = password_hash($password, PASSWORD_DEFAULT);
            $token   = make_token();
            $expires = date('Y-m-d H:i:s', time() + 3600 * 24); // 24h

            $st = mysqli_prepare($db,
                'INSERT INTO readers (username, email, password_hash, verify_token, token_expires_at)
                 VALUES (?,?,?,?,?)');
            mysqli_stmt_bind_param($st, 'sssss', $username, $email, $hash, $token, $expires);
            mysqli_execute($st);
            $new_id = (int)mysqli_insert_id($db);
            mysqli_stmt_close($st);

            if ($new_id > 0) {
                // Tenta enviar e-mail de verificação
                $verify_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                    . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/auth.php?a=verify&token=' . $token;
                send_mail($db, $email,
                    'Confirme seu e-mail — ' . $site_name,
                    auth_email_layout(
                        'Confirme seu e-mail',
                        '<p>Olá, ' . h($username) . '.</p>'
                        . '<p>Clique no botão abaixo para confirmar seu e-mail e ativar sua conta.</p>',
                        'Confirmar e-mail',
                        $verify_url,
                        $site_name
                    )
                );
                auth_flash('Conta criada! Verifique seu e-mail para ativar.');
                auth_redirect('auth.php?a=login');
            } else {
                $err = 'Erro ao criar conta. Tente novamente.';
            }
        }

        if ($err) {
            $flash = ['msg' => $err, 'type' => 'err'];
        }
    }

    ob_start(); ?>
    <form method="post">
      <?= auth_csrf_field() ?>
      <div class="auth-field">
        <label>Username</label>
        <input type="text" name="username" maxlength="50" autocomplete="username" required
               value="<?= h($_POST['username'] ?? '') ?>">
        <p class="auth-hint">Letras, números, _ e - apenas.</p>
      </div>
      <div class="auth-field">
        <label>E-mail</label>
        <input type="email" name="email" autocomplete="email" required
               value="<?= h($_POST['email'] ?? '') ?>">
      </div>
      <div class="auth-field">
        <label>Senha <span style="color:var(--text-muted);font-size:.8rem">(mín. 6 caracteres)</span></label>
        <input type="password" name="password" autocomplete="new-password" required minlength="6">
      </div>
      <div class="auth-field">
        <label>Confirmar senha</label>
        <input type="password" name="confirm" autocomplete="new-password" required>
      </div>
      <button type="submit" class="auth-btn">Criar conta</button>
    </form>
    <div class="auth-links">Já tem conta? <a href="auth.php?a=login">Entrar</a></div>
    <?php
    auth_wrap('Cadastro', ob_get_clean(), $flash, $site_name);
    exit;
}

/* ── Verificação de e-mail ────────────────────────────────────────────── */
if ($action === 'verify') {
    $token = trim($_GET['token'] ?? '');
    $ok = false;

    if ($token !== '') {
        $st = mysqli_prepare($db,
            'SELECT id FROM readers
             WHERE verify_token = ? AND token_expires_at > NOW() AND email_verified = 0
             LIMIT 1');
        mysqli_stmt_bind_param($st, 's', $token);
        mysqli_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) mysqli_free_result($res);
        mysqli_stmt_close($st);

        if ($row) {
            $rid = (int)$row['id'];
            $st2 = mysqli_prepare($db,
                'UPDATE readers SET email_verified=1, verify_token=NULL, token_expires_at=NULL WHERE id=?');
            mysqli_stmt_bind_param($st2, 'i', $rid);
            mysqli_execute($st2);
            mysqli_stmt_close($st2);
            create_session($db, $rid);
            $ok = true;
        }
    }

    if ($ok) {
        auth_flash('E-mail confirmado! Bem-vindo(a).');
        auth_redirect('index.php');
    }

    ob_start(); ?>
    <p>Link inválido ou expirado. <a href="auth.php?a=register">Cadastre-se novamente</a>.</p>
    <?php
    auth_wrap('Verificação de e-mail', ob_get_clean(), $flash, $site_name);
    exit;
}

/* ── Verificação de novo e-mail (troca no perfil) ────────────────────── */
if ($action === 'verify_new_email') {
    $token = trim($_GET['token'] ?? '');
    $ok = false;

    if ($token !== '') {
        $st = mysqli_prepare($db,
            'SELECT id, new_email FROM readers
             WHERE new_email_token = ? AND new_email_expires_at > NOW()
             LIMIT 1');
        mysqli_stmt_bind_param($st, 's', $token);
        mysqli_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) mysqli_free_result($res);
        mysqli_stmt_close($st);

        if ($row && $row['new_email']) {
            $rid = (int)$row['id'];
            $new = $row['new_email'];
            $st2 = mysqli_prepare($db,
                'UPDATE readers SET email=?, new_email=NULL, new_email_token=NULL, new_email_expires_at=NULL WHERE id=?');
            mysqli_stmt_bind_param($st2, 'si', $new, $rid);
            mysqli_execute($st2);
            mysqli_stmt_close($st2);
            $ok = true;
        }
    }

    if ($ok) {
        auth_flash('E-mail atualizado com sucesso!');
        auth_redirect('auth.php?a=profile');
    }

    ob_start(); ?>
    <p>Link inválido ou expirado. <a href="auth.php?a=profile">Voltar ao perfil</a>.</p>
    <?php
    auth_wrap('Troca de e-mail', ob_get_clean(), $flash, $site_name);
    exit;
}

/* ── Login ───────────────────────────────────────────────────────────── */
if ($action === 'login' || $action === '') {
    if ($reader) auth_redirect('index.php');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        auth_verify_csrf();
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password'] ?? '';
        $err = null;

        if ($login === '' || $password === '') {
            $err = 'Preencha todos os campos.';
        } else {
            // Aceita username ou e-mail
            $st = mysqli_prepare($db,
                'SELECT id, password_hash, email_verified FROM readers
                 WHERE username = ? OR email = ? LIMIT 1');
            mysqli_stmt_bind_param($st, 'ss', $login, $login);
            mysqli_execute($st);
            $res = mysqli_stmt_get_result($st);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if ($res) mysqli_free_result($res);
            mysqli_stmt_close($st);

            if (!$row || !password_verify($password, $row['password_hash'])) {
                $err = 'Usuário ou senha incorretos.';
            } elseif (!$row['email_verified']) {
                $err = 'E-mail não confirmado. Verifique sua caixa de entrada.';
            } else {
                create_session($db, (int)$row['id']);
                auth_redirect('index.php');
            }
        }

        if ($err) $flash = ['msg' => $err, 'type' => 'err'];
    }

    ob_start(); ?>
    <form method="post">
      <?= auth_csrf_field() ?>
      <div class="auth-field">
        <label>Username ou e-mail</label>
        <input type="text" name="login" autocomplete="username" required
               value="<?= h($_POST['login'] ?? '') ?>">
      </div>
      <div class="auth-field">
        <label>Senha</label>
        <input type="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="auth-btn">Entrar</button>
    </form>
    <div class="auth-links">Sem conta? <a href="auth.php?a=register">Cadastre-se</a></div>
    <?php
    auth_wrap('Entrar', ob_get_clean(), $flash, $site_name);
    exit;
}

/* ── Perfil ──────────────────────────────────────────────────────────── */
if ($action === 'profile') {
    if (!$reader) auth_redirect('auth.php?a=login');

    $me = reader_by_id($db, (int)$reader['id']);
    if (!$me) auth_redirect('auth.php?a=logout');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        auth_verify_csrf();
        $sub = $_POST['_sub'] ?? '';

        /* Alterar username */
        if ($sub === 'username') {
            $new_username = trim($_POST['username'] ?? '');
            $err = null;
            if ($new_username === '') {
                $err = 'Username não pode ser vazio.';
            } elseif (strlen($new_username) < 3 || strlen($new_username) > 50) {
                $err = 'Username deve ter entre 3 e 50 caracteres.';
            } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $new_username)) {
                $err = 'Username só pode conter letras, números, _ e -.';
            } else {
                $st = mysqli_prepare($db, 'SELECT id FROM readers WHERE username = ? AND id != ? LIMIT 1');
                mysqli_stmt_bind_param($st, 'si', $new_username, $me['id']);
                mysqli_execute($st);
                $res = mysqli_stmt_get_result($st);
                $dup = mysqli_fetch_assoc($res);
                mysqli_free_result($res); mysqli_stmt_close($st);
                if ($dup) $err = 'Username já em uso.';
            }
            if (!$err) {
                $st = mysqli_prepare($db, 'UPDATE readers SET username=? WHERE id=?');
                mysqli_stmt_bind_param($st, 'si', $new_username, $me['id']);
                mysqli_execute($st); mysqli_stmt_close($st);
                auth_flash('Username atualizado.');
            } else {
                auth_flash($err, 'err');
            }
            auth_redirect('auth.php?a=profile');
        }

        /* Alterar senha */
        if ($sub === 'password') {
            $current  = $_POST['current_password'] ?? '';
            $new_pw   = $_POST['new_password']     ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';
            $err = null;

            $st = mysqli_prepare($db, 'SELECT password_hash FROM readers WHERE id=? LIMIT 1');
            mysqli_stmt_bind_param($st, 'i', $me['id']);
            mysqli_execute($st);
            $res = mysqli_stmt_get_result($st);
            $row = mysqli_fetch_assoc($res);
            mysqli_free_result($res); mysqli_stmt_close($st);

            if (!$row || !password_verify($current, $row['password_hash'])) {
                $err = 'Senha atual incorreta.';
            } elseif (strlen($new_pw) < 6) {
                $err = 'Nova senha deve ter pelo menos 6 caracteres.';
            } elseif ($new_pw !== $confirm) {
                $err = 'As senhas não coincidem.';
            }

            if (!$err) {
                $hash = password_hash($new_pw, PASSWORD_DEFAULT);
                $st = mysqli_prepare($db, 'UPDATE readers SET password_hash=? WHERE id=?');
                mysqli_stmt_bind_param($st, 'si', $hash, $me['id']);
                mysqli_execute($st); mysqli_stmt_close($st);
                auth_flash('Senha atualizada.');
            } else {
                auth_flash($err, 'err');
            }
            auth_redirect('auth.php?a=profile');
        }

        /* Solicitar troca de e-mail */
        if ($sub === 'email') {
            $new_email = trim($_POST['new_email'] ?? '');
            $err = null;
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $err = 'E-mail inválido.';
            } elseif ($new_email === $me['email']) {
                $err = 'O novo e-mail é igual ao atual.';
            } else {
                $st = mysqli_prepare($db, 'SELECT id FROM readers WHERE email = ? AND id != ? LIMIT 1');
                mysqli_stmt_bind_param($st, 'si', $new_email, $me['id']);
                mysqli_execute($st);
                $res = mysqli_stmt_get_result($st);
                $dup = mysqli_fetch_assoc($res);
                mysqli_free_result($res); mysqli_stmt_close($st);
                if ($dup) $err = 'E-mail já cadastrado por outro usuário.';
            }

            if (!$err) {
                $token   = make_token();
                $expires = date('Y-m-d H:i:s', time() + 3600 * 24);
                $st = mysqli_prepare($db,
                    'UPDATE readers SET new_email=?, new_email_token=?, new_email_expires_at=? WHERE id=?');
                mysqli_stmt_bind_param($st, 'sssi', $new_email, $token, $expires, $me['id']);
                mysqli_execute($st); mysqli_stmt_close($st);

                $verify_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                    . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/auth.php?a=verify_new_email&token=' . $token;
                send_mail($db, $new_email,
                    'Confirme seu novo e-mail — ' . $site_name,
                    auth_email_layout(
                        'Confirme seu novo e-mail',
                        '<p>Recebemos um pedido para trocar o e-mail da sua conta em ' . h($site_name) . '.</p>'
                        . '<p>Seu e-mail atual (<strong>' . h($me['email']) . '</strong>) continuará ativo até a confirmação.</p>',
                        'Confirmar novo e-mail',
                        $verify_url,
                        $site_name
                    )
                );
                auth_flash('Enviamos um link de confirmação para ' . $new_email . '.');
            } else {
                auth_flash($err, 'err');
            }
            auth_redirect('auth.php?a=profile');
        }
    }

    // Re-lê após possível redirect
    $me = reader_by_id($db, (int)$reader['id']);
    $flash = auth_get_flash();

    ob_start(); ?>
    <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:1.5rem">
      Conta criada para leitura e comentários.
    </p>

    <!-- Username -->
    <div class="auth-section-title">Alterar username</div>
    <form method="post">
      <?= auth_csrf_field() ?>
      <input type="hidden" name="_sub" value="username">
      <div class="auth-field">
        <label>Novo username</label>
        <input type="text" name="username" maxlength="50" required
               value="<?= h($me['username']) ?>">
      </div>
      <button type="submit" class="auth-btn" style="margin-top:.5rem">Salvar username</button>
    </form>

    <!-- Senha -->
    <div class="auth-section-title">Alterar senha</div>
    <form method="post">
      <?= auth_csrf_field() ?>
      <input type="hidden" name="_sub" value="password">
      <div class="auth-field">
        <label>Senha atual</label>
        <input type="password" name="current_password" autocomplete="current-password" required>
      </div>
      <div class="auth-field">
        <label>Nova senha</label>
        <input type="password" name="new_password" autocomplete="new-password" required minlength="6">
      </div>
      <div class="auth-field">
        <label>Confirmar nova senha</label>
        <input type="password" name="confirm_password" autocomplete="new-password" required>
      </div>
      <button type="submit" class="auth-btn" style="margin-top:.5rem">Salvar senha</button>
    </form>

    <!-- E-mail -->
    <div class="auth-section-title">Trocar e-mail</div>
    <p class="auth-hint" style="margin-bottom:.8rem">
      E-mail atual: <strong><?= h($me['email']) ?></strong>
      <?php if ($me['new_email']): ?>
      <br>Aguardando confirmação para: <em><?= h($me['new_email']) ?></em>
      <?php endif; ?>
    </p>
    <form method="post">
      <?= auth_csrf_field() ?>
      <input type="hidden" name="_sub" value="email">
      <div class="auth-field">
        <label>Novo e-mail</label>
        <input type="email" name="new_email" autocomplete="email" required>
      </div>
      <button type="submit" class="auth-btn" style="margin-top:.5rem">Solicitar troca</button>
    </form>

    <div style="margin-top:2rem;padding-top:1rem;border-top:1px solid var(--border)">
      <a href="auth.php?a=logout" style="color:var(--text-muted);font-size:.85rem">Sair da conta</a>
    </div>
    <?php
    auth_wrap('Meu perfil — ' . $me['username'], ob_get_clean(), $flash, $site_name);
    exit;
}

// Fallback
auth_redirect('auth.php?a=login');

/* ══════════════════════════════════════════════════════════════════════
   FUNÇÕES DE E-MAIL
══════════════════════════════════════════════════════════════════════ */

function auth_email_layout(string $titulo, string $body_html, string $cta_label, string $cta_url, string $site_name): string {
    $cta = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">'
         . '<tr><td style="border-radius:8px;background:#059669;">'
         . '<a href="' . h($cta_url) . '" style="display:inline-block;padding:10px 20px;font-size:14px;'
         . 'font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">' . h($cta_label) . '</a>'
         . '</td></tr></table>';

    return '<!doctype html><html lang="pt"><head><meta charset="UTF-8"></head>'
         . '<body style="margin:0;padding:0;background:#f6f7f8;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f8;padding:32px 16px;">'
         . '<tr><td align="center">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'
         . '<tr><td style="padding:0 4px 20px;font-weight:700;color:#111827;font-size:15px;">' . h($site_name) . '</td></tr>'
         . '<tr><td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;">'
         . '<h1 style="margin:0 0 16px;font-size:18px;color:#111827;">' . h($titulo) . '</h1>'
         . $body_html
         . $cta
         . '</td></tr>'
         . '<tr><td style="padding:20px 4px 0;color:#9ca3af;font-size:12px;">'
         . 'Você recebeu este e-mail por ter se cadastrado em ' . h($site_name) . '.'
         . '</td></tr>'
         . '</table></td></tr></table>'
         . '</body></html>';
}
