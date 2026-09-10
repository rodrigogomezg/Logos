<script lang="ts">
	import { api, apiUrl } from '$lib/api';
	import { leerSesion, puede } from '$lib/session';
	import { toast_ } from '$lib/toast';
	import { abrirPdf } from '$lib/pdf';
	import { preguntarOcultarDescuentos } from '$lib/descuento-prompt';
	import { fmtFecha } from '$lib/fecha';

	type Pago = { tipo: string; monto: number };
	type Venta = {
		id: number;
		fecha: string;
		tipo_comprobante: string | null;
		numero: string;
		cliente_nombre: string | null;
		tipo_pago: string;
		total: number;
		pagos?: Pago[];
		cae: string | null;
	};

	const COLUMNAS_PAGO = ['efectivo', 'transferencia', 'tarjeta', 'cheque', 'cc'] as const;
	const esAdmin = puede('cajas_todas');

	let cajas = $state<{ id: number; nombre: string }[]>([]);
	let cajaFiltro = $state('');
	let desde = $state(fechaHoy());
	let hasta = $state(fechaHoy());
	let datos = $state<Venta[]>([]);
	let cargando = $state(true);

	function fechaHoy() {
		const d = new Date();
		return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
	}
	function fmt(n: number) {
		return '$' + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function montoColumna(v: Venta, tipo: string): number {
		if (v.tipo_pago === 'mixto') {
			const p = (v.pagos || []).find((p) => p.tipo === tipo);
			return p ? Number(p.monto) : 0;
		}
		return v.tipo_pago === tipo ? Number(v.total) : 0;
	}

	async function cargarCajas() {
		if (!esAdmin) return;
		const res = await api('/cajas');
		if (res.ok) cajas = await res.json();
	}

	async function cargar() {
		cargando = true;
		const params = new URLSearchParams();
		params.set('limit', '500');
		const cajaId = esAdmin ? cajaFiltro : String(leerSesion()?.caja_id ?? '');
		if (cajaId) params.set('caja_id', cajaId);
		if (desde) params.set('fecha_desde', desde);
		if (hasta) params.set('fecha_hasta', hasta);

		const res = await api(`/ventas?${params.toString()}`);
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al cargar las operaciones', 'err');
			cargando = false;
			return;
		}
		datos = data;
		cargando = false;
	}

	const totales = $derived(COLUMNAS_PAGO.map((t) => datos.reduce((s, v) => s + montoColumna(v, t), 0)));
	const totalGral = $derived(datos.reduce((s, v) => s + Number(v.total), 0));

	async function accionVer(id: number) {
		try {
			const ocultar = await preguntarOcultarDescuentos(id);
			const r = await api(`/ventas/${id}/comprobante${ocultar ? '?ocultar_descuentos=1' : ''}`);
			if (!r.ok) {
				const d = await r.json();
				toast_(d.error || 'Error al generar PDF', 'err');
				return;
			}
			abrirPdf(await r.blob());
		} catch {
			toast_('Error de conexión al generar PDF', 'err');
		}
	}

	async function accionEditar(id: number) {
		try {
			const r = await api(`/ventas/${id}`);
			const v = await r.json();
			if (!r.ok) {
				toast_(v.error || 'Error al cargar la venta', 'err');
				return;
			}
			if (v.cae) {
				toast_('Ya fue autorizada por ARCA (tiene CAE) — no se puede editar. Emití una Nota de Crédito para revertirla.', 'err');
				return;
			}
			try {
				localStorage.setItem('logos_editar_venta', JSON.stringify(v));
			} catch {
				toast_('Sin espacio en el navegador para cargar la venta en el editor.', 'err');
				return;
			}
			sessionStorage.setItem('logos_volver_tras_editar', location.href);
			location.href = '/';
		} catch {
			toast_('Error de conexión', 'err');
		}
	}

	cargarCajas();
	cargar();
</script>

<svelte:head>
	<title>Logos — Listado de operaciones</title>
</svelte:head>

