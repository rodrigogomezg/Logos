<script lang="ts">
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';

	type Regla = {
		id: number;
		nombre: string;
		porcentaje_recargo: number;
		activa: boolean;
	};

	let datos = $state<Regla[]>([]);
	let busqueda = $state('');
	let mostrarInactivas = $state(false);

	let modalAbierto = $state(false);
	let editId = $state<number | null>(null);
	let fNombre = $state('');
	let fPorcentaje = $state('');
	let fActiva = $state(true);

	async function cargar() {
		const res = await api(`/reglas-precio${mostrarInactivas ? '' : '?activas=1'}`);
		datos = await res.json();
	}

	const filas = $derived(datos.filter((r) => r.nombre.toLowerCase().includes(busqueda.toLowerCase())));

	function abrirModal(r: Regla | null) {
		editId = r ? r.id : null;
		fNombre = r ? r.nombre : '';
		fPorcentaje = r ? String(r.porcentaje_recargo) : '';
		fActiva = r ? r.activa : true;
		modalAbierto = true;
	}
	function cerrarModal() {
		modalAbierto = false;
		editId = null;
	}

	async function guardar() {
		const nombre = fNombre.trim();
		if (!nombre) {
			toast_('El nombre es requerido', 'err');
			return;
		}
		const pct = parseFloat(fPorcentaje);
		if (fPorcentaje === '' || isNaN(pct)) {
			toast_('Ingresá un porcentaje válido', 'err');
			return;
		}
		const payload = { nombre, porcentaje_recargo: pct, activa: fActiva };
		const url = editId ? `/reglas-precio/${editId}` : '/reglas-precio';
		const method = editId ? 'PUT' : 'POST';
		const res = await api(url, {
			method,
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		});
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al guardar', 'err');
			return;
		}
		cerrarModal();
		toast_('Regla guardada', 'ok');
		cargar();
	}

	async function eliminar(r: Regla) {
		const ok = await confirmar(`¿Eliminar la regla "${r.nombre}"?`, { danger: true, confirmLabel: 'Eliminar' });
		if (!ok) return;
		const res = await api(`/reglas-precio/${r.id}`, { method: 'DELETE' });
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'No se pudo eliminar', 'err');
			return;
		}
		toast_('Regla eliminada', 'ok');
		cargar();
	}

	cargar();
</script>

<svelte:head>
	<title>Logos — Reglas de precio</title>
</svelte:head>

