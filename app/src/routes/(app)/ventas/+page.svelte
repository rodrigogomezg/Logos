<script lang="ts">
	import { onMount } from 'svelte';
	import { SvelteMap } from 'svelte/reactivity';
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { abrirPdf } from '$lib/pdf';
	import { abrirContacto } from '$lib/contact-modal';
	import { puede } from '$lib/session';
	import { cajaOperativaId } from '$lib/operativa';
	import { setTourSteps, type TourStep } from '$lib/tour';

	type Pago = { tipo: string; monto: number };
	type Venta = {
		id: number;
		numero: string;
		fecha: string;
		tipo_comprobante: string | null;
		tipo_pago: string | null;
		cliente_id: number | null;
		cliente_nombre: string | null;
		observaciones: string | null;
		total: number;
		estado: string | null;
		cae: string | null;
		afip_error: string | null;
		origen_descripcion?: string | null;
	};
	type VentaItem = {
		id: number;
		producto_id: number;
		codigo: string;
		nombre: string;
		nombre_manual?: string | null;
		cantidad: number;
		precio_unitario: number;
		precio_original?: number | null;
		ajuste_desc?: string | null;
		ajuste_visible?: boolean;
		subtotal: number;
	};
	type VentaDetalle = Venta & {
		items: VentaItem[];
		pagos?: Pago[];
		cliente_cuit?: string | null;
		cliente_telefono?: string | null;
		envio_precio?: number | null;
		envio_direccion?: string | null;
		envios_detalle?: { fecha_corta: string; precio: number }[];
		numero_afip?: number | null;
	};
	type Cliente = { id: number; nombre: string; cuit?: string | null };

	const esAdmin = $derived(puede('anular'));

	// ── Utilidades ───────────────────────────────────────────────
	function fmt(n: number | string | null | undefined): string {
		return '$ ' + Number(n ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function fmtDate(d: Date): string {
		return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
	}
	function fmtFechaCorta(s: string | null | undefined): string {
		return s ? s.slice(8, 10) + '/' + s.slice(5, 7) : '—';
	}
	const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
	const TIPO_PAGO_LBL: Record<string, string> = { efectivo: 'Efectivo', transferencia: 'Transferencia', cc: 'Cta. Corriente', tarjeta: 'Tarjeta', cheque: 'Cheque', mixto: 'Mixto', mercado_pago: 'Mercado Pago' };
	function lblTipoPago(v: string | null): string {
		return v ? (TIPO_PAGO_LBL[v] ?? v) : '—';
	}
	const TIPOS_ELECT = ['FC A-ELECT', 'FC B-ELECT', 'FC C-ELECT', 'NC A-ELECT', 'NC B-ELECT', 'NC C-ELECT'];
	function tipoBadgeClass(tipo: string | null): string {
		if (!tipo) return 'tipo-PRES';
		if (tipo.includes('REMITO')) return 'tipo-REMITO';
		if (tipo.includes('NC') || tipo.includes('NOTA')) return 'tipo-NC';
		if (tipo.startsWith('FC')) return 'tipo-FC';
		return 'tipo-PRES';
	}
	function compRowClass(tipo: string | null): string {
		if (!tipo) return 'comp-presupuesto';
		if (tipo.includes('REMITO')) return 'comp-remito';
		if (tipo.includes('NC') || tipo.includes('NOTA')) return 'comp-nc';
		if (tipo.startsWith('FC')) return 'comp-factura';
		return 'comp-presupuesto';
	}
	function fmtCant(n: number): string {
		return Number.isInteger(n) ? String(n) : n.toFixed(2).replace('.', ',');
	}

	// ── Filtros ──────────────────────────────────────────────────
	let fQ = $state('');
	let fSucursal = $state('');
	let sucursales = $state<{ id: number; nombre: string }[]>([]);
	let pcSelect = $state('hoy');
	let fDesde = $state('');
	let fHasta = $state('');
	let fTipo = $state('');
	// Mismos 5 tipos que Configuración > Datos contables deja tildar/destildar
	// (tipos_habilitados) — "Tipo" en este filtro solo debe ofrecer los que
	// estén habilitados ahí. Arranca con los 5 (mismo criterio que el backend:
	// "si ninguno está seleccionado, todos quedan habilitados") hasta que
	// cargarIntegConfig() traiga la lista real.
	const TIPOS_COMPROBANTE_FILTRO: { value: string; label: string }[] = [
		{ value: 'REMITO', label: 'Remito' },
		{ value: 'FC B-ELECT', label: 'Fc B' },
		{ value: 'FC A-ELECT', label: 'Fc A' },
		{ value: 'FC C-ELECT', label: 'Fc C' },
		{ value: 'PRESUPUESTO', label: 'Presup.' }
	];
	let tiposHabilitadosFiltro = $state<string[]>(TIPOS_COMPROBANTE_FILTRO.map((t) => t.value));
	let fVendedor = $state('');
	let vendedores = $state<{ id: number; nombre: string }[]>([]);
	let fMontoMin = $state('');
	let fMontoMax = $state('');
	let fMostrarAnuladas = $state(false);
	let fSinCae = $state(false);
	let fGrupo = $state<'' | 'cliente' | 'tipo'>('cliente');
	let mesesVisible = $state(false);
	let mesesAnio = $state(0);
	let mesActivo = $state<number | null>(null);
	let morePanelVisible = $state(false);
	// Último período realmente aplicado (a diferencia de pcSelect, que ya
	// cambia a 'manual' apenas se elige la opción del combo, antes de
	// confirmar nada en el modal) — permite revertir el combo si cancelan
	// el modal sin aplicar.
	let pcSelectConfirmado = $state('hoy');
	let rangoModalAbierto = $state(false);
	let rmDesde = $state('');
	let rmHasta = $state('');

	function mesLimite(anio: number): number {
		const hoy = new Date();
		return anio === hoy.getFullYear() ? hoy.getMonth() : 11;
	}

	function setPeriodo(tipo: 'hoy' | 'semana' | 'mes' | 'anio' | { mes: number }) {
		const ahora = new Date();
		const anio = ahora.getFullYear();
		let desde: string, hasta: string;

		if (tipo === 'hoy') {
			pcSelect = 'hoy';
			desde = hasta = fmtDate(ahora);
			mesesVisible = false;
		} else if (tipo === 'semana') {
			pcSelect = 'semana';
			const r = new Date(ahora);
			r.setHours(0, 0, 0, 0);
			const dia = r.getDay();
			r.setDate(r.getDate() - (dia === 0 ? 6 : dia - 1));
			desde = fmtDate(r);
			hasta = fmtDate(ahora);
			mesesVisible = false;
		} else if (tipo === 'mes') {
			pcSelect = 'mes';
			desde = fmtDate(new Date(anio, ahora.getMonth(), 1));
			hasta = fmtDate(ahora);
			mesesVisible = false;
		} else if (tipo === 'anio') {
			pcSelect = 'anio';
			desde = fmtDate(new Date(anio, 0, 1));
			hasta = fmtDate(ahora);
			mesesAnio = anio;
			mesActivo = null;
			mesesVisible = true;
		} else {
			pcSelect = 'anio';
			const m = tipo.mes;
			const ultimoDia = new Date(anio, m + 1, 0);
			desde = fmtDate(new Date(anio, m, 1));
			hasta = fmtDate(ultimoDia > ahora ? ahora : ultimoDia);
			mesesAnio = anio;
			mesActivo = m;
			mesesVisible = true;
		}
		fDesde = desde;
		fHasta = hasta;
		pcSelectConfirmado = pcSelect;
		buscar();
	}

	function abrirRangoManual() {
		rmDesde = fDesde || fmtDate(new Date());
		rmHasta = fHasta || fmtDate(new Date());
		rangoModalAbierto = true;
	}
	function aplicarRangoManual() {
		if (!rmDesde || !rmHasta) {
			toast_('Elegí las dos fechas', 'err');
			return;
		}
		if (rmDesde > rmHasta) {
			toast_('La fecha "desde" no puede ser posterior a "hasta"', 'err');
			return;
		}
		fDesde = rmDesde;
		fHasta = rmHasta;
		pcSelect = 'manual';
		pcSelectConfirmado = 'manual';
		mesesVisible = false;
		rangoModalAbierto = false;
		buscar();
	}
	function cancelarRangoManual() {
		rangoModalAbierto = false;
		pcSelect = pcSelectConfirmado;
	}

	function onFechaManualChange() {
		pcSelect = '';
		mesesVisible = false;
		buscar();
	}

	// ── Filtro cliente ───────────────────────────────────────────
	let fCliInput = $state('');
	let clienteFiltroId = $state<number | null>(null);
	let clienteFiltroNombre = $state('');
	let cliDdVisible = $state(false);
	let cliDdResultados = $state<Cliente[]>([]);
	let cliDdIdx = $state(-1);
	let cliFiltroTimer: ReturnType<typeof setTimeout>;

	function onFCliInput() {
		clearTimeout(cliFiltroTimer);
		const q = fCliInput.trim();
		if (q.length < 2) {
			cliDdVisible = false;
			return;
		}
		cliFiltroTimer = setTimeout(() => buscarClientesFiltro(q), 250);
	}
	async function buscarClientesFiltro(q: string) {
		try {
			const r = await api(`/clientes?q=${encodeURIComponent(q)}&limit=8`);
			const d = await r.json();
			if (!Array.isArray(d) || !d.length) {
				cliDdVisible = false;
				return;
			}
			cliDdIdx = -1;
			cliDdResultados = d;
			cliDdVisible = true;
		} catch {
			/* silencioso */
		}
	}
	function onFCliKeydown(e: KeyboardEvent) {
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			cliDdIdx = Math.min(cliDdIdx + 1, cliDdResultados.length - 1);
		}
		if (e.key === 'ArrowUp') {
			e.preventDefault();
			cliDdIdx = Math.max(cliDdIdx - 1, 0);
		}
		if (e.key === 'Enter') {
			e.preventDefault();
			const c = cliDdResultados[cliDdIdx] ?? cliDdResultados[0];
			if (c) seleccionarClienteFiltro(c);
		}
		if (e.key === 'Escape') cliDdVisible = false;
	}
	function seleccionarClienteFiltro(c: Cliente) {
		clienteFiltroId = c.id;
		clienteFiltroNombre = c.nombre;
		cliDdVisible = false;
		fCliInput = '';
	}
	function limpiarClienteFiltro() {
		clienteFiltroId = null;
		fCliInput = '';
		buscar();
	}

	// ── Buscar ───────────────────────────────────────────────────
	let resultadosActuales = $state<Venta[]>([]);
	let buscando = $state(false);
	let countLabel = $state('');
	let tablaError = $state('');

	async function buscar() {
		deseleccionar();
		buscando = true;
		const params = new URLSearchParams({ limit: '500' });
		if (fDesde) params.set('fecha_desde', fDesde);
		if (fHasta) params.set('fecha_hasta', fHasta);
		if (fTipo) params.set('tipo', fTipo);
		if (fQ.trim()) params.set('q', fQ.trim());
		if (clienteFiltroId) params.set('cliente_id', String(clienteFiltroId));
		if (fMontoMin.trim() !== '') params.set('monto_min', fMontoMin.trim());
		if (fMontoMax.trim() !== '') params.set('monto_max', fMontoMax.trim());
		if (fMostrarAnuladas) params.set('mostrar_anuladas', '1');
		if (fSinCae) params.set('sin_cae', '1');
		if (fSucursal) params.set('sucursal_id', fSucursal);
		if (fVendedor) params.set('vendedor_id', fVendedor);

		tablaError = '';
		try {
			const r = await api(`/ventas?${params}`);
			const d = await r.json();
			if (!Array.isArray(d)) throw new Error(d.error ?? 'Error');
			resultadosActuales = d;
			countLabel = d.length ? `${d.length} resultado${d.length === 1 ? '' : 's'}` : '';
			if (d.length === 500) toast_('Se muestran los primeros 500 resultados — acotá el período o usá filtros para ver el resto', 'ok');
		} catch (e) {
			tablaError = e instanceof Error ? e.message : 'Error';
			resultadosActuales = [];
			toast_(tablaError, 'err');
		} finally {
			buscando = false;
		}
	}

	// ── Agrupado / render ────────────────────────────────────────
	type Grupo = { key: string; filas: Venta[]; total: number };
	const grupos = $derived.by((): Grupo[] | null => {
		if (!fGrupo) return null;
		const clave = fGrupo === 'cliente' ? (v: Venta) => v.cliente_nombre ?? '— Consumidor final —' : (v: Venta) => v.tipo_comprobante ?? '—';
		const mapa = new Map<string, Venta[]>();
		for (const v of resultadosActuales) {
			const k = clave(v);
			if (!mapa.has(k)) mapa.set(k, []);
			mapa.get(k)!.push(v);
		}
		return [...mapa.entries()]
			.map(([key, filas]) => ({ key, filas, total: filas.reduce((s, v) => s + Number(v.total), 0) }))
			.sort((a, b) => b.total - a.total);
	});

	// ── Selección ────────────────────────────────────────────────
	let ventaSeleccionada = $state<Venta | null>(null);
	let ventaDetalleCache = $state<VentaDetalle | null>(null);
	const multiSel = new SvelteMap<number, Venta>();
	let modoDeleteBulk = $state(false);

	function puedeUnificar(v: Venta): boolean {
		const t = v.tipo_comprobante ?? '';
		if (t === 'PRESUPUESTO') return true;
		if (t === 'REMITO') return v.tipo_pago === 'cc';
		return false;
	}

	function seleccionar(v: Venta) {
		if (multiSel.size > 0) return;
		if (ventaSeleccionada?.id === v.id) {
			deseleccionar();
			return;
		}
		ventaSeleccionada = v;
		ventaDetalleCache = null;
	}
	function deseleccionar() {
		ventaSeleccionada = null;
		ventaDetalleCache = null;
		compDdVisible = false;
	}
	const necesitaReintento = $derived(!!ventaSeleccionada && TIPOS_ELECT.includes(ventaSeleccionada.tipo_comprobante ?? '') && !ventaSeleccionada.cae && ventaSeleccionada.estado !== 'anulado');
	const esPresupuestoActivo = $derived(!!ventaSeleccionada && ventaSeleccionada.tipo_comprobante === 'PRESUPUESTO' && ventaSeleccionada.estado !== 'anulado');
	// Solo se puede acreditar (NC) una factura electrónica ya autorizada por ARCA.
	const puedeEmitirNc = $derived(!!ventaSeleccionada && !!ventaSeleccionada.cae && (ventaSeleccionada.tipo_comprobante ?? '').startsWith('FC') && ventaSeleccionada.estado !== 'anulado');

	function toggleMultiChk(v: Venta, checked: boolean) {
		if (checked) multiSel.set(v.id, v);
		else multiSel.delete(v.id);
		if (multiSel.size === 0) deseleccionar();
	}
	function limpiarMultiSel() {
		multiSel.clear();
	}
	const multiWarn = $derived.by(() => {
		if (multiSel.size < 2) return 'Seleccioná al menos uno más para unificar';
		const clientes = new Set([...multiSel.values()].map((v) => v.cliente_id ?? '__cf__'));
		if (clientes.size > 1) return 'Pertenecen a distintos clientes';
		return '';
	});
	const puedeUni = $derived(multiSel.size >= 2 && !multiWarn);

	async function cargarDetalle(id: number): Promise<VentaDetalle> {
		if (ventaDetalleCache && ventaDetalleCache.id === id) return ventaDetalleCache;
		const r = await api(`/ventas/${id}`);
		const d = await r.json();
		if (!r.ok) throw new Error(d.error ?? 'Error al cargar venta');
		ventaDetalleCache = d;
		return d;
	}

	// ── Dropdown Comprobante ─────────────────────────────────────
	let compDdVisible = $state(false);

	// ── Modal: Ver ───────────────────────────────────────────────
	let verAbierto = $state(false);
	let verCargando = $state(true);
	let verError = $state('');
	let verVenta = $state<VentaDetalle | null>(null);
	let reintentandoAfip = $state(false);

	async function accionVer() {
		if (!ventaSeleccionada) return;
		verCargando = true;
		verError = '';
		verVenta = null;
		verAbierto = true;
		try {
			verVenta = await cargarDetalle(ventaSeleccionada.id);
		} catch (e) {
			verError = e instanceof Error ? e.message : 'Error';
			toast_(verError, 'err');
		} finally {
			verCargando = false;
		}
	}
	function cerrarVer() {
		verAbierto = false;
	}
	async function reintentarAfipDesdeVer() {
		if (!verVenta) return;
		reintentandoAfip = true;
		try {
			const r = await api(`/ventas/${verVenta.id}/facturar`, { method: 'POST' });
			const d = await r.json();
			if (!r.ok) {
				toast_(d.error || 'AFIP rechazó la factura', 'err');
				return;
			}
			toast_(`Factura autorizada · CAE ${d.cae}`, 'ok');
			verVenta = d;
			ventaDetalleCache = d;
			buscar();
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			reintentandoAfip = false;
		}
	}

	// ── Acciones: imprimir / PDF / mail ───────────────────────────
	async function accionImprimir() {
		compDdVisible = false;
		if (!ventaSeleccionada) return;
		try {
			const r = await api(`/ventas/${ventaSeleccionada.id}/imprimir`, { method: 'POST' });
			const d = await r.json();
			if (!r.ok) {
				toast_(d.error || 'Error al imprimir', 'err');
				return;
			}
			toast_('Enviado a imprimir', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		}
	}
	async function accionPDF() {
		compDdVisible = false;
		if (!ventaSeleccionada) return;
		try {
			const r = await api(`/ventas/${ventaSeleccionada.id}/comprobante`);
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

	let mailAbierto = $state(false);
	let mailDesc = $state('');
	let mailEmail = $state('');
	let mailAsunto = $state('');
	let mailEnviando = $state(false);

	async function accionMail() {
		compDdVisible = false;
		if (!ventaSeleccionada) return;
		const v = ventaSeleccionada;
		mailDesc = `${v.tipo_comprobante ?? 'Comprobante'} #${v.numero}  ·  ${fmt(v.total)}  ·  ${v.cliente_nombre ?? 'Consumidor final'}`;
		mailAsunto = `${v.tipo_comprobante ?? 'Comprobante'} N° ${v.numero} — Logos`;
		mailEmail = '';
		if (v.cliente_id) {
			try {
				const r = await api(`/clientes/${v.cliente_id}`);
				if (r.ok) {
					const cli = await r.json();
					if (cli?.email) mailEmail = cli.email;
				}
			} catch {
				/* best effort */
			}
		}
		mailAbierto = true;
	}
	function cerrarMail() {
		mailAbierto = false;
	}
	async function enviarMail() {
		if (!mailEmail.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mailEmail.trim())) {
			toast_('Ingresá un email válido', 'err');
			return;
		}
		mailEnviando = true;
		try {
			const r = await api('/mail/enviar', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ venta_id: ventaSeleccionada!.id, para: mailEmail.trim(), asunto: mailAsunto.trim() })
			});
			if (r.ok) {
				mailAbierto = false;
				toast_('Mail enviado correctamente', 'ok');
			} else {
				const d = await r.json().catch(() => ({}));
				toast_(d.error || 'Error al enviar', 'err');
			}
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			mailEnviando = false;
		}
	}

	// ── Acción: reintentar AFIP (barra de selección) ──────────────
	let reintentandoAfipBar = $state(false);
	async function accionReintentarAfipBar() {
		if (!ventaSeleccionada) return;
		reintentandoAfipBar = true;
		try {
			const r = await api(`/ventas/${ventaSeleccionada.id}/facturar`, { method: 'POST' });
			const d = await r.json();
			if (!r.ok) {
				toast_(d.error ?? 'Error al facturar', 'err');
				return;
			}
			if (d.cae) {
				toast_(`CAE obtenido: ${d.cae}`, 'ok');
				const idx = resultadosActuales.findIndex((v) => v.id === d.id);
				if (idx >= 0) resultadosActuales[idx] = { ...resultadosActuales[idx], cae: d.cae, afip_error: null };
				ventaSeleccionada = { ...ventaSeleccionada, cae: d.cae, afip_error: null };
			} else {
				toast_(d.afip_error ?? 'ARCA no autorizó el comprobante', 'err');
			}
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			reintentandoAfipBar = false;
		}
	}

	// ── Devolución con crédito ─────────────────────────────────────
	let devAbierto = $state(false);
	let devVentaActual = $state<VentaDetalle | null>(null);
	let devItems = $state<{ producto_id: number; nombre: string; vendido: number; devuelto: number; disponible: number; precio: number; cant: number }[]>([]);
	let devMotivo = $state('');
	let devConfirmando = $state(false);

	async function accionDevolver() {
		if (!ventaSeleccionada) return;
		try {
			const v = await cargarDetalle(ventaSeleccionada.id);
			if (v.estado === 'anulada') {
				toast_('La venta está anulada', 'err');
				return;
			}
			if (!v.cliente_id) {
				toast_('Las devoluciones con crédito requieren un cliente asignado', 'err');
				return;
			}
			await abrirModalDevolver(v);
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		}
	}
	async function abrirModalDevolver(v: VentaDetalle) {
		devVentaActual = v;
		let yaDevuelto: Record<number, number> = {};
		try {
			const r = await api(`/devoluciones?venta_id=${v.id}`);
			if (r.ok) {
				const devs = await r.json();
				for (const d of devs) {
					for (const it of d.items) {
						yaDevuelto[it.producto_id] = (yaDevuelto[it.producto_id] ?? 0) + it.cantidad;
					}
				}
			}
		} catch {
			/* ignorar */
		}
		devItems = v.items
			.map((it) => {
				const vendido = Number(it.cantidad);
				const devuelto = yaDevuelto[it.producto_id] ?? 0;
				return { producto_id: it.producto_id, nombre: it.nombre, vendido, devuelto, disponible: vendido - devuelto, precio: it.precio_unitario, cant: 0 };
			})
			.filter((it) => it.disponible > 0);
		devMotivo = '';
		devAbierto = true;
	}
	const devTotal = $derived(devItems.reduce((s, it) => s + it.cant * it.precio, 0));
	function cerrarDev() {
		devAbierto = false;
	}
	async function confirmarDevolucion() {
		const items = devItems.filter((it) => it.cant > 0).map((it) => ({ producto_id: it.producto_id, cantidad: it.cant, precio_unitario: it.precio }));
		const invalido = devItems.some((it) => it.cant < 0 || it.cant > it.disponible);
		if (invalido) {
			toast_('Cantidad fuera de rango', 'err');
			return;
		}
		if (!items.length) {
			toast_('Ingresá al menos una cantidad a devolver', 'err');
			return;
		}
		devConfirmando = true;
		try {
			const r = await api('/devoluciones', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ venta_id: devVentaActual!.id, items, motivo: devMotivo.trim() || null })
			});
			const d = await r.json();
			if (!r.ok) throw new Error(d.error || 'Error al registrar devolución');
			devAbierto = false;
			toast_(`Devolución registrada · Crédito ${fmt(d.monto_total)} en CC`, 'ok');
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		} finally {
			devConfirmando = false;
		}
	}

	// ── Nota de crédito (acredita ítems de una FC ya autorizada por ARCA) ──
	let ncAbierto = $state(false);
	let ncVentaActual = $state<VentaDetalle | null>(null);
	let ncItems = $state<{ producto_id: number; nombre: string; vendido: number; disponible: number; precio: number; cant: number }[]>([]);
	let ncMotivo = $state('');
	let ncForma = $state<'caja' | 'cc'>('caja');
	let ncMedioPago = $state('efectivo');
	let ncConfirmando = $state(false);

	async function accionEmitirNc() {
		if (!ventaSeleccionada || !puedeEmitirNc) return;
		try {
			const v = await cargarDetalle(ventaSeleccionada.id);
			await abrirModalNc(v);
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		}
	}
	async function abrirModalNc(v: VentaDetalle) {
		ncVentaActual = v;
		// La cantidad ya acreditada por NCs previas se valida en el backend
		// (emitirNc() rechaza con un error claro si se pide de más); acá se
		// arranca siempre desde la cantidad vendida para no duplicar esa lógica.
		ncItems = v.items.map((it) => {
			const vendido = Number(it.cantidad);
			return { producto_id: it.producto_id, nombre: it.nombre_manual ?? it.nombre, vendido, disponible: vendido, precio: it.precio_unitario, cant: 0 };
		});
		ncMotivo = '';
		ncForma = 'caja';
		ncMedioPago = 'efectivo';
		ncAbierto = true;
	}
	const ncTotal = $derived(ncItems.reduce((s, it) => s + it.cant * it.precio, 0));
	function cerrarNc() {
		ncAbierto = false;
	}
	async function confirmarNc() {
		const items = ncItems.filter((it) => it.cant > 0).map((it) => ({ producto_id: it.producto_id, cantidad: it.cant, precio_unitario: it.precio }));
		const invalido = ncItems.some((it) => it.cant < 0 || it.cant > it.disponible);
		if (invalido) {
			toast_('Cantidad fuera de rango', 'err');
			return;
		}
		if (!items.length) {
			toast_('Ingresá al menos una cantidad a acreditar', 'err');
			return;
		}
		if (ncForma === 'cc' && !ncVentaActual?.cliente_id) {
			toast_('Esta venta no tiene cliente asignado — elegí "Descontar de caja"', 'err');
			return;
		}
		ncConfirmando = true;
		try {
			const body: Record<string, unknown> = {
				items,
				forma: ncForma,
				motivo: ncMotivo.trim() || null
			};
			if (ncForma === 'caja') {
				body.medio_pago = ncMedioPago;
				body.caja_id = cajaOperativaId();
			}
			const r = await api(`/ventas/${ncVentaActual!.id}/nota-credito`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body)
			});
			const d = await r.json();
			// 502 con nc_id: la NC ya se guardó (stock e importe ya se movieron),
			// solo falló el pedido de CAE a ARCA — reintentar creando OTRA NC
			// duplicaría el movimiento. El reintento correcto es sobre esta misma
			// fila, vía el botón "Reintentar AFIP" que ya aparece al seleccionarla.
			if (r.status === 502 && d.nc_id) {
				ncAbierto = false;
				toast_('La NC se guardó pero ARCA todavía no le dio CAE — seleccionala en la lista y usá "Reintentar AFIP"', 'err');
				buscar();
				return;
			}
			if (!r.ok) throw new Error(d.error || 'Error al emitir la Nota de Crédito');
			ncAbierto = false;
			toast_(`Nota de Crédito emitida · ${fmt(ncTotal)} ${ncForma === 'caja' ? 'descontado de caja' : 'acreditado en cuenta corriente'}`, 'ok');
			buscar();
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		} finally {
			ncConfirmando = false;
		}
	}

	// ── Nota de envío ────────────────────────────────────────────
	let neAbierto = $state(false);
	let neVenta = $state<VentaDetalle | null>(null);
	let nePrevias = $state<{ id: number; numero: string; fecha_emision: string | null; fecha_entrega: string | null; transportista: string | null; items: { nombre: string; codigo?: string; cantidad: number }[] }[]>([]);
	let neVentaItems = $state<{ id: number; producto_id: number; codigo: string; nombre: string; cantidad: number; enviado: number; pendiente: number; incluido: boolean; cant: number }[]>([]);
	let neTransportista = $state('');
	let neFechaEntrega = $state('');
	let neEnvioPrecio = $state('');
	let neEnvioDir = $state('');
	let neObservaciones = $state('');
	let neAccion = $state<'' | 'caja' | 'cc'>('');
	let neMedioPago = $state('efectivo');
	let neGenerarAfip = $state(false);
	let neTipoCbte = $state('FC B-ELECT');
	let neGenerando = $state(false);

	async function accionNotaEnvio() {
		if (!ventaSeleccionada) return;
		try {
			const v = await cargarDetalle(ventaSeleccionada.id);
			await abrirModalNotaEnvio(v);
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		}
	}
	async function abrirModalNotaEnvio(v: VentaDetalle) {
		neVenta = v;
		try {
			const r = await api(`/notas-envio?venta_id=${v.id}`);
			nePrevias = r.ok ? await r.json() : [];
		} catch {
			nePrevias = [];
		}
		const enviado: Record<number, number> = {};
		nePrevias.forEach((nota) => nota.items.forEach((it: any) => (enviado[it.venta_item_id] = (enviado[it.venta_item_id] || 0) + it.cantidad)));
		neVentaItems = (v.items || []).map((item) => {
			const env = enviado[item.id] || 0;
			const pend = Math.max(0, item.cantidad - env);
			return { id: item.id, producto_id: item.producto_id, codigo: item.codigo, nombre: item.nombre_manual ?? item.nombre, cantidad: item.cantidad, enviado: env, pendiente: pend, incluido: pend > 0, cant: pend };
		});
		neEnvioPrecio = v.envio_precio != null ? String(v.envio_precio) : '';
		neEnvioDir = v.envio_direccion || '';
		neTransportista = '';
		neFechaEntrega = '';
		neObservaciones = '';
		neAccion = '';
		neMedioPago = 'efectivo';
		neGenerarAfip = false;
		neAbierto = true;
	}
	const neBillingVisible = $derived((parseFloat(neEnvioPrecio) || 0) > 0);
	function cerrarNE() {
		neAbierto = false;
	}
	function onNeChkChange(idx: number, checked: boolean) {
		const it = neVentaItems[idx];
		it.incluido = checked;
		it.cant = checked ? it.pendiente : 0;
	}
	async function verPdfNotaEnvio(id: number) {
		try {
			const r = await api(`/notas-envio/${id}/pdf`);
			if (!r.ok) {
				toast_('No se pudo obtener el PDF', 'err');
				return;
			}
			abrirPdf(await r.blob());
		} catch {
			toast_('Error de conexión', 'err');
		}
	}
	async function generarNotaEnvio() {
		if (!neVenta) return;
		const items: { venta_item_id: number; producto_id: number; nombre: string; codigo: string; cantidad: number }[] = [];
		for (const vi of neVentaItems) {
			if (vi.cant <= 0) continue;
			if (vi.cant > vi.pendiente + 0.0001) {
				toast_(`Cantidad mayor al pendiente para "${vi.nombre}"`, 'err');
				return;
			}
			items.push({ venta_item_id: vi.id, producto_id: vi.producto_id, nombre: vi.nombre, codigo: vi.codigo || '', cantidad: vi.cant });
		}
		if (!items.length) {
			toast_('Seleccioná al menos un artículo con cantidad mayor a 0', 'err');
			return;
		}
		neGenerando = true;
		try {
			const rc = await api('/notas-envio', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					venta_id: neVenta.id,
					transportista: neTransportista.trim() || null,
					fecha_entrega: neFechaEntrega || null,
					envio_precio: parseFloat(neEnvioPrecio) || null,
					envio_direccion: neEnvioDir.trim() || null,
					observaciones: neObservaciones.trim() || null,
					items,
					accion_precio: neAccion || null,
					medio_pago: neMedioPago,
					generar_afip: neGenerarAfip,
					tipo_comprobante_envio: neTipoCbte,
					caja_id: cajaOperativaId()
				})
			});
			const dc = await rc.json();
			if (!rc.ok) {
				toast_(dc.error || 'Error al crear nota', 'err');
				return;
			}
			cerrarNE();
			let msg = `Nota de envío N° ${dc.numero} creada`;
			if (dc.cae) msg += ` — CAE: ${dc.cae}`;
			if (dc.cae_error) msg += ` — Error AFIP: ${dc.cae_error}`;
			toast_(msg, dc.cae_error ? 'err' : 'ok');
			const rp = await api(`/notas-envio/${dc.id}/pdf`);
			if (!rp.ok) {
				toast_('La nota se guardó pero no se pudo generar el PDF', 'err');
				return;
			}
			abrirPdf(await rp.blob());
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			neGenerando = false;
		}
	}

	// ── Copiar al POS / Editar ítems ──────────────────────────────
	async function accionCopiar() {
		if (!ventaSeleccionada) return;
		try {
			const v = await cargarDetalle(ventaSeleccionada.id);
			const datos = {
				cliente: v.cliente_id ? { id: v.cliente_id, nombre: v.cliente_nombre } : null,
				items: v.items.map((i) => ({ producto_id: i.producto_id, nombre: i.nombre_manual ?? i.nombre, codigo: i.codigo ?? '', cantidad: i.cantidad, precio_unitario: i.precio_unitario }))
			};
			try {
				localStorage.setItem('logos_copia_venta', JSON.stringify(datos));
			} catch {
				toast_('Sin espacio en el navegador para copiar la venta.', 'err');
				return;
			}
			window.location.href = '/';
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		}
	}
	async function accionEditarItems() {
		if (!ventaSeleccionada) return;
		if (ventaSeleccionada.cae) {
			toast_('Ya fue autorizada por ARCA (tiene CAE) — no se puede editar. Emití una Nota de Crédito para revertirla.', 'err');
			return;
		}
		try {
			const v = await cargarDetalle(ventaSeleccionada.id);
			try {
				localStorage.setItem('logos_editar_venta', JSON.stringify(v));
			} catch {
				toast_('Sin espacio en el navegador para cargar la venta en el editor.', 'err');
				return;
			}
			sessionStorage.setItem('logos_volver_tras_editar', location.href);
			window.location.href = '/';
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		}
	}

	// ── Modificar (rápido) ──────────────────────────────────────
	let editAbierto = $state(false);
	let editComp = $state('');
	let editPago = $state('efectivo');
	let editPagoDisabled = $state(false);
	let editAvisoTexto = $state('');
	let editObs = $state('');
	let editGuardando = $state(false);

	function accionModificarRapido() {
		if (!ventaSeleccionada) return;
		if (ventaSeleccionada.cae) {
			toast_('Ya fue autorizada por ARCA (tiene CAE) — no se puede editar. Emití una Nota de Crédito para revertirla.', 'err');
			return;
		}
		const v = ventaSeleccionada;
		editComp = v.tipo_comprobante ?? '';
		const esCC = v.tipo_pago === 'cc';
		const esMixto = v.tipo_pago === 'mixto';
		const bloqueado = esCC || esMixto;
		editAvisoTexto = esMixto ? 'Esta venta tiene un pago mixto. No se puede cambiar la forma de pago.' : 'Esta venta es en cuenta corriente. No se puede cambiar la forma de pago.';
		editPagoDisabled = bloqueado;
		editPago = bloqueado ? (v.tipo_pago ?? 'efectivo') : (v.tipo_pago ?? 'efectivo');
		editObs = v.observaciones ?? '';
		editAbierto = true;
	}
	function cerrarEdit() {
		editAbierto = false;
	}
	async function guardarEdicionRapida() {
		if (!ventaSeleccionada) return;
		editGuardando = true;
		const body: Record<string, unknown> = { tipo_comprobante: editComp, observaciones: editObs.trim() };
		if (!editPagoDisabled) body.tipo_pago = editPago;
		try {
			const r = await api(`/ventas/${ventaSeleccionada.id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al guardar');
			ventaDetalleCache = d;
			const idx = resultadosActuales.findIndex((v) => v.id === d.id);
			if (idx !== -1) {
				resultadosActuales[idx] = { ...resultadosActuales[idx], ...d };
				ventaSeleccionada = resultadosActuales[idx];
			}
			editAbierto = false;
			toast_(`Venta #${d.numero} actualizada`, 'ok');
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al guardar', 'err');
		} finally {
			editGuardando = false;
		}
	}

	// ── Eliminar / anular ──────────────────────────────────────────
	let delAbierto = $state(false);
	let delTitulo = $state('');
	let delInfo = $state('');
	let delCaeAviso = $state(false);
	let delClaveVisible = $state(false);
	let delClave = $state('');
	let delClaveError = $state('');
	let delConfirmando = $state(false);
	let delConfirmarTexto = $state('Sí, anular');
	let delConfirmarDisabled = $state(false);
	let modoRecuperar = $state(false);

	function mostrarClaveEliminar() {
		delClave = '';
		delClaveError = '';
		delClaveVisible = !esAdmin;
	}
	function accionEliminar() {
		if (!ventaSeleccionada) return;
		const v = ventaSeleccionada;
		modoDeleteBulk = false;
		modoRecuperar = false;
		delTitulo = `Venta #${v.numero} — ${fmt(v.total)}`;
		delInfo = `Fecha: ${v.fecha} · Cliente: ${v.cliente_nombre ?? '— Consumidor final —'} · Tipo: ${v.tipo_comprobante ?? '—'} · Pago: ${v.tipo_pago ?? '—'}`;
		delConfirmarTexto = 'Sí, anular';
		delConfirmarDisabled = !!v.cae;
		delCaeAviso = !!v.cae;
		mostrarClaveEliminar();
		delAbierto = true;
	}
	function accionRecuperar() {
		if (!ventaSeleccionada) return;
		const v = ventaSeleccionada;
		modoDeleteBulk = false;
		modoRecuperar = true;
		delTitulo = `Recuperar venta #${v.numero} — ${fmt(v.total)}`;
		delInfo = `Fecha: ${v.fecha} · Cliente: ${v.cliente_nombre ?? '— Consumidor final —'} · Tipo: ${v.tipo_comprobante ?? '—'} · Pago: ${v.tipo_pago ?? '—'}`;
		delConfirmarTexto = 'Sí, recuperar';
		delConfirmarDisabled = false;
		delCaeAviso = false;
		mostrarClaveEliminar();
		delAbierto = true;
	}
	function accionEliminarBulk() {
		const lista = [...multiSel.values()];
		modoDeleteBulk = true;
		modoRecuperar = false;
		delTitulo = `Eliminar ${lista.length} venta${lista.length !== 1 ? 's' : ''}`;
		delInfo = lista.map((v) => `${v.tipo_comprobante ?? '—'} N° ${v.numero} — ${v.cliente_nombre ?? '— Consumidor final —'} — ${fmt(v.total)}`).join(' · ');
		delConfirmarTexto = `Sí, eliminar las ${lista.length}`;
		delConfirmarDisabled = false;
		delCaeAviso = false;
		mostrarClaveEliminar();
		delAbierto = true;
	}
	function cerrarDel() {
		delAbierto = false;
	}
	async function confirmarEliminarVenta() {
		delClaveError = '';
		delConfirmando = true;
		const opciones: RequestInit = modoRecuperar
			? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ clave_autorizacion: delClave }) }
			: { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ clave_autorizacion: delClave }) };
		try {
			if (modoDeleteBulk) {
				const ids = [...multiSel.keys()];
				let ok = 0;
				for (const id of ids) {
					try {
						const r = await api(`/ventas/${id}`, opciones);
						if (r.ok) {
							resultadosActuales = resultadosActuales.filter((v) => v.id !== id);
							ok++;
						} else if (r.status === 403) {
							const d = await r.json();
							delClaveError = d.error ?? 'Clave incorrecta';
							delConfirmando = false;
							return;
						}
					} catch {
						/* continuar con el resto */
					}
				}
				limpiarMultiSel();
				delAbierto = false;
				toast_(`${ok} venta${ok !== 1 ? 's' : ''} anulada${ok !== 1 ? 's' : ''} correctamente`, 'ok');
			} else {
				if (!ventaSeleccionada) return;
				const url = modoRecuperar ? `/ventas/${ventaSeleccionada.id}/recuperar` : `/ventas/${ventaSeleccionada.id}`;
				const r = await api(url, opciones);
				const d = await r.json();
				if (!r.ok) {
					if (r.status === 403) {
						delClaveError = d.error ?? 'Clave incorrecta';
						return;
					}
					throw new Error(d.error ?? (modoRecuperar ? 'Error al recuperar' : 'Error al eliminar'));
				}
				const id = ventaSeleccionada.id;
				if (modoRecuperar) {
					const idx = resultadosActuales.findIndex((x) => x.id === id);
					if (idx !== -1) resultadosActuales[idx] = { ...resultadosActuales[idx], estado: 'completado' };
					if (ventaSeleccionada) ventaSeleccionada = { ...ventaSeleccionada, estado: 'completado' };
				} else if (fMostrarAnuladas) {
					const idx = resultadosActuales.findIndex((x) => x.id === id);
					if (idx !== -1) resultadosActuales[idx] = { ...resultadosActuales[idx], estado: 'anulado' };
				} else {
					resultadosActuales = resultadosActuales.filter((x) => x.id !== id);
				}
				delAbierto = false;
				if (!modoRecuperar) deseleccionar();
				toast_(modoRecuperar ? 'Venta recuperada correctamente.' : 'Venta anulada correctamente.', 'ok');
			}
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		} finally {
			delConfirmando = false;
		}
	}

	// ── Unificar ──────────────────────────────────────────────────
	let uniAbierto = $state(false);
	let uniTipoPago = $state('cc');
	let uniConfirmando = $state(false);
	const uniLista = $derived([...multiSel.values()].sort((a, b) => (a.fecha < b.fecha ? -1 : 1)));
	const uniTotal = $derived(uniLista.reduce((s, v) => s + (parseFloat(String(v.total)) || 0), 0));

	function abrirModalUnificar() {
		uniTipoPago = 'cc';
		uniAbierto = true;
	}
	function cerrarUni() {
		uniAbierto = false;
	}
	async function confirmarUnificar() {
		uniConfirmando = true;
		try {
			const r = await api('/ventas/unificar', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ ids: [...multiSel.keys()], tipo_pago: uniTipoPago, caja_id: cajaOperativaId() })
			});
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al unificar');
			uniAbierto = false;
			limpiarMultiSel();
			toast_(`Remito unificado N° ${d.numero} creado — ${fmt(d.total)}`, 'ok');
			await buscar();
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		} finally {
			uniConfirmando = false;
		}
	}

	// ── Integraciones: MP / WhatsApp ────────────────────────────────
	let integConfig = $state({ mp_configurado: false, wa_configurado: false });
	async function cargarIntegConfig() {
		try {
			const r = await api('/configuracion');
			if (!r.ok) return;
			const d = await r.json();
			integConfig = { mp_configurado: !!d.mp_configurado, wa_configurado: !!d.wa_configurado };
			if (Array.isArray(d.tipos_habilitados) && d.tipos_habilitados.length > 0) {
				tiposHabilitadosFiltro = d.tipos_habilitados;
			}
		} catch {
			/* sin integraciones si falla */
		}
	}

	let mpAbierto = $state(false);
	let mpQrCargando = $state(true);
	let mpQrUrl = $state('');
	let mpLink = $state('');
	async function abrirMpLink() {
		if (!ventaSeleccionada) return;
		mpQrCargando = true;
		mpQrUrl = '';
		mpLink = '';
		mpAbierto = true;
		try {
			const r = await api('/mercadopago/preferencia', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ venta_id: ventaSeleccionada.id }) });
			const d = await r.json();
			if (!r.ok) {
				toast_(d.error || 'Error al generar link', 'err');
				mpAbierto = false;
				return;
			}
			mpLink = d.init_point || '';
			if (d.qr_data_uri) mpQrUrl = d.qr_data_uri;
			mpQrCargando = false;
		} catch {
			toast_('Error de conexión con MercadoPago', 'err');
			mpAbierto = false;
		}
	}
	function cerrarMp() {
		mpAbierto = false;
	}
	async function copiarLinkMp() {
		if (!mpLink) return;
		try {
			await navigator.clipboard.writeText(mpLink);
			toast_('Link copiado', 'ok');
		} catch {
			toast_('No se pudo copiar', 'err');
		}
	}

	let waAbierto = $state(false);
	let waDesc = $state('');
	let waTelefono = $state('');
	let waEnviando = $state(false);
	function abrirWhatsapp() {
		if (!ventaSeleccionada) return;
		const v = ventaSeleccionada;
		waDesc = `Venta #${v.numero}  ·  ${fmt(v.total)}  ·  ${v.cliente_nombre ?? 'Consumidor final'}`;
		waTelefono = ventaDetalleCache?.cliente_telefono || '';
		waAbierto = true;
	}
	function cerrarWa() {
		waAbierto = false;
	}
	async function enviarWhatsapp() {
		if (!ventaSeleccionada) return;
		if (!waTelefono.trim()) {
			toast_('Ingresá un número de WhatsApp', 'err');
			return;
		}
		waEnviando = true;
		try {
			const r = await api('/whatsapp/enviar', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ venta_id: ventaSeleccionada.id, telefono: waTelefono.trim() }) });
			const d = await r.json();
			if (!r.ok) {
				toast_(d.error || 'Error al enviar', 'err');
				return;
			}
			waAbierto = false;
			toast_('Comprobante enviado por WhatsApp', 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			waEnviando = false;
		}
	}

	// ── Confirmar presupuesto ────────────────────────────────────
	type CpPago = 'efectivo' | 'transferencia' | 'cc' | 'tarjeta' | 'cheque' | 'mercado_pago';
	const CP_TIPOS_PAGO: { val: CpPago; lbl: string }[] = [
		{ val: 'efectivo', lbl: 'Efectivo' },
		{ val: 'transferencia', lbl: 'Transferencia' },
		{ val: 'cc', lbl: 'Cta. Corriente' },
		{ val: 'tarjeta', lbl: 'Tarjeta' },
		{ val: 'cheque', lbl: 'Cheque' },
		{ val: 'mercado_pago', lbl: 'Mercado Pago' }
	];
	let cpAbierto = $state(false);
	let cpVenta = $state<Venta | null>(null);
	let cpTipoComp = $state<'REMITO' | 'FC A-ELECT' | 'FC B-ELECT' | 'FC C-ELECT'>('REMITO');
	let cpClienteId = $state<number | null>(null);
	let cpClienteNom = $state('— Consumidor final —');
	let cpCliBuscando = $state(false);
	let cpCliInput = $state('');
	let cpCliDdVisible = $state(false);
	let cpCliResultados = $state<Cliente[]>([]);
	let cpPago = $state<CpPago>('efectivo');
	let cpEsMixto = $state(false);
	let cpMixtoLineas = $state<{ tipo: CpPago; monto: string }[]>([]);
	let cpChqNumero = $state('');
	let cpChqBanco = $state('');
	let cpChqLibrador = $state('');
	let cpChqCuit = $state('');
	let cpChqEmision = $state('');
	let cpChqVenc = $state('');
	let cpGuardando = $state(false);
	let cpTexto = $state('Confirmar');
	let cpCliTimer: ReturnType<typeof setTimeout>;

	function cpAbrirModal(v: Venta) {
		cpVenta = v;
		cpTipoComp = 'REMITO';
		cpClienteId = v.cliente_id ?? null;
		cpClienteNom = v.cliente_nombre ?? '— Consumidor final —';
		cpPago = 'efectivo';
		cpEsMixto = false;
		cpMixtoLineas = [];
		cpCliBuscando = false;
		cpCliInput = '';
		cpCliDdVisible = false;
		cpChqNumero = '';
		cpChqBanco = '';
		cpChqLibrador = '';
		cpChqCuit = '';
		cpChqEmision = '';
		cpChqVenc = '';
		cpTexto = 'Confirmar';
		cpGuardando = false;
		cpAbierto = true;
	}
	function cerrarCp() {
		cpAbierto = false;
	}
	function cpElegirTipo(t: typeof cpTipoComp) {
		cpTipoComp = t;
	}
	function cpToggleBuscarCliente() {
		cpCliBuscando = !cpCliBuscando;
		cpCliInput = '';
	}
	function onCpCliInput() {
		clearTimeout(cpCliTimer);
		const q = cpCliInput.trim();
		if (!q) {
			cpCliDdVisible = false;
			return;
		}
		cpCliTimer = setTimeout(async () => {
			try {
				const r = await api(`/clientes?q=${encodeURIComponent(q)}&limit=15`);
				const d = await r.json();
				cpCliResultados = Array.isArray(d) ? d : (d.data ?? []);
				cpCliDdVisible = true;
			} catch {
				/* silencioso */
			}
		}, 280);
	}
	function cpSeleccionarCliente(c: Cliente) {
		cpClienteId = c.id;
		cpClienteNom = c.nombre;
		cpCliBuscando = false;
		cpCliDdVisible = false;
	}
	function cpNuevoCliente() {
		abrirContacto(null, 'clientes', {
			onGuardado: (cli) => {
				if (!cli) return;
				cpClienteId = cli.id;
				cpClienteNom = cli.nombre;
				cpCliBuscando = false;
			}
		});
	}
	function cpElegirPago(p: CpPago) {
		cpEsMixto = false;
		cpPago = p;
	}
	function cpActivarMixto() {
		cpEsMixto = true;
		cpMixtoLineas = [
			{ tipo: cpPago, monto: '' },
			{ tipo: CP_TIPOS_PAGO.find((t) => t.val !== cpPago)?.val ?? 'transferencia', monto: '' }
		];
	}
	function cpSumaLineas(excluir = -1): number {
		return cpMixtoLineas.reduce((s, l, i) => (i === excluir ? s : s + (parseFloat(l.monto) || 0)), 0);
	}
	const cpRestante = $derived(cpTotalVenta() - cpSumaLineas());
	function cpTotalVenta(): number {
		return parseFloat(String(cpVenta?.total ?? 0)) || 0;
	}
	function cpAutoLinea(idx: number) {
		const v = Math.max(0, cpTotalVenta() - cpSumaLineas(idx)).toFixed(2);
		cpMixtoLineas[idx].monto = v;
	}
	function cpAgregarLinea() {
		if (cpMixtoLineas.length >= 5) return;
		const usado = cpMixtoLineas.map((l) => l.tipo);
		const libre = CP_TIPOS_PAGO.find((t) => !usado.includes(t.val))?.val ?? 'efectivo';
		cpMixtoLineas = [...cpMixtoLineas, { tipo: libre, monto: '' }];
	}
	function cpQuitarLinea(idx: number) {
		cpMixtoLineas = cpMixtoLineas.filter((_, i) => i !== idx);
	}

	async function cpGuardar() {
		if (cpEsMixto) {
			const suma = cpSumaLineas();
			if (Math.abs(suma - cpTotalVenta()) > 0.005) {
				toast_(`La suma de los pagos (${fmt(suma)}) no coincide con el total (${fmt(cpTotalVenta())})`, 'err');
				return;
			}
		}
		if (!cpEsMixto && cpPago === 'cheque') {
			if (!cpChqNumero.trim() || !cpChqBanco.trim() || !cpChqVenc) {
				toast_('Completá N° de cheque, banco y fecha de vencimiento', 'err');
				return;
			}
		}
		cpGuardando = true;
		cpTexto = 'Guardando...';
		const body: Record<string, unknown> = { tipo_comprobante: cpTipoComp, cliente_id: cpClienteId, tipo_pago: cpEsMixto ? 'mixto' : cpPago };
		if (cpEsMixto) body.pagos = cpMixtoLineas.map((l) => ({ tipo: l.tipo, monto: parseFloat(l.monto) }));

		try {
			const r = await api(`/ventas/${cpVenta!.id}/confirmar-presupuesto`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const d = await r.json();
			if (!r.ok) {
				toast_(d.error ?? 'Error al confirmar', 'err');
				cpGuardando = false;
				cpTexto = 'Confirmar';
				return;
			}
			if (!cpEsMixto && cpPago === 'cheque') {
				try {
					const rc = await api('/cheques', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({
							tipo: 'recibido',
							numero: cpChqNumero.trim(),
							banco: cpChqBanco.trim(),
							librador: cpChqLibrador.trim() || null,
							cuit: cpChqCuit.trim() || null,
							monto: cpTotalVenta(),
							fecha_emision: cpChqEmision || null,
							fecha_vencimiento: cpChqVenc,
							venta_id: d.id,
							cliente_id: cpClienteId
						})
					});
					if (!rc.ok) {
						const dc = await rc.json();
						toast_(`Venta confirmada pero no se pudo registrar el cheque: ${dc.error ?? ''}`, 'err');
					}
				} catch {
					toast_('Venta confirmada. No se pudo registrar el cheque (error de conexión)', 'err');
				}
			}

			const esFC = (['FC A-ELECT', 'FC B-ELECT', 'FC C-ELECT'] as string[]).includes(cpTipoComp);
			if (esFC) {
				cpTexto = 'Solicitando CAE...';
				try {
					const rf = await api(`/ventas/${d.id}/facturar`, { method: 'POST' });
					const df = await rf.json();
					if (!rf.ok) toast_(`Comprobante confirmado pero ARCA rechazó el CAE: ${df.error ?? ''}`, 'err');
					else toast_(`Factura autorizada · CAE ${df.cae}`, 'ok');
				} catch {
					toast_('Comprobante confirmado. No se pudo conectar con ARCA para el CAE.', 'err');
				}
			} else {
				toast_('Presupuesto confirmado como ' + cpTipoComp, 'ok');
			}
			cerrarCp();
			buscar();
		} catch {
			toast_('Error de conexión', 'err');
			cpGuardando = false;
			cpTexto = 'Confirmar';
		}
	}

	// ── Escape cierra modales (misma precedencia que legacy) ────────
	function onKeydownGlobal(e: KeyboardEvent) {
		if (e.key === 'Escape') {
			if (compDdVisible) {
				compDdVisible = false;
				return;
			}
			if (mailAbierto) {
				cerrarMail();
				return;
			}
			if (delAbierto) {
				cerrarDel();
				return;
			}
			if (uniAbierto) {
				cerrarUni();
				return;
			}
			if (editAbierto) {
				cerrarEdit();
				return;
			}
			if (verAbierto) {
				cerrarVer();
				return;
			}
			if (mpAbierto) {
				cerrarMp();
				return;
			}
			if (waAbierto) {
				cerrarWa();
				return;
			}
			if (devAbierto) {
				cerrarDev();
				return;
			}
			if (ncAbierto) {
				cerrarNc();
				return;
			}
			if (neAbierto) {
				cerrarNE();
				return;
			}
			if (cpAbierto) {
				cerrarCp();
				return;
			}
			if (multiSel.size > 0) {
				limpiarMultiSel();
				return;
			}
			deseleccionar();
			return;
		}
		if (!ventaSeleccionada) return;
		if (e.key === 'F5' || (e.ctrlKey && e.key === 'p')) {
			e.preventDefault();
			accionImprimir();
		}
	}

	// ── Init ─────────────────────────────────────────────────────
	async function initSucursalFiltro() {
		try {
			const raw = localStorage.getItem('logos_sesion');
			const s = raw ? JSON.parse(raw) : {};
			let suc;
			if (s.rol === 'admin') {
				const r = await api('/sucursales');
				suc = await r.json();
			} else {
				suc = s.sucursales || [];
			}
			if (!Array.isArray(suc) || suc.length <= 1) return;
			sucursales = suc;
		} catch {
			/* sin filtro si falla */
		}
	}
	async function initVendedorFiltro() {
		try {
			const r = await api('/vendedores');
			if (!r.ok) return;
			const lista = await r.json();
			if (!lista.length) return;
			vendedores = lista;
			const raw = sessionStorage.getItem('logos_filtro_ventas');
			if (raw) {
				sessionStorage.removeItem('logos_filtro_ventas');
				const f = JSON.parse(raw);
				if (f.fecha_desde) fDesde = f.fecha_desde;
				if (f.fecha_hasta) fHasta = f.fecha_hasta;
				if (f.vendedor_id) fVendedor = String(f.vendedor_id);
				buscar();
			}
		} catch {
			/* sin filtro si falla */
		}
	}

	// ── Tour guiado — port de pos/ventas.html (24 pasos). Esta pantalla
	// cambió bastante de estructura en la migración a Svelte: varios ids
	// legacy (#f-desde, #f-tipo, #f-monto-min, #btn-more, #sac-ver, etc.)
	// ya no existen — los botones de acción comparten clase (.selec-btn)
	// sin id individual, así que se agregaron atributos data-tour="..." a
	// los que hacía falta distinguir. Verificado selector por selector
	// contra el DOM real antes de escribir esto.
	const TOUR_STEPS: TourStep[] = [
		{
			el: null,
			title: 'Módulo de Ventas',
			body: 'Acá aparece el historial completo de todas las ventas. Podés filtrar por nombre, número, fecha, tipo de comprobante, cliente, monto y más.',
			onEnter: async ({ delay }) => {
				setPeriodo('anio');
				await delay(500);
			}
		},
		{
			el: '#f-q-input',
			title: 'Búsqueda general',
			body: 'El campo principal de búsqueda: escribí el nombre del cliente, el número de comprobante o cualquier texto y presioná Enter. Filtra en tiempo real sobre todos los resultados.'
		},
		{
			el: '#pc-select',
			title: 'Período',
			body: 'Elegí Hoy, Esta semana, Este mes o Este año para filtrar rápidamente — las fechas se actualizan solas. O elegí "Rango manual…" para elegir vos las fechas de inicio y fin en una ventanita aparte, útil para consultas de un período específico como el mes pasado o un trimestre.'
		},
		{
			el: '[data-tour="f-tipo"]',
			title: 'Tipo de comprobante',
			body: 'Filtrá por Remito, Factura B, Factura A, Presupuesto o Nota de Crédito para ver solo ese tipo en la lista.'
		},
		{
			el: '.f-cli-wrap',
			title: 'Filtro por cliente',
			body: 'Buscá ventas de un cliente específico escribiendo su nombre. El campo muestra sugerencias a medida que escribís.'
		},
		{
			el: '[data-tour="f-monto-min"]',
			title: 'Rango de monto',
			body: 'Filtrá por monto mínimo y máximo para encontrar ventas dentro de un rango de valor.'
		},
		{
			el: '.fbar-btn-more',
			title: 'Más opciones',
			body: 'Este botón despliega opciones adicionales: agrupar resultados por cliente, mostrar ventas anuladas y exportar la lista a Excel.',
			onEnter: async ({ delay }) => {
				if (!morePanelVisible) morePanelVisible = true;
				await delay(250);
			}
		},
		{
			el: '.fbar-more-panel',
			title: 'Opciones del panel',
			body: '<strong>Agrupar</strong>: agrupa las filas por cliente o tipo. <strong>Mostrar anuladas</strong>: incluye las ventas anuladas. <strong>Exportar Excel</strong>: descarga la búsqueda actual como archivo .xlsx.'
		},
		{
			el: '.tabla-wrap',
			title: 'Listado de ventas',
			body: 'Cada fila es una venta: fecha, tipo de comprobante, cliente, observaciones, medio de pago y total. Hacé click en una fila para seleccionarla.',
			onEnter: async ({ delay }) => {
				morePanelVisible = false;
				await delay(250);
			}
		},
		{
			el: '.seleccion-bar',
			title: 'Barra de selección',
			body: 'Al hacer click en una fila, aparece esta barra con los datos de la venta y todas las acciones disponibles. Cada acción se explica a continuación.',
			onEnter: async ({ delay, waitFor }) => {
				// La carga de la tabla es async — esperar a que exista al menos
				// una fila antes de clickear, no asumir que ya está en el DOM
				// (carrera real: al llegar rápido a este paso, la fila todavía
				// no había cargado y el click se perdía). Si "Agrupar" está
				// activo la tabla tiene filas de encabezado de grupo
				// (.grupo-header-row) y separadores (.grupo-sep) que no son
				// ventas — hay que excluirlas, si no el click cae en una fila
				// no clickeable y nunca selecciona nada.
				try {
					const tr = (await waitFor(
						'.tabla-wrap tbody tr:not(.grupo-header-row):not(.grupo-sep)'
					)) as HTMLElement;
					tr.click();
					await delay(200);
					await waitFor('.seleccion-bar .selec-info');
				} catch {
					/* sin ventas cargadas en esta instalación — el highlight sigue funcionando igual */
				}
			}
		},
		{
			el: '.selec-info',
			title: 'Datos de la venta',
			body: 'Muestra el número de comprobante, tipo, cliente, monto total y fecha de la venta seleccionada. Se actualiza instantáneamente al cambiar la selección.'
		},
		{
			el: '.overlay.abierto .modal',
			pad: 0,
			title: 'Ver detalle',
			body: 'Abre el comprobante completo: datos del cliente, todos los ítems con cantidades, precios, descuentos y total. Si tiene CAE de AFIP también aparece acá.',
			onEnter: async ({ delay }) => {
				await accionVer();
				await delay(400);
			}
		},
		{
			el: '.modal-body',
			title: 'Contenido del comprobante',
			body: 'Acá ves todos los productos vendidos con sus cantidades y precios. Podés hacer scroll para ver el detalle completo.'
		},
		{
			el: '[data-tour="comp-dd"]',
			title: 'Opciones de comprobante',
			body: '<strong>Imprimir</strong>: manda el comprobante a la impresora. <strong>Descargar PDF</strong>: genera y descarga el PDF. <strong>Enviar por mail</strong>: envía el comprobante al email del cliente.',
			onEnter: async ({ delay }) => {
				cerrarVer();
				await delay(300);
			}
		},
		{
			el: '[data-tour="sac-nota-envio"]',
			title: 'Nota de envío',
			body: 'Genera una nota de envío para registrar la entrega total o parcial de los productos del remito, sin mostrar los precios.',
			onEnter: async ({ delay }) => {
				compDdVisible = false;
				await delay(150);
			}
		},
		{
			el: '#ne-overlay .ne-modal',
			pad: 0,
			title: 'Modal de nota de envío',
			body: 'Ingresás transportista, fecha de entrega y dirección. Los ítems se listan con su cantidad editable y un checkbox para excluir productos de esta entrega.',
			onEnter: async ({ delay }) => {
				await accionNotaEnvio();
				await delay(500);
			}
		},
		{
			el: '.ne-body',
			title: 'Artículos a entregar',
			body: 'Cada producto aparece con la cantidad pendiente de entregar. Podés ajustar la cantidad que sale en esta nota. Si hubo entregas anteriores, se muestra el historial encima.'
		},
		{
			el: '[data-tour="sac-copiar"]',
			title: 'Copiar al POS',
			body: 'Copia todos los ítems de esta venta al POS para generar un nuevo comprobante basado en ella. Muy útil para repetir pedidos frecuentes o para hacer cambios y rehacer una venta.',
			onEnter: async ({ delay }) => {
				cerrarNE();
				await delay(300);
			}
		},
		{
			el: '.overlay.abierto .modal',
			pad: 0,
			title: 'Editar venta',
			body: 'Cambiá el tipo de comprobante, la forma de pago u observaciones sin tocar los ítems. Para cambiar productos, usá "Editar ítems" que abre el POS con la venta cargada.',
			onEnter: async ({ delay }) => {
				accionModificarRapido();
				await delay(400);
			}
		},
		{
			el: '[data-tour="sac-eliminar"]',
			title: 'Anular venta',
			body: 'Marca la venta como anulada — no se borra, queda en el historial. Si tiene CAE de AFIP, el sistema te avisa. Los usuarios no-administradores necesitan una clave de autorización.',
			onEnter: async ({ delay }) => {
				cerrarEdit();
				await delay(300);
			}
		},
		{
			el: '.seleccion-bar',
			title: 'Selección múltiple',
			body: 'Con los checkboxes a la izquierda de cada fila podés seleccionar varias ventas a la vez para operar en bloque: anular varias o unificarlas en un solo comprobante.',
			onEnter: async ({ delay }) => {
				deseleccionar();
				await delay(150);
				const chks = Array.from(document.querySelectorAll('.tabla-wrap .uni-chk')) as HTMLInputElement[];
				for (let i = 0; i < Math.min(2, chks.length); i++) {
					if (!chks[i].checked) chks[i].click();
					await delay(100);
				}
			}
		},
		{
			el: '[data-tour="sac-unificar"]',
			title: 'Unificar comprobantes',
			body: 'Combina varias ventas del mismo cliente y tipo de comprobante en un único comprobante. Muy útil cuando se hicieron varios remitos para el mismo cliente y se quiere consolidarlos.'
		},
		{
			el: 'a[href="/cuentacorriente"]',
			title: 'Cuenta Corriente',
			body: 'El próximo módulo es <strong>Cta. Cte.</strong>, donde gestionás los saldos pendientes de clientes y proveedores. ¡Eso es todo para Ventas!',
			onEnter: async ({ delay }) => {
				const chks = Array.from(document.querySelectorAll('.tabla-wrap .uni-chk')) as HTMLInputElement[];
				chks.forEach((c) => { if (c.checked) c.click(); });
				limpiarMultiSel();
				await delay(100);
			}
		}
	];

	onMount(() => {
		initSucursalFiltro();
		initVendedorFiltro();
		cargarIntegConfig();
		setPeriodo('hoy');
		setTourSteps('ventas', TOUR_STEPS);
	});
