<script lang="ts">
	import type { ChartConfiguration } from 'chart.js';
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { chartjs } from '$lib/chart-action';

	type Vista = 'resumen-ejecutivo' | 'rotacion-inventario' | 'compras-proveedor' | 'flujo-caja';
	type Kpi = { lbl: string; val: string; cls?: string; dtl?: string };
	type PorDia = { dia: string; cant: number; monto: number };
	type Producto = { nombre: string; categoria: string; marca: string; unidades_vendidas: number; ingresos: number; stock_actual: number; indice_rotacion: number | null };
	type FilaCompraProv = { nombre: string; cuit: string; cantidad_ordenes: number; subtotal: number; iva: number; total: number; primera: string | null; ultima: string | null; saldo_cuenta_corriente: number };
	type SerieFlujo = { periodo: string; ingresos: number; ingresos_caja: number; egresos: number; retiros: number; saldo_neto: number; saldo_acum: number };

	const LABELS_COBROS: Record<string, string> = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta: 'Tarjeta', cc: 'Cta. Cte.', mercado_pago: 'Mercado Pago', cheque: 'Cheque', mixto: 'Mixto' };

	function pesos(n: number | null | undefined, corto = false) {
		const v = Number(n ?? 0);
		if (corto && Math.abs(v) >= 1000000) return '$ ' + (v / 1000000).toFixed(1) + 'M';
		if (corto && Math.abs(v) >= 1000) return '$ ' + (v / 1000).toFixed(1) + 'k';
		return '$ ' + v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function num(n: number | null | undefined) {
		return Number(n ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	const hoy = new Date();
	let vistaActual = $state<Vista>('resumen-ejecutivo');
	let desde = $state(`${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-01`);
	let hasta = $state(hoy.toISOString().slice(0, 10));
	let categoria = $state('');
	let agrupar = $state<'dia' | 'semana' | 'mes'>('dia');
	let cargando = $state(false);
	let errorMsg = $state('');
	let kpis = $state<Kpi[]>([{ lbl: '—', val: '—' }, { lbl: '—', val: '—' }, { lbl: '—', val: '—' }, { lbl: '—', val: '—' }]);

	let porDia = $state<PorDia[]>([]);
	let cobros = $state<Record<string, number>>({});
	let productos = $state<Producto[]>([]);
	let totalesRotacion = $state<{ unidades?: number; ingresos?: number; productos?: number; sin_movimiento?: number; en_quiebre?: number }>({});
	let filasCompras = $state<FilaCompraProv[]>([]);
	let serieFlujo = $state<SerieFlujo[]>([]);

	const chipsHtml = $derived(
		Object.entries(cobros)
			.sort((a, b) => b[1] - a[1])
			.map(([k, v]) => ({ label: LABELS_COBROS[k] ?? k, val: pesos(v, true) }))
	);
	const productosFiltrados = $derived(
		categoria.trim() ? productos.filter((p) => (p.categoria || '').toLowerCase().includes(categoria.trim().toLowerCase())) : productos
	);
	const maxRotacion = $derived(Math.max(...productosFiltrados.map((p) => p.indice_rotacion ?? 0), 0.01));
	const conCompras = $derived(filasCompras.filter((f) => f.cantidad_ordenes > 0).length);

	function setVista(v: Vista) {
		vistaActual = v;
		cargarVista();
	}

	async function cargarVista() {
		errorMsg = '';
		if (!desde || !hasta) return;
		cargando = true;
		try {
			if (vistaActual === 'resumen-ejecutivo') await cargarResumen();
			if (vistaActual === 'rotacion-inventario') await cargarRotacion();
			if (vistaActual === 'compras-proveedor') await cargarCompras();
			if (vistaActual === 'flujo-caja') await cargarFlujo();
		} catch (e) {
			errorMsg = 'Error al cargar datos: ' + (e instanceof Error ? e.message : String(e));
		}
		cargando = false;
	}

	async function cargarResumen() {
		const res = await api(`/reportes/resumen-ejecutivo?desde=${desde}&hasta=${hasta}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		const balance = data.balance ?? 0;
		kpis = [
			{ lbl: 'Ventas del período', val: pesos(data.ventas, true) },
			{ lbl: 'Compras del período', val: pesos(data.total_compras, true) },
			{ lbl: 'Ganancia bruta', val: pesos(data.ganancia_bruta, true), cls: data.ganancia_bruta >= 0 ? 'g' : 'd' },
			{ lbl: 'Balance operativo', val: pesos(balance, true), cls: balance >= 0 ? 'g' : 'd', dtl: `Margen ${data.margen_pct}%` }
		];
		porDia = data.por_dia || [];
		cobros = data.cobros_tipo || {};
	}

	async function cargarRotacion() {
		let url = `/reportes/rotacion-inventario?desde=${desde}&hasta=${hasta}`;
		if (categoria.trim()) url += `&categoria=${encodeURIComponent(categoria.trim())}`;
		const res = await api(url);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		productos = data.productos || [];
		const tot = data.totales || {};
		totalesRotacion = tot;
		kpis = [
			{ lbl: 'Unidades vendidas', val: num(tot.unidades ?? 0), dtl: `${tot.productos ?? 0} productos` },
			{ lbl: 'Ingresos generados', val: pesos(tot.ingresos ?? 0, true) },
			{ lbl: 'Sin movimiento', val: String(tot.sin_movimiento ?? 0), dtl: 'productos sin ventas' },
			{ lbl: 'En quiebre de stock', val: String(tot.en_quiebre ?? 0), dtl: 'stock ≤ mínimo' }
		];
	}

	async function cargarCompras() {
		const res = await api(`/reportes/compras-proveedor?desde=${desde}&hasta=${hasta}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		filasCompras = data.filas || [];
		const tot = data.totales || {};
		kpis = [
			{ lbl: 'Total comprado', val: pesos(tot.total ?? 0, true) },
			{ lbl: 'Órdenes de compra', val: String(tot.ordenes ?? 0) },
			{ lbl: 'IVA crédito fiscal', val: pesos(tot.iva ?? 0, true) },
			{ lbl: 'Proveedores activos', val: String(conCompras), dtl: `de ${filasCompras.length} en catálogo` }
		];
	}

	async function cargarFlujo() {
		const res = await api(`/reportes/flujo-caja?desde=${desde}&hasta=${hasta}&agrupar=${agrupar}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		serieFlujo = data.serie || [];
		const tot = data.totales || {};
		const saldo = tot.saldo_neto ?? 0;
		kpis = [
			{ lbl: 'Ventas cobradas', val: pesos(tot.ingresos ?? 0, true) },
			{ lbl: 'Cobros / Ingr. caja', val: pesos(tot.ingresos_caja ?? 0, true) },
			{ lbl: 'Egresos (compras)', val: pesos(tot.egresos ?? 0, true) },
			{ lbl: 'Saldo neto', val: pesos(saldo, true), cls: saldo >= 0 ? 'g' : 'd' }
		];
	}

	async function exportar() {
		const res = await api(`/reportes/exportar?tipo=${vistaActual}&desde=${desde}&hasta=${hasta}`);
		if (!res.ok) {
			toast_('Error al exportar', 'err');
			return;
		}
		const blob = await res.blob();
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `reporte_${vistaActual.replace(/[^a-z0-9-]/g, '')}_${desde}_${hasta}.xlsx`;
		a.click();
		URL.revokeObjectURL(url);
	}

	const chartDiasConfig = $derived<ChartConfiguration>({
		type: 'bar',
		data: {
			labels: porDia.map((d) => d.dia),
			datasets: [{ label: 'Ventas', data: porDia.map((d) => d.monto), backgroundColor: 'rgba(22,59,102,.75)', borderRadius: 3 }]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: { legend: { display: false } },
			scales: {
				x: { ticks: { font: { size: 9 }, color: '#9B9590', maxRotation: 45 }, grid: { display: false } },
				y: { ticks: { font: { size: 9 }, color: '#9B9590', callback: (v) => pesos(Number(v), true) }, grid: { color: '#E4DFD3' } }
			}
		}
	});

	const chartFlujoConfig = $derived<ChartConfiguration>({
		type: 'bar',
		data: {
			labels: serieFlujo.map((s) => s.periodo),
			datasets: [
				{ type: 'bar', label: 'Ventas cobradas', data: serieFlujo.map((s) => s.ingresos), backgroundColor: 'rgba(39,174,96,.65)', borderRadius: 3 },
				{ type: 'bar', label: 'Cobros/Ingr. caja', data: serieFlujo.map((s) => s.ingresos_caja), backgroundColor: 'rgba(39,174,96,.35)', borderRadius: 3 },
				{ type: 'bar', label: 'Egresos', data: serieFlujo.map((s) => s.egresos), backgroundColor: 'rgba(231,76,60,.55)', borderRadius: 3 },
				{
					type: 'line',
					label: 'Saldo acum.',
					data: serieFlujo.map((s) => s.saldo_acum),
					borderColor: '#163B66',
					backgroundColor: 'rgba(22,59,102,.12)',
					fill: true,
					tension: 0.3,
					pointRadius: 3,
					pointBackgroundColor: '#163B66'
				}
			]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: { legend: { labels: { font: { size: 10 }, color: '#9B9590' } } },
			scales: {
				x: { ticks: { font: { size: 9 }, color: '#9B9590', maxRotation: 45 }, grid: { display: false } },
				y: { ticks: { font: { size: 9 }, color: '#9B9590', callback: (v) => pesos(Number(v), true) }, grid: { color: '#E4DFD3' } }
			}
		}
	});

	cargarVista();
</script>

<svelte:head>
	<title>Reportes — Logos</title>
</svelte:head>

<div class="page-body">
	<div class="toolbar">
		<div class="tab-grupo">
			<button class="tab-vista" class:activo={vistaActual === 'resumen-ejecutivo'} onclick={() => setVista('resumen-ejecutivo')}>Resumen</button>
			<button class="tab-vista" class:activo={vistaActual === 'rotacion-inventario'} onclick={() => setVista('rotacion-inventario')}>Rotación</button>
			<button class="tab-vista" class:activo={vistaActual === 'compras-proveedor'} onclick={() => setVista('compras-proveedor')}>Compras x Prov.</button>
			<button class="tab-vista" class:activo={vistaActual === 'flujo-caja'} onclick={() => setVista('flujo-caja')}>Flujo de Caja</button>
		</div>
		<div class="tb-sep"></div>
		<span class="tb-lbl">Desde</span>
		<input type="date" class="tb-input" bind:value={desde} />
		<span class="tb-lbl">Hasta</span>
		<input type="date" class="tb-input" bind:value={hasta} />
		<button class="btn-accion" onclick={cargarVista}>Aplicar</button>
		{#if vistaActual === 'rotacion-inventario' || vistaActual === 'flujo-caja'}
			<div class="tb-sep"></div>
			<span class="tb-lbl">{vistaActual === 'rotacion-inventario' ? 'Categoría' : 'Agrupar'}</span>
			{#if vistaActual === 'rotacion-inventario'}
				<input type="text" class="tb-input" placeholder="Categoría…" style="width:130px" bind:value={categoria} />
			{:else}
				<select class="tb-input" style="width:90px" bind:value={agrupar} onchange={cargarVista}>
					<option value="dia">Por día</option>
					<option value="semana">Por semana</option>
					<option value="mes">Por mes</option>
				</select>
			{/if}
		{/if}
		<div style="flex:1"></div>
		<button class="btn-export" onclick={exportar}>↓ Excel</button>
	</div>

	<div class="kpis-row">
		{#each kpis as k, i (i)}
			<div class="kcard">
				<span class="klbl">{k.lbl}</span>
				<span class="kval {k.cls ?? ''}">{k.val}</span>
				<span class="kdtl">{k.dtl ?? ''}</span>
			</div>
		{/each}
	</div>

	<div class="content-scroll">
		{#if cargando}
			<div class="empty">Cargando…</div>
		{:else if errorMsg}
			<div class="empty">{errorMsg}</div>
		{:else if vistaActual === 'resumen-ejecutivo'}
			<div class="grid2">
				<div class="bloque">
					<div class="bh"><span class="bt">Ventas por día</span><span class="bs">{porDia.length} días con actividad</span></div>
					<div class="chart-pad">
						{#if porDia.length}
							<canvas use:chartjs={chartDiasConfig}></canvas>
						{/if}
					</div>
				</div>
				<div class="bloque">
					<div class="bh"><span class="bt">Cobros por medio de pago</span></div>
					<div class="pago-tipo">
						{#if chipsHtml.length}
							{#each chipsHtml as c, i (i)}
								<span class="pago-chip">{c.label}: <b>{c.val}</b></span>
							{/each}
						{:else}
							<span class="empty" style="padding:8px">Sin datos</span>
						{/if}
					</div>
					<div class="bh" style="border-top:1px solid var(--borde-fuerte);border-bottom:none;margin-top:8px">
						<span class="bt">Detalle diario</span>
					</div>
					<div class="tabla-wrap">
						<table>
							<thead><tr><th>Fecha</th><th class="r">Ops.</th><th class="r">Monto</th></tr></thead>
							<tbody>
								{#if porDia.length}
									{#each porDia as d, i (i)}
										<tr><td>{d.dia}</td><td class="r muted">{d.cant}</td><td class="r">{pesos(d.monto)}</td></tr>
									{/each}
								{:else}
									<tr><td colspan="3" class="empty">Sin ventas en el período.</td></tr>
								{/if}
							</tbody>
						</table>
					</div>
				</div>
			</div>
		{:else if vistaActual === 'rotacion-inventario'}
			<div class="bloque">
				<div class="bh">
					<span class="bt">Rotación de inventario</span>
					<span class="bs">{productosFiltrados.length} productos</span>
				</div>
				<div class="tabla-wrap" style="max-height:none">
					<table>
						<thead>
							<tr>
								<th>Nombre</th><th>Categoría</th><th>Marca</th>
								<th class="r">Uds. vendidas</th><th class="r">Ingresos</th>
								<th class="r">Stock actual</th><th>Índice rotación</th><th>Estado</th>
							</tr>
						</thead>
						<tbody>
							{#if productosFiltrados.length}
								{#each productosFiltrados as p, i (i)}
									{@const pct = maxRotacion > 0 ? Math.min(100, ((p.indice_rotacion ?? 0) / maxRotacion) * 100) : 0}
									<tr>
										<td>{p.nombre}</td>
										<td class="muted">{p.categoria}</td>
										<td class="muted">{p.marca}</td>
										<td class="r">{p.unidades_vendidas % 1 === 0 ? p.unidades_vendidas : p.unidades_vendidas.toFixed(2)}</td>
										<td class="r">{pesos(p.ingresos, true)}</td>
										<td class="r muted">{p.stock_actual % 1 === 0 ? p.stock_actual : p.stock_actual.toFixed(2)}</td>
										<td>
											<div class="rot-bar">
												<div class="rot-track"><div class="rot-fill" style="width:{pct.toFixed(1)}%"></div></div>
												<span class="rot-val">{p.indice_rotacion != null ? p.indice_rotacion.toFixed(2) : '—'}</span>
											</div>
										</td>
										<td>
											{#if p.unidades_vendidas > 0}
												<span class="badge badge-ok">Activo</span>
											{:else}
												<span class="badge badge-zero">Sin mov.</span>
											{/if}
										</td>
									</tr>
								{/each}
							{:else}
								<tr><td colspan="8" class="empty">Sin productos.</td></tr>
							{/if}
						</tbody>
					</table>
				</div>
			</div>
		{:else if vistaActual === 'compras-proveedor'}
			<div class="bloque">
				<div class="bh">
					<span class="bt">Compras por proveedor</span>
					<span class="bs">{conCompras} proveedores con compras en el período</span>
				</div>
				<div class="tabla-wrap" style="max-height:none">
					<table>
						<thead>
							<tr>
								<th>Proveedor</th><th>CUIT</th><th class="r">Órdenes</th>
								<th class="r">Subtotal</th><th class="r">IVA</th><th class="r">Total</th>
								<th>Primera</th><th>Última</th><th class="r">Saldo CC</th>
							</tr>
						</thead>
						<tbody>
							{#if filasCompras.length}
								{#each filasCompras as f, i (i)}
									<tr>
										<td>{f.nombre}</td>
										<td class="muted">{f.cuit}</td>
										<td class="r muted">{f.cantidad_ordenes}</td>
										<td class="r">{pesos(f.subtotal, true)}</td>
										<td class="r muted">{pesos(f.iva, true)}</td>
										<td class="r">{pesos(f.total, true)}</td>
										<td class="muted">{f.primera ?? '—'}</td>
										<td class="muted">{f.ultima ?? '—'}</td>
										<td class="r {f.saldo_cuenta_corriente > 0 ? '' : 'muted'}">{pesos(f.saldo_cuenta_corriente, true)}</td>
									</tr>
								{/each}
							{:else}
								<tr><td colspan="9" class="empty">Sin compras en el período.</td></tr>
							{/if}
						</tbody>
					</table>
				</div>
			</div>
		{:else if vistaActual === 'flujo-caja'}
			{#if !serieFlujo.length}
				<div class="empty">Sin movimientos en el período.</div>
			{:else}
				<div class="bloque">
					<div class="bh"><span class="bt">Flujo de caja — {agrupar === 'dia' ? 'por día' : agrupar === 'semana' ? 'por semana' : 'por mes'}</span></div>
					<div class="chart-pad" style="height:260px"><canvas use:chartjs={chartFlujoConfig}></canvas></div>
				</div>
				<div class="bloque">
					<div class="bh"><span class="bt">Serie detallada</span><span class="bs">{serieFlujo.length} períodos</span></div>
					<div class="tabla-wrap" style="max-height:none">
						<table>
							<thead>
								<tr>
									<th>Período</th><th class="r">Ventas cobr.</th><th class="r">Cobros/Ingr.</th>
									<th class="r">Egresos</th><th class="r">Retiros</th>
									<th class="r">Saldo neto</th><th class="r">Saldo acum.</th>
								</tr>
							</thead>
							<tbody>
								{#each serieFlujo as s, i (i)}
									<tr>
										<td>{s.periodo}</td>
										<td class="r">{pesos(s.ingresos, true)}</td>
										<td class="r">{pesos(s.ingresos_caja, true)}</td>
										<td class="r muted">{pesos(s.egresos, true)}</td>
										<td class="r muted">{pesos(s.retiros, true)}</td>
										<td class="r" style={s.saldo_neto < 0 ? 'color:var(--neo-danger);font-weight:700' : ''}>{pesos(s.saldo_neto, true)}</td>
										<td class="r" style={s.saldo_acum < 0 ? 'color:var(--neo-danger);font-weight:700' : 'color:var(--neo-success);font-weight:700'}>{pesos(s.saldo_acum, true)}</td>
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
				</div>
			{/if}
		{/if}
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
		padding: 8px 14px;
		box-shadow: var(--neo-e2);
		flex-shrink: 0;
	}
	.tab-grupo {
		display: flex;
		border-radius: var(--neo-r-sm);
		overflow: hidden;
		box-shadow: var(--neo-e1);
	}
	.tab-vista {
		padding: 7px 16px;
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
	.tab-vista.activo {
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
		white-space: nowrap;
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
	.btn-export {
		padding: 7px 14px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 11px;
		font-weight: 700;
		font-family: inherit;
		cursor: pointer;
		box-shadow: var(--neo-e1);
		background: var(--neo-bg);
		color: var(--neo-success);
		text-transform: uppercase;
		letter-spacing: 0.3px;
	}
	.btn-export:hover {
		box-shadow: var(--neo-e2);
	}
	.kpis-row {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 10px;
		flex-shrink: 0;
	}
	.kcard {
		background: var(--neo-bg);
		box-shadow: var(--neo-e2);
		border-radius: var(--neo-r-lg);
		padding: 14px 16px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.klbl {
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.7px;
		color: var(--neo-text-3);
	}
	.kval {
		font-size: 22px;
		font-weight: 800;
		color: var(--neo-text);
		font-variant-numeric: tabular-nums;
		letter-spacing: -0.5px;
	}
	.kval.g {
		color: var(--neo-success);
	}
	.kval.d {
		color: var(--neo-danger);
	}
	.kdtl {
		font-size: 10px;
		color: var(--neo-text-3);
	}
	.content-scroll {
		flex: 1;
		overflow-y: auto;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.bloque {
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e2);
		overflow: hidden;
	}
	.bh {
		padding: 12px 16px;
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		align-items: center;
		justify-content: space-between;
	}
	.bt {
		font-size: 11px;
		font-weight: 700;
		color: var(--neo-text);
		text-transform: uppercase;
		letter-spacing: 0.4px;
	}
	.bs {
		font-size: 11px;
		color: var(--neo-text-3);
	}
	.chart-pad {
		padding: 14px;
		height: 240px;
		position: relative;
	}
	.grid2 {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 10px;
	}
	.tabla-wrap {
		overflow-x: auto;
		max-height: 360px;
		overflow-y: auto;
	}
	table {
		width: 100%;
		border-collapse: collapse;
		font-size: 11px;
	}
	thead th {
		position: sticky;
		top: 0;
		background: var(--neo-bg);
		padding: 8px 12px;
		text-align: left;
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	thead th.r {
		text-align: right;
	}
	tbody tr {
		border-bottom: 1px solid var(--borde-fuerte);
	}
	tbody tr:hover {
		background: var(--color-bg-alt);
	}
	tbody td {
		padding: 7px 12px;
		color: var(--neo-text);
	}
	tbody td.r {
		text-align: right;
		font-variant-numeric: tabular-nums;
	}
	tbody td.muted {
		color: var(--neo-text-3);
		font-size: 10px;
	}
	.rot-bar {
		display: flex;
		align-items: center;
		gap: 6px;
	}
	.rot-track {
		flex: 1;
		height: 4px;
		background: var(--color-bg-alt);
		border-radius: 2px;
		min-width: 60px;
	}
	.rot-fill {
		height: 4px;
		border-radius: 2px;
		background: var(--neo-accent);
	}
	.rot-val {
		font-size: 10px;
		font-weight: 700;
		color: var(--neo-text-3);
		min-width: 32px;
		text-align: right;
	}
	.badge {
		display: inline-block;
		padding: 2px 7px;
		font-size: 9px;
		font-weight: 800;
		border-radius: var(--neo-r-xs);
		box-shadow: var(--neo-e1);
	}
	.badge-ok {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success);
	}
	.badge-zero {
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.empty {
		padding: 24px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 12px;
		font-style: italic;
	}
	.pago-tipo {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		padding: 14px 16px;
	}
	.pago-chip {
		padding: 5px 12px;
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-e1);
		font-size: 11px;
	}
	.pago-chip b {
		color: var(--neo-accent);
	}
</style>
