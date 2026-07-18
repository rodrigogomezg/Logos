<?php
/**
 * Partial: modal de edición/creación de clientes y proveedores.
 * Incluir con: <?php include __DIR__ . '/contacto-modal.php'; ?>
 * Uso JS:
 *   ContactoModal.abrir(id, tipo, { onGuardado, onEliminado })
 *   ContactoModal.nuevo(tipo, { onGuardado })
 *   tipo = 'clientes' | 'proveedores'
 */
?>
<style>
/* ── contacto-modal ─────────────────────────────────────── */
.cm-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; z-index:300; }
.cm-overlay.cm-oculto { display:none; }

.cm-modal {
  background:#fff; border:1px solid #D5D0CA; border-radius:0;
  width:min(96vw, 1020px);
  height:min(88vh, 660px);
  display:flex; flex-direction:column;
}

.cm-header {
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 20px; border-bottom:1px solid #D5D0CA; background:#F0EEEB; flex-shrink:0;
}
.cm-header h3 { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#9B9590; margin:0; }
.cm-header-cerrar { background:none; border:none; cursor:pointer; color:#9B9590; font-size:18px; line-height:1; padding:2px 6px; }
.cm-header-cerrar:hover { color:#111; }

/* body = two-pane */
.cm-body { flex:1; overflow:hidden; display:grid; grid-template-columns:1fr 320px; min-height:0; }
.cm-col {
  padding:18px 20px; overflow-y:auto; display:flex; flex-direction:column; gap:14px;
  scrollbar-width:thin;
}
.cm-col-right { border-left:1px solid #D5D0CA; background:#FAFAF9; gap:16px; }

.cm-footer {
  padding:12px 20px; border-top:1px solid #D5D0CA;
  display:flex; gap:8px; justify-content:flex-end; flex-shrink:0;
}

/* form elements */
.cm-group { display:flex; flex-direction:column; gap:4px; }
.cm-row   { display:grid; gap:10px; }
.cm-row-2 { grid-template-columns:1fr 1fr; }
.cm-row-3 { grid-template-columns:1fr 1fr 1fr; }

.cm-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9B9590; }
.cm-input, .cm-select, .cm-textarea {
  padding:8px 10px; border:1px solid #D5D0CA; border-radius:0;
  font-size:13px; font-family:inherit; outline:none; background:#fff; width:100%; box-sizing:border-box;
}
.cm-input:focus, .cm-select:focus, .cm-textarea:focus { border-color:#111; }
.cm-textarea { resize:vertical; min-height:56px; flex:1; }

.cm-section {
  font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px;
  color:#9B9590; padding-bottom:6px; border-bottom:1px solid #D5D0CA;
  display:block;
}

/* toggle */
.cm-toggle { display:inline-flex; align-items:center; gap:9px; cursor:pointer; user-select:none; }
.cm-toggle input[type=checkbox] { position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
.cm-track { width:36px; height:20px; flex-shrink:0; background:#E2DDD8; border:1px solid #D5D0CA; position:relative; transition:background 140ms, border-color 140ms; border-radius:2px; }
.cm-track::after { content:''; position:absolute; width:14px; height:14px; top:2px; left:2px; background:#fff; border-radius:1px; box-shadow:0 1px 3px rgba(0,0,0,.18); transition:transform 140ms; }
.cm-toggle input:checked ~ .cm-track { background:var(--azul,#2563eb); border-color:var(--azul,#2563eb); }
.cm-toggle input:checked ~ .cm-track::after { transform:translateX(16px); }
.cm-toggle-lbl { font-size:13px; color:#111; }

/* btns */
.cm-btn { padding:9px 18px; border:1.5px solid transparent; border-radius:0; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; cursor:pointer; font-family:inherit; }
.cm-btn-ok      { background:var(--azul,#2563eb); color:#fff; border-color:var(--azul,#2563eb); }
.cm-btn-sec     { background:#fff; color:#111; border-color:#888; }
.cm-btn-peligro { background:#fff; color:#B91C1C; border-color:#B91C1C; }
.cm-btn-mini    { padding:4px 10px; background:#fff; color:#111; border:1px solid #999; border-radius:0; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; cursor:pointer; font-family:inherit; }
.cm-btn-mini:hover { border-color:#111; }
.cm-btn-mini-rojo { padding:4px 9px; background:#fff; color:#B91C1C; border:1px solid #B91C1C; border-radius:0; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; cursor:pointer; font-family:inherit; }

/* domicilios de envío */
.cm-envio-list { display:flex; flex-direction:column; gap:8px; }
.cm-envio-card { background:#fff; border:1px solid #D5D0CA; padding:9px 11px; display:flex; flex-direction:column; gap:7px; }
.cm-envio-header { display:flex; align-items:center; gap:7px; }
.cm-envio-badge { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#9B9590; background:#e2e8f0; padding:2px 7px; flex-shrink:0; }
.cm-envio-badge.principal { background:var(--azul,#2563eb); color:#fff; }
.cm-envio-etiqueta { flex:1; min-width:0; }
.cm-envio-etiqueta input { background:transparent; border:none; border-bottom:1px dashed #D5D0CA; padding:2px 4px; font-size:12px; font-weight:600; width:100%; font-family:inherit; outline:none; }
.cm-envio-fields { display:grid; grid-template-columns:1fr 1fr; gap:4px 7px; }
.cm-envio-fields .ef-full { grid-column:1/-1; }
.cm-envio-fields input { padding:5px 8px; border:1px solid #D5D0CA; border-radius:0; font-size:12px; font-family:inherit; outline:none; background:#fff; width:100%; box-sizing:border-box; }
.cm-envio-fields input:focus { border-color:#111; }
.cm-envio-vacio { font-size:12px; color:#9B9590; }

/* confirm delete mini-modal */
.cm-confirm { width:420px; height:auto; }
.cm-confirm .cm-body { display:flex; flex-direction:column; padding:16px 20px; overflow:visible; }
.cm-confirm p { font-size:13px; margin:0; }
</style>

<!-- ═══ Modal principal ═════════════════════════════════════════ -->
<div class="cm-overlay cm-oculto" id="cm-overlay">
  <div class="cm-modal">
    <div class="cm-header">
      <h3 id="cm-titulo">Nuevo cliente</h3>
      <button class="cm-header-cerrar" id="cm-cerrar">✕</button>
    </div>

    <div class="cm-body">
      <form id="cm-form" autocomplete="off" style="display:contents">

        <!-- ── Columna izquierda: datos de contacto ── -->
        <div class="cm-col">
          <span class="cm-section">Datos de contacto</span>

          <div class="cm-group">
            <label class="cm-label">Nombre / Razón social *</label>
            <input class="cm-input" name="nombre" required>
          </div>

          <div class="cm-row cm-row-2">
            <div class="cm-group">
              <label class="cm-label">CUIT</label>
              <input class="cm-input" name="cuit" placeholder="20-12345678-9">
            </div>
            <div class="cm-group">
              <label class="cm-label">Condición IVA</label>
              <select class="cm-select" name="condicion_iva">
                <option value="">—</option>
                <option>Responsable Inscripto</option>
                <option>Monotributista</option>
                <option>Consumidor Final</option>
                <option>Exento</option>
                <option>No Responsable</option>
              </select>
            </div>
          </div>

          <div class="cm-row cm-row-2">
            <div class="cm-group">
              <label class="cm-label">Email</label>
              <input class="cm-input" name="email" type="email">
            </div>
            <div class="cm-group">
              <label class="cm-label">Teléfono</label>
              <input class="cm-input" name="telefono">
            </div>
          </div>

          <div class="cm-group">
            <label class="cm-label">Domicilio</label>
            <input class="cm-input" name="domicilio">
          </div>

          <div class="cm-row cm-row-2">
            <div class="cm-group">
              <label class="cm-label">Localidad</label>
              <input class="cm-input" name="localidad">
            </div>
            <div class="cm-group">
              <label class="cm-label">Provincia</label>
              <input class="cm-input" name="provincia">
            </div>
          </div>

          <div class="cm-group" style="flex:1;display:flex;flex-direction:column">
            <label class="cm-label">Observaciones</label>
            <textarea class="cm-textarea" name="observaciones"></textarea>
          </div>
        </div>

        <!-- ── Columna derecha: CC + comercial + envío + estado ── -->
        <div class="cm-col cm-col-right">

          <!-- Cuenta corriente -->
          <div style="display:flex;flex-direction:column;gap:10px">
            <span class="cm-section">Cuenta corriente</span>
            <label class="cm-toggle">
              <input type="checkbox" name="cc_habilitada" id="cm-chk-cc">
              <span class="cm-track"></span>
              <span class="cm-toggle-lbl">Habilitar cuenta corriente</span>
            </label>
            <div class="cm-group">
              <label class="cm-label">Límite de crédito ($)</label>
              <input class="cm-input" name="limite_credito" type="number" min="0" step="0.01" value="0">
            </div>
            <div class="cm-group" id="cm-campo-plazo">
              <label class="cm-label">Plazo de pago</label>
              <div style="display:flex;gap:8px">
                <select class="cm-select" id="cm-plazo-preset" style="flex:1">
                  <option value="">Sin plazo</option>
                  <option value="7">7 días</option>
                  <option value="15">15 días</option>
                  <option value="30">30 días</option>
                  <option value="60">60 días</option>
                  <option value="90">90 días</option>
                  <option value="custom">Personalizado…</option>
                </select>
                <input class="cm-input" name="plazo_pago_dias" type="number" min="1" step="1"
                       placeholder="días" style="width:90px;display:none">
              </div>
              <div class="cm-hint" style="font-size:11px;color:#8A8578;margin-top:4px">
                Los cargos de cuenta corriente vencen a estos días. Define las alertas de vencimiento.
              </div>
            </div>
          </div>

          <!-- Configuración comercial (solo clientes) -->
          <div id="cm-seccion-comercial" style="display:flex;flex-direction:column;gap:10px">
            <span class="cm-section">Configuración comercial</span>
            <div class="cm-group">
              <label class="cm-label">Lista de precios</label>
              <select class="cm-select" name="lista_precio_id">
                <option value="">Sin lista asignada</option>
              </select>
            </div>
            <div class="cm-row cm-row-2" style="align-items:flex-end">
              <div class="cm-group">
                <label class="cm-label">Dto. extra (%)</label>
                <input class="cm-input" name="descuento_extra" type="number" min="-100" max="100" step="0.01" value="0">
              </div>
              <label class="cm-toggle" style="padding-bottom:9px">
                <input type="checkbox" name="solo_remito" id="cm-chk-remito">
                <span class="cm-track"></span>
                <span class="cm-toggle-lbl">Solo remito</span>
              </label>
            </div>
          </div>

          <!-- Domicilios de envío (solo clientes) -->
          <div id="cm-seccion-envio" style="display:flex;flex-direction:column;gap:10px;flex:1;min-height:0">
            <div style="display:flex;align-items:center;justify-content:space-between">
              <span class="cm-section" style="flex:1;border:none;padding:0">Domicilios de envío</span>
              <button type="button" class="cm-btn-mini" id="cm-btn-add-envio">+ Agregar</button>
            </div>
            <div id="cm-lista-envio" class="cm-envio-list"></div>
          </div>

          <!-- Estado -->
          <div style="display:flex;flex-direction:column;gap:10px;margin-top:auto;padding-top:12px;border-top:1px solid #D5D0CA">
            <label class="cm-toggle">
              <input type="checkbox" name="activo" id="cm-chk-activo" checked>
              <span class="cm-track"></span>
              <span class="cm-toggle-lbl">Contacto activo</span>
            </label>
          </div>

        </div><!-- /cm-col-right -->

        <input type="hidden" name="_id">
        <input type="hidden" name="_tipo">
      </form>
    </div>

    <div class="cm-footer">
      <button class="cm-btn cm-btn-peligro" id="cm-btn-eliminar" style="display:none;margin-right:auto">Eliminar</button>
      <button class="cm-btn cm-btn-sec" id="cm-btn-cancelar">Cancelar</button>
      <button class="cm-btn cm-btn-ok" id="cm-btn-guardar">Guardar</button>
    </div>
  </div>
</div>

<!-- ═══ Modal confirmar eliminación ════════════════════════════ -->
<div class="cm-overlay cm-oculto" id="cm-overlay-del">
  <div class="cm-modal cm-confirm">
    <div class="cm-header">
      <h3>Confirmar eliminación</h3>
      <button class="cm-header-cerrar" id="cm-del-cerrar">✕</button>
    </div>
    <div class="cm-body">
      <p id="cm-del-texto">¿Eliminar este contacto?</p>
    </div>
    <div class="cm-footer">
      <button class="cm-btn cm-btn-sec" id="cm-del-no">Cancelar</button>
      <button class="cm-btn" style="background:#B91C1C;color:#fff;border-color:#B91C1C" id="cm-del-si">Eliminar</button>
    </div>
  </div>
</div>

<script>
window.ContactoModal = (function () {
  'use strict';

  const $ = id => document.getElementById(id);
  const API = '/Logos/api';

  let _tipo       = 'clientes';
  let _listas     = [];
  let _envios     = [];
  let _cbGuardar  = null;
  let _cbEliminar = null;

  /* ── helpers ─────────────────────────────────────── */
  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── setup visual según tipo ────────────────────── */
  function _configurar(tipo) {
    const esCliente = tipo === 'clientes';
    $('cm-seccion-comercial').style.display = esCliente ? 'flex' : 'none';
    $('cm-seccion-envio').style.display     = esCliente ? 'flex' : 'none';
  }

  /* ── plazo de pago: presets + personalizado ───────── */
  const PLAZO_PRESETS = ['7', '15', '30', '60', '90'];

  // Sincroniza el select de presets desde el valor real del input (que es lo
  // que se serializa en el form).
  function _syncPlazoPreset() {
    const input = document.querySelector('#cm-form [name=plazo_pago_dias]');
    const sel   = $('cm-plazo-preset');
    const v     = String(input.value ?? '').trim();
    if (v === '')                        { sel.value = '';       input.style.display = 'none'; }
    else if (PLAZO_PRESETS.includes(v))  { sel.value = v;        input.style.display = 'none'; }
    else                                 { sel.value = 'custom'; input.style.display = ''; }
  }

  $('cm-plazo-preset').addEventListener('change', () => {
    const input = document.querySelector('#cm-form [name=plazo_pago_dias]');
    const v     = $('cm-plazo-preset').value;
    if (v === 'custom') {
      input.style.display = '';
      input.focus();
    } else {
      input.value = v;
      input.style.display = 'none';
    }
  });

  /* ── listas de precio ────────────────────────────── */
  async function _cargarListas() {
    if (!_listas.length) {
      const r = await fetch(`${API}/listas-precio?activas=1`);
      if (r.ok) _listas = await r.json();
    }
    const sel = document.querySelector('#cm-form [name=lista_precio_id]');
    const val = sel.value;
    sel.innerHTML = '<option value="">Sin lista asignada</option>' +
      _listas.map(l => `<option value="${l.id}">${esc(l.nombre)} (${l.porcentaje >= 0 ? '+' : ''}${l.porcentaje}%)</option>`).join('');
    sel.value = val;
  }

  /* ── domicilios de envío ─────────────────────────── */
  function _renderEnvio() {
    const lista = $('cm-lista-envio');
    if (!_envios.length) {
      lista.innerHTML = '<div class="cm-envio-vacio">Sin direcciones cargadas.</div>';
      return;
    }
    lista.innerHTML = '';
    _envios.forEach((d, i) => {
      const card = document.createElement('div');
      card.className = 'cm-envio-card';
      card.innerHTML = `
        <div class="cm-envio-header">
          <span class="cm-envio-badge ${i === 0 ? 'principal' : ''}">${i === 0 ? 'Principal' : 'Alt. ' + i}</span>
          <div class="cm-envio-etiqueta">
            <input placeholder="Etiqueta (Casa, Depósito…)" value="${esc(d.etiqueta || '')}">
          </div>
          <button type="button" class="cm-btn-mini-rojo" data-rm="${i}">✕</button>
          ${i > 0 ? `<button type="button" class="cm-btn-mini" data-up="${i}">↑</button>` : ''}
        </div>
        <div class="cm-envio-fields">
          <div class="ef-full"><input placeholder="Calle, número, piso…" value="${esc(d.domicilio || '')}"></div>
          <input placeholder="Localidad" value="${esc(d.localidad || '')}">
          <input placeholder="Provincia" value="${esc(d.provincia || '')}">
          <div class="ef-full"><input placeholder="Referencia / indicaciones" value="${esc(d.referencia || '')}"></div>
        </div>`;

      card.querySelector('.cm-envio-etiqueta input').addEventListener('input', e => { _envios[i].etiqueta = e.target.value; });
      const [dirI, locI, provI, refI] = card.querySelectorAll('.cm-envio-fields input');
      dirI.addEventListener('input',  e => { _envios[i].domicilio  = e.target.value; });
      locI.addEventListener('input',  e => { _envios[i].localidad  = e.target.value; });
      provI.addEventListener('input', e => { _envios[i].provincia  = e.target.value; });
      refI.addEventListener('input',  e => { _envios[i].referencia = e.target.value; });

      card.querySelector('[data-rm]').addEventListener('click', () => { _envios.splice(i, 1); _renderEnvio(); });
      const upBtn = card.querySelector('[data-up]');
      if (upBtn) upBtn.addEventListener('click', () => {
        [_envios[i-1], _envios[i]] = [_envios[i], _envios[i-1]];
        _renderEnvio();
      });
      lista.appendChild(card);
    });
  }

  $('cm-btn-add-envio').addEventListener('click', () => {
    _envios.push({ etiqueta:'', domicilio:'', localidad:'', provincia:'', referencia:'' });
    _renderEnvio();
    $('cm-lista-envio').lastElementChild?.querySelector('input')?.focus();
  });

  /* ── reset / poblar ──────────────────────────────── */
  function _reset() {
    const f = $('cm-form');
    f.reset();
    f.querySelector('[name=_id]').value   = '';
    f.querySelector('[name=_tipo]').value = _tipo;
    $('cm-chk-cc').checked     = false;
    $('cm-chk-activo').checked = true;
    $('cm-chk-remito').checked = false;
    _envios = [];
    _renderEnvio();
    _syncPlazoPreset();
  }

  function _poblar(r) {
    const f   = $('cm-form');
    const set = (name, v) => { const el = f.querySelector(`[name=${name}]`); if (el) el.value = v ?? ''; };
    const chk = (name, v) => { const el = f.querySelector(`[name=${name}]`); if (el) el.checked = !!v; };

    f.querySelector('[name=_id]').value   = r.id;
    f.querySelector('[name=_tipo]').value = _tipo;
    ['nombre','cuit','condicion_iva','email','telefono','domicilio','localidad','provincia','observaciones'].forEach(k => set(k, r[k]));
    set('limite_credito', r.limite_credito ?? 0);
    set('descuento_extra', r.descuento_extra ?? 0);
    set('plazo_pago_dias', r.plazo_pago_dias ?? '');
    _syncPlazoPreset();
    set('lista_precio_id', r.lista_precio_id ?? '');
    chk('cc_habilitada', r.cc_habilitada);
    chk('activo', r.activo !== false);
    chk('solo_remito', r.solo_remito);
    _envios = Array.isArray(r.domicilios_envio) ? JSON.parse(JSON.stringify(r.domicilios_envio)) : [];
    _renderEnvio();
  }

  function _formToObj() {
    const f   = $('cm-form');
    const fd  = new FormData(f);
    const obj = {};
    for (const [k, v] of fd.entries()) {
      if (k === '_id' || k === '_tipo') continue;
      obj[k] = v;
    }
    ['cc_habilitada','activo','solo_remito'].forEach(k => {
      obj[k] = !!f.querySelector(`[name=${k}]`)?.checked;
    });
    ['limite_credito','descuento_extra'].forEach(k => { if (k in obj) obj[k] = parseFloat(obj[k]) || 0; });
    if ('plazo_pago_dias' in obj) obj.plazo_pago_dias = obj.plazo_pago_dias !== '' ? parseInt(obj.plazo_pago_dias) : null;
    if ('lista_precio_id' in obj) obj.lista_precio_id = obj.lista_precio_id !== '' ? parseInt(obj.lista_precio_id) : null;
    if (_tipo === 'clientes') obj.domicilios_envio = _envios.filter(d => d.domicilio?.trim());
    return obj;
  }

  /* ── abrir / cerrar ──────────────────────────────── */
  function _cerrar() { $('cm-overlay').classList.add('cm-oculto'); }

  async function _abrir(id, tipo, callbacks = {}) {
    _tipo       = tipo || 'clientes';
    _cbGuardar  = callbacks.onGuardado  || null;
    _cbEliminar = callbacks.onEliminado || null;

    _configurar(_tipo);
    _reset();

    const esCliente = _tipo === 'clientes';
    $('cm-titulo').textContent = id
      ? (esCliente ? 'Editar cliente' : 'Editar proveedor')
      : (esCliente ? 'Nuevo cliente'  : 'Nuevo proveedor');

    if (esCliente) await _cargarListas();

    if (id) {
      const r = await fetch(`${API}/${_tipo}/${id}`);
      if (!r.ok) { alert('Error al cargar los datos.'); return; }
      _poblar(await r.json());
      $('cm-btn-eliminar').style.display = '';
      $('cm-btn-eliminar').dataset.id    = id;
    } else {
      $('cm-btn-eliminar').style.display = 'none';
    }

    $('cm-overlay').classList.remove('cm-oculto');
    setTimeout(() => $('cm-form').querySelector('[name=nombre]')?.focus(), 60);
  }

  /* ── guardar ─────────────────────────────────────── */
  $('cm-btn-guardar').addEventListener('click', async () => {
    const f    = $('cm-form');
    const id   = f.querySelector('[name=_id]').value;
    const body = _formToObj();
    const url    = id ? `${API}/${_tipo}/${id}` : `${API}/${_tipo}`;
    const method = id ? 'PUT' : 'POST';

    $('cm-btn-guardar').disabled    = true;
    $('cm-btn-guardar').textContent = 'Guardando…';

    const resp = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await resp.json();

    $('cm-btn-guardar').disabled    = false;
    $('cm-btn-guardar').textContent = 'Guardar';

    if (!resp.ok) { alert(data.error || 'Error al guardar.'); return; }
    _cerrar();
    if (_cbGuardar) _cbGuardar(data);
  });

  /* ── eliminar ────────────────────────────────────── */
  $('cm-btn-eliminar').addEventListener('click', () => {
    const id     = $('cm-btn-eliminar').dataset.id;
    const nombre = $('cm-form').querySelector('[name=nombre]').value;
    $('cm-del-texto').textContent = `¿Eliminar "${nombre}"? Esta acción no se puede deshacer.`;
    $('cm-overlay').classList.add('cm-oculto');
    $('cm-overlay-del').classList.remove('cm-oculto');
    $('cm-del-si').dataset.id = id;
  });

  const _cancelarDel = () => {
    $('cm-overlay-del').classList.add('cm-oculto');
    $('cm-overlay').classList.remove('cm-oculto');
  };
  $('cm-del-cerrar').addEventListener('click', _cancelarDel);
  $('cm-del-no').addEventListener('click',     _cancelarDel);

  $('cm-del-si').addEventListener('click', async () => {
    const id   = $('cm-del-si').dataset.id;
    const resp = await fetch(`${API}/${_tipo}/${id}`, { method: 'DELETE' });
    const data = await resp.json();
    $('cm-overlay-del').classList.add('cm-oculto');
    if (!resp.ok) { alert(data.error || 'No se pudo eliminar.'); return; }
    if (_cbEliminar) _cbEliminar(id);
  });

  $('cm-cerrar').addEventListener('click',       _cerrar);
  $('cm-btn-cancelar').addEventListener('click', _cerrar);

  /* ── API pública ─────────────────────────────────── */
  return {
    abrir: (id, tipo, callbacks) => _abrir(id, tipo, callbacks),
    nuevo: (tipo, callbacks)     => _abrir(null, tipo, callbacks),
  };
})();
</script>
