<script lang="ts">
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';

	type Vendedor = {
		id: number;
		nombre: string;
		activo: boolean;
		meta_monto: number | null;
		meta_desde: string | null;
		meta_hasta: string | null;
	};

	let datos = $state<Vendedor[]>([]);
	let busqueda = $state('');
	let mostrarInactivos = $state(false);

	let modalAbierto = $state(false);
	let editId = $state<number | null>(null);
	let fNombre = $state('');
	let fActivo = $state(true);
	let fMetaMonto = $state('');
	let fMetaDesde = $state('');
	let fMetaHasta = $state('');

	function fmt(n: number | null) {
		if (n == null) return '—';
		return '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
	}
	function fmtFecha(d: string | null) {
		if (!d) return '';
		const p = d.split('-');
		return `${p[2]}/${p[1]}/${p[0]}`;
	}
	function periodo(v: Vendedor) {
		if (!v.meta_desde && !v.meta_hasta) return '—';
		return `${fmtFecha(v.meta_desde) || '?'} — ${fmtFecha(v.meta_hasta) || '?'}`;
	}

	async function cargar() {
		const res = await api(`/vendedores${mostrarInactivos ? '?todos=1' : ''}`);
		datos = await res.json();
	}

	const filas = $derived(datos.filter((v) => v.nombre.toLowerCase().includes(busqueda.toLowerCase())));

	function abrirModal(v: Vendedor | null) {
		editId = v ? v.id : null;
		fNombre = v ? v.nombre : '';
		fActivo = v ? v.activo : true;
		fMetaMonto = v?.meta_monto != null ? String(v.meta_monto) : '';
		fMetaDesde = v?.meta_desde || '';
		fMetaHasta = v?.meta_hasta || '';
		modalAbierto = true;
	}
	function cerrarModal() {
		modalAbierto = false;
		editId = null;
	}

	async function guardar() {
		const nombre = fNombre.trim();
		if (!nombre) {
			toast_('El nombre es requerido.', 'err');
			return;
		}
		const payload = {
			nombre,
			activo: fActivo,
			meta_monto: fMetaMonto !== '' ? parseFloat(fMetaMonto) : null,
			meta_desde: fMetaDesde || null,
			meta_hasta: fMetaHasta || null
		};
		const url = editId ? `/vendedores/${editId}` : '/vendedores';
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
		toast_('Vendedor guardado', 'ok');
		cargar();
	}

	async function desactivar(v: Vendedor) {
		const ok = await confirmar(`¿Desactivar a ${v.nombre}?\nNo se eliminarán sus ventas históricas.`, {
			titulo: 'Desactivar vendedor',
			confirmLabel: 'Desactivar',
			danger: true
		});
		if (!ok) return;
		await api(`/vendedores/${v.id}`, { method: 'DELETE' });
		toast_('Vendedor desactivado', 'ok');
		cargar();
	}

	cargar();
</script>

<svelte:head>
	<title>Logos — Vendedores</title>
</svelte:head>

