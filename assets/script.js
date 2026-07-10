/* ── Inicialização ────────────────────────────────────────────────────── */
(function () {
  'use strict';

  // Aplica preferências salvas antes do primeiro paint (evita flash)
  applyPrefs();

  document.addEventListener('DOMContentLoaded', function () {
    setupToggleButtons();
    highlightLastReadChapter();
    setupComments();
    setupFavorites();
  });

  /* ── Preferências ───────────────────────────────────────────────────── */
  function applyPrefs() {
    var root = document.documentElement;
    root.setAttribute('data-theme', ls('theme') || 'light');
    root.setAttribute('data-font',  ls('font')  || 'serif');
    root.setAttribute('data-size',  ls('size')  || 'normal');
  }

  function ls(key, val) {
    try {
      if (val !== undefined) {
        localStorage.setItem('stories_' + key, val);
      } else {
        return localStorage.getItem('stories_' + key) || '';
      }
    } catch (e) { return ''; }
  }

  /* ── Botões de controle do header ───────────────────────────────────── */
  function setupToggleButtons() {
    var root = document.documentElement;

    var btnTheme = document.getElementById('ctrl-theme');
    var btnFont  = document.getElementById('ctrl-font');
    var btnSize  = document.getElementById('ctrl-size');

    if (btnTheme) {
      updateThemeBtn(btnTheme, root.getAttribute('data-theme'));
      btnTheme.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        ls('theme', next);
        updateThemeBtn(btnTheme, next);
      });
    }

    if (btnFont) {
      updateFontBtn(btnFont, root.getAttribute('data-font'));
      btnFont.addEventListener('click', function () {
        var next = root.getAttribute('data-font') === 'sans' ? 'serif' : 'sans';
        root.setAttribute('data-font', next);
        ls('font', next);
        updateFontBtn(btnFont, next);
      });
    }

    if (btnSize) {
      updateSizeBtn(btnSize, root.getAttribute('data-size'));
      btnSize.addEventListener('click', function () {
        var next = root.getAttribute('data-size') === 'large' ? 'normal' : 'large';
        root.setAttribute('data-size', next);
        ls('size', next);
        updateSizeBtn(btnSize, next);
      });
    }
  }

  function updateThemeBtn(btn, theme) {
    btn.textContent = theme === 'dark' ? '☀ Claro' : '☽ Escuro';
    btn.classList.toggle('active', theme === 'dark');
  }

  function updateFontBtn(btn, font) {
    btn.textContent = font === 'sans' ? 'Aa Serif' : 'Aa Sans';
    btn.classList.toggle('active', font === 'sans');
  }

  function updateSizeBtn(btn, size) {
    btn.textContent = size === 'large' ? 'A−' : 'A+';
    btn.classList.toggle('active', size === 'large');
  }

  /* ── Último capítulo lido ────────────────────────────────────────────── */

  // Chamado na página de capa/índice do livro
  function highlightLastReadChapter() {
    var list = document.querySelector('.chapter-list');
    if (!list) return;

    var bookSlug = list.getAttribute('data-book-slug');
    if (!bookSlug) return;

    try {
      var last = localStorage.getItem('lastChapter_' + bookSlug);
      if (!last) return;
      var btn = list.querySelector('[data-chapter="' + last + '"]');
      if (btn) btn.classList.add('last-read');
    } catch (e) {}
  }

  /* ── Sistema de comentários ─────────────────────────────────────────── */

  function setupComments() {
    if (typeof window.__chapterId === 'undefined') return;

    var byPara    = window.__commentsByPara || {};
    var modal     = document.getElementById('comment-modal');
    var cmClose   = document.getElementById('cm-close');
    var cmLabel   = document.getElementById('cm-label');
    var cmQuote   = document.getElementById('cm-quote');
    var cmList    = document.getElementById('cm-list');
    var cmFormArea= document.getElementById('cm-form-area');
    var activePara= null;

    if (!modal) return;

    // Adiciona botão em cada parágrafo com data-p
    var paras = document.querySelectorAll('.content-body p[data-p]');
    paras.forEach(function (p) {
      var idx = parseInt(p.getAttribute('data-p'), 10);
      var comments = byPara[idx] || [];
      var count = comments.filter(function (c) { return c.status === 'visible'; }).length;

      // Captura o texto antes de adicionar o botão
      var paraText = p.textContent.trim();

      var btn = document.createElement('button');
      btn.className = 'para-comment-btn' + (count > 0 ? ' has-comments' : '');
      btn.setAttribute('type', 'button');
      btn.setAttribute('aria-label', count > 0
        ? count + ' comentário(s) — clique para ver'
        : 'Adicionar comentário');
      btn.innerHTML = count > 0 ? ('💬 ' + count) : '+';
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        openModal(idx, paraText, comments);
      });
      p.appendChild(btn);

      // Clique no próprio parágrafo também abre o modal
      p.style.cursor = 'pointer';
      p.addEventListener('click', function (e) {
        if (e.target === btn) return;
        openModal(idx, paraText, comments);
      });
    });

    // Fecha ao clicar no backdrop ou no X
    cmClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();
    });

    function openModal(paraIdx, paraText, comments) {
      activePara = paraIdx;
      var count = comments.filter(function (c) { return c.status === 'visible'; }).length;
      cmLabel.textContent = count > 0
        ? count + ' comentário' + (count !== 1 ? 's' : '') + ' — parágrafo ' + (paraIdx + 1)
        : 'Parágrafo ' + (paraIdx + 1);
      cmQuote.textContent = paraText.length > 200 ? paraText.slice(0, 200) + '…' : paraText;
      renderCommentList(comments);
      renderForm(paraIdx);
      modal.classList.add('open');
      document.body.style.overflow = 'hidden';
    }

    function closeModal() {
      modal.classList.remove('open');
      document.body.style.overflow = '';
      activePara = null;
    }

    function renderCommentList(comments) {
      var visible = comments.filter(function (c) { return c.status !== 'pending'; });
      if (visible.length === 0) {
        cmList.innerHTML = '<p class="comment-empty">Nenhum comentário ainda.</p>';
        return;
      }
      cmList.innerHTML = '';
      visible.forEach(function (c) {
        var card = document.createElement('div');
        if (c.status === 'hidden') {
          card.className = 'comment-card hidden-comment';
          card.innerHTML = '<span class="comment-body">Comentário ocultado pela comunidade. '
            + '<button type="button" class="comment-reveal-btn" style="background:none;border:none;'
            + 'color:var(--accent);cursor:pointer;font-size:.8rem;font-family:var(--font-ui)">Mostrar</button></span>';
          var revBtn = card.querySelector('.comment-reveal-btn');
          revBtn.addEventListener('click', function () {
            renderHidden(card, c);
          });
        } else {
          card.className = 'comment-card';
          card.innerHTML =
            '<div class="comment-author">' + esc(c.username) + '</div>'
            + '<div class="comment-body">' + esc(c.body) + '</div>'
            + '<div class="comment-meta">'
            + '<button class="comment-vote-btn" data-vote="1" data-id="' + c.id + '">▲</button>'
            + '<span class="comment-score">' + c.score + '</span>'
            + '<button class="comment-vote-btn" data-vote="-1" data-id="' + c.id + '">▼</button>'
            + '</div>';
          setupVoteButtons(card, c);
        }
        cmList.appendChild(card);
      });
    }

    function renderHidden(card, c) {
      card.className = 'comment-card';
      card.innerHTML =
        '<div class="comment-author">' + esc(c.username) + '</div>'
        + '<div class="comment-body">' + esc(c.body) + '</div>'
        + '<div class="comment-meta">'
        + '<button class="comment-vote-btn" data-vote="1" data-id="' + c.id + '">▲</button>'
        + '<span class="comment-score">' + c.score + '</span>'
        + '<button class="comment-vote-btn" data-vote="-1" data-id="' + c.id + '">▼</button>'
        + '</div>';
      setupVoteButtons(card, c);
    }

    function setupVoteButtons(card, c) {
      card.querySelectorAll('.comment-vote-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (!window.__readerId) {
            window.location.href = window.__loginUrl || 'auth.php?a=login';
            return;
          }
          submitVote(parseInt(btn.getAttribute('data-id'), 10),
                     parseInt(btn.getAttribute('data-vote'), 10),
                     card.querySelector('.comment-score'));
        });
      });
    }

    function renderForm(paraIdx) {
      if (!window.__readerId) {
        cmFormArea.innerHTML =
          '<div class="comment-login-prompt">Para comentar, '
          + '<a href="' + esc(window.__loginUrl || 'auth.php?a=login') + '">entre</a> ou '
          + '<a href="auth.php?a=register">cadastre-se</a>.</div>';
        return;
      }
      cmFormArea.innerHTML =
        '<div class="comment-form">'
        + '<textarea placeholder="Seu comentário…" maxlength="1000" rows="3"></textarea>'
        + '<div class="comment-form-actions">'
        + '<button class="comment-submit" type="button">Publicar</button>'
        + '<button class="comment-cancel" type="button">Cancelar</button>'
        + '</div></div>';
      var ta     = cmFormArea.querySelector('textarea');
      var submit = cmFormArea.querySelector('.comment-submit');
      var cancel = cmFormArea.querySelector('.comment-cancel');
      cancel.addEventListener('click', function () { ta.value = ''; });
      submit.addEventListener('click', function () {
        var body = ta.value.trim();
        if (!body) return;
        submit.disabled = true;
        submitComment(paraIdx, body, function (ok, msg) {
          submit.disabled = false;
          if (ok) {
            ta.value = '';
            var notice = document.createElement('p');
            notice.className = 'comment-empty';
            notice.style.color = 'var(--accent)';
            notice.textContent = msg || 'Comentário enviado! Aguarde aprovação.';
            cmFormArea.insertBefore(notice, cmFormArea.firstChild);
            setTimeout(function () { notice.remove(); }, 4000);
          } else {
            alert(msg || 'Erro ao enviar comentário.');
          }
        });
      });
    }

    function submitComment(paraIdx, body, cb) {
      var fd = new FormData();
      fd.append('chapter_id', window.__chapterId);
      fd.append('paragraph_index', paraIdx);
      fd.append('body', body);
      fetch('auth.php?a=comment', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { cb(d.ok, d.msg); })
        .catch(function () { cb(false, 'Erro de rede.'); });
    }

    function submitVote(commentId, vote, scoreEl) {
      var fd = new FormData();
      fd.append('comment_id', commentId);
      fd.append('vote', vote);
      fetch('auth.php?a=vote', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok && scoreEl) scoreEl.textContent = d.score;
        });
    }

    function esc(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }
  }

  /* ── Favoritos ───────────────────────────────────────────────────────── */
  function setupFavorites() {
    document.querySelectorAll('.fav-btn[data-fav-type]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var type = btn.getAttribute('data-fav-type');
        var id   = btn.getAttribute('data-fav-id');
        var fd   = new FormData();
        fd.append('type',      type);
        fd.append('target_id', id);
        fetch('auth.php?a=favorite', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) return;
            btn.classList.toggle('active', d.favorited);
            btn.setAttribute('aria-pressed', d.favorited ? 'true' : 'false');
            btn.title      = d.favorited ? 'Remover dos favoritos' : 'Adicionar aos favoritos';
            btn.textContent = d.favorited ? '★' : '☆';
          })
          .catch(function () {});
      });
    });
  }

  /* ── Troca de idioma ─────────────────────────────────────────────────── */
  window.changeLang = function (code) {
    try {
      document.cookie = 'lang=' + encodeURIComponent(code) +
        ';path=/;max-age=31536000;SameSite=Lax';
    } catch (e) {}
    // Remove ?lang= da URL e recarrega para evitar loop
    var url = new URL(window.location.href);
    url.searchParams.delete('lang');
    window.location.href = url.toString();
  };
}());
