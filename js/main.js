// thousandli.ru — основной скрипт.
'use strict';

/* Табы «Выберите подходящую программу» (WAI-ARIA tabs, стрелки ←/→, Home/End) */
function initTabs(tablist) {
  const tabs = [...tablist.querySelectorAll('[role="tab"]')];

  const select = (tab, focus) => {
    tabs.forEach((t) => {
      const on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) tab.focus();
  };

  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => select(tab));
    tab.addEventListener('keydown', (e) => {
      const next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
      if (next === undefined) return;
      e.preventDefault();
      select(tabs[(next + tabs.length) % tabs.length], true);
    });
  });
}

/* Ссылки «Программы» в подвале открывают нужный таб */
function initTabLinks() {
  document.querySelectorAll('a[data-tab]').forEach((link) => {
    link.addEventListener('click', () => {
      const tab = document.getElementById('tab-' + link.dataset.tab);
      if (tab) tab.click();
    });
  });
}

/* Маска телефона: +7 (999) 000-00-00 */
function initPhoneMask(input) {
  const format = (value) => {
    let d = value.replace(/\D/g, '');
    // «+7 …» уже введено — первая 7 это код страны; иначе 8/7 в начале 11-значного номера — тоже код
    if (value.startsWith('+7')) d = d.slice(1);
    else if (d.length === 11 && /^[78]/.test(d)) d = d.slice(1);
    // по привычке набрали 8 или 7 сразу после +7 — отбрасываем
    if (d.length === 1 && /[78]/.test(d)) d = '';
    d = '7' + d.slice(0, 10);
    const p = [d.slice(1, 4), d.slice(4, 7), d.slice(7, 9), d.slice(9, 11)];
    let out = '+7';
    if (p[0]) out += ' (' + p[0];
    if (p[0].length === 3) out += ')';
    if (p[1]) out += ' ' + p[1];
    if (p[2]) out += '-' + p[2];
    if (p[3]) out += '-' + p[3];
    return out;
  };
  input.addEventListener('input', () => { input.value = input.value ? format(input.value) : ''; });
  input.addEventListener('paste', (e) => {
    e.preventDefault();
    input.value = format((e.clipboardData || window.clipboardData).getData('text'));
  });
  input.addEventListener('focus', () => { if (!input.value) input.value = '+7 '; });
  input.addEventListener('blur', () => { if (input.value.replace(/\D/g, '').length <= 1) input.value = ''; });
}

/* Отправка формы заявки без перезагрузки */
function initLeadForm(form) {
  const name = form.elements.name;
  const phone = form.elements.phone;
  const consent = form.elements.consent;
  const status = form.querySelector('.lead__status');
  const submit = form.querySelector('[type="submit"]');
  initPhoneMask(phone);

  const setStatus = (text, isError) => {
    status.textContent = text;
    status.classList.toggle('is-error', Boolean(isError));
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const okName = name.value.trim().length >= 2;
    const okPhone = phone.value.replace(/\D/g, '').length === 11;
    const okConsent = consent.checked;
    name.classList.toggle('is-invalid', !okName);
    phone.classList.toggle('is-invalid', !okPhone);
    consent.closest('.lead__consent').classList.toggle('is-invalid', !okConsent);
    if (!okName || !okPhone || !okConsent) {
      setStatus(!okConsent && okName && okPhone ? 'Подтвердите согласие на обработку данных' : 'Проверьте имя и телефон', true);
      return;
    }

    submit.disabled = true;
    setStatus('Отправляем…');
    try {
      const res = await fetch(form.action, { method: 'POST', body: new FormData(form) });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.error || 'send failed');
      form.reset();
      setStatus('Спасибо! Мы свяжемся с вами в ближайшее время.');
    } catch (err) {
      setStatus('Не удалось отправить. Позвоните нам: 8 (953) 156-06-85', true);
    } finally {
      submit.disabled = false;
    }
  });
}

