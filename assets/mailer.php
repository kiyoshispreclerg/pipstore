<?php
declare(strict_types=1);

/*
 * Mailer SMTP sem dependências externas.
 * Adaptado de /home/kiyoshi/Dropbox/alternativa_carteira/includes/mailer.php
 *
 * Configuração via site_settings (chaves smtp_*):
 *   smtp_host, smtp_port, smtp_encryption (tls|ssl),
 *   smtp_user, smtp_pass, smtp_from, smtp_from_name
 *
 * Chamada principal: smtp_send($cfg, $to, $subject, $html)
 */

function smtp_send(array $cfg, string $to, string $subject, string $html): bool {
    $host     = (string)($cfg['smtp_host']       ?? '');
    $port     = (int)   ($cfg['smtp_port']       ?? 587);
    $enc      = strtolower((string)($cfg['smtp_encryption'] ?? 'tls'));
    $user     = (string)($cfg['smtp_user']       ?? '');
    $pass     = (string)($cfg['smtp_pass']       ?? '');
    $from     = (string)($cfg['smtp_from']       ?? $user);
    $fromName = (string)($cfg['smtp_from_name']  ?? ($cfg['site_name'] ?? 'Histórias'));
    $timeout  = 15;

    if ($host === '' || $from === '' || $user === '' || $pass === '') return false;

    $transport = $enc === 'ssl' ? 'ssl://' . $host : $host;
    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) return false;

    stream_set_timeout($fp, $timeout);

    if (!_smtp_expect($fp, [220])) { fclose($fp); return false; }
    if (!_smtp_cmd($fp, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), [250])) { fclose($fp); return false; }

    if ($enc === 'tls') {
        if (!_smtp_cmd($fp, 'STARTTLS', [220])) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        if (!_smtp_cmd($fp, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), [250])) { fclose($fp); return false; }
    }

    if (!_smtp_cmd($fp, 'AUTH LOGIN', [334])) { fclose($fp); return false; }
    if (!_smtp_cmd($fp, base64_encode($user), [334])) { fclose($fp); return false; }
    if (!_smtp_cmd($fp, base64_encode($pass), [235])) { fclose($fp); return false; }

    if (!_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250])) { fclose($fp); return false; }
    if (!_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251])) { fclose($fp); return false; }
    if (!_smtp_cmd($fp, 'DATA', [354])) { fclose($fp); return false; }

    $headers   = [];
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'From: ' . _smtp_name($fromName) . ' <' . $from . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';

    $body = chunk_split(base64_encode($html), 76, "\r\n");
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    fwrite($fp, $data . "\r\n");
    if (!_smtp_expect($fp, [250])) { fclose($fp); return false; }

    _smtp_cmd($fp, 'QUIT', [221]);
    fclose($fp);
    return true;
}

function _smtp_cmd($fp, string $cmd, array $ok): bool {
    fwrite($fp, $cmd . "\r\n");
    return _smtp_expect($fp, $ok);
}

function _smtp_expect($fp, array $ok): bool {
    $line = '';
    while (($part = fgets($fp, 515)) !== false) {
        $line = $part;
        if (strlen($part) < 4) break;
        if ($part[3] === ' ') break;
    }
    if ($line === '') return false;
    return in_array((int)substr($line, 0, 3), $ok, true);
}

function _smtp_name(string $name): string {
    $clean = trim(preg_replace('/[\r\n]+/', ' ', $name));
    return '=?UTF-8?B?' . base64_encode($clean) . '?=';
}

function mail_layout_stories(string $titulo, string $body_html, string $cta_label, string $cta_url, string $site_name): string {
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cta = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">'
         . '<tr><td style="border-radius:8px;background:#059669;">'
         . '<a href="' . $h($cta_url) . '" style="display:inline-block;padding:10px 20px;font-size:14px;'
         . 'font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">' . $h($cta_label) . '</a>'
         . '</td></tr></table>';

    return '<!doctype html><html lang="pt"><head><meta charset="UTF-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1.0"></head>'
         . '<body style="margin:0;padding:0;background:#f6f7f8;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f8;padding:32px 16px;">'
         . '<tr><td align="center">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'
         . '<tr><td style="padding:0 4px 20px;font-weight:700;color:#111827;font-size:16px;">' . $h($site_name) . '</td></tr>'
         . '<tr><td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;">'
         . '<h1 style="margin:0 0 16px;font-size:18px;color:#111827;">' . $h($titulo) . '</h1>'
         . $body_html
         . $cta
         . '</td></tr>'
         . '<tr><td style="padding:20px 4px 0;color:#9ca3af;font-size:12px;">'
         . 'Você recebeu este e-mail por ter se cadastrado em ' . $h($site_name) . '.'
         . '</td></tr>'
         . '</table></td></tr></table>'
         . '</body></html>';
}
