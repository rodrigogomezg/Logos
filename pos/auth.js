(function () {
  var raw = localStorage.getItem('bron_sesion');
  var sesion = null;
  if (raw) {
    try { sesion = JSON.parse(raw); } catch (e) { sesion = null; }
  }
  if (!sesion || !sesion.usuario_id || !sesion.caja_id) {
    location.href = '/BRON/pos/login.html';
    return;
  }
  window.BRON_SESION = sesion;

  fetch('/BRON/api/instalacion/estado').then(function (r) { return r.json(); }).then(function (estado) {
    if (estado.requiere_conexion || estado.requiere_schema || estado.requiere_admin ||
        estado.requiere_negocio || estado.requiere_caja) {
      location.href = '/BRON/pos/instalar.html';
    }
  }).catch(function () {});

  var PAGINAS_SOLO_ADMIN = ['compras.html', 'compras-nueva.html', 'importar.html'];
  if (sesion.rol !== 'admin' && PAGINAS_SOLO_ADMIN.indexOf(location.pathname.split('/').pop()) !== -1) {
    location.href = '/BRON/pos/';
    return;
  }

  // Caja operativa: para admin, puede operar (vender / registrar movimientos)
  // en una caja distinta a la de su login, elegida con el selector del nav.
  // Se guarda por pestaña (sessionStorage), nunca pisa la sesión real.
  window.cajaOperativaId = function () {
    if (sesion.rol !== 'admin') return sesion.caja_id;
    var ov = sessionStorage.getItem('bron_caja_override');
    return ov ? Number(ov) : sesion.caja_id;
  };

  var fetchOriginal = window.fetch;
  window.fetch = function (url, opciones) {
    opciones = opciones || {};
    var esApi = typeof url === 'string' && url.indexOf('/BRON/api') !== -1;
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
        fetchOriginal('/BRON/api/cajas').then(function (r) { return r.json(); }).then(function (cajas) {
          var activa = window.cajaOperativaId();
          elCajaOp.innerHTML = cajas.map(function (c) {
            var etiqueta = c.id === sesion.caja_id ? c.nombre + ' (mi caja)' : c.nombre;
            return '<option value="' + c.id + '"' + (c.id === activa ? ' selected' : '') + '>' + etiqueta + '</option>';
          }).join('');
          actualizarEtiquetaUsuario(elCajaOp.options[elCajaOp.selectedIndex].text.replace(' (mi caja)', ''));
        });
        elCajaOp.addEventListener('change', function () {
          if (Number(elCajaOp.value) === sesion.caja_id) {
            sessionStorage.removeItem('bron_caja_override');
          } else {
            sessionStorage.setItem('bron_caja_override', elCajaOp.value);
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
        localStorage.removeItem('bron_sesion');
        location.href = '/BRON/pos/login.html';
      });
    }
  });
})();
