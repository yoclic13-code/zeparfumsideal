/**
 * Trouvez Votre Parfum — logique du quiz (vanilla JS, sans framework).
 */
(function () {
  'use strict';

  initParallax();

  var mode = window.QUIZ_MODE;
  if (!mode) return; // page sans quiz (accueil, résultat...)

  var progressFill = document.getElementById('progressFill');

  if (mode === 'quiz') {
    initQuizMode();
  } else {
    initFavoriteMode();
  }

  /* ---------------------------------------------------------
     Parcours A : quiz classique
     --------------------------------------------------------- */
  function initQuizMode() {
    var questions = window.QUIZ_QUESTIONS || [];
    var container = document.getElementById('quizQuestions');
    var answers = {};
    var currentIndex = 0;

    questions.forEach(function (q, i) {
      var step = document.createElement('section');
      step.className = 'quiz-step' + (i === 0 ? ' active' : '');
      step.dataset.step = i;

      var eyebrow = document.createElement('p');
      eyebrow.className = 'eyebrow';
      eyebrow.textContent = 'Question ' + (i + 1) + ' / ' + questions.length;
      step.appendChild(eyebrow);

      var h2 = document.createElement('h2');
      h2.className = 'quiz-question';
      h2.textContent = q.question;
      step.appendChild(h2);

      if (q.type === 'slider') {
        renderBudgetSlider(step, q, i);
        container.appendChild(step);
        return;
      }

      var hasImages = q.options.some(function (opt) { return !!opt.image; });

      var grid = document.createElement('div');
      grid.className = hasImages ? 'options-grid options-grid-images' : 'options-grid';

      var isMulti = !!q.multi;
      var maxSelect = q.maxSelect || 3;
      var continueBtn = null;
      var giftPanel = null;

      if (isMulti) {
        answers[q.key] = [];
      }

      if (q.key === 'occasion') {
        giftPanel = document.createElement('div');
        giftPanel.className = 'gift-options';
        giftPanel.hidden = true;

        var giftLabel = document.createElement('label');
        giftLabel.className = 'gift-options-check';

        var giftCheckbox = document.createElement('input');
        giftCheckbox.type = 'checkbox';
        giftCheckbox.id = 'coffretsOnlyCheck';

        var giftLabelText = document.createElement('span');
        giftLabelText.textContent = 'Uniquement des coffrets';

        giftLabel.appendChild(giftCheckbox);
        giftLabel.appendChild(giftLabelText);
        giftPanel.appendChild(giftLabel);

        var giftHint = document.createElement('p');
        giftHint.className = 'gift-options-hint';
        giftHint.textContent = 'Décochez pour voir aussi les parfums classiques.';
        giftPanel.appendChild(giftHint);

        var giftContinueBtn = document.createElement('button');
        giftContinueBtn.type = 'button';
        giftContinueBtn.className = 'btn-primary gift-options-continue';
        giftContinueBtn.textContent = 'Continuer';
        giftContinueBtn.addEventListener('click', function () {
          answers.coffrets_only = giftCheckbox.checked;
          goToStep(i + 1);
        });
        giftPanel.appendChild(giftContinueBtn);
      }

      q.options.forEach(function (opt) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = hasImages ? 'option-btn option-btn-image' : 'option-btn';
        btn.dataset.value = opt.value;

        if (opt.image) {
          var imgEl = document.createElement('img');
          imgEl.className = 'option-image';
          imgEl.src = opt.image;
          imgEl.alt = opt.label;
          btn.appendChild(imgEl);
        }

        var labelEl = document.createElement('span');
        labelEl.className = 'option-label';
        labelEl.textContent = opt.label;
        btn.appendChild(labelEl);

        if (opt.hint) {
          var hintEl = document.createElement('span');
          hintEl.className = 'option-hint';
          hintEl.textContent = opt.hint;
          btn.appendChild(hintEl);
        }

        btn.addEventListener('click', function () {
          if (isMulti) {
            var selected = answers[q.key];
            var pos = selected.indexOf(opt.value);
            if (pos !== -1) {
              selected.splice(pos, 1);
              btn.classList.remove('selected');
            } else if (selected.length < maxSelect) {
              selected.push(opt.value);
              btn.classList.add('selected');
            }
            if (continueBtn) {
              continueBtn.disabled = selected.length === 0;
            }
          } else {
            answers[q.key] = opt.value;

            if (q.key === 'occasion' && opt.value === 'cadeau') {
              grid.querySelectorAll('.option-btn').forEach(function (b) {
                b.classList.toggle('selected', b.dataset.value === 'cadeau');
              });
              if (giftPanel) {
                giftCheckbox.checked = false;
                giftPanel.hidden = false;
              }
              return;
            }

            if (q.key === 'occasion' && giftPanel) {
              giftPanel.hidden = true;
              delete answers.coffrets_only;
            }

            goToStep(i + 1);
          }
        });
        grid.appendChild(btn);
      });

      step.appendChild(grid);

      if (giftPanel) {
        step.appendChild(giftPanel);
      }

      if (isMulti) {
        var hintText = document.createElement('p');
        hintText.className = 'multi-select-hint';
        hintText.textContent = 'Choisissez jusqu\'à ' + maxSelect + ' familles';
        step.insertBefore(hintText, grid);

        continueBtn = document.createElement('button');
        continueBtn.type = 'button';
        continueBtn.className = 'btn-primary';
        continueBtn.style.marginTop = '2rem';
        continueBtn.textContent = 'Continuer';
        continueBtn.disabled = true;
        continueBtn.addEventListener('click', function () {
          goToStep(i + 1);
        });
        step.appendChild(continueBtn);
      }

      container.appendChild(step);
    });

    updateProgress(0, questions.length + 1);

    function renderBudgetSlider(step, q, stepIndex) {
      var options = q.options || [];
      var defaultIndex = Math.min(1, options.length - 1);
      answers[q.key] = options[defaultIndex] ? options[defaultIndex].value : '0';

      var wrap = document.createElement('div');
      wrap.className = 'budget-slider';

      var valueLabel = document.createElement('p');
      valueLabel.className = 'budget-slider-value';
      valueLabel.textContent = options[defaultIndex] ? options[defaultIndex].label : '';
      wrap.appendChild(valueLabel);

      var track = document.createElement('div');
      track.className = 'budget-slider-track';
      track.setAttribute('role', 'slider');
      track.setAttribute('tabindex', '0');
      track.setAttribute('aria-valuemin', '0');
      track.setAttribute('aria-valuemax', String(options.length - 1));
      track.setAttribute('aria-valuenow', String(defaultIndex));
      track.setAttribute('aria-label', q.question);

      var fill = document.createElement('div');
      fill.className = 'budget-slider-fill';
      track.appendChild(fill);

      var thumb = document.createElement('div');
      thumb.className = 'budget-slider-thumb';
      track.appendChild(thumb);

      var ticks = document.createElement('div');
      ticks.className = 'budget-slider-ticks';

      options.forEach(function (opt, idx) {
        var tick = document.createElement('button');
        tick.type = 'button';
        tick.className = 'budget-slider-tick' + (idx === defaultIndex ? ' active' : '');
        tick.dataset.index = String(idx);
        tick.setAttribute('aria-label', opt.label);
        tick.addEventListener('click', function () {
          setBudgetIndex(idx);
        });
        ticks.appendChild(tick);
      });

      track.appendChild(ticks);
      wrap.appendChild(track);

      var labels = document.createElement('div');
      labels.className = 'budget-slider-labels';
      options.forEach(function (opt, idx) {
        var label = document.createElement('button');
        label.type = 'button';
        label.className = 'budget-slider-label' + (idx === defaultIndex ? ' active' : '');
        label.textContent = opt.label;
        label.addEventListener('click', function () {
          setBudgetIndex(idx);
        });
        labels.appendChild(label);
      });
      wrap.appendChild(labels);

      var hint = document.createElement('p');
      hint.className = 'budget-slider-hint';
      hint.textContent = 'Glissez ou choisissez une tranche pour affiner les recommandations.';
      wrap.appendChild(hint);

      var continueBtn = document.createElement('button');
      continueBtn.type = 'button';
      continueBtn.className = 'btn-primary budget-slider-continue';
      continueBtn.textContent = 'Continuer';
      continueBtn.addEventListener('click', function () {
        goToStep(stepIndex + 1);
      });
      wrap.appendChild(continueBtn);

      step.appendChild(wrap);

      function setBudgetIndex(idx) {
        if (idx < 0 || idx >= options.length) return;
        answers[q.key] = options[idx].value;
        valueLabel.textContent = options[idx].label;
        track.setAttribute('aria-valuenow', String(idx));

        var pct = options.length > 1 ? (idx / (options.length - 1)) * 100 : 0;
        fill.style.width = pct + '%';
        thumb.style.left = pct + '%';

        wrap.querySelectorAll('.budget-slider-tick').forEach(function (el, i) {
          el.classList.toggle('active', i === idx);
        });
        wrap.querySelectorAll('.budget-slider-label').forEach(function (el, i) {
          el.classList.toggle('active', i === idx);
        });
      }

      setBudgetIndex(defaultIndex);

      track.addEventListener('keydown', function (e) {
        var current = parseInt(track.getAttribute('aria-valuenow') || '0', 10);
        if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
          e.preventDefault();
          setBudgetIndex(Math.min(options.length - 1, current + 1));
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
          e.preventDefault();
          setBudgetIndex(Math.max(0, current - 1));
        }
      });

      // Drag / click on track
      var dragging = false;

      function indexFromClientX(clientX) {
        var rect = track.getBoundingClientRect();
        var ratio = rect.width > 0 ? (clientX - rect.left) / rect.width : 0;
        ratio = Math.max(0, Math.min(1, ratio));
        return Math.round(ratio * (options.length - 1));
      }

      track.addEventListener('pointerdown', function (e) {
        dragging = true;
        track.setPointerCapture(e.pointerId);
        setBudgetIndex(indexFromClientX(e.clientX));
      });
      track.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        setBudgetIndex(indexFromClientX(e.clientX));
      });
      track.addEventListener('pointerup', function () {
        dragging = false;
      });
      track.addEventListener('pointercancel', function () {
        dragging = false;
      });
    }

    function goToStep(index) {
      var steps = container.querySelectorAll('.quiz-step');
      steps.forEach(function (s) { s.classList.remove('active'); });

      if (index >= steps.length) {
        submitQuiz(answers);
        return;
      }

      steps[index].classList.add('active');
      currentIndex = index;
      updateProgress(index, questions.length + 1);
    }

    // Bouton retour du header : revient à la question précédente
    var backBtn = document.querySelector('.btn-back');
    if (backBtn) {
      backBtn.addEventListener('click', function (e) {
        if (currentIndex > 0) {
          e.preventDefault();
          goToStep(currentIndex - 1);
        }
      });
    }
  }

  function submitQuiz(answers) {
    var flatAnswers = [];
    var coffretsOnly = false;
    var maxPrice = '';

    Object.keys(answers).forEach(function (key) {
      var value = answers[key];

      if (key === 'coffrets_only') {
        coffretsOnly = !!value;
        return;
      }

      if (key === 'budget') {
        maxPrice = String(value || '');
        return;
      }

      if (Array.isArray(value)) {
        flatAnswers = flatAnswers.concat(value);
      } else {
        flatAnswers.push(value);
      }
    });

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'result.php';

    appendHidden(form, 'mode', 'quiz');
    appendHidden(form, 'answers', JSON.stringify(flatAnswers));
    appendHidden(form, 'coffrets_only', coffretsOnly ? '1' : '0');
    appendHidden(form, 'max_price', maxPrice);

    document.body.appendChild(form);
    form.submit();
  }

  /* ---------------------------------------------------------
     Parcours B : partir d'un parfum aimé
     --------------------------------------------------------- */
  function initFavoriteMode() {
    var searchInput = document.getElementById('perfumeSearchInput');
    var resultsBox = document.getElementById('searchResults');
    var selectedCard = document.getElementById('selectedPerfumeCard');
    var nextBtn = document.getElementById('favoriteNextBtn');
    var preferenceButtons = document.querySelectorAll('#preferenceOptions .option-btn');

    var selectedPerfume = null;
    var debounceTimer = null;

    updateProgress(0, 2);

    searchInput.addEventListener('input', function () {
      var query = searchInput.value.trim();
      clearTimeout(debounceTimer);
      resultsBox.innerHTML = '';

      if (query.length < 2) return;

      debounceTimer = setTimeout(function () {
        fetch('search-perfume.php?q=' + encodeURIComponent(query))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            renderResults(data.results || []);
          })
          .catch(function () {
            resultsBox.innerHTML = '';
          });
      }, 300);
    });

    function renderResults(items) {
      resultsBox.innerHTML = '';
      items.forEach(function (p) {
        var item = document.createElement('div');
        item.className = 'search-result-item';

        var img = document.createElement('img');
        img.src = p.image_url || window.PLACEHOLDER_IMG;
        img.alt = p.name;
        img.onerror = function () {
          img.onerror = null;
          img.src = window.PLACEHOLDER_IMG;
        };
        item.appendChild(img);

        var textWrap = document.createElement('div');
        var name = document.createElement('div');
        name.className = 'sr-name';
        name.textContent = p.name;
        var brand = document.createElement('div');
        brand.className = 'sr-brand';
        brand.textContent = p.brand || '';
        textWrap.appendChild(name);
        textWrap.appendChild(brand);
        item.appendChild(textWrap);

        item.addEventListener('click', function () {
          selectPerfume(p);
        });

        resultsBox.appendChild(item);
      });
    }

    function selectPerfume(p) {
      selectedPerfume = p;
      searchInput.value = p.name;
      resultsBox.innerHTML = '';

      selectedCard.style.display = 'flex';
      selectedCard.innerHTML =
        '<img src="' + (p.image_url || window.PLACEHOLDER_IMG) + '" alt="" onerror="this.onerror=null;this.src=window.PLACEHOLDER_IMG;">' +
        '<div><div class="sr-name">' + escapeHtml(p.name) + '</div>' +
        '<div class="sr-brand">' + escapeHtml(p.brand || '') + '</div></div>';

      nextBtn.disabled = false;
    }

    nextBtn.addEventListener('click', function () {
      goToStep('preference');
      updateProgress(1, 2);
    });

    preferenceButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        preferenceButtons.forEach(function (b) { b.classList.remove('selected'); });
        btn.classList.add('selected');
        submitFavorite(selectedPerfume.id, btn.dataset.value);
      });
    });

    function goToStep(stepName) {
      document.querySelectorAll('.quiz-step').forEach(function (s) {
        s.classList.toggle('active', s.dataset.step === stepName);
      });
    }

    var backBtn = document.querySelector('.btn-back');
    if (backBtn) {
      backBtn.addEventListener('click', function (e) {
        var preferenceStep = document.querySelector('[data-step="preference"]');
        if (preferenceStep && preferenceStep.classList.contains('active')) {
          e.preventDefault();
          goToStep('search');
          updateProgress(0, 2);
        }
      });
    }
  }

  function submitFavorite(perfumeId, preference) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'result.php';

    appendHidden(form, 'mode', 'favorite');
    appendHidden(form, 'perfume_id', perfumeId);
    appendHidden(form, 'preference', preference);

    document.body.appendChild(form);
    form.submit();
  }

  /* ---------------------------------------------------------
     Utilitaires
     --------------------------------------------------------- */
  function appendHidden(form, name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }

  function updateProgress(current, total) {
    if (!progressFill) return;
    var pct = total > 0 ? (current / total) * 100 : 0;
    progressFill.style.width = pct + '%';
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  /* ---------------------------------------------------------
     Parallax de fond (mouvement de souris) — page d'accueil
     --------------------------------------------------------- */
  function initParallax() {
    var scene = document.querySelector('.parallax-scene');
    if (!scene) return;

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) return;

    var layers = Array.prototype.slice.call(scene.querySelectorAll('.parallax-layer'));
    if (!layers.length) return;

    var ticking = false;
    var pointerX = 0;
    var pointerY = 0;

    window.addEventListener('mousemove', function (e) {
      var w = window.innerWidth;
      var h = window.innerHeight;
      pointerX = (e.clientX / w) - 0.5;
      pointerY = (e.clientY / h) - 0.5;

      if (!ticking) {
        window.requestAnimationFrame(applyParallax);
        ticking = true;
      }
    });

    function applyParallax() {
      layers.forEach(function (layer) {
        var depth = parseFloat(layer.dataset.depth || '0.2');
        var moveX = pointerX * depth * 60;
        var moveY = pointerY * depth * 60;
        var base = layer.classList.contains('parallax-bottle') ? 'translate(-50%, -50%) ' : '';
        layer.style.transform = base + 'translate(' + moveX.toFixed(1) + 'px, ' + moveY.toFixed(1) + 'px)';
      });
      ticking = false;
    }
  }
})();
