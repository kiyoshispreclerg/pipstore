<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'kiyoshipip');
define('ADMIN_SESSION_KEY', 'stories_admin');
define('SITE_NAME', 'Histórias');

$db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$db) {
    http_response_code(500);
    die('Erro de conexão com o banco de dados: ' . mysqli_connect_error());
}
mysqli_set_charset($db, 'utf8mb4');

function current_reader(mysqli $db): ?array {
    $token = $_COOKIE['rsid'] ?? '';
    if ($token === '') return null;
    $st = mysqli_prepare($db,
        'SELECT r.id, r.username, r.email, r.trusted_at
         FROM reader_sessions s
         JOIN readers r ON r.id = s.reader_id
         WHERE s.token = ? AND s.expires_at > NOW() AND r.email_verified = 1
         LIMIT 1');
    mysqli_stmt_bind_param($st, 's', $token);
    mysqli_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) mysqli_free_result($res);
    mysqli_stmt_close($st);
    return $row ?: null;
}

function send_mail(mysqli $db, string $to, string $subject, string $html): bool {
    // Implementação completa na Etapa 10 (mailer.php).
    // Por enquanto tenta mail() nativo como fallback.
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";
    return @mail($to, $subject, $html, $headers);
}

function load_settings(mysqli $db): array {
    $defaults = ['site_name' => SITE_NAME, 'accent_color' => '#2e7d52', 'logo_url' => ''];
    $res = mysqli_query($db, 'SELECT `key`, `value` FROM site_settings');
    if (!$res) return $defaults;
    while ($r = mysqli_fetch_assoc($res)) {
        $defaults[$r['key']] = $r['value'];
    }
    return $defaults;
}
