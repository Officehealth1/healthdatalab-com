/* ================================================
   HealthDataLab — Shared JS
   Nav, mobile menu, scroll blur, reveal animations
   ================================================ */

(function () {
  // Mobile menu toggle
  const toggle = document.getElementById('menu-toggle');
  const menu = document.getElementById('mobile-menu');
  const lines = [
    document.getElementById('menu-line-1'),
    document.getElementById('menu-line-2'),
    document.getElementById('menu-line-3'),
  ];
  let menuOpen = false;

  function closeMenu() {
    menuOpen = false;
    menu.classList.add('hidden');
    lines[0].setAttribute('d', 'M4 7h16');
    lines[1].style.opacity = '1';
    lines[2].setAttribute('d', 'M4 17h16');
  }

  toggle.addEventListener('click', () => {
    if (menuOpen) {
      closeMenu();
    } else {
      menuOpen = true;
      menu.classList.remove('hidden');
      lines[0].setAttribute('d', 'M6 6l12 12');
      lines[1].style.opacity = '0';
      lines[2].setAttribute('d', 'M6 18L18 6');
    }
  });

  menu.querySelectorAll('.nav-link').forEach(link => {
    link.addEventListener('click', closeMenu);
  });

  // Nav background on scroll
  const navInner = document.getElementById('nav-inner');
  const scrollClasses = ['bg-surface/90', 'nav-blur', 'shadow-[0_2px_20px_rgba(0,0,0,.06)]'];

  window.addEventListener('scroll', () => {
    if (window.scrollY > 40) {
      navInner.classList.add(...scrollClasses);
    } else {
      navInner.classList.remove(...scrollClasses);
    }
  }, { passive: true });

  // ------------------------------------------------
  // GSAP Premium Animations (Hero, 80/20 stat, How It Works)
  // ------------------------------------------------
  var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasGSAP = typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined';

  if (hasGSAP && !prefersReducedMotion) {
    gsap.registerPlugin(ScrollTrigger);

    // --- Remove CSS reveal/animation classes from GSAP-managed elements ---
    // This prevents the IntersectionObserver from also animating them
    document.querySelectorAll('[data-hero-el]').forEach(function (el) {
      el.style.animation = 'none';
      el.classList.remove('hero-enter', 'hero-enter-1', 'hero-enter-2', 'hero-enter-3', 'hero-enter-4');
    });

    var statSection = document.querySelector('[data-stat-section]');
    if (statSection) {
      statSection.classList.remove('reveal');
      statSection.style.opacity = '0';
      statSection.style.transform = 'translateY(32px)';
    }

    var hiwHeader = document.querySelector('[data-hiw-header]');
    if (hiwHeader) {
      hiwHeader.classList.remove('reveal');
      hiwHeader.style.opacity = '0';
      hiwHeader.style.transform = 'translateY(32px)';
    }

    document.querySelectorAll('[data-hiw-step]').forEach(function (el) {
      el.classList.remove('reveal-scale', 'reveal-delay-1', 'reveal-delay-2', 'reveal-delay-3');
      el.style.opacity = '0';
      el.style.transform = 'translateY(24px) scale(0.97)';
    });

    document.querySelectorAll('[data-hiw-report]').forEach(function (el) {
      el.classList.remove('reveal-scale', 'reveal-delay-1', 'reveal-delay-2', 'reveal-delay-3');
      el.style.opacity = '0';
      el.style.transform = 'translateY(24px) scale(0.97)';
    });

    // ========================================
    // HERO — Entrance Timeline
    // ========================================
    var heroEls = {
      subtitle: document.querySelector('[data-hero-el="subtitle"]'),
      heading: document.querySelector('[data-hero-el="heading"]'),
      body: document.querySelector('[data-hero-el="body"]'),
      cta: document.querySelector('[data-hero-el="cta"]')
    };

    // Set initial states for GSAP (override CSS which was killed above)
    gsap.set([heroEls.subtitle, heroEls.heading, heroEls.body, heroEls.cta], {
      opacity: 0, y: 24
    });
    gsap.set(heroEls.heading, { scale: 0.98 });

    var heroTl = gsap.timeline({ delay: 0.15 });
    heroTl
      .to(heroEls.subtitle, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' })
      .to(heroEls.heading, { opacity: 1, y: 0, scale: 1, duration: 0.8, ease: 'power3.out' }, '-=0.5')
      .to(heroEls.body, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }, '-=0.5')
      .to(heroEls.cta, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }, '-=0.4');

    // HERO — Gradient orb drift (autonomous, not scroll-driven)
    gsap.to('.hero-orb', {
      x: 30, y: -20,
      duration: 8,
      ease: 'sine.inOut',
      repeat: -1,
      yoyo: true
    });

    // HERO — Parallax on scroll (desktop only, text elements only)
    if (window.innerWidth >= 768) {
      var heroSection = document.querySelector('[data-hero]');
      if (heroSection) {
        gsap.to(heroEls.heading, {
          y: -60,
          ease: 'none',
          scrollTrigger: {
            trigger: heroSection,
            start: 'top top',
            end: 'bottom top',
            scrub: true
          }
        });
        gsap.to(heroEls.body, {
          y: -40,
          ease: 'none',
          scrollTrigger: {
            trigger: heroSection,
            start: 'top top',
            end: 'bottom top',
            scrub: true
          }
        });
      }
    }

    // ========================================
    // 80/20 STAT — Counter Animation
    // ========================================
    if (statSection) {
      var counterSpans = statSection.querySelectorAll('[data-counter]');
      var statBody = statSection.querySelector('[data-stat-body]');

      // Set body text initial state
      if (statBody) {
        gsap.set(statBody, { opacity: 0, y: 16 });
      }

      ScrollTrigger.create({
        trigger: statSection,
        start: 'top 70%',
        once: true,
        onEnter: function () {
          // Fade in the section container
          gsap.to(statSection, {
            opacity: 1, y: 0,
            duration: 0.6, ease: 'power3.out'
          });

          // Count up each number
          counterSpans.forEach(function (span) {
            var target = parseInt(span.dataset.counter);
            var obj = { val: 0 };
            gsap.to(obj, {
              val: target,
              duration: 0.8,
              ease: 'power2.out',
              onUpdate: function () {
                span.textContent = Math.round(obj.val);
              }
            });
            // Subtle scale pulse
            gsap.fromTo(span,
              { scale: 1 },
              { scale: 1.02, duration: 0.4, ease: 'power2.out', yoyo: true, repeat: 1 }
            );
          });

          // Body text fades in after count
          if (statBody) {
            gsap.to(statBody, {
              opacity: 1, y: 0,
              duration: 0.7, ease: 'power3.out',
              delay: 0.9
            });
          }
        }
      });
    }

    // ========================================
    // HOW IT WORKS — Sequential Reveal
    // ========================================
    if (hiwHeader) {
      ScrollTrigger.create({
        trigger: hiwHeader,
        start: 'top 80%',
        once: true,
        onEnter: function () {
          gsap.to(hiwHeader, {
            opacity: 1, y: 0,
            duration: 0.7, ease: 'power3.out'
          });
        }
      });
    }

    // Step cards + connector line
    var stepsContainer = document.querySelector('[data-hiw-steps]');
    var stepCards = document.querySelectorAll('[data-hiw-step]');
    var connector = document.querySelector('[data-hiw-connector]');

    if (stepCards.length > 0) {
      ScrollTrigger.create({
        trigger: stepsContainer,
        start: 'top 70%',
        once: true,
        onEnter: function () {
          var stepsTl = gsap.timeline();

          // Position and size the connector SVG dynamically
          var connectorLine = null;
          if (connector && window.innerWidth >= 768 && stepCards.length >= 3) {
            var first = stepCards[0];
            var last = stepCards[stepCards.length - 1];
            var containerRect = stepsContainer.getBoundingClientRect();
            var firstRect = first.getBoundingClientRect();
            var lastRect = last.getBoundingClientRect();

            // Center Y at the step number badge (~30px from card top)
            var cy = (firstRect.top - containerRect.top) + 30;
            var x1 = (firstRect.left - containerRect.left) + firstRect.width / 2;
            var x2 = (lastRect.left - containerRect.left) + lastRect.width / 2;

            connector.setAttribute('width', containerRect.width);
            connector.setAttribute('height', '80');
            connector.style.top = cy + 'px';
            connector.style.left = '0';

            connectorLine = connector.querySelector('line');
            connectorLine.setAttribute('x1', x1);
            connectorLine.setAttribute('y1', '30');
            connectorLine.setAttribute('x2', x2);
            connectorLine.setAttribute('y2', '30');

            var lineLength = Math.abs(x2 - x1);
            connectorLine.setAttribute('stroke-dasharray', lineLength);
            connectorLine.setAttribute('stroke-dashoffset', lineLength);
          }

          // Card 1
          stepsTl.to(stepCards[0], {
            opacity: 1, y: 0, scale: 1,
            duration: 0.6, ease: 'power3.out'
          });

          // Connector first half
          if (connectorLine) {
            var halfLength = parseFloat(connectorLine.getAttribute('stroke-dasharray')) / 2;
            stepsTl.to(connectorLine, {
              attr: { 'stroke-dashoffset': halfLength },
              duration: 0.4, ease: 'power2.inOut'
            }, '-=0.2');
          }

          // Card 2
          stepsTl.to(stepCards[1], {
            opacity: 1, y: 0, scale: 1,
            duration: 0.6, ease: 'power3.out'
          }, connectorLine ? '-=0.2' : '-=0.3');

          // Connector second half
          if (connectorLine) {
            stepsTl.to(connectorLine, {
              attr: { 'stroke-dashoffset': 0 },
              duration: 0.4, ease: 'power2.inOut'
            }, '-=0.2');
          }

          // Card 3
          if (stepCards[2]) {
            stepsTl.to(stepCards[2], {
              opacity: 1, y: 0, scale: 1,
              duration: 0.6, ease: 'power3.out'
            }, connectorLine ? '-=0.2' : '-=0.3');
          }
        }
      });
    }

    // Report gallery — staggered reveal
    var reportCards = document.querySelectorAll('[data-hiw-report]');
    if (reportCards.length > 0) {
      ScrollTrigger.create({
        trigger: reportCards[0].closest('.grid'),
        start: 'top 70%',
        once: true,
        onEnter: function () {
          gsap.to(reportCards, {
            opacity: 1, y: 0, scale: 1,
            duration: 0.6, ease: 'power3.out',
            stagger: 0.2
          });
        }
      });
    }

  } else {
    // Fallback: show counter final values immediately when GSAP unavailable
    document.querySelectorAll('[data-counter]').forEach(function (el) {
      el.textContent = el.dataset.counter;
    });
  }

  // Scroll-triggered reveal animations (sections 4-10, untouched)
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        revealObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

  document.querySelectorAll('.reveal, .reveal-scale, .reveal-left, .reveal-right').forEach(el => {
    revealObserver.observe(el);
  });

  // Counter animation (only runs if .counter elements exist)
  const counters = document.querySelectorAll('.counter[data-target]');
  if (counters.length > 0) {
    const counterObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const el = entry.target;
          const target = parseInt(el.dataset.target);
          const duration = 1200;
          const start = performance.now();
          const animate = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.round(eased * target);
            if (progress < 1) requestAnimationFrame(animate);
          };
          requestAnimationFrame(animate);
          counterObserver.unobserve(el);
        }
      });
    }, { threshold: 0.2 });

    counters.forEach(el => counterObserver.observe(el));

    // Fallback: ensure counters show values even if observer misses
    setTimeout(() => {
      counters.forEach(el => {
        if (el.textContent === '0') el.textContent = el.dataset.target;
      });
    }, 3000);
  }
})();
