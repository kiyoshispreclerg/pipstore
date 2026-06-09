/* ── Inicialização ────────────────────────────────────────────────────── */
(function () {
  'use strict';

  // Aplica preferências salvas antes do primeiro paint (evita flash)
  applyPrefs();

  document.addEventListener('DOMContentLoaded', function () {
    setupToggleButtons();
    highlightLastReadChapter();
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
