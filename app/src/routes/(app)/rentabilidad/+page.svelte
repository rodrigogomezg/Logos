<script lang="ts">
	import type { ChartConfiguration } from 'chart.js';
	import { api } from '$lib/api';
	import { chartjs, colorSet } from '$lib/chart-action';

	type Vista = 'resumen' | 'tendencia' | 'abc' | 'clientes' | 'margen';
	type Grupo = { grupo: string; ventas: number; ganancia: number; margen_pct: number };
	type Kpis = { ventas: number; costo: number; ganancia: number; margen_pct: number };
	type TendenciaFila = { mes: string; ventas: number; costo: number; ganancia: number; margen_pct: number };
	type ProductoAbc = { codigo: string; nombre: string; categoria: string; proveedor: string; cantidad: number; ventas: number; costo: number; ganancia: number; margen_pct: number; clase_abc: 'A' | 'B' | 'C'; pct_ganancia_acum: number };
	type ClienteFila = { nombre: string; operaciones: number; ventas: number; costo: number; ganancia: number; margen_pct: number; pct_ganancia: number };
	type ProductoMargen = { codigo: string; nombre: string; categoria: string; proveedor: string; costo_actual: number; precio_venta: number; margen_pct: number; stock_actual: number };

	function pesos(n: number | null | undefined, corto = false) {
		const v = Number(n ?? 0);
		if (corto && Math.abs(v) >= 1000000) return '$ ' + (v / 1000000).toFixed(1) + 'M';
		if (corto && Math.abs(v) >= 1000) return '$ ' + (v / 1000).toFixed(1) + 'k';
		return '$ ' + v.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	const hoy = new Date();
	let vistaActual = $state<Vista>('resumen');
	let desde = $state(`${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-01`);
	let hasta = $state(hoy.toISOString().slice(0, 10));
	let meses = $state('12');
	let umbralMargen = $state(20);
	let umbralInput = $state('20');
	let cargando = $state(false);
	let errorMsg = $state('');
	let kpis = $state<Kpis>({ ventas: 0, costo: 0, ganancia: 0, margen_pct: 0 });

	let categorias = $state<Grupo[]>([]);
	let proveedores = $state<Grupo[]>([]);
	let tendencia = $state<TendenciaFila[]>([]);
	let productosAbc = $state<ProductoAbc[]>([]);
	let clientes = $state<ClienteFila[]>([]);
	let productosMargen = $state<ProductoMargen[]>([]);

	const esTendencia = $derived(vistaActual === 'tendencia');
	const esMargen = $derived(vistaActual === 'margen');
	const mostrarFechas = $derived(!esTendencia && !esMargen);
	const mostrarKpis = $derived(!esTendencia && !esMargen);
	const countA = $derived(productosAbc.filter((p) => p.clase_abc === 'A').length);
	const countB = $derived(productosAbc.filter((p) => p.clase_abc === 'B').length);
	const countC = $derived(productosAbc.filter((p) => p.clase_abc === 'C').length);
	const negativosMargen = $derived(productosMargen.filter((p) => p.margen_pct < 0).length);

	function setVista(v: Vista) {
		vistaActual = v;
		cargarVista();
	}

	async function cargarVista() {
		errorMsg = '';
		cargando = true;
		try {
			if (vistaActual === 'resumen') await cargarResumen();
			if (vistaActual === 'tendencia') await cargarTendencia();
			if (vistaActual === 'abc') await cargarAbc();
			if (vistaActual === 'clientes') await cargarClientes();
			if (vistaActual === 'margen') await cargarMargen();
		} catch (e) {
			errorMsg = 'Error al cargar datos: ' + (e instanceof Error ? e.message : String(e));
		}
		cargando = false;
	}

	async function cargarResumen() {
		if (!desde || !hasta) return;
		const res = await api(`/rentabilidad/resumen?desde=${desde}&hasta=${hasta}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		kpis = data;
		categorias = data.por_categoria || [];
		proveedores = data.por_proveedor || [];
	}

	async function cargarTendencia() {
		const res = await api(`/rentabilidad/tendencia?meses=${meses}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		tendencia = data.datos || [];
	}

	async function cargarAbc() {
		if (!desde || !hasta) return;
		const res = await api(`/rentabilidad/abc?desde=${desde}&hasta=${hasta}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		productosAbc = data.productos || [];
		const ventasTot = productosAbc.reduce((s, p) => s + p.ventas, 0);
		kpis = {
			ventas: ventasTot,
			costo: productosAbc.reduce((s, p) => s + p.costo, 0),
			ganancia: data.total_ganancia,
			margen_pct: productosAbc.length && productosAbc[0].ventas > 0 ? Math.round((data.total_ganancia / ventasTot) * 1000) / 10 : 0
		};
	}

	async function cargarClientes() {
		if (!desde || !hasta) return;
		const res = await api(`/rentabilidad/clientes?desde=${desde}&hasta=${hasta}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		clientes = data.filas || [];
		kpis = data.totales;
	}

	async function cargarMargen() {
		const res = await api(`/rentabilidad/margen-critico?umbral=${umbralMargen}`);
		const data = await res.json();
		if (!res.ok) throw new Error(data.error || 'Error');
		productosMargen = data.productos || [];
	}

	function aplicarUmbral() {
		umbralMargen = Math.max(0, Math.min(90, parseInt(umbralInput) || 20));
		umbralInput = String(umbralMargen);
		cargarVista();
	}

	const chartOptsBase = {
		responsive: true,
		maintainAspectRatio: false,
		plugins: { legend: { display: false } },
		scales: {
			x: { ticks: { font: { size: 10 }, color: '#9B9590', maxRotation: 30 }, grid: { display: false } },
			y: { ticks: { font: { size: 10 }, color: '#9B9590', callback: (v: unknown) => pesos(Number(v), true) }, grid: { color: '#E4DFD3' } }
		}
	};

	const chartCatConfig = $derived<ChartConfiguration>({
		type: 'bar',
		options: chartOptsBase,
		data: {
			labels: categorias.slice(0, 10).map((c) => c.grupo),
			datasets: [{ label: 'Ganancia', data: categorias.slice(0, 10).map((c) => c.ganancia), backgroundColor: colorSet(categorias.length), borderRadius: 4 }]
		}
	});
	const chartProvConfig = $derived<ChartConfiguration>({
		type: 'bar',
		options: chartOptsBase,
		data: {
			labels: proveedores.slice(0, 10).map((p) => p.grupo),
			datasets: [{ label: 'Ganancia', data: proveedores.slice(0, 10).map((p) => p.ganancia), backgroundColor: colorSet(proveedores.length), borderRadius: 4 }]
		}
	});

	const chartTendConfig = $derived<ChartConfiguration>({
		type: 'bar',
		data: {
			labels: tendencia.map((r) => r.mes),
			datasets: [
				{ label: 'Ventas', data: tendencia.map((r) => r.ventas), backgroundColor: '#163B6699', borderRadius: 3 },
				{ label: 'Costo', data: tendencia.map((r) => r.costo), backgroundColor: '#E74C3C99', borderRadius: 3 },
				{ label: 'Ganancia', data: tendencia.map((r) => r.ganancia), backgroundColor: '#27AE6099', borderRadius: 3 }
			]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: { legend: { labels: { font: { size: 10 }, color: '#9B9590' } } },
			scales: {
				x: { ticks: { font: { size: 10 }, color: '#9B9590' }, grid: { display: false } },
				y: { ticks: { font: { size: 10 }, color: '#9B9590', callback: (v) => pesos(Number(v), true) }, grid: { color: '#E4DFD3' } }
			}
		}
	});
	const chartMargenConfig = $derived<ChartConfiguration>({
		type: 'line',
		data: {
			labels: tendencia.map((r) => r.mes),
			datasets: [
				{
					label: 'Margen %',
					data: tendencia.map((r) => r.margen_pct),
					borderColor: '#27AE60',
					backgroundColor: '#27AE6022',
					fill: true,
					tension: 0.3,
					pointRadius: 4,
					pointBackgroundColor: '#27AE60'
				}
			]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: { legend: { display: false } },
			scales: {
				x: { ticks: { font: { size: 10 }, color: '#9B9590' }, grid: { display: false } },
				y: { ticks: { font: { size: 10 }, color: '#9B9590', callback: (v) => v + '%' }, grid: { color: '#E4DFD3' } }
			}
		}
	});

	function margenBarPct(pct: number) {
		return Math.min(100, Math.max(0, pct));
	}

	cargarVista();
</script>

<svelte:head>
	<title>Rentabilidad — Logos</title>
</svelte:head>

<div class="page-body">
	<div class="toolbar">
		<div class="tab-grupo">
			<button class="tab-vista" class:activo={vistaActual === 'resumen'} onclick={() => setVista('resumen')}>Resumen</button>
			<button class="tab-vista" class:activo={vistaActual === 'tendencia'} onclick={() => setVista('tendencia')}>Tendencia</button>
			<button class="tab-vista" class:activo={vistaActual === 'abc'} onclick={() => setVista('abc')}>ABC Productos</button>
			<button class="tab-vista" class:activo={vistaActual === 'clientes'} onclick={() => setVista('clientes')}>Por Cliente</button>
			<button class="tab-vista" class:activo={vistaActual === 'margen'} onclick={() => setVista('margen')}>Margen crítico</button>
		</div>
		<div class="tb-sep"></div>
		{#if mostrarFechas}
			<div style="display:flex;align-items:center;gap:8px">
				<span class="tb-lbl">Desde</span>
				<input type="date" class="tb-input" bind:value={desde} />
				<span class="tb-lbl">Hasta</span>
				<input type="date" class="tb-input" bind:value={hasta} />
			</div>
		{/if}
		<button class="btn-accion" onclick={cargarVista}>Aplicar</button>
		{#if esTendencia}
			<div class="tb-sep"></div>
			<span class="tb-lbl">Meses</span>
			<select class="tb-input" style="width:70px" bind:value={meses} onchange={cargarVista}>
				<option value="6">6</option>
				<option value="12">12</option>
				<option value="24">24</option>
			</select>
		{/if}
	</div>

	{#if mostrarKpis}
		<div class="kpis-row">
			<div class="kcard"><span class="klbl">Ventas</span><span class="kval">{pesos(kpis.ventas, true)}</span></div>
			<div class="kcard"><span class="klbl">Costo</span><span class="kval">{pesos(kpis.costo, true)}</span></div>
			<div class="kcard"><span class="klbl">Ganancia bruta</span><span class="kval g">{pesos(kpis.ganancia, true)}</span></div>
			<div class="kcard"><span class="klbl">Margen</span><span class="kval g">{kpis.margen_pct}%</span><span class="kdtl">sobre ventas</span></div>
		</div>
	{/if}

	<div class="content-scroll">
		{#if cargando}
			<div class="empty">Cargando…</div>
		{:else if errorMsg}
			<div class="empty">{errorMsg}</div>
		{:else if vistaActual === 'resumen'}
			<div class="grid2">
				<div class="bloque">
					<div class="bh"><span class="bt">Por Categoría</span><span class="bs">{categorias.length} categorías</span></div>
					<div class="chart-pad">
						{#if categorias.length}
							<canvas use:chartjs={chartCatConfig}></canvas>
						{/if}
					</div>
				</div>
				<div class="bloque">
					<div class="bh"><span class="bt">Por Proveedor</span><span class="bs">{proveedores.length} proveedores</span></div>
					<div class="chart-pad">
						{#if proveedores.length}
							<canvas use:chartjs={chartProvConfig}></canvas>
						{/if}
					</div>
				</div>
			</div>
			<div class="grid2">
				<div class="bloque">
					<div class="bh"><span class="bt">Detalle por Categoría</span></div>
					<div class="tabla-wrap">
						{#if categorias.length}
							<table>
								<thead><tr><th>Grupo</th><th class="r">Ventas</th><th class="r">Ganancia</th><th>Margen</th></tr></thead>
								<tbody>
									{#each categorias as c, i (i)}
										<tr>
											<td>{c.grupo}</td>
											<td class="r">{pesos(c.ventas, true)}</td>
											<td class="r">{pesos(c.ganancia, true)}</td>
											<td>
												<div class="margen-bar">
													<div class="bar-track"><div class="bar-fill" style="width:{margenBarPct(c.margen_pct)}%"></div></div>
													<span class="bar-pct">{c.margen_pct.toFixed(1)}%</span>
												</div>
											</td>
										</tr>
									{/each}
								</tbody>
							</table>
						{:else}
							<div class="empty">Sin datos</div>
						{/if}
					</div>
				</div>
				<div class="bloque">
					<div class="bh"><span class="bt">Detalle por Proveedor</span></div>
					<div class="tabla-wrap">
						{#if proveedores.length}
							<table>
								<thead><tr><th>Grupo</th><th class="r">Ventas</th><th class="r">Ganancia</th><th>Margen</th></tr></thead>
								<tbody>
									{#each proveedores as p, i (i)}
										<tr>
											<td>{p.grupo}</td>
											<td class="r">{pesos(p.ventas, true)}</td>
											<td class="r">{pesos(p.ganancia, true)}</td>
											<td>
												<div class="margen-bar">
													<div class="bar-track"><div class="bar-fill" style="width:{margenBarPct(p.margen_pct)}%"></div></div>
													<span class="bar-pct">{p.margen_pct.toFixed(1)}%</span>
												</div>
											</td>
										</tr>
									{/each}
								</tbody>
							</table>
						{:else}
							<div class="empty">Sin datos</div>
						{/if}
					</div>
				</div>
			</div>
		{:else if vistaActual === 'tendencia'}
			{#if !tendencia.length}
				<div class="empty">Sin datos de ventas en el período.</div>
			{:else}
				<div class="bloque">
					<div class="bh"><span class="bt">Ventas vs Costo — últimos {meses} meses</span></div>
					<div class="chart-pad" style="height:280px"><canvas use:chartjs={chartTendConfig}></canvas></div>
				</div>
				<div class="bloque">
					<div class="bh"><span class="bt">Margen mensual</span></div>
					<div class="chart-pad"><canvas use:chartjs={chartMargenConfig}></canvas></div>
				</div>
			{/if}
		{:else if vistaActual === 'abc'}
			<div class="bloque">
				<div class="bh">
					<span class="bt">Clasificación ABC</span>
					<span class="bs">A: {countA} productos · B: {countB} · C: {countC}</span>
				</div>
				<div class="tabla-wrap" style="max-height:none">
					<table>
						<thead>
							<tr>
								<th>#</th><th>Clase</th><th>Código</th><th>Nombre</th>
								<th>Categoría</th><th>Proveedor</th>
								<th class="r">Cant.</th><th class="r">Ventas</th>
								<th class="r">Ganancia</th><th>Margen</th><th class="r">% Acum.</th>
							</tr>
						</thead>
						<tbody>
							{#if productosAbc.length}
								{#each productosAbc as p, i (i)}
									<tr>
										<td class="muted">{i + 1}</td>
										<td><span class="abc abc-{p.clase_abc}">{p.clase_abc}</span></td>
										<td>{p.codigo}</td>
										<td>{p.nombre}</td>
										<td class="muted">{p.categoria}</td>
										<td class="muted">{p.proveedor}</td>
										<td class="r">{p.cantidad % 1 === 0 ? p.cantidad : p.cantidad.toFixed(2)}</td>
										<td class="r">{pesos(p.ventas, true)}</td>
										<td class="r">{pesos(p.ganancia, true)}</td>
										<td>
											<div class="margen-bar">
												<div class="bar-track"><div class="bar-fill" style="width:{margenBarPct(p.margen_pct)}%"></div></div>
												<span class="bar-pct">{p.margen_pct.toFixed(1)}%</span>
											</div>
										</td>
										<td class="r muted">{p.pct_ganancia_acum}%</td>
									</tr>
								{/each}
							{:else}
								<tr><td colspan="11" class="empty">Sin datos para el período.</td></tr>
							{/if}
						</tbody>
					</table>
				</div>
			</div>
		{:else if vistaActual === 'clientes'}
			<div class="bloque">
				<div class="bh">
					<span class="bt">Ganancia por cliente</span>
					<span class="bs">{clientes.length} clientes con ventas en el período</span>
				</div>
				<div class="tabla-wrap" style="max-height:none">
					<table>
						<thead>
							<tr>
								<th>#</th><th>Cliente</th><th class="r">Ops.</th>
								<th class="r">Ventas</th><th class="r">Costo</th>
								<th class="r">Ganancia</th><th>Margen</th><th class="r">% Ganancia</th>
							</tr>
						</thead>
						<tbody>
							{#if clientes.length}
								{#each clientes as c, i (i)}
									<tr>
										<td class="muted">{i + 1}</td>
										<td>{c.nombre}</td>
										<td class="r muted">{c.operaciones}</td>
										<td class="r">{pesos(c.ventas, true)}</td>
										<td class="r muted">{pesos(c.costo, true)}</td>
										<td class="r" style={c.ganancia < 0 ? 'color:var(--neo-danger);font-weight:700' : ''}>{pesos(c.ganancia, true)}</td>
										<td>
											<div class="margen-bar">
												<div class="bar-track"><div class="bar-fill" style="width:{margenBarPct(c.margen_pct)}%"></div></div>
												<span class="bar-pct">{c.margen_pct.toFixed(1)}%</span>
											</div>
										</td>
										<td class="r muted">{c.pct_ganancia}%</td>
									</tr>
								{/each}
							{:else}
								<tr><td colspan="8" class="empty">Sin ventas en el período.</td></tr>
							{/if}
						</tbody>
					</table>
				</div>
			</div>
		{:else if vistaActual === 'margen'}
			<div class="bloque">
				<div class="bh">
					<span class="bt">Margen crítico — precio vs costo actuales</span>
					<span class="bs" style="display:flex;align-items:center;gap:8px">
						{#if negativosMargen}<b style="color:var(--neo-danger)">{negativosMargen} bajo costo</b> ·{/if}
						{productosMargen.length} productos con margen menor a
						<input type="number" min="0" max="90" step="1" style="width:56px;padding:3px 6px;font-family:inherit;font-size:12px" bind:value={umbralInput} onchange={aplicarUmbral} /> %
					</span>
				</div>
				<div class="empty" style="padding:8px 14px;text-align:left;font-size:12px">
					Compara el precio de venta vigente contra el costo actual de cada producto — detecta listas desactualizadas. No depende
					del período: es una foto del catálogo de hoy.
				</div>
				<div class="tabla-wrap" style="max-height:none">
					<table>
						<thead>
							<tr>
								<th>Código</th><th>Nombre</th><th>Categoría</th><th>Proveedor</th>
								<th class="r">Costo</th><th class="r">Precio</th><th class="r">Margen</th><th class="r">Stock</th>
							</tr>
						</thead>
						<tbody>
							{#if productosMargen.length}
								{#each productosMargen as p, i (i)}
									<tr>
										<td>{p.codigo}</td>
										<td>{p.nombre}</td>
										<td class="muted">{p.categoria}</td>
										<td class="muted">{p.proveedor}</td>
										<td class="r">{pesos(p.costo_actual, true)}</td>
										<td class="r">{pesos(p.precio_venta, true)}</td>
										<td class="r" style="font-weight:700;color:{p.margen_pct < 0 ? 'var(--neo-danger)' : '#B7791F'}">{p.margen_pct.toFixed(1)}%</td>
										<td class="r muted">{p.stock_actual % 1 === 0 ? p.stock_actual : p.stock_actual.toFixed(2)}</td>
									</tr>
								{/each}
							{:else}
								<tr><td colspan="8" class="empty">Ningún producto con margen bajo el umbral.</td></tr>
							{/if}
						</tbody>
					</table>
				</div>
			</div>
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
		max-height: 320px;
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
	.abc {
		display: inline-block;
		padding: 2px 7px;
		font-size: 9px;
		font-weight: 800;
		border-radius: var(--neo-r-xs);
		box-shadow: var(--neo-e1);
	}
	.abc-A {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success);
	}
	.abc-B {
		background: var(--primary-soft-2);
		color: var(--neo-accent);
	}
	.abc-C {
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.margen-bar {
		display: flex;
		align-items: center;
		gap: 6px;
	}
	.bar-track {
		flex: 1;
		height: 4px;
		background: var(--color-bg-alt);
		border-radius: 2px;
		min-width: 60px;
	}
	.bar-fill {
		height: 4px;
		border-radius: 2px;
		background: var(--neo-success);
	}
	.bar-pct {
		font-size: 10px;
		font-weight: 700;
		color: var(--neo-text-3);
		min-width: 36px;
		text-align: right;
	}
	.empty {
		padding: 24px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 12px;
		font-style: italic;
	}
</style>
