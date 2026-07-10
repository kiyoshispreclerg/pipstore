<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ══════════════════════════════════════════════════════════════════════
   AUTENTICAÇÃO
══════════════════════════════════════════════════════════════════════ */

function is_logged_in(): bool {
    return !empty($_SESSION[ADMIN_SESSION_KEY]);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: admin.php?section=login');
        exit;
    }
}

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 900); // 15 minutos

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!rate_limit_check($ip)) {
        $mins = (int)ceil(rate_limit_remaining($ip) / 60);
        $login_error = "Muitas tentativas. Aguarde {$mins} minuto(s) e tente novamente.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $st = mysqli_prepare($db, 'SELECT id, password_hash FROM admin_users WHERE username = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 's', $username);
        mysqli_execute($st);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);

        if ($user && password_verify($password, $user['password_hash'])) {
            rate_limit_reset($ip);
            session_regenerate_id(true);
            $_SESSION[ADMIN_SESSION_KEY] = $user['id'];
            header('Location: admin.php');
            exit;
        }
        rate_limit_fail($ip);
        $login_error = 'Usuário ou senha incorretos.';
    }
}

// Logout
if (($_GET['_action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: admin.php?section=login');
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════════════ */

/* ── Rate limiting ───────────────────────────────────────────────────── */

function _rl_file(string $ip): string {
    return sys_get_temp_dir() . '/adm_rl_' . md5($ip) . '.json';
}

function rate_limit_check(string $ip): bool {
    $file = _rl_file($ip);
    if (!file_exists($file)) return true;
    $data = json_decode((string)file_get_contents($file), true);
    return !is_array($data) || ($data['until'] ?? 0) <= time();
}

function rate_limit_remaining(string $ip): int {
    $file = _rl_file($ip);
    if (!file_exists($file)) return 0;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? max(0, ($data['until'] ?? 0) - time()) : 0;
}

function rate_limit_fail(string $ip): void {
    $file = _rl_file($ip);
    $data = ['count' => 0, 'until' => 0];
    if (file_exists($file)) {
        $data = json_decode((string)file_get_contents($file), true) ?? $data;
    }
    if (($data['until'] ?? 0) > time()) return;
    $data['count'] = ($data['count'] ?? 0) + 1;
    if ($data['count'] >= LOGIN_MAX_ATTEMPTS) {
        $data['until'] = time() + LOGIN_LOCKOUT_SECONDS;
        $data['count'] = 0;
    }
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function rate_limit_reset(string $ip): void {
    $file = _rl_file($ip);
    if (file_exists($file)) @unlink($file);
}

/* ── CSRF ────────────────────────────────────────────────────────────── */

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Requisição inválida (token CSRF incorreto).');
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ue(string $s): string {
    return urlencode($s);
}

function sanitize_content(string $content): string {
    $clean = strip_tags($content, '<em>');
    $clean = preg_replace('/<em[^>]*>/i', '<em>', $clean);
    return $clean;
}

function generate_slug(string $title): string {
    $slug = mb_strtolower($title, 'UTF-8');
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $type = 'ok'): void {
    $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash(): ?array {
    if (!empty($_SESSION['_flash'])) {
        $f = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $f;
    }
    return null;
}

function swap_sort(mysqli $db, string $table, string $id_col, int $id, string $dir): void {
    $row = mysqli_fetch_assoc(mysqli_query($db,
        "SELECT id, sort_order FROM $table WHERE $id_col = $id"));
    if (!$row) return;
    $cur_sort = (int)$row['sort_order'];

    $op = $dir === 'up' ? '<' : '>';
    $order = $dir === 'up' ? 'DESC' : 'ASC';
    $res = mysqli_query($db,
        "SELECT $id_col, sort_order FROM $table
         WHERE sort_order $op $cur_sort ORDER BY sort_order $order LIMIT 1");
    $other = mysqli_fetch_assoc($res);
    if (!$other) return;

    $other_sort = (int)$other['sort_order'];
    $other_id   = (int)$other[$id_col];

    mysqli_query($db, "UPDATE $table SET sort_order = $other_sort WHERE $id_col = $id");
    mysqli_query($db, "UPDATE $table SET sort_order = $cur_sort   WHERE $id_col = $other_id");
}

function get_all_langs(mysqli $db): array {
    $res = mysqli_query($db, 'SELECT id, code, name, is_default FROM languages ORDER BY is_default DESC, name');
    $r = [];
    while ($row = mysqli_fetch_assoc($res)) $r[] = $row;
    return $r;
}

function get_default_lang_id(mysqli $db): int {
    $res = mysqli_query($db, 'SELECT id FROM languages WHERE is_default = 1 LIMIT 1');
    $row = mysqli_fetch_assoc($res);
    if ($row) return (int)$row['id'];
    // fallback: primeiro idioma
    $res = mysqli_query($db, 'SELECT id FROM languages ORDER BY id LIMIT 1');
    $row = mysqli_fetch_assoc($res);
    return $row ? (int)$row['id'] : 0;
}

/* ══════════════════════════════════════════════════════════════════════
   PROCESSAMENTO DE FORMULÁRIOS (POST)
══════════════════════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_logged_in()) {
    verify_csrf();
    $pa = $_POST['_action'] ?? '';

    /* ── Trocar senha ────────────────────────────────────────────────── */
    if ($pa === 'change_password') {
        $uid     = (int)$_SESSION[ADMIN_SESSION_KEY];
        $current = $_POST['current_password'] ?? '';
        $pw      = $_POST['new_password'] ?? '';

        $st = mysqli_prepare($db, 'SELECT password_hash FROM admin_users WHERE id = ?');
        mysqli_stmt_bind_param($st, 'i', $uid);
        mysqli_execute($st);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);

        if (!$user || !password_verify($current, $user['password_hash'])) {
            flash('Senha atual incorreta.', 'err');
            redirect('admin.php?section=password');
        }

        if (strlen($pw) >= 6) {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $st = mysqli_prepare($db, 'UPDATE admin_users SET password_hash = ? WHERE id = ?');
            mysqli_stmt_bind_param($st, 'si', $hash, $uid);
            mysqli_execute($st);
            mysqli_stmt_close($st);
            flash('Senha alterada com sucesso.');
        } else {
            flash('A nova senha deve ter pelo menos 6 caracteres.', 'err');
        }
        redirect('admin.php?section=dashboard');
    }

    /* ── Idiomas ─────────────────────────────────────────────────────── */
    if ($pa === 'save_lang') {
        $lid  = (int)($_POST['lang_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $def  = !empty($_POST['is_default']) ? 1 : 0;

        if ($code === '' || $name === '') {
            flash('Código e nome são obrigatórios.', 'err');
            redirect('admin.php?section=languages');
        }

        if ($def) mysqli_query($db, 'UPDATE languages SET is_default = 0');

        if ($lid > 0) {
            $st = mysqli_prepare($db, 'UPDATE languages SET code=?,name=?,is_default=? WHERE id=?');
            mysqli_stmt_bind_param($st, 'ssii', $code, $name, $def, $lid);
        } else {
            $st = mysqli_prepare($db, 'INSERT INTO languages (code,name,is_default) VALUES (?,?,?)');
            mysqli_stmt_bind_param($st, 'ssi', $code, $name, $def);
        }
        mysqli_execute($st);
        mysqli_stmt_close($st);
        flash($lid > 0 ? 'Idioma atualizado.' : 'Idioma criado.');
        redirect('admin.php?section=languages');
    }

    if ($pa === 'delete_lang') {
        $lid = (int)($_POST['lang_id'] ?? 0);
        $st = mysqli_prepare($db, 'DELETE FROM languages WHERE id = ?');
        mysqli_stmt_bind_param($st, 'i', $lid);
        mysqli_execute($st);
        mysqli_stmt_close($st);
        flash('Idioma removido.');
        redirect('admin.php?section=languages');
    }

    /* ── Séries ──────────────────────────────────────────────────────── */
    if ($pa === 'save_series') {
        $sid   = (int)($_POST['series_id'] ?? 0);
        $slug  = trim($_POST['slug'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $trans = $_POST['trans'] ?? [];
        $default_lid = get_default_lang_id($db);

        if ($slug === '') $slug = generate_slug($trans[$default_lid]['title'] ?? '');
        if ($slug === '') { flash('O título no idioma padrão é obrigatório.', 'err'); redirect('admin.php?section=series'); }
        $slug = generate_slug($slug);

        if ($sid > 0) {
            $st = mysqli_prepare($db, 'UPDATE series SET slug=?,sort_order=? WHERE id=?');
            mysqli_stmt_bind_param($st, 'sii', $slug, $sort, $sid);
            mysqli_execute($st); mysqli_stmt_close($st);
        } else {
            $st = mysqli_prepare($db, 'INSERT INTO series (slug,sort_order) VALUES (?,?)');
            mysqli_stmt_bind_param($st, 'si', $slug, $sort);
            mysqli_execute($st);
            $sid = (int)mysqli_insert_id($db);
            mysqli_stmt_close($st);
        }

        foreach ($trans as $lid_s => $t) {
            $lid2  = (int)$lid_s;
            $title = trim($t['title'] ?? '');
            $desc  = trim($t['description'] ?? '');
            if ($title === '') continue;
            $st2 = mysqli_prepare($db,
                'INSERT INTO series_t (series_id,lang_id,title,description) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)');
            mysqli_stmt_bind_param($st2, 'iiss', $sid, $lid2, $title, $desc);
            mysqli_execute($st2); mysqli_stmt_close($st2);
        }
        flash('Série salva.');
        redirect('admin.php?section=series');
    }

    if ($pa === 'delete_series') {
        $sid = (int)($_POST['series_id'] ?? 0);
        $st = mysqli_prepare($db, 'DELETE FROM series WHERE id=?');
        mysqli_stmt_bind_param($st, 'i', $sid);
        mysqli_execute($st); mysqli_stmt_close($st);
        flash('Série removida.');
        redirect('admin.php?section=series');
    }

    if ($pa === 'move_series') {
        $sid = (int)($_POST['series_id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        swap_sort($db, 'series', 'id', $sid, $dir);
        redirect('admin.php?section=series');
    }

    /* ── Livros ──────────────────────────────────────────────────────── */
    if ($pa === 'save_book') {
        $bid   = (int)($_POST['book_id']   ?? 0);
        $srid  = (int)($_POST['series_id'] ?? 0);
        $slug  = trim($_POST['slug'] ?? '');
        $cover = trim($_POST['cover_image'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $trans = $_POST['trans'] ?? [];
        $default_lid = get_default_lang_id($db);

        if ($slug === '') $slug = generate_slug($trans[$default_lid]['title'] ?? '');
        if ($slug === '') { flash('O título no idioma padrão é obrigatório.', 'err'); redirect('admin.php?section=books'); }
        $slug = generate_slug($slug);

        if ($bid > 0) {
            $st = mysqli_prepare($db,
                'UPDATE books SET series_id=?,slug=?,cover_image=?,sort_order=? WHERE id=?');
            mysqli_stmt_bind_param($st, 'issii', $srid, $slug, $cover, $sort, $bid);
            mysqli_execute($st); mysqli_stmt_close($st);
        } else {
            $st = mysqli_prepare($db,
                'INSERT INTO books (series_id,slug,cover_image,sort_order) VALUES (?,?,?,?)');
            mysqli_stmt_bind_param($st, 'issi', $srid, $slug, $cover, $sort);
            mysqli_execute($st);
            $bid = (int)mysqli_insert_id($db);
            mysqli_stmt_close($st);
        }

        foreach ($trans as $lid_s => $t) {
            $lid2      = (int)$lid_s;
            $title     = trim($t['title'] ?? '');
            $copyright = trim($t['copyright'] ?? '');
            $desc      = trim($t['description'] ?? '');
            if ($title === '') continue;
            $st2 = mysqli_prepare($db,
                'INSERT INTO books_t (book_id,lang_id,title,copyright,description) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE title=VALUES(title), copyright=VALUES(copyright), description=VALUES(description)');
            mysqli_stmt_bind_param($st2, 'iisss', $bid, $lid2, $title, $copyright, $desc);
            mysqli_execute($st2); mysqli_stmt_close($st2);
        }
        flash('Livro salvo.');
        redirect('admin.php?section=books');
    }

    if ($pa === 'delete_book') {
        $bid = (int)($_POST['book_id'] ?? 0);
        $st = mysqli_prepare($db, 'DELETE FROM books WHERE id=?');
        mysqli_stmt_bind_param($st, 'i', $bid);
        mysqli_execute($st); mysqli_stmt_close($st);
        flash('Livro removido.');
        redirect('admin.php?section=books');
    }

    if ($pa === 'move_book') {
        $bid = (int)($_POST['book_id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        swap_sort($db, 'books', 'id', $bid, $dir);
        redirect('admin.php?section=books');
    }

    /* ── Capítulos ───────────────────────────────────────────────────── */
    if ($pa === 'save_chapter') {
        $cid   = (int)($_POST['chapter_id'] ?? 0);
        $bid   = (int)($_POST['book_id']    ?? 0);
        $slug  = trim($_POST['slug'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $trans = $_POST['trans'] ?? [];
        $default_lid = get_default_lang_id($db);

        if ($slug === '') $slug = generate_slug($trans[$default_lid]['title'] ?? '');
        if ($slug === '') { flash('O título no idioma padrão é obrigatório.', 'err'); redirect('admin.php?section=chapters&book_id=' . $bid); }
        $slug = generate_slug($slug);

        if ($cid > 0) {
            $st = mysqli_prepare($db,
                'UPDATE chapters SET book_id=?,slug=?,sort_order=? WHERE id=?');
            mysqli_stmt_bind_param($st, 'isii', $bid, $slug, $sort, $cid);
            mysqli_execute($st); mysqli_stmt_close($st);
        } else {
            $st = mysqli_prepare($db,
                'INSERT INTO chapters (book_id,slug,sort_order) VALUES (?,?,?)');
            mysqli_stmt_bind_param($st, 'isi', $bid, $slug, $sort);
            mysqli_execute($st);
            $cid = (int)mysqli_insert_id($db);
            mysqli_stmt_close($st);
        }

        foreach ($trans as $lid_s => $t) {
            $lid2    = (int)$lid_s;
            $title   = trim($t['title'] ?? '');
            $content = sanitize_content($t['content'] ?? '');
            if ($title === '') continue;
            $st2 = mysqli_prepare($db,
                'INSERT INTO chapters_t (chapter_id,lang_id,title,content) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content)');
            mysqli_stmt_bind_param($st2, 'iiss', $cid, $lid2, $title, $content);
            mysqli_execute($st2); mysqli_stmt_close($st2);
        }
        flash('Capítulo salvo.');
        redirect('admin.php?section=chapters&book_id=' . $bid);
    }

    if ($pa === 'delete_chapter') {
        $cid = (int)($_POST['chapter_id'] ?? 0);
        $bid_back = (int)($_POST['book_id'] ?? 0);
        $st = mysqli_prepare($db, 'DELETE FROM chapters WHERE id=?');
        mysqli_stmt_bind_param($st, 'i', $cid);
        mysqli_execute($st); mysqli_stmt_close($st);
        flash('Capítulo removido.');
        redirect('admin.php?section=chapters&book_id=' . $bid_back);
    }

    if ($pa === 'move_chapter') {
        $cid     = (int)($_POST['chapter_id'] ?? 0);
        $dir     = $_POST['dir'] ?? '';
        $bid_back = (int)($_POST['book_id'] ?? 0);
        swap_sort($db, 'chapters', 'id', $cid, $dir);
        redirect('admin.php?section=chapters&book_id=' . $bid_back);
    }

    /* ── Moderação de comentários ────────────────────────────────────── */
    if ($pa === 'approve_comment') {
        $cid = (int)($_POST['comment_id'] ?? 0);
        if ($cid > 0) {
            $st = mysqli_prepare($db, "UPDATE comments SET status='visible' WHERE id=?");
            mysqli_stmt_bind_param($st, 'i', $cid);
            mysqli_execute($st); mysqli_stmt_close($st);

            // Conta comentários aprovados do autor; se >= 5, marca como confiável
            $st2 = mysqli_prepare($db,
                'SELECT c.reader_id,
                        (SELECT COUNT(*) FROM comments c2 WHERE c2.reader_id = c.reader_id AND c2.status = \'visible\') AS approved_count
                 FROM comments c WHERE c.id = ? LIMIT 1');
            mysqli_stmt_bind_param($st2, 'i', $cid);
            mysqli_execute($st2);
            $res2 = mysqli_stmt_get_result($st2);
            $row2 = mysqli_fetch_assoc($res2);
            mysqli_free_result($res2); mysqli_stmt_close($st2);
            if ($row2 && (int)$row2['approved_count'] >= 5) {
                $st3 = mysqli_prepare($db,
                    'UPDATE readers SET trusted_at = NOW() WHERE id = ? AND trusted_at IS NULL');
                mysqli_stmt_bind_param($st3, 'i', (int)$row2['reader_id']);
                mysqli_execute($st3); mysqli_stmt_close($st3);
            }
        }
        flash('Comentário aprovado.');
        redirect('admin.php?section=comments');
    }

    if ($pa === 'reject_comment') {
        $cid = (int)($_POST['comment_id'] ?? 0);
        if ($cid > 0) {
            $st = mysqli_prepare($db, "UPDATE comments SET status='hidden' WHERE id=?");
            mysqli_stmt_bind_param($st, 'i', $cid);
            mysqli_execute($st); mysqli_stmt_close($st);
        }
        flash('Comentário rejeitado.');
        redirect('admin.php?section=comments');
    }

    if ($pa === 'delete_comment') {
        $cid = (int)($_POST['comment_id'] ?? 0);
        if ($cid > 0) {
            $st = mysqli_prepare($db, 'DELETE FROM comments WHERE id=?');
            mysqli_stmt_bind_param($st, 'i', $cid);
            mysqli_execute($st); mysqli_stmt_close($st);
        }
        flash('Comentário excluído.');
        redirect('admin.php?section=comments');
    }

    if ($pa === 'trust_reader') {
        $rid = (int)($_POST['reader_id'] ?? 0);
        if ($rid > 0) {
            $st = mysqli_prepare($db, 'UPDATE readers SET trusted_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_execute($st); mysqli_stmt_close($st);
        }
        flash('Leitor marcado como confiável.');
        redirect('admin.php?section=comments');
    }

    /* ── Design / configurações visuais ─────────────────────────────── */
    if ($pa === 'save_design') {
        $site_name_v = trim($_POST['site_name']    ?? '');
        $accent_v    = trim($_POST['accent_color'] ?? '#2e7d52');
        $logo_v      = trim($_POST['logo_url']     ?? '');

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent_v)) $accent_v = '#2e7d52';

        $smtp_fields = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_from', 'smtp_from_name'];
        foreach ($smtp_fields as $sf) {
            $sv = trim($_POST[$sf] ?? '');
            $st = mysqli_prepare($db,
                'INSERT INTO site_settings (`key`,`value`) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
            mysqli_stmt_bind_param($st, 'ss', $sf, $sv);
            mysqli_execute($st); mysqli_stmt_close($st);
        }
        // Senha SMTP: só salva se preenchida (evita apagar senha existente)
        $smtp_pass_v = trim($_POST['smtp_pass'] ?? '');
        if ($smtp_pass_v !== '') {
            $st = mysqli_prepare($db,
                'INSERT INTO site_settings (`key`,`value`) VALUES (\'smtp_pass\',?)
                 ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
            mysqli_stmt_bind_param($st, 's', $smtp_pass_v);
            mysqli_execute($st); mysqli_stmt_close($st);
        }

        foreach (['site_name' => $site_name_v, 'accent_color' => $accent_v, 'logo_url' => $logo_v] as $k => $v) {
            $st = mysqli_prepare($db,
                'INSERT INTO site_settings (`key`,`value`) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
            mysqli_stmt_bind_param($st, 'ss', $k, $v);
            mysqli_execute($st); mysqli_stmt_close($st);
        }

        // Conteúdo da home por idioma
        $home_trans = $_POST['home'] ?? [];
        foreach ($home_trans as $lid_s => $t) {
            $lid2    = (int)$lid_s;
            $title_v = trim($t['title'] ?? '');
            $cont_v  = sanitize_content($t['content'] ?? '');
            $st = mysqli_prepare($db,
                'INSERT INTO home_t (lang_id,title,content) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content)');
            mysqli_stmt_bind_param($st, 'iss', $lid2, $title_v, $cont_v);
            mysqli_execute($st); mysqli_stmt_close($st);
        }

        flash('Configurações salvas.');
        redirect('admin.php?section=design');
    }

    /* ── Bio links ───────────────────────────────────────────────────── */
    if ($pa === 'save_bio') {
        $lid_b = (int)($_POST['link_id']    ?? 0);
        $label = trim($_POST['label'] ?? '');
        $url   = trim($_POST['url']   ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if ($label === '' || $url === '') {
            flash('Label e URL são obrigatórios.', 'err');
            redirect('admin.php?section=bio');
        }

        if ($lid_b > 0) {
            $st = mysqli_prepare($db,
                'UPDATE bio_links SET label=?,url=?,sort_order=? WHERE id=?');
            mysqli_stmt_bind_param($st, 'ssii', $label, $url, $sort, $lid_b);
        } else {
            $st = mysqli_prepare($db,
                'INSERT INTO bio_links (label,url,sort_order) VALUES (?,?,?)');
            mysqli_stmt_bind_param($st, 'ssi', $label, $url, $sort);
        }
        mysqli_execute($st); mysqli_stmt_close($st);
        flash('Link salvo.');
        redirect('admin.php?section=bio');
    }

    if ($pa === 'delete_bio') {
        $lid_b = (int)($_POST['link_id'] ?? 0);
        $st = mysqli_prepare($db, 'DELETE FROM bio_links WHERE id=?');
        mysqli_stmt_bind_param($st, 'i', $lid_b);
        mysqli_execute($st); mysqli_stmt_close($st);
        flash('Link removido.');
        redirect('admin.php?section=bio');
    }

    if ($pa === 'move_bio') {
        $lid_b = (int)($_POST['link_id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        swap_sort($db, 'bio_links', 'id', $lid_b, $dir);
        redirect('admin.php?section=bio');
    }
}

/* ══════════════════════════════════════════════════════════════════════
   VIEWS DO ADMIN
══════════════════════════════════════════════════════════════════════ */

$section = $_GET['section'] ?? 'dashboard';
$flash   = get_flash();

if ($section === 'login') {
    // Página de login (não requer autenticação)
} else {
    require_login();
}

/* ── Render helper ───────────────────────────────────────────────────── */
function admin_wrap(string $title, string $section, string $body, ?array $flash): void {
    $csrf_input = '<input type="hidden" name="csrf_token" value="'
                . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
    $body = preg_replace_callback(
        '/(<form\b[^>]*\bmethod=["\']post["\'][^>]*>)/i',
        fn($m) => $m[1] . $csrf_input,
        $body
    );
    ?>
<!DOCTYPE html>
<html lang="pt" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?> — Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <script>
    (function(){
      var t = localStorage.getItem('stories_theme') || 'light';
      document.documentElement.setAttribute('data-theme', t);
    }());
  </script>
</head>
<body>
<?php if ($section !== 'login'): ?>
<div class="adm-layout">
  <nav class="adm-nav">
    <div class="adm-logo"><a href="index.php" target="_blank">↗ <?= h(SITE_NAME) ?></a></div>
    <a href="admin.php?section=dashboard"  class="<?= $section==='dashboard'  ?'active':'' ?>">Dashboard</a>
    <a href="admin.php?section=languages"  class="<?= $section==='languages'  ?'active':'' ?>">Idiomas</a>
    <a href="admin.php?section=series"     class="<?= $section==='series'     ?'active':'' ?>">Séries</a>
    <a href="admin.php?section=books"      class="<?= $section==='books'      ?'active':'' ?>">Livros</a>
    <a href="admin.php?section=chapters"   class="<?= $section==='chapters'   ?'active':'' ?>">Capítulos</a>
    <a href="admin.php?section=bio"        class="<?= $section==='bio'        ?'active':'' ?>">Bio Links</a>
    <a href="admin.php?section=comments"  class="<?= $section==='comments'  ?'active':'' ?>">Comentários<?php
      $pc = mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM comments WHERE status='pending'"));
      if ($pc && $pc[0] > 0) echo ' <span style="background:var(--adm-accent);color:#fff;border-radius:999px;padding:1px 6px;font-size:.72rem">' . (int)$pc[0] . '</span>';
    ?></a>
    <a href="admin.php?section=design"    class="<?= $section==='design'    ?'active':'' ?>">Design</a>
    <hr>
    <a href="admin.php?section=password">Senha</a>
    <a href="admin.php?_action=logout">Sair</a>
  </nav>
  <main class="adm-main">
    <h1 class="adm-title"><?= h($title) ?></h1>
    <?php if ($flash): ?>
    <div class="adm-flash adm-flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <?= $body ?>
  </main>
</div>
<?php else: ?>
<?= $body ?>
<?php endif; ?>
<script src="assets/admin.js"></script>
</body>
</html>
<?php }

/* ── Login ─────────────────────────────────────────────────────────── */
if ($section === 'login') {
    ob_start(); ?>
    <div class="adm-login-wrap">
      <h1 class="adm-login-title"><?= h(SITE_NAME) ?> — Admin</h1>
      <?php if (!empty($login_error)): ?>
      <div class="adm-flash adm-flash-err"><?= h($login_error) ?></div>
      <?php endif; ?>
      <form method="post" class="adm-login-form">
        <input type="hidden" name="_action" value="login">
        <label>Usuário<br><input type="text" name="username" autocomplete="username" required></label>
        <label>Senha<br><input type="password" name="password" autocomplete="current-password" required></label>
        <button type="submit" class="adm-btn adm-btn-primary">Entrar</button>
      </form>
    </div>
    <?php
    admin_wrap('Login', 'login', ob_get_clean(), null);
    exit;
}

/* ── Dashboard ──────────────────────────────────────────────────────── */
if ($section === 'dashboard') {
    $counts = [];
    foreach (['series','books','chapters','languages','bio_links','readers'] as $t) {
        $r = mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM $t"));
        $counts[$t] = $r[0];
    }
    $r = mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM comments WHERE status='pending'"));
    $counts['pending_comments'] = (int)($r[0] ?? 0);
    ob_start(); ?>
    <div class="adm-stats">
      <div class="adm-stat"><span><?= $counts['series'] ?></span>Séries</div>
      <div class="adm-stat"><span><?= $counts['books'] ?></span>Livros</div>
      <div class="adm-stat"><span><?= $counts['chapters'] ?></span>Capítulos</div>
      <div class="adm-stat"><span><?= $counts['readers'] ?></span>Leitores</div>
    </div>
    <?php if ($counts['pending_comments'] > 0): ?>
    <p style="margin-top:1rem;padding:.6rem 1rem;background:rgba(217,119,6,.12);border-radius:8px;font-size:.88rem;font-family:var(--adm-font-ui)">
      ⚠ <strong><?= $counts['pending_comments'] ?></strong> comentário(s) aguardando moderação.
      <a href="admin.php?section=comments" style="color:var(--adm-accent)">Ver agora</a>
    </p>
    <?php endif; ?>
    <p style="margin-top:1.5rem;color:var(--adm-muted);font-size:.9rem">
      Use o menu lateral para gerenciar o conteúdo do site.
    </p>
    <?php
    admin_wrap('Dashboard', 'dashboard', ob_get_clean(), $flash);
    exit;
}

/* ── Trocar senha ───────────────────────────────────────────────────── */
if ($section === 'password') {
    ob_start(); ?>
    <form method="post" class="adm-form">
      <input type="hidden" name="_action" value="change_password">
      <div class="adm-field">
        <label>Senha atual
          <input type="password" name="current_password" required autocomplete="current-password">
        </label>
      </div>
      <div class="adm-field">
        <label>Nova senha (mín. 6 caracteres)
          <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
        </label>
      </div>
      <button type="submit" class="adm-btn adm-btn-primary">Salvar</button>
    </form>
    <?php
    admin_wrap('Alterar Senha', 'password', ob_get_clean(), $flash);
    exit;
}

/* ── Idiomas ────────────────────────────────────────────────────────── */
if ($section === 'languages') {
    $edit_id = (int)($_GET['edit'] ?? 0);
    $edit = null;
    if ($edit_id > 0) {
        $r = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT * FROM languages WHERE id = $edit_id"));
        $edit = $r ?: null;
    }

    $langs = [];
    $res = mysqli_query($db, 'SELECT * FROM languages ORDER BY name');
    while ($r = mysqli_fetch_assoc($res)) $langs[] = $r;

    ob_start(); ?>
    <form method="post" class="adm-form" style="margin-bottom:2rem">
      <input type="hidden" name="_action" value="save_lang">
      <input type="hidden" name="lang_id" value="<?= $edit ? $edit['id'] : 0 ?>">
      <div class="adm-fields-row">
        <div class="adm-field">
          <label>Código (ex: pt, en, es)
            <input type="text" name="code" maxlength="10" required
                   value="<?= $edit ? h($edit['code']) : '' ?>">
          </label>
        </div>
        <div class="adm-field">
          <label>Nome
            <input type="text" name="name" required
                   value="<?= $edit ? h($edit['name']) : '' ?>">
          </label>
        </div>
        <div class="adm-field adm-field-check">
          <label><input type="checkbox" name="is_default" value="1"
                        <?= ($edit && $edit['is_default']) ? 'checked' : '' ?>>
            Padrão</label>
        </div>
      </div>
      <div class="adm-actions">
        <button type="submit" class="adm-btn adm-btn-primary">
          <?= $edit ? 'Atualizar' : 'Adicionar' ?>
        </button>
        <?php if ($edit): ?>
        <a href="admin.php?section=languages" class="adm-btn">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>

    <table class="adm-table">
      <thead><tr><th>Código</th><th>Nome</th><th>Padrão</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($langs as $l): ?>
      <tr>
        <td><code><?= h($l['code']) ?></code></td>
        <td><?= h($l['name']) ?></td>
        <td><?= $l['is_default'] ? '✓' : '' ?></td>
        <td class="adm-td-actions">
          <a href="admin.php?section=languages&amp;edit=<?= $l['id'] ?>" class="adm-btn adm-btn-sm">Editar</a>
          <form method="post" class="inline" onsubmit="return confirm('Remover idioma?')">
            <input type="hidden" name="_action" value="delete_lang">
            <input type="hidden" name="lang_id" value="<?= $l['id'] ?>">
            <button class="adm-btn adm-btn-sm adm-btn-danger">Remover</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    admin_wrap('Idiomas', 'languages', ob_get_clean(), $flash);
    exit;
}

/* ── Séries ─────────────────────────────────────────────────────────── */
if ($section === 'series') {
    $all_langs = get_all_langs($db);
    $edit_id   = (int)($_GET['edit'] ?? 0);
    $edit = $edit_trans = null;

    if ($edit_id > 0) {
        $edit = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT * FROM series WHERE id = $edit_id"));
        if ($edit) {
            $res = mysqli_query($db,
                "SELECT lang_id, title, description FROM series_t WHERE series_id = $edit_id");
            while ($r = mysqli_fetch_assoc($res)) {
                $edit_trans[$r['lang_id']] = $r;
            }
        }
    }

    $default_lid = get_default_lang_id($db);
    $series_list = [];
    $res = mysqli_query($db, 'SELECT s.id, s.slug, s.sort_order,
                                      GROUP_CONCAT(st.title ORDER BY st.lang_id SEPARATOR " / ") AS titles
                               FROM series s
                               LEFT JOIN series_t st ON st.series_id = s.id
                               GROUP BY s.id ORDER BY s.sort_order, s.id');
    while ($r = mysqli_fetch_assoc($res)) $series_list[] = $r;

    ob_start(); ?>
    <form method="post" class="adm-form adm-card" style="margin-bottom:2rem">
      <input type="hidden" name="_action" value="save_series">
      <input type="hidden" name="series_id" value="<?= $edit ? $edit['id'] : 0 ?>">
      <div class="adm-fields-row">
        <div class="adm-field">
          <label>Slug (URL)
            <input type="text" name="slug" placeholder="gerado automaticamente"
                   value="<?= $edit ? h($edit['slug']) : '' ?>">
          </label>
        </div>
        <div class="adm-field" style="max-width:100px">
          <label>Ordem
            <input type="number" name="sort_order" value="<?= $edit ? $edit['sort_order'] : 0 ?>">
          </label>
        </div>
      </div>
      <?php foreach ($all_langs as $l):
            $is_def = ((int)$l['id'] === $default_lid); ?>
      <fieldset class="adm-fieldset">
        <legend><?= h($l['name']) ?> (<?= h($l['code']) ?>)<?= $is_def ? ' — idioma padrão' : '' ?></legend>
        <div class="adm-field">
          <label>Título<?= $is_def ? ' <span style="color:var(--adm-accent)">*</span>' : '' ?>
            <input type="text" name="trans[<?= $l['id'] ?>][title]"
                   <?= $is_def ? 'required' : '' ?>
                   value="<?= h($edit_trans[$l['id']]['title'] ?? '') ?>">
          </label>
        </div>
        <div class="adm-field">
          <label>Descrição
            <textarea name="trans[<?= $l['id'] ?>][description]" rows="2"><?= h($edit_trans[$l['id']]['description'] ?? '') ?></textarea>
          </label>
        </div>
      </fieldset>
      <?php endforeach; ?>
      <div class="adm-actions">
        <button type="submit" class="adm-btn adm-btn-primary"><?= $edit ? 'Atualizar' : 'Criar Série' ?></button>
        <?php if ($edit): ?>
        <a href="admin.php?section=series" class="adm-btn">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>

    <table class="adm-table">
      <thead><tr><th>Slug</th><th>Títulos</th><th>Ord.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($series_list as $s): ?>
      <tr>
        <td><code><?= h($s['slug']) ?></code></td>
        <td><?= h($s['titles'] ?? '') ?></td>
        <td><?= $s['sort_order'] ?></td>
        <td class="adm-td-actions">
          <form method="post" class="inline">
            <input type="hidden" name="_action" value="move_series">
            <input type="hidden" name="series_id" value="<?= $s['id'] ?>">
            <button name="dir" value="up"   class="adm-btn adm-btn-sm">↑</button>
            <button name="dir" value="down" class="adm-btn adm-btn-sm">↓</button>
          </form>
          <a href="admin.php?section=series&amp;edit=<?= $s['id'] ?>" class="adm-btn adm-btn-sm">Editar</a>
          <form method="post" class="inline" onsubmit="return confirm('Remover série e todos os livros/capítulos?')">
            <input type="hidden" name="_action" value="delete_series">
            <input type="hidden" name="series_id" value="<?= $s['id'] ?>">
            <button class="adm-btn adm-btn-sm adm-btn-danger">Remover</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    admin_wrap('Séries', 'series', ob_get_clean(), $flash);
    exit;
}

/* ── Livros ─────────────────────────────────────────────────────────── */
if ($section === 'books') {
    $all_langs  = get_all_langs($db);
    $all_series = [];
    $res = mysqli_query($db, 'SELECT s.id, COALESCE(st.title, s.slug) AS title
                               FROM series s
                               LEFT JOIN series_t st ON st.series_id = s.id
                               GROUP BY s.id ORDER BY s.sort_order, s.id');
    while ($r = mysqli_fetch_assoc($res)) $all_series[] = $r;

    $edit_id   = (int)($_GET['edit'] ?? 0);
    $edit = $edit_trans = null;

    if ($edit_id > 0) {
        $edit = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT * FROM books WHERE id = $edit_id"));
        if ($edit) {
            $res = mysqli_query($db,
                "SELECT lang_id, title, copyright, description FROM books_t WHERE book_id = $edit_id");
            while ($r = mysqli_fetch_assoc($res)) $edit_trans[$r['lang_id']] = $r;
        }
    }

    $default_lid = get_default_lang_id($db);
    $books_list = [];
    $res = mysqli_query($db, 'SELECT b.id, b.slug, b.sort_order,
                                      COALESCE(st.title, s.slug) AS series_title,
                                      GROUP_CONCAT(bt.title ORDER BY bt.lang_id SEPARATOR " / ") AS titles
                               FROM books b
                               JOIN series s ON s.id = b.series_id
                               LEFT JOIN series_t st ON st.series_id = s.id
                               LEFT JOIN books_t  bt ON bt.book_id   = b.id
                               GROUP BY b.id ORDER BY s.sort_order, b.sort_order, b.id');
    while ($r = mysqli_fetch_assoc($res)) $books_list[] = $r;

    ob_start(); ?>
    <form method="post" class="adm-form adm-card" style="margin-bottom:2rem">
      <input type="hidden" name="_action" value="save_book">
      <input type="hidden" name="book_id" value="<?= $edit ? $edit['id'] : 0 ?>">
      <div class="adm-fields-row">
        <div class="adm-field">
          <label>Série
            <select name="series_id" required>
              <?php foreach ($all_series as $s): ?>
              <option value="<?= $s['id'] ?>"
                <?= ($edit && $edit['series_id'] == $s['id']) ? 'selected' : '' ?>>
                <?= h($s['title']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="adm-field">
          <label>Slug (URL)
            <input type="text" name="slug" placeholder="gerado automaticamente"
                   value="<?= $edit ? h($edit['slug']) : '' ?>">
          </label>
        </div>
        <div class="adm-field" style="max-width:80px">
          <label>Ordem
            <input type="number" name="sort_order" value="<?= $edit ? $edit['sort_order'] : 0 ?>">
          </label>
        </div>
      </div>
      <div class="adm-field">
        <label>URL da Capa (imagem, opcional)
          <input type="url" name="cover_image" value="<?= $edit ? h($edit['cover_image'] ?? '') : '' ?>">
        </label>
      </div>
      <?php foreach ($all_langs as $l):
            $is_def = ((int)$l['id'] === $default_lid); ?>
      <fieldset class="adm-fieldset">
        <legend><?= h($l['name']) ?> (<?= h($l['code']) ?>)<?= $is_def ? ' — idioma padrão' : '' ?></legend>
        <div class="adm-field">
          <label>Título<?= $is_def ? ' <span style="color:var(--adm-accent)">*</span>' : '' ?>
            <input type="text" name="trans[<?= $l['id'] ?>][title]"
                   <?= $is_def ? 'required' : '' ?>
                   value="<?= h($edit_trans[$l['id']]['title'] ?? '') ?>">
          </label>
        </div>
        <div class="adm-field">
          <label>Copyright <span style="color:var(--adm-muted);font-size:.8rem">(ex: © 2024 Autor — aparece abaixo do título)</span>
            <input type="text" name="trans[<?= $l['id'] ?>][copyright]" maxlength="300"
                   value="<?= h($edit_trans[$l['id']]['copyright'] ?? '') ?>">
          </label>
        </div>
        <div class="adm-field">
          <label>Descrição / sinopse
            <textarea name="trans[<?= $l['id'] ?>][description]" rows="2"><?= h($edit_trans[$l['id']]['description'] ?? '') ?></textarea>
          </label>
        </div>
      </fieldset>
      <?php endforeach; ?>
      <div class="adm-actions">
        <button type="submit" class="adm-btn adm-btn-primary"><?= $edit ? 'Atualizar' : 'Criar Livro' ?></button>
        <?php if ($edit): ?>
        <a href="admin.php?section=books" class="adm-btn">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>

    <table class="adm-table">
      <thead><tr><th>Série</th><th>Slug</th><th>Títulos</th><th>Ord.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($books_list as $b): ?>
      <tr>
        <td><?= h($b['series_title']) ?></td>
        <td><code><?= h($b['slug']) ?></code></td>
        <td><?= h($b['titles'] ?? '') ?></td>
        <td><?= $b['sort_order'] ?></td>
        <td class="adm-td-actions">
          <a href="admin.php?section=chapters&amp;book_id=<?= $b['id'] ?>" class="adm-btn adm-btn-sm">Capítulos</a>
          <form method="post" class="inline">
            <input type="hidden" name="_action" value="move_book">
            <input type="hidden" name="book_id" value="<?= $b['id'] ?>">
            <button name="dir" value="up"   class="adm-btn adm-btn-sm">↑</button>
            <button name="dir" value="down" class="adm-btn adm-btn-sm">↓</button>
          </form>
          <a href="admin.php?section=books&amp;edit=<?= $b['id'] ?>" class="adm-btn adm-btn-sm">Editar</a>
          <form method="post" class="inline" onsubmit="return confirm('Remover livro e todos os capítulos?')">
            <input type="hidden" name="_action" value="delete_book">
            <input type="hidden" name="book_id" value="<?= $b['id'] ?>">
            <button class="adm-btn adm-btn-sm adm-btn-danger">Remover</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    admin_wrap('Livros', 'books', ob_get_clean(), $flash);
    exit;
}

/* ── Capítulos ──────────────────────────────────────────────────────── */
if ($section === 'chapters') {
    $all_langs = get_all_langs($db);
    $filter_bid = (int)($_GET['book_id'] ?? 0);

    // Lista de livros para o filtro/select
    $all_books = [];
    $res = mysqli_query($db, 'SELECT b.id, COALESCE(bt.title, b.slug) AS title,
                                      COALESCE(st.title, s.slug) AS series_title
                               FROM books b
                               JOIN series s ON s.id = b.series_id
                               LEFT JOIN books_t  bt ON bt.book_id   = b.id
                               LEFT JOIN series_t st ON st.series_id = s.id
                               GROUP BY b.id ORDER BY s.sort_order, b.sort_order, b.id');
    while ($r = mysqli_fetch_assoc($res)) $all_books[] = $r;

    $edit_id   = (int)($_GET['edit'] ?? 0);
    $edit = $edit_trans = null;

    if ($edit_id > 0) {
        $edit = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT * FROM chapters WHERE id = $edit_id"));
        if ($edit) {
            $filter_bid = $filter_bid ?: (int)$edit['book_id'];
            $res = mysqli_query($db,
                "SELECT lang_id, title, content FROM chapters_t WHERE chapter_id = $edit_id");
            while ($r = mysqli_fetch_assoc($res)) $edit_trans[$r['lang_id']] = $r;
        }
    }

    $default_lid = get_default_lang_id($db);
    $chapters_list = [];
    if ($filter_bid > 0) {
        $res = mysqli_query($db,
            "SELECT c.id, c.slug, c.sort_order,
                    GROUP_CONCAT(ct.title ORDER BY ct.lang_id SEPARATOR ' / ') AS titles
             FROM chapters c
             LEFT JOIN chapters_t ct ON ct.chapter_id = c.id
             WHERE c.book_id = $filter_bid
             GROUP BY c.id ORDER BY c.sort_order, c.id");
        while ($r = mysqli_fetch_assoc($res)) $chapters_list[] = $r;
    }

    ob_start(); ?>
    <!-- Filtro de livro -->
    <form method="get" class="adm-inline-form" style="margin-bottom:1.5rem">
      <input type="hidden" name="section" value="chapters">
      <label style="font-size:.9rem">Livro:
        <select name="book_id" onchange="this.form.submit()">
          <option value="">— selecione —</option>
          <?php foreach ($all_books as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $filter_bid == $b['id'] ? 'selected' : '' ?>>
            <?= h($b['series_title']) ?> / <?= h($b['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>

    <?php if ($filter_bid > 0): ?>
    <form method="post" class="adm-form adm-card" style="margin-bottom:2rem">
      <input type="hidden" name="_action" value="save_chapter">
      <input type="hidden" name="chapter_id" value="<?= $edit ? $edit['id'] : 0 ?>">
      <input type="hidden" name="book_id" value="<?= $filter_bid ?>">
      <div class="adm-fields-row">
        <div class="adm-field">
          <label>Slug (URL)
            <input type="text" name="slug" placeholder="gerado automaticamente"
                   value="<?= $edit ? h($edit['slug']) : '' ?>">
          </label>
        </div>
        <div class="adm-field" style="max-width:80px">
          <label>Ordem
            <input type="number" name="sort_order"
                   value="<?= $edit ? $edit['sort_order'] : count($chapters_list) ?>">
          </label>
        </div>
      </div>
      <?php foreach ($all_langs as $l):
            $is_def = ((int)$l['id'] === $default_lid); ?>
      <fieldset class="adm-fieldset">
        <legend><?= h($l['name']) ?> (<?= h($l['code']) ?>)<?= $is_def ? ' — idioma padrão' : '' ?></legend>
        <div class="adm-field">
          <label>Título<?= $is_def ? ' <span style="color:var(--adm-accent)">*</span>' : '' ?>
            <input type="text" name="trans[<?= $l['id'] ?>][title]"
                   <?= $is_def ? 'required' : '' ?>
                   value="<?= h($edit_trans[$l['id']]['title'] ?? '') ?>">
          </label>
        </div>
        <div class="adm-field">
          <label>Conteúdo<?= $is_def ? ' <span style="color:var(--adm-accent)">*</span>' : '' ?>
            <div class="adm-editor-toolbar">
              <button type="button" class="adm-btn adm-btn-sm italic-btn"
                      data-target="content_<?= $l['id'] ?>"><em>I</em> Itálico</button>
            </div>
            <textarea id="content_<?= $l['id'] ?>"
                      name="trans[<?= $l['id'] ?>][content]"
                      rows="12"
                      class="adm-content-area"><?= h($edit_trans[$l['id']]['content'] ?? '') ?></textarea>
          </label>
          <p class="adm-hint">Selecione texto e clique em Itálico para marcar. Linha em branco = novo parágrafo.</p>
        </div>
      </fieldset>
      <?php endforeach; ?>
      <div class="adm-actions">
        <button type="submit" class="adm-btn adm-btn-primary"><?= $edit ? 'Atualizar' : 'Criar Capítulo' ?></button>
        <?php if ($edit): ?>
        <a href="admin.php?section=chapters&amp;book_id=<?= $filter_bid ?>" class="adm-btn">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>

    <table class="adm-table">
      <thead><tr><th>#</th><th>Slug</th><th>Títulos</th><th>Ord.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($chapters_list as $i => $ch): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><code><?= h($ch['slug']) ?></code></td>
        <td><?= h($ch['titles'] ?? '') ?></td>
        <td><?= $ch['sort_order'] ?></td>
        <td class="adm-td-actions">
          <form method="post" class="inline">
            <input type="hidden" name="_action" value="move_chapter">
            <input type="hidden" name="chapter_id" value="<?= $ch['id'] ?>">
            <input type="hidden" name="book_id" value="<?= $filter_bid ?>">
            <button name="dir" value="up"   class="adm-btn adm-btn-sm">↑</button>
            <button name="dir" value="down" class="adm-btn adm-btn-sm">↓</button>
          </form>
          <a href="admin.php?section=chapters&amp;book_id=<?= $filter_bid ?>&amp;edit=<?= $ch['id'] ?>"
             class="adm-btn adm-btn-sm">Editar</a>
          <form method="post" class="inline" onsubmit="return confirm('Remover capítulo?')">
            <input type="hidden" name="_action" value="delete_chapter">
            <input type="hidden" name="chapter_id" value="<?= $ch['id'] ?>">
            <input type="hidden" name="book_id" value="<?= $filter_bid ?>">
            <button class="adm-btn adm-btn-sm adm-btn-danger">Remover</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p class="adm-muted">Selecione um livro para gerenciar seus capítulos.</p>
    <?php endif; ?>
    <?php
    admin_wrap('Capítulos', 'chapters', ob_get_clean(), $flash);
    exit;
}

/* ── Bio Links ──────────────────────────────────────────────────────── */
if ($section === 'bio') {
    $edit_id = (int)($_GET['edit'] ?? 0);
    $edit = null;
    if ($edit_id > 0) {
        $edit = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT * FROM bio_links WHERE id = $edit_id"));
    }

    $links = [];
    $res = mysqli_query($db, 'SELECT * FROM bio_links ORDER BY sort_order, id');
    while ($r = mysqli_fetch_assoc($res)) $links[] = $r;

    ob_start(); ?>
    <form method="post" class="adm-form adm-card" style="margin-bottom:2rem">
      <input type="hidden" name="_action" value="save_bio">
      <input type="hidden" name="link_id" value="<?= $edit ? $edit['id'] : 0 ?>">
      <div class="adm-fields-row">
        <div class="adm-field">
          <label>Label (texto do botão)
            <input type="text" name="label" required
                   value="<?= $edit ? h($edit['label']) : '' ?>">
          </label>
        </div>
        <div class="adm-field">
          <label>URL
            <input type="url" name="url" required
                   value="<?= $edit ? h($edit['url']) : '' ?>">
          </label>
        </div>
        <div class="adm-field" style="max-width:80px">
          <label>Ordem
            <input type="number" name="sort_order"
                   value="<?= $edit ? $edit['sort_order'] : count($links) ?>">
          </label>
        </div>
      </div>
      <div class="adm-actions">
        <button type="submit" class="adm-btn adm-btn-primary"><?= $edit ? 'Atualizar' : 'Adicionar Link' ?></button>
        <?php if ($edit): ?>
        <a href="admin.php?section=bio" class="adm-btn">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>

    <table class="adm-table">
      <thead><tr><th>Label</th><th>URL</th><th>Ord.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($links as $l): ?>
      <tr>
        <td><?= h($l['label']) ?></td>
        <td><a href="<?= h($l['url']) ?>" target="_blank" rel="noopener"><?= h($l['url']) ?></a></td>
        <td><?= $l['sort_order'] ?></td>
        <td class="adm-td-actions">
          <form method="post" class="inline">
            <input type="hidden" name="_action" value="move_bio">
            <input type="hidden" name="link_id" value="<?= $l['id'] ?>">
            <button name="dir" value="up"   class="adm-btn adm-btn-sm">↑</button>
            <button name="dir" value="down" class="adm-btn adm-btn-sm">↓</button>
          </form>
          <a href="admin.php?section=bio&amp;edit=<?= $l['id'] ?>" class="adm-btn adm-btn-sm">Editar</a>
          <form method="post" class="inline" onsubmit="return confirm('Remover link?')">
            <input type="hidden" name="_action" value="delete_bio">
            <input type="hidden" name="link_id" value="<?= $l['id'] ?>">
            <button class="adm-btn adm-btn-sm adm-btn-danger">Remover</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    admin_wrap('Bio Links', 'bio', ob_get_clean(), $flash);
    exit;
}

/* ── Design ─────────────────────────────────────────────────────────── */
if ($section === 'design') {
    $all_langs = get_all_langs($db);
    $default_lid = get_default_lang_id($db);
    $cfg = load_settings($db);

    // Conteúdo da home existente por idioma
    $home_trans = [];
    $res = mysqli_query($db, 'SELECT lang_id, title, content FROM home_t');
    while ($r = mysqli_fetch_assoc($res)) $home_trans[$r['lang_id']] = $r;

    ob_start(); ?>
    <form method="post" class="adm-form">
      <input type="hidden" name="_action" value="save_design">

      <div class="adm-card" style="margin-bottom:1.5rem">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem">Identidade</h2>
        <div class="adm-fields-row">
          <div class="adm-field">
            <label>Nome do site
              <input type="text" name="site_name" value="<?= h($cfg['site_name']) ?>">
            </label>
          </div>
          <div class="adm-field" style="max-width:140px">
            <label>Cor de destaque
              <input type="color" name="accent_color"
                     value="<?= h($cfg['accent_color'] ?: '#2e7d52') ?>"
                     style="height:2.2rem;padding:.2rem .3rem;cursor:pointer">
            </label>
          </div>
        </div>
        <div class="adm-field">
          <label>URL da logo (imagem horizontal, aparece no topo do site)
            <input type="url" name="logo_url" placeholder="https://…"
                   value="<?= h($cfg['logo_url']) ?>">
          </label>
          <p class="adm-hint">Deixe vazio para exibir o nome do site em texto. Recomendado: PNG ou SVG com fundo transparente, altura ~60px.</p>
        </div>
        <?php if ($cfg['logo_url']): ?>
        <p style="margin-top:.5rem;font-size:.82rem;color:var(--adm-muted)">
          Preview: <img src="<?= h($cfg['logo_url']) ?>" alt="" style="height:28px;vertical-align:middle;margin-left:.4rem">
        </p>
        <?php endif; ?>
      </div>

      <div class="adm-card" style="margin-bottom:1.5rem">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem">Texto da Home</h2>
        <p style="font-size:.82rem;color:var(--adm-muted);margin-bottom:1rem">
          Título e texto exibidos na página inicial. Suporta itálico e parágrafos (linha em branco). Deixe vazio para usar o nome do site como título.
        </p>
        <?php foreach ($all_langs as $l):
              $is_def = ((int)$l['id'] === $default_lid); ?>
        <fieldset class="adm-fieldset">
          <legend><?= h($l['name']) ?> (<?= h($l['code']) ?>)<?= $is_def ? ' — idioma padrão' : '' ?></legend>
          <div class="adm-field">
            <label>Título da home
              <input type="text" name="home[<?= $l['id'] ?>][title]"
                     value="<?= h($home_trans[$l['id']]['title'] ?? '') ?>">
            </label>
          </div>
          <div class="adm-field">
            <label>Texto de boas-vindas
              <div class="adm-editor-toolbar">
                <button type="button" class="adm-btn adm-btn-sm italic-btn"
                        data-target="home_content_<?= $l['id'] ?>"><em>I</em> Itálico</button>
              </div>
              <textarea id="home_content_<?= $l['id'] ?>"
                        name="home[<?= $l['id'] ?>][content]"
                        rows="6"
                        class="adm-content-area"><?= h($home_trans[$l['id']]['content'] ?? '') ?></textarea>
            </label>
          </div>
        </fieldset>
        <?php endforeach; ?>
      </div>

      <div class="adm-card" style="margin-bottom:1.5rem">
        <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem">E-mail (SMTP)</h2>
        <p style="font-size:.82rem;color:var(--adm-muted);margin-bottom:1rem">
          Necessário para envio de e-mails de verificação de cadastro e troca de e-mail.
          Deixe smtp_host vazio para usar o <code>mail()</code> nativo do PHP.
        </p>
        <div class="adm-fields-row">
          <div class="adm-field">
            <label>Host SMTP
              <input type="text" name="smtp_host" placeholder="smtp.example.com"
                     value="<?= h($cfg['smtp_host'] ?? '') ?>">
            </label>
          </div>
          <div class="adm-field" style="max-width:100px">
            <label>Porta
              <input type="number" name="smtp_port" placeholder="587"
                     value="<?= h($cfg['smtp_port'] ?? '587') ?>">
            </label>
          </div>
          <div class="adm-field" style="max-width:120px">
            <label>Encryption
              <select name="smtp_encryption">
                <option value="tls" <?= ($cfg['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                <option value="ssl" <?= ($cfg['smtp_encryption'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL</option>
                <option value=""    <?= ($cfg['smtp_encryption'] ?? '') === ''      ? 'selected' : '' ?>>Nenhuma</option>
              </select>
            </label>
          </div>
        </div>
        <div class="adm-fields-row">
          <div class="adm-field">
            <label>Usuário SMTP
              <input type="text" name="smtp_user" value="<?= h($cfg['smtp_user'] ?? '') ?>">
            </label>
          </div>
          <div class="adm-field">
            <label>Senha SMTP
              <input type="password" name="smtp_pass" placeholder="(deixe vazio para manter)"
                     autocomplete="new-password">
            </label>
          </div>
        </div>
        <div class="adm-fields-row">
          <div class="adm-field">
            <label>E-mail remetente (From)
              <input type="email" name="smtp_from" value="<?= h($cfg['smtp_from'] ?? '') ?>">
            </label>
          </div>
          <div class="adm-field">
            <label>Nome remetente
              <input type="text" name="smtp_from_name" value="<?= h($cfg['smtp_from_name'] ?? '') ?>">
            </label>
          </div>
        </div>
      </div>

      <div class="adm-actions">
        <button type="submit" class="adm-btn adm-btn-primary">Salvar configurações</button>
      </div>
    </form>
    <?php
    admin_wrap('Design', 'design', ob_get_clean(), $flash);
    exit;
}

/* ── Comentários (moderação) ────────────────────────────────────────── */
if ($section === 'comments') {
    $filter = $_GET['filter'] ?? 'pending';
    $allowed_filters = ['pending', 'visible', 'hidden', 'all'];
    if (!in_array($filter, $allowed_filters, true)) $filter = 'pending';

    $where = $filter === 'all' ? '' : "WHERE cm.status = '$filter'";

    $comments_list = [];
    $res = mysqli_query($db,
        "SELECT cm.id, cm.paragraph_index, cm.body, cm.status, cm.score, cm.created_at,
                r.id AS reader_id, r.username, r.trusted_at,
                ct.title AS chapter_title, ct.chapter_id,
                bt.title AS book_title
         FROM comments cm
         JOIN readers r   ON r.id  = cm.reader_id
         JOIN chapters ch ON ch.id = cm.chapter_id
         LEFT JOIN chapters_t ct ON ct.chapter_id = ch.id
         LEFT JOIN books b       ON b.id = ch.book_id
         LEFT JOIN books_t bt    ON bt.book_id = b.id
         $where
         GROUP BY cm.id
         ORDER BY cm.created_at DESC
         LIMIT 200");
    while ($r = mysqli_fetch_assoc($res)) $comments_list[] = $r;

    ob_start(); ?>
    <div style="margin-bottom:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <?php foreach (['pending' => 'Pendentes', 'visible' => 'Aprovados', 'hidden' => 'Ocultos', 'all' => 'Todos'] as $f => $label): ?>
      <a href="admin.php?section=comments&amp;filter=<?= $f ?>"
         class="adm-btn adm-btn-sm <?= $filter === $f ? 'adm-btn-primary' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$comments_list): ?>
    <p style="color:var(--adm-muted)">Nenhum comentário nesta categoria.</p>
    <?php else: ?>
    <table class="adm-table">
      <thead><tr><th>Leitor</th><th>Cap./§</th><th>Comentário</th><th>Score</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($comments_list as $cm): ?>
      <tr>
        <td>
          <?= h($cm['username']) ?>
          <?php if (!$cm['trusted_at']): ?>
          <form method="post" class="inline" style="display:inline">
            <input type="hidden" name="_action"   value="trust_reader">
            <input type="hidden" name="reader_id" value="<?= (int)$cm['reader_id'] ?>">
            <button class="adm-btn adm-btn-sm" title="Marcar como confiável">★</button>
          </form>
          <?php else: ?>
          <span title="Confiável" style="color:var(--adm-accent)">★</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.82rem">
          <?= h($cm['book_title'] ?? '—') ?><br>
          <span style="color:var(--adm-muted)"><?= h($cm['chapter_title'] ?? '—') ?> §<?= (int)$cm['paragraph_index'] + 1 ?></span>
        </td>
        <td style="max-width:300px;word-break:break-word;font-size:.85rem"><?= h(mb_strimwidth($cm['body'], 0, 200, '…')) ?></td>
        <td><?= (int)$cm['score'] ?></td>
        <td>
          <?php $sc = $cm['status'];
                $colors = ['pending' => '#d97706', 'visible' => '#16a34a', 'hidden' => '#dc2626'];
                echo '<span style="color:' . ($colors[$sc] ?? '#888') . '">' . h($sc) . '</span>'; ?>
        </td>
        <td class="adm-td-actions">
          <?php if ($cm['status'] !== 'visible'): ?>
          <form method="post" class="inline">
            <input type="hidden" name="_action"    value="approve_comment">
            <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
            <button class="adm-btn adm-btn-sm adm-btn-primary">Aprovar</button>
          </form>
          <?php endif; ?>
          <?php if ($cm['status'] !== 'hidden'): ?>
          <form method="post" class="inline">
            <input type="hidden" name="_action"    value="reject_comment">
            <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
            <button class="adm-btn adm-btn-sm">Rejeitar</button>
          </form>
          <?php endif; ?>
          <form method="post" class="inline" onsubmit="return confirm('Excluir comentário?')">
            <input type="hidden" name="_action"    value="delete_comment">
            <input type="hidden" name="comment_id" value="<?= (int)$cm['id'] ?>">
            <button class="adm-btn adm-btn-sm adm-btn-danger">Excluir</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php
    admin_wrap('Comentários', 'comments', ob_get_clean(), $flash);
    exit;
}

// Fallback: redireciona para dashboard
redirect('admin.php');