<div class="page-body">
	<div class="rgp-inner">
		<div class="rgp-header">
			<h1 class="rgp-title">Reglas de precio</h1>
			<button class="neo-btn neo-btn-add" onclick={() => abrirModal(null)}>+ Nueva regla</button>
		</div>
		<p class="rgp-sub">
			Recargo % sobre el costo (con IVA incluido). El precio de venta se recalcula solo cada vez que cambia el
			costo. Se pueden asignar en cuatro lugares — a un producto individual o en bloque desde
			<strong>Productos</strong>, o a toda una <strong>marca</strong>, <strong>rubro</strong> o
			<strong>proveedor</strong> (ahí en su propia pantalla) — y esa regla se aplica sola a los productos que
			correspondan, incluidos los que se carguen después. Si un producto queda alcanzado por más de una a la
			vez, gana en este orden: <strong>regla propia del producto</strong> &gt; <strong>marca</strong> &gt;
			<strong>rubro</strong> &gt; <strong>proveedor</strong>.
		</p>

		<div class="rgp-toolbar">
			<input class="t-input rgp-search" type="search" placeholder="Buscar regla…" bind:value={busqueda} />
			<span class="rgp-count">{filas.length} regla{filas.length !== 1 ? 's' : ''}</span>
			<label class="rgp-chk-todos">
				<input type="checkbox" bind:checked={mostrarInactivas} onchange={cargar} /> Mostrar inactivas
			</label>
		</div>

		<div class="rgp-table-wrap">
			<table class="rgp-tbl">
				<colgroup><col class="c-nom" /><col class="c-pct" /><col class="c-est" /><col class="c-act" /></colgroup>
				<thead>
					<tr>
						<th>Nombre</th>
						<th>Recargo</th>
						<th>Estado</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					{#each filas as r (r.id)}
						<tr>
							<td><strong>{r.nombre}</strong></td>
							<td>{r.porcentaje_recargo >= 0 ? '+' : ''}{r.porcentaje_recargo.toLocaleString('es-AR', { maximumFractionDigits: 2 })}%</td>
							<td><span class={r.activa ? 'badge-on' : 'badge-off'}>{r.activa ? 'Activa' : 'Inactiva'}</span></td>
							<td class="rgp-act">
								<button class="btn-icon" title="Editar" onclick={() => abrirModal(r)}>✏️</button>
								<button class="btn-icon danger" title="Eliminar" onclick={() => eliminar(r)}>🗑️</button>
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
			{#if !filas.length}
				<div class="empty-msg">No hay reglas de precio cargadas.</div>
			{/if}
		</div>
	</div>
</div>

{#if modalAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModal()}>
		<div class="modal">
			<p class="modal-title">{editId ? 'Editar regla de precio' : 'Nueva regla de precio'}</p>
			<div class="form-row">
				<label class="form-label" for="f-nombre">Nombre</label>
				<input id="f-nombre" class="t-input" type="text" placeholder="Ej: Recargo importados" maxlength="100" bind:value={fNombre} />
			</div>
			<div class="form-row">
				<label class="form-label" for="f-porcentaje">Recargo sobre el costo</label>
				<div class="pct-wrap">
					<input id="f-porcentaje" class="t-input" type="number" step="0.01" placeholder="Ej: 40" bind:value={fPorcentaje} />
					<span class="pct-sym">%</span>
				</div>
				<p class="form-hint">precio de venta = costo × (1 + recargo / 100)</p>
			</div>
			<div class="form-row">
				<label class="form-label" for="f-activa">Estado</label>
				<div class="toggle-wrap">
					<label class="toggle"><input id="f-activa" type="checkbox" bind:checked={fActiva} /><span class="toggle-slider"></span></label>
					<span class="toggle-lbl">{fActiva ? 'Activa' : 'Inactiva'}</span>
				</div>
			</div>
			<div class="modal-footer">
				<button class="neo-btn neo-btn-ghost" onclick={cerrarModal}>Cancelar</button>
				<button class="neo-btn neo-btn-primary" onclick={guardar}>Guardar</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.page-body {
		flex: 1;
		display: flex;
		flex-direction: column;
		overflow: hidden;
		padding: 16px;
		gap: 12px;
		background: var(--neo-bg-deep);
	}
	.rgp-inner {
		max-width: 780px;
		width: 100%;
		margin: 0 auto;
		padding: 20px 24px;
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e2);
	}
	.rgp-header {
		display: flex;
		align-items: center;
		gap: 14px;
		margin-bottom: 8px;
	}
	.rgp-title {
		font-size: 18px;
		font-weight: 700;
		color: var(--neo-text);
		margin: 0;
		flex: 1;
	}
	.rgp-sub {
		font-size: 12px;
		color: var(--neo-text-3);
		margin: 0 0 20px;
	}
	.t-input {
		padding: 7px 10px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		box-shadow: var(--neo-i1);
		color: var(--neo-text);
		width: 100%;
		box-sizing: border-box;
	}
	.t-input:focus {
		box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent);
	}
	.rgp-toolbar {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 14px;
		flex-wrap: wrap;
	}
	.rgp-search {
		flex: 1;
		min-width: 160px;
		max-width: 280px;
	}
	.rgp-count {
		font-size: 12px;
		color: var(--neo-text-3);
	}
	.rgp-chk-todos {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		color: var(--neo-text-2);
		cursor: pointer;
		margin-left: auto;
	}
	table.rgp-tbl {
		width: 100%;
		border-collapse: collapse;
	}
	table.rgp-tbl .c-pct {
		width: 140px;
	}
	table.rgp-tbl .c-est {
		width: 90px;
	}
	table.rgp-tbl .c-act {
		width: 90px;
	}
	/* Sin esto los dos <button> (inline, ancho angosto de 90px) se partían a
	   dos renglones en vez de quedar uno al lado del otro (caso real 15/08/2026). */
	.rgp-act {
		display: flex;
		align-items: center;
		gap: 6px;
		white-space: nowrap;
	}
	table.rgp-tbl th {
		padding: 8px 10px;
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	table.rgp-tbl td {
		padding: 9px 10px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		color: var(--neo-text);
		vertical-align: middle;
	}
	table.rgp-tbl tr:last-child td {
		border-bottom: none;
	}
	table.rgp-tbl tr:hover td {
		background: var(--color-bg-alt);
	}
	.badge-on {
		display: inline-block;
		padding: 2px 8px;
		border-radius: var(--neo-r-pill);
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		background: rgba(46, 204, 113, 0.12);
		color: #27ae60;
	}
	.badge-off {
		display: inline-block;
		padding: 2px 8px;
		border-radius: var(--neo-r-pill);
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.btn-icon {
		padding: 5px 8px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-bg);
		cursor: pointer;
		color: var(--neo-text-2);
		box-shadow: var(--neo-e1);
		font-size: 13px;
	}
	.btn-icon:hover {
		box-shadow: var(--neo-e2);
		color: var(--neo-accent);
	}
	.btn-icon.danger:hover {
		color: var(--neo-danger);
	}
	.overlay {
		position: fixed;
		inset: 0;
		z-index: 200;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.modal {
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		padding: 28px 28px 24px;
		width: 400px;
		max-width: 95vw;
		box-shadow: 0 8px 32px rgba(0, 0, 0, 0.22);
	}
	.modal-title {
		font-size: 16px;
		font-weight: 700;
		margin: 0 0 20px;
		color: var(--neo-text);
	}
	.form-row {
		margin-bottom: 14px;
	}
	.form-label {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		display: block;
		margin-bottom: 5px;
	}
	.form-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 4px;
	}
	.pct-wrap {
		position: relative;
	}
	.pct-wrap .t-input {
		padding-right: 28px;
	}
	.pct-sym {
		position: absolute;
		right: 10px;
		top: 50%;
		transform: translateY(-50%);
		font-size: 13px;
		color: var(--neo-text-3);
		pointer-events: none;
	}
	.toggle-wrap {
		display: flex;
		align-items: center;
		gap: 8px;
	}
	.toggle-lbl {
		font-size: 13px;
		color: var(--neo-text-2);
	}
	.toggle {
		position: relative;
		width: 36px;
		height: 20px;
	}
	.toggle input {
		opacity: 0;
		width: 0;
		height: 0;
	}
	.toggle-slider {
		position: absolute;
		inset: 0;
		border-radius: 10px;
		background: var(--color-bg-alt);
		cursor: pointer;
	}
	.toggle-slider::before {
		content: '';
		position: absolute;
		width: 14px;
		height: 14px;
		left: 3px;
		top: 3px;
		border-radius: 50%;
		background: #fff;
		box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
	}
	.toggle input:checked + .toggle-slider {
		background: var(--neo-accent);
	}
	.toggle input:checked + .toggle-slider::before {
		transform: translateX(16px);
	}
	.modal-footer {
		display: flex;
		justify-content: flex-end;
		gap: 10px;
		margin-top: 22px;
	}
	.neo-btn {
		padding: 8px 20px;
		border: none;
		border-radius: var(--neo-r-sm);
		cursor: pointer;
		font-size: 13px;
		font-weight: 700;
		font-family: inherit;
	}
	.neo-btn-primary {
		background: var(--neo-accent);
		color: #fff;
		box-shadow: 3px 3px 8px var(--neo-accent-glow), -2px -2px 5px rgba(255, 255, 255, 0.2);
	}
	.neo-btn-primary:hover {
		opacity: 0.9;
	}
	.neo-btn-ghost {
		background: var(--neo-bg);
		color: var(--neo-text-2);
		box-shadow: var(--neo-e1);
	}
	.neo-btn-ghost:hover {
		box-shadow: var(--neo-e2);
		color: var(--neo-text);
	}
	.neo-btn-add {
		background: var(--neo-accent);
		color: #fff;
		font-size: 12px;
		font-weight: 700;
		box-shadow: 3px 3px 8px var(--neo-accent-glow), -2px -2px 5px rgba(255, 255, 255, 0.2);
	}
	.neo-btn-add:hover {
		opacity: 0.9;
	}
	.empty-msg {
		text-align: center;
		padding: 40px 0;
		color: var(--neo-text-3);
		font-size: 13px;
	}
</style>
