/* ================================================
   HealthDataLab — Shared JS (v3)
   Nav, mobile menu, scroll blur, reveal animations,
   sticky CTA, Protocol step reveal
   ================================================ */

(function () {
  // ------------------------------------------------
  // Sticky CTA bar label — update in one place
  // ------------------------------------------------
  var STICKY_CTA_LABEL = "Watch the Free Workshop";
  var NAV_APPLY_LABEL = "Watch Workshop";

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
      cta: document.querySelector('[data-hero-el="cta"]'),
      visual: document.querySelector('[data-hero-el="visual"]')
    };

    // Set initial states (guard: only run hero animations on pages with data-hero-el elements)
    var allHeroTargets = [heroEls.subtitle, heroEls.heading, heroEls.cta].filter(Boolean);
    if (heroEls.visual) allHeroTargets.push(heroEls.visual);
    heroEls.body.forEach(function (el) { allHeroTargets.push(el); });

    if (allHeroTargets.length > 0) {
      gsap.set(allHeroTargets, { opacity: 0, y: 24 });
      if (heroEls.heading) gsap.set(heroEls.heading, { scale: 0.98 });

      var heroTl = gsap.timeline({ delay: 0.15 });
      if (heroEls.subtitle) heroTl.to(heroEls.subtitle, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' });
      if (heroEls.heading) heroTl.to(heroEls.heading, { opacity: 1, y: 0, scale: 1, duration: 0.8, ease: 'power3.out' }, '-=0.5');
      if (heroEls.body.length > 0) heroTl.to(Array.from(heroEls.body), { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out', stagger: 0.15 }, '-=0.5');
      if (heroEls.cta) heroTl.to(heroEls.cta, { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }, '-=0.4');

      // HERO — Trajectory chart progressive reveal
      var chartContainer = document.getElementById('hero-trajectory-chart');
      if (chartContainer && typeof HDLTrajectoryChart !== 'undefined') {
        HDLTrajectoryChart.render('#hero-trajectory-chart', {
          chronoAge: 50, agingRate: 0.84, showBands: true, showProjections: true
        });

        var heroChartLoops = [];
        var chartSvg = chartContainer.querySelector('svg');
        if (chartSvg) {
          // Collect all animatable elements by data-hdl attribute
          var q = function (v) { return chartSvg.querySelectorAll('[data-hdl="' + v + '"]'); };

          var gridEls = q('grid');
          var gridLabels = q('grid-label');
          var axisLabels = q('axis-label');
          var zones = q('zone');
          var zoneLabels = q('zone-label');
          var bandPast = q('band-past');
          var bandFuture = q('band-future');
          var bandLabels = q('band-label');
          var nowLine = q('now-line');
          var nowLabel = q('now-label');
          var regionLabels = q('region-label');
          var userLine = q('user-line');
          var anchorDots = q('anchor-dot');
          var anchorPulse = q('anchor-pulse');
          var pessimisticFill = q('pessimistic-fill');
          var pessimisticLine = q('pessimistic-line');
          var optimisticFill = q('optimistic-fill');
          var optimisticLine = q('optimistic-line');
          var optimisticPeak = q('optimistic-peak');
          var badge = q('badge');
          var legend = q('legend');
          var greenCallout = q('green-callout');
          var redCallout = q('red-callout');
          var greenTraveler = q('green-traveler');
          var redTraveler = q('red-traveler');

          // Gather everything and hide initially
          var allChartEls = chartSvg.querySelectorAll('[data-hdl]');
          gsap.set(allChartEls, { opacity: 0 });

          // Prepare stroke-draw paths
          var strokeDrawSetup = function (els) {
            for (var i = 0; i < els.length; i++) {
              var el = els[i];
              if (el.tagName === 'path' && el.getTotalLength) {
                var len = el.getTotalLength();
                gsap.set(el, { strokeDasharray: len, strokeDashoffset: len });
              } else if (el.tagName === 'line') {
                var x1 = parseFloat(el.getAttribute('x1')) || 0;
                var y1 = parseFloat(el.getAttribute('y1')) || 0;
                var x2 = parseFloat(el.getAttribute('x2')) || 0;
                var y2 = parseFloat(el.getAttribute('y2')) || 0;
                var len2 = Math.sqrt((x2 - x1) * (x2 - x1) + (y2 - y1) * (y2 - y1));
                gsap.set(el, { strokeDasharray: len2, strokeDashoffset: len2 });
              }
            }
          };

          strokeDrawSetup(userLine);
          strokeDrawSetup(pessimisticLine);
          strokeDrawSetup(optimisticLine);
          strokeDrawSetup(nowLine);

          // Anchor dots: scale from 0
          gsap.set(anchorDots, { scale: 0, transformOrigin: 'center center' });

          // Badge: slide in from left
          gsap.set(badge, { x: -30 });

          // Reveal the visual container first
          if (heroEls.visual) {
            heroTl.to(heroEls.visual, { opacity: 1, y: 0, duration: 0.5, ease: 'power3.out' }, '-=0.6');
          }

          // Phase 1 (0.4s): Grid lines + labels fade in
          heroTl.to(gridEls, { opacity: 1, duration: 0.4, ease: 'power2.out' }, '-=0.3');
          heroTl.to(gridLabels, { opacity: 1, duration: 0.4, ease: 'power2.out' }, '<');
          heroTl.to(axisLabels, { opacity: 1, duration: 0.4, ease: 'power2.out' }, '<');

          // Phase 2 (0.5s): Zones + band curves appear
          heroTl.to(zones, { opacity: 1, duration: 0.5, ease: 'power2.out' }, '-=0.1');
          heroTl.to(zoneLabels, { opacity: 0.6, duration: 0.5, ease: 'power2.out' }, '<');
          heroTl.to(bandPast, { opacity: 1, duration: 0.5, ease: 'power2.out' }, '<');
          heroTl.to(bandFuture, { opacity: 1, duration: 0.5, ease: 'power2.out' }, '<+0.15');
          heroTl.to(bandLabels, { opacity: 0.4, duration: 0.5, ease: 'power2.out' }, '<');

          // Phase 3 (0.4s): "Now" divider draws down, region labels appear
          heroTl.to(nowLine, { opacity: 0.35, strokeDashoffset: 0, duration: 0.4, ease: 'power2.out' });
          heroTl.to(nowLabel, { opacity: 0.65, duration: 0.3, ease: 'power2.out' }, '<+0.1');
          heroTl.to(regionLabels, { opacity: 0.5, duration: 0.3, ease: 'power2.out' }, '<');

          // Phase 4 (1.2s): USER HISTORY LINE — the hero moment
          heroTl.to(userLine, { opacity: 1, strokeDashoffset: 0, duration: 1.2, ease: 'power2.out' });

          // Phase 5 (0.3s): Anchor dot scales up with bounce
          heroTl.to(anchorDots, { opacity: 1, scale: 1, duration: 0.3, ease: 'back.out(1.7)', stagger: 0.05 }, '-=0.1');

          // Phase 6 (0.8s): Projections draw outward, fills fade in
          heroTl.to(pessimisticFill, { opacity: 1, duration: 0.5, ease: 'power2.out' }, '-=0.1');
          heroTl.to(pessimisticLine, { opacity: 0.75, strokeDashoffset: 0, duration: 0.8, ease: 'power2.out' }, '<');
          heroTl.to(optimisticFill, { opacity: 1, duration: 0.5, ease: 'power2.out' }, '<+0.1');
          heroTl.to(optimisticLine, { opacity: 0.8, strokeDashoffset: 0, duration: 0.8, ease: 'power2.out' }, '<');
          heroTl.to(optimisticPeak, { opacity: 0.65, duration: 0.4, ease: 'power2.out' }, '-=0.3');

          // Phase 7 (0.4s): Rate badge slides in, legend fades in
          heroTl.to(badge, { opacity: 1, x: 0, duration: 0.4, ease: 'power3.out' });
          heroTl.to(legend, { opacity: 1, duration: 0.4, ease: 'power2.out' }, '<');

          // Phase 8 (0.5s): Projection callouts fade in
          heroTl.to(greenCallout, { opacity: 1, duration: 0.5, ease: 'power2.out' });
          heroTl.to(redCallout, { opacity: 1, duration: 0.5, ease: 'power2.out' }, '<+0.1');
          if (greenTraveler.length > 0) heroTl.to(greenTraveler, { opacity: 1, duration: 0.4, ease: 'power2.out' }, '<');
          if (redTraveler.length > 0) heroTl.to(redTraveler, { opacity: 1, duration: 0.4, ease: 'power2.out' }, '<');

          // Continuous anchor pulse — "you are here" radar ping
          if (anchorPulse.length > 0) {
            gsap.set(anchorPulse, { transformOrigin: 'center center' });
            heroChartLoops.push(gsap.fromTo(anchorPulse, {
              scale: 1, opacity: 0.6
            }, {
              scale: 3.5, opacity: 0,
              duration: 2, repeat: -1,
              ease: 'power2.out',
              stagger: { each: 1 },
              delay: heroTl.duration()
            }));
          }

          // Green line breathing glow — alive vs static contrast
          if (optimisticLine.length > 0) {
            heroChartLoops.push(gsap.to(optimisticLine, {
              opacity: 0.5,
              duration: 1.5,
              ease: 'sine.inOut',
              repeat: -1,
              yoyo: true,
              delay: heroTl.duration()
            }));
          }
          if (optimisticFill.length > 0) {
            heroChartLoops.push(gsap.to(optimisticFill, {
              opacity: 0.02,
              duration: 1.5,
              ease: 'sine.inOut',
              repeat: -1,
              yoyo: true,
              delay: heroTl.duration()
            }));
          }

          // Green traveler — 5s journey along optimistic line
          if (greenTraveler.length > 0 && optimisticLine.length > 0) {
            var greenPath = optimisticLine[0];
            var greenDot = greenTraveler[0];
            var greenLen = greenPath.getTotalLength();
            heroChartLoops.push(gsap.to({ progress: 0 }, {
              progress: 1, duration: 5, ease: 'none', repeat: -1,
              delay: heroTl.duration(),
              onUpdate: function () {
                var pt = greenPath.getPointAtLength(this.targets()[0].progress * greenLen);
                greenDot.setAttribute('cx', pt.x);
                greenDot.setAttribute('cy', pt.y);
              }
            }));
            heroChartLoops.push(gsap.fromTo(greenDot, { attr: { r: 3 } }, {
              attr: { r: 5 }, duration: 1.2, ease: 'sine.inOut',
              repeat: -1, yoyo: true, delay: heroTl.duration()
            }));
          }

          // Red traveler — 3.5s journey along pessimistic line
          if (redTraveler.length > 0 && pessimisticLine.length > 0) {
            var redPath = pessimisticLine[0];
            var redDot = redTraveler[0];
            var redLen = redPath.getTotalLength();
            heroChartLoops.push(gsap.to({ progress: 0 }, {
              progress: 1, duration: 3.5, ease: 'none', repeat: -1,
              delay: heroTl.duration(),
              onUpdate: function () {
                var pt = redPath.getPointAtLength(this.targets()[0].progress * redLen);
                redDot.setAttribute('cx', pt.x);
                redDot.setAttribute('cy', pt.y);
              }
            }));
            heroChartLoops.push(gsap.fromTo(redDot, { attr: { r: 2.5 } }, {
              attr: { r: 4 }, duration: 1, ease: 'sine.inOut',
              repeat: -1, yoyo: true, delay: heroTl.duration()
            }));
          }
        }

        // ── HERO CHART CYCLING ──────────────────────────────────
        var HERO_SAMPLES = [
          { chronoAge: 50, agingRate: 0.84 },
          { chronoAge: 38, agingRate: 1.18 },
          { chronoAge: 62, agingRate: 0.76 },
          { chronoAge: 45, agingRate: 1.02 },
          { chronoAge: 55, agingRate: 0.91 }
        ];

        var startChartLoops = function (svg) {
          var loops = [];
          var q = function (v) { return svg.querySelectorAll('[data-hdl="' + v + '"]'); };

          var anchorP = q('anchor-pulse');
          var optLine = q('optimistic-line');
          var optFill = q('optimistic-fill');
          var greenTrav = q('green-traveler');
          var redTrav = q('red-traveler');
          var pessLine = q('pessimistic-line');

          if (anchorP.length > 0) {
            gsap.set(anchorP, { transformOrigin: 'center center' });
            loops.push(gsap.fromTo(anchorP,
              { scale: 1, opacity: 0.6 },
              { scale: 3.5, opacity: 0, duration: 2, repeat: -1, ease: 'power2.out', stagger: { each: 1 } }
            ));
          }

          if (optLine.length > 0) {
            loops.push(gsap.to(optLine, {
              opacity: 0.5, duration: 1.5, ease: 'sine.inOut', repeat: -1, yoyo: true
            }));
          }
          if (optFill.length > 0) {
            loops.push(gsap.to(optFill, {
              opacity: 0.02, duration: 1.5, ease: 'sine.inOut', repeat: -1, yoyo: true
            }));
          }

          if (greenTrav.length > 0 && optLine.length > 0) {
            var gPath = optLine[0];
            var gDot = greenTrav[0];
            var gLen = gPath.getTotalLength();
            loops.push(gsap.to({ progress: 0 }, {
              progress: 1, duration: 5, ease: 'none', repeat: -1,
              onUpdate: function () {
                var pt = gPath.getPointAtLength(this.targets()[0].progress * gLen);
                gDot.setAttribute('cx', pt.x);
                gDot.setAttribute('cy', pt.y);
              }
            }));
            loops.push(gsap.fromTo(gDot, { attr: { r: 3 } }, {
              attr: { r: 5 }, duration: 1.2, ease: 'sine.inOut', repeat: -1, yoyo: true
            }));
          }

          if (redTrav.length > 0 && pessLine.length > 0) {
            var rPath = pessLine[0];
            var rDot = redTrav[0];
            var rLen = rPath.getTotalLength();
            loops.push(gsap.to({ progress: 0 }, {
              progress: 1, duration: 3.5, ease: 'none', repeat: -1,
              onUpdate: function () {
                var pt = rPath.getPointAtLength(this.targets()[0].progress * rLen);
                rDot.setAttribute('cx', pt.x);
                rDot.setAttribute('cy', pt.y);
              }
            }));
            loops.push(gsap.fromTo(rDot, { attr: { r: 2.5 } }, {
              attr: { r: 4 }, duration: 1, ease: 'sine.inOut', repeat: -1, yoyo: true
            }));
          }

          return loops;
        };

        var startHeroChartCycling = function () {
          if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

          var container = document.getElementById('hero-trajectory-chart');
          if (!container) return;

          var currentIdx = 0;
          var activeLoops = heroChartLoops.slice();

          function cycleNext() {
            var fromSample = HERO_SAMPLES[currentIdx];
            currentIdx = (currentIdx + 1) % HERO_SAMPLES.length;
            var toSample = HERO_SAMPLES[currentIdx];

            // Kill continuous loops (they target elements that get destroyed on re-render)
            activeLoops.forEach(function (t) { t.kill(); });
            activeLoops = [];

            // Tween data values — chart re-renders every frame with intermediate values
            gsap.to(
              { age: fromSample.chronoAge, rate: fromSample.agingRate },
              {
                age: toSample.chronoAge,
                rate: toSample.agingRate,
                duration: 1.5,
                ease: 'power2.inOut',
                onUpdate: function () {
                  var d = this.targets()[0];
                  HDLTrajectoryChart.render(container, {
                    chronoAge: d.age, agingRate: d.rate,
                    showBands: true, showProjections: true
                  });
                  var svg = container.querySelector('svg');
                  if (svg) {
                    var allEls = svg.querySelectorAll('[data-hdl]');
                    gsap.set(allEls, { opacity: 1 });
                    allEls.forEach(function (el) {
                      if ((el.tagName === 'path' || el.tagName === 'line') && el.getTotalLength) {
                        gsap.set(el, { strokeDashoffset: 0 });
                      }
                    });
                  }
                },
                onComplete: function () {
                  var svg = container.querySelector('svg');
                  if (svg) activeLoops = startChartLoops(svg);
                  setTimeout(cycleNext, 5000); // 5s viewing, then next morph
                }
              }
            );
          }

          setTimeout(cycleNext, 5000); // first morph after 5s of viewing
        };

        setTimeout(startHeroChartCycling, (heroTl.duration() + 5) * 1000);
      }

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
        if (heroSectionEl && heroEls.heading) {
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

  // ========================================
  // ASSESSMENT — Initialize
  // ========================================
  var assessmentSection = document.querySelector('[data-assessment]');
  var assessmentCards = document.getElementById('assessment-cards');
  if (assessmentSection && assessmentCards && typeof HDLAssessment !== 'undefined') {
    if (hasGSAP && !prefersReducedMotion && window.innerWidth >= 768) {
      ScrollTrigger.create({
        trigger: assessmentSection,
        start: 'top 70%',
        once: true,
        onEnter: function () {
          HDLAssessment.init(assessmentCards);
        }
      });
    } else {
      HDLAssessment.init(assessmentCards);
    }
  }

  // ========================================
  // RADAR CHART — Initialize + Cycling
  // ========================================
  var radarContainer = document.getElementById('hero-radar-chart');
  if (radarContainer && typeof HDLRadarChart !== 'undefined') {
    var radarProfiles = HDLRadarChart.PROFILES;
    HDLRadarChart.render('#hero-radar-chart', {
      healthy: radarProfiles[0].healthy,
      unhealthy: radarProfiles[0].unhealthy
    });

    if (hasGSAP && !prefersReducedMotion) {
      var radarIdx = 0;
      function cycleRadar() {
        radarIdx = (radarIdx + 1) % radarProfiles.length;
        HDLRadarChart.update(
          radarProfiles[radarIdx].healthy,
          radarProfiles[radarIdx].unhealthy,
          1.5,
          function () { setTimeout(cycleRadar, 5000); }
        );
      }
      setTimeout(cycleRadar, 6000);
    }
  }

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
