/**
 * Page d'accueil — effet machine à écrire sur le titre hero.
 */
(function () {
  'use strict';

  var root = document.getElementById('heroTypewriter');
  if (!root) return;

  var subtitle = document.querySelector('.home-subtitle');
  var segments = [
    { text: 'Trouvez ', accent: false, breakAfter: false },
    { text: '"Ze" Parfums', accent: true, breakAfter: true },
    { text: 'qui vous correspond.', accent: false, breakAfter: false }
  ];

  function delay(ms) {
    return new Promise(function (resolve) {
      window.setTimeout(resolve, ms);
    });
  }

  function renderStatic() {
    root.innerHTML =
      '<span class="typewriter-line">Trouvez <span class="typewriter-accent">"Ze" Parfums</span></span>' +
      '<span class="typewriter-line">qui vous correspond.</span>';
    revealSubtitle();
  }

  function revealSubtitle() {
    if (subtitle) {
      subtitle.classList.add('is-visible');
    }
  }

  function buildQueue() {
    var queue = [];

    segments.forEach(function (segment) {
      for (var i = 0; i < segment.text.length; i++) {
        queue.push({
          char: segment.text.charAt(i),
          accent: segment.accent
        });
      }
      if (segment.breakAfter) {
        queue.push({ break: true });
      }
    });

    return queue;
  }

  function typingDelay(char) {
    var pause = 62 + Math.random() * 48;

    if (char === ' ') pause += 70;
    if (char === ',') pause += 220;
    if (char === '.' || char === '!' || char === '?') pause += 780;

    return pause;
  }

  function appendChar(target, lineEl, cursor, char, accent) {
    var charEl = document.createElement('span');
    charEl.className = 'typewriter-char';

    if (char === ' ') {
      charEl.classList.add('typewriter-space');
      charEl.textContent = '\u00A0';
    } else {
      charEl.textContent = char;
    }

    if (accent) {
      target.appendChild(charEl);
    } else {
      lineEl.insertBefore(charEl, cursor);
    }
  }

  async function runTypewriter() {
    var queue = buildQueue();
    var lineEl = document.createElement('span');
    lineEl.className = 'typewriter-line';
    root.appendChild(lineEl);

    var accentEl = null;
    var cursor = document.createElement('span');
    cursor.className = 'typewriter-cursor';
    cursor.setAttribute('aria-hidden', 'true');
    lineEl.appendChild(cursor);

    root.setAttribute('aria-live', 'polite');

    await delay(900);

    for (var i = 0; i < queue.length; i++) {
      var item = queue[i];

      if (item.break) {
        lineEl = document.createElement('span');
        lineEl.className = 'typewriter-line';
        root.appendChild(lineEl);
        accentEl = null;
        lineEl.appendChild(cursor);
        await delay(800);
        continue;
      }

      if (item.accent && !accentEl) {
        accentEl = document.createElement('span');
        accentEl.className = 'typewriter-accent';
        lineEl.insertBefore(accentEl, cursor);
      } else if (!item.accent && accentEl) {
        accentEl = null;
      }

      appendChar(accentEl || lineEl, lineEl, cursor, item.char, !!accentEl);

      await delay(typingDelay(item.char));
    }

    cursor.classList.add('is-done');
    root.removeAttribute('aria-live');
    revealSubtitle();
  }

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    renderStatic();
    return;
  }

  runTypewriter();
})();
