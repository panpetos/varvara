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
/* Яндекс SmartCaptcha «Я не робот».
   Ключ клиента подставляет деплой из GitHub Secrets (SMARTCAPTCHA_CLIENT_KEY); пусто — капча выключена.
   Скрипт капчи грузится только когда человек начал заполнять форму. */
const CAPTCHA_SITEKEY = '';
let captchaLoading;
function loadCaptcha() {
  captchaLoading = captchaLoading || new Promise((resolve, reject) => {
    window.onSmartCaptchaLoad = () => resolve(window.smartCaptcha);
    const s = document.createElement('script');
    s.src = 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=onSmartCaptchaLoad';
    s.defer = true;
    s.onerror = reject;
    document.head.append(s);
  });
  return captchaLoading;
}

function initCaptcha(form, { lazy = true } = {}) {
  const box = form.querySelector('[data-captcha]');
  const off = { start() {}, ok: () => true, reset() {} };
  if (!CAPTCHA_SITEKEY || !box) return off;
  let id;
  const start = () => {
    if (id !== undefined) return;
    loadCaptcha().then((sc) => {
      if (id !== undefined) return;
      box.hidden = false;
      id = sc.render(box, { sitekey: CAPTCHA_SITEKEY, hl: 'ru' });
    }).catch(() => {});
  };
  if (lazy) {
    form.addEventListener('focusin', start, { once: true });
    form.addEventListener('pointerdown', start, { once: true });
  }
  return {
    start,
    ok: () => id !== undefined && Boolean(window.smartCaptcha.getResponse(id)),
    reset: () => { if (id !== undefined) window.smartCaptcha.reset(id); },
  };
}

function initLeadForm(form) {
  const name = form.elements.name;
  const phone = form.elements.phone;
  const consent = form.elements.consent;
  const status = form.querySelector('.lead__status');
  const submit = form.querySelector('[type="submit"]');
  const captcha = initCaptcha(form);
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
    if (!captcha.ok()) {
      captcha.start();
      setStatus('Отметьте «Я не робот»', true);
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
      setStatus(err.message === 'captcha' ? 'Отметьте «Я не робот» ещё раз' : 'Не удалось отправить. Позвоните нам: 8 (953) 156-06-85', true);
    } finally {
      captcha.reset();
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
    // на телефоне карточки занятий — горизонтальный слайдер: появляется весь ряд целиком,
    // иначе карточки «подпрыгивают» при первом пролистывании
    [window.matchMedia('(max-width: 1023.98px)').matches ? '.lessons__list' : '.lesson', '', 110],
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

/* «Об основателе»: выделенные слова в тексте открывают фото справа (на телефоне — окном) */
function initStory() {
  const viewer = document.getElementById('story-viewer');
  if (!viewer) return;
  const img = viewer.querySelector('.story__img');
  const caption = viewer.querySelector('.story__caption');
  const thumbs = [...document.querySelectorAll('.story__thumb')];
  const marks = [...document.querySelectorAll('.story__mark')];
  const modal = document.getElementById('story-modal');
  const narrow = window.matchMedia('(max-width: 599.98px)');

  const select = (id, fromMark) => {
    const t = thumbs.find((b) => b.dataset.photo === id);
    if (!t) return;
    thumbs.forEach((b) => { const on = b === t; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on)); });
    marks.forEach((m) => m.classList.toggle('is-active', m.dataset.photo === id));
    if (fromMark && narrow.matches && modal && typeof modal.showModal === 'function') {
      const mi = modal.querySelector('.story-modal__img');
      mi.src = t.dataset.src; mi.alt = t.dataset.alt;
      modal.querySelector('.story-modal__caption').innerHTML = t.dataset.caption;
      modal.showModal();
      document.body.classList.add('is-modal-open');
      return;
    }
    if (img.getAttribute('src') !== t.dataset.src) {
      img.classList.add('is-loading');
      const next = new Image();
      next.onload = () => { img.src = t.dataset.src; img.width = t.dataset.w; img.height = t.dataset.h; img.alt = t.dataset.alt; img.classList.remove('is-loading'); };
      next.src = t.dataset.src;
    }
    caption.innerHTML = t.dataset.caption;
  };

  marks.forEach((m) => m.addEventListener('click', () => select(m.dataset.photo, true)));
  thumbs.forEach((b) => b.addEventListener('click', () => select(b.dataset.photo, false)));
  if (modal) {
    modal.querySelector('[data-story-close]').addEventListener('click', () => modal.close());
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.close(); });
    modal.addEventListener('close', () => document.body.classList.remove('is-modal-open'));
  }
}

/* Карточка программы кликабельна целиком — как ссылка «Подробнее» внутри неё */
function initProgramCards() {
  document.querySelectorAll('.program-card').forEach((card) => {
    const link = card.querySelector('.program-card__more');
    if (!link) return;
    card.classList.add('is-link');
    card.addEventListener('click', (e) => {
      if (e.target.closest('a')) return;            // по самой ссылке — обычный переход
      if (window.getSelection().toString()) return; // выделяли текст — не переходим
      link.click();
    });
  });
}

