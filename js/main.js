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

/* Стрелки над табами (мобильная версия): соседний таб */
function initTabArrows() {
  document.querySelectorAll('[data-tab-step]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const tabs = [...document.querySelectorAll('.programs__tab')];
      const i = tabs.findIndex((t) => t.getAttribute('aria-selected') === 'true');
      const next = tabs[(i + Number(btn.dataset.tabStep) + tabs.length) % tabs.length];
      next.click();
      next.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    });
  });
}

/* Ссылки «Программы» в подвале открывают нужный таб */
function initTabLinks() {
  const select = (name) => {
    const tab = document.getElementById('tab-' + name);
    if (tab) tab.click();
    return Boolean(tab);
  };
  document.querySelectorAll('a[data-tab]').forEach((link) => {
    link.addEventListener('click', (e) => {
      // на главной — переключаем таб без перезагрузки; с других страниц ссылка ведёт на главную с ?tab=
      if (select(link.dataset.tab)) {
        e.preventDefault();
        document.getElementById('programs').scrollIntoView({ behavior: 'smooth' });
        history.replaceState(null, '', '#programs');
      }
    });
  });
  const fromUrl = new URLSearchParams(location.search).get('tab');
  if (fromUrl) select(fromUrl);
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

/* Мобильное меню: панель справа */
function initDrawer() {
  const drawer = document.getElementById('drawer');
  const btn = document.querySelector('.header__burger');
  if (!drawer || !btn) return;
  const panel = drawer.querySelector('.drawer__panel');
  let closeTimer;

  const open = () => {
    clearTimeout(closeTimer);
    drawer.hidden = false;
    requestAnimationFrame(() => requestAnimationFrame(() => drawer.classList.add('is-open')));
    document.body.classList.add('is-drawer-open');
    btn.setAttribute('aria-expanded', 'true');
    drawer.querySelector('.drawer__close').focus();
  };
  const close = (returnFocus = true) => {
    drawer.classList.remove('is-open');
    document.body.classList.remove('is-drawer-open');
    btn.setAttribute('aria-expanded', 'false');
    closeTimer = setTimeout(() => { drawer.hidden = true; }, 400);
    if (returnFocus) btn.focus();
  };

  btn.addEventListener('click', open);
  drawer.querySelector('[data-drawer-close]').addEventListener('click', () => close());
  // клик по затемнению слева
  drawer.addEventListener('click', (e) => { if (!panel.contains(e.target)) close(); });
  // выбран раздел — закрываем, переход по якорю выполнит браузер
  drawer.querySelectorAll('a[href]').forEach((a) => a.addEventListener('click', () => close(false)));
  document.addEventListener('keydown', (e) => {
    if (drawer.hidden) return;
    if (e.key === 'Escape') close();
    // фокус не уходит за пределы панели
    if (e.key === 'Tab') {
      const f = [...panel.querySelectorAll('a[href], button')];
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });
  window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => { if (e.matches && !drawer.hidden) close(false); });
}

/* Слайдер «Как проходят занятия» на телефоне: прокрутка со snap + стрелки и точки */
function initLessonsSlider() {
  const list = document.querySelector('.lessons__list');
  const dots = [...document.querySelectorAll('.lessons__dots span')];
  if (!list || !dots.length) return;
  const cards = [...list.children];
  const current = () => {
    const x = list.scrollLeft;
    let best = 0;
    cards.forEach((c, i) => { if (Math.abs(c.offsetLeft - list.offsetLeft - x) < Math.abs(cards[best].offsetLeft - list.offsetLeft - x)) best = i; });
    // у правого края — последний слайд
    if (x + list.clientWidth >= list.scrollWidth - 2) best = cards.length - 1;
    return best;
  };
  const update = () => dots.forEach((d, i) => d.classList.toggle('is-active', i === current()));
  list.addEventListener('scroll', () => requestAnimationFrame(update), { passive: true });
  document.querySelectorAll('[data-slide-step]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const i = Math.min(cards.length - 1, Math.max(0, current() + Number(btn.dataset.slideStep)));
      list.scrollTo({ left: cards[i].offsetLeft - list.offsetLeft, behavior: 'smooth' });
    });
  });
}

/* Таблица сравнения: подсказка «листайте» исчезает после первой прокрутки */
function initCompareHint() {
  const wrap = document.querySelector('.compare__wrap');
  const hint = document.querySelector('.compare__hint');
  if (!wrap || !hint) return;
  wrap.addEventListener('scroll', () => { if (wrap.scrollLeft > 20) hint.classList.add('is-hidden'); }, { passive: true });
}

/* 1024–1439 px: до планшетного макета десктоп масштабируется целиком, без обрезки справа */
function initDesktopZoom() {
  const mq = window.matchMedia('(min-width: 1024px) and (max-width: 1439.98px)');
  const update = () => {
    document.documentElement.style.setProperty('--page-zoom', mq.matches ? String(window.innerWidth / 1440) : '1');
  };
  update();
  window.addEventListener('resize', update, { passive: true });
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
  initDesktopZoom();
  initHeader();
  initDrawer();
  initVideoModal();
  initReveal();
  document.querySelectorAll('[data-year]').forEach((el) => { el.textContent = new Date().getFullYear(); });
  document.querySelectorAll('[role="tablist"]').forEach(initTabs);
  initTabLinks();
  initTabArrows();
  initLessonsSlider();
  initCompareHint();
  document.querySelectorAll('.lead__form').forEach(initLeadForm);
});
