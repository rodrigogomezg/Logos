<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';

	type Fila = { id: number; nombre: string; productos: number; regla_precio_id: number | null };
	type ReglaPrecio = { id: number; nombre: string; porcentaje_recargo: number };

	let tipoActual = $state<'rubros' | 'marcas'>(page.url.searchParams.get('tipo') === 'marcas' ? 'marcas' : 'rubros');
	let filas = $state<Fila[]>([]);
	let cargando = $state(true);
	let busqueda = $state('');
	let busqTimer: ReturnType<typeof setTimeout>;
	let selIds = $state<Set<number>>(new Set());
	let renombrando = $state<number | null>(null);
	let renValor = $state('');
	let bulkAbierto = $state(false);
	let bulkTxt = $state('');
	let reglasPrecio = $state<ReglaPrecio[]>([]);
	let reglasCargadas = false;

	function cambiarTipo(tipo: 'rubros' | 'marcas') {
		tipoActual = tipo;
		busqueda = '';
		selIds = new Set();
		goto(`/taxonomias?tipo=${tipo}`, { replaceState: true, noScroll: true, keepFocus: true });
		cargar();
	}

	async function cargarReglasPrecio() {
		if (reglasCargadas) return;
		const r = await api('/reglas-precio?activas=1');
		reglasPrecio = r.ok ? await r.json() : [];
		reglasCargadas = true;
	}

	async function cargar(q = '') {
		cargando = true;
		const [r] = await Promise.all([
			api(`/taxonomias?tipo=${tipoActual}${q ? '&q=' + encodeURIComponent(q) : ''}`),
			cargarReglasPrecio()
		]);
		filas = r.ok ? await r.json() : [];
		cargando = false;
	}

	function onBuscar(v: string) {
		busqueda = v;
		clearTimeout(busqTimer);
		busqTimer = setTimeout(() => cargar(v), 260);
	}

	function toggleSel(id: number, checked: boolean) {
		const s = new Set(selIds);
		checked ? s.add(id) : s.delete(id);
		selIds = s;
	}
	function toggleAll(checked: boolean) {
		selIds = checked ? new Set(filas.map((f) => f.id)) : new Set();
	}

	function iniciarRename(f: Fila) {
		renombrando = f.id;
		renValor = f.nombre;
	}
	function cancelarRename() {
		renombrando = null;
	}
	async function confirmarRename(id: number) {
		const nombre = renValor.trim();
		if (!nombre) return;
		const r = await api(`/taxonomias/${id}`, {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ tipo: tipoActual, nombre })
		});
		const data = await r.json();
		if (!r.ok) {
			toast_(data.error || 'Error al renombrar', 'err');
			return;
		}
		renombrando = null;
		toast_('Renombrado correctamente', 'ok');
		cargar(busqueda);
	}

	async function cambiarRegla(f: Fila, reglaId: string) {
		const r = await api(`/taxonomias/${f.id}`, {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ tipo: tipoActual, nombre: f.nombre, regla_precio_id: reglaId ? parseInt(reglaId) : null })
		});
		const data = await r.json();
		if (!r.ok) {
			toast_(data.error || 'Error al asignar la regla', 'err');
			cargar(busqueda);
			return;
		}
		toast_('Regla actualizada — los productos ya recalcularon su precio', 'ok');
		cargar(busqueda);
	}

	let nuevoNombre = $state('');
	async function crearUno() {
		const nombre = nuevoNombre.trim();
		if (!nombre) return;
		const r = await api('/taxonomias', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ tipo: tipoActual, nombre })
		});
		const data = await r.json();
		if (!r.ok) {
			toast_(data.error || 'Error al agregar', 'err');
			return;
		}
		nuevoNombre = '';
		toast_('Agregado correctamente', 'ok');
		cargar(busqueda);
	}

	async function bulkCrear() {
		const nombres = bulkTxt
			.split('\n')
			.map((s) => s.trim())
			.filter(Boolean);
		if (!nombres.length) return;
		const r = await api('/taxonomias/bulk', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ tipo: tipoActual, nombres })
		});
		const data = await r.json();
		if (!r.ok) {
			toast_(data.error || 'Error', 'err');
			return;
		}
		bulkTxt = '';
		bulkAbierto = false;
		toast_('Creados: ' + data.creados + (data.omitidos ? ', omitidos (ya existen): ' + data.omitidos : ''), 'ok');
		cargar(busqueda);
	}

	async function eliminarUno(f: Fila) {
		const aviso =
			f.productos > 0
				? `"${f.nombre}" está asignado a ${f.productos} producto${f.productos > 1 ? 's' : ''}. Se quitará de esos productos.`
				: `¿Eliminar "${f.nombre}"?`;
		const ok = await confirmar(aviso, { confirmLabel: 'Eliminar', danger: true });
		if (!ok) return;
		const r = await api(`/taxonomias/${f.id}?tipo=${tipoActual}`, { method: 'DELETE' });
		if (!r.ok) {
			const d = await r.json();
			toast_(d.error || 'Error', 'err');
			return;
		}
		toast_('Eliminado', 'ok');
		const s = new Set(selIds);
		s.delete(f.id);
		selIds = s;
		cargar(busqueda);
	}

	async function bulkEliminar() {
		const ids = [...selIds];
		if (!ids.length) return;
		const afectados = filas.filter((f) => ids.includes(f.id) && f.productos > 0);
		let msg = `¿Eliminar ${ids.length} ${ids.length === 1 ? 'elemento' : 'elementos'}?`;
		if (afectados.length) msg += ` ${afectados.length} de ellos tienen productos asignados (se quitará la referencia).`;

		const ok = await confirmar(msg, { confirmLabel: 'Eliminar', danger: true });
		if (!ok) return;
		const r = await api('/taxonomias/bulk-eliminar', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ tipo: tipoActual, ids })
		});
		const data = await r.json();
		if (!r.ok) {
			toast_(data.error || 'Error', 'err');
			return;
		}
		selIds = new Set();
		toast_('Eliminados: ' + data.eliminados, 'ok');
		cargar(busqueda);
	}

	cargar();
