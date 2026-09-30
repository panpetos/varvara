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

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[role="tablist"]').forEach(initTabs);
  initTabLinks();
  const form = document.querySelector('.lead__form');
  if (form) initLeadForm(form);
});
