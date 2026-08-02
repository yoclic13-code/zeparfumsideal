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

      if (q.type === 'budget') {
        renderBudgetInput(step, q, i);
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

    function renderBudgetInput(step, q, stepIndex) {
      answers[q.key] = '';

      var minBudget = parseFloat(q.min != null ? q.min : (window.QUIZ_MIN_BUDGET || 1));
      if (isNaN(minBudget) || minBudget <= 0) {
        minBudget = 1;
      }
      minBudget = Math.round(minBudget * 100) / 100;

      var wrap = document.createElement('div');
      wrap.className = 'budget-input';

      var field = document.createElement('div');
      field.className = 'budget-input-field';

      var input = document.createElement('input');
      input.type = 'number';
      input.inputMode = 'decimal';
      input.min = String(minBudget);
      input.step = '0.1';
      input.placeholder = String(minBudget).replace('.', ',');
      input.setAttribute('aria-label', q.question);
      input.autocomplete = 'off';

      var currency = document.createElement('span');
      currency.className = 'budget-input-currency';
      currency.textContent = '€';

      field.appendChild(input);
      field.appendChild(currency);
      wrap.appendChild(field);

      var hint = document.createElement('p');
      hint.className = 'budget-input-hint';
      hint.textContent = 'Minimum : ' + formatBudgetAmount(minBudget) + ' (parfum le moins cher du catalogue).';
      wrap.appendChild(hint);

      var error = document.createElement('p');
      error.className = 'budget-input-error';
      error.hidden = true;
      error.textContent = 'Le budget ne peut pas être inférieur à ' + formatBudgetAmount(minBudget) + '.';
      wrap.appendChild(error);

      var continueBtn = document.createElement('button');
      continueBtn.type = 'button';
      continueBtn.className = 'btn-primary budget-input-continue';
      continueBtn.textContent = 'Continuer';
      continueBtn.disabled = true;

      function syncBudget(clamp) {
        var raw = String(input.value || '').trim().replace(',', '.');
        var amount = parseFloat(raw);

        if (raw === '' || isNaN(amount)) {
          answers[q.key] = '';
          continueBtn.disabled = true;
          error.hidden = true;
          return;
        }

        if (amount < minBudget) {
          if (clamp) {
            amount = minBudget;
            input.value = String(minBudget);
            error.hidden = true;
          } else {
            answers[q.key] = '';
            continueBtn.disabled = true;
            error.hidden = false;
            return;
          }
        } else {
          error.hidden = true;
        }

        // Conserve 1 décimale utile (ex. 7,9) sans bruit.
        amount = Math.round(amount * 10) / 10;
        answers[q.key] = String(amount);
        continueBtn.disabled = false;
      }

      input.addEventListener('input', function () {
        syncBudget(false);
      });
      input.addEventListener('blur', function () {
        syncBudget(true);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          syncBudget(true);
          if (!continueBtn.disabled) {
            goToStep(stepIndex + 1);
          }
        }
      });

      continueBtn.addEventListener('click', function () {
        syncBudget(true);
        if (continueBtn.disabled) return;
        goToStep(stepIndex + 1);
      });
      wrap.appendChild(continueBtn);

      var skipBtn = document.createElement('button');
      skipBtn.type = 'button';
      skipBtn.className = 'budget-input-skip';
      skipBtn.textContent = 'Pas de limite';
      skipBtn.addEventListener('click', function () {
        answers[q.key] = '0';
        goToStep(stepIndex + 1);
      });
      wrap.appendChild(skipBtn);

      step.appendChild(wrap);

      setTimeout(function () {
        try { input.focus(); } catch (err) { /* ignore */ }
      }, 80);
    }

    function formatBudgetAmount(value) {
      return String(value).replace('.', ',') + ' €';
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