/* Квиз «Подобрать программу»: 4 вопроса → рекомендация + контакты → send.php */
function initQuiz() {
  const quiz = document.getElementById('quiz');
  if (!quiz || typeof quiz.showModal !== 'function') return;
  const form = quiz.querySelector('form');
  const steps = [...quiz.querySelectorAll('[data-step]')];
  const next = quiz.querySelector('[data-quiz-next]');
  const back = quiz.querySelector('[data-quiz-back]');
  const submit = quiz.querySelector('[data-quiz-submit]');
  const num = quiz.querySelector('[data-quiz-num]');
  const bar = quiz.querySelector('[data-quiz-progress]');
  const status = quiz.querySelector('.quiz__status');
  const head = quiz.querySelector('.quiz__head');
  const captcha = initCaptcha(form, { lazy: false });
  // на странице программы квиз итоговый: сводка ответов по этой программе
  const program = quiz.dataset.program;
  const summary = quiz.querySelector('[data-quiz-summary]');
  const alt = quiz.querySelector('[data-quiz-alt]');
  let step = 1;

  // рекомендация по ответам — только существующие программы школы (страницы программ)
  const recommend = () => {
    const v = (n) => (form.elements[n] ? form.elements[n].value : '');
    const who = v('q1'), goal = v('q2'), level = v('q3');
    const kid = who.includes('7–10'), teen = who.includes('11–15');
    if (kid) return ['подготовка к YCT', 'yct.html'];
    if (goal.startsWith('Сдать')) {
      if (teen) return ['подготовка к YCT', 'yct.html'];
      return level.includes('5–6') ? ['подготовка к HSK и HSKK', 'hsk.html'] : ['подготовка к HSK', 'hsk.html'];
    }
    if (goal.startsWith('Работа')) return ['деловой китайский', 'business.html'];
    if (goal.startsWith('Учёба')) return teen ? ['подготовка к HSK', 'hsk.html'] : ['подготовка к CSCA', 'csca.html'];
    if (teen) return ['общий китайский для детей 11–15 лет', 'kids.html'];
    return ['общий китайский для взрослых', 'adults.html'];
  };

  const show = (n) => {
    step = n;
    steps.forEach((s) => { s.hidden = Number(s.dataset.step) !== n; });
    const q = Math.min(n, 4);
    num.textContent = q;
    bar.style.width = (n >= 5 ? 100 : (n - 1) * 25) + '%';
    head.hidden = n === 6;
    quiz.querySelector('.quiz__bar').hidden = n === 6;
    back.hidden = n === 1 || n === 6;
    next.hidden = n >= 5;
    submit.hidden = n !== 5;
    if (n <= 4) next.disabled = !steps[n - 1].querySelector('input:checked');
    if (n === 5) {
      const [name, url] = recommend();
      const link = quiz.querySelector('[data-quiz-result]');
      link.textContent = name;
      link.href = url;
      if (program) {
        const labels = { q1: 'Для кого', q2: 'Цель', q3: 'Уровень', q4: 'Формат' };
        summary.replaceChildren(...Object.entries(labels).map(([k, label]) => {
          const li = document.createElement('li');
          const b = document.createElement('b');
          b.textContent = label + ': ';
          li.append(b, form.elements[k].value);
          return li;
        }));
        alt.hidden = url === quiz.dataset.programUrl;
      }
      captcha.start();
    }
    status.textContent = '';
  };

  quiz.addEventListener('change', (e) => {
    if (e.target.type !== 'radio') return;
    next.disabled = false;
    // выбор ответа сразу ведёт к следующему вопросу
    setTimeout(() => { if (step <= 4 && e.target.closest('[data-step]').dataset.step == step) show(step + 1); }, 260);
  });
  next.addEventListener('click', () => show(step + 1));
  back.addEventListener('click', () => show(step - 1));

  const phone = form.elements.phone;
  initPhoneMask(phone);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = form.elements.name;
    const okName = name.value.trim().length >= 2;
    const okPhone = phone.value.replace(/\D/g, '').length === 11;
    const okConsent = form.elements.consent.checked;
    name.classList.toggle('is-invalid', !okName);
    phone.classList.toggle('is-invalid', !okPhone);
    form.elements.consent.closest('.lead__consent').classList.toggle('is-invalid', !okConsent);
    if (!okName || !okPhone || !okConsent) {
      status.textContent = !okConsent && okName && okPhone ? 'Подтвердите согласие на обработку данных' : 'Проверьте имя и телефон';
      status.classList.add('is-error');
      return;
    }
    if (!captcha.ok()) {
      status.textContent = 'Отметьте «Я не робот»';
      status.classList.add('is-error');
      return;
    }
    submit.disabled = true;
    status.classList.remove('is-error');
    status.textContent = 'Отправляем…';
    try {
      const data = new FormData(form);
      const rec = recommend()[0];
      data.append('result', !program ? rec : alt.hidden ? program : `${program} (по ответам также подходит: ${rec})`);
      const res = await fetch(form.action, { method: 'POST', body: data });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) throw new Error(json.error || 'send failed');
      show(6);
    } catch (err) {
      status.textContent = err.message === 'captcha' ? 'Отметьте «Я не робот» ещё раз' : 'Не удалось отправить. Позвоните нам: 8 (953) 156-06-85';
      status.classList.add('is-error');
    } finally {
      captcha.reset();
      submit.disabled = false;
    }
  });

  const open = () => {
    if (step === 6) { form.reset(); show(1); }
    quiz.showModal();
    document.body.classList.add('is-modal-open');
  };
  document.querySelectorAll('[data-quiz-open]').forEach((b) => b.addEventListener('click', open));
  quiz.querySelector('[data-quiz-close]').addEventListener('click', () => quiz.close());
  quiz.addEventListener('click', (e) => { if (e.target === quiz) quiz.close(); });
  quiz.addEventListener('close', () => document.body.classList.remove('is-modal-open'));
  show(1);
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
  initQuiz();
  initProgramCards();
  initStory();
  initReveal();
  document.querySelectorAll('[data-year]').forEach((el) => { el.textContent = new Date().getFullYear(); });
  document.querySelectorAll('[role="tablist"]').forEach(initTabs);
  initTabLinks();
  initTabArrows();
  initLessonsSlider();
  initCompareHint();
  document.querySelectorAll('.lead__form').forEach(initLeadForm);
});
