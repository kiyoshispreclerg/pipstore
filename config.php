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

function load_settings(mysqli $db): array {
    $defaults = ['site_name' => SITE_NAME, 'accent_color' => '#2e7d52', 'logo_url' => ''];
    $res = mysqli_query($db, 'SELECT `key`, `value` FROM site_settings');
    if (!$res) return $defaults;
    while ($r = mysqli_fetch_assoc($res)) {
        $defaults[$r['key']] = $r['value'];
    }
    return $defaults;
}
