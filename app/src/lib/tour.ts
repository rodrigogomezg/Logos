// tour.ts — motor de tour guiado tipo demo-conducido. Port de pos/tour.js
// para la SPA de SvelteKit.
//
// Diferencia clave frente al original: pos/tour.js se enganchaba a
// DOMContentLoaded, que se dispara una vez por página en una app de
// archivos sueltos (MPA). En la SPA, navegar entre rutas NO dispara ese
// evento — solo pasaría una vez, al abrir la app. Por eso acá cada página
// llama a `setTourSteps(pageKey, steps)` en su propio onMount, y el layout
// llama a `dismissTour()` en afterNavigate para no dejar el overlay de una
// pantalla vieja colgado al cambiar de ruta.

import { writable } from 'svelte/store';

export type TourHelpers = {
	delay: (ms: number) => Promise<void>;
	waitFor: (sel: string, timeout?: number) => Promise<Element>;
};

export type TourStep = {
	el: string | null;
	title: string;
	body: string;
	onEnter?: (helpers: TourHelpers) => Promise<void>;
	pad?: number;
};

/** true si la página actual tiene pasos definidos — controla si se ve el botón "?" en el nav. */
export const tourDisponible = writable(false);

const delay = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));
const waitFor = (sel: string, timeout = 4000) =>
	new Promise<Element>((res, rej) => {
		const t0 = Date.now();
		(function poll() {
			const el = document.querySelector(sel);
			if (el && (el as HTMLElement).offsetParent !== null) return res(el);
			if (Date.now() - t0 > timeout) return rej(new Error('tour waitFor timeout: ' + sel));
			setTimeout(poll, 80);
		})();
	});

let steps: TourStep[] = [];
let pageKey = '';
let current = 0;
let running = false;
let busy = false;

const storKey = () => 'tour_seen_' + (pageKey || 'unknown');

let elOverlay: HTMLDivElement;
let elHighlight: HTMLDivElement;
let elTooltip: HTMLDivElement;
let elStep: HTMLElement;
let elTitle: HTMLElement;
let elBody: HTMLElement;
let elPrev: HTMLButtonElement;
let elNext: HTMLButtonElement;
let elClose: HTMLButtonElement;
let domReady = false;
let cssReady = false;
let listenersHooked = false;

