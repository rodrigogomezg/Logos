// Paleta de temas de color del sistema
window.LOGOS_TEMAS = {
  azul:      { label: 'Azul',      primary: '#2563eb', hover: '#1d4ed8' },
  verde:     { label: 'Verde',     primary: '#16a34a', hover: '#15803d' },
  rojo:      { label: 'Rojo',      primary: '#dc2626', hover: '#b91c1c' },
  naranja:   { label: 'Naranja',   primary: '#ea580c', hover: '#c2410c' },
  violeta:   { label: 'Violeta',   primary: '#7c3aed', hover: '#6d28d9' },
  rosa:      { label: 'Rosa',      primary: '#db2777', hover: '#be185d' },
  indigo:    { label: 'Índigo',    primary: '#4f46e5', hover: '#4338ca' },
  teal:      { label: 'Teal',      primary: '#0d9488', hover: '#0f766e' },
  gris:      { label: 'Gris',      primary: '#4b5563', hover: '#374151' },
  negro:     { label: 'Negro',     primary: '#1f2937', hover: '#111827' },
};

window.LOGOS_aplicarTema = function (key) {
  var t = window.LOGOS_TEMAS[key] || window.LOGOS_TEMAS.azul;
  document.documentElement.style.setProperty('--azul',   t.primary);
  document.documentElement.style.setProperty('--azul-h', t.hover);
  try { localStorage.setItem('logos_tema', JSON.stringify([t.primary, t.hover])); } catch(e) {}
};

// Aplica el tema cacheado sincrónicamente para evitar el flash al navegar entre páginas
(function () {
  try {
    var c = JSON.parse(localStorage.getItem('logos_tema') || 'null');
    if (c && c[0] && c[1]) {
      document.documentElement.style.setProperty('--azul',   c[0]);
      document.documentElement.style.setProperty('--azul-h', c[1]);
    }
  } catch(e) {}
})();

(function () {
  var raw = localStorage.getItem('logos_sesion');
  var sesion = null;
  if (raw) {
    try { sesion = JSON.parse(raw); } catch (e) { sesion = null; }
  }
  if (!sesion || !sesion.usuario_id || !sesion.caja_id) {
    location.href = '/Logos/pos/login.html';
    return;
  }
  window.LOGOS_SESION = sesion;

  fetch('/Logos/api/instalacion/estado').then(function (r) { return r.json(); }).then(function (estado) {
    if (estado.requiere_conexion || estado.requiere_schema || estado.requiere_admin ||
        estado.requiere_negocio || estado.requiere_caja) {
      location.href = '/Logos/pos/instalar.html';
    }
  }).catch(function () {});

  var PAGINAS_SOLO_ADMIN = ['compras.html', 'compras-nueva.html', 'importar.html'];
  if (sesion.rol !== 'admin' && PAGINAS_SOLO_ADMIN.indexOf(location.pathname.split('/').pop()) !== -1) {
    location.href = '/Logos/pos/';
    return;
  }

  // Caja operativa: para admin, puede operar (vender / registrar movimientos)
  // en una caja distinta a la de su login, elegida con el selector del nav.
  // Se guarda por pestaña (sessionStorage), nunca pisa la sesión real.
  window.cajaOperativaId = function () {
    if (sesion.rol !== 'admin') return sesion.caja_id;
    var ov = sessionStorage.getItem('logos_caja_override');
    return ov ? Number(ov) : sesion.caja_id;
  };

  var fetchOriginal = window.fetch;
  window.fetch = function (url, opciones) {
    opciones = opciones || {};
    var esApi = typeof url === 'string' && url.indexOf('/Logos/api') !== -1;
    if (esApi) {
      opciones.headers = Object.assign({}, opciones.headers, { 'X-Usuario-Id': String(sesion.usuario_id) });
    }
    return fetchOriginal.call(this, url, opciones);
  };

  document.addEventListener('DOMContentLoaded', function () {
    var elUsuario = document.getElementById('nav-usuario');
    function actualizarEtiquetaUsuario(nombreCaja) {
      if (elUsuario) elUsuario.innerHTML = sesion.nombre + ' <span>· ' + nombreCaja + '</span>';
    }
    actualizarEtiquetaUsuario(sesion.caja_nombre);

    var elConfig = document.getElementById('nav-config-link');
    if (elConfig && sesion.rol !== 'admin') elConfig.style.display = 'none';

    var elCompras = document.getElementById('nav-compras-link');
    if (elCompras && sesion.rol !== 'admin') elCompras.style.display = 'none';

    var elCajaOp = document.getElementById('nav-caja-op');
    if (elCajaOp) {
      if (sesion.rol === 'admin') {
        elCajaOp.style.display = '';
        fetchOriginal('/Logos/api/cajas').then(function (r) { return r.json(); }).then(function (cajas) {
          var activa = window.cajaOperativaId();
          elCajaOp.innerHTML = cajas.map(function (c) {
            var etiqueta = c.id === sesion.caja_id ? c.nombre + ' (mi caja)' : c.nombre;
            return '<option value="' + c.id + '"' + (c.id === activa ? ' selected' : '') + '>' + etiqueta + '</option>';
          }).join('');
          actualizarEtiquetaUsuario(elCajaOp.options[elCajaOp.selectedIndex].text.replace(' (mi caja)', ''));
        });
        elCajaOp.addEventListener('change', function () {
          if (Number(elCajaOp.value) === sesion.caja_id) {
            sessionStorage.removeItem('logos_caja_override');
          } else {
            sessionStorage.setItem('logos_caja_override', elCajaOp.value);
          }
          actualizarEtiquetaUsuario(elCajaOp.options[elCajaOp.selectedIndex].text.replace(' (mi caja)', ''));
        });
      } else {
        elCajaOp.remove();
      }
    }

    var elLogout = document.getElementById('nav-logout');
    if (elLogout) {
      elLogout.addEventListener('click', function (e) {
        e.preventDefault();
        localStorage.removeItem('logos_sesion');
        location.href = '/Logos/pos/login.html';
      });
    }

    // Aplica el tema de color y actualiza el título con la razón social
    fetchOriginal('/Logos/api/configuracion').then(function (r) { return r.json(); }).then(function (cfg) {
      if (cfg && cfg.color_tema) window.LOGOS_aplicarTema(cfg.color_tema);
      if (cfg && cfg.razon_social) {
        window.LOGOS_EMPRESA = cfg.razon_social;
        document.title = document.title.replace(/^Logos/, cfg.razon_social);
      }
    }).catch(function () {});
  });
})();
