<script lang="ts">
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';

	type FilaVenta = {
		fecha: string;
		tipo_comprobante: string;
		es_nc: boolean;
		punto_venta: number | null;
		numero_afip: string | null;
		cae: string | null;
		cliente_nombre: string | null;
		cliente_cuit: string | null;
		cliente_condicion_iva: string | null;
		neto_21: number;
		iva_21: number;
		neto_105: number;
		iva_105: number;
		neto_exento: number;
		total: number;
	};
	type FilaCompra = {
		fecha: string;
		tipo_comprobante: string;
		numero_comprobante: string | null;
		proveedor_nombre: string | null;
		proveedor_cuit: string | null;
		neto_21: number;
		iva_21: number;
		neto_105: number;
		iva_105: number;
		neto_exento: number;
		iva_total: number;
		percepcion_iibb: number;
		total: number;
	};
	type Totales = {
		neto_21?: number;
		iva_21?: number;
		neto_105?: number;
		iva_105?: number;
		neto_exento?: number;
		percepcion_iibb?: number;
		iva_total?: number;
		total?: number;
	};

	function pesos(n: number | null | undefined) {
		return '$ ' + Number(n ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	const hoy = new Date();
	const primerDia = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-01`;
	const hoyStr = hoy.toISOString().slice(0, 10);

	let tipoActual = $state<'ventas' | 'compras'>('ventas');
	let desde = $state(primerDia);
	let hasta = $state(hoyStr);
	let filasVentas = $state<FilaVenta[]>([]);
	let filasCompras = $state<FilaCompra[]>([]);
	let totales = $state<Totales>({});
	let cargando = $state(false);
	let cargado = $state(false);

	const filas = $derived(tipoActual === 'ventas' ? filasVentas : filasCompras);
	const hay105 = $derived(filas.some((r) => (r.neto_105 ?? 0) > 0 || (r.iva_105 ?? 0) > 0));
	const hayExento = $derived(filas.some((r) => (r.neto_exento ?? 0) > 0));
	const hayPerc = $derived(tipoActual === 'compras' && (filasCompras as FilaCompra[]).some((r) => (r.percepcion_iibb ?? 0) > 0));

	function setTipo(tipo: 'ventas' | 'compras') {
		tipoActual = tipo;
		cargar();
	}

	async function cargar() {
		if (!desde || !hasta) return;
		cargando = true;
		const res = await api(`/iva/${tipoActual}?desde=${desde}&hasta=${hasta}`);
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al cargar el libro IVA', 'err');
			cargando = false;
			return;
		}
		if (tipoActual === 'ventas') filasVentas = data.filas || [];
		else filasCompras = data.filas || [];
		totales = data.totales || {};
		cargando = false;
		cargado = true;
	}

	async function exportar() {
		if (!desde || !hasta) return;
		const res = await api(`/iva/export?tipo=${tipoActual}&desde=${desde}&hasta=${hasta}`);
		if (!res.ok) {
			const e = await res.json().catch(() => ({}));
			toast_(e.error ?? 'Error al exportar', 'err');
			return;
		}
		const blob = await res.blob();
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `libro_iva_${tipoActual}_${desde}_${hasta}.csv`;
		a.click();
		URL.revokeObjectURL(url);
	}

	cargar();
</script>

<svelte:head>
	<title>Libro IVA — Logos</title>
</svelte:head>

<div class="page-body">
	<div class="toolbar">
		<div class="tab-grupo">
			<button class="tab-tipo" class:activo={tipoActual === 'ventas'} onclick={() => setTipo('ventas')}>Ventas</button>
			<button class="tab-tipo" class:activo={tipoActual === 'compras'} onclick={() => setTipo('compras')}>Compras</button>
		</div>
		<div class="tb-sep"></div>
		<span class="tb-lbl">Desde</span>
		<input type="date" class="tb-input" bind:value={desde} />
		<span class="tb-lbl">Hasta</span>
		<input type="date" class="tb-input" bind:value={hasta} />
		<button class="btn-accion" onclick={cargar}>Aplicar</button>
		<div class="tb-sep"></div>
		<button class="btn-accion export" onclick={exportar}>Exportar CSV</button>
		<span style="margin-left:auto;font-size:11px;color:var(--neo-text-3)">{cargado ? `${filas.length} comprobante(s)` : ''}</span>
	</div>

	{#if cargado && filas.length}
		<div class="totales-bar">
			<div class="total-item"><span class="total-lbl">Neto 21%</span><span class="total-val">{pesos(totales.neto_21)}</span></div>
			<div class="total-item"><span class="total-lbl">IVA 21%</span><span class="total-val accent">{pesos(totales.iva_21)}</span></div>
			{#if hay105}
				<div class="total-item"><span class="total-lbl">Neto 10.5%</span><span class="total-val">{pesos(totales.neto_105)}</span></div>
				<div class="total-item"><span class="total-lbl">IVA 10.5%</span><span class="total-val accent">{pesos(totales.iva_105)}</span></div>
			{/if}
			{#if hayExento}
				<div class="total-item"><span class="total-lbl">Exento</span><span class="total-val">{pesos(totales.neto_exento)}</span></div>
			{/if}
			{#if hayPerc}
				<div class="total-item"><span class="total-lbl">Perc. IIBB</span><span class="total-val">{pesos(totales.percepcion_iibb)}</span></div>
			{/if}
			<div class="total-item"><span class="total-lbl">Total</span><span class="total-val">{pesos(totales.total)}</span></div>
		</div>
	{/if}

	<div class="card">
		<div class="tabla-wrap">
			<table>
				{#if tipoActual === 'ventas'}
					<thead>
						<tr>
							<th>Fecha</th><th>Tipo</th><th class="r">Pto.Vta</th><th class="r">N° AFIP</th>
							<th>CAE</th><th>Cliente</th><th>CUIT</th><th>Cond. IVA</th>
							<th class="r">Neto 21%</th><th class="r">IVA 21%</th>
							<th class="r">Neto 10.5%</th><th class="r">IVA 10.5%</th>
							<th class="r">Exento</th><th class="r">Total</th>
						</tr>
					</thead>
				{:else}
					<thead>
						<tr>
							<th>Fecha</th><th>Tipo</th><th>N° Comprobante</th>
							<th>Proveedor</th><th>CUIT</th>
							<th class="r">Neto 21%</th><th class="r">IVA 21%</th>
							<th class="r">Neto 10.5%</th><th class="r">IVA 10.5%</th>
							<th class="r">Exento</th><th class="r">IVA Total</th><th class="r">Perc. IIBB</th>
							<th class="r">Total</th>
						</tr>
					</thead>
				{/if}
				<tbody>
					{#if cargando}
						<tr><td colspan="14" class="empty">Cargando…</td></tr>
					{:else if !filas.length}
						<tr><td colspan="14" class="empty">Sin comprobantes para el período seleccionado.</td></tr>
					{:else if tipoActual === 'ventas'}
						{#each filasVentas as r, i (i)}
							<tr class={r.es_nc ? 'fila-nc' : ''}>
								<td>{r.fecha}</td>
								<td>{r.tipo_comprobante}{#if r.es_nc}<span class="badge-nc">NC</span>{/if}</td>
								<td class="r">{r.punto_venta || '—'}</td>
								<td class="r">{r.numero_afip || '—'}</td>
								<td class="muted">{r.cae || '—'}</td>
								<td>{r.cliente_nombre || 'Consumidor Final'}</td>
								<td class="muted">{r.cliente_cuit || '—'}</td>
								<td class="muted">{r.cliente_condicion_iva || '—'}</td>
								<td class="r">{pesos(r.neto_21)}</td>
								<td class="r">{pesos(r.iva_21)}</td>
								<td class="r">{r.neto_105 > 0 ? pesos(r.neto_105) : '—'}</td>
								<td class="r">{r.iva_105 > 0 ? pesos(r.iva_105) : '—'}</td>
								<td class="r">{r.neto_exento > 0 ? pesos(r.neto_exento) : '—'}</td>
								<td class="r"><strong>{pesos(r.total)}</strong></td>
							</tr>
						{/each}
						<tr class="tfoot-row">
							<td colspan="8">TOTALES</td>
							<td class="r">{pesos(totales.neto_21)}</td>
							<td class="r">{pesos(totales.iva_21)}</td>
							<td class="r">{pesos(totales.neto_105)}</td>
							<td class="r">{pesos(totales.iva_105)}</td>
							<td class="r">{pesos(totales.neto_exento)}</td>
							<td class="r">{pesos(totales.total)}</td>
						</tr>
					{:else}
						{#each filasCompras as r, i (i)}
							<tr>
								<td>{r.fecha}</td>
								<td>{r.tipo_comprobante}</td>
								<td class="muted">{r.numero_comprobante || '—'}</td>
								<td>{r.proveedor_nombre || '—'}</td>
								<td class="muted">{r.proveedor_cuit || '—'}</td>
								<td class="r">{pesos(r.neto_21)}</td>
								<td class="r">{pesos(r.iva_21)}</td>
								<td class="r">{r.neto_105 > 0 ? pesos(r.neto_105) : '—'}</td>
								<td class="r">{r.iva_105 > 0 ? pesos(r.iva_105) : '—'}</td>
								<td class="r">{r.neto_exento > 0 ? pesos(r.neto_exento) : '—'}</td>
								<td class="r">{pesos(r.iva_total)}</td>
								<td class="r">{r.percepcion_iibb > 0 ? pesos(r.percepcion_iibb) : '—'}</td>
								<td class="r"><strong>{pesos(r.total)}</strong></td>
							</tr>
						{/each}
						<tr class="tfoot-row">
							<td colspan="5">TOTALES</td>
							<td class="r">{pesos(totales.neto_21)}</td>
							<td class="r">{pesos(totales.iva_21)}</td>
							<td class="r">{pesos(totales.neto_105)}</td>
							<td class="r">{pesos(totales.iva_105)}</td>
							<td class="r">{pesos(totales.neto_exento)}</td>
							<td class="r">{pesos(totales.iva_total)}</td>
							<td class="r">{pesos(totales.percepcion_iibb)}</td>
							<td class="r">{pesos(totales.total)}</td>
						</tr>
					{/if}
				</tbody>
			</table>
		</div>
	</div>
</div>

<style>
	.page-body {
		flex: 1;
		display: flex;
		flex-direction: column;
		overflow: hidden;
		padding: 12px;
		gap: 10px;
		background: var(--neo-bg-deep);
	}
	.toolbar {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		background: var(--neo-bg);
		border-radius: var(--neo-r-md);
		padding: 8px 12px;
		box-shadow: var(--neo-e2);
	}
	.tab-grupo {
		display: flex;
		border-radius: var(--neo-r-sm);
		overflow: hidden;
		box-shadow: var(--neo-e1);
	}
	.tab-tipo {
		padding: 7px 20px;
		background: var(--neo-bg);
		border: none;
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		cursor: pointer;
		color: var(--neo-text-3);
		font-family: inherit;
	}
	.tab-tipo.activo {
		box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent);
		color: var(--neo-accent);
	}
	.tb-sep {
		width: 1px;
		background: var(--color-bg-alt);
		height: 30px;
		margin: 0 4px;
	}
	.tb-input {
		padding: 7px 11px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-i1);
	}
	.tb-lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.btn-accion {
		padding: 7px 14px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 11px;
		font-weight: 700;
		font-family: inherit;
		cursor: pointer;
		box-shadow: var(--neo-e1);
		background: var(--neo-bg);
		color: var(--neo-text-2);
		text-transform: uppercase;
		letter-spacing: 0.3px;
	}
	.btn-accion:hover {
		box-shadow: var(--neo-e2);
		color: var(--neo-text);
	}
	.btn-accion.export {
		background: var(--neo-success);
		color: #fff;
	}
	.totales-bar {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
		background: var(--neo-bg);
		border-radius: var(--neo-r-md);
		padding: 10px 16px;
		box-shadow: var(--neo-e2);
	}
	.total-item {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 0 12px;
		border-right: 1px solid var(--borde-fuerte);
	}
	.total-item:last-child {
		border: none;
	}
	.total-lbl {
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.total-val {
		font-size: 15px;
		font-weight: 800;
		color: var(--neo-text);
		font-variant-numeric: tabular-nums;
	}
	.total-val.accent {
		color: var(--neo-accent);
	}
	.card {
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-i1);
		flex: 1;
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}
	.tabla-wrap {
		flex: 1;
		overflow: auto;
	}
	table {
		width: 100%;
		border-collapse: collapse;
		font-size: 11px;
		white-space: nowrap;
	}
	thead th {
		position: sticky;
		top: 0;
		background: var(--neo-bg-deep);
		padding: 9px 10px;
		text-align: left;
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	thead th.r {
		text-align: right;
	}
	tbody tr {
		border-bottom: 1px solid var(--borde);
	}
	tbody tr:hover {
		background: var(--color-bg-alt);
	}
	tbody tr.fila-nc {
		background: rgba(231, 76, 60, 0.04);
	}
	tbody td {
		padding: 7px 10px;
		vertical-align: middle;
		color: var(--neo-text);
	}
	tbody td.r {
		text-align: right;
		font-variant-numeric: tabular-nums;
	}
	tbody td.muted {
		color: var(--neo-text-3);
	}
	.badge-nc {
		display: inline-block;
		padding: 1px 6px;
		font-size: 9px;
		font-weight: 700;
		border-radius: var(--neo-r-xs);
		background: rgba(231, 76, 60, 0.12);
		color: var(--neo-danger);
		margin-left: 4px;
	}
	.tfoot-row td {
		font-weight: 700;
		background: var(--neo-bg-deep);
		border-top: 2px solid var(--borde-fuerte);
		color: var(--neo-text);
	}
	.empty {
		padding: 32px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 13px;
		font-style: italic;
	}
</style>
