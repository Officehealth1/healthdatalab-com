/* ================================================
   HDLAssessment — Practice Assessment Module
   5-card interactive quiz with horizontal carousel
   ================================================ */
var HDLAssessment = (function () {
  'use strict';

  var QUESTIONS = [
    {
      title: 'THE DEAD END',
      subtitle: 'Your clients heal\u2026 then leave.',
      question: 'How often do clients come back after the initial problem is resolved?',
      options: [
        { text: 'Rarely \u2014 most disappear once they feel better', grade: 'ab' },
        { text: 'Some come back, but there\u2019s no real structure to it', grade: 'ab' },
        { text: 'Most stay \u2014 I have a strong ongoing relationship with them', grade: 'c' }
      ],
      resultAB: 'Overcoming a health issue matters \u2014 but helping a client understand their trajectory and set a new course? That\u2019s an inspiring journey. One where they need a guide. And they keep coming back.',
      resultC: 'That\u2019s great to hear \u2014 you\u2019re ahead of 97% of practitioners on client retention.'
    },
    {
      title: 'THE INVISIBLE IMPACT',
      subtitle: 'You know your work helps. But can you prove it?',
      question: 'Could you show measurable, long-term results to a GP, a new client, or in your marketing?',
      options: [
        { text: 'Not really \u2014 I rely on how clients say they feel', grade: 'ab' },
        { text: 'Partially \u2014 I track some things but nothing systematic', grade: 'ab' },
        { text: 'Yes \u2014 I have structured before-and-after data', grade: 'c' }
      ],
      resultAB: 'The HDL report gives clarity on where a client actually stands \u2014 helping you take a more holistic approach. Over time it shows progress that\u2019s often slow and hard to see, and highlights what would most benefit from tweaking. Useful for you, for them, and for others who need to see your work matters.',
      resultC: 'That\u2019s great to hear \u2014 you\u2019re ahead of 97% of practitioners on measuring impact.'
    },
    {
      title: 'THE REACTIVE TRAP',
      subtitle: 'Clients come to you when something\u2019s wrong. Then what?',
      question: 'Is your practice mostly problem-solving, or do you help people build long-term health?',
      options: [
        { text: 'Mostly reactive \u2014 clients come with a problem, I help fix it', grade: 'ab' },
        { text: 'A mix \u2014 some problem-solving, some prevention', grade: 'ab' },
        { text: 'Mostly proactive \u2014 I help clients build resilience and capability', grade: 'c' }
      ],
      resultAB: 'The longevity approach layers on top of the fixing. While you address what\u2019s urgent, you\u2019re also building resilience and capacity \u2014 positive impact now, massive impact on a person\u2019s last two or three decades. Clients engage deeper, stay longer, and the work becomes far more rewarding for both of you.',
      resultC: 'That\u2019s great to hear \u2014 you\u2019re ahead of 97% of practitioners on proactive care.'
    },
    {
      title: 'THE FOLLOW-THROUGH PROBLEM',
      subtitle: 'You give the advice. They nod. Then nothing changes.',
      question: 'How often do your clients actually follow your recommendations?',
      options: [
        { text: 'Honestly? Most don\u2019t follow through consistently', grade: 'ab' },
        { text: 'Some do, but I have no way to track it', grade: 'ab' },
        { text: 'Most follow through \u2014 I can see it in their results', grade: 'c' }
      ],
      resultAB: 'When clients can see their own trajectory shifting in the data, they don\u2019t need convincing to follow through. The numbers do the motivating.',
      resultC: 'That\u2019s great to hear \u2014 you\u2019re ahead of 97% of practitioners on client follow-through.'
    },
    {
      title: 'THE DARK ROOM',
      subtitle: 'Could you show a client how far they\u2019ve come in the last year?',
      question: 'Do you have any way to measure long-term change \u2014 not just how they feel today?',
      options: [
        { text: 'No \u2014 I have no long-term data on my clients', grade: 'ab' },
        { text: 'Some notes but nothing visual or structured', grade: 'ab' },
        { text: 'Yes \u2014 I track and can show progress over time', grade: 'c' }
      ],
      resultAB: 'Repeat assessments show you exactly what\u2019s working, what\u2019s stalled, and where to focus. You stop guessing and start refining.',
      resultC: 'That\u2019s great to hear \u2014 you\u2019re ahead of 97% of practitioners on long-term tracking.'
    }
  ];

  var state = {
    answers: [null, null, null, null, null],
    activeCard: 0
  };

  var containerEl = null;
  var viewportEl = null;
  var resultEl = null;

  /* Measured dimensions */
  var viewportWidth = 0;
  var cardWidth = 0;
  var gap = 20;

  /* Touch tracking */
  var touchState = { startX: 0, startY: 0, startTime: 0, tracking: false };

  /* Auto-advance timer (cancel on manual navigation) */
  var autoAdvanceTimer = null;

  /* Resize debounce */
  var resizeTimer = null;

  /* ---------- helpers ---------- */
  var isDesktop = function () { return window.innerWidth >= 768; };
  var hasGSAP = function () { return typeof gsap !== 'undefined'; };

  /* ---------- measureDimensions ---------- */
  function measureDimensions() {
    if (!viewportEl) return;
    viewportWidth = viewportEl.offsetWidth;
    var firstCard = containerEl.querySelector('.assessment-card');
    if (firstCard) {
      cardWidth = firstCard.offsetWidth;
    }
    var computed = window.getComputedStyle(containerEl);
    gap = parseFloat(computed.gap) || (isDesktop() ? 20 : 12);
  }

  /* ---------- getTranslateXForCard ---------- */
  function getTranslateXForCard(index) {
    return (viewportWidth - cardWidth) / 2 - index * (cardWidth + gap);
  }

  /* ---------- panToCard ---------- */
  function panToCard(index, animate) {
    var tx = getTranslateXForCard(index);

    if (animate && hasGSAP()) {
      gsap.to(containerEl, { x: tx, duration: 0.6, ease: 'power3.inOut' });
    } else if (animate) {
      containerEl.style.transition = 'transform 0.6s cubic-bezier(0.33,1,0.68,1)';
      containerEl.style.transform = 'translateX(' + tx + 'px)';
      setTimeout(function () { containerEl.style.transition = ''; }, 650);
    } else {
      /* Instant — no animation */
      if (hasGSAP()) {
        gsap.set(containerEl, { x: tx });
      } else {
        containerEl.style.transform = 'translateX(' + tx + 'px)';
      }
    }
  }

  /* ---------- updateCardClasses ---------- */
  function updateCardClasses() {
    var cards = containerEl.querySelectorAll('.assessment-card');
    cards.forEach(function (card, i) {
      card.classList.remove('carousel-active', 'carousel-future', 'carousel-answered');
      if (i === state.activeCard) {
        card.classList.add('carousel-active');
      } else if (state.answers[i] !== null) {
        card.classList.add('carousel-answered');
      } else {
        card.classList.add('carousel-future');
      }
    });
  }

  /* ---------- createCard ---------- */
  function createCard(index) {
    var q = QUESTIONS[index];
    var card = document.createElement('div');

    card.className = 'assessment-card rounded-[32px] bg-surface overflow-hidden';
    card.setAttribute('data-card', index);

    card.innerHTML =
      '<div class="card-header p-4 md:p-5">' +
        '<p class="text-xs font-semibold text-primary tracking-widest uppercase mb-1 flex items-center gap-1.5">' + (index + 1) + ' OF 5' +
          '<svg class="card-check w-4 h-4 text-primary" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.306-3.497 4.49 4.49 0 0 1 3.498-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/></svg>' +
        '</p>' +
        '<h3 class="font-heading text-base md:text-lg text-dark font-medium leading-tight">' + q.title + '</h3>' +
      '</div>' +
      '<div class="card-body px-4 pb-4 md:px-5 md:pb-5">' +
        '<p class="text-sm text-body italic mb-3">' + q.subtitle + '</p>' +
        '<p class="text-sm text-body mb-4">' + q.question + '</p>' +
        '<div class="flex flex-col gap-2">' +
        q.options.map(function (opt, i) {
          return '<button class="assessment-option rounded-2xl border border-stroke px-4 py-3 text-left text-sm text-body ' +
            'hover:border-primary hover:bg-primary/5 transition-colors cursor-pointer" data-option="' + i + '">' +
            opt.text + '</button>';
        }).join('') +
        '</div>' +
        '<div class="assessment-result-text hidden mt-4 pt-4 border-t border-stroke/50 text-sm text-primary/90 italic leading-relaxed"></div>' +
      '</div>';

    /* Option click handlers */
    card.querySelectorAll('.assessment-option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (index !== state.activeCard) return;
        handleAnswer(index, parseInt(btn.getAttribute('data-option'), 10));
      });
    });

    /* Click-to-navigate */
    card.addEventListener('click', function (e) {
      if (e.target.closest('.assessment-option')) return;
      handleCardClick(index);
    });

    return card;
  }

  /* ---------- handleCardClick ---------- */
  function handleCardClick(index) {
    if (index === state.activeCard) return;
    /* Answered card — pan to it for read-only view */
    if (state.answers[index] !== null) {
      activateCard(index);
      return;
    }
    /* Next sequential unanswered — activate */
    var nextUnanswered = -1;
    for (var i = 0; i < 5; i++) {
      if (state.answers[i] === null) { nextUnanswered = i; break; }
    }
    if (index === nextUnanswered) {
      activateCard(index);
    }
    /* Out-of-order unanswered — blocked */
  }

  /* ---------- handleAnswer ---------- */
  function handleAnswer(cardIndex, optionIndex) {
    var wasAlreadyAnswered = state.answers[cardIndex] !== null;

    var q = QUESTIONS[cardIndex];
    var grade = q.options[optionIndex].grade;
    state.answers[cardIndex] = grade;

    var card = containerEl.querySelector('[data-card="' + cardIndex + '"]');
    if (!card) return;

    /* Highlight selected, dim others via CSS classes (toggleable on re-answer) */
    card.querySelectorAll('.assessment-option').forEach(function (btn, i) {
      btn.classList.toggle('selected', i === optionIndex);
      btn.classList.toggle('not-selected', i !== optionIndex);
    });

    /* Show/update result text */
    var resultDiv = card.querySelector('.assessment-result-text');
    resultDiv.textContent = grade === 'c' ? q.resultC : q.resultAB;
    resultDiv.classList.remove('hidden');
    if (!wasAlreadyAnswered && hasGSAP()) {
      gsap.from(resultDiv, { opacity: 0, y: 8, duration: 0.4, ease: 'power2.out' });
    }

    /* Add "Next" button for manual advancement (skip if already present) */
    var cardBody = card.querySelector('.card-body');
    if (!cardBody.querySelector('.assessment-next-btn')) {
      var isLast = cardIndex === 4 && state.answers.every(function (a) { return a !== null; });
      var nextBtn = document.createElement('button');
      nextBtn.className = 'assessment-next-btn rounded-[48px] bg-primary text-white text-sm font-medium px-6 py-2.5 hover:bg-primary-dark transition-colors cursor-pointer mt-4';
      nextBtn.textContent = isLast ? 'See your results →' : 'Next →';
      nextBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (isLast) { showAllAnswered(); } else { activateCard(cardIndex + 1); }
      });
      cardBody.appendChild(nextBtn);
      if (hasGSAP()) {
        gsap.from(nextBtn, { opacity: 0, y: 8, duration: 0.4, ease: 'power2.out', delay: 0.1 });
      }
    } else if (cardIndex === 4) {
      /* Update last card button text if all now answered */
      var existingBtn = cardBody.querySelector('.assessment-next-btn');
      var nowAllAnswered = state.answers.every(function (a) { return a !== null; });
      existingBtn.textContent = nowAllAnswered ? 'See your results →' : 'Next →';
    }
  }

  /* ---------- activateCard ---------- */
  function activateCard(index) {
    if (autoAdvanceTimer) {
      clearTimeout(autoAdvanceTimer);
      autoAdvanceTimer = null;
    }
    state.activeCard = index;
    /* Hide "Next" buttons on all non-active cards */
    containerEl.querySelectorAll('.assessment-next-btn').forEach(function (btn) {
      btn.style.display = 'none';
    });
    /* Show button on newly active card if it exists (revisiting answered card) */
    var activeCard = containerEl.querySelector('[data-card="' + index + '"]');
    if (activeCard) {
      var activeBtn = activeCard.querySelector('.assessment-next-btn');
      if (activeBtn) activeBtn.style.display = '';
    }
    updateCardClasses();
    panToCard(index, true);
  }

  /* ---------- showAllAnswered ---------- */
  function showAllAnswered() {
    state.activeCard = -1;
    var cards = containerEl.querySelectorAll('.assessment-card');

    /* Calculate shrunk width: fit all 5 in viewport */
    var shrunkWidth = Math.min(230, (viewportWidth - 40 - 4 * gap) / 5);

    if (hasGSAP()) {
      /* Collapse card bodies */
      cards.forEach(function (card) {
        card.classList.remove('carousel-active', 'carousel-future');
        card.classList.add('carousel-answered');

        gsap.to(card.querySelector('.card-body'), {
          opacity: 0,
          maxHeight: 0,
          overflow: 'hidden',
          duration: 0.5,
          ease: 'power3.in'
        });

        gsap.to(card, {
          width: shrunkWidth,
          flexBasis: shrunkWidth,
          duration: 0.7,
          ease: 'power3.inOut'
        });
      });

      /* Re-center the row after shrink */
      var totalWidth = 5 * shrunkWidth + 4 * gap;
      var centeredX = (viewportWidth - totalWidth) / 2;
      gsap.to(containerEl, { x: centeredX, duration: 0.7, ease: 'power3.inOut' });
    } else {
      /* No-GSAP fallback */
      cards.forEach(function (card) {
        card.classList.remove('carousel-active', 'carousel-future');
        card.classList.add('carousel-answered');
        card.querySelector('.card-body').style.display = 'none';
        card.style.width = shrunkWidth + 'px';
        card.style.flex = '0 0 ' + shrunkWidth + 'px';
      });
      var totalWidth = 5 * shrunkWidth + 4 * gap;
      var centeredX = (viewportWidth - totalWidth) / 2;
      containerEl.style.transform = 'translateX(' + centeredX + 'px)';
    }

    showRecommendation();
  }

  /* ---------- showRecommendation ---------- */
  function showRecommendation() {
    if (!resultEl) return;

    var abCount = state.answers.filter(function (a) { return a === 'ab'; }).length;
    var needsHelp = abCount >= 3;

    var heading, body;
    if (needsHelp) {
      heading = 'Here\u2019s what would shift.';
      body = 'The areas you\u2019ve flagged are exactly where adding a longevity dimension has the most impact. ' +
        'The tools, training, and framework to transform these areas already exist \u2014 and the free ' +
        '30-minute workshop shows you exactly how it works with a real assessment and a real consultation example.';
    } else {
      heading = 'You\u2019re already running a strong practice.';
      body = 'The longevity dimension would amplify what\u2019s already working \u2014 giving you measurable ' +
        'long-term data, a named protocol, and a premium service line that deepens client relationships ' +
        'even further. The free workshop shows how practitioners at your level are using this to go further.';
    }

    resultEl.innerHTML =
      '<div class="rounded-[32px] bg-surface p-8 md:p-10">' +
        '<h3 class="font-heading text-xl text-dark font-medium mb-3">' + heading + '</h3>' +
        '<p class="text-body leading-relaxed mb-6">' + body + '</p>' +
        '<div class="flex flex-col sm:flex-row justify-center items-center gap-4">' +
          '<a href="#workshop" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-[48px] bg-primary text-white text-base font-medium px-8 py-3.5 hover:bg-primary-dark transition-colors shadow-[0_4px_20px_rgba(61,141,160,.25)]">' +
            'Watch the Free Workshop ' +
            '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>' +
          '</a>' +
          '<a href="/pricing" class="w-full sm:w-auto inline-flex items-center justify-center text-sm text-primary font-medium hover:text-primary-dark transition-colors py-3.5">' +
            'Ready to go further? See the Course &amp; Apply &rarr;' +
          '</a>' +
        '</div>' +
        '<p class="mt-6 text-xs text-muted text-center">This is a quick snapshot highlighting common patterns \u2014 not a precise diagnosis of your practice. The workshop goes into each of these dynamics in detail with real examples.</p>' +
      '</div>';

    resultEl.classList.remove('hidden');

    if (hasGSAP()) {
      gsap.from(resultEl.firstChild, { opacity: 0, y: 20, duration: 0.6, ease: 'power3.out' });
    }

    setTimeout(function () {
      resultEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 100);
  }

  /* ---------- setupTouchHandlers ---------- */
  function setupTouchHandlers() {
    if (!viewportEl) return;

    viewportEl.addEventListener('touchstart', function (e) {
      var touch = e.touches[0];
      touchState.startX = touch.clientX;
      touchState.startY = touch.clientY;
      touchState.startTime = Date.now();
      touchState.tracking = true;
    }, { passive: true });

    viewportEl.addEventListener('touchmove', function (e) {
      if (!touchState.tracking) return;
      var touch = e.touches[0];
      var dx = Math.abs(touch.clientX - touchState.startX);
      var dy = Math.abs(touch.clientY - touchState.startY);
      /* If horizontal-dominant, prevent vertical scroll */
      if (dx > dy && dx > 10) {
        e.preventDefault();
      }
    }, { passive: false });

    viewportEl.addEventListener('touchend', function (e) {
      if (!touchState.tracking) return;
      touchState.tracking = false;

      var touch = e.changedTouches[0];
      var dx = touch.clientX - touchState.startX;
      var elapsed = Date.now() - touchState.startTime;

      /* Swipe threshold: 50px within 400ms */
      if (Math.abs(dx) < 50 || elapsed > 400) return;

      if (dx < 0) {
        /* Swipe left → next card */
        var nextIdx = state.activeCard + 1;
        if (nextIdx < 5) handleCardClick(nextIdx);
      } else {
        /* Swipe right → previous card */
        var prevIdx = state.activeCard - 1;
        if (prevIdx >= 0) handleCardClick(prevIdx);
      }
    }, { passive: true });
  }

  /* ---------- handleResize ---------- */
  function handleResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      measureDimensions();
      if (state.activeCard >= 0) {
        panToCard(state.activeCard, false);
      }
    }, 150);
  }

  /* ---------- init ---------- */
  function init(container) {
    containerEl = container;
    viewportEl = document.getElementById('assessment-viewport');
    resultEl = document.getElementById('assessment-result');

    containerEl.innerHTML = '';
    state.answers = [null, null, null, null, null];
    state.activeCard = 0;

    for (var i = 0; i < QUESTIONS.length; i++) {
      containerEl.appendChild(createCard(i));
    }

    measureDimensions();
    updateCardClasses();
    panToCard(0, false);
    setupTouchHandlers();

    window.addEventListener('resize', handleResize);
  }

  return { init: init };
})();
