const adminThemeStorageKey = 'midex-admin-theme';
const autosaveFields = [
  'title',
  'slug',
  'path',
  'excerpt',
  'content_mode',
  'content_raw',
  'template',
  'seo_title',
  'seo_description',
  'seo_keywords',
  'comments_enabled',
  'status',
];

function preferredAdminTheme() {
  const stored = window.localStorage.getItem(adminThemeStorageKey);

  if (stored === 'light' || stored === 'dark') {
    return stored;
  }

  return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

function setAdminTheme(theme) {
  document.documentElement.dataset.adminTheme = theme;
  window.localStorage.setItem(adminThemeStorageKey, theme);

  for (const toggle of document.querySelectorAll('[data-admin-theme-toggle]')) {
    if (toggle instanceof HTMLInputElement && toggle.type === 'checkbox') {
      toggle.checked = theme === 'dark';
    }
  }

  for (const label of document.querySelectorAll('[data-theme-label]')) {
    label.textContent = theme === 'dark' ? 'Dark' : 'Light';
  }

  for (const option of document.querySelectorAll('[data-theme-option]')) {
    if (!(option instanceof HTMLElement)) {
      continue;
    }

    const isActive = option.dataset.themeOption === theme;
    option.classList.toggle('is-active', isActive);
    option.classList.toggle('is-inactive', !isActive);
  }
}

function toggleAdminSidebar(forceOpen) {
  const shouldOpen = typeof forceOpen === 'boolean'
    ? forceOpen
    : !document.body.classList.contains('admin-sidebar-open');

  document.body.classList.toggle('admin-sidebar-open', shouldOpen);

  for (const toggle of document.querySelectorAll('[data-admin-sidebar-toggle]')) {
    toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
  }
}

function setAutosaveStatus(form, message) {
  const status = form.querySelector('[data-autosave-status]');

  if (status instanceof HTMLElement) {
    status.textContent = message;
  }
}

function readAutosavePayload(form) {
  const values = {};

  for (const name of autosaveFields) {
    const field = form.elements.namedItem(name);

    if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
      values[name] = field.value;
    }
  }

  return values;
}

function writeAutosavePayload(form, values) {
  for (const name of autosaveFields) {
    if (!(name in values) || typeof values[name] !== 'string') {
      continue;
    }

    const field = form.elements.namedItem(name);

    if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
      field.value = values[name];
    }
  }
}

function bindAutosave(form) {
  const key = form.dataset.autosaveKey;

  if (!key) {
    return;
  }

  if (document.querySelector('.flash-success')) {
    window.localStorage.removeItem(key);
    setAutosaveStatus(form, 'Local draft cleared after save.');

    return;
  }

  const raw = window.localStorage.getItem(key);

  if (raw) {
    try {
      const payload = JSON.parse(raw);

      if (payload && typeof payload === 'object' && payload.values && typeof payload.values === 'object') {
        writeAutosavePayload(form, payload.values);
        setAutosaveStatus(form, 'Draft restored from this browser.');
      }
    } catch (error) {
      window.localStorage.removeItem(key);
    }
  }

  let timerId = 0;

  const persist = () => {
    const payload = {
      saved_at: new Date().toISOString(),
      values: readAutosavePayload(form),
    };

    window.localStorage.setItem(key, JSON.stringify(payload));
    setAutosaveStatus(form, 'Draft saved locally.');
  };

  const handleFieldChange = (event) => {
    const target = event.target;

    if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement)) {
      return;
    }

    if (!autosaveFields.includes(target.name)) {
      return;
    }

    window.clearTimeout(timerId);
    timerId = window.setTimeout(persist, event.type === 'input' ? 250 : 0);
  };

  form.addEventListener('input', handleFieldChange);
  form.addEventListener('change', handleFieldChange);
}

function parseChartConfig(canvas) {
  const configMapRaw = canvas.dataset.chartConfigs;

  if (configMapRaw) {
    try {
      const configMap = JSON.parse(configMapRaw);
      const activeKey = canvas.dataset.chartActive || canvas.dataset.chartDefault || '24h';

      if (configMap && typeof configMap === 'object' && activeKey in configMap) {
        return configMap[activeKey];
      }
    } catch (error) {
      return null;
    }
  }

  const raw = canvas.dataset.chartConfig;

  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw);
  } catch (error) {
    return null;
  }
}

