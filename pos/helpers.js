/**
 * Funciones globales helper para todos los scripts
 * Se carga en config.js o inmediatamente después
 */

function $(id) {
  const el = document.getElementById(id);
  if (!el) {
    console.warn(`[Helpers] Elemento con ID no encontrado: ${id}`);
  }
  return el;
}

function fmt(valor) {
  if (typeof valor !== 'number') valor = parseFloat(valor) || 0;
  return new Intl.NumberFormat('es-AR', {
    style: 'currency',
    currency: 'ARS',
    minimumFractionDigits: 2,
  }).format(valor);
}

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
    const data = await resp.json();

    if (!resp.ok) {
      throw new Error(data.error || `HTTP ${resp.status}`);
    }

    return data;
  } catch (e) {
    console.error(`[API Error] ${endpoint}:`, e.message);
    throw e;
  }
}

console.log('✅ Helpers globales cargados');
