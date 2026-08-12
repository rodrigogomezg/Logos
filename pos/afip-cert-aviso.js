'use strict';

// Avisa cuando el certificado AFIP está por vencer o ya venció. Mismo
// esqueleto que licencia.js (badge en el ícono de Config, banner fijo en la
// pantalla de Configuración, toast una vez por día) — ver ese archivo para
// el patrón original. Los certificados de ARCA duran 2 años; sin este aviso
// el cliente solo se entera el día que una factura falla.
(function () {
  const API_CFG   = (typeof API_BASE !== 'undefined' ? API_BASE : '/Logos/api') + '/configuracion';
  const DIAS_AVISO = 30;

  async function fetchConfig() {
    try {
      const r = await fetch(API_CFG);
      return r.ok ? await r.json() : null;
    } catch { return null; }
  }

  function diasHasta(fechaDDMMYYYY) {
    const [d, m, y] = fechaDDMMYYYY.split('/').map(Number);
    const venc = new Date(y, m - 1, d);
    return Math.ceil((venc - new Date()) / 86400000);
  }

  function marcarBadge() {
    const link = document.getElementById('nav-config-link');
    if (!link || link.querySelector('.afip-badge')) return;
    const dot = document.createElement('span');
    dot.className = 'afip-badge';
    dot.style.cssText = 'display:inline-block;width:7px;height:7px;border-radius:50%;' +
      'background:#f39c12;margin-left:5px;vertical-align:middle;flex-shrink:0;';
    link.appendChild(dot);
  }

  function mostrarBanner(dias, vencido) {
    const navConfig = document.getElementById('nav-config-link');
    if (!navConfig || !navConfig.classList.contains('activo')) return;
    if (document.getElementById('afip-cert-banner')) return;

    const banner = document.createElement('div');
    banner.id = 'afip-cert-banner';
    banner.style.cssText = [
      'padding:12px 18px', 'border-radius:6px', 'margin-bottom:20px', 'font-size:13px',
      'font-weight:600', 'line-height:1.5',
      vencido
        ? 'background:#fde8e8;color:#7b1d1d;border:1px solid #f5c6c6'
        : 'background:#fff8e6;color:#7d5a00;border:1px solid #f5da8b',
    ].join(';');
    banner.textContent = vencido
      ? 'Tu certificado AFIP está vencido. Las facturas electrónicas no van a funcionar hasta que lo renueves.'
      : `Tu certificado AFIP vence en ${dias} día${dias === 1 ? '' : 's'}. Generá uno nuevo desde la tarjeta "Facturación electrónica AFIP".`;

    const contenido = document.querySelector('.contenido');
    if (contenido && contenido.parentNode) {
      contenido.parentNode.insertBefore(banner, contenido);
    }
  }

  function mostrarToast(dias, vencido) {
    const key = 'afip_toast_' + new Date().toISOString().slice(0, 10);
    if (localStorage.getItem(key)) return;
    localStorage.setItem(key, '1');

    const toast = document.createElement('div');
    toast.style.cssText = [
      'position:fixed', 'bottom:24px', 'right:24px', 'z-index:9999', 'max-width:380px',
      'padding:14px 18px', 'border-radius:8px', 'font-size:13px', 'line-height:1.5',
      'box-shadow:0 4px 16px rgba(0,0,0,.25)', 'color:#fff', 'display:flex',
      'align-items:flex-start', 'gap:10px',
      vencido ? 'background:#c0392b' : 'background:#d4860b',
    ].join(';');

    const msg = document.createElement('span');
    msg.style.flex = '1';
    msg.textContent = vencido
      ? 'Certificado AFIP vencido — renovalo en Configuración.'
      : `Certificado AFIP vence en ${dias} días — renovalo en Configuración.`;

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
    const cfg = await fetchConfig();
    if (!cfg || !cfg.afip_configurado || !cfg.afip_cert_vencimiento) return;

    const dias = diasHasta(cfg.afip_cert_vencimiento);
    if (dias > DIAS_AVISO) return;
    const vencido = dias < 0;

    marcarBadge();
    mostrarBanner(dias, vencido);
    mostrarToast(dias, vencido);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
