<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'kiyoshipip');
define('ADMIN_SESSION_KEY', 'stories_admin');
define('SITE_NAME', 'Histórias');
define('APP_VERSION', '1.1.0');

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
    static $mailer_loaded = false;
    if (!$mailer_loaded) {
        require_once __DIR__ . '/assets/mailer.php';
        $mailer_loaded = true;
    }
    $cfg = load_settings($db);
    if (!empty($cfg['smtp_host'])) {
        return smtp_send($cfg, $to, $subject, $html);
    }
    // Fallback: mail() nativo
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $from = $cfg['smtp_from'] ?? ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $headers .= "From: $from\r\n";
    return @mail($to, $subject, $html, $headers);
}

function run_pending_migrations(mysqli $db): void {
    mysqli_query($db, "CREATE TABLE IF NOT EXISTS schema_migrations (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename   VARCHAR(200) NOT NULL,
        applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_filename (filename)
    ) ENGINE=InnoDB CHARSET=utf8mb4");

    $applied = [];
    $res = mysqli_query($db, 'SELECT filename FROM schema_migrations ORDER BY filename');
    if ($res) { while ($r = mysqli_fetch_row($res)) $applied[] = $r[0]; }

    $dir   = __DIR__ . '/migrations';
    $files = glob($dir . '/*.sql');
    if (!$files) return;
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) continue;

        $sql = file_get_contents($file);
        mysqli_multi_query($db, $sql);
        // Drena todos os result-sets antes de continuar
        do {
            $r = mysqli_store_result($db);
            if ($r) mysqli_free_result($r);
        } while (mysqli_more_results($db) && mysqli_next_result($db));

        $st = mysqli_prepare($db, 'INSERT IGNORE INTO schema_migrations (filename) VALUES (?)');
        mysqli_stmt_bind_param($st, 's', $name);
        mysqli_execute($st);
        mysqli_stmt_close($st);
    }
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