function drawLineChart(ctx, width, height, config) {
  const padding = { top: 20, right: 20, bottom: 30, left: 36 };
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;
  const series = Array.isArray(config.series) ? config.series : [];
  const allValues = series.flatMap((item) => Array.isArray(item.values) ? item.values : []);
  const maxValue = Math.max(1, ...allValues);
  const labels = Array.isArray(config.labels) ? config.labels : [];

  ctx.clearRect(0, 0, width, height);
  ctx.strokeStyle = 'rgba(255,255,255,0.08)';
  ctx.lineWidth = 1;

  for (let i = 0; i < 4; i += 1) {
    const y = padding.top + (plotHeight / 3) * i;
    ctx.beginPath();
    ctx.moveTo(padding.left, y);
    ctx.lineTo(width - padding.right, y);
    ctx.stroke();
  }

  series.forEach((item) => {
    const values = Array.isArray(item.values) ? item.values : [];
    const color = typeof item.color === 'string' ? item.color : '#1df7ff';

    ctx.strokeStyle = color;
    ctx.lineWidth = 3;
    ctx.shadowBlur = 14;
    ctx.shadowColor = color;
    ctx.beginPath();

    values.forEach((value, index) => {
      const x = padding.left + (plotWidth * index) / Math.max(1, values.length - 1);
      const y = padding.top + plotHeight - (Number(value) / maxValue) * plotHeight;

      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });

    ctx.stroke();
    ctx.shadowBlur = 0;

    values.forEach((value, index) => {
      const x = padding.left + (plotWidth * index) / Math.max(1, values.length - 1);
      const y = padding.top + plotHeight - (Number(value) / maxValue) * plotHeight;

      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.arc(x, y, 3.5, 0, Math.PI * 2);
      ctx.fill();
    });
  });

  ctx.fillStyle = 'rgba(255,255,255,0.56)';
  ctx.font = '11px "JetBrains Mono", monospace';
  labels.forEach((label, index) => {
    if (labels.length > 8 && index % Math.ceil(labels.length / 6) !== 0 && index !== labels.length - 1) {
      return;
    }

    const x = padding.left + (plotWidth * index) / Math.max(1, labels.length - 1);
    ctx.fillText(String(label), x - 10, height - 8);
  });
}

function drawBarChart(ctx, width, height, config) {
  const padding = { top: 20, right: 20, bottom: 30, left: 24 };
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;
  const series = Array.isArray(config.series) ? config.series : [];
  const values = series[0] && Array.isArray(series[0].values) ? series[0].values : [];
  const labels = Array.isArray(config.labels) ? config.labels : [];
  const maxValue = Math.max(1, ...values);
  const color = series[0] && typeof series[0].color === 'string' ? series[0].color : '#8d5cff';
  const slotWidth = plotWidth / Math.max(1, values.length);
  const barWidth = Math.max(8, slotWidth * 0.62);

  ctx.clearRect(0, 0, width, height);

  values.forEach((value, index) => {
    const x = padding.left + slotWidth * index + (slotWidth - barWidth) / 2;
    const barHeight = (Number(value) / maxValue) * plotHeight;
    const y = padding.top + plotHeight - barHeight;
    const gradient = ctx.createLinearGradient(0, y, 0, y + barHeight);
    gradient.addColorStop(0, color);
    gradient.addColorStop(1, 'rgba(255,255,255,0.08)');
    ctx.fillStyle = gradient;
    ctx.shadowBlur = 12;
    ctx.shadowColor = color;
    ctx.fillRect(x, y, barWidth, barHeight);
    ctx.shadowBlur = 0;
  });

  ctx.fillStyle = 'rgba(255,255,255,0.56)';
  ctx.font = '11px "JetBrains Mono", monospace';
  labels.forEach((label, index) => {
    if (labels.length > 8 && index % Math.ceil(labels.length / 6) !== 0 && index !== labels.length - 1) {
      return;
    }

    const x = padding.left + slotWidth * index + 2;
    ctx.fillText(String(label), x, height - 8);
  });
}

