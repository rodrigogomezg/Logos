<script lang="ts">
	import { onMount } from 'svelte';
	import type { ChartConfiguration } from 'chart.js';
	import { api } from '$lib/api';
	import { leerSesion, puede } from '$lib/session';
	import { cajaOperativaId } from '$lib/operativa';
	import { chartjs } from '$lib/chart-action';
	import { setTourSteps, type TourStep } from '$lib/tour';

	type DashData = {
		total_ventas?: number;
		count_ventas?: number;
		ganancia_bruta?: number;
		margen_pct?: number;
		por_dia?: { dia: string; total_ventas: number }[];
		por_hora?: { hora: number; total: number }[] | null;
		medios_pago?: { tipo: string; total: number }[];
		top_productos?: { nombre: string; cant: number; ventas: number }[];
		error?: string;
	};
	type CajaActual = {
		turno: { fondo_inicial: number | string; usuario_nombre: string; caja_nombre: string; abierto_en: string } | null;
		total_efectivo?: number;
		count_total?: number;
		ventas_efectivo?: number;
		ventas_tarjeta?: number;
		ventas_transferencia?: number;
		ventas_cc?: number;
	};
	type StockItem = { nombre: string; stock_actual: number; stock_minimo: number };
	type FeedLogItem = { fecha: string; accion: string; detalle: string | null; usuario_nom?: string; entidad?: string; entidad_id?: number };
	type FeedVentaItem = { numero: number | string; estado: string; tipo_pago: string; fecha: string; cliente_nombre?: string; total: number };
	type TurnoRow = {
		estado: string;
		cerrado_en: string | null;
		abierto_en: string;
		total_efectivo: number | string;
		efectivo_esperado: number | string;
		efectivo_contado: number | string;
		diferencia: number | string;
		usuario_nombre: string;
		usuario_id: number | string;
	};
	type AgingData = { totales?: { vencido: number; saldo: number }; filas?: { id: number; vencido: number }[] };
	type UsuarioRow = { id: number | string; nombre: string; rol: string; caja_nombre: string | null };
	type VendedorVentas = { id: number; nombre: string; total: number; monto_comisionado: number; cant_ventas: number; ticket_promedio: number; meta_monto: number | null };
	type DmpReporte = {
		kpi: { total: number; comision: number; cant: number; ticket_promedio: number };
		vendedor: { meta_monto: number | null };
		series: { dia?: string; turno?: string; label?: string; periodo?: string; total: number }[];
	};
	type ComisionesData = { cerrado: boolean; cerrado_en?: string | null; filas: { vendedor_nombre: string; total_ventas: number; total_comision: number; cant_ventas: number }[] };

	const sesion = leerSesion();
	const esAdmin = sesion?.rol === 'admin';
	const veLog = puede('log');
	const veCajas = puede('cajas_todas');
	const veCosto = puede('costos');
	const veCc = puede('cc_ver');
	const puedeVendedores = puede('gestionar_vendedores');

	const pad = (n: number) => String(n).padStart(2, '0');
	const fmtD = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
	const hoyFecha = () => fmtD(new Date());
	const moneyFmt = (n: number | string | null | undefined) => '$' + Math.round(Number(n) || 0).toLocaleString('es-AR');

	const C = { azul: '#163B66', verde: '#27ae60', rojo: '#e74c3c', bajo: '#f39c12', morado: '#7c3aed', gris: '#9B9590', borde: '#E4DFD3' };
	const TIPO_LABELS: Record<string, string> = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transf.', cc: 'Cta. Cte.', cheque: 'Cheque', mercado_pago: 'Mercado Pago' };
	const TIPO_COLORS: Record<string, string> = { efectivo: C.verde, tarjeta: C.azul, transferencia: C.morado, cc: C.bajo, cheque: '#0d9488', mercado_pago: '#db2777' };

	function calcFechas(tipo: string, deVal: string, haVal: string) {
		const h = new Date();
		if (tipo === 'hoy') return { desde: hoyFecha(), hasta: hoyFecha() };
		if (tipo === 'semana') {
			const l = new Date(h);
			const dow = h.getDay();
			l.setDate(h.getDate() - (dow === 0 ? 6 : dow - 1));
			return { desde: fmtD(l), hasta: hoyFecha() };
		}
		if (tipo === 'mes') return { desde: `${h.getFullYear()}-${pad(h.getMonth() + 1)}-01`, hasta: hoyFecha() };
		if (tipo === 'anio') return { desde: `${h.getFullYear()}-01-01`, hasta: hoyFecha() };
		return { desde: deVal || hoyFecha(), hasta: haVal || hoyFecha() };
	}
	function calcPrevRango(desde: string, hasta: string, tipo: string) {
		const MS = 86400000;
		const d1 = new Date(desde + 'T00:00:00');
		const d2 = new Date(hasta + 'T00:00:00');
		if (tipo === 'hoy') {
			const p = new Date(d1.getTime() - 7 * MS);
			return { desde: fmtD(p), hasta: fmtD(p) };
		}
		const dias = Math.round((d2.getTime() - d1.getTime()) / MS) + 1;
		const pHasta = new Date(d1.getTime() - MS);
		const pDesde = new Date(d1.getTime() - dias * MS);
		return { desde: fmtD(pDesde), hasta: fmtD(pHasta) };
	}
	function ultimos6Meses() {
		const meses: { label: string; desde: string; hasta: string }[] = [];
		const h = new Date();
		for (let i = 5; i >= 0; i--) {
			const d = new Date(h.getFullYear(), h.getMonth() - i, 1);
			const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
			meses.push({ label: d.toLocaleString('es-AR', { month: 'short' }), desde: fmtD(d), hasta: i === 0 ? hoyFecha() : fmtD(last) });
		}
		return meses;
	}
	function deltaChip(actual: number, previo: number | null): { texto: string; cls: string } | null {
		if (previo == null || previo <= 0) return null;
		const pct = ((actual - previo) / previo) * 100;
		if (!isFinite(pct)) return null;
		return { texto: `${pct >= 0 ? '↑' : '↓'}${Math.abs(pct).toFixed(0)}%`, cls: pct >= 0 ? 'delta-up' : 'delta-down' };
	}

	let periodoTipo = $state('hoy');
	let desde = $state(hoyFecha());
	let hasta = $state(hoyFecha());
	let refrescando = $state(false);

	let dash = $state<DashData | null>(null);
	let cajaData = $state<CajaActual | null>(null);
	let prevDash = $state<DashData | null>(null);
	let stockBajo = $state<StockItem[]>([]);
	let feedLog = $state<FeedLogItem[]>([]);
	let feedVentas = $state<FeedVentaItem[]>([]);
	let turnos = $state<TurnoRow[] | null>(null);
	let aging = $state<AgingData | null>(null);
	let usuarios = $state<UsuarioRow[]>([]);
	let usuariosListo = $state(false);
	let tendenciaMeses = $state<{ label: string; desde: string; hasta: string }[]>([]);
	let tendenciaDatos = $state<(DashData | null)[]>([]);
	let productosTab = $state<'top' | 'crit'>('top');

	async function cargar() {
		refrescando = true;
		const { desde: d, hasta: h } = calcFechas(periodoTipo, desde, hasta);
		desde = d;
		hasta = h;
		const cajaId = cajaOperativaId();
		const prevRango = calcPrevRango(d, h, periodoTipo);
		const turnosUrl = veCajas ? '/caja-turnos' : cajaId ? `/caja-turnos?caja_id=${cajaId}` : null;
		const feedUrl = veLog ? '/log-acciones?limit=25' : `/ventas?fecha_desde=${hoyFecha()}&fecha_hasta=${hoyFecha()}&limit=20`;

		const [r1, r2, r3, r4, r5, r6, r7] = await Promise.allSettled([
			api(`/ventas/dashboard?desde=${d}&hasta=${h}`).then((r) => r.json()),
			cajaId ? api(`/caja-turnos/actual?caja_id=${cajaId}`).then((r) => r.json()) : Promise.resolve(null),
			api('/stock?stock_filter=stock_bajo&per_page=20').then((r) => r.json()),
			api(feedUrl).then((r) => r.json()),
			turnosUrl ? api(turnosUrl).then((r) => r.json()) : Promise.resolve(null),
			veCc ? api('/cc/aging?entidad_tipo=cliente').then((r) => r.json()) : Promise.resolve(null),
			api(`/ventas/dashboard?desde=${prevRango.desde}&hasta=${prevRango.hasta}`).then((r) => r.json())
		]);

		dash = r1.status === 'fulfilled' && r1.value && !r1.value.error ? r1.value : null;
		cajaData = r2.status === 'fulfilled' ? r2.value : null;
		stockBajo = (r3.status === 'fulfilled' && r3.value?.items) || [];
		if (r4.status === 'fulfilled' && Array.isArray(r4.value)) {
			if (veLog) {
				feedLog = r4.value;
				feedVentas = [];
			} else {
				feedVentas = r4.value;
				feedLog = [];
			}
		} else {
			feedLog = [];
			feedVentas = [];
		}
		turnos = r5.status === 'fulfilled' && Array.isArray(r5.value) ? r5.value : null;
		aging = r6.status === 'fulfilled' ? r6.value : null;
		prevDash = r7.status === 'fulfilled' && r7.value && !r7.value.error ? r7.value : null;

		const meses = ultimos6Meses();
		tendenciaMeses = meses;
		const tendRes = await Promise.allSettled(meses.map((m) => api(`/ventas/dashboard?desde=${m.desde}&hasta=${m.hasta}`).then((r) => r.json())));
		tendenciaDatos = tendRes.map((r) => (r.status === 'fulfilled' && r.value && !r.value.error ? r.value : null));

		if (esAdmin) {
			try {
				const r = await api('/usuarios/todos');
				const u = await r.json();
				usuarios = Array.isArray(u) ? u : [];
			} catch {
				usuarios = [];
			}
			usuariosListo = true;
		}

		refrescando = false;
	}

	function setPeriodo(tipo: string) {
		periodoTipo = tipo;
		const r = calcFechas(tipo, desde, hasta);
		desde = r.desde;
		hasta = r.hasta;
		cargar();
	}
	function onFechaManual() {
		periodoTipo = 'custom';
	}

	// ── KPIs derivados ──────────────────────────────────────────
	const tv = $derived(dash?.total_ventas ?? 0);
	const cv = $derived(dash?.count_ventas ?? 0);
	const gb = $derived(dash?.ganancia_bruta ?? 0);
	const mp = $derived(dash?.margen_pct ?? 0);
	const pTv = $derived(prevDash?.total_ventas ?? null);
	const pCv = $derived(prevDash?.count_ventas ?? null);
	const deltaVentas = $derived(deltaChip(tv, pTv));
	const deltaTxn = $derived(deltaChip(cv, pCv));
	const deltaTicket = $derived(cv > 0 && pCv && pCv > 0 ? deltaChip(tv / cv, pTv ? pTv / pCv : null) : null);

	const cajaId = $derived(cajaOperativaId());
	const turnoActual = $derived(cajaData?.turno ?? null);
	const efEsperado = $derived(turnoActual ? (parseFloat(String(turnoActual.fondo_inicial)) || 0) + (parseFloat(String(cajaData?.total_efectivo)) || 0) : 0);

	let ccAgingData = $state<AgingData | null>(null);
	$effect(() => {
		ccAgingData = aging;
	});
	const deudoresVencidos = $derived((ccAgingData?.filas || []).filter((f) => f.vencido > 0.001));

	function irRentabilidad() {
		location.href = '/rentabilidad';
	}
	function irCuentaCorriente() {
		location.href = deudoresVencidos.length === 1 ? `/cuentacorriente?id=${deudoresVencidos[0].id}` : '/cuentacorriente?vencimientos=1';
	}

	// ── Chart: Ventas del período ───────────────────────────────
	const ventasChartData = $derived.by(() => {
		const porHora = dash?.por_hora ?? null;
		if (porHora && porHora.length) {
			const hMin = Math.min(9, ...porHora.map((h) => h.hora));
			const hMax = Math.max(18, ...porHora.map((h) => h.hora));
			const mapa = Object.fromEntries(porHora.map((h) => [h.hora, h.total]));
			const labels: string[] = [];
			const valores: number[] = [];
			for (let h = hMin; h <= hMax; h++) {
				labels.push(h + ' hs');
				valores.push(mapa[h] ?? 0);
			}
			return { labels, valores, labelSerie: 'Ventas por hora' };
		}
		const dias = dash?.por_dia ?? [];
		return {
			labels: dias.map((d) => {
				const dt = new Date(d.dia + 'T00:00:00');
				return `${pad(dt.getDate())}/${pad(dt.getMonth() + 1)}`;
			}),
			valores: dias.map((d) => d.total_ventas),
			labelSerie: 'Ventas'
		};
	});
	const chartVenConfig = $derived<ChartConfiguration>({
		type: 'bar',
		data: {
			labels: ventasChartData.labels,
			datasets: [{ type: 'bar', label: ventasChartData.labelSerie, data: ventasChartData.valores, backgroundColor: 'rgba(22,59,102,.18)', borderColor: C.azul, borderWidth: 1.5, borderRadius: 4 }]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 9, padding: 10 } } },
			scales: {
				x: { grid: { color: C.borde }, ticks: { color: C.gris } },
				y: { grid: { color: C.borde }, position: 'left', ticks: { color: C.gris, callback: (v) => '$' + (Number(v) / 1000).toFixed(0) + 'k' } }
			}
		}
	});
	const medios = $derived(dash?.medios_pago ?? []);
	const totalMedios = $derived(medios.reduce((s, m) => s + m.total, 0));
	const chartTortaConfig = $derived<ChartConfiguration<'doughnut'>>({
		type: 'doughnut',
		data: {
			labels: medios.map((m) => TIPO_LABELS[m.tipo] ?? m.tipo),
			datasets: [{ data: medios.map((m) => m.total), backgroundColor: medios.map((m) => TIPO_COLORS[m.tipo] ?? C.gris), borderWidth: 0, hoverOffset: 4 }]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			cutout: '70%',
			plugins: {
				legend: { display: false },
				tooltip: {
					callbacks: {
						label: (ctx) => {
							const pct = totalMedios > 0 ? (((ctx.parsed as number) / totalMedios) * 100).toFixed(1) : '0';
							return `${ctx.label}: ${pct}%`;
						}
					}
				}
			}
		}
	});

	// ── Tendencia ────────────────────────────────────────────────
	const tendProyV = $derived(tendenciaDatos.length ? (tendenciaDatos[tendenciaDatos.length - 1]?.total_ventas ?? 0) : 0);
	const tendValidos = $derived(tendenciaDatos.filter((d): d is DashData => !!d && (d.total_ventas ?? 0) > 0));
	const tendProm = $derived(tendValidos.length > 0 ? tendValidos.reduce((s, d) => s + (d.total_ventas ?? 0), 0) / tendValidos.length : 0);
	const tendVs = $derived.by(() => {
		const ult = tendenciaDatos[tendenciaDatos.length - 1];
		const ant = tendenciaDatos[tendenciaDatos.length - 2];
		if (ant && ult && (ant.total_ventas ?? 0) > 0 && (ult.total_ventas ?? 0) > 0) {
			const chg = (((ult.total_ventas ?? 0) - (ant.total_ventas ?? 0)) / (ant.total_ventas ?? 1)) * 100;
			return `${chg > 0 ? '↑' : '↓'} ${Math.abs(chg).toFixed(1)}% vs mes anterior`;
		}
		return '—';
	});
	const tendTieneData = $derived(tendenciaDatos.some((d) => d && (d.total_ventas ?? 0) > 0));
	const chartTendConfig = $derived<ChartConfiguration>({
		type: 'line',
		data: {
			labels: tendenciaMeses.map((m) => m.label),
			datasets: [
				{
					label: 'Ventas',
					data: tendenciaDatos.map((d) => d?.total_ventas ?? 0),
					borderColor: C.azul,
					backgroundColor: 'rgba(22,59,102,.1)',
					fill: true,
					tension: 0.35,
					borderWidth: 2,
					pointRadius: 3,
					pointBackgroundColor: C.azul
				}
			]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 9, padding: 10 } } },
			scales: {
				x: { grid: { color: C.borde }, ticks: { color: C.gris } },
				y: {
					grid: { color: C.borde },
					ticks: {
						color: C.gris,
						callback: (v) => {
							const n = Number(v);
							if (n === 0) return '$0';
							return n >= 1000000 ? '$' + (n / 1000000).toFixed(1) + 'M' : '$' + (n / 1000).toFixed(0) + 'k';
						}
					}
				}
			}
		}
	});

	// ── Caja / cierres ───────────────────────────────────────────
	const cerrados = $derived((turnos ?? []).filter((t) => t.estado === 'cerrado').slice(0, 8));
	function difClase(dif: number) {
		return dif > 0.5 ? 'dp' : dif < -0.5 ? 'dn' : 'd0';
	}
	function difStr(dif: number) {
		return dif > 0.5 ? `+${moneyFmt(dif)}` : dif < -0.5 ? moneyFmt(dif) : '$0';
	}
	function diaCorto(s: string | null) {
		const d = new Date((s || '').replace(' ', 'T'));
		return isNaN(d.getTime()) ? '—' : `${pad(d.getDate())}/${pad(d.getMonth() + 1)}`;
	}

	// ── Feed ─────────────────────────────────────────────────────
	const ALABELS: Record<string, string> = {
		anular_venta: 'Venta anulada',
		cierre_caja: 'Cierre de caja',
		cierre_parcial: 'Cierre parcial',
		ajuste_stock: 'Ajuste de stock',
		crear_usuario: 'Nuevo usuario',
		editar_usuario: 'Usuario editado',
		eliminar_usuario: 'Usuario eliminado',
		registrar_compra: 'Compra registrada',
		cambio_precio: 'Cambio de precio'
	};
	const ADOT: Record<string, string> = { anular_venta: 'a', cierre_caja: 'c', cierre_parcial: 'c', ajuste_stock: 's' };
	const FEED_DK: Record<string, string> = { total: 'Total', tipo_pago: 'Pago', diferencia: 'Diferencia', efectivo_contado: 'Ef. contado', nombre: 'Producto', delta: 'Cambio', precio_nuevo: 'Precio nuevo', tipo: 'Tipo' };
	const FEED_KEYS: Record<string, string[]> = {
		anular_venta: ['total', 'tipo_pago'],
		cierre_caja: ['diferencia', 'efectivo_contado'],
		cierre_parcial: ['diferencia', 'efectivo_contado'],
		ajuste_stock: ['nombre', 'delta'],
		registrar_compra: ['total'],
		cambio_precio: ['nombre', 'precio_nuevo']
	};
	const FEED_AMT = new Set(['total', 'diferencia', 'efectivo_contado', 'precio_nuevo', 'precio_anterior']);

	function feedLogDetalle(item: FeedLogItem): string {
		try {
			const j = JSON.parse(item.detalle ?? 'null');
			if (j && typeof j === 'object') {
				const keys = FEED_KEYS[item.accion] ?? Object.keys(j).slice(0, 2);
				return keys
					.map((k) => {
						if (!(k in j)) return null;
						const lbl = FEED_DK[k];
						if (!lbl) return null;
						const val = FEED_AMT.has(k) && !isNaN(Number(j[k])) ? moneyFmt(Number(j[k])) : j[k];
						return `${lbl}: ${val}`;
					})
					.filter(Boolean)
					.join(' · ');
			}
		} catch {
			// detalle no es JSON válido — se omite
		}
		return '';
	}
	function feedLogMeta(item: FeedLogItem): string {
		const dt = new Date((item.fecha || '').replace(' ', 'T'));
		const hora = isNaN(dt.getTime()) ? '' : `${pad(dt.getDate())}/${pad(dt.getMonth() + 1)} ${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
		return [item.usuario_nom, item.entidad && item.entidad_id ? `${item.entidad} #${item.entidad_id}` : null, hora].filter(Boolean).join(' · ');
	}
	function feedVentaHora(v: FeedVentaItem): string {
		const dt = new Date((v.fecha || '').replace(' ', 'T'));
		return isNaN(dt.getTime()) ? '' : `${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
	}

	// ── Usuarios ─────────────────────────────────────────────────
	const turnoAbierto = $derived((turnos ?? []).find((t) => t.estado === 'abierto') ?? null);
	function usuarioEnTurno(u: UsuarioRow) {
		return !!turnoAbierto && parseInt(String(turnoAbierto.usuario_id)) === parseInt(String(u.id));
	}

	cargar();

	// ══════════════════════════════════════════════════════════════
	// B8 — Ventas por vendedor
	// ══════════════════════════════════════════════════════════════
	function rangoMesActual() {
		const h = new Date();
		h.setDate(1);
		return { desde: fmtD(h), hasta: hoyFecha() };
	}
	let vxvPeriodo = $state('mes');
	let vxvDesde = $state(rangoMesActual().desde);
	let vxvHasta = $state(rangoMesActual().hasta);
	let vxvDatos = $state<VendedorVentas[]>([]);
	const vxvColores = $derived(vxvDatos.map((_, i) => `hsl(${(i * 47 + 200) % 360},60%,55%)`));

	async function cargarVxv() {
		if (!vxvDesde || !vxvHasta) return;
		const r = await api(`/vendedores/reporte/ventas?desde=${vxvDesde}&hasta=${vxvHasta}`);
		if (!r.ok) return;
		vxvDatos = await r.json();
	}
	function setRangoVxv(d: string, h: string) {
		vxvDesde = d;
		vxvHasta = h;
		cargarVxv();
	}
	function setPeriodoVxv(id: string) {
		vxvPeriodo = id;
		const h = new Date();
		if (id === 'hoy') setRangoVxv(hoyFecha(), hoyFecha());
		if (id === 'semana') {
			const l = new Date(h);
			l.setDate(h.getDate() - h.getDay() + 1);
			setRangoVxv(fmtD(l), hoyFecha());
		}
		if (id === 'mes') {
			const l = new Date(h.getFullYear(), h.getMonth(), 1);
			setRangoVxv(fmtD(l), hoyFecha());
		}
		if (id === 'anio') setRangoVxv(h.getFullYear() + '-01-01', hoyFecha());
	}
	function onVxvFechaManual() {
		vxvPeriodo = 'custom';
		cargarVxv();
	}
	const chartVxvConfig = $derived<ChartConfiguration>({
		type: 'bar',
		data: { labels: vxvDatos.map((d) => d.nombre), datasets: [{ label: 'Total vendido', data: vxvDatos.map((d) => d.total), backgroundColor: vxvColores, borderRadius: 4 }] },
		options: {
			indexAxis: 'y',
			responsive: true,
			maintainAspectRatio: false,
			plugins: { legend: { display: false } },
			scales: { x: { ticks: { callback: (v) => '$' + Math.round(Number(v)).toLocaleString('es-AR') } } }
		}
	});
	function irAVentasVendedor(vendedorId: number) {
		sessionStorage.setItem('logos_filtro_ventas', JSON.stringify({ vendedor_id: vendedorId, fecha_desde: vxvDesde, fecha_hasta: vxvHasta }));
		location.href = '/ventas';
	}
	if (puedeVendedores) cargarVxv();

	// ══════════════════════════════════════════════════════════════
	// B9 — Desempeño individual
	// ══════════════════════════════════════════════════════════════
	function rangoDefaultDmp(gran: string) {
		const h = new Date();
		if (gran === 'diario') return { desde: hoyFecha(), hasta: hoyFecha() };
		if (gran === 'semanal') {
			const l = new Date(h);
			l.setDate(h.getDate() - h.getDay() + 1);
			return { desde: fmtD(l), hasta: hoyFecha() };
		}
		if (gran === 'mensual') return { desde: h.getFullYear() + '-' + pad(h.getMonth() + 1) + '-01', hasta: hoyFecha() };
		if (gran === 'anual') return { desde: h.getFullYear() + '-01-01', hasta: hoyFecha() };
		return { desde: hoyFecha(), hasta: hoyFecha() };
	}
	let dmpVendedores = $state<{ id: number; nombre: string }[]>([]);
	let dmpVendedorId = $state('');
	let dmpGran = $state('mensual');
	let dmpDesdeD = $state(rangoDefaultDmp('mensual').desde);
	let dmpHastaD = $state(rangoDefaultDmp('mensual').hasta);
	let dmpDatos = $state<DmpReporte | null>(null);

	async function cargarListaDmpVendedores() {
		const r = await api('/vendedores');
		if (!r.ok) return;
		dmpVendedores = await r.json();
	}
	async function cargarDmp() {
		if (!dmpVendedorId || !dmpDesdeD || !dmpHastaD) {
			dmpDatos = null;
			return;
		}
		const r = await api(`/vendedores/${dmpVendedorId}/reporte?desde=${dmpDesdeD}&hasta=${dmpHastaD}&granularidad=${dmpGran}`);
		if (!r.ok) {
			dmpDatos = null;
			return;
		}
		dmpDatos = await r.json();
	}
	function setGranDmp(g: string) {
		dmpGran = g;
		const r = rangoDefaultDmp(g);
		dmpDesdeD = r.desde;
		dmpHastaD = r.hasta;
		cargarDmp();
	}
	const dmpMetaPct = $derived(dmpDatos?.vendedor.meta_monto ? Math.min(100, Math.round((dmpDatos.kpi.total / dmpDatos.vendedor.meta_monto) * 100)) : null);
	const dmpChartConfig = $derived.by<ChartConfiguration | null>(() => {
		if (!dmpDatos) return null;
		const accentRgb = '99,125,255';
		if (dmpGran === 'semanal') {
			const dias = [...new Set(dmpDatos.series.map((s) => s.dia!))].sort();
			const manana = dias.map((dia) => dmpDatos!.series.find((x) => x.dia === dia && x.turno === 'mañana')?.total ?? 0);
			const tarde = dias.map((dia) => dmpDatos!.series.find((x) => x.dia === dia && x.turno === 'tarde')?.total ?? 0);
			return {
				type: 'bar',
				data: {
					labels: dias.map((d) => {
						const p = d.split('-');
						return p[2] + '/' + p[1];
					}),
					datasets: [
						{ label: 'Mañana (0-12h)', data: manana, backgroundColor: `rgba(${accentRgb},.7)`, borderRadius: 3 },
						{ label: 'Tarde (12-24h)', data: tarde, backgroundColor: `rgba(${accentRgb},.35)`, borderRadius: 3 }
					]
				},
				options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }, scales: { y: { ticks: { callback: (v) => '$' + Math.round(Number(v)).toLocaleString('es-AR') } } } }
			};
		}
		const labels = dmpDatos.series.map((s) => s.label || s.periodo || '');
		return {
			type: dmpGran === 'diario' ? 'bar' : 'line',
			data: {
				labels,
				datasets: [
					{
						label: 'Total vendido',
						data: dmpDatos.series.map((s) => s.total),
						backgroundColor: `rgba(${accentRgb},.25)`,
						borderColor: `rgb(${accentRgb})`,
						borderWidth: 2,
						pointRadius: 3,
						fill: dmpGran !== 'diario',
						tension: 0.3,
						borderRadius: 4
					}
				]
			},
			options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: (v) => '$' + Math.round(Number(v)).toLocaleString('es-AR') } } } }
		};
	});
	if (puedeVendedores) {
		cargarListaDmpVendedores();
	}

	// ══════════════════════════════════════════════════════════════
	// B10 — Comisiones por período
	// ══════════════════════════════════════════════════════════════
	const hoyD = new Date();
	let comPeriodo = $state(hoyD.getFullYear() + '-' + pad(hoyD.getMonth() + 1));
	let comDatos = $state<ComisionesData | null>(null);
	let comCargando = $state(false);
	let comCerrando = $state(false);

	async function cargarCom() {
		if (!comPeriodo) return;
		comCargando = true;
		try {
			const r = await api(`/vendedores/comisiones?periodo=${comPeriodo}`);
			if (!r.ok) {
				comDatos = null;
				return;
			}
			comDatos = await r.json();
		} finally {
			comCargando = false;
		}
	}
	const comTotV = $derived((comDatos?.filas ?? []).reduce((s, f) => s + f.total_ventas, 0));
	const comTotC = $derived((comDatos?.filas ?? []).reduce((s, f) => s + f.total_comision, 0));

	async function cerrarMesCom() {
		if (!comPeriodo) return;
		comCerrando = true;
		try {
			const r = await api('/vendedores/comisiones/cerrar', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ periodo: comPeriodo }) });
			if (!r.ok) throw new Error('Error al cerrar');
			await cargarCom();
		} finally {
			comCerrando = false;
		}
	}
	async function exportarCom() {
		if (!comPeriodo) return;
		const res = await api(`/vendedores/comisiones/exportar?periodo=${comPeriodo}`);
		if (!res.ok) return;
		const blob = await res.blob();
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = `comisiones_${comPeriodo}.csv`;
		a.click();
		URL.revokeObjectURL(url);
	}
	if (puedeVendedores) cargarCom();

	// ── Tour guiado — port de pos/dashboard.html (8 pasos, sin onEnter). ─
	const TOUR_STEPS: TourStep[] = [
		{
			el: null,
			title: 'Dashboard del negocio',
			body: 'Esta pantalla te muestra en tiempo real cómo está yendo el negocio: ventas, caja, productos más vendidos y actividad del equipo. Todo en un vistazo.'
		},
		{
			el: '.dash-tb',
			title: 'Período de análisis',
			body: 'Elegí <b>Hoy</b>, <b>Semana</b>, <b>Mes</b> o <b>Año</b>, o ingresá un rango de fechas a medida. El dashboard entero se actualiza con el período seleccionado.'
		},
		{
			el: '.kpis-row',
			title: 'Indicadores clave',
			body: 'Los KPIs resumen lo más importante: total vendido, cantidad de transacciones, ticket promedio, ganancia bruta, efectivo en caja, estado del turno y saldo vencido de cuenta corriente. Hacé clic en <em>Ganancia</em> o <em>CC vencida</em> para ir directo al detalle.'
		},
		{
			el: '.block-ven',
			title: 'Ventas del período',
			body: 'El gráfico de barras muestra la evolución diaria de ventas. La torta de la derecha desglosa el total por medio de pago (efectivo, tarjeta, MP, etc.).'
		},
		{
			el: '.block-prod',
			title: 'Productos',
			body: 'La pestaña <b>Top ventas</b> lista los artículos que más facturaron. Cambiá a <b>Stock crítico</b> para ver los productos que están por debajo del stock mínimo y necesitan reposición urgente.'
		},
		{
			el: '.block-caja',
			title: 'Estado de caja',
			body: 'A la izquierda el resumen del turno actual: quién lo abrió, cuándo y con cuánto efectivo. A la derecha los últimos cierres con el efectivo vendido vs. contado, para detectar diferencias rápidamente.'
		},
		{
			el: '.block-feed',
			title: 'Actividad reciente',
			body: 'Registro de las últimas operaciones: ventas, anulaciones, ajustes de stock. Te permite detectar de un vistazo si pasó algo fuera de lo normal.'
		},
		{
			el: '.block-tend',
			title: 'Tendencia de ventas',
			body: 'Compara el mes actual con los últimos 6 meses. Incluye una proyección de cierre de mes basada en el ritmo actual de ventas.'
		}
	];

	onMount(() => {
		setTourSteps('dashboard', TOUR_STEPS);
	});
</script>

<svelte:head>
	<title>Logos — Dashboard</title>
</svelte:head>

<div class="dash-tb">
	<span class="tb-lbl">Período</span>
	<button class="tb-btn" class:activo={periodoTipo === 'hoy'} onclick={() => setPeriodo('hoy')}>Hoy</button>
	<button class="tb-btn" class:activo={periodoTipo === 'semana'} onclick={() => setPeriodo('semana')}>Semana</button>
	<button class="tb-btn" class:activo={periodoTipo === 'mes'} onclick={() => setPeriodo('mes')}>Mes</button>
	<button class="tb-btn" class:activo={periodoTipo === 'anio'} onclick={() => setPeriodo('anio')}>Año</button>
	<div class="tb-sep"></div>
	<input type="date" class="tb-date" bind:value={desde} onchange={onFechaManual} />
	<span style="color:var(--gris3);font-size:12px;flex-shrink:0">→</span>
	<input type="date" class="tb-date" bind:value={hasta} onchange={onFechaManual} />
	<button class="tb-refresh" disabled={refrescando} onclick={cargar}>
		<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10" /><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" /></svg>
		Actualizar
	</button>
</div>

<div class="dash-scroll">
	<div class="dash-main">
		<!-- B1: KPIs -->
		<section class="block-kpis">
			<div class="kpis-row">
				<div class="kcard">
					<span class="klbl">Ventas del período</span>
					<span class="kval g">{tv > 0 ? moneyFmt(tv) : '$0'}</span>
					<span class="kdtl">{cv} transacción{cv !== 1 ? 'es' : ''}{#if deltaVentas} <span class={deltaVentas.cls}>{deltaVentas.texto}</span>{/if}</span>
				</div>
				<div class="kcard">
					<span class="klbl">Transacciones</span>
					<span class="kval">{cv}</span>
					<span class="kdtl">{dash ? 'del período' : '—'}{#if deltaTxn} <span class={deltaTxn.cls}>{deltaTxn.texto}</span>{/if}</span>
				</div>
				<div class="kcard">
					<span class="klbl">Ticket promedio</span>
					<span class="kval">{cv > 0 ? moneyFmt(tv / cv) : '—'}</span>
					<span class="kdtl">{cv > 0 ? 'por transacción' : 'Sin ventas'}{#if deltaTicket} <span class={deltaTicket.cls}>{deltaTicket.texto}</span>{/if}</span>
				</div>
				{#if veCosto}
					<div class="kcard" style="cursor:pointer" title="Ver análisis de rentabilidad" onclick={irRentabilidad}>
						<span class="klbl">Ganancia bruta</span>
						<span class="kval {gb >= 0 ? 'g' : 'r'}">{moneyFmt(gb)}</span>
						<span class="kdtl">Margen {mp.toFixed(1)}%</span>
					</div>
				{/if}
				<div class="kcard">
					<span class="klbl">Efectivo en caja</span>
					{#if turnoActual}
						<span class="kval">{moneyFmt(efEsperado)}</span>
						<span class="kdtl">Fondo: {moneyFmt(turnoActual.fondo_inicial)}</span>
					{:else if cajaData && cajaData.turno === null}
						<span class="kval">—</span>
						<span class="kdtl">Sin turno activo</span>
					{:else}
						<span class="kval">—</span>
						<span class="kdtl">{cajaId ? 'Sin datos' : 'Sin caja asignada'}</span>
					{/if}
				</div>
				<div class="kcard">
					<span class="klbl">Estado de caja</span>
					<span class="kval" style="font-size:13px;margin-top:3px">{turnoActual ? turnoActual.usuario_nombre ?? '—' : '—'}</span>
					<span class="kbadge {turnoActual ? 'badge-open' : 'badge-closed'}">
						{turnoActual ? '● Abierta' : cajaData && cajaData.turno === null ? 'Cerrada' : '—'}
					</span>
					<span class="kdtl" style="margin-top:8px">
						{#if turnoActual}
							{@const dt = new Date((turnoActual.abierto_en || '').replace(' ', 'T'))}
							{isNaN(dt.getTime()) ? '—' : `Desde ${pad(dt.getHours())}:${pad(dt.getMinutes())} hs`}
						{:else}
							—
						{/if}
					</span>
				</div>
				{#if veCc}
					<div class="kcard" style="cursor:pointer" title="Ver vencimientos de cuenta corriente" onclick={irCuentaCorriente}>
						<span class="klbl">CC vencida</span>
						{#if !ccAgingData?.totales}
							<span class="kval">$0</span>
							<span class="kdtl">Sin deudores en CC</span>
						{:else}
							<span class="kval {ccAgingData.totales.vencido > 0.001 ? 'r' : ''}">{moneyFmt(ccAgingData.totales.vencido)}</span>
							<span class="kdtl">
								{#if ccAgingData.totales.vencido > 0.001}
									{deudoresVencidos.length} deudor{deudoresVencidos.length !== 1 ? 'es' : ''} vencido{deudoresVencidos.length !== 1 ? 's' : ''} · saldo total {moneyFmt(ccAgingData.totales.saldo)}
								{:else}
									Al día · saldo total {moneyFmt(ccAgingData.totales.saldo)}
								{/if}
							</span>
						{/if}
					</div>
				{/if}
			</div>
		</section>

		<!-- B2: Ventas -->
		<section class="block block-ven">
			<div class="bh">
				<span class="bt">Ventas del período</span>
				<span class="bs">{tv > 0 ? moneyFmt(tv) : 'Sin ventas'}</span>
			</div>
			<div class="ven-inner">
				<div class="chart-pad">
					{#if !(dash?.por_dia?.length)}
						<div class="empty-state">Sin ventas en el período</div>
					{:else}
						<canvas use:chartjs={chartVenConfig}></canvas>
					{/if}
				</div>
				<div class="torta-col">
					{#if medios.length}
						<canvas class="torta-canvas" use:chartjs={chartTortaConfig}></canvas>
						<div class="tleg">
							{#each medios as m, i (i)}
								{@const pct = totalMedios > 0 ? Math.round((m.total / totalMedios) * 100) : 0}
								<div class="tl-row">
									<span class="tl-dot" style="background:{TIPO_COLORS[m.tipo] ?? C.gris}"></span>
									<span class="tl-lbl">{TIPO_LABELS[m.tipo] ?? m.tipo}</span>
									<span class="tl-pct">{pct}%</span>
								</div>
							{/each}
						</div>
					{:else}
						<div style="color:var(--gris3);font-size:10px;text-align:center">Sin datos</div>
					{/if}
				</div>
			</div>
		</section>

		<!-- B3: Productos -->
		<section class="block block-prod">
			<div class="bh"><span class="bt">Productos</span></div>
			<div class="ptabs">
				<button class="ptab" class:activo={productosTab === 'top'} onclick={() => (productosTab = 'top')}>Top ventas</button>
				<button class="ptab" class:activo={productosTab === 'crit'} onclick={() => (productosTab = 'crit')}>Stock crítico</button>
			</div>
			{#if productosTab === 'top'}
				<div class="ppanel activo">
					{#if dash?.top_productos?.length}
						{#each dash.top_productos as p, i (i)}
							<div class="prow">
								<span class="prank">{i + 1}</span>
								<span class="pname">{p.nombre}</span>
								<span class="pqty">{Math.round(p.cant)} u</span>
								<span class="pmonto">{moneyFmt(p.ventas)}</span>
							</div>
						{/each}
					{:else}
						<div class="empty-state">Sin ventas en el período</div>
					{/if}
				</div>
			{:else}
				<div class="ppanel activo">
					{#if stockBajo.length}
						{#each stockBajo as p, i (i)}
							<div class="arow">
								<span class="arow-n">{p.nombre}</span>
								<span class="arow-s">{p.stock_actual} / mín {p.stock_minimo}</span>
							</div>
						{/each}
					{:else}
						<div class="empty-state" style="color:var(--verde)">Sin alertas de stock</div>
					{/if}
				</div>
			{/if}
		</section>

		<!-- B4: Caja -->
		<section class="block block-caja">
			<div class="bh">
				<span class="bt">Caja</span>
				<span class="bs">Estado actual · últimos cierres</span>
			</div>
			<div class="caja-inner">
				<div class="caja-izq">
					{#if turnoActual}
						<div class="caja-est-grid">
							<div class="ced"><div class="ced-lbl">Estado</div><div class="ced-val" style="color:var(--verde);font-weight:700">● Abierta</div></div>
							<div class="ced"><div class="ced-lbl">Caja</div><div class="ced-val">{turnoActual.caja_nombre ?? '—'}</div></div>
							<div class="ced"><div class="ced-lbl">Operador</div><div class="ced-val">{turnoActual.usuario_nombre ?? '—'}</div></div>
							<div class="ced"><div class="ced-lbl">Ventas en turno</div><div class="ced-val g">{cajaData?.count_total ?? 0}</div></div>
							<div class="ced"><div class="ced-lbl">Efectivo esperado</div><div class="ced-val" style="font-weight:700">{moneyFmt(efEsperado)}</div></div>
							<div class="ced"><div class="ced-lbl">Efectivo ventas</div><div class="ced-val">{moneyFmt(cajaData?.ventas_efectivo ?? 0)}</div></div>
							<div class="ced"><div class="ced-lbl">Tarjeta</div><div class="ced-val">{moneyFmt(cajaData?.ventas_tarjeta ?? 0)}</div></div>
							<div class="ced"><div class="ced-lbl">Transferencia</div><div class="ced-val">{moneyFmt(cajaData?.ventas_transferencia ?? 0)}</div></div>
							{#if (cajaData?.ventas_cc ?? 0) > 0}
								<div class="ced"><div class="ced-lbl">Cta. Cte.</div><div class="ced-val">{moneyFmt(cajaData?.ventas_cc)}</div></div>
							{/if}
						</div>
					{:else if cajaData && cajaData.turno === null}
						<div class="empty-state" style="margin-top:4px">● Sin turno activo</div>
					{:else}
						<div class="empty-state">Sin datos de caja</div>
					{/if}
				</div>
				<div class="caja-der">
					<table class="ct">
						<thead><tr><th>Fecha</th><th>Ef. ventas</th><th>Ef. esperado</th><th>Ef. contado</th><th>Diferencia</th><th>Cerró</th></tr></thead>
						<tbody>
							{#if !cerrados.length}
								<tr><td colspan="6"><div class="empty-state">Sin cierres registrados</div></td></tr>
							{:else}
								{#each cerrados as t, i (i)}
									{@const dif = parseFloat(String(t.diferencia ?? 0))}
									<tr>
										<td>{diaCorto(t.cerrado_en || t.abierto_en)}</td>
										<td>{moneyFmt(parseFloat(String(t.total_efectivo ?? 0)))}</td>
										<td>{moneyFmt(parseFloat(String(t.efectivo_esperado ?? 0)))}</td>
										<td>{moneyFmt(parseFloat(String(t.efectivo_contado ?? 0)))}</td>
										<td class={difClase(dif)}>{difStr(dif)}</td>
										<td>{t.usuario_nombre ?? '—'}</td>
									</tr>
								{/each}
							{/if}
						</tbody>
					</table>
				</div>
			</div>
		</section>

		<!-- B5: Feed -->
		<section class="block block-feed">
			<div class="bh"><span class="bt">Actividad reciente</span></div>
			<div class="feed-list">
				{#if veLog}
					{#if !feedLog.length}
						<div class="empty-state">Sin actividad registrada</div>
					{:else}
						{#each feedLog as item, i (i)}
							<div class="fi">
								<span class="fdot {ADOT[item.accion] ?? 'v'}"></span>
								<div class="fbody">
									<div class="fm">{ALABELS[item.accion] ?? item.accion}{#if feedLogDetalle(item)} · {feedLogDetalle(item)}{/if}</div>
									<div class="fmeta">{feedLogMeta(item)}</div>
								</div>
							</div>
						{/each}
					{/if}
				{:else if !feedVentas.length}
					<div class="empty-state">Sin actividad registrada</div>
				{:else}
					{#each feedVentas as v, i (i)}
						{@const anulada = v.estado === 'anulado'}
						<div class="fi" style={anulada ? 'opacity:.6' : ''}>
							<span class="fdot {anulada ? 'a' : 'v'}"></span>
							<div class="fbody">
								<div class="fm">Venta #{v.numero}{anulada ? ' · ANULADA' : ''} · {TIPO_LABELS[v.tipo_pago] ?? v.tipo_pago}</div>
								<div class="fmeta">{[feedVentaHora(v), v.cliente_nombre ?? 'Consumidor final'].filter(Boolean).join(' · ')}</div>
							</div>
							<span class="fval {anulada ? 'r' : 'g'}">{moneyFmt(v.total)}</span>
						</div>
					{/each}
				{/if}
			</div>
		</section>

		<!-- B6: Tendencia -->
		<section class="block block-tend">
			<div class="bh">
				<span class="bt">Tendencia</span>
				<span class="bs">Últimos 6 meses</span>
			</div>
			<div class="tend-stats">
				<div class="ts">
					<div class="ts-lbl">Mes actual</div>
					<div class="ts-val">{tendProyV > 0 ? moneyFmt(tendProyV) : 'Sin datos'}</div>
					<div class="ts-sub">{tendVs}</div>
				</div>
				<div class="ts">
					<div class="ts-lbl">Promedio mensual</div>
					<div class="ts-val">{tendProm > 0 ? moneyFmt(tendProm) : '—'}</div>
					<div class="ts-sub">Últimos 6 meses</div>
				</div>
			</div>
			<div class="tend-chart">
				{#if !tendTieneData}
					<div class="empty-state">Sin ventas registradas</div>
				{:else}
					<canvas use:chartjs={chartTendConfig}></canvas>
				{/if}
			</div>
		</section>

		<!-- B7: Usuarios -->
		<section class="block block-usrs">
			<div class="bh"><span class="bt">Usuarios</span></div>
			<div style="overflow-x:auto">
				<table class="ut">
					<thead><tr><th>Nombre</th><th>Rol</th><th>Caja asignada</th><th>Estado</th></tr></thead>
					<tbody>
						{#if !esAdmin}
							<tr><td colspan="4"><div class="empty-state">Solo visible para administradores</div></td></tr>
						{:else if !usuariosListo}
							<tr><td colspan="4"><div class="empty-state">Cargando…</div></td></tr>
						{:else if !usuarios.length}
							<tr><td colspan="4"><div class="empty-state">Sin usuarios</div></td></tr>
						{:else}
							{#each usuarios as u, i (i)}
								<tr>
									<td><span class="udot {usuarioEnTurno(u) ? 'on' : 'off'}"></span>{u.nombre}</td>
									<td>{u.rol ?? '—'}</td>
									<td>{u.caja_nombre ?? '—'}</td>
									<td>{#if usuarioEnTurno(u)}<span class="chip chip-g">En turno</span>{:else}<span class="chip" style="background:var(--gris2);color:var(--gris3)">Inactivo</span>{/if}</td>
								</tr>
							{/each}
						{/if}
					</tbody>
				</table>
			</div>
		</section>

		<!-- B8: Ventas por vendedor -->
		{#if puedeVendedores}
			<section class="block block-vxv">
				<div class="bh"><span class="bt">Ventas por vendedor</span></div>
				<div class="vxv-tb">
					<span class="tb-lbl">Período</span>
					<button class="tb-btn" class:activo={vxvPeriodo === 'hoy'} onclick={() => setPeriodoVxv('hoy')}>Hoy</button>
					<button class="tb-btn" class:activo={vxvPeriodo === 'semana'} onclick={() => setPeriodoVxv('semana')}>Semana</button>
					<button class="tb-btn" class:activo={vxvPeriodo === 'mes'} onclick={() => setPeriodoVxv('mes')}>Mes</button>
					<button class="tb-btn" class:activo={vxvPeriodo === 'anio'} onclick={() => setPeriodoVxv('anio')}>Año</button>
					<span class="tb-sep"></span>
					<input class="tb-date" type="date" bind:value={vxvDesde} onchange={onVxvFechaManual} />
					<span style="font-size:11px;color:var(--neo-text-3)">—</span>
					<input class="tb-date" type="date" bind:value={vxvHasta} onchange={onVxvFechaManual} />
				</div>
				<div class="vxv-body">
					<div class="vxv-chart-wrap">
						{#if vxvDatos.length}
							<canvas use:chartjs={chartVxvConfig}></canvas>
						{/if}
					</div>
					<div class="vxv-table-wrap">
						<table class="vxv-tbl">
							<thead><tr><th>Vendedor</th><th>Total</th><th>Comisionado</th><th>Tickets</th><th>Ticket prom.</th><th>Meta</th><th></th></tr></thead>
							<tbody>
								{#if !vxvDatos.length}
									<tr><td colspan="7" style="text-align:center;color:var(--neo-text-3);padding:20px">Sin datos en el período</td></tr>
								{:else}
									{#each vxvDatos as v, i (i)}
										{@const pct = v.meta_monto ? Math.min(100, Math.round((v.total / v.meta_monto) * 100)) : null}
										<tr>
											<td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{vxvColores[i]};margin-right:5px;vertical-align:middle"></span>{v.nombre}</td>
											<td><strong>{moneyFmt(v.total)}</strong></td>
											<td>{v.monto_comisionado > 0 ? moneyFmt(v.monto_comisionado) : '—'}</td>
											<td>{v.cant_ventas}</td>
											<td>{moneyFmt(v.ticket_promedio)}</td>
											<td style="min-width:100px">
												{#if pct !== null}
													<div class="meta-bar"><div class="meta-fill" style="width:{pct}%;background:{vxvColores[i]}"></div></div>
													<span style="font-size:10px;color:var(--neo-text-3)">{pct}%</span>
												{:else}
													—
												{/if}
											</td>
											<td><button class="com-ver-ventas" onclick={() => irAVentasVendedor(v.id)}>Ver</button></td>
										</tr>
									{/each}
								{/if}
							</tbody>
						</table>
					</div>
				</div>
			</section>

			<!-- B9: Desempeño individual -->
			<section class="block block-dmp">
				<div class="bh"><span class="bt">Desempeño individual</span></div>
				<div class="dmp-tb">
					<select bind:value={dmpVendedorId} onchange={cargarDmp}>
						<option value="">— Vendedor —</option>
						{#each dmpVendedores as v (v.id)}
							<option value={v.id}>{v.nombre}</option>
						{/each}
					</select>
					<span class="tb-sep"></span>
					<span class="tb-lbl">Vista</span>
					<button class="tb-btn" class:activo={dmpGran === 'diario'} onclick={() => setGranDmp('diario')}>Diario</button>
					<button class="tb-btn" class:activo={dmpGran === 'semanal'} onclick={() => setGranDmp('semanal')}>Semanal</button>
					<button class="tb-btn" class:activo={dmpGran === 'mensual'} onclick={() => setGranDmp('mensual')}>Mensual</button>
					<button class="tb-btn" class:activo={dmpGran === 'anual'} onclick={() => setGranDmp('anual')}>Anual</button>
					<span class="tb-sep"></span>
					<input class="tb-date" type="date" bind:value={dmpDesdeD} onchange={cargarDmp} />
					<span style="font-size:11px;color:var(--neo-text-3)">—</span>
					<input class="tb-date" type="date" bind:value={dmpHastaD} onchange={cargarDmp} />
				</div>
				<div class="dmp-body">
					<div class="dmp-kpis" style="grid-template-columns:repeat(4,1fr)">
						<div class="dmp-kpi"><div class="dmp-kpi-val">{dmpDatos ? moneyFmt(dmpDatos.kpi.total) : '—'}</div><div class="dmp-kpi-lbl">Total vendido</div></div>
						<div class="dmp-kpi"><div class="dmp-kpi-val">{dmpDatos && dmpDatos.kpi.comision > 0 ? moneyFmt(dmpDatos.kpi.comision) : '—'}</div><div class="dmp-kpi-lbl">Comisión</div></div>
						<div class="dmp-kpi"><div class="dmp-kpi-val">{dmpDatos ? dmpDatos.kpi.cant : '—'}</div><div class="dmp-kpi-lbl">Tickets</div></div>
						<div class="dmp-kpi"><div class="dmp-kpi-val">{dmpDatos ? moneyFmt(dmpDatos.kpi.ticket_promedio) : '—'}</div><div class="dmp-kpi-lbl">Ticket prom.</div></div>
					</div>
					{#if dmpDatos?.vendedor.meta_monto}
						<div class="dmp-meta">
							<span>Meta: {moneyFmt(dmpDatos.vendedor.meta_monto)}</span>
							<div class="dmp-meta-bar"><div class="dmp-meta-fill" style="width:{dmpMetaPct}%"></div></div>
							<span>{dmpMetaPct}%</span>
						</div>
					{/if}
					<div class="dmp-chart-wrap">
						{#if dmpChartConfig}
							<canvas use:chartjs={dmpChartConfig}></canvas>
						{/if}
					</div>
				</div>
			</section>

			<!-- B10: Comisiones -->
			<section class="block block-com">
				<div class="bh">
					<span class="bt">Comisiones por período</span>
					<span class="bs">
						{#if comDatos}
							<span class="com-badge {comDatos.cerrado ? 'com-badge-cerrado' : 'com-badge-abierto'}">
								{comDatos.cerrado ? `Cerrado ${comDatos.cerrado_en ? comDatos.cerrado_en.slice(0, 10) : ''}` : 'Abierto'}
							</span>
						{/if}
					</span>
				</div>
				<div class="com-tb">
					<span class="tb-lbl">Período</span>
					<input type="month" class="tb-date" bind:value={comPeriodo} onchange={cargarCom} />
					<span class="tb-sep"></span>
					<button class="com-btn-cerrar" disabled={comCerrando} onclick={cerrarMesCom}>{comCerrando ? 'Guardando…' : comDatos?.cerrado ? 'Reabrir y actualizar' : 'Cerrar mes'}</button>
					<button class="com-btn-exp" onclick={exportarCom}>Exportar CSV</button>
				</div>
				<div class="com-body">
					<table class="com-tbl">
						<thead><tr><th>Vendedor</th><th>Total vendido</th><th>Comisionado</th><th>Ventas</th></tr></thead>
						<tbody>
							{#if comCargando}
								<tr><td colspan="4" style="text-align:center;color:var(--neo-text-3);padding:14px">Cargando…</td></tr>
							{:else if !comDatos?.filas.length}
								<tr><td colspan="4" style="text-align:center;color:var(--neo-text-3);padding:14px">Sin datos en el período</td></tr>
							{:else}
								{#each comDatos.filas as f, i (i)}
									<tr>
										<td>{f.vendedor_nombre}</td>
										<td><strong>{moneyFmt(f.total_ventas)}</strong></td>
										<td>{f.total_comision > 0 ? moneyFmt(f.total_comision) : '—'}</td>
										<td>{f.cant_ventas}</td>
									</tr>
								{/each}
								<tr style="font-weight:700;border-top:2px solid var(--borde-fuerte)">
									<td>Total</td><td>{moneyFmt(comTotV)}</td><td>{comTotC > 0 ? moneyFmt(comTotC) : '—'}</td><td></td>
								</tr>
							{/if}
						</tbody>
					</table>
				</div>
			</section>
		{/if}
	</div>
</div>

<style>
	.dash-tb {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd), 0 -1px 4px var(--neo-sl);
		padding: 9px 20px;
		display: flex;
		align-items: center;
		gap: 8px;
		flex-shrink: 0;
		flex-wrap: wrap;
		position: relative;
		z-index: 10;
	}
	.tb-lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.tb-btn {
		padding: 5px 12px;
		border-radius: var(--neo-r-xs);
		border: none;
		background: var(--neo-bg);
		color: var(--neo-text-2);
		font-size: 11px;
		font-weight: 700;
		font-family: inherit;
		cursor: pointer;
		letter-spacing: 0.2px;
		box-shadow: var(--neo-e1);
	}
	.tb-btn:hover {
		box-shadow: var(--neo-e2);
		color: var(--neo-text);
	}
	.tb-btn.activo {
		box-shadow: var(--neo-i1);
		color: var(--neo-accent);
	}
	.tb-btn:disabled {
		opacity: 0.45;
		cursor: not-allowed;
	}
	.tb-date {
		padding: 5px 10px;
		border-radius: var(--neo-r-xs);
		border: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		font-size: 11px;
		font-family: inherit;
		outline: none;
		box-shadow: var(--neo-i1);
	}
	.tb-sep {
		width: 1px;
		height: 16px;
		background: var(--neo-sd);
		opacity: 0.35;
		margin: 0 4px;
		flex-shrink: 0;
	}
	.tb-refresh {
		margin-left: auto;
		padding: 5px 12px;
		border-radius: var(--neo-r-xs);
		border: none;
		background: var(--neo-bg);
		color: var(--neo-text-2);
		font-size: 11px;
		font-family: inherit;
		cursor: pointer;
		display: flex;
		align-items: center;
		gap: 5px;
		box-shadow: var(--neo-e1);
	}
	.tb-refresh:hover {
		box-shadow: var(--neo-e2);
		color: var(--neo-text);
	}
	.tb-refresh:disabled {
		opacity: 0.45;
		cursor: not-allowed;
	}
	.dash-scroll {
		flex: 1;
		overflow-y: auto;
		background: var(--neo-bg);
	}
	.dash-main {
		padding: 16px 20px;
		display: grid;
		gap: 14px;
		grid-template-columns: 1fr 1fr 1fr;
		grid-template-areas:
			'kpis kpis kpis'
			'ven ven prod'
			'caja caja caja'
			'feed tend tend'
			'feed usrs usrs'
			'vxv vxv dmp'
			'com com com';
		max-width: 1700px;
		width: 100%;
		margin: 0 auto;
	}
	.block {
		background: var(--neo-bg);
		box-shadow: var(--neo-e2);
		border-radius: var(--neo-r-lg);
		overflow: hidden;
	}
	.bh {
		padding: 12px 16px 11px;
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
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
	.empty-state {
		padding: 24px 14px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 12px;
		font-style: italic;
	}
	.block-kpis {
		grid-area: kpis;
		background: transparent;
		box-shadow: none;
		border-radius: 0;
		overflow: visible;
	}
	.kpis-row {
		display: grid;
		grid-template-columns: repeat(7, 1fr);
		gap: 12px;
	}
	.kdtl :global(.delta-up) {
		color: var(--neo-success);
		font-weight: 700;
	}
	.kdtl :global(.delta-down) {
		color: var(--neo-danger);
		font-weight: 700;
	}
	.kcard {
		background: var(--neo-bg);
		box-shadow: var(--neo-e2);
		border-radius: var(--neo-r-lg);
		padding: 14px 15px;
		display: flex;
		flex-direction: column;
		gap: 5px;
		position: relative;
		cursor: default;
	}
	.kcard:hover {
		box-shadow: var(--neo-e3);
	}
	.klbl {
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.7px;
		color: var(--neo-text-3);
	}
	.kval {
		font-size: 20px;
		font-weight: 800;
		color: var(--color-primary);
		letter-spacing: -0.5px;
		line-height: 1.1;
		font-variant-numeric: tabular-nums;
	}
	.kval.g {
		color: var(--neo-success);
	}
	.kval.r {
		color: var(--neo-danger);
	}
	.kdtl {
		font-size: 10px;
		color: var(--neo-text-3);
		margin-top: 1px;
	}
	.kbadge {
		position: absolute;
		top: 10px;
		right: 10px;
		padding: 2px 8px;
		border-radius: var(--neo-r-pill);
		font-size: 9px;
		font-weight: 700;
		letter-spacing: 0.3px;
		box-shadow: var(--neo-e1);
	}
	.badge-open {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success);
	}
	.badge-closed {
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.block-ven {
		grid-area: ven;
	}
	.ven-inner {
		display: grid;
		grid-template-columns: 1fr 185px;
		height: 250px;
	}
	.chart-pad {
		padding: 10px 10px 10px 14px;
		position: relative;
		overflow: hidden;
	}
	.torta-col {
		padding: 12px 14px 12px 4px;
		display: flex;
		flex-direction: column;
		gap: 8px;
		border-left: 1px solid var(--borde-fuerte);
	}
	.torta-canvas {
		flex: 1;
		max-height: 120px;
	}
	.tleg {
		display: flex;
		flex-direction: column;
		gap: 5px;
	}
	.tl-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 6px;
	}
	.tl-dot {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		flex-shrink: 0;
	}
	.tl-lbl {
		color: var(--neo-text-3);
		flex: 1;
		font-size: 10px;
	}
	.tl-pct {
		color: var(--neo-text);
		font-weight: 700;
		font-size: 11px;
	}
	.block-prod {
		grid-area: prod;
		display: flex;
		flex-direction: column;
	}
	.ptabs {
		display: flex;
		border-bottom: 1px solid var(--borde-fuerte);
	}
	.ptab {
		flex: 1;
		padding: 9px 4px;
		font-size: 10px;
		font-weight: 700;
		letter-spacing: 0.3px;
		text-align: center;
		cursor: pointer;
		color: var(--neo-text-3);
		border: none;
		border-bottom: 2px solid transparent;
		background: none;
		text-transform: uppercase;
	}
	.ptab.activo {
		color: var(--neo-accent);
		border-bottom-color: var(--neo-accent);
	}
	.ppanel {
		padding: 8px 12px;
		flex: 1;
		overflow-y: auto;
		max-height: 280px;
	}
	.prow {
		display: flex;
		align-items: center;
		gap: 7px;
		padding: 7px 0;
		border-bottom: 1px solid var(--borde);
	}
	.prow:last-child {
		border-bottom: none;
	}
	.prank {
		width: 16px;
		font-size: 10px;
		font-weight: 700;
		color: var(--neo-text-3);
		text-align: right;
		flex-shrink: 0;
	}
	.pname {
		flex: 1;
		color: var(--neo-text);
		font-weight: 500;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
		font-size: 11px;
	}
	.pqty {
		color: var(--neo-text-3);
		font-size: 10px;
		flex-shrink: 0;
	}
	.pmonto {
		color: var(--neo-success);
		font-weight: 700;
		font-size: 11px;
		flex-shrink: 0;
	}
	.arow {
		padding: 7px 10px;
		border-radius: var(--neo-r-xs);
		background: rgba(231, 76, 60, 0.08);
		box-shadow: var(--neo-e1);
		margin-bottom: 6px;
		font-size: 11px;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
	}
	.arow-n {
		color: var(--neo-text);
		font-weight: 500;
		flex: 1;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.arow-s {
		color: var(--neo-danger);
		font-weight: 700;
		flex-shrink: 0;
		font-size: 10px;
	}
	.block-caja {
		grid-area: caja;
	}
	.caja-inner {
		display: grid;
		grid-template-columns: 300px 1fr;
	}
	.caja-izq {
		padding: 14px;
		border-right: 1px solid var(--borde-fuerte);
	}
	.caja-der {
		overflow-x: auto;
		background: var(--neo-bg-deep);
		border-radius: 0 0 var(--neo-r-lg) 0;
	}
	.caja-est-grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 10px;
	}
	.ced {
		display: flex;
		flex-direction: column;
		gap: 3px;
	}
	.ced-lbl {
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.ced-val {
		font-size: 13px;
		font-weight: 500;
		color: var(--neo-text);
	}
	.ced-val.g {
		color: var(--neo-success);
		font-weight: 700;
	}
	.ct {
		width: 100%;
		border-collapse: collapse;
		font-size: 11px;
	}
	.ct th {
		padding: 8px 12px;
		text-align: left;
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--neo-text-3);
		border-bottom: 1px solid var(--borde-fuerte);
		background: var(--neo-bg-deep);
		white-space: nowrap;
	}
	.ct td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--borde);
		color: var(--neo-text-2);
		font-variant-numeric: tabular-nums;
	}
	.ct tr:last-child td {
		border-bottom: none;
	}
	.dp {
		color: var(--neo-success);
		font-weight: 700;
	}
	.dn {
		color: var(--neo-danger);
		font-weight: 700;
	}
	.d0 {
		color: var(--neo-text-3);
		font-weight: 700;
	}
	.block-feed {
		grid-area: feed;
		display: flex;
		flex-direction: column;
	}
	.feed-list {
		padding: 2px 14px;
		flex: 1;
		overflow-y: auto;
		max-height: 440px;
	}
	.fi {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 9px 0;
		border-bottom: 1px solid var(--borde);
	}
	.fi:last-child {
		border-bottom: none;
	}
	.fdot {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		flex-shrink: 0;
		margin-top: 3px;
		box-shadow: var(--neo-e1);
	}
	.fdot.v {
		background: var(--neo-success);
	}
	.fdot.s {
		background: var(--neo-warning);
	}
	.fdot.c {
		background: var(--neo-accent);
	}
	.fdot.a {
		background: var(--neo-danger);
	}
	.fbody {
		flex: 1;
		min-width: 0;
	}
	.fm {
		font-size: 12px;
		color: var(--neo-text);
		font-weight: 500;
	}
	.fmeta {
		font-size: 10px;
		color: var(--neo-text-3);
		margin-top: 2px;
	}
	.fval {
		font-size: 12px;
		font-weight: 700;
		flex-shrink: 0;
		font-variant-numeric: tabular-nums;
	}
	.fval.g {
		color: var(--neo-success);
	}
	.fval.r {
		color: var(--neo-danger);
	}
	.block-tend {
		grid-area: tend;
		display: flex;
		flex-direction: column;
	}
	.tend-stats {
		display: grid;
		grid-template-columns: 1fr 1fr;
		border-bottom: 1px solid var(--borde-fuerte);
	}
	.ts {
		padding: 13px 15px;
	}
	.ts:not(:last-child) {
		border-right: 1px solid var(--borde-fuerte);
	}
	.ts-lbl {
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		margin-bottom: 5px;
	}
	.ts-val {
		font-size: 18px;
		font-weight: 800;
		color: var(--neo-text);
		font-variant-numeric: tabular-nums;
	}
	.ts-sub {
		font-size: 10px;
		color: var(--neo-text-3);
		margin-top: 3px;
	}
	.tend-chart {
		padding: 14px;
		flex: 1;
		min-height: 190px;
		position: relative;
	}
	.block-usrs {
		grid-area: usrs;
	}
	.ut {
		width: 100%;
		border-collapse: collapse;
		font-size: 11px;
	}
	.ut th {
		padding: 8px 12px;
		text-align: left;
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		border-bottom: 1px solid var(--borde-fuerte);
		background: var(--neo-bg-deep);
	}
	.ut td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--borde);
		color: var(--neo-text-2);
	}
	.ut tr:last-child td {
		border-bottom: none;
	}
	.udot {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		display: inline-block;
		margin-right: 5px;
		vertical-align: middle;
		box-shadow: var(--neo-e1);
	}
	.udot.on {
		background: var(--neo-success);
	}
	.udot.off {
		background: var(--neo-text-3);
	}
	.chip {
		padding: 2px 8px;
		border-radius: var(--neo-r-pill);
		font-size: 10px;
		font-weight: 700;
		box-shadow: var(--neo-e1);
	}
	.chip-g {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success);
	}
	.block-vxv {
		grid-area: vxv;
		display: flex;
		flex-direction: column;
	}
	.block-dmp {
		grid-area: dmp;
		display: flex;
		flex-direction: column;
	}
	.vxv-tb,
	.dmp-tb {
		display: flex;
		align-items: center;
		gap: 6px;
		flex-wrap: wrap;
		padding: 10px 14px;
		border-bottom: 1px solid var(--borde);
	}
	.vxv-tb .tb-btn,
	.dmp-tb .tb-btn {
		font-size: 10px;
		padding: 4px 10px;
	}
	.vxv-tb .tb-date,
	.dmp-tb .tb-date {
		font-size: 11px;
	}
	.dmp-tb select {
		padding: 4px 8px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 11px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-i1);
		cursor: pointer;
	}
	.vxv-body {
		flex: 1;
		display: flex;
		flex-direction: column;
		padding: 12px 14px;
		gap: 10px;
	}
	.dmp-body {
		flex: 1;
		display: flex;
		flex-direction: column;
		padding: 12px 14px;
		gap: 10px;
	}
	.vxv-chart-wrap {
		position: relative;
		height: 200px;
	}
	.dmp-chart-wrap {
		position: relative;
		flex: 1;
		min-height: 160px;
	}
	.vxv-table-wrap {
		overflow-x: auto;
	}
	table.vxv-tbl {
		width: 100%;
		border-collapse: collapse;
		font-size: 12px;
	}
	table.vxv-tbl th {
		padding: 6px 8px;
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	table.vxv-tbl td {
		padding: 7px 8px;
		border-bottom: 1px solid var(--borde-fuerte);
		color: var(--neo-text);
		vertical-align: middle;
	}
	table.vxv-tbl tr:last-child td {
		border-bottom: none;
	}
	table.vxv-tbl :global(.meta-bar) {
		height: 4px;
		border-radius: 2px;
		background: var(--color-bg-alt);
		position: relative;
		margin-top: 3px;
	}
	table.vxv-tbl :global(.meta-fill) {
		height: 100%;
		border-radius: 2px;
		background: var(--neo-accent);
	}
	.dmp-kpis {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 8px;
	}
	.dmp-kpi {
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-sm);
		padding: 8px 10px;
		box-shadow: var(--neo-i1);
	}
	.dmp-kpi-val {
		font-size: 16px;
		font-weight: 700;
		color: var(--color-primary);
		line-height: 1;
	}
	.dmp-kpi-lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--neo-text-3);
		margin-top: 3px;
	}
	.dmp-meta {
		font-size: 11px;
		color: var(--neo-text-2);
		display: flex;
		align-items: center;
		gap: 6px;
	}
	.dmp-meta-bar {
		flex: 1;
		height: 6px;
		border-radius: 3px;
		background: var(--color-bg-alt);
	}
	.dmp-meta-fill {
		height: 100%;
		border-radius: 3px;
		background: var(--neo-accent);
	}
	.block-com {
		grid-area: com;
		display: flex;
		flex-direction: column;
	}
	.com-tb {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		padding: 9px 14px;
		border-bottom: 1px solid var(--borde);
	}
	.com-body {
		padding: 12px 14px;
		overflow-x: auto;
	}
	table.com-tbl {
		width: 100%;
		border-collapse: collapse;
		font-size: 12px;
	}
	table.com-tbl th {
		padding: 6px 8px;
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	table.com-tbl td {
		padding: 7px 8px;
		border-bottom: 1px solid var(--borde-fuerte);
		color: var(--neo-text);
	}
	table.com-tbl tr:last-child td {
		border-bottom: none;
	}
	.com-badge {
		display: inline-block;
		padding: 2px 8px;
		border-radius: 20px;
		font-size: 10px;
		font-weight: 700;
		letter-spacing: 0.3px;
	}
	.com-badge-abierto {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success);
	}
	.com-badge-cerrado {
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.com-btn-exp {
		height: 28px;
		padding: 0 12px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-success);
		color: #fff;
		font-size: 11px;
		font-weight: 600;
		font-family: inherit;
		cursor: pointer;
	}
	.com-btn-cerrar {
		height: 28px;
		padding: 0 12px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-bg);
		color: var(--neo-text-2);
		font-size: 11px;
		font-family: inherit;
		cursor: pointer;
		box-shadow: var(--neo-e1);
	}
	.com-btn-cerrar:hover {
		box-shadow: var(--neo-e2);
		color: var(--neo-text);
	}
	.com-ver-ventas {
		font-size: 11px;
		color: var(--neo-accent);
		font-weight: 600;
		background: none;
		border: none;
		cursor: pointer;
		padding: 0;
		font-family: inherit;
	}
	.com-ver-ventas:hover {
		text-decoration: underline;
	}
	@media (max-width: 900px) {
		.dash-main {
			grid-template-columns: 1fr;
			grid-template-areas: 'kpis' 'ven' 'prod' 'caja' 'feed' 'tend' 'usrs' 'vxv' 'dmp' 'com';
			padding: 10px;
		}
		.kpis-row {
			grid-template-columns: 1fr 1fr;
		}
		.ven-inner {
			grid-template-columns: 1fr;
		}
		.torta-col {
			display: none;
		}
		.caja-inner {
			grid-template-columns: 1fr;
		}
		.tend-stats {
			grid-template-columns: 1fr 1fr;
		}
	}
</style>