/* Закреплённая шапка: «стекло» плотнее после начала прокрутки */
function initHeader() {
  const header = document.querySelector('.header');
  if (!header) return;
  const update = () => header.classList.toggle('is-scrolled', window.scrollY > 10);
  update();
  window.addEventListener('scroll', update, { passive: true });
}

/* Появление блоков при прокрутке. Классы вешает JS — без JS всё видно сразу */
function initReveal() {
  if (!('IntersectionObserver' in window)) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // [селектор, вариант, шаг задержки между соседями, мс]
  const groups = [
    ['.hero__content', '', 0],
    ['.hero__founder, .hero__temple', 'zoom', 120],
    ['.stats__item', '', 90],
    ['.section-title, .method__title, .programs__title, .founder__title, .steps__title, .reviews__title, .lead__title', '', 0],
    ['.method__item', 'left', 110],
    ['.method__book', 'zoom', 0],
    ['.method__summary', '', 0],
    ['.programs__tabs', '', 0],
    ['.program-card', '', 70],
    ['.founder__motto, .founder__name', 'left', 80],
    ['.founder__fact', 'left', 80],
    ['.founder__collage, .founder__more', '', 100],
    ['.founder__video', 'right', 0],
    ['.lesson', '', 110],
    ['.cta', '', 0],
    ['.adv-main', 'left', 0],
    ['.adv-card, .adv-photo', '', 80],
    ['.steps__btn', '', 0],
    ['.compare__wrap', '', 0],
    ['.plan', '', 140],
    ['.reviews__photo', 'zoom', 0],
    ['.review--a, .review--b, .review--d, .reviews__note', '', 110],
    ['.faq__item', '', 70],
    ['.lead__info', 'left', 0],
    ['.lead__form, .lead__contacts', 'right', 100],
    ['.footer__brand, .footer__col', '', 90],
  ];

  const items = [];
  groups.forEach(([selector, variant, step]) => {
    const parents = new Map();
    document.querySelectorAll(selector).forEach((el) => {
      // задержка считается внутри общего родителя — соседние карточки выезжают по очереди
      const key = el.parentElement;
      const i = parents.get(key) || 0;
      parents.set(key, i + 1);
      el.classList.add('reveal');
      if (variant) el.classList.add('reveal--' + variant);
      if (step) el.style.setProperty('--d', Math.min(i * step, 600) + 'ms');
      items.push(el);
    });
  });

  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      el.classList.add('is-in');
      io.unobserve(el);
      // после появления убираем задержку, чтобы наведение реагировало сразу
      el.addEventListener('transitionend', function done(e) {
        if (e.target !== el || e.propertyName !== 'opacity') return;
        el.classList.add('is-done');
        el.removeEventListener('transitionend', done);
      });
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

  items.forEach((el) => io.observe(el));
}

/* Видео-презентация в модальном окне */
function initVideoModal() {
  const modal = document.getElementById('video-modal');
  if (!modal || typeof modal.showModal !== 'function') return;
  const video = modal.querySelector('video');
  const fallback = modal.querySelector('.video-modal__fallback');

  // файла ещё нет на хостинге — показываем аккуратное сообщение вместо пустого плеера
  video.querySelector('source').addEventListener('error', () => {
    video.hidden = true;
    fallback.hidden = false;
  });

  const open = () => {
    modal.showModal();
    document.body.classList.add('is-modal-open');
    if (!video.hidden) {
      video.play().catch(() => {});
    }
  };
  const close = () => modal.close();

  document.querySelectorAll('[data-video-open]').forEach((btn) => btn.addEventListener('click', open));
  modal.querySelector('[data-video-close]').addEventListener('click', close);
  // клик по затемнению (вне окна) закрывает
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  modal.addEventListener('close', () => {
    video.pause();
    document.body.classList.remove('is-modal-open');
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initHeader();
  initVideoModal();
  initReveal();
  document.querySelectorAll('[data-year]').forEach((el) => { el.textContent = new Date().getFullYear(); });
  document.querySelectorAll('[role="tablist"]').forEach(initTabs);
  initTabLinks();
  const form = document.querySelector('.lead__form');
  if (form) initLeadForm(form);
});