function injectCSS() {
	if (cssReady) return;
	cssReady = true;
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
.tt-next { background:var(--color-primary,#163B66); color:#fff; margin-left:auto; }
.tt-next:hover { background:var(--color-primary-dark,#0F2A4A); }
  `;
	document.head.appendChild(s);
}

function injectDOM() {
	if (domReady) return;
	domReady = true;
	injectCSS();

	elOverlay = document.createElement('div');
	elOverlay.id = 'tour-overlay';
	document.body.appendChild(elOverlay);

	elHighlight = document.createElement('div');
	elHighlight.id = 'tour-highlight';
	document.body.appendChild(elHighlight);

	elTooltip = document.createElement('div');
	elTooltip.id = 'tour-tooltip';
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

	elStep = document.getElementById('tt-step')!;
	elTitle = document.getElementById('tt-title')!;
	elBody = document.getElementById('tt-body')!;
	elPrev = document.getElementById('tt-prev') as HTMLButtonElement;
	elNext = document.getElementById('tt-next') as HTMLButtonElement;
	elClose = document.getElementById('tt-close') as HTMLButtonElement;

	elPrev.addEventListener('click', () => {
		if (!busy) prevStep();
	});
	elNext.addEventListener('click', () => {
		if (!busy) nextStep();
	});
	elClose.addEventListener('click', () => endTour());
	elOverlay.addEventListener('click', () => endTour());
}

function positionHighlight(rect: DOMRect, pad: number) {
	elHighlight.style.top = rect.top - pad + 'px';
	elHighlight.style.left = rect.left - pad + 'px';
	elHighlight.style.width = rect.width + pad * 2 + 'px';
	elHighlight.style.height = rect.height + pad * 2 + 'px';
}

function positionTooltip(highlightRect: { top: number; left: number; right: number; bottom: number } | null) {
	const TW = 300;
	const TH = elTooltip.offsetHeight || 220;
	const VP_W = window.innerWidth;
	const VP_H = window.innerHeight;
	const GAP = 14;

	let top: number, left: number;

	if (highlightRect && highlightRect.right + GAP + TW < VP_W) {
		left = highlightRect.right + GAP;
		top = Math.max(10, Math.min(highlightRect.top, VP_H - TH - 10));
	} else if (highlightRect && highlightRect.left - GAP - TW > 0) {
		left = highlightRect.left - GAP - TW;
		top = Math.max(10, Math.min(highlightRect.top, VP_H - TH - 10));
	} else if (highlightRect && highlightRect.bottom + GAP + TH < VP_H) {
		top = highlightRect.bottom + GAP;
		left = Math.max(10, Math.min(highlightRect.left, VP_W - TW - 10));
	} else {
		top = Math.max(10, (VP_H - TH) / 2);
		left = Math.max(10, (VP_W - TW) / 2);
	}

	elTooltip.style.top = top + 'px';
	elTooltip.style.left = left + 'px';
}

async function show(skipOnEnter = false) {
	if (!running) return;
	const step = steps[current];
	busy = true;

	if (!skipOnEnter && step.onEnter) {
		try {
			await step.onEnter({ delay, waitFor });
		} catch (e) {
			console.warn('[tour] onEnter error:', e);
		}
	}

	if (!running) {
		busy = false;
		return;
	}

	elStep.textContent = 'Paso ' + (current + 1) + ' de ' + steps.length;
	elTitle.textContent = step.title;
	elBody.innerHTML = step.body;

	elPrev.disabled = current === 0;
	elNext.textContent = current === steps.length - 1 ? 'Finalizar ✓' : 'Siguiente →';

	elOverlay.style.display = 'block';
	elTooltip.style.display = 'block';

	let highlightRect: { top: number; left: number; right: number; bottom: number } | null = null;

	if (step.el) {
		const target = document.querySelector(step.el) as HTMLElement | null;
		if (target && target.offsetParent !== null) {
			const pad = step.pad !== undefined ? step.pad : 6;
			const rect = target.getBoundingClientRect();
			highlightRect = {
				top: rect.top - pad,
				left: rect.left - pad,
				right: rect.right + pad,
				bottom: rect.bottom + pad
			};
			positionHighlight(rect, pad);
			elHighlight.style.display = 'block';
		} else {
			elHighlight.style.display = 'none';
		}
	} else {
		elHighlight.style.display = 'none';
	}

	requestAnimationFrame(() => positionTooltip(highlightRect));

	busy = false;
}

async function nextStep() {
	if (current < steps.length - 1) {
		current++;
		await show();
	} else {
		endTour();
	}
}
async function prevStep() {
	if (current > 0) {
		current--;
		await show();
	}
}

export function startTour() {
	if (!steps.length) return;
	injectDOM();
	running = true;
	current = 0;
	show();
}

/** Cierra el tour y lo marca como visto — click en X, Escape, click afuera, o llegar al final. */
export function endTour() {
	running = false;
	busy = false;
	hideUI();
	localStorage.setItem(storKey(), '1');
}

/** Solo esconde el overlay, sin marcar como visto — usado al navegar a otra pantalla a mitad de tour. */
export function dismissTour() {
	if (running) {
		running = false;
		busy = false;
		hideUI();
	}
	// Reset por-navegación: si la pantalla nueva no llama a setTourSteps en
	// su propio onMount (porque no tiene tour), esto evita que el botón "?"
	// y los pasos de la pantalla anterior queden pegados. Si la pantalla
	// nueva SÍ tiene tour, su onMount corre después y lo vuelve a poblar.
	pageKey = '';
	steps = [];
	current = 0;
	tourDisponible.set(false);
}

function hideUI() {
	if (elOverlay) elOverlay.style.display = 'none';
	if (elHighlight) elHighlight.style.display = 'none';
	if (elTooltip) elTooltip.style.display = 'none';
}

function hookGlobalListeners() {
	if (listenersHooked) return;
	listenersHooked = true;

	let resizeTimer: ReturnType<typeof setTimeout>;
	window.addEventListener('resize', () => {
		if (!running) return;
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(() => show(true), 120);
	});

	document.addEventListener('keydown', (e) => {
		if (!running) return;
		if (e.key === 'Escape') endTour();
	});
}

/**
 * Cada página llama esto en su propio onMount con sus pasos. Si el usuario
 * nunca vio el tour de esta página (localStorage), arranca solo a los 700ms
 * — mismo comportamiento que pos/tour.js.
 */
export function setTourSteps(key: string, newSteps: TourStep[]) {
	dismissTour();
	pageKey = key;
	steps = newSteps;
	current = 0;
	tourDisponible.set(newSteps.length > 0);
	hookGlobalListeners();

	if (newSteps.length && !localStorage.getItem(storKey())) {
		setTimeout(() => startTour(), 700);
	}
}
