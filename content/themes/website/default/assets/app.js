document.documentElement.dataset.theme = 'default';

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