<div class="page-body">
	<div class="vnd-inner">
		<div class="vnd-header">
			<h1 class="vnd-title">Vendedores</h1>
			<button class="neo-btn neo-btn-add" onclick={() => abrirModal(null)}>+ Nuevo vendedor</button>
		</div>

		<div class="vnd-toolbar">
			<input class="t-input vnd-search" type="search" placeholder="Buscar vendedor…" bind:value={busqueda} />
			<span class="vnd-count">{filas.length} vendedor{filas.length !== 1 ? 'es' : ''}</span>
			<label class="vnd-chk-todos">
				<input type="checkbox" bind:checked={mostrarInactivos} onchange={cargar} /> Mostrar inactivos
			</label>
		</div>

		<div class="vnd-table-wrap">
			<table class="vnd-tbl">
				<colgroup>
					<col class="c-nom" /><col class="c-meta" /><col class="c-per" /><col class="c-est" /><col
						class="c-act"
					/>
				</colgroup>
				<thead>
					<tr>
						<th>Nombre</th>
						<th>Meta ($)</th>
						<th>Período meta</th>
						<th>Estado</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					{#each filas as v (v.id)}
						<tr>
							<td><strong>{v.nombre}</strong></td>
							<td>{fmt(v.meta_monto)}</td>
							<td style="font-size:11px;color:var(--neo-text-2)">{periodo(v)}</td>
							<td><span class={v.activo ? 'badge-on' : 'badge-off'}>{v.activo ? 'Activo' : 'Inactivo'}</span></td>
							<td>
								<button class="btn-icon" title="Editar" onclick={() => abrirModal(v)}>✏️</button>
								{#if v.activo}
									<button class="btn-icon danger" title="Desactivar" onclick={() => desactivar(v)}>🔕</button>
								{/if}
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
			{#if !filas.length}
				<div class="empty-msg">No hay vendedores cargados.</div>
			{/if}
		</div>
	</div>
</div>

{#if modalAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModal()}>
		<div class="modal">
			<p class="modal-title">{editId ? 'Editar vendedor' : 'Nuevo vendedor'}</p>
			<div class="form-row">
				<label class="form-label" for="f-nombre">Nombre</label>
				<input id="f-nombre" class="t-input" type="text" placeholder="Ej: Juan García" maxlength="255" bind:value={fNombre} />
			</div>
			<div class="form-row">
				<label class="form-label" for="f-activo">Estado</label>
				<div class="toggle-wrap">
					<label class="toggle"
						><input id="f-activo" type="checkbox" bind:checked={fActivo} /><span class="toggle-slider"
						></span></label
					>
					<span class="toggle-lbl">{fActivo ? 'Activo' : 'Inactivo'}</span>
				</div>
			</div>
			<div class="form-row">
				<label class="form-label" for="f-meta-monto"
					>Meta de venta <span style="font-weight:400;text-transform:none">(opcional)</span></label
				>
				<input
					id="f-meta-monto"
					class="t-input"
					type="number"
					min="0"
					step="0.01"
					placeholder="Ej: 500000"
					bind:value={fMetaMonto}
				/>
				<p class="form-hint">Monto a alcanzar en el período configurado abajo.</p>
			</div>
			<div class="form-row form-row-2">
				<div>
					<label class="form-label" for="f-meta-desde">Desde</label>
					<input id="f-meta-desde" class="t-input" type="date" bind:value={fMetaDesde} />
				</div>
				<div>
					<label class="form-label" for="f-meta-hasta">Hasta</label>
					<input id="f-meta-hasta" class="t-input" type="date" bind:value={fMetaHasta} />
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
	.vnd-inner {
		max-width: 860px;
		width: 100%;
		margin: 0 auto;
		padding: 20px 24px;
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e2);
	}
	.vnd-header {
		display: flex;
		align-items: center;
		gap: 14px;
		margin-bottom: 20px;
	}
	.vnd-title {
		font-size: 18px;
		font-weight: 700;
		color: var(--neo-text);
		margin: 0;
		flex: 1;
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
	.vnd-toolbar {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 14px;
		flex-wrap: wrap;
	}
	.vnd-search {
		flex: 1;
		min-width: 160px;
		max-width: 280px;
	}
	.vnd-count {
		font-size: 12px;
		color: var(--neo-text-3);
	}
	.vnd-chk-todos {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
		color: var(--neo-text-2);
		cursor: pointer;
		margin-left: auto;
	}
	table.vnd-tbl {
		width: 100%;
		border-collapse: collapse;
	}
	table.vnd-tbl .c-meta {
		width: 160px;
	}
	table.vnd-tbl .c-per {
		width: 180px;
	}
	table.vnd-tbl .c-est {
		width: 80px;
	}
	table.vnd-tbl .c-act {
		width: 90px;
	}
	table.vnd-tbl th {
		padding: 8px 10px;
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	table.vnd-tbl td {
		padding: 9px 10px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		color: var(--neo-text);
		vertical-align: middle;
	}
	table.vnd-tbl tr:last-child td {
		border-bottom: none;
	}
	table.vnd-tbl tr:hover td {
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
		width: 440px;
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
	.form-row-2 {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 10px;
	}
	.form-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 4px;
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
