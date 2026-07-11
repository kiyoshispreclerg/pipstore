<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

/* ══════════════════════════════════════════════════════════════════════
   IDIOMA
══════════════════════════════════════════════════════════════════════ */

function get_current_lang(mysqli $db): array {
    // Prioridade: GET > cookie > padrão do banco
    $code = '';
    if (!empty($_GET['lang'])) {
        $code = trim($_GET['lang']);
        // Persiste no cookie
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        setcookie('lang', $code, time() + 31536000, '/', '', $is_https, true);
    } elseif (!empty($_COOKIE['lang'])) {
        $code = trim($_COOKIE['lang']);
    }

    if ($code !== '') {
        $st = mysqli_prepare($db, 'SELECT id, code, name FROM languages WHERE code = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 's', $code);
        mysqli_execute($st);
        $row = stmt_fetch_one($st);
        if ($row) return $row;
    }

    // Fallback: idioma padrão
    $res = mysqli_query($db, 'SELECT id, code, name FROM languages WHERE is_default = 1 LIMIT 1');
    $row = mysqli_fetch_assoc($res);
    if ($row) return $row;

    // Fallback absoluto: primeiro idioma cadastrado
    $res = mysqli_query($db, 'SELECT id, code, name FROM languages ORDER BY id LIMIT 1');
    $row = mysqli_fetch_assoc($res);
    return $row ?: ['id' => 0, 'code' => 'pt', 'name' => 'Português'];
}

function get_all_langs(mysqli $db): array {
    $res = mysqli_query($db, 'SELECT id, code, name FROM languages ORDER BY name');
    $langs = [];
    while ($r = mysqli_fetch_assoc($res)) $langs[] = $r;
    return $langs;
}

// Executa stmt e retorna uma única linha, liberando o result set corretamente.
function stmt_fetch_one(mysqli_stmt $st): ?array {
    $res = mysqli_stmt_get_result($st);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) mysqli_free_result($res);
    mysqli_stmt_close($st);
    return $row ?: null;
}

// Executa stmt e retorna todas as linhas, liberando o result set.
function stmt_fetch_all(mysqli_stmt $st): array {
    $res = mysqli_stmt_get_result($st);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    if ($res) mysqli_free_result($res);
    mysqli_stmt_close($st);
    return $rows;
}

/* ══════════════════════════════════════════════════════════════════════
   FORMATAÇÃO DE CONTEÚDO
══════════════════════════════════════════════════════════════════════ */

function format_content(string $raw): string {
    if ($raw === '') return '';
    $escaped = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Restaura somente <em> e </em>
    $escaped = str_replace(
        ['&lt;em&gt;', '&lt;/em&gt;'],
        ['<em>',       '</em>'],
        $escaped
    );
    // Cada linha não-vazia = um parágrafo; linhas vazias são ignoradas
    $lines = explode("\n", $escaped);
    $html = '';
    $i = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $html .= '<p data-p="' . $i . '">' . $line . "</p>\n";
            $i++;
        }
    }
    return $html;
}

/* ══════════════════════════════════════════════════════════════════════
   VIEWS
══════════════════════════════════════════════════════════════════════ */

