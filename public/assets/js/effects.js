(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Particle network behind the hero (vanilla canvas, reacts to cursor) ---- */
  function initParticles() {
    var canvas = document.getElementById('hero-canvas');
    if (!canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var w = 0, h = 0, particles = [], mouse = { x: -9999, y: -9999 };
    var COUNT = 0, MAXD = 130, raf = null;

    function resize() {
      var r = canvas.getBoundingClientRect();
      w = r.width; h = r.height;
      canvas.width = Math.round(w * dpr);
      canvas.height = Math.round(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      COUNT = Math.round(Math.min(85, Math.max(26, (w * h) / 15000)));
      MAXD = Math.min(150, Math.max(104, w / 10));
      build();
    }

    function build() {
      particles = [];
      for (var i = 0; i < COUNT; i++) {
        particles.push({
          x: Math.random() * w, y: Math.random() * h,
          vx: (Math.random() - 0.5) * 0.34, vy: (Math.random() - 0.5) * 0.34
        });
      }
    }

    function frame() {
      ctx.clearRect(0, 0, w, h);
      var i, p;
      for (i = 0; i < particles.length; i++) {
        p = particles[i];
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > w) p.vx *= -1;
        if (p.y < 0 || p.y > h) p.vy *= -1;
        var dxm = p.x - mouse.x, dym = p.y - mouse.y, dm = Math.hypot(dxm, dym);
        if (dm < 130 && dm > 0.01) { p.x += (dxm / dm) * 0.5; p.y += (dym / dm) * 0.5; }
      }
      for (var a = 0; a < particles.length; a++) {
        for (var b = a + 1; b < particles.length; b++) {
          var dx = particles[a].x - particles[b].x, dy = particles[a].y - particles[b].y;
          var d = Math.hypot(dx, dy);
          if (d < MAXD) {
            ctx.strokeStyle = 'rgba(139,130,255,' + ((1 - d / MAXD) * 0.45).toFixed(3) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(particles[a].x, particles[a].y);
            ctx.lineTo(particles[b].x, particles[b].y); ctx.stroke();
          }
        }
        var dx2 = particles[a].x - mouse.x, dy2 = particles[a].y - mouse.y, d2 = Math.hypot(dx2, dy2);
        if (d2 < 165) {
          ctx.strokeStyle = 'rgba(34,211,238,' + ((1 - d2 / 165) * 0.55).toFixed(3) + ')';
          ctx.beginPath(); ctx.moveTo(particles[a].x, particles[a].y);
          ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
        }
      }
      ctx.fillStyle = 'rgba(199,196,255,0.9)';
      for (i = 0; i < particles.length; i++) {
        ctx.beginPath(); ctx.arc(particles[i].x, particles[i].y, 1.6, 0, 6.2832); ctx.fill();
      }
      raf = requestAnimationFrame(frame);
    }

    window.addEventListener('mousemove', function (e) {
      var r = canvas.getBoundingClientRect();
      mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top;
    });
    window.addEventListener('mouseout', function () { mouse.x = -9999; mouse.y = -9999; });
    window.addEventListener('resize', function () {
      if (raf) cancelAnimationFrame(raf);
      resize();
      if (!reduce) raf = requestAnimationFrame(frame);
      else drawStatic();
    });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { if (raf) cancelAnimationFrame(raf); }
      else if (!reduce) raf = requestAnimationFrame(frame);
    });

    function drawStatic() {
      ctx.clearRect(0, 0, w, h);
      for (var a = 0; a < particles.length; a++) {
        for (var b = a + 1; b < particles.length; b++) {
          var dx = particles[a].x - particles[b].x, dy = particles[a].y - particles[b].y;
          var d = Math.hypot(dx, dy);
          if (d < MAXD) {
            ctx.strokeStyle = 'rgba(139,130,255,' + ((1 - d / MAXD) * 0.4).toFixed(3) + ')';
            ctx.beginPath(); ctx.moveTo(particles[a].x, particles[a].y);
            ctx.lineTo(particles[b].x, particles[b].y); ctx.stroke();
          }
        }
      }
      ctx.fillStyle = 'rgba(199,196,255,0.85)';
      for (var i = 0; i < particles.length; i++) {
        ctx.beginPath(); ctx.arc(particles[i].x, particles[i].y, 1.6, 0, 6.2832); ctx.fill();
      }
    }

    resize();
    if (reduce) drawStatic();
    else raf = requestAnimationFrame(frame);
  }

  /* ---- Count-up (easing), optionally driving a conic ring ---- */
  function countUp(el, to, dur, suffix, ring) {
    suffix = suffix || '';
    if (reduce) {
      el.textContent = to + suffix;
      if (ring) ring.style.setProperty('--p', to);
      return;
    }
    var start = null;
    function f(ts) {
      if (start === null) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      var v = Math.round(eased * to);
      el.textContent = v + suffix;
      if (ring) ring.style.setProperty('--p', v);
      if (p < 1) requestAnimationFrame(f);
    }
    requestAnimationFrame(f);
  }

  function initCounters() {
    var score = document.getElementById('sample-score');
    var ring = document.getElementById('sample-ring');
    var started = false;
    function run() {
      if (started) return;
      started = true;
      if (score) countUp(score, 84, 1500, '', ring);
      document.querySelectorAll('[data-count]').forEach(function (el) {
        countUp(el, parseInt(el.getAttribute('data-count'), 10), 1300, el.getAttribute('data-suffix') || '');
      });
    }
    var target = document.getElementById('sample-card');
    if (!target || !('IntersectionObserver' in window)) { run(); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { run(); io.disconnect(); } });
    }, { threshold: 0.35 });
    io.observe(target);
  }

  /* ---- 3D tilt on cards ---- */
  function initTilt() {
    if (reduce) return;
    document.querySelectorAll('[data-tilt]').forEach(function (card) {
      card.addEventListener('pointermove', function (e) {
        var r = card.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width - 0.5;
        var py = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = 'perspective(850px) rotateX(' + (-py * 5).toFixed(2) +
          'deg) rotateY(' + (px * 7).toFixed(2) + 'deg) translateY(-2px)';
      });
      card.addEventListener('pointerleave', function () { card.style.transform = ''; });
    });
  }

  /* ---- Reveal on load (staggered) ---- */
  function initReveal() {
    var els = document.querySelectorAll('.reveal');
    if (reduce) { els.forEach(function (e) { e.classList.add('in'); }); return; }
    els.forEach(function (e, i) {
      setTimeout(function () { e.classList.add('in'); }, 90 * i + 60);
    });
    window.addEventListener('load', function () {
      els.forEach(function (e) { e.classList.add('in'); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initParticles();
    initCounters();
    initTilt();
    initReveal();
  });
})();
