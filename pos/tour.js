/* tour.js — Motor de tour guiado tipo demo-conducido.
 * Cada página define window.TOUR_PAGE_KEY (string) y window.TOUR_STEPS (array).
 * Cada step: { el, title, body, onEnter, pad }
 *   el       — selector CSS del elemento a resaltar (null = intro centrado)
 *   title    — título del tooltip
 *   body     — texto del tooltip
 *   onEnter  — async ({ delay, waitFor }) => void  — acción del sistema antes de mostrar
 *   pad      — padding alrededor del highlight (default 6px)
 */
(function () {
  'use strict';

  /* ── helpers ─────────────────────────────────────────────── */
  const delay   = ms  => new Promise(r => setTimeout(r, ms));
  const waitFor = (sel, timeout = 4000) => new Promise((res, rej) => {
    const t0 = Date.now();
    (function poll() {
      const el = document.querySelector(sel);
      if (el && el.offsetParent !== null) return res(el);   // visible
      if (Date.now() - t0 > timeout) return rej(new Error('tour waitFor timeout: ' + sel));
      setTimeout(poll, 80);
    })();
  });

  /* ── estado ──────────────────────────────────────────────── */
  let steps   = [];
  let current = 0;
  let running = false;
  let busy    = false;    // evita double-click en Next mientras hay onEnter en curso

  const storKey = () => 'tour_seen_' + (window.TOUR_PAGE_KEY || 'unknown');

  /* ── CSS ─────────────────────────────────────────────────── */
  function injectCSS() {
    if (document.getElementById('tour-style')) return;
    const s = document.createElement('style');
    s.id = 'tour-style';
    s.textContent = `
#tour-overlay {
  position:fixed; inset:0; z-index:9000; pointer-events:all; background:transparent;
  display:none;
}
#tour-highlight {
  position:fixed; z-index:9001; pointer-events:none;
  box-shadow: 0 0 0 9999px rgba(0,0,0,.58);
  border-radius:6px;
  transition: top .22s ease, left .22s ease, width .22s ease, height .22s ease;
  display:none;
}
#tour-tooltip {
  position:fixed; z-index:9002; background:#fff; border-radius:10px;
  box-shadow:0 8px 36px rgba(0,0,0,.28); width:300px; font-family:inherit;
  overflow:hidden; display:none;
}
.tt-head {
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 14px 10px; border-bottom:1px solid #e8e8e8;
  background:#f8f8f6;
}
.tt-step { font-size:11px; font-weight:600; color:#888; letter-spacing:.04em; text-transform:uppercase; }
.tt-close {
  background:none; border:none; cursor:pointer; color:#aaa; font-size:18px;
  line-height:1; padding:0 2px; border-radius:4px;
}
.tt-close:hover { background:#eee; color:#333; }
.tt-title { font-size:15px; font-weight:700; color:#1a1a1a; padding:13px 14px 4px; }
.tt-body  { font-size:13px; color:#444; line-height:1.55; padding:0 14px 14px; }
.tt-footer {
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 14px; border-top:1px solid #efefef; background:#f8f8f6; gap:8px;
}
.tt-prev, .tt-next {
  border:none; border-radius:6px; cursor:pointer;
  font-size:13px; font-weight:600; padding:7px 14px; font-family:inherit;
  transition: background .15s;
}
.tt-prev { background:#eee; color:#555; }
.tt-prev:hover { background:#ddd; }
.tt-prev:disabled { opacity:.35; cursor:default; }
.tt-next { background:#1a4a8a; color:#fff; margin-left:auto; }
.tt-next:hover { background:#153a6e; }
.nav-tour-btn {
  background:none; border:1px solid rgba(163,177,198,.4); border-radius:50%;
  width:26px; height:26px; display:flex; align-items:center; justify-content:center;
  cursor:pointer; font-size:13px; font-weight:700; color:var(--neo-text-2,#666);
  flex-shrink:0; transition: border-color .15s, color .15s;
}
.nav-tour-btn:hover { border-color:var(--neo-accent,#4f8ef7); color:var(--neo-accent,#4f8ef7); }
    `;
    document.head.appendChild(s);
  }

  /* ── DOM ─────────────────────────────────────────────────── */
  let elOverlay, elHighlight, elTooltip, elStep, elTitle, elBody, elPrev, elNext, elClose;

  function injectDOM() {
    if (document.getElementById('tour-overlay')) return;

    elOverlay = document.createElement('div'); elOverlay.id = 'tour-overlay';
    document.body.appendChild(elOverlay);

    elHighlight = document.createElement('div'); elHighlight.id = 'tour-highlight';
    document.body.appendChild(elHighlight);

    elTooltip = document.createElement('div'); elTooltip.id = 'tour-tooltip';
    elTooltip.innerHTML = `
      <div class="tt-head">
        <span class="tt-step" id="tt-step"></span>
        <button class="tt-close" id="tt-close" aria-label="Cerrar tour">✕</button>
      </div>
      <div class="tt-title" id="tt-title"></div>
      <div class="tt-body"  id="tt-body"></div>
      <div class="tt-footer">
        <button class="tt-prev" id="tt-prev">← Anterior</button>
        <button class="tt-next" id="tt-next">Siguiente →</button>
      </div>`;
    document.body.appendChild(elTooltip);

    elStep  = document.getElementById('tt-step');
    elTitle = document.getElementById('tt-title');
    elBody  = document.getElementById('tt-body');
    elPrev  = document.getElementById('tt-prev');
    elNext  = document.getElementById('tt-next');
    elClose = document.getElementById('tt-close');

    elPrev.addEventListener('click',  () => { if (!busy) prevStep(); });
    elNext.addEventListener('click',  () => { if (!busy) nextStep(); });
    elClose.addEventListener('click', () => end());
    elOverlay.addEventListener('click', () => end());
  }

  /* ── posicionamiento ─────────────────────────────────────── */
  function positionHighlight(rect, pad) {
    elHighlight.style.top    = (rect.top    - pad) + 'px';
    elHighlight.style.left   = (rect.left   - pad) + 'px';
    elHighlight.style.width  = (rect.width  + pad*2) + 'px';
    elHighlight.style.height = (rect.height + pad*2) + 'px';
  }

  function positionTooltip(highlightRect) {
    const TW = 300, TH = elTooltip.offsetHeight || 220;
    const VP_W = window.innerWidth, VP_H = window.innerHeight;
    const GAP = 14;

    let top, left;

    // intentar derecha
    if (highlightRect && highlightRect.right + GAP + TW < VP_W) {
      left = highlightRect.right + GAP;
      top  = Math.max(10, Math.min(highlightRect.top, VP_H - TH - 10));
    }
    // intentar izquierda
    else if (highlightRect && highlightRect.left - GAP - TW > 0) {
      left = highlightRect.left - GAP - TW;
      top  = Math.max(10, Math.min(highlightRect.top, VP_H - TH - 10));
    }
    // intentar abajo
    else if (highlightRect && highlightRect.bottom + GAP + TH < VP_H) {
      top  = highlightRect.bottom + GAP;
      left = Math.max(10, Math.min(highlightRect.left, VP_W - TW - 10));
    }
    // centrado
    else {
      top  = Math.max(10, (VP_H - TH) / 2);
      left = Math.max(10, (VP_W - TW) / 2);
    }

    elTooltip.style.top  = top  + 'px';
    elTooltip.style.left = left + 'px';
  }

  /* ── show ────────────────────────────────────────────────── */
  async function show(skipOnEnter) {
    if (!running) return;
    const step = steps[current];
    busy = true;

    // Ejecutar acción del sistema (solo si no viene de resize)
    if (!skipOnEnter && step.onEnter) {
      try { await step.onEnter({ delay, waitFor }); }
      catch (e) { console.warn('[tour] onEnter error:', e); }
    }

    if (!running) { busy = false; return; }

    // Actualizar contenido
    const _off = window.TOUR_STEP_OFFSET || 0;
    const _tot = window.TOUR_TOTAL_STEPS  || steps.length;
    elStep.textContent  = 'Paso ' + (current + _off + 1) + ' de ' + _tot;
    elTitle.textContent = step.title;
    elBody.innerHTML    = step.body;

    // Prev/Next state
    elPrev.disabled = (current === 0);
    elNext.textContent = (current === steps.length - 1) ? 'Finalizar ✓' : 'Siguiente →';

    // Mostrar elementos
    elOverlay.style.display   = 'block';
    elTooltip.style.display   = 'block';

    let highlightRect = null;

    if (step.el) {
      const target = document.querySelector(step.el);
      if (target && target.offsetParent !== null) {
        const pad  = step.pad !== undefined ? step.pad : 6;
        const rect = target.getBoundingClientRect();
        highlightRect = {
          top:    rect.top    - pad,
          left:   rect.left   - pad,
          width:  rect.width  + pad * 2,
          height: rect.height + pad * 2,
          right:  rect.right  + pad,
          bottom: rect.bottom + pad,
        };
        positionHighlight(rect, pad);
        elHighlight.style.display = 'block';
      } else {
        // Elemento no encontrado o no visible → sin highlight
        elHighlight.style.display = 'none';
      }
    } else {
      elHighlight.style.display = 'none';
    }

    // Posicionar tooltip después de que sea visible (para medir altura)
    requestAnimationFrame(() => positionTooltip(highlightRect));

    busy = false;
  }

  /* ── navegación ──────────────────────────────────────────── */
  async function nextStep() {
    if (current < steps.length - 1) { current++; await show(); }
    else end();
  }
  async function prevStep() {
    if (current > 0) { current--; await show(); }
  }

  /* ── start / end ─────────────────────────────────────────── */
  function start() {
    running = true;
    current = 0;
    show();
  }

  function end() {
    running = false;
    busy    = false;
    if (elOverlay)   elOverlay.style.display   = 'none';
    if (elHighlight) elHighlight.style.display = 'none';
    if (elTooltip)   elTooltip.style.display   = 'none';
    localStorage.setItem(storKey(), '1');
  }

  /* ── init ────────────────────────────────────────────────── */
  function init() {
    steps = window.TOUR_STEPS || [];
    const navBtn = document.getElementById('nav-tour-btn');

    if (!steps.length) {
      if (navBtn) navBtn.style.display = 'none';
      return;
    }

    injectCSS();
    injectDOM();

    if (navBtn) navBtn.addEventListener('click', start);

    document.addEventListener('keydown', e => {
      if (!running) return;
      if (e.key === 'Escape') end();
    });

    // Re-posicionar en resize sin re-ejecutar onEnter
    let resizeTimer;
    window.addEventListener('resize', () => {
      if (!running) return;
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => show(true), 120);
    });

    // Auto-arrancar en primera visita
    if (!localStorage.getItem(storKey())) {
      setTimeout(start, 700);
    }
  }

  document.addEventListener('DOMContentLoaded', init);

  // API pública para debug
  window.__tour = { start, end, next: nextStep, prev: prevStep };
})();