function drawDonutChart(ctx, width, height, config) {
  const labels = Array.isArray(config.labels) ? config.labels : [];
  const segments = Array.isArray(config.segments) ? config.segments : [];
  const total = Math.max(1, segments.reduce((sum, item) => sum + Number(item.value || 0), 0));
  const centerX = width / 2;
  const centerY = height / 2 - 8;
  const radius = Math.min(width, height) * 0.26;
  const innerRadius = radius * 0.58;
  let start = -Math.PI / 2;

  ctx.clearRect(0, 0, width, height);

  segments.forEach((segment) => {
    const slice = (Number(segment.value || 0) / total) * Math.PI * 2;
    ctx.beginPath();
    ctx.strokeStyle = typeof segment.color === 'string' ? segment.color : '#1df7ff';
    ctx.lineWidth = radius - innerRadius;
    ctx.lineCap = 'round';
    ctx.shadowBlur = 10;
    ctx.shadowColor = ctx.strokeStyle;
    ctx.arc(centerX, centerY, (radius + innerRadius) / 2, start, start + slice);
    ctx.stroke();
    ctx.shadowBlur = 0;
    start += slice;
  });

  ctx.fillStyle = 'rgba(255,255,255,0.92)';
  ctx.font = '700 20px Inter, sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText(String(total), centerX, centerY + 6);
  ctx.font = '11px "JetBrains Mono", monospace';
  ctx.fillStyle = 'rgba(255,255,255,0.56)';
  ctx.fillText('views', centerX, centerY + 24);
  ctx.textAlign = 'left';

  labels.forEach((label, index) => {
    const segment = segments[index];
    const y = height - 56 + index * 14;
    const color = segment && typeof segment.color === 'string' ? segment.color : '#1df7ff';
    ctx.fillStyle = color;
    ctx.fillRect(18, y - 8, 8, 8);
    ctx.fillStyle = 'rgba(255,255,255,0.72)';
    ctx.fillText(String(label), 32, y);
  });
}

function renderAdminChart(canvas) {
  const config = parseChartConfig(canvas);

  if (!config) {
    return;
  }

  const ctx = canvas.getContext('2d');

  if (!ctx) {
    return;
  }

  const ratio = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  const width = Math.max(320, Math.floor(rect.width || canvas.width));
  const minHeight = canvas.closest('.dashboard-traffic-chart-lg, .traffic-monitor-card') ? 420 : 220;
  const height = Math.max(minHeight, Math.floor((rect.height || canvas.height) || 260));
  canvas.width = width * ratio;
  canvas.height = height * ratio;
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

  const type = canvas.dataset.chartType || 'line';

  if (type === 'bar') {
    drawBarChart(ctx, width, height, config);
    return;
  }

  if (type === 'donut') {
    drawDonutChart(ctx, width, height, config);
    return;
  }

  drawLineChart(ctx, width, height, config);
}

function initAdminCharts() {
  const charts = Array.from(document.querySelectorAll('.admin-chart-canvas'));

  if (charts.length === 0) {
    return;
  }

  const render = () => {
    charts.forEach((canvas) => {
      if (canvas instanceof HTMLCanvasElement) {
        renderAdminChart(canvas);
      }
    });
  };

  render();
  window.addEventListener('resize', render);
}

function initChartRangeSwitchers() {
  const switches = Array.from(document.querySelectorAll('.traffic-range-switch'));

  for (const switcher of switches) {
    if (!(switcher instanceof HTMLElement)) {
      continue;
    }

    switcher.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-chart-range]') : null;

      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      const monitor = switcher.closest('.traffic-monitor-card');
      const canvas = monitor ? monitor.querySelector('.admin-chart-canvas') : null;
      const range = button.dataset.chartRange;

      if (!(canvas instanceof HTMLCanvasElement) || !range) {
        return;
      }

      canvas.dataset.chartActive = range;

      for (const item of switcher.querySelectorAll('[data-chart-range]')) {
        if (item instanceof HTMLButtonElement) {
          const isActive = item === button;
          item.classList.toggle('is-active', isActive);
          item.setAttribute('aria-selected', isActive ? 'true' : 'false');
        }
      }

      renderAdminChart(canvas);
    });
  }
}

