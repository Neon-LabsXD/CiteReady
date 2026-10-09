(function () {
  'use strict';

  var CAT_ORDER = ['A', 'B', 'C', 'D', 'E'];
  var GRADE_COLOR = { A: '#34d399', B: '#8b82ff', C: '#eab308', D: '#f59e0b', F: '#fb7185' };
  var LOADING_STEPS = ['loading.step1', 'loading.step2', 'loading.step3', 'loading.step4'];

  var lastResult = null;   // останній успішний payload
  var lastError = null;    // останній код помилки
  var loadingTimers = [];

  function t(key) { return window.CiteI18n.t(key); }

  /* DOM helper: h('div', {class:'x'}, child, 'text') */
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'class') el.className = attrs[k];
        else if (k === 'text') el.textContent = attrs[k];
        else if (k === 'html') el.innerHTML = attrs[k];
        else el.setAttribute(k, attrs[k]);
      });
    }
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c == null) continue;
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return el;
  }

  function find(arr, key, val) {
    for (var i = 0; i < arr.length; i++) { if (arr[i] && arr[i][key] === val) return arr[i]; }
    return null;
  }

  function results() { return document.getElementById('results'); }

  function clearLoadingTimers() {
    loadingTimers.forEach(clearTimeout);
    loadingTimers = [];
  }

  /* ---------- Loading ---------- */
  function showLoading() {
    clearLoadingTimers();
    document.body.classList.add('has-results');
    var box = results();
    box.innerHTML = '';
    var line = h('p', { class: 'load-step', id: 'load-step', 'aria-live': 'polite' }, t(LOADING_STEPS[0]));
    box.appendChild(
      h('div', { class: 'card loading-card' },
        h('div', { class: 'spinner', 'aria-hidden': 'true' }),
        line
      )
    );
    var i = 1;
    function next() {
      if (i >= LOADING_STEPS.length) return;
      var step = LOADING_STEPS[i++];
      loadingTimers.push(setTimeout(function () {
        var l = document.getElementById('load-step');
        if (l) l.textContent = t(step);
        next();
      }, 700));
    }
    next();
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ---------- Score ring (SVG) ---------- */
  function scoreRing(score, grade) {
    var r = 52, c = 2 * Math.PI * r;
    var off = c * (1 - Math.max(0, Math.min(100, score)) / 100);
    var color = GRADE_COLOR[grade] || '#8b82ff';
    var svgns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(svgns, 'svg');
    svg.setAttribute('viewBox', '0 0 120 120');
    svg.setAttribute('class', 'score-ring');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', t('res.score') + ': ' + score + '/100, ' + t('res.grade') + ' ' + grade);
    function circle(cls, extra) {
      var el = document.createElementNS(svgns, 'circle');
      el.setAttribute('cx', '60'); el.setAttribute('cy', '60'); el.setAttribute('r', String(r));
      el.setAttribute('class', cls);
      if (extra) Object.keys(extra).forEach(function (k) { el.setAttribute(k, extra[k]); });
      return el;
    }
    svg.appendChild(circle('ring-track'));
    svg.appendChild(circle('ring-val', {
      'stroke': color, 'stroke-dasharray': c.toFixed(1),
      'stroke-dashoffset': off.toFixed(1), 'transform': 'rotate(-90 60 60)'
    }));
    var num = document.createElementNS(svgns, 'text');
    num.setAttribute('x', '60'); num.setAttribute('y', '58'); num.setAttribute('class', 'ring-num');
    num.setAttribute('fill', color); num.textContent = String(score);
    var sub = document.createElementNS(svgns, 'text');
    sub.setAttribute('x', '60'); sub.setAttribute('y', '78'); sub.setAttribute('class', 'ring-sub');
    sub.textContent = '/ 100 · ' + grade;
    svg.appendChild(num); svg.appendChild(sub);
    return svg;
  }

  /* ---------- Category bars ---------- */
  function categoryBars(categories) {
    var wrap = h('div', { class: 'cat-bars' });
    CAT_ORDER.forEach(function (id) {
      var c = find(categories, 'id', id);
      if (!c) return;
      var pct = c.max > 0 ? Math.round((c.score / c.max) * 100) : 0;
      var label = h('div', { class: 'cat-label' },
        h('span', { text: t('cat.' + id) }),
        h('span', { class: 'cat-num', text: c.all_na ? t('status.na') : (c.score + ' / ' + c.max) })
      );
      var bar = h('div', { class: 'cat-track' },
        h('div', { class: 'cat-fill' + (c.all_na ? ' na' : '') })
      );
      bar.firstChild.style.width = (c.all_na ? 0 : pct) + '%';
      wrap.appendChild(h('div', { class: 'cat-row' }, label, bar));
    });
    return wrap;
  }

  /* ---------- "How AI sees your site" ---------- */
  function profileCard(profile) {
    var card = h('div', { class: 'card pad' }, h('h3', { text: t('res.aiView') }));
    if (!profile || !profile.ai_summary) {
      card.appendChild(h('p', { class: 'muted', text: t('res.aiViewEmpty') }));
      return card;
    }
    card.appendChild(h('p', { class: 'ai-summary', text: profile.ai_summary }));
    var meta = h('div', { class: 'profile-meta' });
    if (profile.ai_categories && profile.ai_categories.length) {
      meta.appendChild(metaItem('res.niche', profile.ai_categories.join(', ')));
    }
    if (profile.ai_source) meta.appendChild(metaItem('res.stack', profile.ai_source));
    if (profile.dr != null) meta.appendChild(metaItem('res.dr', String(profile.dr)));
    if (meta.children.length) card.appendChild(meta);
    return card;
  }

  function metaItem(labelKey, value) {
    return h('div', { class: 'meta-item' },
      h('span', { class: 'meta-k', text: t(labelKey) }),
      h('span', { class: 'meta-v', text: value })
    );
  }

  /* ---------- AI bots table ---------- */
  function botsTable(bots) {
    var card = h('div', { class: 'card pad' }, h('h3', { text: t('res.bots') }));
    var table = h('table', { class: 'bots' });
    var thead = h('tr', {},
      h('th', { text: t('bots.bot') }),
      h('th', { text: t('bots.owner') }),
      h('th', { text: t('bots.status') })
    );
    table.appendChild(h('thead', {}, thead));
    var tbody = h('tbody');
    bots.forEach(function (b) {
      var status = h('span', { class: 'badge-status ' + (b.allowed ? 'ok' : 'bad') },
        b.allowed ? t('bots.allowed') : t('bots.blocked'));
      tbody.appendChild(h('tr', {},
        h('td', { text: b.bot }),
        h('td', { text: b.owner }),
        h('td', {}, status)
      ));
    });
    table.appendChild(tbody);
    card.appendChild(table);
    return card;
  }

  /* ---------- Recommendations ---------- */
  function recommendations(checks) {
    var recs = checks.filter(function (c) {
      return (c.status === 'warn' || c.status === 'fail');
    }).map(function (c) {
      var lost = (c.max || 0) - (c.points || 0);
      return { c: c, lost: lost };
    }).sort(function (a, b) { return b.lost - a.lost; });

    var card = h('div', { class: 'card pad recs' }, h('h3', { text: t('res.recommendations') }));
    if (recs.length === 0) {
      card.appendChild(h('p', { class: 'muted', text: t('res.noRecommendations') }));
      return card;
    }
    recs.forEach(function (r) {
      var id = r.c.id;
      var item = h('div', { class: 'rec rec-' + r.c.status });
      item.appendChild(h('div', { class: 'rec-head' },
        h('span', { class: 'rec-dot ' + r.c.status }),
        h('span', { class: 'rec-title', text: t('checks.' + id + '.name') }),
        h('span', { class: 'rec-lost', text: '−' + (Math.round(r.lost * 10) / 10) })
      ));
      item.appendChild(h('p', { class: 'rec-why', text: t('checks.' + id + '.why') }));
      item.appendChild(h('p', { class: 'rec-fix', text: t('checks.' + id + '.fix') }));
      var code = t('checks.' + id + '.code');
      if (code && code !== 'checks.' + id + '.code') {
        item.appendChild(h('pre', { class: 'rec-code' }, h('code', { text: code })));
      }
      card.appendChild(item);
    });
    return card;
  }

  /* ---------- Details ---------- */
  function detailsCard(checks) {
    var det = h('details', { class: 'card pad details' });
    det.appendChild(h('summary', { text: t('res.details') }));
    var list = h('div', { class: 'detail-list' });
    checks.forEach(function (c) {
      list.appendChild(h('div', { class: 'detail-row' },
        h('span', { class: 'detail-dot ' + c.status }),
        h('span', { class: 'detail-name', text: t('checks.' + c.id + '.name') }),
        h('span', { class: 'detail-status ' + c.status, text: t('status.' + c.status) })
      ));
    });
    det.appendChild(list);
    return det;
  }

  /* ---------- Competitors ---------- */
  function competitorsCard(list) {
    if (!list || !list.length) return null;
    var card = h('div', { class: 'card pad' }, h('h3', { text: t('res.competitors') }));
    list.forEach(function (x) {
      var row = h('div', { class: 'comp-row' },
        h('div', { class: 'comp-top' },
          h('span', { class: 'comp-domain', text: x.domain }),
          x.dr != null ? h('span', { class: 'comp-dr', text: 'DR ' + x.dr }) : null
        )
      );
      if (x.summary) row.appendChild(h('p', { class: 'comp-sum', text: x.summary }));
      card.appendChild(row);
    });
    return card;
  }

  /* ---------- Actions ---------- */
  function actions(domain) {
    var bar = h('div', { class: 'actions' });
    var copy = h('button', { type: 'button', class: 'btn ghost', text: t('res.copy') });
    copy.addEventListener('click', function () {
      var url = location.origin + '/?d=' + encodeURIComponent(domain);
      var done = function () { copy.textContent = t('res.copied'); setTimeout(function () { copy.textContent = t('res.copy'); }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done, done);
      } else {
        var ta = document.createElement('textarea'); ta.value = url; document.body.appendChild(ta);
        ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); done();
      }
    });
    var again = h('button', { type: 'button', class: 'btn ghost', text: t('res.another') });
    again.addEventListener('click', function () {
      document.body.classList.remove('has-results');
      results().innerHTML = '';
      lastResult = null; lastError = null;
      history.replaceState(null, '', '/');
      var inp = document.getElementById('domain');
      if (inp) { inp.value = ''; inp.focus(); }
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    var print = h('button', { type: 'button', class: 'btn ghost', text: t('res.print') });
    print.addEventListener('click', function () { window.print(); });
    bar.appendChild(copy); bar.appendChild(again); bar.appendChild(print);
    return bar;
  }

  /* ---------- Render full result ---------- */
  function render(data) {
    clearLoadingTimers();
    lastResult = data; lastError = null;
    document.body.classList.add('has-results');
    var box = results();
    box.innerHTML = '';

    var dateStr = '';
    try { dateStr = new Date(data.checked_at).toLocaleString(window.CiteI18n.lang()); } catch (e) { dateStr = data.checked_at; }

    // header card
    var head = h('div', { class: 'card pad result-head' });
    head.appendChild(scoreRing(data.score, data.grade));
    var info = h('div', { class: 'result-info' },
      h('div', { class: 'result-domain', text: data.domain },),
      h('div', { class: 'result-verdict', text: t('verdict.' + data.grade) }),
      h('div', { class: 'result-date muted', text: t('res.checkedAt') + ' ' + dateStr })
    );
    if (data.cached) info.appendChild(h('span', { class: 'cached-tag', text: t('res.cached') }));
    head.appendChild(info);
    box.appendChild(head);

    // category bars
    box.appendChild(h('div', { class: 'card pad' },
      h('h3', { text: t('res.categories') }),
      categoryBars(data.categories)
    ));

    box.appendChild(profileCard(data.profile));
    box.appendChild(botsTable(data.ai_bots));
    box.appendChild(recommendations(data.checks));
    box.appendChild(detailsCard(data.checks));
    var comp = competitorsCard(data.competitors);
    if (comp) box.appendChild(comp);
    box.appendChild(actions(data.domain));

    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderError(code) {
    clearLoadingTimers();
    lastError = code; lastResult = null;
    document.body.classList.add('has-results');
    var box = results();
    box.innerHTML = '';
    var key = 'error.' + code;
    var msg = t(key);
    if (msg === key) msg = t('error.internal');
    box.appendChild(h('div', { class: 'card pad error-card' },
      h('div', { class: 'error-icon', text: '!' }),
      h('p', { text: msg }),
      (function () {
        var b = h('button', { type: 'button', class: 'btn ghost', text: t('res.another') });
        b.addEventListener('click', function () {
          document.body.classList.remove('has-results');
          box.innerHTML = ''; lastError = null;
          var inp = document.getElementById('domain'); if (inp) inp.focus();
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        return b;
      })()
    ));
  }

  /* ---------- Run check ---------- */
  function runCheck(domain) {
    if (!domain) return;
    showLoading();
    history.replaceState(null, '', '/?d=' + encodeURIComponent(domain));
    fetch('/api/check.php?domain=' + encodeURIComponent(domain), { headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, body: j }; }, function () { return { ok: false, body: null }; });
      })
      .then(function (res) {
        if (res.body && res.body.ok) render(res.body);
        else renderError(res.body && res.body.error ? res.body.error : 'internal');
      })
      .catch(function () { renderError('network'); });
  }

  /* ---------- Wiring ---------- */
  function wireForm() {
    var form = document.getElementById('check-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var inp = document.getElementById('domain');
      var v = inp ? inp.value.trim() : '';
      if (v === '') { if (inp) inp.focus(); return; }
      runCheck(v);
    });
  }

  function wireTags() {
    document.querySelectorAll('[data-example]').forEach(function (tag) {
      tag.addEventListener('click', function () {
        var inp = document.getElementById('domain');
        var v = tag.getAttribute('data-example');
        if (inp) inp.value = v;
        runCheck(v);
      });
    });
  }

  function onLangChange() {
    if (lastResult) render(lastResult);
    else if (lastError) renderError(lastError);
  }

  function maybeAutoRun() {
    var m = /[?&]d=([^&]+)/.exec(location.search);
    if (!m) return;
    var domain = decodeURIComponent(m[1]);
    var inp = document.getElementById('domain');
    if (inp) inp.value = domain;
    runCheck(domain);
  }

  document.addEventListener('DOMContentLoaded', function () {
    window.CiteI18n.init().then(function () {
      wireForm();
      wireTags();
      document.addEventListener('langchange', onLangChange);
      maybeAutoRun();
    });
  });
})();