/* ── Home ────────────────────────────────────────────────────────────── */
function view_home(mysqli $db, array $lang): array {
    $lid       = (int)$lang['id'];
    $site_name = site_setting('site_name', SITE_NAME);

    // Texto da home personalizado (idioma atual → fallback qualquer idioma)
    $res  = mysqli_query($db, "SELECT title, content FROM home_t WHERE lang_id = $lid LIMIT 1");
    $home = mysqli_fetch_assoc($res) ?: null;
    if (!$home) {
        $res2 = mysqli_query($db, 'SELECT title, content FROM home_t ORDER BY lang_id LIMIT 1');
        $home = mysqli_fetch_assoc($res2) ?: null;
    }

    // Livros mais recentes (últimos 6 cadastrados), com título traduzido
    $sql = "SELECT b.id, b.slug, b.cover_image,
                   COALESCE(bt.title, bt2.title, b.slug) AS title,
                   COALESCE(st.title, st2.title, s.slug) AS series_title
            FROM books b
            JOIN series s ON s.id = b.series_id
            LEFT JOIN books_t   bt   ON bt.book_id   = b.id AND bt.lang_id = $lid
            LEFT JOIN books_t   bt2  ON bt2.book_id  = b.id
            LEFT JOIN series_t  st   ON st.series_id = s.id AND st.lang_id = $lid
            LEFT JOIN series_t  st2  ON st2.series_id = s.id
            WHERE b.is_published = 1
            GROUP BY b.id
            ORDER BY b.id DESC
            LIMIT 6";
    $res   = mysqli_query($db, $sql);
    $books = [];
    while ($r = mysqli_fetch_assoc($res)) $books[] = $r;

    ob_start(); ?>
    <div class="home-welcome">
      <h1 class="page-title"><?= h($home['title'] ?? $site_name) ?></h1>
      <?php if (!empty($home['content'])): ?>
      <div class="content-body"><?= format_content($home['content']) ?></div>
      <?php else: ?>
      <p class="page-subtitle">Bem-vindo. Escolha uma história para ler.</p>
      <?php endif; ?>
    </div>
    <hr class="divider">
    <?php if ($books): ?>
    <div class="home-recent-title">Histórias recentes</div>
    <div class="book-grid">
      <?php foreach ($books as $b): ?>
      <div class="book-card-wrap">
        <a href="?action=book&amp;slug=<?= ue($b['slug']) ?>" class="book-card">
          <div class="book-card-series"><?= h($b['series_title']) ?></div>
          <?= h($b['title']) ?>
        </a>
        <?= fav_btn('book', (int)$b['id']) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="empty-msg">Nenhuma história publicada ainda.</p>
    <?php endif; ?>
    <?php
    return ['title' => $site_name, 'body' => ob_get_clean(), 'action' => ''];
}

/* ── Lista de séries e livros ────────────────────────────────────────── */
function view_series(mysqli $db, array $lang): array {
    $lid = (int)$lang['id'];

    $sql = "SELECT s.id, s.slug,
                   COALESCE(st.title, st2.title, s.slug) AS title,
                   COALESCE(st.description, st2.description, '') AS description
            FROM series s
            LEFT JOIN series_t st  ON st.series_id  = s.id AND st.lang_id = $lid
            LEFT JOIN series_t st2 ON st2.series_id = s.id
            GROUP BY s.id
            ORDER BY s.sort_order, s.id";
    $res = mysqli_query($db, $sql);
    $series_list = [];
    while ($r = mysqli_fetch_assoc($res)) $series_list[] = $r;

    foreach ($series_list as &$s) {
        $sid = (int)$s['id'];
        $sql2 = "SELECT b.id, b.slug,
                        COALESCE(bt.title, bt2.title, b.slug) AS title
                 FROM books b
                 LEFT JOIN books_t bt  ON bt.book_id  = b.id AND bt.lang_id = $lid
                 LEFT JOIN books_t bt2 ON bt2.book_id = b.id
                 WHERE b.series_id = $sid AND b.is_published = 1
                 GROUP BY b.id
                 ORDER BY b.sort_order, b.id";
        $res2 = mysqli_query($db, $sql2);
        $s['books'] = [];
        while ($r2 = mysqli_fetch_assoc($res2)) $s['books'][] = $r2;
    }
    unset($s);

    ob_start(); ?>
    <h1 class="page-title">Séries &amp; Histórias</h1>
    <hr class="divider">
    <?php if (!$series_list): ?>
    <p class="empty-msg">Nenhuma série publicada ainda.</p>
    <?php endif; ?>
    <?php foreach ($series_list as $s): ?>
    <div class="series-block">
      <div class="series-title-row">
        <span class="series-title"><?= h($s['title']) ?></span>
        <?= fav_btn('series', (int)$s['id']) ?>
      </div>
      <?php if ($s['description']): ?>
      <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:.5rem"><?= h($s['description']) ?></p>
      <?php endif; ?>
      <?php if ($s['books']): ?>
      <div class="book-grid">
        <?php foreach ($s['books'] as $b): ?>
        <div class="book-card-wrap">
          <a href="?action=book&amp;slug=<?= ue($b['slug']) ?>" class="book-card">
            <div class="book-card-series"><?= h($s['title']) ?></div>
            <?= h($b['title']) ?>
          </a>
          <?= fav_btn('book', (int)$b['id']) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="empty-msg" style="padding:.5rem 0">Nenhum livro nesta série.</p>
      <?php endif; ?>
    </div>
    <hr class="divider">
    <?php endforeach; ?>
    <?php
    return ['title' => 'Séries — ' . site_setting('site_name', SITE_NAME), 'body' => ob_get_clean(), 'action' => 'series'];
}

