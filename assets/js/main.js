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

  // Scroll-triggered reveal animations
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
