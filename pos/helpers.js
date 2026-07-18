/**
 * Funciones globales helper para todos los scripts
 * Se carga en config.js o inmediatamente después
 */

function toast_(mensaje, tipo = 'info') {
  const tipos = {
    'ok': { bg: '#4ade80', color: '#15803d' },
    'err': { bg: '#ef4444', color: '#7f1d1d' },
    'info': { bg: '#3b82f6', color: '#1e3a8a' },
    'warn': { bg: '#f59e0b', color: '#92400e' },
  };

  const cfg = tipos[tipo] || tipos.info;
  
  const toast = document.createElement('div');
  toast.style.cssText = `
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: ${cfg.bg};
    color: ${cfg.color};
    padding: 12px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    z-index: 9999;
    max-width: 400px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideIn 0.3s ease-out;
  `;
  toast.textContent = mensaje;
  
  document.body.appendChild(toast);
  
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s ease-out';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

function validarEmail(email) {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return re.test(email);
}

function validarFecha(fecha) {
  return /^\d{4}-\d{2}-\d{2}$/.test(fecha) && !isNaN(Date.parse(fecha));
}

// Cierra un overlay solo cuando el click completo (mousedown + mouseup) ocurrió
// sobre el propio backdrop, no cuando terminó ahí arrastrado desde adentro del modal.
function overlayAutoClose(overlayEl, closeFn) {
  let downOnBackdrop = false;
  overlayEl.addEventListener('mousedown', e => { downOnBackdrop = e.target === overlayEl; });
  overlayEl.addEventListener('click',     e => { if (e.target === overlayEl && downOnBackdrop) closeFn(); });
}

function escapeHtml(texto) {
  const div = document.createElement('div');
  div.textContent = texto;
  return div.innerHTML;
}

async function apiCall(endpoint, opciones = {}) {
  if (!window.API) {
    throw new Error('API base no configurada. Verifica config.js');
  }

  const url = window.API + endpoint;
  const opts = {
    ...opciones,
    headers: {
      ...opciones.headers,
      'Content-Type': 'application/json',
    },
  };

  try {
    const resp = await fetch(url, opts);
    let data;
    try { data = await resp.json(); } catch (_) { data = {}; }

    if (!resp.ok) {
      throw new Error(data.error || `HTTP ${resp.status}`);
    }

    return data;
  } catch (e) {
    console.error(`[API Error] ${endpoint}:`, e.message);
    throw e;
  }
}

async function apiFetch(url, opts = {}) {
  opts = { ...opts, headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) } };
  try {
    const resp = await fetch(url, opts);
    let data;
    try { data = await resp.json(); } catch (_) { data = {}; }
    if (!resp.ok) throw new Error(data.error || `HTTP ${resp.status}`);
    return data;
  } catch (e) {
    console.error('[apiFetch]', url, e.message);
    throw e;
  }
}

// Visor PDF interno — reemplaza window.open(url, '_blank') para PDFs
function abrirPdfModal(blob) {
  if (!document.getElementById('pdf-modal-css')) {
    const s = document.createElement('style');
    s.id = 'pdf-modal-css';
    s.textContent = `
#pdf-modal{position:fixed;inset:0;z-index:9000;background:#404040;display:flex;flex-direction:column}
#pdf-modal-bar{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:8px 14px;background:#1e1e1e;flex-shrink:0}
#pdf-modal-close{background:none;border:1.5px solid rgba(255,255,255,.3);color:#fff;padding:6px 18px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;font-family:inherit;transition:background .15s}
#pdf-modal-close:hover{background:rgba(255,255,255,.1)}
#pdf-modal-frame{flex:1;border:none;width:100%;height:100%}
`;
    document.head.appendChild(s);
  }
  const url = URL.createObjectURL(blob);
  const el  = document.createElement('div');
  el.id = 'pdf-modal';
  el.innerHTML = `<div id="pdf-modal-bar"><button id="pdf-modal-close">✕&nbsp;&nbsp;Cerrar</button></div><iframe id="pdf-modal-frame" src="${url}"></iframe>`;
  document.body.appendChild(el);
  const cerrar = () => { el.remove(); URL.revokeObjectURL(url); document.removeEventListener('keydown', onKey); };
  function onKey(e) { if (e.key === 'Escape') cerrar(); }
  document.addEventListener('keydown', onKey);
  el.querySelector('#pdf-modal-close').addEventListener('click', cerrar);
}

// Modal de confirmación nativo del sistema — reemplaza window.confirm()
// Uso: const ok = await confirmarModal('¿Eliminar?', { titulo:'Eliminar', confirmLabel:'Eliminar', danger:true })
(function _inyectarCmodalCss() {
  if (document.getElementById('cmodal-css')) return;
  const s = document.createElement('style');
  s.id = 'cmodal-css';
  s.textContent = `
.cmodal-overlay{position:fixed;inset:0;z-index:9500;background:rgba(49,52,75,.55);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center}
.cmodal-box{background:var(--neo-bg,#e0e5ec);border-radius:12px;border:1px solid rgba(0,0,0,.1);box-shadow:0 8px 32px rgba(0,0,0,.18);padding:28px 32px;max-width:420px;width:90%;animation:cmodalIn .16s ease}
@keyframes cmodalIn{from{opacity:0;transform:scale(.93)}to{opacity:1;transform:scale(1)}}
.cmodal-titulo{font-size:16px;font-weight:700;margin-bottom:10px;color:var(--neo-text,#333)}
.cmodal-msg{font-size:13.5px;color:var(--neo-text-2,#555);line-height:1.6;margin-bottom:22px}
.cmodal-btns{display:flex;gap:10px;justify-content:flex-end}
.cmodal-btn{padding:8px 20px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:opacity .15s}
.cmodal-btn:hover{opacity:.8}
.cmodal-btn-cancelar{background:transparent;color:var(--neo-text-2,#555);border:1.5px solid rgba(0,0,0,.18)}
.cmodal-btn-ok{background:var(--neo-accent,#4f8ef7);color:#fff;border:1.5px solid var(--neo-accent,#4f8ef7)}
.cmodal-btn-ok.danger{background:var(--neo-danger,#ef4444);border-color:var(--neo-danger,#ef4444)}
`;
  document.head.appendChild(s);
})();

function confirmarModal(mensaje, { titulo = 'Confirmar', confirmLabel = 'Confirmar', danger = false } = {}) {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.className = 'cmodal-overlay';
    overlay.innerHTML = `
      <div class="cmodal-box" role="dialog" aria-modal="true">
        <div class="cmodal-titulo">${escapeHtml(titulo)}</div>
        <div class="cmodal-msg">${escapeHtml(mensaje)}</div>
        <div class="cmodal-btns">
          <button class="cmodal-btn cmodal-btn-cancelar">Cancelar</button>
          <button class="cmodal-btn cmodal-btn-ok${danger ? ' danger' : ''}">${escapeHtml(confirmLabel)}</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);

    const cerrar = val => { overlay.remove(); resolve(val); };
    overlay.querySelector('.cmodal-btn-cancelar').addEventListener('click', () => cerrar(false));
    overlay.querySelector('.cmodal-btn-ok').addEventListener('click', () => cerrar(true));
    overlay.addEventListener('click', e => { if (e.target === overlay) cerrar(false); });
    overlay.addEventListener('keydown', e => { if (e.key === 'Escape') cerrar(false); });
    overlay.querySelector('.cmodal-btn-cancelar').focus();
  });
}

console.log('✅ Helpers globales cargados');
