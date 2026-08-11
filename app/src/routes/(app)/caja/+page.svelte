<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { api } from '$lib/api';
	import { leerSesion } from '$lib/session';
	import { cajaOperativaId } from '$lib/operativa';
	import { toast_ } from '$lib/toast';

	type Turno = { id: number; abierto_en: string; usuario_nombre: string; fondo_inicial: number | string };
	type TurnoActual = {
		no_aplica?: boolean;
		turno: Turno | null;
		fondo_sugerido?: number;
		count_total?: number;
		count_devoluciones?: number;
		count_efectivo?: number;
		count_tarjeta?: number;
		count_transferencia?: number;
		count_cheque?: number;
		count_mercado_pago?: number;
		count_cc?: number;
		count_mixto?: number;
		total_efectivo?: number;
		total_tarjeta?: number;
		total_transferencia?: number;
		total_cheque?: number;
		total_mercado_pago?: number;
		total_cc?: number;
		total_ingresos?: number;
		total_retiros?: number;
		total_devoluciones?: number;
		ventas_efectivo?: number;
		ventas_tarjeta?: number;
		ventas_transferencia?: number;
		ventas_mercado_pago?: number;
		ventas_cheque?: number;
		ventas_cc?: number;
		ultima_venta?: string | null;
		ventas_por_hora?: { hora: string; total: number; cnt: number }[];
		cierres_parciales?: { registrado_en: string; usuario_nombre: string; total_efectivo: number; total_tarjeta: number; total_mercado_pago: number; diferencia_efectivo: number }[];
	};
	type PeriodoData = {
		turno_id?: number;
		periodo_desde: string;
		fondo_periodo: number;
		efectivo_esperado: number;
		total_efectivo: number;
		total_tarjeta: number;
		total_transferencia: number;
		total_cheque: number;
		total_mercado_pago: number;
		total_cc: number;
		total_ingresos: number;
		total_retiros: number;
		total_devoluciones: number;
		count_total?: number;
	};
	type DatosIngresados = {
		efectivo_contado: number;
		fondo_siguiente: number;
		tarjeta_contado: number;
		transf_contado: number;
		mp_contado: number;
		cheques: number;
		depositos: number;
		posnet_cierres: { nombre: string; monto: number }[];
		observaciones: string | null;
	};
	type FacturaSinCae = { tipo_comprobante: string; numero: number | string; total: number; afip_error: string | null };
	type PosnetTerminal = { nombre: string };

	function fmt(n: number | string | null | undefined) {
		return '$ ' + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function fmtDt(s: string) {
		return new Date(s).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' });
	}
	function fmtCompacto(n: number) {
		n = Number(n || 0);
		if (n >= 1000000) return (n / 1000000).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + 'M';
		if (n >= 1000) return (n / 1000).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + 'k';
		return String(Math.round(n));
	}
	function pct(v: number, total: number) {
		return ((v / total) * 100).toFixed(1).replace('.', ',') + ' %';
	}

	const sesion = leerSesion();
	let cajaId = $state<number | null>(null);
	let cajaNombre = $state('');
	let cajas = $state<{ id: number; nombre: string }[]>([]);

	let vista = $state<'cargando' | 'no_aplica' | 'apertura' | 'turno_abierto'>('cargando');
	let fondoSugerido = $state(0);
	let turnoActualId = $state<number | null>(null);
	let turnoData = $state<TurnoActual | null>(null);
	let terminalesPostnet = $state<PosnetTerminal[]>([]);
	let apFondo = $state('');
	let abriendoCaja = $state(false);

	async function resolverCajaActual() {
		const id = cajaOperativaId();
		cajaId = id;
		if (!cajas.length) {
			const r = await api('/cajas');
			if (r.ok) cajas = await r.json();
		}
		const c = cajas.find((x) => x.id === id);
		cajaNombre = c?.nombre || sesion?.caja_nombre || '';
	}

	async function cargar() {
		await resolverCajaActual();
		if (!cajaId) {
			vista = 'no_aplica';
			return;
		}
		try {
			const rc = await api('/configuracion');
			const cfg = await rc.json();
			terminalesPostnet = Array.isArray(cfg.posnet_terminales) ? cfg.posnet_terminales : [];
		} catch {
			terminalesPostnet = [];
		}

		const res = await api(`/caja-turnos/actual?caja_id=${cajaId}`);
		const data: TurnoActual = await res.json();

		if (data.no_aplica) {
			vista = 'no_aplica';
		} else if (!data.turno) {
			fondoSugerido = data.fondo_sugerido || 0;
			apFondo = '';
			turnoActualId = null;
			vista = 'apertura';
		} else {
			turnoActualId = data.turno.id;
			turnoData = data;
			vista = 'turno_abierto';
		}
	}

	async function abrirCajaAccion() {
		const fondo_inicial = parseFloat(apFondo) || 0;
		if (fondo_inicial < 0) {
			toast_('Fondo inicial inválido', 'err');
			return;
		}
		abriendoCaja = true;
		try {
			const res = await api('/caja-turnos', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ caja_id: cajaId, fondo_inicial }) });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al abrir la caja', 'err');
				return;
			}
			toast_('Caja abierta', 'ok');
			cargar();
		} finally {
			abriendoCaja = false;
		}
	}

	// ── Visualizaciones del turno ────────────────────────────────
	const MEDIOS_COBRO = [
		{ k: 'efectivo', lbl: 'Efectivo', color: '#15803D' },
		{ k: 'tarjeta', lbl: 'Tarjeta', color: 'var(--color-primary)' },
		{ k: 'transferencia', lbl: 'Transferencia', color: '#7C3AED' },
		{ k: 'mercado_pago', lbl: 'Mercado Pago', color: '#0891B2' },
		{ k: 'cheque', lbl: 'Cheque', color: '#B45309' },
		{ k: 'cc', lbl: 'Cta. Cte.', color: '#9B9590' }
	];

	const totalRecaudado = $derived.by(() => {
		if (!turnoData) return 0;
		return Number(turnoData.total_efectivo || 0) + Number(turnoData.total_tarjeta || 0) + Number(turnoData.total_transferencia || 0) + Number(turnoData.total_cheque || 0) + Number(turnoData.total_mercado_pago || 0) + Number(turnoData.total_cc || 0);
	});
	const totalVentas = $derived.by(() => {
		if (!turnoData) return 0;
		return MEDIOS_COBRO.reduce((s, m) => s + Number((turnoData as Record<string, unknown>)['ventas_' + m.k] as number || 0), 0);
	});
	const efectivoEsperado = $derived(turnoData?.turno ? Number(turnoData.turno.fondo_inicial) + Number(turnoData.total_efectivo || 0) : 0);

	const medios = $derived.by(() => {
		if (!turnoData) return [];
		return MEDIOS_COBRO.map((m) => ({ ...m, val: Number((turnoData as Record<string, unknown>)['ventas_' + m.k] as number || 0), cnt: Number((turnoData as Record<string, unknown>)['count_' + m.k] as number || 0) })).filter((m) => m.val > 0.009);
	});
	const mediosTotal = $derived(medios.reduce((s, m) => s + m.val, 0));
	const donutSegs = $derived.by(() => {
		const R = 62,
			C = 2 * Math.PI * R;
		const gap = medios.length > 1 ? 2.5 : 0;
		let off = 0;
		return medios.map((m) => {
			const len = (m.val / mediosTotal) * C;
			const seg = { color: m.color, dasharray: `${Math.max(len - gap, 1).toFixed(2)} ${C.toFixed(2)}`, dashoffset: (-off).toFixed(2), lbl: m.lbl, val: m.val };
			off += len;
			return seg;
		});
	});

	const horasCols = $derived.by(() => {
		if (!turnoData?.turno) return [];
		const map: Record<string, { hora: string; total: number; cnt: number }> = {};
		(turnoData.ventas_por_hora || []).forEach((h) => (map[h.hora] = h));
		const pad2 = (n: number) => String(n).padStart(2, '0');
		const desde = new Date(turnoData.turno.abierto_en);
		if (isNaN(desde.getTime())) return [];
		desde.setMinutes(0, 0, 0);
		const ahora = new Date();
		const ventana = new Date(ahora);
		ventana.setMinutes(0, 0, 0);
		ventana.setHours(ventana.getHours() - 23);
		const inicio = desde > ventana ? desde : ventana;
		const cols: { lbl: string; total: number; cnt: number }[] = [];
		for (let d = new Date(inicio); d <= ahora && cols.length < 24; d.setHours(d.getHours() + 1)) {
			const key = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' ' + pad2(d.getHours());
			const h = map[key];
			cols.push({ lbl: pad2(d.getHours()), total: Number(h?.total || 0), cnt: Number(h?.cnt || 0) });
		}
		return cols;
	});
	const horasMax = $derived(Math.max(...horasCols.map((c) => c.total), 0));
	const idxTransf = $derived(terminalesPostnet.length ? terminalesPostnet.length + 1 : 2);
	const horasVacioMsg = $derived(
		horasMax > 0 ? null : (turnoData?.ventas_por_hora || []).length ? 'Sin ventas en las últimas 24 horas.' : 'Sin ventas registradas en el turno todavía.'
	);

	const statsData = $derived.by(() => {
		if (!turnoData?.turno) return null;
		const t = turnoData.turno;
		const ops = Number(turnoData.count_total || 0);
		const ticket = ops > 0 ? totalVentas / ops : 0;
		const ahora = new Date();
		const durMin = Math.max(0, Math.floor((ahora.getTime() - new Date(t.abierto_en).getTime()) / 60000));
		const dur = Math.floor(durMin / 60) + ' h ' + String(durMin % 60).padStart(2, '0') + ' m';
		const desde = new Date(t.abierto_en).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
		let ultimaVal = '—',
			ultimaSub = 'sin ventas aún';
		if (turnoData.ultima_venta) {
			const uv = new Date(turnoData.ultima_venta);
			const hace = Math.max(0, Math.round((ahora.getTime() - uv.getTime()) / 60000));
			ultimaVal = uv.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
			ultimaSub = hace < 1 ? 'recién' : hace < 60 ? `hace ${hace} min` : `hace ${Math.floor(hace / 60)} h ${hace % 60} m`;
		}
		const nDev = Number(turnoData.count_devoluciones || 0);
		return { ops, ticket, dur, desde, ultimaVal, ultimaSub, nDev };
	});

	function cnt(n: number) {
		return n > 0 ? `(${n} op.)` : '';
	}

	// ── Alerta facturas sin CAE ──────────────────────────────────
	let modalAlertaCae = $state(false);
	let facturasPendientes = $state<FacturaSinCae[]>([]);
	let alertaCaeTipo = $state<'parcial' | 'total' | null>(null);

	async function verificarFacturasSinCae(): Promise<FacturaSinCae[]> {
		if (!turnoActualId) return [];
		try {
			const r = await api(`/ventas?turno_id=${turnoActualId}&sin_cae=1&limit=50`);
			const d = await r.json();
			return Array.isArray(d) ? d : [];
		} catch {
			return [];
		}
	}
	function cerrarAlertaCae() {
		modalAlertaCae = false;
		alertaCaeTipo = null;
	}
	function irAVentasDesdeAlerta() {
		location.href = '/ventas';
	}
	function forzarCierreDesdeAlerta() {
		const tipo = alertaCaeTipo || 'total';
		cerrarAlertaCae();
		abrirModalSinCheck(tipo);
	}

	// ── Modal de cierre ──────────────────────────────────────────
	let modalCierre = $state(false);
	let modalTipo = $state<'parcial' | 'total' | null>(null);
	let pasoActual = $state(1);
	let periodoData = $state<PeriodoData | null>(null);
	let datosIngresados = $state<Partial<DatosIngresados>>({});
	let cargandoModal = $state(false);
	let errorModal = $state('');
	let confirmandoCierre = $state(false);

	// Inputs paso 1
	let efContado = $state('');
	let tarjetaContado = $state('');
	let transfContado = $state('');
	let mpContado = $state('');
	let chequesRec = $state('');
	let posnetInputs = $state<string[]>([]);
	let obsCierre = $state('');
	let fondoSiguienteInput = $state('');
	let inputRefs: HTMLInputElement[] = [];

	async function abrirModal(tipo: 'parcial' | 'total') {
		const pendientes = await verificarFacturasSinCae();
		if (pendientes.length > 0) {
			facturasPendientes = pendientes;
			alertaCaeTipo = tipo;
			modalAlertaCae = true;
			return;
		}
		abrirModalSinCheck(tipo);
	}

	async function abrirModalSinCheck(tipo: 'parcial' | 'total') {
		modalTipo = tipo;
		periodoData = null;
		pasoActual = 1;
		datosIngresados = {};
		errorModal = '';
		cargandoModal = true;
		modalCierre = true;

		const url = tipo === 'parcial' ? `/caja-turnos/${turnoActualId}/periodo` : `/caja-turnos/actual?caja_id=${cajaId}`;
		try {
			const res = await api(url);
			const data = await res.json();

			if (tipo === 'total') {
				const t = data.turno;
				periodoData = {
					turno_id: t.id,
					periodo_desde: t.abierto_en,
					fondo_periodo: Number(t.fondo_inicial),
					efectivo_esperado: Number(t.fondo_inicial) + Number(data.total_efectivo || 0),
					total_efectivo: Number(data.total_efectivo || 0),
					total_tarjeta: Number(data.total_tarjeta || 0),
					total_transferencia: Number(data.total_transferencia || 0),
					total_cheque: Number(data.total_cheque || 0),
					total_mercado_pago: Number(data.total_mercado_pago || 0),
					total_cc: Number(data.total_cc || 0),
					total_ingresos: Number(data.total_ingresos || 0),
					total_retiros: Number(data.total_retiros || 0),
					total_devoluciones: Number(data.total_devoluciones || 0),
					count_total: data.count_total
				};
			} else {
				periodoData = {
					...data,
					total_efectivo: Number(data.total_efectivo || 0),
					total_tarjeta: Number(data.total_tarjeta || 0),
					total_transferencia: Number(data.total_transferencia || 0),
					total_cheque: Number(data.total_cheque || 0),
					total_mercado_pago: Number(data.total_mercado_pago || 0),
					total_cc: Number(data.total_cc || 0),
					total_ingresos: Number(data.total_ingresos || 0),
					total_retiros: Number(data.total_retiros || 0),
					total_devoluciones: Number(data.total_devoluciones || 0)
				};
			}
			renderPaso1Prep();
		} catch {
			errorModal = 'Error al cargar los totales. Intentá de nuevo.';
		} finally {
			cargandoModal = false;
		}
	}

	function renderPaso1Prep() {
		efContado = '';
		tarjetaContado = '';
		transfContado = '';
		mpContado = '';
		chequesRec = '';
		posnetInputs = terminalesPostnet.map(() => '');
		obsCierre = datosIngresados.observaciones || '';
		fondoSiguienteInput = (datosIngresados.fondo_siguiente ?? periodoData?.fondo_periodo ?? 0).toFixed(2);

		if (datosIngresados.efectivo_contado !== undefined) {
			efContado = datosIngresados.efectivo_contado.toFixed(2);
			transfContado = (datosIngresados.transf_contado || 0).toFixed(2);
			mpContado = (datosIngresados.mp_contado || 0).toFixed(2);
			chequesRec = (datosIngresados.cheques || 0).toFixed(2);
			tarjetaContado = (datosIngresados.tarjeta_contado || 0).toFixed(2);
			if (datosIngresados.posnet_cierres) {
				posnetInputs = terminalesPostnet.map((_, i) => (datosIngresados.posnet_cierres?.[i] ? String(datosIngresados.posnet_cierres[i].monto.toFixed(2)) : ''));
			}
		}
		setTimeout(() => inputRefs[0]?.focus(), 60);
	}

	function onArqueoKeydown(e: KeyboardEvent, idx: number) {
		if (e.key === 'Enter') {
			e.preventDefault();
			inputRefs[idx + 1]?.focus();
		}
	}

	function irPaso2() {
		const ef = parseFloat(efContado);
		if (isNaN(ef) || ef < 0) {
			toast_('Ingresá el efectivo contado', 'err');
			return;
		}
		const tarjetaCont = terminalesPostnet.length ? posnetInputs.reduce((s, v) => s + (parseFloat(v) || 0), 0) : parseFloat(tarjetaContado) || 0;
		const posnet_cierres = terminalesPostnet.map((t, i) => ({ nombre: t.nombre, monto: parseFloat(posnetInputs[i]) || 0 }));

		let fondo_siguiente: number;
		if (modalTipo === 'parcial') {
			fondo_siguiente = ef;
		} else {
			const fondoRaw = parseFloat(fondoSiguienteInput);
			fondo_siguiente = isNaN(fondoRaw) ? (periodoData?.fondo_periodo ?? 0) : Math.max(0, fondoRaw);
			if (fondo_siguiente > ef + 0.01) {
				toast_('El fondo no puede ser mayor al efectivo contado', 'err');
				return;
			}
		}

		datosIngresados = {
			efectivo_contado: ef,
			fondo_siguiente,
			tarjeta_contado: tarjetaCont,
			transf_contado: parseFloat(transfContado) || 0,
			mp_contado: parseFloat(mpContado) || 0,
			cheques: parseFloat(chequesRec) || 0,
			depositos: 0,
			posnet_cierres,
			observaciones: obsCierre.trim() || null
		};
		pasoActual = 2;
	}

	// Paso 2 / 3 — comparación derivada de periodoData + datosIngresados
	const filasCmp = $derived.by(() => {
		if (!periodoData) return [];
		const d = periodoData;
		const di = datosIngresados;
		return [
			{ lbl: 'Efectivo', sis: d.efectivo_esperado, cnt: di.efectivo_contado ?? 0 },
			{ lbl: 'Tarjeta', sis: d.total_tarjeta, cnt: di.tarjeta_contado ?? 0 },
			{ lbl: 'Transferencia', sis: d.total_transferencia, cnt: di.transf_contado ?? 0 },
			{ lbl: 'QR Mercado Pago', sis: d.total_mercado_pago, cnt: di.mp_contado ?? 0 },
			{ lbl: 'Cheques', sis: d.total_cheque, cnt: di.cheques ?? 0 }
		].filter((f) => f.sis > 0.009 || f.cnt > 0.009);
	});
	const totalEsp = $derived(filasCmp.reduce((s, f) => s + f.sis, 0));
	const totalCnt = $derived(filasCmp.reduce((s, f) => s + f.cnt, 0));
	const totalDif = $derived(totalCnt - totalEsp);
	const hasDifTotal = $derived(Math.abs(totalDif) >= 0.01);
	const fondoLabel = $derived(modalTipo === 'parcial' ? 'El siguiente cajero recibe en caja' : 'Fondo para próximo turno');
	const fondoVal = $derived(datosIngresados.fondo_siguiente ?? 0);
	const retiro = $derived(modalTipo !== 'parcial' ? Math.max(0, (datosIngresados.efectivo_contado ?? 0) - (datosIngresados.fondo_siguiente ?? 0)) : 0);

	function avanzarPaso() {
		if (pasoActual === 1) irPaso2();
		else if (pasoActual === 2) pasoActual = 3;
	}
	function retrocederPaso() {
		if (pasoActual === 2) {
			pasoActual = 1;
			renderPaso1Prep();
		} else if (pasoActual === 3) pasoActual = 2;
	}

	async function confirmarCierre() {
		const di = datosIngresados;
		confirmandoCierre = true;
		const posnetFiltrado = di.posnet_cierres?.filter((p) => p.monto > 0);
		const body = {
			efectivo_contado: di.efectivo_contado,
			cheques_recibidos: di.cheques || null,
			depositos_recibidos: di.depositos || null,
			posnet_cierres: posnetFiltrado?.length ? posnetFiltrado : (di.tarjeta_contado ?? 0) > 0 ? [{ nombre: 'Tarjeta', monto: di.tarjeta_contado }] : null,
			mercado_pago_contado: di.mp_contado || null,
			fondo_siguiente: di.fondo_siguiente,
			observaciones: di.observaciones
		};
		const url = modalTipo === 'parcial' ? `/caja-turnos/${turnoActualId}/cierre-parcial` : `/caja-turnos/${turnoActualId}/cerrar`;
		try {
			const res = await api(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const data = await res.json();
			if (!res.ok) {
				toast_(data.error || 'Error al registrar el cierre', 'err');
				return;
			}
			cerrarModal();
			toast_(modalTipo === 'parcial' ? 'Cierre parcial registrado' : 'Caja cerrada', 'ok');
			cargar();
		} finally {
			confirmandoCierre = false;
		}
	}

	function cerrarModal() {
		modalCierre = false;
		modalTipo = null;
		periodoData = null;
		pasoActual = 1;
		datosIngresados = {};
	}

	// ── Teclado + refresco periódico + reactividad a cambio de caja ─
	function onKeydown(e: KeyboardEvent) {
		const tag = (document.activeElement as HTMLElement)?.tagName;
		if (!modalCierre && (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT')) return;
		if (modalCierre) {
			if (e.key === 'Escape') cerrarModal();
			return;
		}
		if ((e.key === 'x' || e.key === 'X') && turnoActualId) {
			e.preventDefault();
			abrirModal('parcial');
		}
		if ((e.key === 'z' || e.key === 'Z') && turnoActualId) {
			e.preventDefault();
			abrirModal('total');
		}
	}
	function onCajaCambiada() {
		cargar();
	}

	let refrescoInterval: ReturnType<typeof setInterval>;
	onMount(() => {
		document.addEventListener('keydown', onKeydown);
		window.addEventListener('caja-changed', onCajaCambiada);
		refrescoInterval = setInterval(() => {
			if (turnoActualId && !modalCierre) cargar();
		}, 60000);
	});
	onDestroy(() => {
		document.removeEventListener('keydown', onKeydown);
		window.removeEventListener('caja-changed', onCajaCambiada);
		clearInterval(refrescoInterval);
	});

	cargar();
</script>

<svelte:head>
	<title>Logos — Caja</title>
</svelte:head>

<div class="page-header">
	<div>
		<h1>Caja</h1>
		<p>{cajaNombre || 'Apertura, movimientos y cierre del turno'}</p>
	</div>
	<div class="page-header-meta">
		<a href="/operaciones" class="btn btn-sec" style="text-decoration:none;font-size:10px">Ver operaciones</a>
		<span style="font-size:11px;color:var(--gris3)">
			<kbd style="font-size:10px;padding:1px 5px;border:1px solid #ccc;background:#f5f5f5">X</kbd> Cierre parcial
			&nbsp;·&nbsp;
			<kbd style="font-size:10px;padding:1px 5px;border:1px solid #ccc;background:#f5f5f5">Z</kbd> Cierre diario
		</span>
	</div>
</div>

<div class="contenido">
	<div class="contenido-inner" class:solo={vista !== 'turno_abierto'}>
		{#if vista === 'cargando'}
			<div class="card"><div class="card-body"><p class="form-hint">Cargando…</p></div></div>
		{:else if vista === 'no_aplica'}
			<div class="card">
				<div class="card-body">
					<p class="no-aplica">Esta caja (<strong>{cajaNombre}</strong>) no maneja apertura ni cierre de turno.</p>
				</div>
			</div>
		{:else if vista === 'apertura'}
			<div class="card">
				<div class="card-header">
					<h2>Apertura de caja — {cajaNombre}</h2>
					<span class="estado-pill cerrado"><span class="dot"></span>Sin turno abierto</span>
				</div>
				<div class="card-body">
					<div class="form-group">
						<label class="form-label" for="ap-fondo">Fondo inicial</label>
						<input class="form-input" id="ap-fondo" type="number" min="0" step="0.01" placeholder="0.00" style="width:200px" autocomplete="off" bind:value={apFondo} />
						<span class="form-hint" style="margin-top:4px">
							{#if fondoSugerido > 0.009}
								Sugerido del último cierre: <strong>{fmt(fondoSugerido)}</strong>
							{:else}
								Dejá en blanco para abrir sin fondo.
							{/if}
						</span>
					</div>
					<div style="margin-top:16px">
						<button class="btn btn-ok" disabled={abriendoCaja} onclick={abrirCajaAccion}>Abrir caja</button>
					</div>
				</div>
			</div>
		{:else if vista === 'turno_abierto' && turnoData?.turno}
			{@const t = turnoData.turno}
			<div class="layout-turno">
				<div class="layout-col">
					<div class="card">
						<div class="card-header">
							<h2>Turno actual — {cajaNombre}</h2>
							<span class="estado-pill abierto"><span class="dot"></span>Abierto</span>
						</div>
						<div class="card-body">
							<p class="form-hint" style="margin-bottom:16px">Abierto por <strong>{t.usuario_nombre}</strong> · {fmtDt(t.abierto_en)}</p>
							<div class="ledger">
								<div class="ledger-row"><span class="ledger-lbl">Fondo inicial</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(t.fondo_inicial)}</span></div>
								<div class="ledger-row"><span class="ledger-lbl">Efectivo {cnt(turnoData.count_efectivo || 0)}</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(turnoData.total_efectivo)}</span></div>
								<div class="ledger-row"><span class="ledger-lbl">Tarjeta {cnt(turnoData.count_tarjeta || 0)}</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(turnoData.total_tarjeta)}</span></div>
								<div class="ledger-row"><span class="ledger-lbl">Transferencia {cnt(turnoData.count_transferencia || 0)}</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(turnoData.total_transferencia)}</span></div>
								<div class="ledger-row"><span class="ledger-lbl">Cheque {cnt(turnoData.count_cheque || 0)}</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(turnoData.total_cheque)}</span></div>
								<div class="ledger-row"><span class="ledger-lbl">Mercado Pago {cnt(turnoData.count_mercado_pago || 0)}</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(turnoData.total_mercado_pago)}</span></div>
								{#if (turnoData.total_cc ?? 0) > 0}
									<div class="ledger-row"><span class="ledger-lbl">Cta. Cte. {cnt(turnoData.count_cc || 0)}</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(turnoData.total_cc)}</span></div>
								{/if}
								{#if (turnoData.count_mixto ?? 0) > 0}
									<div class="ledger-row"><span class="ledger-lbl">Pago mixto {cnt(turnoData.count_mixto || 0)}</span><span class="ledger-fill"></span><span class="ledger-val" style="color:var(--gris3);font-size:11px">desglosado arriba</span></div>
								{/if}
								{#if (turnoData.total_ingresos ?? 0) > 0}
									<div class="ledger-row" style="color:#15803D"><span class="ledger-lbl" style="color:#15803D">+ Ingresos de caja</span><span class="ledger-fill"></span><span class="ledger-val" style="color:#15803D">+{fmt(turnoData.total_ingresos)}</span></div>
								{/if}
								{#if (turnoData.total_retiros ?? 0) > 0}
									<div class="ledger-row" style="color:var(--rojo)"><span class="ledger-lbl" style="color:var(--rojo)">− Retiros de caja</span><span class="ledger-fill"></span><span class="ledger-val" style="color:var(--rojo)">-{fmt(turnoData.total_retiros)}</span></div>
								{/if}
								{#if (turnoData.count_devoluciones ?? 0) > 0}
									<div class="ledger-row" style="color:var(--rojo)"><span class="ledger-lbl" style="color:var(--rojo)">Devoluciones {cnt(turnoData.count_devoluciones || 0)}</span><span class="ledger-fill"></span><span class="ledger-val" style="color:var(--rojo)">-{fmt(turnoData.total_devoluciones)}</span></div>
								{/if}
								<div class="ledger-row bold sep"><span class="ledger-lbl">Total recaudado</span><span class="ledger-fill"></span><span class="ledger-val">{fmt(totalRecaudado)}</span></div>
								<div class="ledger-row accent" style="margin-top:6px"><span class="ledger-lbl" style="color:var(--azul);font-weight:600">Efectivo esperado en caja</span><span class="ledger-fill"></span><span class="ledger-val" style="color:var(--azul)">{fmt(efectivoEsperado)}</span></div>
							</div>
						</div>
					</div>

					<div class="acciones">
						<button class="accion-btn" onclick={() => abrirModal('parcial')}>
							<span class="kbd">X</span>
							<div class="accion-lbl"><strong>Cierre Parcial</strong><span>Cambio de cajero — la caja sigue abierta</span></div>
						</button>
						<button class="accion-btn danger" onclick={() => abrirModal('total')}>
							<span class="kbd">Z</span>
							<div class="accion-lbl"><strong>Cierre Diario</strong><span>Cierra el turno definitivamente</span></div>
						</button>
					</div>
				</div>

				<div class="layout-col">
					{#if statsData}
						<div class="stats-strip">
							<div class="stat"><div class="stat-lbl">Operaciones</div><div class="stat-val">{statsData.ops}</div><div class="stat-sub">{statsData.nDev > 0 ? statsData.nDev + (statsData.nDev > 1 ? ' devoluciones' : ' devolución') : 'sin devoluciones'}</div></div>
							<div class="stat"><div class="stat-lbl">Ticket promedio</div><div class="stat-val">{fmt(statsData.ticket)}</div><div class="stat-sub">sobre lo vendido</div></div>
							<div class="stat"><div class="stat-lbl">Última venta</div><div class="stat-val">{statsData.ultimaVal}</div><div class="stat-sub">{statsData.ultimaSub}</div></div>
							<div class="stat"><div class="stat-lbl">Turno abierto</div><div class="stat-val">{statsData.dur}</div><div class="stat-sub">desde las {statsData.desde}</div></div>
						</div>
					{/if}

					<div class="card">
						<div class="card-header"><h2>Medios de cobro del turno</h2></div>
						<div class="card-body">
							{#if !medios.length}
								<div class="chart-vacio">Todavía no hay cobros registrados en este turno.</div>
							{:else}
								<div class="mix-grid">
									<svg width="180" height="180" viewBox="0 0 180 180" role="img" aria-label="Distribución de medios de cobro">
										{#each donutSegs as seg, i (i)}
											<circle cx="90" cy="90" r="62" fill="none" stroke={seg.color} stroke-width="30" stroke-dasharray={seg.dasharray} stroke-dashoffset={seg.dashoffset} transform="rotate(-90 90 90)"
												><title>{seg.lbl}: {fmt(seg.val)} ({pct(seg.val, mediosTotal)})</title></circle
											>
										{/each}
									</svg>
									<div class="ledger mix-leyenda">
										{#each medios as m, i (i)}
											<div class="ledger-row">
												<span class="ledger-lbl"><span class="swatch" style="background:{m.color}"></span>{m.lbl} {cnt(m.cnt)}</span>
												<span class="ledger-fill"></span>
												<span class="ledger-val">{fmt(m.val)}</span>
												<span class="leyenda-pct">{pct(m.val, mediosTotal)}</span>
											</div>
										{/each}
										<div class="ledger-row bold sep">
											<span class="ledger-lbl">Total vendido</span>
											<span class="ledger-fill"></span>
											<span class="ledger-val">{fmt(mediosTotal)}</span>
											<span class="leyenda-pct">100 %</span>
										</div>
									</div>
								</div>
							{/if}
						</div>
					</div>

					<div class="card">
						<div class="card-header"><h2>Ventas por hora</h2></div>
						<div class="card-body" style="padding-top:6px">
							{#if horasVacioMsg}
								<div class="chart-vacio">{horasVacioMsg}</div>
							{:else}
								<div class="horas-chart">
									{#each horasCols as c, i (i)}
										{@const alto = c.total > 0 ? Math.max(4, Math.round((c.total / horasMax) * 100)) : 0}
										<div class="hora-col" title="{c.lbl}:00 — {c.cnt} op. · {fmt(c.total)}">
											{#if c.total > 0}<span class="hora-val">{fmtCompacto(c.total)}</span>{/if}
											<div class="hora-bar {c.total > 0 ? '' : 'vacia'}" style="height:{alto}%"></div>
											<span class="hora-lbl">{c.lbl}</span>
										</div>
									{/each}
								</div>
							{/if}
						</div>
					</div>
				</div>

				{#if turnoData.cierres_parciales?.length}
					<div class="layout-full">
						<div class="card">
							<div class="card-header"><h2>Cierres parciales del turno</h2></div>
							<div style="overflow-x:auto">
								<table class="hist-tabla">
									<thead>
										<tr><th>Fecha / Hora</th><th>Tipo</th><th>Cajero</th><th class="r">Efectivo</th><th class="r">Tarjeta</th><th class="r">Mercado Pago</th><th class="r">Diferencia</th></tr>
									</thead>
									<tbody>
										{#each turnoData.cierres_parciales as c, i (i)}
											{@const dif = Number(c.diferencia_efectivo)}
											{@const difStyle = dif < -0.01 ? 'color:var(--rojo)' : dif > 0.01 ? 'color:#15803D' : ''}
											<tr>
												<td>{fmtDt(c.registrado_en)}</td>
												<td><span class="badge-parcial">Parcial</span></td>
												<td>{c.usuario_nombre || '—'}</td>
												<td class="r">{fmt(c.total_efectivo)}</td>
												<td class="r">{fmt(c.total_tarjeta)}</td>
												<td class="r">{fmt(c.total_mercado_pago)}</td>
												<td class="r" style="font-weight:700;{difStyle}">{dif >= 0 ? '+' : ''}{fmt(dif)}</td>
											</tr>
										{/each}
									</tbody>
								</table>
							</div>
						</div>
					</div>
				{/if}
			</div>
		{/if}
	</div>
</div>

<!-- Modal alerta: facturas sin CAE -->
{#if modalAlertaCae}
	<div class="modal-overlay visible" role="dialog" aria-modal="true">
		<div class="modal-cierre" style="max-width:580px;width:min(96vw,580px)">
			<div class="modal-header">
				<div class="modal-header-txt">
					<h2 style="font-size:13px;font-weight:800;color:#B91C1C;text-transform:none;letter-spacing:0">Facturas pendientes de autorización ARCA</h2>
					<p style="font-size:12px;color:#666;margin-top:4px">Este turno tiene facturas electrónicas sin CAE aprobado. Autorizalas antes de cerrar para mantener los comprobantes válidos.</p>
				</div>
			</div>
			<div style="padding:16px 18px;overflow-y:auto;max-height:52vh">
				<table style="width:100%;border-collapse:collapse">
					<thead>
						<tr style="background:var(--color-bg-alt)">
							<th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666;border-bottom:1px solid var(--borde-fuerte)">Tipo</th>
							<th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666;border-bottom:1px solid var(--borde-fuerte)">Número</th>
							<th style="padding:7px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666;border-bottom:1px solid var(--borde-fuerte)">Total</th>
							<th style="padding:7px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#666;border-bottom:1px solid var(--borde-fuerte)">Estado AFIP</th>
						</tr>
					</thead>
					<tbody>
						{#each facturasPendientes as f, i (i)}
							<tr>
								<td style="padding:6px 10px;border-bottom:1px solid var(--color-bg-alt);font-size:12px">{f.tipo_comprobante}</td>
								<td style="padding:6px 10px;border-bottom:1px solid var(--color-bg-alt);font-size:12px;font-variant-numeric:tabular-nums">N°{f.numero}</td>
								<td style="padding:6px 10px;border-bottom:1px solid var(--color-bg-alt);font-size:12px;text-align:right;font-variant-numeric:tabular-nums">{fmt(f.total)}</td>
								<td style="padding:6px 10px;border-bottom:1px solid var(--color-bg-alt)">
									{#if f.afip_error}
										<span style="color:#B91C1C;font-size:11px">{String(f.afip_error).substring(0, 80)}{f.afip_error.length > 80 ? '…' : ''}</span>
									{:else}
										<span style="color:#9B9590;font-size:11px">Sin intentar</span>
									{/if}
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
			<div style="padding:12px 18px;border-top:1px solid var(--borde-fuerte);display:flex;align-items:center;gap:8px;justify-content:flex-end;flex-wrap:wrap">
				<button class="btn btn-sec" onclick={cerrarAlertaCae}>Cancelar</button>
				<button class="btn btn-danger" onclick={forzarCierreDesdeAlerta}>Cerrar de todas formas</button>
				<button class="btn btn-ok" onclick={irAVentasDesdeAlerta}>Ir a Ventas</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal de cierre (X y Z) — wizard 3 pasos -->
{#if modalCierre}
	<div class="modal-overlay visible" role="dialog" aria-modal="true">
		<div class="modal-cierre">
			<div class="modal-header">
				<div class="modal-header-txt">
					<h2>{modalTipo === 'parcial' ? 'Cierre Parcial (X)' : 'Cierre Diario (Z)'}</h2>
					<p>{modalTipo === 'parcial' ? 'Corte de turno — la caja continua abierta.' : 'Cierre definitivo del turno.'}</p>
				</div>
				<button class="modal-close" aria-label="Cerrar" onclick={cerrarModal}>×</button>
			</div>
			<div class="stepper">
				{#each [1, 2, 3] as n, i (i)}
					<div class="step" class:active={n === pasoActual} class:done={n < pasoActual}>
						<div class="step-circle">{n}</div>
						<span class="step-lbl">{n === 1 ? 'Ingresar valores' : n === 2 ? 'Comparar' : 'Reporte'}</span>
					</div>
					{#if n < 3}<div class="step-line"></div>{/if}
				{/each}
			</div>
			<div class="modal-body">
				{#if cargandoModal}
					<p class="form-hint">Cargando…</p>
				{:else if errorModal}
					<p class="form-hint" style="color:var(--rojo)">{errorModal}</p>
				{:else if periodoData && pasoActual === 1}
					<table class="arqueo-table">
						<thead>
							<tr><th>Medio de pago</th><th class="r">Sistema</th><th class="r">Contado hoy</th></tr>
						</thead>
						<tbody>
							<tr>
								<td><div class="am-lbl">Efectivo</div><div class="am-hint">Esperado en caja: <strong>{fmt(periodoData.efectivo_esperado)}</strong></div></td>
								<td class="r am-sis">{fmt(periodoData.total_efectivo)}</td>
								<td><input class="arqueo-input" type="number" min="0" step="0.01" placeholder="0,00" autocomplete="off" bind:value={efContado} bind:this={inputRefs[0]} onkeydown={(e) => onArqueoKeydown(e, 0)} /></td>
							</tr>
							{#if terminalesPostnet.length}
								<tr class="arqueo-group">
									<td colspan="3">
										<span class="am-sub">Posnet / Tarjeta</span>
										<span class="am-hint">&nbsp;—&nbsp;Sistema registra <strong>{fmt(periodoData.total_tarjeta)}</strong> en total entre todas las terminales</span>
									</td>
								</tr>
								{#each terminalesPostnet as term, i (i)}
									<tr>
										<td style="padding-left:28px"><div class="am-lbl">{term.nombre}</div></td>
										<td class="r am-sis">—</td>
										<td><input class="arqueo-input" type="number" min="0" step="0.01" placeholder="0,00" bind:value={posnetInputs[i]} bind:this={inputRefs[1 + i]} onkeydown={(e) => onArqueoKeydown(e, 1 + i)} /></td>
									</tr>
								{/each}
							{:else}
								<tr>
									<td><div class="am-lbl">Tarjeta</div></td>
									<td class="r am-sis">{fmt(periodoData.total_tarjeta)}</td>
									<td><input class="arqueo-input" type="number" min="0" step="0.01" placeholder="0,00" bind:value={tarjetaContado} bind:this={inputRefs[1]} onkeydown={(e) => onArqueoKeydown(e, 1)} /></td>
								</tr>
							{/if}
							<tr>
								<td><div class="am-lbl">Transferencia</div></td>
								<td class="r am-sis">{fmt(periodoData.total_transferencia)}</td>
								<td><input class="arqueo-input" type="number" min="0" step="0.01" placeholder="0,00" bind:value={transfContado} bind:this={inputRefs[idxTransf]} onkeydown={(e) => onArqueoKeydown(e, idxTransf)} /></td>
							</tr>
							<tr>
								<td><div class="am-lbl">QR Mercado Pago</div></td>
								<td class="r am-sis">{fmt(periodoData.total_mercado_pago)}</td>
								<td><input class="arqueo-input" type="number" min="0" step="0.01" placeholder="0,00" bind:value={mpContado} bind:this={inputRefs[idxTransf + 1]} onkeydown={(e) => onArqueoKeydown(e, idxTransf + 1)} /></td>
							</tr>
							<tr>
								<td><div class="am-lbl">Cheques</div></td>
								<td class="r am-sis">{fmt(periodoData.total_cheque)}</td>
								<td><input class="arqueo-input" type="number" min="0" step="0.01" placeholder="0,00" bind:value={chequesRec} bind:this={inputRefs[idxTransf + 2]} onkeydown={(e) => onArqueoKeydown(e, idxTransf + 2)} /></td>
							</tr>
						</tbody>
					</table>

					<div>
						<div class="ms-titulo">Observaciones</div>
						<textarea rows="3" style="width:100%;padding:8px 10px;font-size:12px;font-family:inherit;resize:vertical;box-sizing:border-box;color:var(--neo-text)" placeholder="Notas del cajero, incidentes, diferencias…" bind:value={obsCierre}></textarea>
					</div>
					{#if modalTipo !== 'parcial'}
						<div style="margin-top:14px;padding:12px 14px;border:1px solid var(--borde-fuerte);border-radius:0;background:#fff">
							<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--neo-text-3);margin-bottom:8px">Fondo para próximo turno</div>
							<div style="display:flex;align-items:center;gap:10px">
								<input class="arqueo-input" type="number" min="0" step="0.01" style="max-width:140px" bind:value={fondoSiguienteInput} />
								<span style="font-size:11px;color:var(--gris3)">¿Cuánto dejás en el cajón? (default: {fmt(periodoData.fondo_periodo)})</span>
							</div>
						</div>
					{/if}
				{:else if periodoData && pasoActual === 2}
					<table class="cmp-table">
						<thead>
							<tr><th>Medio de pago</th><th class="r">Esperado</th><th class="r">Contado</th><th class="r">Diferencia</th></tr>
						</thead>
						<tbody>
							{#if !filasCmp.length}
								<tr><td colspan="4" style="text-align:center;color:var(--gris3);padding:16px">Sin movimientos en este período.</td></tr>
							{:else}
								{#each filasCmp as f, i (i)}
									{@const dif = f.cnt - f.sis}
									{@const hasDif = Math.abs(dif) >= 0.01}
									{@const difCls = hasDif ? (dif < 0 ? 'cmp-falta' : 'cmp-ok') : ''}
									<tr>
										<td style="padding:9px 10px;font-size:13px;font-weight:600">{f.lbl}</td>
										<td class="r" style="padding:9px 10px;font-size:13px;color:var(--gris3);font-variant-numeric:tabular-nums">{fmt(f.sis)}</td>
										<td class="r" style="padding:9px 10px;font-size:13px;font-weight:600;font-variant-numeric:tabular-nums">{fmt(f.cnt)}</td>
										<td class="r" style="padding:9px 10px">
											{#if hasDif}
												<span class={difCls} style="font-size:14px;font-weight:700">{dif > 0 ? '+' : ''}{fmt(dif)}</span>
											{:else}
												<span style="color:var(--gris2);font-size:12px">—</span>
											{/if}
										</td>
									</tr>
								{/each}
							{/if}
						</tbody>
						<tfoot>
							<tr style="border-top:2px solid var(--borde);background:var(--gris1)">
								<td style="padding:9px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gris3)">Total</td>
								<td class="r" style="padding:9px 10px;font-size:13px;color:var(--gris3);font-variant-numeric:tabular-nums">{fmt(totalEsp)}</td>
								<td class="r" style="padding:9px 10px;font-size:13px;font-weight:700;font-variant-numeric:tabular-nums">{fmt(totalCnt)}</td>
								<td class="r" style="padding:9px 10px">
									{#if hasDifTotal}
										<span class={totalDif < 0 ? 'cmp-falta' : 'cmp-ok'} style="font-size:15px;font-weight:700">{totalDif > 0 ? '+' : ''}{fmt(totalDif)}</span>
									{:else}
										<span style="color:var(--gris3);font-size:12px">Sin diferencias</span>
									{/if}
								</td>
							</tr>
						</tfoot>
					</table>
					{#if hasDifTotal}
						<div class="dif-total {totalDif < 0 ? 'falta' : 'ok'}">
							<span class="dt-lbl">{totalDif < 0 ? 'Falta en el arqueo' : 'Sobra en el arqueo'}</span>
							<span class="dt-val">{totalDif > 0 ? '+' : ''}{fmt(totalDif)}</span>
						</div>
					{/if}
					<div class="rep-row" style="padding-top:10px">
						<span class="rep-lbl">{fondoLabel}</span>
						<span class="rep-val {fondoVal > 0.009 ? 'cmp-ok' : ''}">{fmt(fondoVal)}</span>
					</div>
					{#if retiro > 0.009}
						<div class="rep-row"><span class="rep-lbl" style="color:var(--gris3)">Sale del cajón</span><span class="rep-val" style="color:var(--gris3)">{fmt(retiro)}</span></div>
					{/if}
				{:else if periodoData && pasoActual === 3}
					<div>
						<div class="ms-titulo">Resumen del turno</div>
						<div style="display:flex;flex-direction:column">
							<div class="rep-row"><span class="rep-lbl">Caja</span><span class="rep-val">{cajaNombre}</span></div>
							<div class="rep-row"><span class="rep-lbl">Período desde</span><span class="rep-val">{fmtDt(periodoData.periodo_desde)}</span></div>
							<div class="rep-row"><span class="rep-lbl">Total operaciones</span><span class="rep-val">{Number(periodoData.count_total || 0)}</span></div>
							<div class="rep-row"><span class="rep-lbl">Total recaudado</span><span class="rep-val" style="font-size:14px;font-weight:700">{fmt(totalCnt)}</span></div>
						</div>
					</div>
					{#if hasDifTotal}
						<div class="dif-total {totalDif < 0 ? 'falta' : 'ok'}">
							<span class="dt-lbl">{totalDif < 0 ? 'Falta en el arqueo' : 'Sobra en el arqueo'}</span>
							<span class="dt-val">{totalDif > 0 ? '+' : ''}{fmt(totalDif)}</span>
						</div>
					{:else}
						<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border:1.5px solid var(--borde);border-radius:6px;background:var(--gris1)">
							<span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gris3)">Arqueo</span>
							<span style="font-size:13px;color:var(--gris3)">Sin diferencias</span>
						</div>
					{/if}
					<div style="display:flex;flex-direction:column">
						<div class="rep-row bold" style="padding-top:10px">
							<span class="rep-lbl">{fondoLabel}</span>
							<span class="rep-val {fondoVal > 0.009 ? 'cmp-ok' : ''}">{fmt(fondoVal)}</span>
						</div>
						{#if retiro > 0.009}
							<div class="rep-row"><span class="rep-lbl" style="color:var(--gris3)">Sale del cajón</span><span class="rep-val" style="color:var(--gris3)">{fmt(retiro)}</span></div>
						{/if}
					</div>
					{#if datosIngresados.observaciones}
						<div>
							<div class="ms-titulo">Observaciones</div>
							<p style="font-size:13px;color:var(--gris3);line-height:1.6;white-space:pre-wrap">{datosIngresados.observaciones}</p>
						</div>
					{/if}
				{/if}
			</div>
			<div class="modal-footer">
				<button class="btn btn-sec" onclick={cerrarModal}>Cancelar</button>
				{#if pasoActual > 1}
					<button class="btn btn-sec" onclick={retrocederPaso}>← Atrás</button>
				{/if}
				{#if pasoActual < 3}
					<button class="btn btn-ok" onclick={avanzarPaso}>Siguiente →</button>
				{:else}
					<button class="btn btn-ok" disabled={confirmandoCierre} onclick={confirmarCierre}>{confirmandoCierre ? 'Cerrando…' : 'Confirmar cierre'}</button>
				{/if}
			</div>
		</div>
	</div>
{/if}

<style>
	.page-header {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd), 0 -1px 4px var(--neo-sl);
		padding: 14px clamp(20px, 4vw, 48px);
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		flex-shrink: 0;
		position: relative;
		z-index: 10;
	}
	.page-header h1 {
		font-size: 13px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: var(--neo-text);
	}
	.page-header p {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 2px;
	}
	.page-header-meta {
		display: flex;
		align-items: center;
		gap: 10px;
	}
	.contenido {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: 20px clamp(16px, 3vw, 40px);
		background: var(--neo-bg-deep);
	}
	.contenido-inner {
		max-width: 1560px;
		margin: 0 auto;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.contenido-inner.solo {
		max-width: 980px;
	}
	.layout-turno {
		display: grid;
		grid-template-columns: minmax(400px, 470px) minmax(0, 1fr);
		gap: 14px;
		align-items: start;
	}
	.layout-col {
		display: flex;
		flex-direction: column;
		gap: 14px;
		min-width: 0;
	}
	.layout-full {
		grid-column: 1 / -1;
	}
	@media (max-width: 1080px) {
		.layout-turno {
			grid-template-columns: 1fr;
		}
	}
	.card {
		background: var(--neo-bg);
		box-shadow: var(--neo-e2);
		border-radius: var(--neo-r-lg);
	}
	.card-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
		padding: 11px 18px;
		border-bottom: 1px solid var(--borde);
		border-radius: var(--neo-r-lg) var(--neo-r-lg) 0 0;
	}
	.card-header h2 {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: var(--neo-text-3);
	}
	.card-body {
		padding: 18px;
	}
	.estado-pill {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		padding: 3px 10px;
		border-radius: var(--neo-r-pill);
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		box-shadow: var(--neo-e1);
	}
	.estado-pill.abierto {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success);
	}
	.estado-pill.cerrado {
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.estado-pill .dot {
		width: 6px;
		height: 6px;
		border-radius: 50%;
		background: currentColor;
	}
	.ledger {
		display: table;
		width: 100%;
		border-collapse: collapse;
	}
	.ledger-row {
		display: table-row;
	}
	.ledger-row.sep > * {
		border-top: 1px solid var(--borde-fuerte);
		padding-top: 9px;
		margin-top: 5px;
	}
	.ledger-lbl {
		display: table-cell;
		padding: 5px 0;
		font-size: 12px;
		color: var(--neo-text-2);
		white-space: nowrap;
	}
	.ledger-fill {
		display: table-cell;
		width: 100%;
		padding: 5px 10px;
		vertical-align: bottom;
	}
	.ledger-fill::after {
		content: '';
		display: block;
		border-bottom: 1px dotted var(--borde-fuerte);
		margin-bottom: 3px;
	}
	.ledger-val {
		display: table-cell;
		padding: 5px 0 5px 16px;
		text-align: right;
		font-size: 13px;
		font-weight: 600;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
		color: var(--neo-text);
	}
	.ledger-row.bold .ledger-lbl {
		font-weight: 700;
		color: var(--neo-text);
		font-size: 13px;
	}
	.ledger-row.bold .ledger-val {
		font-size: 14px;
		font-weight: 700;
	}
	.ledger-row.accent .ledger-val {
		color: var(--neo-accent);
	}
	.form-group {
		display: flex;
		flex-direction: column;
		gap: 5px;
		max-width: 280px;
	}
	.form-label {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.form-input {
		padding: 9px 12px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 14px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		box-shadow: var(--neo-i1);
		color: var(--neo-text);
		font-variant-numeric: tabular-nums;
	}
	.form-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		line-height: 1.4;
	}
	.btn {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 9px 18px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		cursor: pointer;
		font-family: inherit;
	}
	.btn:disabled {
		opacity: 0.45;
		cursor: default;
	}
	.btn-ok {
		background: var(--neo-accent);
		color: #fff;
		box-shadow: 4px 4px 10px var(--neo-accent-glow), -2px -2px 5px rgba(255, 255, 255, 0.15);
	}
	.btn-ok:hover:not(:disabled) {
		background: var(--neo-accent-h);
	}
	.btn-sec {
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-e2);
	}
	.btn-danger {
		background: var(--neo-danger);
		color: #fff;
		box-shadow: 4px 4px 10px var(--neo-danger-glow), -2px -2px 5px rgba(255, 255, 255, 0.15);
	}
	.acciones {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.accion-btn {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 16px;
		width: 100%;
		text-align: left;
		background: var(--neo-bg);
		border: none;
		border-radius: var(--neo-r-md);
		cursor: pointer;
		font-family: inherit;
		box-shadow: var(--neo-e1);
	}
	.accion-btn:hover {
		box-shadow: var(--neo-e2);
	}
	.accion-btn.danger:hover {
		color: var(--neo-danger);
	}
	.accion-btn .kbd {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 26px;
		height: 26px;
		border-radius: var(--neo-r-xs);
		box-shadow: var(--neo-i1);
		background: var(--neo-bg);
		font-size: 12px;
		font-weight: 800;
		color: var(--neo-text);
		flex-shrink: 0;
	}
	.accion-btn.danger .kbd {
		color: var(--neo-danger);
	}
	.accion-lbl strong {
		font-size: 12px;
		font-weight: 700;
		display: block;
		color: var(--neo-text);
	}
	.accion-lbl span {
		font-size: 10px;
		color: var(--neo-text-3);
		display: block;
		margin-top: 1px;
	}
	.stats-strip {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		background: var(--neo-bg);
		box-shadow: var(--neo-e2);
		border-radius: var(--neo-r-lg);
	}
	.stat {
		padding: 14px 16px;
		min-width: 0;
	}
	.stat + .stat {
		border-left: 1px solid var(--borde);
	}
	.stat-lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		white-space: nowrap;
	}
	.stat-val {
		font-size: 17px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
		margin-top: 3px;
		white-space: nowrap;
		color: var(--neo-text);
	}
	.stat-sub {
		font-size: 10px;
		color: var(--neo-text-3);
		margin-top: 1px;
		white-space: nowrap;
	}
	@media (max-width: 1400px) {
		.stats-strip {
			grid-template-columns: repeat(2, 1fr);
		}
		.stat:nth-child(3) {
			border-left: none;
		}
		.stat:nth-child(n + 3) {
			border-top: 1px solid var(--borde);
		}
	}
	.mix-grid {
		display: grid;
		grid-template-columns: auto minmax(0, 1fr);
		gap: 10px 28px;
		align-items: center;
	}
	@media (max-width: 1300px) {
		.mix-grid {
			grid-template-columns: 1fr;
			justify-items: center;
		}
	}
	.mix-leyenda {
		width: 100%;
	}
	.swatch {
		display: inline-block;
		width: 10px;
		height: 10px;
		border-radius: 2px;
		margin-right: 7px;
		vertical-align: baseline;
		box-shadow: var(--neo-e1);
	}
	.leyenda-pct {
		display: table-cell;
		padding: 5px 0 5px 14px;
		text-align: right;
		font-size: 11px;
		color: var(--neo-text-3);
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}
	.horas-chart {
		display: flex;
		align-items: flex-end;
		gap: 5px;
		height: 150px;
		padding-top: 14px;
	}
	.hora-col {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
		justify-content: flex-end;
		align-items: center;
		gap: 0;
		height: 100%;
	}
	.hora-val {
		font-size: 9px;
		color: var(--neo-text-3);
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
		margin-bottom: 3px;
	}
	.hora-bar {
		width: 100%;
		max-width: 44px;
		background: var(--neo-accent);
		border-radius: var(--neo-r-xs) var(--neo-r-xs) 0 0;
	}
	.hora-bar.vacia {
		background: var(--color-bg-alt);
		height: 2px !important;
	}
	.hora-lbl {
		font-size: 10px;
		color: var(--neo-text-3);
		margin-top: 5px;
		font-variant-numeric: tabular-nums;
	}
	.chart-vacio {
		padding: 36px 20px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 12px;
	}
	.hist-tabla {
		width: 100%;
		border-collapse: collapse;
		font-size: 12px;
	}
	.hist-tabla th {
		text-align: left;
		padding: 7px 12px;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
	}
	.hist-tabla th.r,
	.hist-tabla td.r {
		text-align: right;
	}
	.hist-tabla td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--borde);
		font-variant-numeric: tabular-nums;
		color: var(--neo-text);
	}
	.hist-tabla tbody tr:last-child td {
		border-bottom: none;
	}
	.hist-tabla tbody tr:hover {
		background: var(--color-bg-alt);
	}
	.badge-parcial {
		display: inline-block;
		padding: 1px 7px;
		border-radius: var(--neo-r-pill);
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.3px;
		background: rgba(243, 156, 18, 0.12);
		color: var(--neo-warning);
		box-shadow: var(--neo-e1);
	}
	.no-aplica {
		text-align: center;
		padding: 40px 20px;
		color: var(--neo-text-3);
		font-size: 13px;
	}
	.modal-overlay {
		position: fixed;
		inset: 0;
		background: rgba(49, 52, 75, 0.48);
		z-index: 200;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 16px;
	}
	.modal-overlay.visible {
		backdrop-filter: blur(4px);
	}
	.modal-cierre {
		background: #fff;
		border: 1px solid var(--borde-fuerte);
		border-radius: 0;
		box-shadow: 0 4px 28px rgba(0, 0, 0, 0.14);
		width: min(96vw, 700px);
		max-height: 92vh;
		display: flex;
		flex-direction: column;
	}
	.modal-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 14px 22px;
		border-bottom: 1px solid var(--borde-fuerte);
		background: var(--color-bg-alt);
		flex-shrink: 0;
	}
	.modal-header-txt h2 {
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: var(--neo-text-3);
	}
	.modal-header-txt p {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 2px;
	}
	.modal-close {
		background: none;
		border: none;
		cursor: pointer;
		color: var(--neo-text-3);
		font-size: 18px;
		line-height: 1;
		padding: 2px 6px;
		border-radius: var(--neo-r-xs);
	}
	.modal-close:hover {
		background: var(--color-bg-alt);
		color: var(--neo-text);
	}
	.modal-body {
		flex: 1;
		overflow-y: auto;
		min-height: 0;
		padding: 22px;
		display: flex;
		flex-direction: column;
		gap: 20px;
	}
	.modal-footer {
		padding: 12px 22px;
		border-top: 1px solid var(--borde-fuerte);
		display: flex;
		gap: 10px;
		justify-content: flex-end;
		align-items: center;
		flex-shrink: 0;
	}
	.stepper {
		display: flex;
		align-items: center;
		padding: 12px 22px;
		border-bottom: 1px solid var(--borde-fuerte);
		background: var(--color-bg-alt);
		flex-shrink: 0;
	}
	.step {
		display: flex;
		align-items: center;
		gap: 8px;
	}
	.step-circle {
		width: 26px;
		height: 26px;
		border-radius: 50%;
		border: 2px solid var(--borde-fuerte);
		background: #fff;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 11px;
		font-weight: 700;
		color: var(--neo-text-3);
		flex-shrink: 0;
	}
	.step.active .step-circle {
		border-color: var(--neo-accent);
		background: var(--neo-accent);
		color: #fff;
	}
	.step.done .step-circle {
		border-color: var(--neo-success);
		background: var(--neo-success);
		color: #fff;
	}
	.step-lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		white-space: nowrap;
	}
	.step.active .step-lbl {
		color: var(--neo-text);
	}
	.step.done .step-lbl {
		color: var(--neo-success);
	}
	.step-line {
		flex: 1;
		height: 1px;
		background: var(--color-bg-alt);
		margin: 0 10px;
	}
	.ms-titulo {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: var(--neo-text-3);
		padding-bottom: 6px;
		border-bottom: 1px solid var(--borde-fuerte);
		margin-bottom: 12px;
	}
	.arqueo-table {
		width: 100%;
		border-collapse: collapse;
		border: 1px solid var(--borde-fuerte);
	}
	.arqueo-table thead th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		padding: 10px 16px;
		border-bottom: 1px solid var(--borde-fuerte);
		background: var(--color-bg-alt);
		white-space: nowrap;
	}
	.arqueo-table thead th.r {
		text-align: right;
	}
	.arqueo-table tbody tr {
		border-bottom: 1px solid #e8e4e0;
	}
	.arqueo-table tbody tr:last-child {
		border-bottom: none;
	}
	.arqueo-table tbody tr:not(.arqueo-group):hover {
		background: var(--color-bg-alt);
	}
	.arqueo-table td {
		padding: 12px 16px;
		vertical-align: middle;
	}
	.arqueo-table td.r {
		text-align: right;
	}
	.arqueo-group {
		background: #f8f7f5 !important;
	}
	.arqueo-group td {
		padding: 7px 16px !important;
	}
	.am-lbl {
		font-size: 13px;
		font-weight: 600;
		color: var(--neo-text);
	}
	.am-sub {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.am-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 2px;
		line-height: 1.4;
	}
	.am-sis {
		font-size: 13px;
		font-variant-numeric: tabular-nums;
		color: var(--neo-text-2);
		white-space: nowrap;
		min-width: 110px;
	}
	.arqueo-input {
		width: 160px;
		padding: 9px 12px;
		border: 1px solid var(--borde-fuerte);
		border-radius: 0;
		font-size: 15px;
		font-weight: 700;
		font-family: inherit;
		outline: none;
		text-align: right;
		font-variant-numeric: tabular-nums;
		background: #fff;
		color: var(--neo-text);
		display: block;
		box-sizing: border-box;
	}
	.arqueo-input:focus {
		border-color: var(--color-primary);
	}
	.cmp-table {
		width: 100%;
		border-collapse: collapse;
		font-size: 13px;
	}
	.cmp-table th {
		text-align: left;
		padding: 8px 10px;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	.cmp-table th.r {
		text-align: right;
	}
	.cmp-table td {
		padding: 9px 10px;
		border-bottom: 1px solid var(--borde);
		font-variant-numeric: tabular-nums;
		color: var(--neo-text);
	}
	.cmp-table td.r {
		text-align: right;
		font-weight: 600;
	}
	.cmp-table tbody tr:last-child td {
		border-bottom: none;
	}
	.cmp-ok {
		color: var(--neo-success);
	}
	.cmp-falta {
		color: var(--neo-danger);
	}
	.dif-total {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 13px 16px;
		border-radius: 0;
	}
	.dif-total .dt-lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}
	.dif-total .dt-val {
		font-size: 18px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
	}
	.dif-total.ok {
		background: rgba(39, 174, 96, 0.1);
	}
	.dif-total.ok .dt-lbl,
	.dif-total.ok .dt-val {
		color: var(--neo-success);
	}
	.dif-total.falta {
		background: rgba(231, 76, 60, 0.1);
	}
	.dif-total.falta .dt-lbl,
	.dif-total.falta .dt-val {
		color: var(--neo-danger);
	}
	.rep-row {
		display: flex;
		justify-content: space-between;
		align-items: baseline;
		padding: 6px 0;
		border-bottom: 1px solid var(--borde);
	}
	.rep-row:last-child {
		border-bottom: none;
	}
	.rep-lbl {
		font-size: 12px;
		color: var(--neo-text-2);
	}
	.rep-val {
		font-size: 13px;
		font-weight: 600;
		font-variant-numeric: tabular-nums;
		color: var(--neo-text);
	}
	.rep-row.bold .rep-lbl {
		font-weight: 700;
		color: var(--neo-text);
	}
	.rep-row.bold .rep-val {
		font-size: 14px;
		font-weight: 700;
	}
</style>