<div class="page-header">
	<div>
		<h1>Listado de operaciones</h1>
		<p>Comprobantes del día, encolumnados por forma de pago</p>
	</div>
	<div class="filtros">
		{#if esAdmin}
			<select class="fil-select" bind:value={cajaFiltro} onchange={cargar}>
				<option value="">Todas las cajas</option>
				{#each cajas as c (c.id)}
					<option value={c.id}>{c.nombre}</option>
				{/each}
			</select>
		{/if}
		<input class="fil-input" type="date" bind:value={desde} onchange={cargar} />
		<input class="fil-input" type="date" bind:value={hasta} onchange={cargar} />
	</div>
</div>

<div class="contenido-scroll">
	<div class="contenido-inner">
		<div class="card">
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th>Fecha</th>
							<th>Comprobante</th>
							<th>Cliente</th>
							<th class="r">Efectivo</th>
							<th class="r">Transferencia</th>
							<th class="r">Tarjeta</th>
							<th class="r">Cheque</th>
							<th class="r">Cta. Cte.</th>
							<th class="r">Total</th>
							<th>Acciones</th>
						</tr>
					</thead>
					<tbody>
						{#if cargando}
							<tr><td colspan="10"><div class="vacio">Cargando…</div></td></tr>
						{:else if !datos.length}
							<tr><td colspan="10"><div class="vacio">No hay operaciones para los filtros aplicados.</div></td></tr>
						{:else}
							{#each datos as v (v.id)}
								<tr>
									<td>{fmtFecha(v.fecha)}</td>
									<td><span class="tipo-badge">{v.tipo_comprobante ?? '—'}</span>N° {v.numero}</td>
									<td>{v.cliente_nombre ?? '— Consumidor final —'}</td>
									{#each COLUMNAS_PAGO as tipo (tipo)}
										{@const m = montoColumna(v, tipo)}
										<td class="r" class:vacia={m <= 0}>{m > 0 ? fmt(m) : '—'}</td>
									{/each}
									<td class="r">{fmt(v.total)}</td>
									<td>
										<button class="acc-btn" onclick={() => accionVer(v.id)}>Ver</button>
										<button
											class="acc-btn editar"
											disabled={!!v.cae}
											title={v.cae ? 'Ya fue autorizada por ARCA (tiene CAE) — no se puede editar.' : ''}
											onclick={() => accionEditar(v.id)}>Editar</button
										>
									</td>
								</tr>
							{/each}
						{/if}
					</tbody>
					{#if datos.length}
						<tfoot>
							<tr>
								<td colspan="3">Totales ({datos.length})</td>
								{#each totales as t, i (i)}
									<td class="r">{fmt(t)}</td>
								{/each}
								<td class="r">{fmt(totalGral)}</td>
								<td></td>
							</tr>
						</tfoot>
					{/if}
				</table>
			</div>
		</div>
	</div>
</div>

<style>
	.page-header {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd);
		padding: 16px clamp(16px, 4vw, 48px);
		flex-shrink: 0;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
	}
	.page-header h1 {
		font-size: 22px;
		font-weight: 700;
		letter-spacing: -0.3px;
		color: var(--neo-text);
	}
	.page-header p {
		font-size: 12px;
		color: var(--neo-text-3);
		margin-top: 3px;
	}
	.filtros {
		display: flex;
		gap: 10px;
		align-items: center;
		flex-wrap: wrap;
	}
	.fil-select,
	.fil-input {
		padding: 8px 11px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 12px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-i1);
	}
	.contenido-scroll {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: clamp(16px, 3vw, 32px) clamp(16px, 4vw, 48px);
		background: var(--neo-bg-deep);
	}
	.contenido-inner {
		max-width: 1400px;
		margin: 0 auto;
	}
	.card {
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-i1);
		overflow: hidden;
	}
	.table-wrap {
		overflow-x: auto;
	}
	table {
		width: 100%;
		border-collapse: collapse;
		min-width: 1180px;
	}
	thead th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		padding: 12px 14px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		background: var(--neo-bg-deep);
		position: sticky;
		top: 0;
	}
	thead th.r {
		text-align: right;
	}
	tbody td {
		padding: 10px 14px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		white-space: nowrap;
		color: var(--neo-text);
	}
	tbody td.r {
		text-align: right;
		font-variant-numeric: tabular-nums;
		font-weight: 600;
	}
	tbody td.r.vacia {
		color: var(--neo-text-3);
		font-weight: 400;
	}
	tbody tr:last-child td {
		border-bottom: none;
	}
	tbody tr:hover {
		background: var(--color-bg-alt);
	}
	tfoot td {
		padding: 12px 14px;
		font-size: 13px;
		font-weight: 700;
		border-top: 1px solid var(--borde-fuerte);
		color: var(--neo-text);
	}
	tfoot td.r {
		text-align: right;
		font-variant-numeric: tabular-nums;
	}
	.tipo-badge {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.3px;
		padding: 3px 7px;
		border-radius: var(--neo-r-xs);
		margin-right: 6px;
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.acc-btn {
		background: none;
		border: none;
		cursor: pointer;
		padding: 4px 8px;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-weight: 600;
		color: var(--neo-accent);
		font-family: inherit;
	}
	.acc-btn:hover {
		background: var(--primary-soft-2);
	}
	.acc-btn.editar {
		color: var(--neo-warning);
	}
	.acc-btn:disabled {
		opacity: 0.4;
		cursor: not-allowed;
	}
	.acc-btn:disabled:hover {
		background: none;
	}
	.vacio {
		text-align: center;
		padding: 48px 20px;
		color: var(--neo-text-3);
		font-size: 13px;
	}
</style>
