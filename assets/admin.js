/* ── Admin JS ─────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
  setupItalicButtons();
  autoSlugFromTitle();
});

/* ── Botão de itálico ────────────────────────────────────────────────── */
function setupItalicButtons() {
  document.querySelectorAll('.italic-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-target');
      var ta = document.getElementById(targetId);
      if (!ta) return;

      var start = ta.selectionStart;
      var end   = ta.selectionEnd;
      var val   = ta.value;

      if (start === end) {
        // Nada selecionado: insere marcador de posição
        var insert = '<em></em>';
        ta.value = val.slice(0, start) + insert + val.slice(end);
        ta.selectionStart = start + 4; // posiciona dentro das tags
        ta.selectionEnd   = start + 4;
      } else {
        var selected = val.slice(start, end);
        // Toggle: remove <em> se já estiver marcado
        if (selected.startsWith('<em>') && selected.endsWith('</em>')) {
          var inner = selected.slice(4, -5);
          ta.value = val.slice(0, start) + inner + val.slice(end);
          ta.selectionStart = start;
          ta.selectionEnd   = start + inner.length;
        } else {
          var wrapped = '<em>' + selected + '</em>';
          ta.value = val.slice(0, start) + wrapped + val.slice(end);
          ta.selectionStart = start;
          ta.selectionEnd   = start + wrapped.length;
        }
      }
      ta.focus();
    });
  });
}

/* ── Auto-slug a partir do primeiro título preenchido ────────────────── */
function autoSlugFromTitle() {
  var slugInput = document.querySelector('input[name="slug"]');
  if (!slugInput || slugInput.value.trim() !== '') return; // não sobrescreve slug existente

  var firstTitle = document.querySelector('input[name^="trans["][name$="[title]"]');
  if (!firstTitle) return;

  firstTitle.addEventListener('input', function () {
    if (slugInput.dataset.manualSlug) return;
    slugInput.value = slugify(firstTitle.value);
  });

  slugInput.addEventListener('input', function () {
    slugInput.dataset.manualSlug = '1';
  });
}

function slugify(str) {
  return str
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '') // remove diacríticos
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}
