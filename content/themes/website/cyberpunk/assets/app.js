(() => {
  const root = document.documentElement;
  const themeToggle = document.querySelector('[data-theme-toggle]');
  const themeLabel = document.querySelector('[data-theme-label]');
  const navToggle = document.querySelector('.nav-toggle');
  const siteNav = document.getElementById('site-nav');
  const year = document.querySelector('[data-current-year]');
  const storageKey = 'midex-cyberpunk-theme';

  const preferredTheme = () => {
    const saved = localStorage.getItem(storageKey);
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  };

  const applyTheme = (theme) => {
    root.dataset.theme = theme;
    localStorage.setItem(storageKey, theme);
    if (themeLabel) {
      themeLabel.textContent = theme === 'dark' ? 'Night' : 'Day';
    }
  };

  applyTheme(preferredTheme());

  themeToggle?.addEventListener('click', () => {
    applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
  });

  const closeMenu = () => {
    siteNav?.classList.remove('is-open');
    navToggle?.classList.remove('is-open');
    navToggle?.setAttribute('aria-expanded', 'false');
  };

  navToggle?.addEventListener('click', () => {
    const isOpen = siteNav?.classList.toggle('is-open') ?? false;
    navToggle.classList.toggle('is-open', isOpen);
    navToggle.setAttribute('aria-expanded', String(isOpen));
  });

  siteNav?.addEventListener('click', (event) => {
    const target = event.target;
    if (target instanceof Element && target.closest('a')) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenu();
  });

  if (year) {
    year.textContent = String(new Date().getFullYear());
  }

  const reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.12 });

    reveals.forEach((element) => observer.observe(element));
  } else {
    reveals.forEach((element) => element.classList.add('is-visible'));
  }

  document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-like-toggle]') : null;

    if (!(button instanceof HTMLButtonElement)) {
      return;
    }

    event.preventDefault();

    const endpoint = button.dataset.endpoint;
    const csrfInput = button.dataset.csrfInput;
    const csrfToken = button.dataset.csrfToken;

    if (!endpoint || !csrfInput || !csrfToken) {
      return;
    }

    const body = new FormData();
    body.append(csrfInput, csrfToken);
    button.disabled = true;

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        body,
        headers: {
          'X-Requested-With': 'fetch',
        },
      });
      const payload = await response.json();

      if (!response.ok || typeof payload.count !== 'number') {
        throw new Error(payload.error || 'Like request failed.');
      }

      const label = button.querySelector('[data-like-label]');
      const count = button.querySelector('[data-like-count]');

      if (label) {
        label.textContent = payload.liked ? 'Unlike' : 'Like';
      }

      if (count) {
        count.textContent = String(payload.count);
      }

      button.classList.toggle('is-liked', Boolean(payload.liked));
    } catch (error) {
      window.alert(error instanceof Error ? error.message : 'Like request failed.');
    } finally {
      button.disabled = false;
    }
  });
})();