function initParticles() {
  const canvas = document.querySelector('[data-admin-particles]');

  if (!(canvas instanceof HTMLCanvasElement) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  const context = canvas.getContext('2d');

  if (!context) {
    return;
  }

  const particles = [];
  const particleCount = 36;

  const resize = () => {
    const ratio = window.devicePixelRatio || 1;
    canvas.width = window.innerWidth * ratio;
    canvas.height = window.innerHeight * ratio;
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
  };

  resize();

  for (let i = 0; i < particleCount; i += 1) {
    particles.push({
      x: Math.random() * window.innerWidth,
      y: Math.random() * window.innerHeight,
      radius: 1 + Math.random() * 2.2,
      dx: -0.35 + Math.random() * 0.7,
      dy: -0.25 + Math.random() * 0.5,
      color: i % 2 === 0 ? 'rgba(29,247,255,0.85)' : 'rgba(255,43,214,0.72)',
    });
  }

  const draw = () => {
    context.clearRect(0, 0, window.innerWidth, window.innerHeight);

    particles.forEach((particle, index) => {
      particle.x += particle.dx;
      particle.y += particle.dy;

      if (particle.x < -10) particle.x = window.innerWidth + 10;
      if (particle.x > window.innerWidth + 10) particle.x = -10;
      if (particle.y < -10) particle.y = window.innerHeight + 10;
      if (particle.y > window.innerHeight + 10) particle.y = -10;

      context.beginPath();
      context.fillStyle = particle.color;
      context.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
      context.fill();

      for (let j = index + 1; j < particles.length; j += 1) {
        const other = particles[j];
        const distance = Math.hypot(particle.x - other.x, particle.y - other.y);

        if (distance < 120) {
          context.beginPath();
          context.strokeStyle = `rgba(125, 190, 255, ${(1 - distance / 120) * 0.18})`;
          context.lineWidth = 1;
          context.moveTo(particle.x, particle.y);
          context.lineTo(other.x, other.y);
          context.stroke();
        }
      }
    });

    window.requestAnimationFrame(draw);
  };

  window.addEventListener('resize', resize);
  window.requestAnimationFrame(draw);
}

function initMenuBuilder() {
  for (const form of document.querySelectorAll('[data-menu-builder]')) {
    if (!(form instanceof HTMLFormElement)) {
      continue;
    }

    const list = form.querySelector('[data-menu-items]');
    const template = form.querySelector('[data-menu-item-template]');
    const nextIndexField = form.querySelector('[data-menu-next-index]');

    if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement) || !(nextIndexField instanceof HTMLInputElement)) {
      continue;
    }

    let dragged = null;

    const cards = () => Array.from(list.querySelectorAll('[data-menu-item]')).filter((item) => item instanceof HTMLElement);

    const updateEmptyState = () => {
      const empty = list.querySelector('.menu-builder-empty');
      const hasCards = cards().length > 0;

      if (!hasCards && !(empty instanceof HTMLElement)) {
        const note = document.createElement('p');
        note.className = 'helper-text menu-builder-empty';
        note.textContent = 'No items yet. Add the first menu item to begin.';
        list.appendChild(note);
      }

      if (hasCards && empty instanceof HTMLElement) {
        empty.remove();
      }
    };

    const setDepth = (card, depth) => {
      card.style.setProperty('--menu-depth', String(Math.min(depth, 5)));
    };

    const refreshParentOptions = () => {
      const options = cards().map((card) => {
        const rowId = card.dataset.rowId || '';
        const labelInput = card.querySelector('input[name="item_label[]"]');
        const label = labelInput instanceof HTMLInputElement && labelInput.value.trim() !== '' ? labelInput.value.trim() : rowId;

        return { rowId, label };
      });

      const parents = new Map();

      cards().forEach((card) => {
        const select = card.querySelector('[data-parent-select]');

        if (select instanceof HTMLSelectElement) {
          parents.set(card.dataset.rowId || '', select.value);
        }
      });

      const computeDepth = (rowId, visited = new Set()) => {
        const parent = parents.get(rowId) || '';

        if (!parent || visited.has(parent)) {
          return 0;
        }

        visited.add(parent);

        return 1 + computeDepth(parent, visited);
      };

      cards().forEach((card, index) => {
        const rowId = card.dataset.rowId || '';
        const title = card.querySelector('.menu-builder-item-meta strong');
        const indexBadge = card.querySelector('[data-menu-item-index]');
        const labelInput = card.querySelector('input[name="item_label[]"]');
        const select = card.querySelector('[data-parent-select]');

        if (title instanceof HTMLElement && labelInput instanceof HTMLInputElement) {
          title.textContent = labelInput.value.trim() !== '' ? labelInput.value.trim() : 'Untitled item';
        }

        if (indexBadge instanceof HTMLElement) {
          indexBadge.textContent = String(index + 1);
        }

        if (select instanceof HTMLSelectElement) {
          const current = select.value;
          select.innerHTML = '';

          const topLevel = document.createElement('option');
          topLevel.value = '';
          topLevel.textContent = 'Top level';
          select.appendChild(topLevel);

          for (const option of options) {
            if (option.rowId === rowId) {
              continue;
            }

            const element = document.createElement('option');
            element.value = option.rowId;
            element.textContent = option.label;

            if (option.rowId === current) {
              element.selected = true;
            }

            select.appendChild(element);
          }
        }

        setDepth(card, computeDepth(rowId));
      });
    };

    const syncLinkFields = (card) => {
      const linkType = card.querySelector('[data-link-type]');
      const pageField = card.querySelector('.menu-page-field');
      const urlField = card.querySelector('.menu-url-field');

      if (!(linkType instanceof HTMLSelectElement) || !(pageField instanceof HTMLElement) || !(urlField instanceof HTMLElement)) {
        return;
      }

      const isPage = linkType.value === 'page';
      pageField.hidden = !isPage;
      urlField.hidden = isPage;
    };

    const syncAll = () => {
      updateEmptyState();
      refreshParentOptions();

      for (const card of cards()) {
        syncLinkFields(card);
      }
    };

    const insertFromTemplate = () => {
      const currentIndex = Number.parseInt(nextIndexField.value || '1', 10) || 1;
      const rowId = `new-${currentIndex}`;
      nextIndexField.value = String(currentIndex + 1);
      const html = template.innerHTML.replaceAll('__ROW_ID__', rowId);
      list.insertAdjacentHTML('beforeend', html);
      syncAll();
    };

    form.addEventListener('click', (event) => {
      const addButton = event.target instanceof Element ? event.target.closest('[data-menu-add]') : null;

      if (addButton instanceof HTMLButtonElement) {
        event.preventDefault();
        insertFromTemplate();
        return;
      }

      const removeButton = event.target instanceof Element ? event.target.closest('[data-menu-remove]') : null;

      if (removeButton instanceof HTMLButtonElement) {
        event.preventDefault();
        removeButton.closest('[data-menu-item]')?.remove();
        syncAll();
        return;
      }

      const moveUpButton = event.target instanceof Element ? event.target.closest('[data-menu-move-up]') : null;

      if (moveUpButton instanceof HTMLButtonElement) {
        event.preventDefault();
        const card = moveUpButton.closest('[data-menu-item]');

        if (card && card.previousElementSibling) {
          card.parentElement?.insertBefore(card, card.previousElementSibling);
          syncAll();
        }

        return;
      }

      const moveDownButton = event.target instanceof Element ? event.target.closest('[data-menu-move-down]') : null;

      if (moveDownButton instanceof HTMLButtonElement) {
        event.preventDefault();
        const card = moveDownButton.closest('[data-menu-item]');

        if (card && card.nextElementSibling) {
          card.parentElement?.insertBefore(card.nextElementSibling, card);
          syncAll();
        }
      }
    });

    form.addEventListener('input', (event) => {
      const card = event.target instanceof Element ? event.target.closest('[data-menu-item]') : null;

      if (card instanceof HTMLElement) {
        syncLinkFields(card);
        refreshParentOptions();
      }
    });

    form.addEventListener('change', (event) => {
      const card = event.target instanceof Element ? event.target.closest('[data-menu-item]') : null;

      if (card instanceof HTMLElement) {
        syncAll();
      }
    });

    list.addEventListener('dragstart', (event) => {
      const card = event.target instanceof Element ? event.target.closest('[data-menu-item]') : null;

      if (!(card instanceof HTMLElement) || !(event.target instanceof HTMLElement)) {
        return;
      }

      dragged = card;
      card.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', card.dataset.rowId || '');
      event.dataTransfer?.setDragImage(card, 40, 20);
    });

    list.addEventListener('dragend', () => {
      if (dragged instanceof HTMLElement) {
        dragged.classList.remove('is-dragging');
      }

      dragged = null;

      for (const card of cards()) {
        card.classList.remove('is-drop-target');
      }
    });

    list.addEventListener('dragover', (event) => {
      event.preventDefault();

      if (!(dragged instanceof HTMLElement)) {
        return;
      }

      const target = event.target instanceof Element ? event.target.closest('[data-menu-item]') : null;

      for (const card of cards()) {
        card.classList.toggle('is-drop-target', card === target && card !== dragged);
      }

      if (!(target instanceof HTMLElement) || target === dragged) {
        return;
      }

      const rect = target.getBoundingClientRect();
      const before = event.clientY < rect.top + rect.height / 2;
      list.insertBefore(dragged, before ? target : target.nextElementSibling);
    });

    list.addEventListener('drop', (event) => {
      event.preventDefault();
      syncAll();
    });

    syncAll();
  }
}