</script>

<svelte:head>
	<title>Logos — Rubros y Marcas</title>
</svelte:head>

<main class="page-body">
	<div class="tax-inner">
		<div class="tax-header">
			<h1 class="tax-title">{tipoActual === 'rubros' ? 'Rubros' : 'Marcas'}</h1>
			<div class="tax-tabs">
				<button class="tax-tab" class:active={tipoActual === 'rubros'} onclick={() => cambiarTipo('rubros')}>Rubros</button>
				<button class="tax-tab" class:active={tipoActual === 'marcas'} onclick={() => cambiarTipo('marcas')}>Marcas</button>
			</div>
			<div class="tax-spacer"></div>
			<button class="btn btn-sec btn-sm" onclick={() => (bulkAbierto = !bulkAbierto)}>+ Agregar varios</button>
		</div>

		{#if bulkAbierto}
			<div class="tax-bulk-panel open">
				<strong style="font-size:13px">Agregar varios de una vez</strong>
				<p class="tax-bulk-hint">Escribí uno por línea. Los duplicados se ignoran.</p>
				<textarea class="t-input" placeholder={'Electrónica\nRopa\nCalzado'} bind:value={bulkTxt}></textarea>
				<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
					<button class="btn btn-sec btn-sm" onclick={() => (bulkAbierto = false)}>Cancelar</button>
					<button class="btn btn-ok btn-sm" onclick={bulkCrear}>Agregar</button>
				</div>
			</div>
		{/if}

		<div class="tax-toolbar">
			<input
				type="search"
				class="t-input tax-search"
				placeholder="Buscar…"
				value={busqueda}
				oninput={(e) => onBuscar((e.target as HTMLInputElement).value)}
			/>
			<span class="tax-count">{filas.length ? filas.length + ' ' + (filas.length === 1 ? 'elemento' : 'elementos') : ''}</span>
		</div>

		{#if selIds.size > 0}
			<div class="tax-sel-bar visible">
				<span class="tax-sel-txt">{selIds.size} {selIds.size === 1 ? 'elemento seleccionado' : 'elementos seleccionados'}</span>
				<button class="btn btn-danger btn-sm" onclick={bulkEliminar}>Eliminar seleccionados</button>
			</div>
		{/if}

		<div class="tax-table-wrap">
			<table class="tax-tbl">
				<colgroup>
					<col class="c-chk" /><col class="c-nom" /><col class="c-prod" /><col class="c-regla" /><col class="c-act" />
				</colgroup>
				<thead>
					<tr>
						<th
							><input
								type="checkbox"
								title="Seleccionar todos"
								checked={filas.length > 0 && selIds.size === filas.length}
								onchange={(e) => toggleAll((e.target as HTMLInputElement).checked)}
							/></th
						>
						<th>Nombre</th>
						<th style="text-align:center">Productos</th>
						<th>Regla de precio</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{#if cargando}
						<tr><td colspan="5" class="tax-loading">Cargando…</td></tr>
					{:else if !filas.length}
						<tr><td colspan="5" class="tax-empty">No hay elementos. Agregá el primero.</td></tr>
					{:else}
						{#each filas as f (f.id)}
							{#if renombrando === f.id}
								<tr>
									<td
										><input
											type="checkbox"
											checked={selIds.has(f.id)}
											onchange={(e) => toggleSel(f.id, (e.target as HTMLInputElement).checked)}
										/></td
									>
									<td colspan="4">
										<div class="tax-rename-form">
											<input
												type="text"
												class="t-input tax-rename-inp"
												maxlength="100"
												bind:value={renValor}
												onkeydown={(e) => {
													if (e.key === 'Enter') confirmarRename(f.id);
													if (e.key === 'Escape') cancelarRename();
												}}
											/>
											<button class="btn btn-ok btn-xs" onclick={() => confirmarRename(f.id)}>✓</button>
											<button class="btn btn-sec btn-xs" onclick={cancelarRename}>✕</button>
										</div>
									</td>
								</tr>
							{:else}
								<tr>
									<td
										><input
											type="checkbox"
											checked={selIds.has(f.id)}
											onchange={(e) => toggleSel(f.id, (e.target as HTMLInputElement).checked)}
										/></td
									>
									<td class="td-nom">{f.nombre}</td>
									<td class="td-prod">{f.productos || 0}</td>
									<td>
										<select class="t-input" value={f.regla_precio_id ?? ''} onchange={(e) => cambiarRegla(f, (e.target as HTMLSelectElement).value)}>
											<option value="">— Sin regla —</option>
											{#each reglasPrecio as rg (rg.id)}
												<option value={rg.id}>{rg.nombre} (+{rg.porcentaje_recargo}%)</option>
											{/each}
										</select>
									</td>
									<td class="td-act">
										<button class="btn btn-sec btn-xs" title="Renombrar" onclick={() => iniciarRename(f)}>✎</button>
										<button class="btn btn-danger btn-xs" title="Eliminar" onclick={() => eliminarUno(f)}>✕</button>
									</td>
								</tr>
							{/if}
						{/each}
					{/if}
				</tbody>
				<tfoot>
					<tr class="tax-add-row">
						<td></td>
						<td colspan="4">
							<div class="tax-add-form">
								<input
									type="text"
									class="t-input tax-add-inp"
									placeholder="Nombre nuevo…"
									maxlength="100"
									bind:value={nuevoNombre}
									onkeydown={(e) => e.key === 'Enter' && crearUno()}
								/>
								<button class="btn btn-ok btn-sm" onclick={crearUno}>Agregar</button>
							</div>
						</td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
</main>

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
	.tax-inner {
		max-width: 900px;
		width: 100%;
		margin: 0 auto;
		padding: 20px 24px;
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e2);
		overflow-y: auto;
	}
	.tax-header {
		display: flex;
		align-items: center;
		gap: 14px;
		margin-bottom: 20px;
		flex-wrap: wrap;
	}
	.tax-title {
		font-size: 18px;
		font-weight: 700;
		color: var(--neo-text);
		margin: 0;
	}
	.tax-tabs {
		display: flex;
		gap: 8px;
	}
	.tax-tab {
		padding: 6px 18px;
		border-radius: var(--neo-r-sm);
		border: none;
		cursor: pointer;
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.4px;
		text-transform: uppercase;
		background: var(--neo-bg);
		color: var(--neo-text-2);
		box-shadow: var(--neo-e1);
		font-family: inherit;
	}
	.tax-tab:hover {
		color: var(--neo-text);
		box-shadow: var(--neo-e2);
	}
	.tax-tab.active {
		background: var(--neo-accent);
		color: #fff;
		box-shadow: 4px 4px 10px var(--neo-accent-glow), -2px -2px 6px rgba(255, 255, 255, 0.25);
	}
	.tax-spacer {
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
	textarea.t-input {
		resize: vertical;
		min-height: 110px;
	}
	.tax-toolbar {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 14px;
		flex-wrap: wrap;
	}
	.tax-search {
		flex: 1;
		min-width: 160px;
		max-width: 280px;
	}
	.tax-count {
		font-size: 12px;
		color: var(--neo-text-3);
	}
	.tax-bulk-panel {
		padding: 14px 16px;
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-i1);
		margin-bottom: 16px;
	}
	.tax-bulk-panel textarea {
		margin: 8px 0;
	}
	.tax-bulk-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		margin: 0 0 8px;
	}
	.tax-table-wrap {
		overflow-x: auto;
	}
	table.tax-tbl {
		width: 100%;
		border-collapse: collapse;
		table-layout: fixed;
	}
	table.tax-tbl col.c-chk {
		width: 38px;
	}
	table.tax-tbl col.c-nom {
		width: auto;
	}
	table.tax-tbl col.c-prod {
		width: 110px;
	}
	table.tax-tbl col.c-regla {
		width: 220px;
	}
	table.tax-tbl col.c-act {
		width: 100px;
	}
	table.tax-tbl thead th {
		padding: 8px 10px;
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		border-bottom: 2px solid var(--borde);
		text-align: left;
	}
	table.tax-tbl tbody tr {
		border-bottom: 1px solid var(--borde);
	}
	table.tax-tbl tbody tr:hover {
		background: var(--primary-soft);
	}
	table.tax-tbl td {
		padding: 9px 10px;
		font-size: 13px;
		vertical-align: middle;
	}
	table.tax-tbl td.td-nom {
		font-weight: 500;
	}
	table.tax-tbl td.td-prod {
		color: var(--neo-text-3);
		text-align: center;
	}
	table.tax-tbl td.td-act {
		text-align: right;
		white-space: nowrap;
	}
	.tax-rename-form {
		display: flex;
		gap: 6px;
		align-items: center;
	}
	.tax-add-row td {
		padding: 8px 10px;
		border-top: 2px solid var(--borde);
	}
	.tax-add-form {
		display: flex;
		gap: 8px;
		align-items: center;
	}
	.tax-add-inp {
		flex: 1;
		min-width: 0;
	}
	.tax-sel-bar {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 8px 14px;
		background: var(--primary-soft);
		border-radius: var(--neo-r-sm);
		margin-bottom: 12px;
		font-size: 13px;
	}
	.tax-sel-txt {
		flex: 1;
	}
	.tax-empty,
	.tax-loading {
		text-align: center;
		padding: 48px 20px;
		color: var(--neo-text-3);
		font-size: 14px;
	}
	.btn-sm {
		padding: 5px 12px;
		font-size: 11px;
	}
	.btn-xs {
		padding: 4px 8px;
		font-size: 11px;
	}
	.btn {
		border: none;
		border-radius: var(--neo-r-xs);
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		cursor: pointer;
		font-family: inherit;
	}
	.btn-ok {
		background: var(--neo-accent);
		color: white;
	}
	.btn-sec {
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-e2);
	}
	.btn-danger {
		background: var(--neo-danger);
		color: white;
	}
</style>