</script>

<svelte:window onkeydown={onKeydownGlobal} />

<svelte:head>
	<title>Logos — Ventas</title>
</svelte:head>

<div class="filtros-bar">
<div class="fbar-scroll">
	<input type="text" class="fbar-ctrl" id="f-q-input" placeholder="Nombre, N°…" autocomplete="off" bind:value={fQ} onkeydown={(e) => e.key === 'Enter' && buscar()} />
	<button class="fbar-btn fbar-btn-search" title="Buscar" onclick={buscar}>
		<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4.35-4.35" /></svg>
	</button>

	<div class="fbar-sep"></div>

	{#if sucursales.length > 1}
		<select class="fbar-ctrl" title="Sucursal" bind:value={fSucursal} onchange={buscar}>
			<option value="">Todas las sucursales</option>
			{#each sucursales as s (s.id)}<option value={String(s.id)}>{s.nombre}</option>{/each}
		</select>
		<div class="fbar-sep"></div>
	{/if}

	<select
		class="fbar-ctrl"
		id="pc-select"
		data-tour="f-desde"
		title="Período"
		bind:value={pcSelect}
		onchange={() => {
			if (pcSelect === 'manual') abrirRangoManual();
			else if (pcSelect) setPeriodo(pcSelect as 'hoy' | 'semana' | 'mes' | 'anio');
		}}
	>
		<option value="hoy">Hoy</option>
		<option value="semana">Esta semana</option>
		<option value="mes">Este mes</option>
		<option value="anio">Este año</option>
		<option value="manual">Rango manual…</option>
	</select>
	{#if pcSelect === 'manual'}
		<button type="button" class="fbar-ctrl fbar-rango-btn" title="Cambiar el rango de fechas" onclick={abrirRangoManual}>
			{fmtFechaCorta(fDesde)} → {fmtFechaCorta(fHasta)}
		</button>
	{/if}

	<div class="fbar-sep"></div>

	<select class="fbar-ctrl" data-tour="f-tipo" title="Tipo de comprobante" bind:value={fTipo} onchange={buscar}>
		<option value="">Tipo</option>
		{#each TIPOS_COMPROBANTE_FILTRO.filter((t) => tiposHabilitadosFiltro.includes(t.value)) as t (t.value)}
			<option value={t.value}>{t.label}</option>
		{/each}
	</select>

	{#if vendedores.length}
		<select class="fbar-ctrl" style="width:120px" title="Vendedor" bind:value={fVendedor} onchange={buscar}>
			<option value="">Vendedor</option>
			{#each vendedores as v (v.id)}<option value={String(v.id)}>{v.nombre}</option>{/each}
		</select>
	{/if}

	<div class="f-cli-wrap">
		{#if clienteFiltroId}
			<div class="f-cli-tag visible">
				<span>{clienteFiltroNombre}</span>
				<button class="f-cli-clear" title="Quitar filtro" onclick={limpiarClienteFiltro}>×</button>
			</div>
		{:else}
			<input type="text" class="fbar-ctrl" placeholder="Cliente…" autocomplete="off" spellcheck="false" bind:value={fCliInput} oninput={onFCliInput} onkeydown={onFCliKeydown} onblur={() => setTimeout(() => (cliDdVisible = false), 150)} />
		{/if}
		{#if cliDdVisible}
			<div class="f-cli-dd visible">
				{#each cliDdResultados as c, i (c.id)}
					<div class="f-cli-dd-item" class:activo={i === cliDdIdx} onmousedown={() => seleccionarClienteFiltro(c)} role="button" tabindex="-1">
						<div class="cn">{c.nombre}</div>
						<div class="cd">{c.cuit ?? ''}</div>
					</div>
				{/each}
			</div>
		{/if}
	</div>

	<input type="number" class="fbar-ctrl" data-tour="f-monto-min" placeholder="$ mín" min="0" step="any" bind:value={fMontoMin} onchange={buscar} />
	<input type="number" class="fbar-ctrl" placeholder="$ máx" min="0" step="any" bind:value={fMontoMax} onchange={buscar} />
</div>

	<span class="resultados-count">{countLabel}</span>

	<button class="fbar-btn fbar-btn-refresh" class:spinning={buscando} title="Actualizar lista" onclick={buscar}>
		<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6" /><path d="M1 20v-6h6" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10" /><path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14" /></svg>
	</button>

	<div class="fbar-more-wrap">
		<button class="fbar-btn fbar-btn-more" class:activo={morePanelVisible} title="Más opciones" onclick={(e) => { e.stopPropagation(); morePanelVisible = !morePanelVisible; }}>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="5" r="1" fill="currentColor" /><circle cx="12" cy="12" r="1" fill="currentColor" /><circle cx="12" cy="19" r="1" fill="currentColor" /></svg>
		</button>
		{#if morePanelVisible}
			<div class="fbar-more-panel visible">
				<label class="fbar-more-label" for="f-grupo-sel">Agrupar</label>
				<select id="f-grupo-sel" class="fbar-more-ctrl" bind:value={fGrupo}>
					<option value="">Sin agrupar</option>
					<option value="cliente">Por cliente</option>
					<option value="tipo">Por tipo</option>
				</select>
				<div class="fbar-more-sep"></div>
				<label class="fbar-chk fbar-more-chk" title="Mostrar ventas anuladas">
					<input type="checkbox" bind:checked={fMostrarAnuladas} onchange={buscar} /> Mostrar anuladas
				</label>
				<label class="fbar-chk fbar-more-chk" title="Facturas electrónicas sin CAE autorizado por ARCA">
					<input type="checkbox" bind:checked={fSinCae} onchange={buscar} /> Solo pendientes de CAE
				</label>
				<div class="fbar-more-sep"></div>
				<button class="fbar-more-export" onclick={async () => {
					const params = new URLSearchParams();
					if (fDesde) params.set('fecha_desde', fDesde);
					if (fHasta) params.set('fecha_hasta', fHasta);
					if (fTipo) params.set('tipo', fTipo);
					if (fMontoMin.trim() !== '') params.set('monto_min', fMontoMin.trim());
					if (fMontoMax.trim() !== '') params.set('monto_max', fMontoMax.trim());
					if (clienteFiltroId) params.set('cliente_id', String(clienteFiltroId));
					if (fMostrarAnuladas) params.set('mostrar_anuladas', '1');
					try {
						const r = await api(`/ventas/export?${params}`);
						if (!r.ok) { const d = await r.json(); throw new Error(d.error ?? 'Error al exportar'); }
						const blob = await r.blob();
						const url = URL.createObjectURL(blob);
						const a = document.createElement('a');
						a.href = url;
						a.download = `ventas_${fDesde || 'todo'}_${fHasta || 'hoy'}.xlsx`;
						a.click();
						URL.revokeObjectURL(url);
					} catch (e) {
						toast_(e instanceof Error ? e.message : 'Error al exportar', 'err');
					}
				}}>
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
					Descargar Excel
				</button>
			</div>
		{/if}
	</div>
</div>

{#if mesesVisible}
	<div class="meses-strip visible">
		<span class="mes-sep">{mesesAnio} /</span>
		{#each MESES as nm, i (i)}
			<button class="mes-chip" class:activo={mesActivo === i} class:futuro={i > mesLimite(mesesAnio)} onclick={() => i <= mesLimite(mesesAnio) && setPeriodo({ mes: i })}>{nm}</button>
		{/each}
	</div>
{/if}

{#if rangoModalAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cancelarRangoManual()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Rango de fechas</h2>
				<button class="modal-close" aria-label="Cerrar" onclick={cancelarRangoManual}>×</button>
			</div>
			<div class="modal-body">
				<div class="rango-form">
					<div class="form-group">
						<label class="form-label" for="rm-desde">Desde</label>
						<input id="rm-desde" type="date" class="form-input" bind:value={rmDesde} />
					</div>
					<div class="form-group">
						<label class="form-label" for="rm-hasta">Hasta</label>
						<input id="rm-hasta" type="date" class="form-input" bind:value={rmHasta} />
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-sec" onclick={cancelarRangoManual}>Cancelar</button>
				<button class="selec-btn sbtn-primary" onclick={aplicarRangoManual}>Aplicar</button>
			</div>
		</div>
	</div>
{/if}

<!-- Barra de selección -->
<div class="seleccion-bar" class:sin-sel={!ventaSeleccionada && multiSel.size === 0} class:modo-multi={multiSel.size > 0}>
	<div class="selec-left">
		{#if !ventaSeleccionada && multiSel.size === 0}
			<div class="selec-placeholder" style="display:block">Seleccioná una venta para ver las acciones</div>
		{:else if multiSel.size === 0 && ventaSeleccionada}
			{@const v = ventaSeleccionada}
			<div class="selec-info" style="display:flex">
				<span class="selec-numero">#{v.numero}</span>
				<span class="selec-badge"><span class="tipo-badge {tipoBadgeClass(v.tipo_comprobante)}">{v.tipo_comprobante ?? '—'}</span></span>
				<span class="selec-cliente">{v.cliente_nombre ?? '— Consumidor final —'}</span>
				<span class="selec-monto">{fmt(v.total)}</span>
				<span class="selec-fecha">{v.fecha}</span>
			</div>
		{:else}
			<div class="selec-multi-info" style="display:flex">
				<span class="selec-multi-count">{multiSel.size} seleccionado{multiSel.size !== 1 ? 's' : ''}</span>
				<span class="selec-multi-warn">{multiWarn}</span>
			</div>
		{/if}
	</div>
	<div class="selec-sep"></div>
	{#if multiSel.size === 0}
		<div class="selec-acciones">
			<button class="selec-btn sbtn-ghost" data-tour="sac-ver" onclick={accionVer}
				><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3" /><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /></svg>Ver</button
			>
			{#if necesitaReintento}
				<button class="selec-btn sbtn-primary" disabled={reintentandoAfipBar} onclick={accionReintentarAfipBar}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10" /><path d="M3.51 15a9 9 0 1 0 .49-4" /></svg
					>{reintentandoAfipBar ? 'Facturando…' : 'Reintentar AFIP'}</button
				>
			{/if}
			<button
				class="selec-btn sbtn-ghost"
				data-tour="sac-modificar"
				disabled={!!ventaSeleccionada?.cae}
				title={ventaSeleccionada?.cae ? 'Ya fue autorizada por ARCA (tiene CAE) — no se puede editar. Emití una Nota de Crédito para revertirla.' : ''}
				onclick={accionModificarRapido}
				><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>Editar</button
			>
			<div class="comp-dd-wrap">
				<button class="selec-btn sbtn-ghost" data-tour="comp-dd" onclick={(e) => { e.stopPropagation(); compDdVisible = !compDdVisible; }}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" /><rect x="6" y="14" width="12" height="8" /></svg
					>Comprobante<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.6"><polyline points="6 9 12 15 18 9" /></svg></button
				>
				<div class="comp-dd" data-tour="comp-dd-menu" class:visible={compDdVisible}>
					<button class="comp-dd-item" onclick={accionImprimir}
						><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" /><rect x="6" y="14" width="12" height="8" /></svg>Imprimir</button
					>
					<button class="comp-dd-item" onclick={accionPDF}
						><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>Descargar PDF</button
					>
					<button class="comp-dd-item" onclick={accionMail}
						><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" /><polyline points="22,6 12,13 2,6" /></svg>Enviar por mail</button
					>
				</div>
			</div>
			{#if integConfig.mp_configurado}
				<button class="selec-btn sbtn-ghost" onclick={abrirMpLink}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2" /><line x1="2" y1="10" x2="22" y2="10" /></svg>Link MP</button
				>
			{/if}
			{#if integConfig.wa_configurado}
				<button class="selec-btn sbtn-ghost" onclick={abrirWhatsapp}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" /></svg>WhatsApp</button
				>
			{/if}
			<button class="selec-btn sbtn-ghost" data-tour="sac-nota-envio" onclick={accionNotaEnvio}
				><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="1" /><polygon points="16 8 20 8 23 11 23 16 16 16 16 8" /><circle cx="5.5" cy="18.5" r="2.5" /><circle cx="18.5" cy="18.5" r="2.5" /></svg>Nota de envío</button
			>
			<button class="selec-btn sbtn-ghost" data-tour="sac-copiar" onclick={accionCopiar}
				><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" /><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" /></svg>Copiar al POS</button
			>
			{#if esPresupuestoActivo}
				<button class="selec-btn sbtn-primary" onclick={() => ventaSeleccionada && cpAbrirModal(ventaSeleccionada)}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12" /></svg>Confirmar</button
				>
			{/if}
			<button class="selec-btn sbtn-ghost" onclick={accionDevolver}
				><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10" /><path d="M3.51 15a9 9 0 1 0 .49-3.38" /></svg>Devolver</button
			>
			{#if puedeEmitirNc}
				<button class="selec-btn sbtn-ghost" onclick={accionEmitirNc}
					title="Emite una Nota de Crédito (con CAE) que acredita ítems de esta factura y descuenta de caja o de la cuenta corriente del cliente"
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="9" y1="15" x2="15" y2="15" /></svg>Nota de crédito</button
				>
			{/if}
			{#if ventaSeleccionada?.estado === 'anulado'}
				<button class="selec-btn sbtn-primary" onclick={accionRecuperar}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10" /><path d="M3.51 15a9 9 0 1 0 .49-3.38" /></svg>Recuperar</button
				>
			{:else}
				<button class="selec-btn sbtn-danger" data-tour="sac-eliminar" onclick={accionEliminar}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2" /></svg>Eliminar</button
				>
			{/if}
			<button class="selec-cerrar" title="Deseleccionar" onclick={deseleccionar}>×</button>
		</div>
	{:else}
		<div class="selec-acciones">
			<button class="selec-btn sbtn-danger" onclick={accionEliminarBulk}
				><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2" /></svg>Eliminar ({multiSel.size})</button
			>
			<button class="selec-btn sbtn-primary" data-tour="sac-unificar" disabled={!puedeUni} onclick={abrirModalUnificar}
				><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="18" r="3" /><circle cx="6" cy="6" r="3" /><path d="M6 21V9a9 9 0 009 9" /></svg>Unificar</button
			>
			<button class="selec-cerrar" title="Limpiar selección" onclick={limpiarMultiSel}>×</button>
		</div>
	{/if}
</div>

<div class="tabla-wrap">
	{#if buscando}
		<div class="estado-vacio">Buscando...</div>
	{:else if tablaError}
		<div class="estado-vacio">{tablaError}</div>
	{:else if !resultadosActuales.length}
		<div class="estado-vacio">Sin resultados para los filtros seleccionados</div>
	{:else if !fGrupo}
		<table>
			<thead><tr><th class="chk-col"></th><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th>Obs.</th><th>Pago</th><th class="r">Monto</th></tr></thead>
			<tbody>
				{#each resultadosActuales as v (v.id)}
					{@const obs = v.observaciones ?? ''}
					{@const obsCorta = obs.length > 38 ? obs.slice(0, 38) + '…' : obs}
					{@const anulada = v.estado === 'anulado'}
					<tr class="{compRowClass(v.tipo_comprobante)}{anulada ? ' fila-anulada' : ''}" class:seleccionada={ventaSeleccionada?.id === v.id} onclick={() => seleccionar(v)} ondblclick={() => { seleccionar(v); accionVer(); }}>
						{#if puedeUnificar(v)}
							<td class="chk-col" onclick={(e) => e.stopPropagation()}><input type="checkbox" class="uni-chk" checked={multiSel.has(v.id)} onchange={(e) => toggleMultiChk(v, (e.target as HTMLInputElement).checked)} /></td>
						{:else}
							<td class="chk-col"></td>
						{/if}
						<td class="fecha-cell">{v.origen_descripcion ? '⊕ Agrupado' : fmtFechaCorta(v.fecha)}</td>
						<td class="comp-cell">
							<span class="tipo-badge {tipoBadgeClass(v.tipo_comprobante)}">{v.tipo_comprobante ?? '—'}</span><span class="comp-num">{v.numero}</span>{#if anulada}<span class="badge-anulada">anulada</span>{/if}
							{#if TIPOS_ELECT.includes(v.tipo_comprobante ?? '')}
								{#if v.cae}<span class="cae-badge cae-ok" title="CAE: {v.cae}">✓</span>
								{:else if v.afip_error}<button type="button" class="cae-badge cae-err" title="Ver motivo del rechazo de ARCA" onclick={(e) => { e.stopPropagation(); ventaSeleccionada = v; ventaDetalleCache = null; accionVer(); }}>!</button>
								{:else}<span class="cae-badge cae-pend" title="Pendiente de autorización ARCA">…</span>{/if}
							{/if}
						</td>
						<td>{v.cliente_nombre ?? '— Consumidor final —'}</td>
						<td class="obs-cell" title={obs}>{#if obs}{obsCorta}{:else}<span class="obs-vacia">—</span>{/if}</td>
						<td><span class="pago-badge">{lblTipoPago(v.tipo_pago)}</span></td>
						<td class="r total-cell">{fmt(v.total)}</td>
					</tr>
				{/each}
			</tbody>
		</table>
	{:else}
		<table>
			<thead><tr><th class="chk-col"></th><th>Fecha</th><th>Comprobante</th><th>Cliente</th><th>Obs.</th><th>Pago</th><th class="r">Monto</th></tr></thead>
			{#each grupos ?? [] as g, gi (g.key)}
				<tbody>
					{#if gi > 0}<tr class="grupo-sep"><td colspan="7"></td></tr>{/if}
					<tr class="grupo-header-row"><td colspan="7"><span>{g.key}</span><span class="g-stats">{g.filas.length} venta{g.filas.length !== 1 ? 's' : ''}</span><span class="g-total">{fmt(g.total)}</span></td></tr>
					{#each g.filas as v (v.id)}
						{@const obs = v.observaciones ?? ''}
						{@const obsCorta = obs.length > 38 ? obs.slice(0, 38) + '…' : obs}
						{@const anulada = v.estado === 'anulado'}
						<tr class="{compRowClass(v.tipo_comprobante)}{anulada ? ' fila-anulada' : ''}" class:seleccionada={ventaSeleccionada?.id === v.id} onclick={() => seleccionar(v)} ondblclick={() => { seleccionar(v); accionVer(); }}>
							{#if puedeUnificar(v)}
								<td class="chk-col" onclick={(e) => e.stopPropagation()}><input type="checkbox" class="uni-chk" checked={multiSel.has(v.id)} onchange={(e) => toggleMultiChk(v, (e.target as HTMLInputElement).checked)} /></td>
							{:else}
								<td class="chk-col"></td>
							{/if}
							<td class="fecha-cell">{v.origen_descripcion ? '⊕ Agrupado' : fmtFechaCorta(v.fecha)}</td>
							<td class="comp-cell">
								<span class="tipo-badge {tipoBadgeClass(v.tipo_comprobante)}">{v.tipo_comprobante ?? '—'}</span><span class="comp-num">{v.numero}</span>{#if anulada}<span class="badge-anulada">anulada</span>{/if}
								{#if TIPOS_ELECT.includes(v.tipo_comprobante ?? '')}
									{#if v.cae}<span class="cae-badge cae-ok" title="CAE: {v.cae}">✓</span>
									{:else if v.afip_error}<button type="button" class="cae-badge cae-err" title="Ver motivo del rechazo de ARCA" onclick={(e) => { e.stopPropagation(); ventaSeleccionada = v; ventaDetalleCache = null; accionVer(); }}>!</button>
									{:else}<span class="cae-badge cae-pend" title="Pendiente de autorización ARCA">…</span>{/if}
								{/if}
							</td>
							<td>{v.cliente_nombre ?? '— Consumidor final —'}</td>
							<td class="obs-cell" title={obs}>{#if obs}{obsCorta}{:else}<span class="obs-vacia">—</span>{/if}</td>
							<td><span class="pago-badge">{lblTipoPago(v.tipo_pago)}</span></td>
							<td class="r total-cell">{fmt(v.total)}</td>
						</tr>
					{/each}
				</tbody>
			{/each}
		</table>
	{/if}
</div>

<!-- Modal detalle venta -->
{#if verAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarVer()}>
		<div class="modal" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>{verVenta ? `${verVenta.tipo_comprobante ?? 'Comprobante'}  N° ${verVenta.numero}` : 'Cargando...'}</h2>
				<button class="modal-close" aria-label="Cerrar" onclick={cerrarVer}>×</button>
			</div>
			<div class="modal-body">
				{#if verCargando}
					<div class="estado-vacio">Cargando...</div>
				{:else if verError}
					<div class="estado-vacio">{verError}</div>
				{:else if verVenta}
					{@const v = verVenta}
					<div class="comp-header">
						<div class="comp-bloque"><label for="_">Fecha</label><div class="val">{v.fecha}</div></div>
						<div class="comp-bloque">
							<label for="_">Forma de pago</label>
							<div class="val">
								{lblTipoPago(v.tipo_pago)}
								{#if v.tipo_pago === 'mixto' && v.pagos?.length}
									{#each v.pagos as p, i (i)}<br /><span style="font-size:12px;color:var(--gris3)">{lblTipoPago(p.tipo)}: {fmt(p.monto)}</span>{/each}
								{/if}
							</div>
						</div>
						<div class="comp-bloque"><label for="_">Cliente</label><div class="val">{v.cliente_nombre ?? '— Consumidor final —'}</div></div>
						<div class="comp-bloque"><label for="_">Estado</label><div class="val">{v.estado ?? '—'}</div></div>
						{#if v.cliente_cuit}<div class="comp-bloque"><label for="_">CUIT</label><div class="val">{v.cliente_cuit}</div></div>{/if}
						{#if v.observaciones}<div class="comp-bloque"><label for="_">Observaciones</label><div class="val">{v.observaciones}</div></div>{/if}
						{#if v.envios_detalle && v.envios_detalle.length > 1}
							{#each v.envios_detalle as e, i (i)}<div class="comp-bloque"><label for="_">Envío {e.fecha_corta}</label><div class="val">{fmt(e.precio)}</div></div>{/each}
						{:else if v.envio_precio}
							<div class="comp-bloque"><label for="_">Envío</label><div class="val">{fmt(v.envio_precio)}</div></div>
						{/if}
						{#if v.envio_direccion}<div class="comp-bloque"><label for="_">Dirección de entrega</label><div class="val">{v.envio_direccion}</div></div>{/if}
						{#if v.origen_descripcion}<div class="comp-bloque" style="flex-basis:100%"><label for="_">Origen</label><div class="val" style="font-size:12px;color:var(--gris3)">{v.origen_descripcion}</div></div>{/if}
						{#if v.cae}<div class="comp-bloque"><label for="_">CAE</label><div class="val">{v.cae}{#if v.numero_afip} <span style="font-size:11px;color:var(--gris3)">· N° AFIP {String(v.numero_afip).padStart(8, '0')}</span>{/if}</div></div>{/if}
						{#if !v.cae && v.afip_error}
							<div class="comp-bloque" style="flex-basis:100%">
								<label for="_" style="color:var(--rojo)">Factura sin autorizar por AFIP</label>
								<div class="val" style="font-size:12px;color:var(--rojo)">{v.afip_error}</div>
								<button
									style="margin-top:6px;padding:6px 14px;border:none;border-radius:6px;background:var(--neo-bg);color:var(--neo-danger);box-shadow:var(--neo-e1),0 0 0 1px var(--neo-danger);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;font-family:inherit"
									disabled={reintentandoAfip}
									onclick={reintentarAfipDesdeVer}>{reintentandoAfip ? 'Facturando…' : 'Reintentar facturación'}</button
								>
							</div>
						{/if}
					</div>
					<table class="comp-items">
						<thead><tr><th>Código</th><th>Producto</th><th class="r">Cant.</th><th class="r">Precio</th><th class="r">Subtotal</th></tr></thead>
						<tbody>
							{#each v.items as it (it.id)}
								{@const tieneAjuste = it.ajuste_desc && it.ajuste_visible && it.precio_original}
								{@const badgeDesc = it.ajuste_desc?.startsWith('+') ? false : true}
								<tr>
									<td><span class="cod">{it.codigo}</span></td>
									<td>
										{it.nombre_manual ?? it.nombre}
										{#if tieneAjuste}<span class="tipo-badge" style="background:{badgeDesc ? '#fee2e2' : '#dcfce7'};color:{badgeDesc ? '#991b1b' : '#166534'};font-size:10px;padding:1px 5px">{it.ajuste_desc}</span>{/if}
									</td>
									<td class="r">{Number(it.cantidad) % 1 === 0 ? Number(it.cantidad) : Number(it.cantidad).toFixed(2)}</td>
									<td class="r">
										{#if tieneAjuste}<span style="text-decoration:line-through;color:var(--gris3);font-size:11px;margin-right:4px">{fmt(it.precio_original)}</span>{/if}{fmt(it.precio_unitario)}
									</td>
									<td class="r">{fmt(it.subtotal)}</td>
								</tr>
							{/each}
						</tbody>
					</table>
					<div class="comp-total"><span class="label">TOTAL</span><span class="monto">{fmt(v.total)}</span></div>
				{/if}
			</div>
		</div>
	</div>
{/if}

<!-- Modal edición rápida -->
{#if editAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarEdit()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Editar venta <span style="color:var(--gris3);font-weight:400;font-size:14px">#{ventaSeleccionada?.numero}</span></h2>
				<button class="modal-close" aria-label="Cerrar" onclick={cerrarEdit}>×</button>
			</div>
			<div class="modal-body">
				{#if editPagoDisabled}
					<div class="ef-aviso visible">{editAvisoTexto}<br />Solo podés editar el tipo de comprobante y las observaciones.</div>
				{/if}
				<div class="ef-row">
					<label for="edit-comp">Tipo de comprobante</label>
					<select id="edit-comp" bind:value={editComp}>
						<option value="REMITO">Remito</option>
						<option value="FC B-ELECT">Factura B Electrónica</option>
						<option value="FC A-ELECT">Factura A Electrónica</option>
						<option value="FC C-ELECT">Factura C Electrónica</option>
						<option value="PRESUPUESTO">Presupuesto</option>
					</select>
				</div>
				<div class="ef-row">
					<label for="edit-pago">Forma de pago</label>
					<select id="edit-pago" bind:value={editPago} disabled={editPagoDisabled}>
						<option value="efectivo">Efectivo</option>
						<option value="transferencia">Transferencia</option>
						<option value="tarjeta">Tarjeta</option>
						<option value="cheque">Cheque</option>
						<option value="cc" disabled>Cuenta Corriente (no editable)</option>
						<option value="mixto" disabled>Pago mixto (no editable)</option>
					</select>
				</div>
				<div class="ef-row">
					<label for="edit-obs">Observaciones</label>
					<input type="text" id="edit-obs" placeholder="Opcional..." bind:value={editObs} />
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn-modal-link" onclick={() => { editAbierto = false; accionEditarItems(); }}>Editar ítems →</button>
				<button class="btn-modal btn-modal-ghost" onclick={cerrarEdit}>Cancelar</button>
				<button class="btn-modal btn-modal-primary" disabled={editGuardando} onclick={guardarEdicionRapida}>{editGuardando ? 'Guardando...' : 'Guardar cambios'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal confirmar anulación -->
{#if delAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarDel()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2 style="color:var(--rojo)">{modoRecuperar ? 'Recuperar venta' : 'Anular venta'}</h2>
				<button class="modal-close" aria-label="Cerrar" onclick={cerrarDel}>×</button>
			</div>
			<div class="del-body">
				<div class="del-titulo">{delTitulo}</div>
				<div class="del-info">{delInfo}</div>
				{#if modoRecuperar}
					<div class="del-aviso">
						La venta volverá a quedar <strong>COMPLETADA</strong> en el historial.<br />
						El stock de los productos se volverá a descontar.<br />
						Si era en cuenta corriente, el saldo del cliente se volverá a cargar.
					</div>
				{:else}
					<div class="del-aviso">
						La venta quedará marcada como <strong>ANULADA</strong> en el historial.<br />
						El stock de los productos se revertirá.<br />
						Si era en cuenta corriente, el saldo del cliente se actualizará.
					</div>
				{/if}
				{#if delCaeAviso}
					<div style="display:block;margin-top:10px;padding:8px 10px;background:#FEF9C3;border:1px solid #FDE047;border-radius:6px;font-size:12px;color:#78350F;">
						Esta factura fue autorizada por ARCA (tiene CAE) y no se puede anular: quedaría fuera del Libro IVA pero seguiría existiendo ante el organismo. Usá <strong>Nota de Crédito</strong> para revertirla.
					</div>
				{/if}
				{#if delClaveVisible}
					<div class="del-clave visible">
						<label for="del-clave">Clave de autorización</label>
						<input type="password" id="del-clave" autocomplete="off" bind:value={delClave} />
						<div class="del-clave-error">{delClaveError}</div>
					</div>
				{/if}
			</div>
			<div class="modal-footer">
				<button class="btn-modal btn-modal-ghost" onclick={cerrarDel}>Cancelar</button>
				<button class="btn-modal btn-modal-danger" disabled={delConfirmarDisabled || delConfirmando} onclick={confirmarEliminarVenta}>{delConfirmando ? (modoRecuperar ? 'Recuperando...' : 'Eliminando...') : delConfirmarTexto}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal enviar por mail -->
{#if mailAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarMail()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Enviar comprobante por mail</h2>
				<button class="modal-close" aria-label="Cerrar" onclick={cerrarMail}>×</button>
			</div>
			<div class="modal-body">
				<div style="margin-bottom:14px;font-size:var(--fs-sm);color:var(--gris3);line-height:1.5;">{mailDesc}</div>
				<div class="ef-row">
					<label for="mail-email">Dirección de email</label>
					<input type="email" id="mail-email" placeholder="cliente@ejemplo.com" bind:value={mailEmail} />
				</div>
				<div class="ef-row">
					<label for="mail-asunto">Asunto</label>
					<input type="text" id="mail-asunto" bind:value={mailAsunto} />
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn-modal btn-modal-ghost" onclick={cerrarMail}>Cancelar</button>
				<button class="btn-modal btn-modal-primary" disabled={mailEnviando} onclick={enviarMail}
					><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:6px"><line x1="22" y1="2" x2="11" y2="13" /><polygon points="22 2 15 22 11 13 2 9 22 2" /></svg
					>{mailEnviando ? 'Enviando…' : 'Enviar'}</button
				>
			</div>
		</div>
	</div>
{/if}

<!-- Modal MercadoPago -->
{#if mpAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarMp()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2 class="modal-titulo">Link de pago MercadoPago</h2>
				<button class="modal-close" onclick={cerrarMp}>×</button>
			</div>
			<div class="modal-body" style="text-align:center;padding:24px 20px">
				<div style="margin-bottom:16px">
					{#if mpQrUrl}<img src={mpQrUrl} alt="QR MercadoPago" style="width:180px;height:180px" />{:else if mpQrCargando}<div style="color:#888;font-size:13px">Generando QR...</div>{/if}
				</div>
				<div style="margin-bottom:12px;font-size:13px;color:#888">O compartí el link:</div>
				<div style="display:flex;gap:8px;align-items:center">
					<input class="form-input" readonly value={mpLink} style="font-size:12px;flex:1" />
					<button class="btn btn-sec" style="flex-shrink:0" onclick={copiarLinkMp}>Copiar</button>
				</div>
			</div>
			<div class="modal-footer"><button class="btn btn-sec" onclick={cerrarMp}>Cerrar</button></div>
		</div>
	</div>
{/if}

<!-- Modal WhatsApp -->
{#if waAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarWa()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2 class="modal-titulo">Enviar por WhatsApp</h2>
				<button class="modal-close" onclick={cerrarWa}>×</button>
			</div>
			<div class="modal-body" style="padding:20px">
				<p style="margin:0 0 16px;font-size:14px;color:#555">{waDesc}</p>
				<div class="form-group">
					<label class="form-label" for="wa-tel">Número de WhatsApp</label>
					<input class="form-input" type="tel" id="wa-tel" placeholder="+54 9 11 1234-5678" inputmode="tel" bind:value={waTelefono} />
					<div class="form-hint">Formato: código de país + número. Ej: +5491155556666</div>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-sec" onclick={cerrarWa}>Cancelar</button>
				<button class="btn btn-ok" disabled={waEnviando} onclick={enviarWhatsapp}>{waEnviando ? 'Enviando…' : 'Enviar PDF'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal Devolución con crédito -->
{#if devAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarDev()}>
		<div class="modal" style="width:560px;max-width:96vw" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Devolución con crédito</h2>
				<button class="modal-close" onclick={cerrarDev}>×</button>
			</div>
			<div class="modal-body" style="padding:20px">
				<p style="margin:0 0 14px;font-size:13px;color:#666">Venta N° {devVentaActual?.numero} · Cliente: {devVentaActual?.cliente_nombre ?? '—'} · Total: {fmt(devVentaActual?.total)}</p>
				<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px">
					<thead>
						<tr style="border-bottom:2px solid #eee;text-align:left">
							<th style="padding:6px 8px;font-weight:600">Producto</th>
							<th style="padding:6px 8px;font-weight:600;text-align:right">Vendido</th>
							<th style="padding:6px 8px;font-weight:600;text-align:center">Devolver</th>
							<th style="padding:6px 8px;font-weight:600;text-align:right">Precio</th>
						</tr>
					</thead>
					<tbody>
						{#if !devItems.length}
							<tr><td colspan="4" style="padding:14px 8px;text-align:center;color:#aaa;font-style:italic">Todos los ítems ya fueron devueltos</td></tr>
						{:else}
							{#each devItems as it, i (it.producto_id)}
								<tr style="border-bottom:1px solid #f0f0f0">
									<td style="padding:7px 8px">{it.nombre}</td>
									<td style="padding:7px 8px;text-align:right;color:#666">
										{it.vendido % 1 === 0 ? it.vendido : it.vendido.toFixed(2)}
										{#if it.devuelto > 0}<br /><span style="font-size:10px;color:#aaa">ya devuelto: {it.devuelto}</span>{/if}
									</td>
									<td style="padding:7px 8px;text-align:center">
										<input type="number" min="0" max={it.disponible} step={it.vendido % 1 === 0 ? 1 : 0.01} style="width:70px;text-align:center;padding:4px 6px" bind:value={devItems[i].cant} />
									</td>
									<td style="padding:7px 8px;text-align:right">{fmt(it.precio)}</td>
								</tr>
							{/each}
						{/if}
					</tbody>
				</table>
				<div class="form-group" style="margin-bottom:10px">
					<label class="form-label" for="dev-motivo">Motivo (opcional)</label>
					<input type="text" id="dev-motivo" class="form-input" placeholder="Ej: producto defectuoso, cambio de talle…" bind:value={devMotivo} />
				</div>
				<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;font-size:13px">Crédito a acreditar en CC: <strong style="font-size:15px">{fmt(devTotal)}</strong></div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-sec" onclick={cerrarDev}>Cancelar</button>
				<button class="btn btn-ok" disabled={!devItems.length || devConfirmando} onclick={confirmarDevolucion}>{devConfirmando ? 'Procesando…' : 'Confirmar devolución'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal Nota de Crédito -->
{#if ncAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarNc()}>
		<div class="modal" style="width:560px;max-width:96vw" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Nota de crédito</h2>
				<button class="modal-close" onclick={cerrarNc}>×</button>
			</div>
			<div class="modal-body" style="padding:20px">
				<p style="margin:0 0 14px;font-size:13px;color:#666">Venta N° {ncVentaActual?.numero} · Cliente: {ncVentaActual?.cliente_nombre ?? '—'} · Total: {fmt(ncVentaActual?.total)}</p>
				<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px">
					<thead>
						<tr style="border-bottom:2px solid #eee;text-align:left">
							<th style="padding:6px 8px;font-weight:600">Producto</th>
							<th style="padding:6px 8px;font-weight:600;text-align:right">Vendido</th>
							<th style="padding:6px 8px;font-weight:600;text-align:center">Acreditar</th>
							<th style="padding:6px 8px;font-weight:600;text-align:right">Precio</th>
						</tr>
					</thead>
					<tbody>
						{#if !ncItems.length}
							<tr><td colspan="4" style="padding:14px 8px;text-align:center;color:#aaa;font-style:italic">Esta venta no tiene ítems</td></tr>
						{:else}
							{#each ncItems as it, i (it.producto_id)}
								<tr style="border-bottom:1px solid #f0f0f0">
									<td style="padding:7px 8px">{it.nombre}</td>
									<td style="padding:7px 8px;text-align:right;color:#666">{it.vendido % 1 === 0 ? it.vendido : it.vendido.toFixed(2)}</td>
									<td style="padding:7px 8px;text-align:center">
										<input type="number" min="0" max={it.disponible} step={it.vendido % 1 === 0 ? 1 : 0.01} style="width:70px;text-align:center;padding:4px 6px" bind:value={ncItems[i].cant} />
									</td>
									<td style="padding:7px 8px;text-align:right">{fmt(it.precio)}</td>
								</tr>
							{/each}
						{/if}
					</tbody>
				</table>
				<div class="form-group" style="margin-bottom:10px">
					<span class="form-label">Acreditar como</span>
					<div style="display:flex;gap:14px;margin-top:4px">
						<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="radio" name="nc-forma" value="caja" bind:group={ncForma} /> Descontar de caja</label>
						<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="radio" name="nc-forma" value="cc" bind:group={ncForma} /> Cuenta corriente del cliente</label>
					</div>
				</div>
				{#if ncForma === 'caja'}
					<div class="form-group" style="margin-bottom:10px">
						<label class="form-label" for="nc-medio">Medio de pago</label>
						<select id="nc-medio" class="form-input" bind:value={ncMedioPago}>
							<option value="efectivo">Efectivo</option>
							<option value="transferencia">Transferencia</option>
							<option value="tarjeta">Tarjeta</option>
							<option value="mercado_pago">Mercado Pago</option>
						</select>
					</div>
				{:else if !ncVentaActual?.cliente_id}
					<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:6px;padding:8px 12px;font-size:12px;margin-bottom:10px">Esta venta no tiene cliente asignado — no se puede acreditar en cuenta corriente.</div>
				{/if}
				<div class="form-group" style="margin-bottom:10px">
					<label class="form-label" for="nc-motivo">Motivo (opcional)</label>
					<input type="text" id="nc-motivo" class="form-input" placeholder="Ej: producto defectuoso, corrección de precio…" bind:value={ncMotivo} />
				</div>
				<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;font-size:13px">Total de la NC: <strong style="font-size:15px">{fmt(ncTotal)}</strong> — se emite con CAE por ARCA, restaura el stock acreditado y {ncForma === 'caja' ? 'descuenta de la caja actual' : 'se acredita en la cuenta corriente del cliente'}.</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-sec" onclick={cerrarNc}>Cancelar</button>
				<button class="btn btn-ok" disabled={!ncItems.length || ncConfirmando || ncTotal <= 0} onclick={confirmarNc}>{ncConfirmando ? 'Emitiendo…' : 'Emitir NC'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal Nota de Envío -->
{#if neAbierto}
	<div id="ne-overlay" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarNE()}>
		<div class="ne-modal" role="dialog" aria-modal="true">
			<div class="ne-header">
				<h3>Nota de envío</h3>
				<button class="ne-header-cerrar" aria-label="Cerrar" onclick={cerrarNE}>✕</button>
			</div>
			<div class="ne-body">
				{#if nePrevias.length}
					<div>
						<span class="ne-section">Entregas anteriores</span>
						<div style="margin-top:10px">
							{#each nePrevias as nota (nota.id)}
								{@const fechaEm = nota.fecha_emision ? new Date(nota.fecha_emision + 'T00:00:00').toLocaleDateString('es-AR') : '—'}
								{@const fechaEnt = nota.fecha_entrega ? new Date(nota.fecha_entrega + 'T00:00:00').toLocaleDateString('es-AR') : null}
								<div class="ne-previas-bloque">
									<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
										<strong>Entrega N° {nota.numero} — {fechaEm}{fechaEnt ? ' · entrega: ' + fechaEnt : ''}{nota.transportista ? ' · ' + nota.transportista : ''}</strong>
										<button style="font-size:11px;padding:3px 9px;border:1px solid var(--borde-fuerte);background:#fff;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0" onclick={() => verPdfNotaEnvio(nota.id)}>Ver PDF</button>
									</div>
									<ul class="ne-previas-items">
										{#each nota.items as it, i (i)}<li>{it.nombre}{#if it.codigo} <small>({it.codigo})</small>{/if} — <strong>{fmtCant(it.cantidad)}</strong></li>{/each}
									</ul>
								</div>
							{/each}
						</div>
					</div>
				{/if}

				<div>
					<span class="ne-section">Datos de envío</span>
					<div class="ne-row2" style="margin-top:10px">
						<div class="ne-group">
							<label class="ne-label" for="ne-transportista">Transportista</label>
							<input class="ne-input" type="text" id="ne-transportista" placeholder="Nombre del transportista o empresa" bind:value={neTransportista} />
						</div>
						<div class="ne-group">
							<label class="ne-label" for="ne-fecha-entrega">Fecha de entrega</label>
							<input class="ne-input" type="date" id="ne-fecha-entrega" bind:value={neFechaEntrega} />
						</div>
						<div class="ne-group">
							<label class="ne-label" for="ne-envio-precio">Costo de envío</label>
							<input class="ne-input" type="number" id="ne-envio-precio" placeholder="$ 0,00" min="0" step="any" bind:value={neEnvioPrecio} />
						</div>
						<div class="ne-group">
							<label class="ne-label" for="ne-envio-dir">Dirección de entrega</label>
							<input class="ne-input" type="text" id="ne-envio-dir" placeholder="Calle, número, piso..." bind:value={neEnvioDir} />
						</div>
					</div>
				</div>

				{#if neBillingVisible}
					<div class="ne-billing visible">
						<span class="ne-billing-label">Registrar costo de envío como</span>
						<div class="ne-billing-radios">
							<label class="ne-radio-lbl" class:activo={neAccion === ''}><input type="radio" name="ne-accion" value="" bind:group={neAccion} /> Sin registrar</label>
							<label class="ne-radio-lbl" class:activo={neAccion === 'caja'}><input type="radio" name="ne-accion" value="caja" bind:group={neAccion} /> Agregar a caja</label>
							<label class="ne-radio-lbl" class:activo={neAccion === 'cc'}><input type="radio" name="ne-accion" value="cc" bind:group={neAccion} /> Cuenta corriente</label>
						</div>
						{#if neAccion === 'caja'}
							<div class="ne-billing-sub" style="display:flex">
								<div class="ne-group">
									<label class="ne-label" for="ne-medio">Medio de pago</label>
									<select id="ne-medio" class="ne-input" bind:value={neMedioPago}>
										<option value="efectivo">Efectivo</option>
										<option value="transferencia">Transferencia</option>
										<option value="tarjeta">Tarjeta</option>
										<option value="mercado_pago">Mercado Pago</option>
									</select>
								</div>
								<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding-bottom:2px">
									<input type="checkbox" bind:checked={neGenerarAfip} /> Generar comprobante AFIP (CAE)
								</label>
								{#if neGenerarAfip}
									<div class="ne-group">
										<label class="ne-label" for="ne-tipo-cbte">Tipo comprobante</label>
										<select id="ne-tipo-cbte" class="ne-input" bind:value={neTipoCbte}>
											<option value="FC B-ELECT">FC B-ELECT</option>
											<option value="FC A-ELECT">FC A-ELECT</option>
											<option value="FC C-ELECT">FC C-ELECT</option>
										</select>
									</div>
								{/if}
							</div>
						{/if}
					</div>
				{/if}

				<div>
					<span class="ne-section">Artículos a entregar</span>
					<div style="margin-top:10px; overflow-x:auto">
						<table class="ne-tabla">
							<thead><tr><th style="width:32px"></th><th>Producto</th><th class="r">Cant. remito</th><th class="r">Ya enviado</th><th class="r">Pendiente</th><th class="r">Esta entrega</th></tr></thead>
							<tbody>
								{#if !neVentaItems.some((it) => it.pendiente > 0)}
									<tr><td colspan="6" style="text-align:center;color:#9B9590;padding:14px">Todos los artículos de este remito ya fueron entregados.</td></tr>
								{/if}
								{#each neVentaItems as item, idx (item.id)}
									{@const entregado = item.pendiente === 0}
									<tr>
										<td style="text-align:center"><input type="checkbox" checked={item.incluido} disabled={entregado} onchange={(e) => onNeChkChange(idx, (e.target as HTMLInputElement).checked)} /></td>
										<td class={entregado ? 'ne-entregado' : ''}>{item.nombre}{#if item.codigo} <small style="color:#9B9590">{item.codigo}</small>{/if}</td>
										<td class="r">{fmtCant(item.cantidad)}</td>
										<td class="r">{item.enviado > 0 ? fmtCant(item.enviado) : '—'}</td>
										<td class="r {entregado ? 'ne-entregado' : ''}">{entregado ? '✓ Completo' : fmtCant(item.pendiente)}</td>
										<td class="r"><input class="ne-cant-input" type="number" min="0" max={item.pendiente} step="any" disabled={entregado || !item.incluido} style={entregado || !item.incluido ? 'opacity:.4' : ''} bind:value={neVentaItems[idx].cant} /></td>
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
				</div>

				<div class="ne-group">
					<label class="ne-label" for="ne-obs">Observaciones</label>
					<textarea class="ne-textarea" id="ne-obs" rows="2" placeholder="Notas adicionales..." bind:value={neObservaciones}></textarea>
				</div>
			</div>
			<div class="ne-footer">
				<button class="ne-btn ne-btn-sec" onclick={cerrarNE}>Cancelar</button>
				<button class="ne-btn ne-btn-ok" disabled={neGenerando} onclick={generarNotaEnvio}>{neGenerando ? 'Generando…' : 'Generar nota'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal Confirmar Presupuesto -->
{#if cpAbierto && cpVenta}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarCp()}>
		<div class="modal" style="max-width:480px" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Confirmar presupuesto N° {cpVenta.numero}</h2>
				<button class="modal-close" aria-label="Cerrar" onclick={cerrarCp}>×</button>
			</div>
			<div class="modal-body">
				<div class="cp-section">
					<span class="cp-lbl">Convertir en</span>
					<div class="cp-tipo-grid">
						<button class="cp-tipo-btn" class:activo={cpTipoComp === 'REMITO'} onclick={() => cpElegirTipo('REMITO')}>Remito</button>
						<button class="cp-tipo-btn" class:activo={cpTipoComp === 'FC B-ELECT'} onclick={() => cpElegirTipo('FC B-ELECT')}>Factura B</button>
						<button class="cp-tipo-btn" class:activo={cpTipoComp === 'FC A-ELECT'} onclick={() => cpElegirTipo('FC A-ELECT')}>Factura A</button>
						<button class="cp-tipo-btn" class:activo={cpTipoComp === 'FC C-ELECT'} onclick={() => cpElegirTipo('FC C-ELECT')}>Factura C</button>
					</div>
				</div>

				<div class="cp-section">
					<span class="cp-lbl">Cliente</span>
					<div class="cp-cli-row">
						<div class="cp-cli-nombre">{cpClienteNom}</div>
						<button class="cp-cli-btn" onclick={cpToggleBuscarCliente}>Cambiar</button>
						<button class="cp-cli-btn" onclick={cpNuevoCliente}>+ Nuevo</button>
					</div>
					{#if cpCliBuscando}
						<div class="cp-cli-search-wrap">
							<input type="text" placeholder="Buscar cliente..." autocomplete="off" spellcheck="false" bind:value={cpCliInput} oninput={onCpCliInput} />
							{#if cpCliDdVisible}
								<div class="cp-cli-dd visible">
									{#if !cpCliResultados.length}
										<div class="cp-cli-dd-item" style="color:#9B9590">Sin resultados</div>
									{:else}
										{#each cpCliResultados as c (c.id)}<div class="cp-cli-dd-item" onclick={() => cpSeleccionarCliente(c)} onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cpSeleccionarCliente(c); } }} role="button" tabindex="-1">{c.nombre}</div>{/each}
									{/if}
								</div>
							{/if}
						</div>
					{/if}
				</div>

				<div class="cp-section">
					<span class="cp-lbl">Forma de pago</span>
					{#if !cpEsMixto}
						<div class="cp-pago-grid" id="cp-pago-grid">
							{#each CP_TIPOS_PAGO as t (t.val)}<button class="cp-pago-btn" class:activo={cpPago === t.val} onclick={() => cpElegirPago(t.val)}>{t.lbl}</button>{/each}
						</div>
						<button class="cp-mixto-link" onclick={cpActivarMixto}>+ Pago mixto</button>
					{:else}
						<div class="cp-mixto-wrap visible">
							<div>
								{#each cpMixtoLineas as l, i (i)}
									<div class="cp-mixto-linea">
										<select bind:value={cpMixtoLineas[i].tipo}>
											{#each CP_TIPOS_PAGO as t (t.val)}<option value={t.val}>{t.lbl}</option>{/each}
										</select>
										<input type="number" bind:value={cpMixtoLineas[i].monto} placeholder="0,00" min="0" step="any" />
										<button title="Autocompletar" onclick={() => cpAutoLinea(i)}>↑</button>
										{#if cpMixtoLineas.length > 1}<button onclick={() => cpQuitarLinea(i)}>×</button>{/if}
									</div>
								{/each}
							</div>
							<div class="cp-mixto-footer">
								<button onclick={cpAgregarLinea}>+ Agregar forma de pago</button>
								<span class="cp-mixto-estado" class:ok={Math.abs(cpRestante) < 0.005} class:err={Math.abs(cpRestante) >= 0.005}>
									{Math.abs(cpRestante) < 0.005 ? 'Total cubierto ✓' : cpRestante > 0 ? `Restante: ${fmt(cpRestante)}` : `Excede por: ${fmt(-cpRestante)}`}
								</span>
							</div>
						</div>
					{/if}
				</div>

				{#if !cpEsMixto && cpPago === 'cheque'}
					<div class="cp-section">
						<span class="cp-lbl">Datos del cheque</span>
						<div class="cp-chq-grid">
							<div><span class="cp-chq-lbl">N° de cheque *</span><input type="text" class="form-input" placeholder="00123456" autocomplete="off" bind:value={cpChqNumero} /></div>
							<div><span class="cp-chq-lbl">Banco *</span><input type="text" class="form-input" placeholder="Ej: Galicia" autocomplete="off" bind:value={cpChqBanco} /></div>
							<div><span class="cp-chq-lbl">Librador</span><input type="text" class="form-input" placeholder="Nombre del firmante" autocomplete="off" bind:value={cpChqLibrador} /></div>
							<div><span class="cp-chq-lbl">CUIT</span><input type="text" class="form-input" placeholder="20-12345678-9" autocomplete="off" bind:value={cpChqCuit} /></div>
							<div><span class="cp-chq-lbl">Fecha de emisión</span><input type="date" class="form-input" bind:value={cpChqEmision} /></div>
							<div><span class="cp-chq-lbl">Fecha de vencimiento *</span><input type="date" class="form-input" bind:value={cpChqVenc} /></div>
						</div>
					</div>
				{/if}

				{#if cpTipoComp !== 'REMITO'}
					<div style="display:block;padding:8px 10px;background:#FEF9C3;border:1px solid #FDE047;font-size:12px;color:#78350F;margin-bottom:4px">
						Se actualizará el comprobante y luego se solicitará el CAE a ARCA. Asegurate de tener conexión.
					</div>
				{/if}
			</div>
			<div class="modal-footer">
				<button class="btn-modal btn-modal-ghost" onclick={cerrarCp}>Cancelar</button>
				<button class="btn-modal btn-modal-primary" disabled={cpGuardando} onclick={cpGuardar}>{cpTexto}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal unificar -->
{#if uniAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarUni()}>
		<div class="modal" style="max-width:520px" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Unificar comprobantes</h2>
				<button class="modal-close" aria-label="Cerrar" onclick={cerrarUni}>×</button>
			</div>
			<div class="modal-body">
				<div class="uni-resumen">
					<div class="uni-resumen-titulo">Estás por unificar {uniLista.length} comprobante{uniLista.length !== 1 ? 's' : ''}</div>
					<div class="uni-resumen-fila"><span class="lbl">Cliente</span><span class="val">{uniLista[0]?.cliente_nombre ?? '— Consumidor final —'}</span></div>
					<div class="uni-resumen-fila">
						<span class="lbl">Período</span>
						<span class="val">{fmtFechaCorta(uniLista[0]?.fecha) === fmtFechaCorta(uniLista[uniLista.length - 1]?.fecha) ? fmtFechaCorta(uniLista[0]?.fecha) : `${fmtFechaCorta(uniLista[0]?.fecha)} → ${fmtFechaCorta(uniLista[uniLista.length - 1]?.fecha)}`}</span>
					</div>
					<div class="uni-resumen-total"><span>Total unificado</span><span>{fmt(uniTotal)}</span></div>
				</div>
				<div class="uni-lista">
					{#each uniLista as v (v.id)}
						<div class="uni-fila">
							<span class="tipo-badge {tipoBadgeClass(v.tipo_comprobante)}">{v.tipo_comprobante}</span>
							<span>N° {v.numero}</span>
							<span class="uni-fecha">{fmtFechaCorta(v.fecha)}</span>
							<span class="uni-cli">{v.cliente_nombre ?? '— Consumidor final —'}</span>
							<span class="r">{fmt(v.total)}</span>
						</div>
					{/each}
				</div>
				<div class="uni-pago-wrap">
					<label for="uni-tipo-pago">Forma de pago del remito unificado</label>
					<select id="uni-tipo-pago" bind:value={uniTipoPago}>
						<option value="efectivo">Efectivo</option>
						<option value="transferencia">Transferencia</option>
						<option value="cc">Cta. Corriente</option>
						<option value="tarjeta">Tarjeta</option>
						<option value="cheque">Cheque</option>
					</select>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn-modal btn-modal-ghost" onclick={cerrarUni}>Cancelar</button>
				<button class="btn-modal btn-modal-primary" disabled={uniConfirmando} onclick={confirmarUnificar}>{uniConfirmando ? 'Unificando...' : 'Confirmar unificación'}</button>
			</div>
		</div>
	</div>
{/if}

<style>
	/* Caso real (13/08/2026): con flex-wrap:wrap, en ventanas angostas la
	   fila desbordada de controles pasaba a un segundo renglón que quedaba
	   tapado por la barra de selección (siguiente hermano en el flujo
	   normal) — controles enteros (Agrupar, Mostrar anuladas, Exportar)
	   ocultos sin ningún aviso. Fix: .filtros-bar nunca wrappea ni scrollea
	   ella misma (así el dropdown de "Más opciones", position:absolute,
	   nunca queda cortado por un overflow del contenedor) — el que
	   scrollea horizontalmente es el sub-wrapper .fbar-scroll, que agrupa
	   los filtros; el resultado, actualizar y "más opciones" quedan fijos
	   a la derecha, siempre visibles. */
	.filtros-bar {
	  background: var(--neo-bg);
	  box-shadow: 0 -1px 4px var(--neo-sl);
	  padding: 0 16px;
	  display: flex; gap: 6px; align-items: center;
	  flex-shrink: 0; height: 50px; flex-wrap: nowrap;
	  position: relative; z-index: 10;
	}
	.fbar-scroll {
	  display: flex; gap: 6px; align-items: center;
	  flex: 1 1 auto; min-width: 0; height: 100%;
	  overflow-x: auto; overflow-y: hidden;
	  scrollbar-width: thin;
	}
	.fbar-sep { width: 1px; height: 18px; background: var(--neo-sd); opacity: .35; flex-shrink: 0; margin: 0 4px; }
	.fbar-ctrl {
	  height: 32px; padding: 0 10px;
	  border: none; border-radius: var(--neo-r-xs);
	  font-size: 12px; font-family: inherit;
	  background: var(--neo-bg); color: var(--neo-text); outline: none;
	  box-shadow: var(--neo-i1);
	  transition: box-shadow var(--neo-t-fast);
	}
	.fbar-ctrl:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	select.fbar-ctrl { cursor: pointer; }
	.fbar-rango-btn { cursor: pointer; white-space: nowrap; font-weight: 600; }
	.fbar-rango-btn:hover { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	#f-q-input { flex: 1; min-width: 80px; }
	.fbar-arrow { font-size: 12px; color: var(--neo-text-3); flex-shrink: 0; font-weight: 600; }
	.fbar-btn {
	  height: 32px; width: 32px;
	  border-radius: var(--neo-r-xs); border: none;
	  background: var(--neo-bg); color: var(--neo-text-2);
	  cursor: pointer; display: flex; align-items: center; justify-content: center;
	  flex-shrink: 0;
	  box-shadow: var(--neo-e1);
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.fbar-btn:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
	@keyframes spin-cw { to { transform: rotate(360deg); } }
	.fbar-btn-refresh.spinning svg { animation: spin-cw .65s linear infinite; transform-origin: center; }
	.fbar-btn-search { background: var(--neo-accent); color: white; box-shadow: 3px 3px 7px var(--neo-accent-glow), -1px -1px 4px rgba(255,255,255,.2); }
	.fbar-btn-search:hover { background: var(--neo-accent-h); }
	.fbar-chk {
	  display: flex; align-items: center; gap: 5px;
	  font-size: 10px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase;
	  color: var(--neo-text-3); cursor: pointer; white-space: nowrap; user-select: none; flex-shrink: 0;
	}
	.fbar-chk input[type=checkbox] { accent-color: var(--neo-danger); cursor: pointer; width: 13px; height: 13px; }
	.resultados-count { margin-left: auto; font-size: 11px; color: var(--neo-text-3); flex-shrink: 0; font-variant-numeric: tabular-nums; }

	.fbar-more-wrap { position: relative; flex-shrink: 0; }
	.fbar-btn-more { background: var(--neo-bg); color: var(--neo-text-2); box-shadow: var(--neo-e1); }
	.fbar-btn-more:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
	.fbar-btn-more.activo { box-shadow: var(--neo-e2), 0 0 0 2px var(--neo-accent); color: var(--neo-accent); }

	.fbar-more-panel {
	  display: none;
	  position: absolute; top: calc(100% + 8px); right: 0;
	  background: var(--neo-bg); border-radius: var(--neo-r-md);
	  box-shadow: var(--neo-e3); min-width: 200px; z-index: 50;
	  padding: 10px 12px; flex-direction: column; gap: 8px;
	}
	.fbar-more-panel.visible { display: flex; }
	.fbar-more-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.fbar-more-ctrl {
	  height: 32px; padding: 0 10px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 12px; font-family: inherit; background: var(--neo-bg); color: var(--neo-text);
	  box-shadow: var(--neo-i1); outline: none; cursor: pointer; width: 100%;
	}
	.fbar-more-sep { height: 1px; background: var(--neo-sd); opacity: .3; margin: 2px 0; }
	.fbar-more-chk { font-size: 12px !important; text-transform: none !important; letter-spacing: 0 !important; }
	.fbar-more-export {
	  display: flex; align-items: center; gap: 7px;
	  padding: 7px 10px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-success); color: #fff; font-size: 12px; font-weight: 600;
	  font-family: inherit; cursor: pointer; width: 100%;
	  box-shadow: 2px 2px 6px var(--neo-success-glow);
	}
	.fbar-more-export:hover { background: var(--neo-success-h); }

	.f-cli-wrap { position: relative; }
	.f-cli-tag {
	  display: flex; align-items: center; gap: 6px;
	  background: rgba(231,76,60,.1); border-radius: var(--neo-r-xs); box-shadow: var(--neo-e1);
	  padding: 0 8px 0 10px; font-size: 12px; font-weight: 500; color: var(--neo-text);
	  height: 32px;
	}
	.f-cli-clear { background: none; border: none; cursor: pointer; color: var(--neo-danger); font-size: 17px; line-height: 1; padding: 0 2px; }
	.f-cli-dd {
	  position: absolute; top: calc(100% + 6px); left: 0; min-width: 260px;
	  background: var(--neo-bg); border: none;
	  border-radius: var(--neo-r-md); box-shadow: var(--neo-e3);
	  z-index: 200; max-height: 220px; overflow-y: auto; display: none;
	}
	.f-cli-dd.visible { display: block; }
	.f-cli-dd-item { padding: 9px 14px; cursor: pointer; border-bottom: 1px solid var(--borde); font-size: 12px; }
	.f-cli-dd-item:last-child { border-bottom: none; }
	.f-cli-dd-item:hover, .f-cli-dd-item.activo { background: rgba(255,255,255,.5); }
	.f-cli-dd-item .cn { font-weight: 600; color: var(--neo-text); }
	.f-cli-dd-item .cd { font-size: 11px; color: var(--neo-text-3); margin-top: 1px; }

	.meses-strip { display: flex; width: 100%; padding: 4px 0 8px; gap: 3px; align-items: center; flex-wrap: wrap; }
	.mes-sep { font-size: 10px; color: var(--neo-text-3); font-weight: 700; margin-right: 2px; text-transform: uppercase; letter-spacing: .4px; }
	.mes-chip {
	  padding: 3px 9px; border-radius: var(--neo-r-xs); border: none;
	  background: var(--neo-bg); font-size: 11px; font-weight: 600; cursor: pointer;
	  color: var(--neo-text-2); box-shadow: var(--neo-e1);
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	  text-transform: uppercase; letter-spacing: .3px; font-family: inherit;
	}
	.mes-chip:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
	.mes-chip.activo { box-shadow: var(--neo-i1); color: var(--neo-accent); }
	.mes-chip.futuro { opacity: .35; cursor: not-allowed; pointer-events: none; }

	.seleccion-bar {
	  display: flex; flex-shrink: 0;
	  background: var(--color-primary-dark);
	  color: white;
	  padding: 0 16px;
	  align-items: center; gap: 0;
	  border-bottom: 1px solid var(--borde-fuerte);
	}
	.selec-left { flex: 1; min-width: 0; overflow: hidden; }
	.selec-placeholder { font-size: 12px; color: rgba(255,255,255,.28); padding: 13px 0; font-style: italic; }
	.seleccion-bar.sin-sel .selec-sep,
	.seleccion-bar.sin-sel .selec-cerrar { opacity: 0; pointer-events: none; }
	.selec-info { display: flex; align-items: center; gap: 12px; padding: 8px 0; min-width: 0; overflow: hidden; }
	.selec-numero { font-family: monospace; font-size: 12px; color: rgba(255,255,255,.5); flex-shrink: 0; }
	.selec-badge { flex-shrink: 0; }
	.selec-cliente { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
	.selec-monto { font-size: 16px; font-weight: 800; color: white; flex-shrink: 0; font-variant-numeric: tabular-nums; }
	.selec-fecha { font-size: 12px; color: rgba(255,255,255,.4); flex-shrink: 0; }
	.selec-sep { width: 1px; background: rgba(255,255,255,.12); height: 32px; margin: 0 12px; flex-shrink: 0; }
	.selec-acciones { display: flex; align-items: center; gap: 4px; flex-shrink: 0; padding: 6px 0; flex-wrap: wrap; }
	.selec-btn {
	  display: flex; align-items: center; gap: 5px;
	  padding: 6px 12px; border-radius: var(--neo-r-xs); border: none; cursor: pointer;
	  font-family: inherit; font-size: 12px; font-weight: 600;
	  transition: background var(--neo-t-fast), transform var(--neo-t-fast), opacity var(--neo-t-fast);
	  white-space: nowrap;
	}
	.selec-btn:active { transform: scale(.96); }
	.selec-btn:disabled { opacity: .4; cursor: not-allowed; }
	.sbtn-ghost { background: rgba(255,255,255,.08); color: rgba(255,255,255,.85); }
	.sbtn-ghost:hover { background: rgba(255,255,255,.16); color: white; }
	.sbtn-danger { background: rgba(231,76,60,.25); color: #fca5a5; }
	.sbtn-danger:hover { background: rgba(231,76,60,.45); color: white; }
	.selec-cerrar {
	  background: none; border: none; cursor: pointer;
	  color: rgba(255,255,255,.3); font-size: 20px; line-height: 1;
	  padding: 4px 6px; margin-left: 4px; border-radius: var(--neo-r-xs);
	  transition: color var(--neo-t-fast);
	}
	.selec-cerrar:hover { color: white; }

	.comp-dd-wrap { position: relative; }
	.comp-dd {
	  position: absolute; top: calc(100% + 4px); left: 0;
	  background: var(--neo-text); border-radius: var(--neo-r-md);
	  box-shadow: var(--neo-e4); min-width: 175px; overflow: hidden;
	  pointer-events: none; visibility: hidden;
	  opacity: 0; transform: scale(.96) translateY(-4px);
	  transform-origin: top left;
	  transition: opacity 150ms cubic-bezier(0.23,1,0.32,1), transform 150ms cubic-bezier(0.23,1,0.32,1), visibility 0ms 150ms;
	  z-index: 300;
	}
	.comp-dd.visible {
	  pointer-events: all; visibility: visible;
	  opacity: 1; transform: scale(1) translateY(0);
	  transition: opacity 150ms cubic-bezier(0.23,1,0.32,1), transform 150ms cubic-bezier(0.23,1,0.32,1), visibility 0ms 0ms;
	}
	.comp-dd-item {
	  display: flex; align-items: center; gap: 9px; width: 100%;
	  padding: 9px 14px; border: none; background: none; cursor: pointer;
	  font-family: inherit; font-size: 12px; font-weight: 500;
	  color: rgba(255,255,255,.82); transition: background var(--neo-t-fast); text-align: left;
	}
	.comp-dd-item + .comp-dd-item { border-top: 1px solid rgba(255,255,255,.06); }
	.comp-dd-item:hover { background: rgba(255,255,255,.1); color: white; }

	.tabla-wrap { flex: 1; overflow-y: auto; background: var(--neo-bg-deep); }
	table { width: 100%; border-collapse: collapse; }
	thead th {
	  position: sticky; top: 0; background: var(--neo-bg-deep);
	  padding: 10px 14px; text-align: left;
	  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--color-ink);
	  border-bottom: 1px solid var(--borde-fuerte);
	}
	thead th.r { text-align: right; }
	tbody tr { cursor: pointer; transition: background 80ms ease-out; border-bottom: 1px solid var(--borde); }
	tbody tr:hover { background: var(--color-bg-alt); }
	tbody tr.seleccionada { background: var(--primary-soft-2) !important; outline: 2px solid var(--neo-accent); outline-offset: -1px; }
	tbody td { padding: 9px 14px; color: var(--neo-text); }
	tbody td.r { text-align: right; }
	.fecha-cell { font-family: monospace; font-size: 12px; white-space: nowrap; color: var(--neo-text); }
	.comp-cell { white-space: nowrap; }
	.comp-num { font-family: monospace; font-size: 11px; color: var(--neo-text-3); margin-left: 7px; vertical-align: middle; }
	.obs-cell { font-size: 12px; color: var(--neo-text-2); max-width: 180px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
	.obs-vacia { color: var(--neo-text-3); }
	.tipo-badge { display: inline-block; padding: 2px 8px; border-radius: var(--neo-r-pill); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; box-shadow: var(--neo-e1); }
	.seleccion-bar .tipo-badge, .tabla-wrap .tipo-badge { box-shadow: none; }
	.tipo-REMITO { background: rgba(243,156,18,.12); color: var(--neo-warning); }
	.tipo-FC { background: rgba(231,76,60,.1); color: var(--neo-danger); }
	.tipo-NC { background: rgba(236,72,153,.1); color: #ec4899; }
	.tipo-PRES { background: var(--color-bg-alt); color: var(--neo-text-2); }
	.pago-badge { font-size: 11px; color: var(--neo-text-3); }
	.total-cell { font-weight: 700; color: var(--neo-accent); font-variant-numeric: tabular-nums; }
	.fila-anulada td { opacity: .5; }
	.fila-anulada .comp-num { text-decoration: line-through; }
	.badge-anulada { display: inline-block; padding: 1px 6px; border-radius: var(--neo-r-pill); font-size: 10px; font-weight: 700; text-transform: uppercase; background: rgba(231,76,60,.12); color: var(--neo-danger); margin-left: 5px; vertical-align: middle; }
	.cae-badge { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; font-size: 10px; font-weight: 800; margin-left: 5px; vertical-align: middle; flex-shrink: 0; border: none; padding: 0; font-family: inherit; }
	button.cae-badge { cursor: pointer; }
	.cae-ok { background: rgba(39,174,96,.15); color: var(--neo-success); }
	.cae-err { background: rgba(231,76,60,.15); color: var(--neo-danger); }
	.cae-pend { background: var(--color-bg-alt); color: var(--neo-text-3); }

	.grupo-sep td { height: 8px; background: var(--neo-bg-deep); border: none !important; padding: 0; }
	.grupo-header-row td {
	  padding: 7px 14px; background: var(--neo-bg); font-weight: 700; font-size: 11px;
	  text-transform: uppercase; letter-spacing: .4px; color: var(--neo-text-2);
	  border-top: 1px solid var(--borde-fuerte); border-bottom: 1px solid var(--borde-fuerte);
	  position: sticky; top: 38px;
	}
	.g-stats { margin-left: 10px; font-size: 11px; color: var(--neo-text-3); font-weight: 500; text-transform: none; letter-spacing: 0; }
	.g-total { float: right; color: var(--neo-text); font-weight: 700; text-transform: none; letter-spacing: 0; font-variant-numeric: tabular-nums; }

	tr.comp-remito td { background: rgba(243,156,18,.06); }
	tr.comp-factura td { background: rgba(231,76,60,.05); }
	tr.comp-presupuesto td { background: var(--color-bg-alt); }
	tr.comp-nc td { background: rgba(236,72,153,.05); }
	tr.comp-remito.seleccionada td { background: rgba(243,156,18,.14); }
	tr.comp-factura.seleccionada td { background: rgba(231,76,60,.12); }
	tr.comp-presupuesto.seleccionada td { background: var(--color-bg-alt); }
	tr.comp-nc.seleccionada td { background: rgba(236,72,153,.12); }
	.estado-vacio { padding: 60px 20px; text-align: center; color: var(--neo-text-3); }

	.overlay {
	  display: none; position: fixed; inset: 0; background: transparent;
	  z-index: 500; align-items: flex-start; justify-content: center; padding-top: 40px;
	}
	.overlay.abierto { display: flex; background: rgba(49,52,75,.48); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
	.modal {
	  background: var(--neo-bg);
	  border-radius: var(--neo-r-xl);
	  box-shadow: var(--neo-e4);
	  width: min(720px,95vw); max-height: calc(100vh - 80px); display: flex;
	  flex-direction: column; overflow: hidden;
	}
	.modal-sm { width: min(480px,95vw); }
	.modal-head { padding: 16px 22px; border-bottom: 1px solid var(--borde-fuerte); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
	.modal-head h2 { flex: 1; font-size: 15px; font-weight: 700; color: var(--neo-text); }
	.modal-close {
	  width: 30px; height: 30px; border: none; cursor: pointer;
	  border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); box-shadow: var(--neo-e1);
	  color: var(--neo-text-2); font-size: 18px; line-height: 1;
	  display: flex; align-items: center; justify-content: center;
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.modal-close:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
	.modal-body { overflow-y: auto; padding: 20px 22px; flex: 1; }

	.comp-header { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
	.comp-bloque label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); display: block; margin-bottom: 4px; }
	.comp-bloque .val { font-size: 14px; font-weight: 500; color: var(--neo-text); }
	.comp-items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
	.comp-items th { padding: 8px 10px; text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--color-ink); border-bottom: 1px solid var(--borde-fuerte); }
	.comp-items th.r { text-align: right; }
	.comp-items td { padding: 9px 10px; border-bottom: 1px solid var(--borde); font-size: 13px; color: var(--neo-text); }
	.comp-items td.r { text-align: right; font-variant-numeric: tabular-nums; }
	.comp-items .cod { font-family: monospace; font-size: 11px; color: var(--neo-text-3); }
	.comp-total { display: flex; justify-content: flex-end; align-items: center; gap: 16px; padding-top: 14px; border-top: 1px solid var(--borde-fuerte); }
	.comp-total .label { font-size: 12px; color: var(--neo-text-3); }
	.comp-total .monto { font-size: 26px; font-weight: 800; color: var(--neo-accent); font-variant-numeric: tabular-nums; }

	.chk-col { width: 32px; padding: 0 4px !important; text-align: center; }
	:global(.uni-chk) { accent-color: var(--neo-accent); width: 14px; height: 14px; cursor: pointer; display: block; margin: auto; }
	.sbtn-primary { background: var(--neo-accent); color: white; }
	.sbtn-primary:hover { background: var(--neo-accent-h); }
	.sbtn-primary:disabled { background: rgba(255,255,255,.06); color: rgba(255,255,255,.3); cursor: not-allowed; }
	.uni-lista { margin-bottom: 16px; }
	.uni-fila {
	  display: flex; align-items: center; gap: 10px; padding: 8px 12px;
	  border-radius: var(--neo-r-sm); background: var(--neo-bg);
	  box-shadow: var(--neo-e1); margin-bottom: 6px; font-size: 12px;
	}
	.uni-fila .uni-fecha { color: var(--neo-text-3); font-size: 11px; }
	.uni-fila .uni-cli { flex: 1; color: var(--neo-text-2); font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	.uni-fila .r { font-weight: 700; margin-left: auto; flex-shrink: 0; color: var(--neo-text); font-variant-numeric: tabular-nums; }
	.uni-resumen { background: var(--primary-soft); border-radius: var(--neo-r-md); box-shadow: var(--neo-i1); padding: 12px 14px; margin-bottom: 14px; }
	.uni-resumen-titulo { font-size: 13px; font-weight: 700; color: var(--neo-accent); margin-bottom: 6px; }
	.uni-resumen-fila { display: flex; justify-content: space-between; align-items: baseline; font-size: 13px; color: var(--neo-text-2); padding: 2px 0; }
	.uni-resumen-fila .lbl { color: var(--neo-text-3); font-size: 12px; }
	.uni-resumen-fila .val { font-weight: 600; color: var(--neo-text); }
	.uni-resumen-total { border-top: 1px solid var(--primary-line); margin-top: 8px; padding-top: 8px; display: flex; justify-content: space-between; font-size: 15px; font-weight: 700; color: var(--neo-accent); font-variant-numeric: tabular-nums; }
	.uni-pago-wrap { margin-top: 14px; }
	.uni-pago-wrap label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 6px; }
	.uni-pago-wrap select { width: 100%; padding: 9px 10px; border: none; border-radius: var(--neo-r-sm); box-shadow: var(--neo-i1); font-size: 13px; font-family: inherit; outline: none; background: var(--neo-bg); color: var(--neo-text); }
	.origen-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: var(--neo-accent); background: var(--primary-soft-2); border-radius: var(--neo-r-xs); padding: 2px 7px; white-space: nowrap; }

	.ef-row { margin-bottom: 14px; }
	.ef-row label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 5px; }
	.ef-row select, .ef-row input[type=text], .ef-row input[type=email] {
	  width: 100%; padding: 9px 10px; border: none; border-radius: var(--neo-r-sm);
	  box-shadow: var(--neo-i1); font-size: 13px; font-family: inherit; outline: none;
	  background: var(--neo-bg); color: var(--neo-text); box-sizing: border-box;
	}
	.ef-row select:focus, .ef-row input:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.ef-row select:disabled { opacity: .45; cursor: not-allowed; }
	.ef-aviso { background: rgba(231,76,60,.1); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1); padding: 10px 12px; font-size: 12px; color: var(--neo-danger); margin-bottom: 14px; line-height: 1.5; }
	.modal-footer { padding: 14px 22px; border-top: 1px solid var(--borde-fuerte); display: flex; gap: 8px; align-items: center; justify-content: flex-end; flex-shrink: 0; }
	.btn-modal-link { background: none; border: none; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 600; color: var(--neo-text-3); padding: 9px 4px; margin-right: auto; transition: color var(--neo-t-fast); }
	.btn-modal-link:hover { color: var(--neo-accent); }
	.btn-modal {
	  display: inline-flex; align-items: center; gap: 6px;
	  padding: 9px 20px; border: none; border-radius: var(--neo-r-sm);
	  font-size: 13px; font-weight: 700; font-family: inherit;
	  cursor: pointer; letter-spacing: .3px; text-transform: uppercase;
	  transition: box-shadow var(--neo-t-fast), transform var(--neo-t-fast);
	}
	.btn-modal:active { transform: scale(.97); }
	.btn-modal-primary { background: var(--neo-accent); color: white; box-shadow: 4px 4px 10px var(--neo-accent-glow), -2px -2px 5px rgba(255,255,255,.2); }
	.btn-modal-primary:hover { background: var(--neo-accent-h); box-shadow: 6px 6px 14px var(--neo-accent-glow); }
	.btn-modal-primary:disabled { opacity: .42; cursor: not-allowed; }
	.btn-modal-ghost { background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-e2); }
	.btn-modal-ghost:hover { box-shadow: var(--neo-e3); }
	.btn-modal-danger { background: var(--neo-danger); color: white; box-shadow: 4px 4px 10px var(--neo-danger-glow), -2px -2px 5px rgba(255,255,255,.2); }
	.btn-modal-danger:hover { background: var(--neo-danger-h); }
	.btn-modal-danger:disabled { opacity: .42; cursor: not-allowed; }

	.del-body { padding: 20px 22px; }
	.del-titulo { font-size: 15px; font-weight: 700; margin-bottom: 8px; color: var(--neo-text); }
	.del-info { font-size: 12px; color: var(--neo-text-2); margin-bottom: 16px; line-height: 1.6; }
	.del-aviso { background: rgba(231,76,60,.1); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1); padding: 10px 12px; font-size: 12px; color: var(--neo-danger); line-height: 1.5; margin-bottom: 4px; }
	.del-clave { margin-top: 14px; }
	.del-clave label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--neo-text-2); }
	.del-clave input {
	  width: 100%; padding: 9px 12px; border: none; border-radius: var(--neo-r-sm);
	  box-shadow: var(--neo-i1); font-size: 14px; font-family: inherit; outline: none;
	  background: var(--neo-bg); color: var(--neo-text); box-sizing: border-box;
	}
	.del-clave input:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-danger); }
	.del-clave-error { color: var(--neo-danger); font-size: 12px; font-weight: 600; margin-top: 6px; min-height: 14px; }

	:global(#ne-overlay) {
	  position: fixed; inset: 0; background: rgba(0,0,0,.45);
	  display: flex; align-items: center; justify-content: center; z-index: 600;
	}
	:global(#ne-overlay .ne-modal) {
	  background: #fff; border: 1px solid var(--borde-fuerte); border-radius: 0;
	  width: min(96vw, 700px); max-height: 90vh;
	  display: flex; flex-direction: column;
	}
	.ne-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid var(--borde-fuerte); background: var(--color-bg-alt); flex-shrink: 0; }
	.ne-header h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #9B9590; margin: 0; }
	.ne-header-cerrar { background: none; border: none; cursor: pointer; color: #9B9590; font-size: 18px; line-height: 1; padding: 2px 6px; }
	.ne-header-cerrar:hover { color: #111; }
	.ne-body { padding: 18px 20px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 14px; }
	.ne-footer { padding: 12px 20px; border-top: 1px solid var(--borde-fuerte); display: flex; gap: 8px; justify-content: flex-end; flex-shrink: 0; }
	.ne-section { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #9B9590; padding-bottom: 6px; border-bottom: 1px solid var(--borde-fuerte); display: block; }
	.ne-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
	.ne-group { display: flex; flex-direction: column; gap: 4px; }
	.ne-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #9B9590; }
	.ne-input, .ne-textarea { padding: 8px 10px; border: 1px solid var(--borde-fuerte); border-radius: 0; font-size: 13px; font-family: inherit; outline: none; background: #fff; width: 100%; box-sizing: border-box; }
	.ne-input:focus, .ne-textarea:focus { border-color: var(--color-primary); }
	.ne-textarea { resize: vertical; min-height: 52px; }
	.ne-tabla { width: 100%; border-collapse: collapse; font-size: 12px; }
	.ne-tabla th { padding: 6px 8px; background: var(--color-bg-alt); border: 1px solid var(--borde-fuerte); font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #9B9590; text-align: left; white-space: nowrap; }
	.ne-tabla th.r, .ne-tabla td.r { text-align: right; }
	.ne-tabla td { padding: 7px 8px; border: 1px solid var(--borde-fuerte); vertical-align: middle; font-size: 13px; color: #111; }
	.ne-tabla tbody tr:nth-child(even) td { background: #FAFAF9; }
	.ne-tabla td.ne-entregado { color: #9B9590; font-style: italic; }
	.ne-cant-input { width: 74px; text-align: right; padding: 4px 7px; border: 1px solid var(--borde-fuerte); border-radius: 0; font-size: 13px; font-family: inherit; background: #fff; }
	.ne-cant-input:focus { outline: none; border-color: var(--color-primary); }
	.ne-previas-bloque { border: 1px solid var(--borde-fuerte); padding: 8px 12px; margin-bottom: 6px; background: #FAFAF9; font-size: 12px; }
	.ne-previas-items { margin: 5px 0 0 0; padding-left: 14px; color: #555; font-size: 12px; }
	.ne-btn { padding: 9px 18px; border: 1.5px solid transparent; border-radius: 0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; cursor: pointer; font-family: inherit; }
	.ne-btn-ok { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
	.ne-btn-sec { background: #fff; color: var(--color-primary); border-color: var(--color-primary); }
	.ne-billing { margin-top: 14px; padding: 12px; background: #F7F6F4; border: 1px solid #E5E3E0; }
	.ne-billing-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #9B9590; display: block; margin-bottom: 8px; }
	.ne-billing-radios { display: flex; gap: 6px; flex-wrap: wrap; }
	.ne-radio-lbl { display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer; padding: 5px 10px; border: 1px solid var(--borde-fuerte); background: #fff; }
	.ne-radio-lbl.activo { border-color: var(--color-primary); background: color-mix(in srgb, var(--color-primary) 8%, #fff); color: var(--color-primary); font-weight: 600; }
	.ne-billing-sub { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }

	.cp-section { margin-bottom: 18px; }
	.cp-lbl { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #9B9590; margin-bottom: 8px; }
	.cp-tipo-grid { display: flex; gap: 8px; flex-wrap: wrap; }
	.cp-tipo-btn { flex: 1; min-width: 100px; padding: 8px 10px; border: 1px solid var(--borde-fuerte); background: #fff; cursor: pointer; font-size: 13px; font-weight: 600; font-family: inherit; text-align: center; border-radius: 0; transition: border-color .15s, background .15s; }
	.cp-tipo-btn.activo { border-color: var(--neo-accent); background: color-mix(in srgb, var(--neo-accent) 8%, #fff); color: var(--neo-accent); }
	.cp-cli-row { display: flex; gap: 8px; align-items: center; }
	.cp-cli-nombre { flex: 1; padding: 7px 10px; border: 1px solid var(--borde-fuerte); background: #F7F6F4; font-size: 13px; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
	.cp-cli-btn { padding: 7px 12px; border: 1px solid var(--borde-fuerte); background: #fff; cursor: pointer; font-size: 12px; font-weight: 600; font-family: inherit; border-radius: 0; white-space: nowrap; }
	.cp-cli-btn:hover { background: var(--color-bg-alt); }
	.cp-cli-search-wrap { position: relative; margin-top: 8px; }
	.cp-cli-search-wrap input { width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid var(--borde-fuerte); font-size: 13px; font-family: inherit; outline: none; }
	.cp-cli-dd { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--borde-fuerte); border-top: none; z-index: 10; max-height: 180px; overflow-y: auto; display: none; }
	.cp-cli-dd.visible { display: block; }
	.cp-cli-dd-item { padding: 7px 10px; cursor: pointer; font-size: 13px; border-bottom: 1px solid var(--color-bg-alt); }
	.cp-cli-dd-item:hover { background: var(--color-bg-alt); }
	.cp-pago-grid { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
	.cp-pago-btn { flex: 1; min-width: 90px; padding: 7px 8px; border: 1px solid var(--borde-fuerte); background: #fff; cursor: pointer; font-size: 12px; font-family: inherit; border-radius: 0; }
	.cp-pago-btn.activo { border-color: var(--neo-accent); background: color-mix(in srgb, var(--neo-accent) 8%, #fff); color: var(--neo-accent); font-weight: 600; }
	.cp-mixto-wrap { display: none; }
	.cp-mixto-wrap.visible { display: block; }
	.cp-mixto-linea { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
	.cp-mixto-linea select, .cp-mixto-linea input { padding: 6px 8px; border: 1px solid var(--borde-fuerte); font-size: 13px; font-family: inherit; outline: none; border-radius: 0; }
	.cp-mixto-linea select { flex: 1; }
	.cp-mixto-linea input { width: 110px; }
	.cp-mixto-linea button { padding: 6px 9px; border: 1px solid var(--borde-fuerte); background: #fff; cursor: pointer; font-size: 14px; font-family: inherit; border-radius: 0; color: #9B9590; }
	.cp-mixto-linea button:hover { color: var(--rojo); }
	.cp-mixto-footer { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #9B9590; margin-top: 4px; }
	.cp-mixto-footer button { background: none; border: none; cursor: pointer; font-size: 12px; font-weight: 600; color: var(--neo-accent); padding: 0; font-family: inherit; }
	.cp-mixto-estado { font-weight: 700; }
	.cp-chq-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
	.cp-chq-grid .form-input { font-size: 12px; width: 100%; box-sizing: border-box; }
	.cp-chq-lbl { font-size: 10px; color: var(--neo-text-3); margin-bottom: 2px; display: block; }
	.cp-mixto-estado.ok { color: #166534; }
	.cp-mixto-estado.err { color: var(--rojo); }
	.cp-mixto-link { background: none; border: none; cursor: pointer; font-size: 12px; font-weight: 600; color: var(--neo-accent); padding: 0; font-family: inherit; text-decoration: underline; text-underline-offset: 2px; }

	.rango-form { display: flex; gap: 12px; }
	.rango-form .form-group { flex: 1; }
	.form-group { display: flex; flex-direction: column; gap: 4px; }
	.form-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.form-input {
	  padding: 8px 10px; border: none; border-radius: var(--neo-r-sm);
	  font-size: 13px; font-family: inherit; outline: none;
	  background: var(--neo-bg); box-shadow: var(--neo-i1); color: var(--neo-text);
	  box-sizing: border-box; width: 100%;
	}
	.form-hint { font-size: 11px; color: var(--neo-text-3); }
	.btn { padding: 9px 18px; border: none; border-radius: var(--neo-r-sm); font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
	.btn-sec { background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-e2); }
	.btn-ok { background: var(--neo-accent); color: white; box-shadow: 3px 3px 7px var(--neo-accent-glow); }
	.btn-ok:disabled { opacity: .5; cursor: not-allowed; }
</style>