function initTemplateModuleSlots() {
  const menuCards = Array.from(document.querySelectorAll('[data-menu-component]'));
  const slotCards = Array.from(document.querySelectorAll('[data-slot-card]'));

  if (menuCards.length === 0 || slotCards.length === 0) {
    return;
  }

  let draggedMenu = null;

  for (const card of menuCards) {
    if (!(card instanceof HTMLElement)) {
      continue;
    }

    card.addEventListener('dragstart', (event) => {
      draggedMenu = card;
      card.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', card.dataset.menuId || '');
    });

    card.addEventListener('dragend', () => {
      card.classList.remove('is-dragging');
      draggedMenu = null;

      for (const slot of slotCards) {
        if (slot instanceof HTMLElement) {
          slot.classList.remove('is-drop-target');
        }
      }
    });
  }

  for (const slot of slotCards) {
    if (!(slot instanceof HTMLElement)) {
      continue;
    }

    slot.addEventListener('dragover', (event) => {
      event.preventDefault();
      slot.classList.add('is-drop-target');
    });

    slot.addEventListener('dragleave', () => {
      slot.classList.remove('is-drop-target');
    });

    slot.addEventListener('drop', (event) => {
      event.preventDefault();
      slot.classList.remove('is-drop-target');

      if (!(draggedMenu instanceof HTMLElement)) {
        return;
      }

      const form = slot.querySelector('[data-slot-form]');
      const select = slot.querySelector('[data-slot-select]');

      if (!(form instanceof HTMLFormElement) || !(select instanceof HTMLSelectElement)) {
        return;
      }

      const nextValue = draggedMenu.dataset.menuId || '';

      if (select.value && select.value !== nextValue) {
        const currentLabel = select.selectedOptions[0] ? select.selectedOptions[0].textContent || 'the current menu' : 'the current menu';

        if (!window.confirm(`Replace ${currentLabel} in this slot?`)) {
          return;
        }
      }

      select.value = nextValue;
      form.requestSubmit();
    });
  }
}

