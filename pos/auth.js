// Identidad de marca fija (navy/dorado): el sistema de temas por color
// quedó desactivado. Se conserva la API por compatibilidad y se limpia
// el tema cacheado de versiones anteriores para que no pise la paleta.
window.LOGOS_TEMAS = {};
window.LOGOS_aplicarTema = function () {};
try { localStorage.removeItem('logos_tema'); } catch(e) {}

(function () {
  var raw = localStorage.getItem('logos_sesion');
  var sesion = null;
  if (raw) {
    try { sesion = JSON.parse(raw); } catch (e) { sesion = null; }
  }
  if (!sesion || !sesion.usuario_id || !sesion.caja_id || !sesion.token) {
    localStorage.removeItem('logos_sesion');
    location.href = window.APP_CONFIG.POS_BASE + '/login.html';
    return;
  }
  // Sesión anterior al sistema de permisos granulares: forzar un re-login
  // (una sola vez tras el deploy) para que la sesión incluya la matriz.
  if (sesion.rol !== 'admin' && sesion.permisos === undefined) {
    localStorage.removeItem('logos_sesion');
    location.href = window.APP_CONFIG.POS_BASE + '/login.html';
    return;
  }
  window.LOGOS_SESION = sesion;

  // ¿Tiene el usuario este permiso granular? Admin → siempre sí.
  // Copia de UI: el server re-valida cada request con la matriz fresca.
  window.puede = function (p) {
    return sesion.rol === 'admin' || !!(sesion.permisos && sesion.permisos[p]);
  };

  var deviceName = null;
  try { deviceName = localStorage.getItem('logos_device_name'); } catch(e) {}

  function mostrarSinConexion() {
    function pintar() {
      if (document.getElementById('logos-sin-conexion')) return;
      var overlay = document.createElement('div');
      overlay.id = 'logos-sin-conexion';
      overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#1A1A1A;display:flex;align-items:center;justify-content:center;';
      overlay.innerHTML =
        '<div style="max-width:420px;text-align:center;color:rgba(255,255,255,.9);font-family:inherit;display:flex;flex-direction:column;gap:16px;align-items:center;padding:24px;">' +
          '<div style="font-size:40px;">🔌</div>' +
          '<div style="font-size:20px;font-weight:700;">No se pudo conectar a la base de datos</div>' +
          '<div style="font-size:14px;color:rgba(255,255,255,.6);line-height:1.5;">El sistema ya está instalado, pero el servidor de base de datos no responde.<br>Abrí el <b>Panel de Control de XAMPP</b> y presioná <b>Start</b> en <b>MySQL</b>.</div>' +
          '<button id="logos-reintentar" style="background:var(--azul,#2563eb);color:white;border:none;border-radius:10px;padding:12px 28px;font-size:14px;font-weight:600;cursor:pointer;">Reintentar</button>' +
        '</div>';
      document.body.appendChild(overlay);
      document.getElementById('logos-reintentar').addEventListener('click', function () { location.reload(); });
    }
    if (document.body) pintar();
    else document.addEventListener('DOMContentLoaded', pintar);
  }

  fetch(window.API + '/instalacion/estado').then(function (r) { return r.json(); }).then(function (estado) {
    if (estado.sin_conexion) {
      mostrarSinConexion();
      return;
    }
    if (estado.requiere_conexion || estado.requiere_schema || estado.requiere_admin ||
        estado.requiere_negocio || estado.requiere_caja) {
      location.href = window.APP_CONFIG.POS_BASE + '/instalar.html';
    }
  }).catch(function () {});

  // Permiso requerido por página (null = solo admin). Lo que no figura acá
  // es libre para cualquier usuario logueado. El server igual re-valida.
  var PAGINA_PERMISO = {
    'compras.html':         'compras',
    'compras-nueva.html':   'compras',
    'importar.html':        'importar',
    'cuentacorriente.html': 'cc_ver',
    'rentabilidad.html':    'costos',
    'dashboard.html':       'reportes',
    'iva.html':             'reportes',
    'log.html':             'log',
    'configuracion.html':   null,
  };
  var _pagina = location.pathname.split('/').pop();
  if (_pagina in PAGINA_PERMISO) {
    var _permReq = PAGINA_PERMISO[_pagina];
    var _ok = _permReq === null ? sesion.rol === 'admin' : window.puede(_permReq);
    if (!_ok) {
      location.href = window.APP_CONFIG.POS_BASE;
      return;
    }
  }

  // Caja operativa: con el permiso 'cajas_todas' (admin lo tiene implícito)
  // se puede operar en una caja distinta a la del login, elegida con el
  // selector del nav. Se guarda por pestaña (sessionStorage).
  window.cajaOperativaId = function () {
    if (!window.puede('cajas_todas')) return sesion.caja_id;
    var ov = sessionStorage.getItem('logos_caja_override');
    return ov ? Number(ov) : sesion.caja_id;
  };

  // Sucursal activa: la del login por defecto; admin puede cambiarla con el selector.
  window.sucursalActivaId = function () {
    var ov = sessionStorage.getItem('logos_sucursal_override');
    return ov ? Number(ov) : (sesion.sucursal_id || 1);
  };
  window.depositoActivoId = function () {
    return sesion.deposito_id || 1;
  };
  // ¿El comercio tiene múltiples sucursales? (determina si se muestra el selector)
  window.tieneMultiSucursal = function () {
    return Array.isArray(sesion.sucursales) && sesion.sucursales.length > 1;
  };

  var fetchOriginal = window.fetch;

  // ── Detección de servidor caído ───────────────────────────────────────────
  var _netFails = 0;
  var _OV_ID    = 'logos-sin-servidor-ov';

  function _mostrarSinServidor() {
    if (document.getElementById(_OV_ID)) return;
    var ov = document.createElement('div');
    ov.id = _OV_ID;
    ov.style.cssText = [
      'position:fixed;inset:0;z-index:99999',
      'background:#0f1117',
      'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px',
      'font-family:inherit',
    ].join(';');
    ov.innerHTML =
      '<div style="font-size:40px">⚠️</div>' +
      '<div style="font-size:17px;font-weight:700;color:#f87171">Servidor desconectado</div>' +
      '<div style="font-size:13px;color:#9ca3af;text-align:center;max-width:380px;line-height:1.7">' +
        'No se puede conectar al servidor.<br>' +
        'Verificá que la PC servidor esté encendida y conectada a la red.' +
      '</div>' +
      '<button id="' + _OV_ID + '-btn" style="' +
        'background:#4f8ef7;color:#fff;border:none;border-radius:8px;' +
        'padding:11px 28px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit' +
      '">Reintentar</button>';
    function _pintar() {
      document.body.appendChild(ov);
      document.getElementById(_OV_ID + '-btn').addEventListener('click', function () {
        var btn = document.getElementById(_OV_ID + '-btn');
        btn.textContent = 'Conectando…';
        btn.disabled = true;
        fetchOriginal(window.API + '/instalacion/estado')
          .then(function (r) {
            if (r.status < 500) {
              _netFails = 0;
              var el = document.getElementById(_OV_ID);
              if (el) el.remove();
              location.reload();
            } else { btn.textContent = 'Reintentar'; btn.disabled = false; }
          })
          .catch(function () { btn.textContent = 'Reintentar'; btn.disabled = false; });
      });
    }
    if (document.body) _pintar();
    else document.addEventListener('DOMContentLoaded', _pintar);
  }
  // ─────────────────────────────────────────────────────────────────────────

  window.fetch = function (url, opciones) {
    opciones = opciones || {};
    var esApi = typeof url === 'string' && url.indexOf(window.APP_CONFIG.API_BASE) !== -1;
    if (esApi) {
      var _hdrs = { 'X-Auth-Token': sesion.token };
      if (deviceName) _hdrs['X-Device-Name'] = deviceName;
      opciones.headers = Object.assign({}, opciones.headers, _hdrs);
    }
    var promesa = fetchOriginal.call(this, url, opciones);
    if (!esApi) return promesa;
    return promesa.then(function (r) {
      _netFails = 0; // servidor respondió: limpiar contador
      if (r.status === 401) {
        localStorage.removeItem('logos_sesion');
        location.href = window.APP_CONFIG.POS_BASE + '/login.html';
      }
      return r;
    }).catch(function (err) {
      // Error de red (no HTTP): el servidor no responde
      if (++_netFails >= 2) _mostrarSinServidor();
      throw err;
    });
  };

  // Escape HTML local: auth.js corre antes de helpers.js y no puede depender
  // de escapeHtml(). Un nombre de usuario/caja con markup no debe ejecutar JS.
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var elUsuario = document.getElementById('nav-usuario');
    function actualizarEtiquetaUsuario(nombreCaja) {
      if (!elUsuario) return;
      elUsuario.innerHTML = nombreCaja
        ? esc(sesion.nombre) + ' <span>· ' + esc(nombreCaja) + '</span>'
        : esc(sesion.nombre);
    }
    // Si el selector de cajas va a estar visible, la caja no se repite en el nombre.
    actualizarEtiquetaUsuario(window.puede('cajas_todas') ? null : sesion.caja_nombre);

    var elConfig = document.getElementById('nav-config-link');
    if (elConfig && sesion.rol !== 'admin') elConfig.style.display = 'none';

    var elLog = document.getElementById('nav-log-link');
    if (elLog && window.puede('log')) elLog.style.display = '';

    var elCompras = document.getElementById('nav-compras-link');
    if (elCompras && !window.puede('compras')) elCompras.style.display = 'none';

    // Visibilidad por permiso granular del resto del menú
    function ocultarSi(id, permiso) {
      var el = document.getElementById(id);
      if (el && !window.puede(permiso)) el.style.display = 'none';
    }
    ocultarSi('nav-vendedores-link',     'gestionar_vendedores');
    ocultarSi('nav-cc-drop',            'cc_ver');
    ocultarSi('nav-importar-link',      'importar');
    ocultarSi('nav-dashboard-link',     'reportes');
    ocultarSi('nav-iva-link',           'reportes');
    ocultarSi('nav-rentabilidad-link',  'costos');
    if (!window.puede('reportes') && !window.puede('costos')) {
      var elRep = document.getElementById('nav-reportes-drop');
      if (elRep) elRep.style.display = 'none';
    }

    // Selector de sucursal.
    // Admin: fetch fresco de la API (siempre refleja el estado actual, aunque
    //        se hayan creado sucursales después del login).
    // No-admin: usa los datos de sesión (ya filtrados por sucursal_ids).
    var elSucursalOp = document.getElementById('nav-sucursal-op');
    if (elSucursalOp) {
      function montarSelectorSucursal(suxList) {
        if (!suxList || suxList.length === 0) { elSucursalOp.remove(); return; }
        if (suxList.length === 1) {
          var lbl = document.createElement('span');
          lbl.className = 'nav-sucursal-label';
          lbl.title = 'Sucursal en la que estás trabajando';
          lbl.textContent = suxList[0].nombre;
          elSucursalOp.parentNode.replaceChild(lbl, elSucursalOp);
          return;
        }
        // Actualizar sesión con datos frescos
        sesion.sucursales = suxList;
        try { localStorage.setItem('logos_sesion', JSON.stringify(sesion)); } catch (e) {}
        elSucursalOp.style.display = '';
        var activaSuc = window.sucursalActivaId();
        elSucursalOp.innerHTML = suxList.map(function (s) {
          return '<option value="' + s.id + '"' + (s.id === activaSuc ? ' selected' : '') + '>' + esc(s.nombre) + '</option>';
        }).join('');
        elSucursalOp.addEventListener('change', function () {
          var nuevo = Number(elSucursalOp.value);
          sessionStorage.setItem('logos_sucursal_override', String(nuevo));
          var sux = suxList.find(function (s) { return s.id === nuevo; });
          if (sux) {
            sesion.deposito_id = sux.deposito_principal_id || 1;
            try { localStorage.setItem('logos_sesion', JSON.stringify(sesion)); } catch (e) {}
          }
          window.dispatchEvent(new CustomEvent('sucursal-changed', { detail: { sucursal_id: nuevo } }));
        });
      }

      if (sesion.rol === 'admin') {
        window.fetch(window.API + '/sucursales').then(function (r) { return r.json(); })
          .then(function (data) { montarSelectorSucursal(Array.isArray(data) ? data : []); })
          .catch(function () { elSucursalOp.remove(); });
      } else {
        montarSelectorSucursal(Array.isArray(sesion.sucursales) ? sesion.sucursales : []);
      }
    }

    var elCajaOp = document.getElementById('nav-caja-op');
    if (elCajaOp) {
      if (window.puede('cajas_todas')) {
        elCajaOp.style.display = '';
        window.fetch(window.API + '/cajas').then(function (r) { return r.json(); }).then(function (cajas) {
          var activa = window.cajaOperativaId();
          elCajaOp.innerHTML = cajas.map(function (c) {
            var etiqueta = c.nombre;
            return '<option value="' + Number(c.id) + '"' + (c.id === activa ? ' selected' : '') + '>' + esc(etiqueta) + '</option>';
          }).join('');
        });
        elCajaOp.addEventListener('change', function () {
          if (Number(elCajaOp.value) === sesion.caja_id) {
            sessionStorage.removeItem('logos_caja_override');
          } else {
            sessionStorage.setItem('logos_caja_override', elCajaOp.value);
          }
        });
      } else {
        elCajaOp.remove();
      }
    }

    var elLogout = document.getElementById('nav-logout');
    if (elLogout) {
      elLogout.addEventListener('click', function (e) {
        e.preventDefault();
        // Invalidar la sesión en el servidor antes de limpiar la local
        fetchOriginal(window.API + '/usuarios/logout', {
          method: 'POST',
          headers: { 'X-Auth-Token': sesion.token },
        }).catch(function () {}).finally(function () {
          localStorage.removeItem('logos_sesion');
          location.href = window.APP_CONFIG.POS_BASE + '/login.html';
        });
      });
    }

// Aplica el tema de color y actualiza el título con la razón social
    fetchOriginal(window.API + '/configuracion').then(function (r) { return r.json(); }).then(function (cfg) {
      if (cfg && cfg.color_tema) window.LOGOS_aplicarTema(cfg.color_tema);
      if (cfg && cfg.razon_social) {
        window.LOGOS_EMPRESA = cfg.razon_social;
        document.title = document.title.replace(/^Logos/, cfg.razon_social);
      }
    }).catch(function () {});

    // Primera vez en este equipo: pedir nombre de identificación
    if (!deviceName) {
      var _overlay = document.createElement('div');
      _overlay.id = 'logos-device-modal';
      _overlay.style.cssText = 'position:fixed;inset:0;z-index:99998;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;font-family:inherit;';
      _overlay.innerHTML =
        '<div style="background:var(--neo-bg,#fff);border-radius:16px;padding:32px;max-width:400px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);display:flex;flex-direction:column;gap:16px;">' +
          '<div style="font-size:18px;font-weight:700;color:var(--neo-text,#1a1a1a)">Identificá este equipo</div>' +
          '<div style="font-size:13px;color:var(--neo-text-3,#888);line-height:1.6">Asigná un nombre a este dispositivo. Aparecerá en el log de acciones para identificar desde qué PC se realizó cada operación.<br><br>Ej: <em>Caja 1</em>, <em>Administración</em>, <em>Notebook Rodrigo</em></div>' +
          '<input id="logos-device-input" type="text" placeholder="Nombre de este equipo" maxlength="100" style="padding:10px 14px;border:none;border-radius:8px;font-size:14px;font-family:inherit;background:var(--neo-bg-deep,#eee);color:var(--neo-text,#1a1a1a);outline:none;box-shadow:inset 2px 2px 5px rgba(0,0,0,.08);">' +
          '<button id="logos-device-guardar" style="padding:10px 20px;background:var(--azul,#2563eb);color:white;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;">Guardar</button>' +
        '</div>';
      document.body.appendChild(_overlay);
      document.getElementById('logos-device-guardar').addEventListener('click', function () {
        var val = (document.getElementById('logos-device-input').value || '').trim();
        if (!val) {
          document.getElementById('logos-device-input').style.boxShadow = 'inset 2px 2px 5px rgba(0,0,0,.08), 0 0 0 2px #dc2626';
          return;
        }
        try { localStorage.setItem('logos_device_name', val); deviceName = val; } catch(e) {}
        _overlay.remove();
      });
      document.getElementById('logos-device-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') document.getElementById('logos-device-guardar').click();
      });
    }

    var elFecha = document.getElementById('fecha-hora');
    if (elFecha) {
      function _tickFecha() {
        elFecha.textContent = new Date().toLocaleDateString('es-AR', { dateStyle: 'short' });
      }
      _tickFecha();
      setInterval(_tickFecha, 60000);
    }
  });
})();
