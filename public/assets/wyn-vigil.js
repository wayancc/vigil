/* Vigil: small progressive enhancements. Every page works without this file. */
(() => {
  'use strict';
  const wynAll = (s, el = document) => [...el.querySelectorAll(s)];

  /* Monitor form: show only the fields for the chosen type, and disable the rest so they aren't validated. */
  const wynForm = document.querySelector('[data-wyn-monitor-form]');
  if (wynForm) {
    const wynSync = () => {
      const type = wynForm.querySelector('input[name="type"]:checked')?.value;
      wynAll('[data-wyn-show]', wynForm).forEach((el) => {
        const on = el.dataset.wynShow.split(' ').includes(type);
        el.hidden = !on;
        wynAll('input, select, textarea', el).forEach((input) => { input.disabled = !on; });
      });
    };
    wynForm.addEventListener('change', (e) => { if (e.target.name === 'type') wynSync(); });
    wynSync();
  }

  /* Click a code block to copy it. */
  wynAll('[data-wyn-copy]').forEach((el) => {
    el.title = 'Click to copy';
    el.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(el.textContent.trim());
        el.classList.add('wyn-copied');
        setTimeout(() => el.classList.remove('wyn-copied'), 1400);
      } catch {
        getSelection().selectAllChildren(el);
      }
    });
  });

  /* Confirm destructive actions. */
  wynAll('form[data-wyn-confirm]').forEach((f) => {
    f.addEventListener('submit', (e) => { if (!confirm(f.dataset.wynConfirm)) e.preventDefault(); });
  });

  /* Uptime bar tooltips (hover on desktop, tap on touch). */
  const wynTip = document.querySelector('.wyn-tip')
    ?? Object.assign(document.body.appendChild(document.createElement('div')), { className: 'wyn-tip', hidden: true });
  const wynShowTip = (bar) => {
    const [day, pct, detail] = bar.dataset.wynTip.split('|');
    wynTip.innerHTML = '';
    const strong = document.createElement('strong');
    strong.textContent = day;
    wynTip.append(strong, pct, ...(detail ? [document.createElement('br'), detail] : []));
    wynTip.hidden = false;
    const r = bar.getBoundingClientRect();
    const t = wynTip.getBoundingClientRect();
    const left = Math.min(Math.max(8, r.left + r.width / 2 - t.width / 2), innerWidth - t.width - 8);
    const top = r.top - t.height - 8 < 8 ? r.bottom + 8 : r.top - t.height - 8;
    wynTip.style.left = `${left}px`;
    wynTip.style.top = `${top}px`;
  };
  document.addEventListener('pointerover', (e) => {
    const bar = e.target.closest?.('.wyn-bar[data-wyn-tip]');
    if (bar) wynShowTip(bar); else if (e.pointerType === 'mouse') wynTip.hidden = true;
  });
  document.addEventListener('pointerdown', (e) => { if (!e.target.closest?.('.wyn-bar')) wynTip.hidden = true; });
  addEventListener('scroll', () => { wynTip.hidden = true; }, { passive: true });

  /* Status page: refresh quietly while the tab is visible. */
  const wynEvery = Number(document.body.dataset.wynAutorefresh || 0);
  if (wynEvery > 0) {
    setInterval(() => { if (document.visibilityState === 'visible') location.reload(); }, wynEvery * 1000);
  }
})();