document.addEventListener('click', async (event) => {
  const copyTarget = event.target instanceof Element ? event.target.closest('[data-copy-text]') : null;

  if (copyTarget instanceof HTMLButtonElement) {
    event.preventDefault();

    const text = copyTarget.dataset.copyText;

    if (!text) {
      return;
    }

    const original = copyTarget.textContent;

    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'absolute';
        helper.style.left = '-9999px';
        document.body.appendChild(helper);
        helper.select();
        document.execCommand('copy');
        helper.remove();
      }

      copyTarget.textContent = 'Copied';
      window.setTimeout(() => {
        copyTarget.textContent = original;
      }, 1200);
    } catch (error) {
      window.alert('Copy failed. You can still select the snippet manually.');
    }

    return;
  }

  const themeToggle = event.target instanceof Element ? event.target.closest('[data-admin-theme-toggle]') : null;

  if (themeToggle instanceof HTMLButtonElement) {
    event.preventDefault();
    setAdminTheme(document.documentElement.dataset.adminTheme === 'dark' ? 'light' : 'dark');

    return;
  }

  const sidebarToggle = event.target instanceof Element ? event.target.closest('[data-admin-sidebar-toggle], [data-admin-overlay]') : null;

  if (sidebarToggle instanceof HTMLElement) {
    event.preventDefault();
    toggleAdminSidebar(sidebarToggle.hasAttribute('data-admin-overlay') ? false : undefined);
  }
});

document.addEventListener('submit', (event) => {
  const form = event.target instanceof HTMLFormElement ? event.target : null;

  if (!form) {
    return;
  }

  const message = form.dataset.confirm;

  if (message && !window.confirm(message)) {
    event.preventDefault();
  }
});

document.addEventListener('change', (event) => {
  const target = event.target;

  if (target instanceof HTMLInputElement && target.matches('[data-admin-theme-toggle][type="checkbox"]')) {
    setAdminTheme(target.checked ? 'dark' : 'light');
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    toggleAdminSidebar(false);
  }
});

document.addEventListener('DOMContentLoaded', () => {
  setAdminTheme(preferredAdminTheme());

  for (const form of document.querySelectorAll('form[data-autosave-key]')) {
    if (form instanceof HTMLFormElement) {
      bindAutosave(form);
    }
  }

  initAdminCharts();
  initChartRangeSwitchers();
  initParticles();
  initMenuBuilder();
  initTemplateModuleSlots();
});