/* ── Capa / índice do livro ──────────────────────────────────────────── */
function view_book(mysqli $db, string $slug, array $lang): array {
    $lid = (int)$lang['id'];

    $st = mysqli_prepare($db, 'SELECT b.id, b.slug, b.cover_image, s.slug AS series_slug
                                FROM books b JOIN series s ON s.id = b.series_id
                                WHERE b.slug = ? AND b.is_published = 1 LIMIT 1');
    mysqli_stmt_bind_param($st, 's', $slug);
    mysqli_execute($st);
    $book = stmt_fetch_one($st);

    if (!$book) {
        return not_found('Livro não encontrado.');
    }
    $bid = (int)$book['id'];

    // Tradução do livro (fallback para qualquer idioma disponível)
    $st2 = mysqli_prepare($db,
        "SELECT COALESCE(bt.title, bt2.title, ?) AS title,
                COALESCE(bt.copyright, bt2.copyright, '') AS copyright,
                COALESCE(bt.description, bt2.description, '') AS description
         FROM books b
         LEFT JOIN books_t bt  ON bt.book_id  = b.id AND bt.lang_id = ?
         LEFT JOIN books_t bt2 ON bt2.book_id = b.id
         WHERE b.id = ?
         GROUP BY b.id LIMIT 1");
    mysqli_stmt_bind_param($st2, 'sii', $slug, $lid, $bid);
    mysqli_execute($st2);
    $info = stmt_fetch_one($st2);

    // Capítulos
    $st3 = mysqli_prepare($db,
        "SELECT c.id, c.slug, c.sort_order,
                COALESCE(ct.title, ct2.title, c.slug) AS title
         FROM chapters c
         LEFT JOIN chapters_t ct  ON ct.chapter_id  = c.id AND ct.lang_id = ?
         LEFT JOIN chapters_t ct2 ON ct2.chapter_id = c.id
         WHERE c.book_id = ?
         GROUP BY c.id
         ORDER BY c.sort_order, c.id");
    mysqli_stmt_bind_param($st3, 'ii', $lid, $bid);
    mysqli_execute($st3);
    $chapters = stmt_fetch_all($st3);

    ob_start(); ?>
    <div class="book-cover">
      <?php if ($book['cover_image']): ?>
      <img src="<?= h($book['cover_image']) ?>" alt="<?= h($info['title']) ?>">
      <?php endif; ?>
      <div class="book-cover-info">
        <div style="display:flex;align-items:flex-start;gap:.5rem">
          <h1 class="page-title" style="flex:1"><?= h($info['title']) ?></h1>
          <?= fav_btn('book', $bid) ?>
        </div>
        <?php if ($info['copyright']): ?>
        <p class="book-copyright"><?= h($info['copyright']) ?></p>
        <?php endif; ?>
        <?php if ($info['description']): ?>
        <p class="page-subtitle"><?= h($info['description']) ?></p>
        <?php endif; ?>
        <a href="?action=series" class="btn" style="margin-top:.75rem;font-size:.8rem">← Séries</a>
      </div>
    </div>
    <hr class="divider">
    <?php if ($chapters): ?>
    <div class="chapter-list" data-book-slug="<?= h($book['slug']) ?>">
      <?php foreach ($chapters as $i => $ch): ?>
      <a href="?action=chapter&amp;slug=<?= ue($ch['slug']) ?>&amp;book=<?= ue($book['slug']) ?>"
         class="chapter-btn"
         data-chapter="<?= h($ch['slug']) ?>">
        <?= h($ch['title']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="empty-msg">Nenhum capítulo publicado ainda.</p>
    <?php endif; ?>
    <?php
    return ['title' => $info['title'] . ' — ' . site_setting('site_name', SITE_NAME), 'body' => ob_get_clean(), 'action' => 'book'];
}

/* ── Leitura de capítulo ─────────────────────────────────────────────── */
function view_chapter(mysqli $db, string $slug, array $lang): array {
    $lid = (int)$lang['id'];

    $book_slug = trim($_GET['book'] ?? '');
    if ($book_slug !== '') {
        $st = mysqli_prepare($db, 'SELECT c.id, c.book_id, c.slug, c.sort_order
                                    FROM chapters c
                                    JOIN books b ON b.id = c.book_id
                                    WHERE c.slug = ? AND b.slug = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 'ss', $slug, $book_slug);
    } else {
        $st = mysqli_prepare($db, 'SELECT c.id, c.book_id, c.slug, c.sort_order
                                    FROM chapters c WHERE c.slug = ? LIMIT 1');
        mysqli_stmt_bind_param($st, 's', $slug);
    }
    mysqli_execute($st);
    $ch = stmt_fetch_one($st);

    if (!$ch) {
        return not_found('Capítulo não encontrado.');
    }
    $cid  = (int)$ch['id'];
    $bid  = (int)$ch['book_id'];
    $sort = (int)$ch['sort_order'];

    // Conteúdo traduzido
    $st2 = mysqli_prepare($db,
        "SELECT COALESCE(ct.title, ct2.title, ?) AS title,
                COALESCE(ct.content, ct2.content, '') AS content
         FROM chapters c
         LEFT JOIN chapters_t ct  ON ct.chapter_id  = c.id AND ct.lang_id = ?
         LEFT JOIN chapters_t ct2 ON ct2.chapter_id = c.id
         WHERE c.id = ?
         GROUP BY c.id LIMIT 1");
    mysqli_stmt_bind_param($st2, 'sii', $slug, $lid, $cid);
    mysqli_execute($st2);
    $info = stmt_fetch_one($st2);

    // Livro pai (para voltar e para nav) — 404 se privado
    $st3 = mysqli_prepare($db,
        "SELECT b.slug AS book_slug,
                COALESCE(bt.title, bt2.title, b.slug) AS book_title
         FROM books b
         LEFT JOIN books_t bt  ON bt.book_id  = b.id AND bt.lang_id = ?
         LEFT JOIN books_t bt2 ON bt2.book_id = b.id
         WHERE b.id = ? AND b.is_published = 1
         GROUP BY b.id LIMIT 1");
    mysqli_stmt_bind_param($st3, 'ii', $lid, $bid);
    mysqli_execute($st3);
    $book = stmt_fetch_one($st3);

    if (!$book) {
        return not_found('Capítulo não encontrado.');
    }

    // Capítulo anterior e próximo
    $st4 = mysqli_prepare($db,
        "SELECT c.slug,
                COALESCE(ct.title, ct2.title, c.slug) AS title
         FROM chapters c
         LEFT JOIN chapters_t ct  ON ct.chapter_id  = c.id AND ct.lang_id = ?
         LEFT JOIN chapters_t ct2 ON ct2.chapter_id = c.id
         WHERE c.book_id = ? AND (c.sort_order < ? OR (c.sort_order = ? AND c.id < ?))
         GROUP BY c.id
         ORDER BY c.sort_order DESC, c.id DESC LIMIT 1");
    mysqli_stmt_bind_param($st4, 'iiiii', $lid, $bid, $sort, $sort, $cid);
    mysqli_execute($st4);
    $prev = stmt_fetch_one($st4);

    $st5 = mysqli_prepare($db,
        "SELECT c.slug,
                COALESCE(ct.title, ct2.title, c.slug) AS title
         FROM chapters c
         LEFT JOIN chapters_t ct  ON ct.chapter_id  = c.id AND ct.lang_id = ?
         LEFT JOIN chapters_t ct2 ON ct2.chapter_id = c.id
         WHERE c.book_id = ? AND (c.sort_order > ? OR (c.sort_order = ? AND c.id > ?))
         GROUP BY c.id
         ORDER BY c.sort_order ASC, c.id ASC LIMIT 1");
    mysqli_stmt_bind_param($st5, 'iiiii', $lid, $bid, $sort, $sort, $cid);
    mysqli_execute($st5);
    $next = stmt_fetch_one($st5);

    // Busca comentários visíveis agrupados por parágrafo
    $comments_by_para = [];
    $st6 = mysqli_prepare($db,
        'SELECT c.id, c.paragraph_index, c.body, c.score, c.status, c.created_at,
                r.username
         FROM comments c
         JOIN readers r ON r.id = c.reader_id
         WHERE c.chapter_id = ? AND c.status IN (\'visible\',\'hidden\')
         ORDER BY c.paragraph_index, c.score DESC, c.created_at ASC');
    mysqli_stmt_bind_param($st6, 'i', $cid);
    mysqli_execute($st6);
    $all_comments = stmt_fetch_all($st6);
    foreach ($all_comments as $cm) {
        $comments_by_para[(int)$cm['paragraph_index']][] = $cm;
    }

    $reader_id = (int)($GLOBALS['_reader']['id'] ?? 0);

    ob_start(); ?>
    <p style="font-family:var(--font-ui);font-size:.8rem;color:var(--text-muted);margin-bottom:1rem">
      <a href="?action=book&amp;slug=<?= ue($book['book_slug'] ?? '') ?>">← <?= h($book['book_title'] ?? 'Índice') ?></a>
    </p>
    <h1 class="page-title"><?= h($info['title']) ?></h1>
    <hr class="divider">
    <div class="content-body">
      <?= format_content($info['content']) ?>
    </div>
    <nav class="chapter-nav">
      <?php if ($prev): ?>
      <a href="?action=chapter&amp;slug=<?= ue($prev['slug']) ?>&amp;book=<?= ue($book['book_slug']) ?>" class="btn">← <?= h($prev['title']) ?></a>
      <?php else: ?>
      <span></span>
      <?php endif; ?>
      <?php if ($next): ?>
      <a href="?action=chapter&amp;slug=<?= ue($next['slug']) ?>&amp;book=<?= ue($book['book_slug']) ?>" class="btn"><?= h($next['title']) ?> →</a>
      <?php endif; ?>
    </nav>

    <!-- Modal de comentários (aberto por JS ao clicar no ícone do parágrafo) -->
    <div id="comment-modal" role="dialog" aria-modal="true" aria-label="Comentários">
      <div class="comment-modal-panel">
        <div class="comment-modal-header">
          <span id="cm-label">Comentários</span>
          <button class="comment-modal-close" id="cm-close" aria-label="Fechar">✕</button>
        </div>
        <div class="comment-modal-body">
          <blockquote class="comment-para-quote" id="cm-quote"></blockquote>
          <div class="comment-list" id="cm-list"></div>
          <div id="cm-form-area"></div>
        </div>
      </div>
    </div>

    <script>
      try {
        localStorage.setItem('lastChapter_' + <?= json_encode($book['book_slug'] ?? '') ?>, <?= json_encode($slug) ?>);
      } catch(e) {}
      window.__commentsByPara = <?= json_encode($comments_by_para, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      window.__readerId     = <?= $reader_id ?: 'null' ?>;
      window.__chapterId    = <?= $cid ?>;
      window.__loginUrl     = <?= json_encode('auth.php?a=login') ?>;
    </script>
    <?php
    return ['title' => $info['title'] . ' — ' . site_setting('site_name', SITE_NAME), 'body' => ob_get_clean(), 'action' => 'chapter'];
}

/* ── Bio / links ─────────────────────────────────────────────────────── */
function view_bio(mysqli $db): array {
    $res = mysqli_query($db, 'SELECT label, url FROM bio_links ORDER BY sort_order, id');
    $links = [];
    while ($r = mysqli_fetch_assoc($res)) $links[] = $r;

    ob_start(); ?>
    <h1 class="page-title">Bio &amp; Links</h1>
    <hr class="divider">
    <?php if ($links): ?>
    <div class="bio-links">
      <?php foreach ($links as $l): ?>
      <a href="<?= h($l['url']) ?>" target="_blank" rel="noopener noreferrer" class="bio-link">
        <?= h($l['label']) ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="empty-msg">Nenhum link cadastrado.</p>
    <?php endif; ?>
    <?php
    return ['title' => 'Bio — ' . site_setting('site_name', SITE_NAME), 'body' => ob_get_clean(), 'action' => 'bio'];
}

/* ── 404 ─────────────────────────────────────────────────────────────── */
function not_found(string $msg): array {
    http_response_code(404);
    ob_start(); ?>
    <h1 class="page-title">Não encontrado</h1>
    <p class="page-subtitle"><?= h($msg) ?></p>
    <a href="." class="btn" style="margin-top:1rem">← Início</a>
    <?php
    return ['title' => 'Não encontrado — ' . site_setting('site_name', SITE_NAME), 'body' => ob_get_clean(), 'action' => ''];
}

/* ══════════════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════════════ */

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ue(string $s): string {
    return urlencode($s);
}

// Botão de estrela para favoritar série ou livro
function fav_btn(string $type, int $id): string {
    $reader = $GLOBALS['_reader'] ?? null;
    $favs   = $GLOBALS['_favs'][$type] ?? [];
    $active = in_array($id, $favs, true);
    if (!$reader) {
        return '<a href="auth.php?a=login" class="fav-btn fav-hint" title="Entre para favoritar">☆</a>';
    }
    $star = $active ? '★' : '☆';
    return '<button class="fav-btn' . ($active ? ' active' : '') . '" '
         . 'data-fav-type="' . $type . '" data-fav-id="' . $id . '" '
         . 'title="' . ($active ? 'Remover dos favoritos' : 'Adicionar aos favoritos') . '" '
         . 'aria-pressed="' . ($active ? 'true' : 'false') . '">'
         . $star . '</button>';
}

// Acessa settings globais carregados no roteamento
function site_setting(string $key, string $default = ''): string {
    return (string)($GLOBALS['_settings'][$key] ?? $default);
}

// Converte #rrggbb para "r, g, b" (para uso em rgba())
function hex_to_rgb(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '46, 125, 82';
    return hexdec(substr($hex,0,2)) . ', ' . hexdec(substr($hex,2,2)) . ', ' . hexdec(substr($hex,4,2));
}

/* ══════════════════════════════════════════════════════════════════════
   ROTEAMENTO
══════════════════════════════════════════════════════════════════════ */

$lang              = get_current_lang($db);
$all_langs         = get_all_langs($db);
$GLOBALS['_settings'] = load_settings($db);
$reader            = current_reader($db);
$GLOBALS['_reader'] = $reader;

// Carrega favoritos do leitor logado (sets de IDs por tipo)
$GLOBALS['_favs'] = ['series' => [], 'book' => []];
if ($reader) {
    $rid_g = (int)$reader['id'];
    $fres  = mysqli_query($db, "SELECT type, target_id FROM reader_favorites WHERE reader_id = $rid_g");
    while ($fr = mysqli_fetch_assoc($fres)) {
        $GLOBALS['_favs'][$fr['type']][] = (int)$fr['target_id'];
    }
}

$action            = trim($_GET['action'] ?? '');
$slug              = trim($_GET['slug']   ?? '');

switch ($action) {
    case 'series':  $page = view_series($db, $lang);            break;
    case 'book':    $page = view_book($db, $slug, $lang);       break;
    case 'chapter': $page = view_chapter($db, $slug, $lang);    break;
    case 'bio':     $page = view_bio($db);                      break;
    default:        $page = view_home($db, $lang);              break;
}

/* ══════════════════════════════════════════════════════════════════════
   RENDER
══════════════════════════════════════════════════════════════════════ */

$_s         = $GLOBALS['_settings'];
$site_name  = $_s['site_name'] ?: SITE_NAME;
$accent     = $_s['accent_color'] ?: '#2e7d52';
$logo_url   = $_s['logo_url'] ?? '';
$accent_rgb = hex_to_rgb($accent);
?>
<!DOCTYPE html>
<html lang="<?= h($lang['code']) ?>" data-theme="light" data-font="serif" data-size="normal">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($page['title']) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    :root {
      --accent:       <?= h($accent) ?>;
      --accent-light: rgba(<?= $accent_rgb ?>, 0.12);
      --accent-hover: rgba(<?= $accent_rgb ?>, 0.2);
    }
  </style>
  <script>
    (function(){
      var root = document.documentElement;
      try {
        root.setAttribute('data-theme', localStorage.getItem('stories_theme') || 'light');
        root.setAttribute('data-font',  localStorage.getItem('stories_font')  || 'serif');
        root.setAttribute('data-size',  localStorage.getItem('stories_size')  || 'normal');
      } catch(e) {}
    }());
  </script>
</head>
<body>

<header id="site-header">
  <div class="header-inner">
    <?php if ($logo_url): ?>
    <a href="." class="site-logo-link">
      <img src="<?= h($logo_url) ?>" alt="<?= h($site_name) ?>" class="site-logo">
    </a>
    <?php else: ?>
    <a href="." class="site-title"><?= h($site_name) ?></a>
    <?php endif; ?>
    <nav class="header-nav">
      <a href="?action=series" <?= $page['action'] === 'series' ? 'class="active"' : '' ?>>Histórias</a>
      <a href="?action=bio"    <?= $page['action'] === 'bio'    ? 'class="active"' : '' ?>>Bio</a>
    </nav>
    <div class="header-controls">
      <button id="ctrl-theme" class="ctrl-btn" title="Alternar tema">☽ Escuro</button>
      <button id="ctrl-font"  class="ctrl-btn" title="Alternar fonte">Aa Sans</button>
      <button id="ctrl-size"  class="ctrl-btn" title="Tamanho da fonte">A+</button>
    </div>
  </div>
</header>

<main id="main-content">
  <?= $page['body'] ?>
</main>

<footer id="site-footer">
  <div class="footer-inner">
    <a href="https://github.com/kiyoshispreclerg/pipstore" class="footer-pipstore-link" target="_blank" rel="noopener">PipStore v<?= APP_VERSION ?></a>
    <div class="footer-auth">
      <?php if ($reader): ?>
      <span class="footer-username"><?= h($reader['username']) ?></span>
      <a href="auth.php?a=profile" class="footer-auth-link">Perfil</a>
      <a href="auth.php?a=logout" class="footer-auth-link">Sair</a>
      <?php else: ?>
      <a href="auth.php?a=login"    class="footer-auth-link">Entrar</a>
      <a href="auth.php?a=register" class="footer-auth-link">Cadastrar</a>
      <?php endif; ?>
    </div>
    <?php if (count($all_langs) > 1): ?>
    <select class="lang-select" onchange="changeLang(this.value)" title="Idioma">
      <?php foreach ($all_langs as $l): ?>
      <option value="<?= h($l['code']) ?>" <?= $l['code'] === $lang['code'] ? 'selected' : '' ?>>
        <?= h($l['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <span><?= h($lang['name']) ?></span>
    <?php endif; ?>
  </div>
</footer>

<script src="assets/script.js"></script>
</body>
</html>
