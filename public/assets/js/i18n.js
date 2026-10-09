(function () {
  'use strict';

  var SUPPORTED = ['en', 'uk'];
  var dict = {};
  var current = 'en';

  function storedLang() {
    try {
      return localStorage.getItem('cr_lang');
    } catch (e) {
      return null;
    }
  }

  function saveLang(lang) {
    try {
      localStorage.setItem('cr_lang', lang);
    } catch (e) { /* storage may be blocked */ }
  }

  function detectLang() {
    var saved = storedLang();
    if (SUPPORTED.indexOf(saved) !== -1) return saved;
    var nav = (navigator.language || 'en').toLowerCase();
    return (nav.indexOf('uk') === 0 || nav.indexOf('ru') === 0) ? 'uk' : 'en';
  }

  function lookup(key) {
    return key.split('.').reduce(function (o, k) {
      return o && o[k] !== undefined ? o[k] : undefined;
    }, dict);
  }

  function t(key) {
    var v = lookup(key);
    return typeof v === 'string' ? v : key;
  }

  function apply() {
    document.documentElement.lang = current;
    var titleKey = document.documentElement.getAttribute('data-title-key') || 'meta.title';
    var descKey = document.documentElement.getAttribute('data-desc-key') || 'meta.description';
    document.title = t(titleKey);
    var md = document.querySelector('meta[name="description"]');
    if (md) md.setAttribute('content', t(descKey));

    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      el.textContent = t(el.getAttribute('data-i18n'));
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
      el.setAttribute('placeholder', t(el.getAttribute('data-i18n-placeholder')));
    });
    document.querySelectorAll('[data-lang]').forEach(function (btn) {
      var active = btn.getAttribute('data-lang') === current;
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    document.dispatchEvent(new CustomEvent('langchange', { detail: { lang: current } }));
  }

  function setLang(lang) {
    if (SUPPORTED.indexOf(lang) === -1) return Promise.resolve();
    return fetch('/i18n/' + lang + '.json')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        dict = data;
        current = lang;
        saveLang(lang);
        apply();
      });
  }

  window.CiteI18n = {
    t: t,
    setLang: setLang,
    lang: function () { return current; },
    init: function () {
      document.querySelectorAll('[data-lang]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          setLang(btn.getAttribute('data-lang'));
        });
      });
      return setLang(detectLang());
    }
  };
})();
