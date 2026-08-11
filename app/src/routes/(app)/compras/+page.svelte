<script lang="ts">
	import { goto } from '$app/navigation';
	import { api } from '$lib/api';
	import { leerSesion } from '$lib/session';
	import { toast_ } from '$lib/toast';

	type Compra = {
		id: number;
		numero: number;
		fecha: string;
		proveedor_nombre: string | null;
		tipo_comprobante: string;
		numero_comprobante: string | null;
		tipo_pago: string;
		total: number;
		estado: string;
	};

	const TIPO_COMPROBANTE_LBL: Record<string, string> = { factura_a: 'Factura A', factura_b: 'Factura B', remito: 'Remito' };
	const TIPO_PAGO_LBL: Record<string, string> = {
		efectivo: 'Efectivo',
		transferencia: 'Transferencia',
		tarjeta: 'Tarjeta',
		cc: 'Cta. Corriente',
		mixto: 'Mixto'
	};

	function fmtFecha(d: Date) {
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	function fmt(n: number) {
		return '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	const sesion = leerSesion();
	const hoy = new Date();
	const hace30 = new Date();
	hace30.setDate(hace30.getDate() - 30);

	let desde = $state(fmtFecha(hace30));
	let hasta = $state(fmtFecha(hoy));
	let filtroProveedor = $state('');
	let sucursales = $state<{ id: number; nombre: string }[]>([]);
	let sucursalFiltro = $state('');
	let compras = $state<Compra[]>([]);
	let cargando = $state(true);
	let anulandoId = $state<number | null>(null);
	let procesandoAnular = $state(false);

	async function initSucursales() {
		if (sesion?.rol === 'admin') {
			const r = await api('/sucursales');
			if (r.ok) sucursales = await r.json();
		} else {
			sucursales = sesion?.sucursales || [];
		}
	}

	async function cargar() {
		cargando = true;
		const params = new URLSearchParams();
		if (desde) params.set('fecha_desde', desde);
		if (hasta) params.set('fecha_hasta', hasta);
		params.set('limit', '200');
		if (sucursalFiltro) params.set('sucursal_id', sucursalFiltro);

		const res = await api(`/compras?${params.toString()}`);
		let data: Compra[] = res.ok ? await res.json() : [];

		const filtro = filtroProveedor.trim().toLowerCase();
		if (filtro) {
			data = data.filter((c) => (c.proveedor_nombre || '').toLowerCase().includes(filtro));
		}
		compras = data;
		cargando = false;
	}

	function irANueva() {
		goto('/compras-nueva');
	}
	function irAEditar(id: number) {
		goto(`/compras-nueva?id=${id}`);
	}

	function pedirAnular(id: number) {
		anulandoId = id;
	}
	function cancelarAnular() {
		anulandoId = null;
	}
	async function confirmarAnular(id: number) {
		procesandoAnular = true;
		const res = await api(`/compras/${id}`, { method: 'DELETE' });
		procesandoAnular = false;
		if (!res.ok) {
			const data = await res.json();
			toast_(data.error || 'Error al anular', 'err');
			anulandoId = null;
			return;
		}
		anulandoId = null;
		cargar();
	}

	(async () => {
		await initSucursales();
		cargar();
	})();
</script>

<svelte:head>
	<title>Logos — Compras</title>
</svelte:head>

<div class="filtros-bar">
	<div>
		<label for="f-desde">Desde</label>
		<input id="f-desde" type="date" bind:value={desde} />
	</div>
	<div>
		<label for="f-hasta">Hasta</label>
		<input id="f-hasta" type="date" bind:value={hasta} />
	</div>
	<div>
		<label for="f-proveedor">Proveedor</label>
		<input id="f-proveedor" type="text" placeholder="Nombre del proveedor..." autocomplete="off" bind:value={filtroProveedor} />
	</div>
	{#if sucursales.length > 1}
		<div>
			<label for="f-sucursal">Sucursal</label>
			<select id="f-sucursal" bind:value={sucursalFiltro}>
				<option value="">Todas</option>
				{#each sucursales as s (s.id)}
					<option value={s.id}>{s.nombre}</option>
				{/each}
			</select>
		</div>
	{/if}
	<button class="btn-buscar-sec" onclick={cargar}>Buscar</button>
	<button class="btn-nueva" onclick={irANueva}>+ Nueva compra</button>
</div>

<div class="contenido">
	<div class="table-wrap">
		<table class="compras">
			<thead>
				<tr>
					<th>N°</th>
					<th>Fecha</th>
					<th>Proveedor</th>
					<th>Comprobante</th>
					<th>N° comprobante</th>
					<th>Pago</th>
					<th style="text-align:right">Total</th>
					<th>Estado</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{#if cargando}
					<tr><td colspan="9" class="vacio">Cargando…</td></tr>
				{:else if !compras.length}
					<tr><td colspan="9" class="vacio">No hay compras en este período</td></tr>
				{:else}
					{#each compras as c (c.id)}
						<tr class={c.estado === 'anulado' ? 'anulada' : ''}>
							<td class="num">#{c.numero}</td>
							<td>{c.fecha}</td>
							<td>{c.proveedor_nombre || '—'}</td>
							<td><span class="tipo-pill">{TIPO_COMPROBANTE_LBL[c.tipo_comprobante] || c.tipo_comprobante || '—'}</span></td>
							<td class="num">{c.numero_comprobante || '—'}</td>
							<td>{TIPO_PAGO_LBL[c.tipo_pago] || c.tipo_pago || '—'}</td>
							<td class="total">{fmt(c.total)}</td>
							<td>{#if c.estado === 'anulado'}<span class="tipo-pill pill-anulada">Anulada</span>{/if}</td>
							<td style="white-space:nowrap">
								{#if anulandoId === c.id}
									<div class="confirm-anular">
										<span>¿Anular?</span>
										<button class="btn-conf-si" disabled={procesandoAnular} onclick={() => confirmarAnular(c.id)}
											>{procesandoAnular ? '…' : 'Sí'}</button
										>
										<button class="btn-conf-no" onclick={cancelarAnular}>No</button>
									</div>
								{:else}
									<button class="btn-editar{c.estado === 'anulado' ? ' btn-ver' : ''}" onclick={() => irAEditar(c.id)}>
										{c.estado === 'anulado' ? 'Ver' : 'Editar'}
									</button>
									{#if c.estado !== 'anulado'}
										<button class="btn-anular-fila" style="margin-left:6px" onclick={() => pedirAnular(c.id)}>Anular</button>
									{/if}
								{/if}
							</td>
						</tr>
					{/each}
				{/if}
			</tbody>
		</table>
	</div>
</div>

<style>
	.filtros-bar {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd), 0 -1px 4px var(--neo-sl);
		padding: 10px 16px;
		display: flex;
		gap: 10px;
		align-items: flex-end;
		flex-shrink: 0;
		flex-wrap: wrap;
		position: relative;
		z-index: 10;
	}
	.filtros-bar label {
		display: block;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		margin-bottom: 4px;
	}
	.filtros-bar input,
	.filtros-bar select {
		padding: 7px 10px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-family: inherit;
		background: var(--neo-bg);
		color: var(--neo-text);
		outline: none;
		box-shadow: var(--neo-i1);
	}
	.filtros-bar input:focus,
	.filtros-bar select:focus {
		box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent);
	}
	.filtros-bar input[type='date'] {
		width: 130px;
	}
	.filtros-bar input[type='text'] {
		width: 200px;
	}
	.btn-buscar-sec {
		padding: 7px 18px;
		border: 1px solid var(--color-primary);
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-weight: 700;
		cursor: pointer;
		height: 34px;
		font-family: inherit;
		background: #fff;
		color: var(--color-primary);
	}
	.btn-buscar-sec:hover {
		background: var(--primary-soft);
	}
	.btn-nueva {
		padding: 7px 18px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-weight: 700;
		cursor: pointer;
		height: 34px;
		margin-left: auto;
		display: inline-flex;
		align-items: center;
		background: var(--color-accent);
		color: var(--color-primary-dark);
		font-family: inherit;
	}
	.btn-nueva:hover {
		background: var(--color-accent-h);
	}
	.contenido {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: 16px;
		background: var(--neo-bg-deep);
	}
	.table-wrap {
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-i2);
		overflow: hidden;
	}
	table.compras {
		width: 100%;
		border-collapse: collapse;
	}
	table.compras th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		padding: 11px 14px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		background: var(--neo-bg-deep);
	}
	table.compras td {
		padding: 10px 14px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		color: var(--neo-text);
	}
	table.compras tbody tr:hover {
		background: var(--color-bg-alt);
	}
	table.compras tbody tr.anulada {
		opacity: 0.55;
	}
	table.compras .num {
		font-family: monospace;
		color: var(--neo-text-3);
	}
	table.compras .total {
		font-weight: 700;
		text-align: right;
		font-variant-numeric: tabular-nums;
		color: var(--neo-accent);
	}
	.tipo-pill {
		display: inline-block;
		padding: 2px 8px;
		border-radius: var(--neo-r-pill);
		font-size: 11px;
		font-weight: 600;
		background: var(--color-bg-alt);
		color: var(--neo-text-2);
		box-shadow: var(--neo-e1);
	}
	.pill-anulada {
		background: rgba(231, 76, 60, 0.1);
		color: var(--neo-danger);
	}
	.vacio {
		padding: 40px;
		text-align: center;
		color: var(--neo-text-3);
	}
	.btn-editar {
		padding: 4px 12px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-bg);
		box-shadow: var(--neo-e1);
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		color: var(--neo-text-2);
		font-family: inherit;
	}
	.btn-editar:hover {
		box-shadow: var(--neo-e2);
		color: var(--neo-accent);
	}
	.btn-ver {
		color: var(--neo-text-3);
	}
	.btn-anular-fila {
		padding: 4px 12px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-bg);
		box-shadow: var(--neo-e1);
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		color: var(--neo-danger);
		font-family: inherit;
	}
	.btn-anular-fila:hover {
		box-shadow: var(--neo-e2);
	}
	.confirm-anular {
		display: flex;
		align-items: center;
		gap: 6px;
		white-space: nowrap;
	}
	.confirm-anular span {
		font-size: 12px;
		font-weight: 600;
		color: var(--neo-danger);
	}
	.btn-conf-si {
		padding: 3px 10px;
		border: none;
		border-radius: var(--neo-r-xs);
		cursor: pointer;
		background: var(--neo-danger);
		color: white;
		font-size: 12px;
		font-weight: 700;
		box-shadow: 3px 3px 6px var(--neo-danger-glow);
	}
	.btn-conf-no {
		padding: 3px 10px;
		border: none;
		border-radius: var(--neo-r-xs);
		cursor: pointer;
		background: var(--neo-bg);
		color: var(--neo-text);
		font-size: 12px;
		font-weight: 600;
		box-shadow: var(--neo-e1);
	}
</style>
