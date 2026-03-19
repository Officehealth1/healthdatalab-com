/* ================================================
   HDLAssessment — Practice Assessment Module
   5-card interactive quiz with branching results
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
  var resultEl = null;

  function createCard(index) {
    var q = QUESTIONS[index];
    var card = document.createElement('div');
    var cls = 'assessment-card rounded-[32px] bg-surface p-6 md:p-8 md:col-span-2';
    if (index === 3) cls += ' md:col-start-2';
    if (index === 4) cls += ' md:col-start-4';
    cls += index === 0 ? ' active' : ' inactive';
    card.className = cls;
    card.setAttribute('data-card', index);

    card.innerHTML =
      '<p class="text-xs font-semibold text-primary tracking-widest uppercase mb-2">' + (index + 1) + ' OF 5</p>' +
      '<h3 class="font-heading text-lg text-dark font-medium mb-1">' + q.title + '</h3>' +
      '<p class="text-sm text-body italic mb-3">' + q.subtitle + '</p>' +
      '<p class="text-sm text-body mb-4">' + q.question + '</p>' +
      '<div class="flex flex-col gap-2">' +
      q.options.map(function (opt, i) {
        return '<button class="assessment-option rounded-2xl border border-stroke px-4 py-3 text-left text-sm text-body ' +
          'hover:border-primary hover:bg-primary/5 transition-colors cursor-pointer" data-option="' + i + '">' +
          opt.text + '</button>';
      }).join('') +
      '</div>' +
      '<div class="assessment-result-text hidden mt-4 pt-4 border-t border-stroke/50 text-sm text-primary/90 italic leading-relaxed"></div>';

    card.querySelectorAll('.assessment-option').forEach(function (btn) {
      btn.addEventListener('click', function () {
        handleAnswer(index, parseInt(btn.getAttribute('data-option'), 10));
      });
    });

    return card;
  }

  function handleAnswer(cardIndex, optionIndex) {
    var q = QUESTIONS[cardIndex];
    var grade = q.options[optionIndex].grade;
    state.answers[cardIndex] = grade;

    var card = containerEl.querySelector('[data-card="' + cardIndex + '"]');
    if (!card) return;

    // Highlight selected, deselect others
    card.querySelectorAll('.assessment-option').forEach(function (btn, i) {
      btn.classList.toggle('selected', i === optionIndex);
    });

    // Show result text
    var resultDiv = card.querySelector('.assessment-result-text');
    resultDiv.textContent = grade === 'c' ? q.resultC : q.resultAB;
    resultDiv.classList.remove('hidden');

    // Activate next card (only if next is unanswered)
    if (cardIndex < 4 && state.answers[cardIndex + 1] === null) {
      setTimeout(function () { activateCard(cardIndex + 1); }, 800);
    }

    // Show/update recommendation if all answered
    if (state.answers.every(function (a) { return a !== null; })) {
      showRecommendation();
    }
  }

  function activateCard(index) {
    state.activeCard = index;
    containerEl.querySelectorAll('.assessment-card').forEach(function (card, i) {
      if (i === index) {
        card.classList.remove('inactive');
        card.classList.add('active');
      } else if (state.answers[i] !== null) {
        // Answered — visible, no glow
        card.classList.remove('active', 'inactive');
      } else {
        // Future unanswered — dimmed
        card.classList.add('inactive');
        card.classList.remove('active');
      }
    });

    // Scroll into view on mobile
    if (window.innerWidth < 768) {
      var card = containerEl.querySelector('[data-card="' + index + '"]');
      if (card) {
        setTimeout(function () {
          card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
      }
    }
  }

  function showRecommendation() {
    if (!resultEl) return;

    var abCount = state.answers.filter(function (a) { return a === 'ab'; }).length;
    var needsHelp = abCount >= 3;

    var heading, body;
    if (needsHelp) {
      heading = 'Here\u2019s what would shift.';
      body = 'You\u2019re skilled at what you do \u2014 but without structured data, your impact stays invisible. ' +
        'The Longevity Trajectory Protocol gives your clients a reason to stay, a way to see progress, ' +
        'and you a practice that grows through results, not just referrals.';
    } else {
      heading = 'You\u2019re already running a strong practice.';
      body = 'Most practitioners score far lower on these dimensions. You\u2019ve built something solid \u2014 ' +
        'the Protocol would sharpen what\u2019s already working and give you data to prove it.';
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
            'Already convinced? See course &amp; pricing &rarr;' +
          '</a>' +
        '</div>' +
        '<p class="mt-6 text-xs text-muted text-center">This is a self-reflection tool, not a clinical assessment.</p>' +
      '</div>';

    resultEl.classList.remove('hidden');

    // Smooth reveal with GSAP if available
    if (typeof gsap !== 'undefined') {
      gsap.from(resultEl.firstChild, { opacity: 0, y: 20, duration: 0.6, ease: 'power3.out' });
    }

    // Scroll into view
    setTimeout(function () {
      resultEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 100);
  }

  function init(container) {
    containerEl = container;
    resultEl = document.getElementById('assessment-result');

    containerEl.innerHTML = '';
    state.answers = [null, null, null, null, null];
    state.activeCard = 0;

    for (var i = 0; i < QUESTIONS.length; i++) {
      containerEl.appendChild(createCard(i));
    }
  }

  return { init: init };
})();
