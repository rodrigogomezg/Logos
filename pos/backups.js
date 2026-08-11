'use strict';

// Avisa si el backup automático nunca corrió, viene fallando, o hace mucho
// que no hay uno exitoso. Mismo patrón que licencia.js: badge en el ícono
// de Configuración, toast una vez por día, banner fijo en Configuración.
(function () {
  const API_ESTADO = (typeof API_BASE !== 'undefined' ? API_BASE : '/Logos/api') + '/backup/estado';

  async function fetchEstado() {
    try {
      const r = await fetch(API_ESTADO);
      if (!r.ok) return null;
      return await r.json();
    } catch { return null; }
  }

  function marcarBadge() {
    const link = document.getElementById('nav-config-link');
    if (!link || link.querySelector('.backup-badge')) return;
    const dot = document.createElement('span');
    dot.className = 'backup-badge';
    dot.style.cssText = 'display:inline-block;width:7px;height:7px;border-radius:50%;' +
      'background:#d4860b;margin-left:5px;vertical-align:middle;flex-shrink:0;';
    link.appendChild(dot);
  }

  function mostrarBanner(estado) {
    // Solo en la pantalla de Configuración (el link de Config tiene clase 'activo')
    const navConfig = document.getElementById('nav-config-link');
    if (!navConfig || !navConfig.classList.contains('activo')) return;
    if (document.getElementById('backup-banner')) return;

    const banner = document.createElement('div');
    banner.id = 'backup-banner';
    banner.style.cssText = [
      'padding:12px 18px',
      'border-radius:6px',
      'margin-bottom:20px',
      'font-size:13px',
      'font-weight:600',
      'line-height:1.5',
      'background:#fff8e6;color:#7d5a00;border:1px solid #f5da8b',
    ].join(';');
    banner.textContent = estado.mensaje || 'El backup automático necesita atención.';

    const contenido = document.querySelector('.contenido');
    if (contenido && contenido.parentNode) {
      contenido.parentNode.insertBefore(banner, contenido);
    }
  }

  function mostrarToast(estado) {
    const key = 'backup_toast_' + new Date().toISOString().slice(0, 10);
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, '1');

    const toast = document.createElement('div');
    toast.style.cssText = [
      'position:fixed',
      'bottom:24px',
      'right:24px',
      'z-index:9999',
      'max-width:380px',
      'padding:14px 18px',
      'border-radius:8px',
      'font-size:13px',
      'line-height:1.5',
      'box-shadow:0 4px 16px rgba(0,0,0,.25)',
      'color:#fff',
      'display:flex',
      'align-items:flex-start',
      'gap:10px',
      'background:#d4860b',
    ].join(';');

    const msg = document.createElement('span');
    msg.style.flex = '1';
    msg.textContent = estado.mensaje || 'El backup automático necesita atención. Entrá a Configuración.';

    const btn = document.createElement('button');
    btn.textContent = '×';
    btn.style.cssText = 'background:none;border:none;color:inherit;font-size:20px;cursor:pointer;' +
      'line-height:1;padding:0;flex-shrink:0;margin-top:-2px;';
    btn.addEventListener('click', () => toast.remove());

    toast.appendChild(msg);
    toast.appendChild(btn);
    document.body.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 9000);
  }

  async function init() {
    const estado = await fetchEstado();
    if (!estado || !estado.alerta) return;

    marcarBadge();
    mostrarBanner(estado);
    mostrarToast(estado);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
