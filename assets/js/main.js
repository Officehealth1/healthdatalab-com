/* ================================================
   HealthDataLab — Shared JS (v3)
   Nav, mobile menu, scroll blur, reveal animations,
   sticky CTA, Protocol step reveal
   ================================================ */

(function () {
  // ------------------------------------------------
  // Sticky CTA bar label — update in one place
  // ------------------------------------------------
  var STICKY_CTA_LABEL = "Apply for the Course";
  var NAV_APPLY_LABEL = "Apply \u2014 Limited Places"; // Change to "Apply" post-launch

  // Apply labels from single config
  var stickyCta = document.getElementById('sticky-cta-btn');
  if (stickyCta) stickyCta.textContent = STICKY_CTA_LABEL;
  var navApply = document.getElementById('nav-apply-btn');
  if (navApply) navApply.textContent = NAV_APPLY_LABEL;
  var navApplyMobile = document.getElementById('nav-apply-btn-mobile');
  if (navApplyMobile) navApplyMobile.textContent = NAV_APPLY_LABEL;

  // ------------------------------------------------
  // Mobile menu toggle
  // ------------------------------------------------
  var toggle = document.getElementById('menu-toggle');
  var menu = document.getElementById('mobile-menu');
  var lines = [
    document.getElementById('menu-line-1'),
    document.getElementById('menu-line-2'),
    document.getElementById('menu-line-3'),
  ];
  var menuOpen = false;

  function closeMenu() {
    menuOpen = false;
    menu.classList.add('hidden');
    lines[0].setAttribute('d', 'M4 7h16');
    lines[1].style.opacity = '1';
    lines[2].setAttribute('d', 'M4 17h16');
  }

  toggle.addEventListener('click', function () {
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

  menu.querySelectorAll('.nav-link').forEach(function (link) {
    link.addEventListener('click', closeMenu);
  });

  // ------------------------------------------------
  // Nav background on scroll
  // ------------------------------------------------
  var navInner = document.getElementById('nav-inner');
  var scrollClasses = ['bg-surface/90', 'nav-blur', 'shadow-[0_2px_20px_rgba(0,0,0,.06)]'];

  window.addEventListener('scroll', function () {
    if (window.scrollY > 40) {
      navInner.classList.add.apply(navInner.classList, scrollClasses);
    } else {
      navInner.classList.remove.apply(navInner.classList, scrollClasses);
    }
  }, { passive: true });

  // ------------------------------------------------
  // Sticky CTA — IntersectionObserver show/hide
  // ------------------------------------------------
  var stickyBar = document.getElementById('sticky-cta');
  var heroSection = document.querySelector('[data-hero]');
  var workshopSection = document.querySelector('[data-workshop]');
  var heroVisible = true;
  var workshopVisible = false;

  function updateStickyVisibility() {
    if (heroVisible || workshopVisible) {
      stickyBar.classList.add('translate-y-full');
      stickyBar.classList.remove('translate-y-0');
    } else {
      stickyBar.classList.remove('translate-y-full');
      stickyBar.classList.add('translate-y-0');
    }
  }

  if (stickyBar && heroSection) {
    var heroObserver = new IntersectionObserver(function (entries) {
      heroVisible = entries[0].isIntersecting;
      updateStickyVisibility();
    }, { threshold: 0 });
    heroObserver.observe(heroSection);

    if (workshopSection) {
      var workshopObserver = new IntersectionObserver(function (entries) {
        workshopVisible = entries[0].isIntersecting;
        updateStickyVisibility();
      }, { threshold: 0.3 });
      workshopObserver.observe(workshopSection);
    }
  }

  // ------------------------------------------------
  // GSAP Premium Animations
  // ------------------------------------------------
  var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasGSAP = typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined';

  if (hasGSAP && !prefersReducedMotion) {
    gsap.registerPlugin(ScrollTrigger);

    // Remove CSS reveal/animation classes from GSAP-managed elements
    document.querySelectorAll('[data-hero-el]').forEach(function (el) {
      el.style.animation = 'none';
      el.classList.remove('hero-enter', 'hero-enter-1', 'hero-enter-2', 'hero-enter-3', 'hero-enter-4');
    });

    // ========================================
    // HERO — Entrance Timeline
    // ========================================
    var heroEls = {
      subtitle: document.querySelector('[data-hero-el="subtitle"]'),
      heading: document.querySelector('[data-hero-el="heading"]'),
      body: document.querySelectorAll('[data-hero-el="body"]'),
      cta: document.querySelector('[data-hero-el="cta"]')
    };

    // Set initial states
    var allHeroTargets = [heroEls.subtitle, heroEls.heading, heroEls.cta];
    heroEls.body.forEach(function (el) { allHeroTargets.push(el); });
    gsap.set(allHeroTargets, { opacity: 0, y: 24 });
    gsap.set(heroEls.heading, { scale: 0.98 });

    var heroTl = gsap.timeline({ delay: 0.15 });
    heroTl
      .to(heroEls.subtitle, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' })
      .to(heroEls.heading, { opacity: 1, y: 0, scale: 1, duration: 0.8, ease: 'power3.out' }, '-=0.5')
      .to(Array.from(heroEls.body), { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out', stagger: 0.15 }, '-=0.5')
      .to(heroEls.cta, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }, '-=0.4');

    // HERO — Gradient orb drift
    gsap.to('.hero-orb', {
      x: 30, y: -20,
      duration: 8,
      ease: 'sine.inOut',
      repeat: -1,
      yoyo: true
    });

    // HERO — Parallax on scroll (desktop only)
    if (window.innerWidth >= 768) {
      var heroSectionEl = document.querySelector('[data-hero]');
      if (heroSectionEl) {
        gsap.to(heroEls.heading, {
          y: -60,
          ease: 'none',
          scrollTrigger: {
            trigger: heroSectionEl,
            start: 'top top',
            end: 'bottom top',
            scrub: true
          }
        });
      }
    }

    // ========================================
    // PROTOCOL — Sequential Step Reveal
    // ========================================
    var protocolSteps = document.querySelectorAll('[data-protocol-step]');
    var protocolContainer = document.querySelector('[data-protocol-steps]');
    var protocolConnector = document.querySelector('[data-protocol-connector]');

    if (protocolSteps.length > 0) {
      // Remove CSS reveal classes for GSAP management
      protocolSteps.forEach(function (el) {
        el.classList.remove('reveal-scale', 'reveal-delay-1', 'reveal-delay-2', 'reveal-delay-3', 'reveal-delay-4');
        el.style.opacity = '0';
        el.style.transform = 'translateY(24px) scale(0.97)';
      });

      ScrollTrigger.create({
        trigger: protocolContainer,
        start: 'top 70%',
        once: true,
        onEnter: function () {
          var connectorLine = null;

          // Position connector line (desktop, 4 steps)
          if (protocolConnector && window.innerWidth >= 768 && protocolSteps.length >= 4) {
            var containerRect = protocolContainer.getBoundingClientRect();
            var first = protocolSteps[0];
            var last = protocolSteps[protocolSteps.length - 1];
            var firstRect = first.getBoundingClientRect();
            var lastRect = last.getBoundingClientRect();

            var cy = (firstRect.top - containerRect.top) + 30;
            var x1 = (firstRect.left - containerRect.left) + firstRect.width / 2;
            var x2 = (lastRect.left - containerRect.left) + lastRect.width / 2;

            protocolConnector.setAttribute('width', containerRect.width);
            protocolConnector.setAttribute('height', '80');
            protocolConnector.style.top = cy + 'px';
            protocolConnector.style.left = '0';

            connectorLine = protocolConnector.querySelector('line');
            connectorLine.setAttribute('x1', x1);
            connectorLine.setAttribute('y1', '30');
            connectorLine.setAttribute('x2', x2);
            connectorLine.setAttribute('y2', '30');

            var lineLength = Math.abs(x2 - x1);
            connectorLine.setAttribute('stroke-dasharray', lineLength);
            connectorLine.setAttribute('stroke-dashoffset', lineLength);
          }

          var stepsTl = gsap.timeline();

          // Reveal each step sequentially with connector segments
          protocolSteps.forEach(function (step, i) {
            stepsTl.to(step, {
              opacity: 1, y: 0, scale: 1,
              duration: 0.6, ease: 'power3.out'
            }, i === 0 ? 0 : '-=0.2');

            // Draw connector segment after each step (except the last)
            if (connectorLine && i < protocolSteps.length - 1) {
              var totalLength = parseFloat(connectorLine.getAttribute('stroke-dasharray'));
              var segmentTarget = totalLength - (totalLength * (i + 1) / (protocolSteps.length - 1));
              stepsTl.to(connectorLine, {
                attr: { 'stroke-dashoffset': segmentTarget },
                duration: 0.3, ease: 'power2.inOut'
              }, '-=0.2');
            }
          });
        }
      });
    }

  } // end hasGSAP

  // ------------------------------------------------
  // Scroll-triggered reveal animations (general)
  // ------------------------------------------------
  var revealObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        revealObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

  document.querySelectorAll('.reveal, .reveal-scale, .reveal-left, .reveal-right').forEach(function (el) {
    revealObserver.observe(el);
  });

})();
