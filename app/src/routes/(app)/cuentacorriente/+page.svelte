<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { abrirPdf } from '$lib/pdf';
	import { abrirContacto } from '$lib/contact-modal';
	import { puede } from '$lib/session';
	import { cajaOperativaId } from '$lib/operativa';
	import { setTourSteps, type TourStep } from '$lib/tour';

	type ModoEntidad = 'cliente' | 'proveedor';

	type Cliente = {
		id: number;
		nombre: string;
		cuit: string | null;
		condicion_iva: string | null;
		email: string | null;
		telefono: string | null;
		domicilio: string | null;
		localidad: string | null;
		provincia: string | null;
		observaciones: string | null;
		limite_credito: number | null;
		plazo_pago_dias: number | null;
		saldo_cuenta_corriente: number;
	};

	type VentaPendiente = {
		id: number;
		fecha: string;
		saldo_pendiente: number;
		tipo_comprobante?: string | null;
		tipo_ref?: 'cargo' | string;
	};

	type Asignacion = { venta_id?: number; cargo_id?: number; monto: number };

	type Movimiento = {
		id: number;
		tipo: 'cargo' | 'pago';
		monto: number;
		fecha: string;
		referencia_id: number | null;
		ref_tipo?: string | null;
		observaciones?: string | null;
		medio_pago?: string | null;
		asignaciones?: Asignacion[];
		pago_datos?: Record<string, string | null> | null;
		comprobante?: string | null;
	};

	type AgingFila = {
		id: number;
		nombre: string;
		telefono?: string | null;
		plazo_pago_dias: number | null;
		a_vencer: number;
		v1_30: number;
		v31_60: number;
		v61_90: number;
		v90: number;
		vencido: number;
		saldo: number;
		max_dias_vencido: number;
	};

	// ── Estado general ───────────────────────────────────────────
	// $derived (no const) — el dropdown "Cta. Cte." del nav linkea a
	// /cuentacorriente?tipo=cliente y ?tipo=proveedor: como es la MISMA ruta,
	// SvelteKit no remonta el componente al pasar de uno a otro, solo cambia
	// el query string. Con `const` esto quedaba congelado en lo que fuera al
	// montar (caso real 15/08/2026) — con $derived se recalcula solo.
	const modoEntidad = $derived<ModoEntidad>(page.url.searchParams.get('tipo') === 'proveedor' ? 'proveedor' : 'cliente');
	const recurso = $derived(modoEntidad === 'proveedor' ? 'proveedores' : 'clientes');

	let clienteActual = $state<Cliente | null>(null);
	let ventasPendientes = $state<VentaPendiente[]>([]);
	let movsActuales = $state<Movimiento[]>([]);
	let vistaLibro = $state<'cargando' | 'vacio' | 'lista' | 'error'>('vacio');
	let libroError = $state('');

	// ── Búsqueda ─────────────────────────────────────────────────
	let inputBusq = $state('');
	let ddResultados = $state<Cliente[]>([]);
	let ddVisible = $state(false);
	let ddIdx = $state(-1);
	let busqTimer: ReturnType<typeof setTimeout>;

	function onInputBusq() {
		clearTimeout(busqTimer);
		const q = inputBusq.trim();
		if (q.length < 2) {
			ddVisible = false;
			ddResultados = [];
			return;
		}
		busqTimer = setTimeout(() => buscarClientes(q), 250);
	}

	async function buscarClientes(q: string) {
		try {
			const r = await api(`/${recurso}?q=${encodeURIComponent(q)}&limit=8`);
			if (!r.ok) {
				const d = await r.json().catch(() => ({}));
				throw new Error(d.error ?? `Error ${r.status} al buscar`);
			}
			const d = await r.json();
			if (!Array.isArray(d) || !d.length) {
				ddVisible = false;
				ddResultados = [];
				return;
			}
			ddIdx = -1;
			ddResultados = d;
			ddVisible = true;
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al buscar', 'err');
		}
	}

	function onBusqKeydown(e: KeyboardEvent) {
		if (!ddResultados.length) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			ddIdx = Math.min(ddIdx + 1, ddResultados.length - 1);
		}
		if (e.key === 'ArrowUp') {
			e.preventDefault();
			ddIdx = Math.max(ddIdx - 1, 0);
		}
		if (e.key === 'Enter') {
			e.preventDefault();
			const c = ddResultados[ddIdx] ?? ddResultados[0];
			if (c) seleccionarCliente(c);
		}
		if (e.key === 'Escape') cerrarDD();
	}

	function cerrarDD() {
		ddVisible = false;
		ddResultados = [];
		ddIdx = -1;
	}

	async function seleccionarCliente(c: { id: number; nombre: string }) {
		cerrarDD();
		inputBusq = c.nombre;
		await cargarCC(c.id);
	}

	// ── Cargar CC ────────────────────────────────────────────────
	async function cargarCC(entidadId: number) {
		vistaMostrandoLista = false;
		vistaLibro = 'cargando';
		try {
			const r = await api(`/cc?entidad_tipo=${modoEntidad}&entidad_id=${entidadId}`);
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al cargar');

			clienteActual = modoEntidad === 'proveedor' ? d.proveedor : d.cliente;
			ventasPendientes = modoEntidad === 'proveedor' ? d.compras_cc : d.ventas_cc;
			movsActuales = d.movimientos;
			vistaLibro = movsActuales.length ? 'lista' : 'vacio';

			cargarMiniAging(entidadId);
		} catch (e) {
			libroError = e instanceof Error ? e.message : 'Error al cargar';
			vistaLibro = 'error';
			toast_(libroError, 'err');
		}
	}

	// ── Utilidades de formato ────────────────────────────────────
	function fmt(n: number | string | null | undefined): string {
		return '$ ' + Number(n ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function pad(n: number | string | null | undefined): string {
		return String(n ?? '').padStart(8, '0');
	}
	function fmtFechaStr(s: string | null | undefined): string {
		if (!s) return '—';
		const [y, m, d] = s.split('-');
		return `${d}/${m}/${y}`;
	}
	function fmtFechaCorta(s: string | null | undefined): string {
		if (!s) return '—';
		const [, m, d] = s.split('-');
		return `${d}/${m}`;
	}
	function diasDesde(fechaStr: string): number {
		const f = new Date(fechaStr + 'T00:00:00');
		return Math.floor((new Date().setHours(0, 0, 0, 0) - f.getTime()) / 86400000);
	}
	function telWa(t: string | null | undefined): string | null {
		let d = String(t ?? '').replace(/\D/g, '');
		if (!d) return null;
		if (!d.startsWith('54')) d = '549' + d.replace(/^0/, '');
		return d;
	}
	function abrirReclamoWa(f: { nombre: string; telefono?: string | null; vencido: number; saldo: number; max_dias_vencido: number }) {
		const tel = telWa(f.telefono);
		if (!tel) {
			toast_('El cliente no tiene teléfono cargado en la ficha', 'err');
			return;
		}
		const dias = f.max_dias_vencido > 0 ? ` (el más antiguo venció hace ${f.max_dias_vencido} días)` : '';
		const msg =
			`Hola ${f.nombre}! Te escribimos. ` +
			`Registrás un saldo vencido de ${fmt(f.vencido)} en tu cuenta corriente${dias}. ` +
			`Saldo total: ${fmt(f.saldo)}. ¿Podés regularizarlo? ¡Muchas gracias!`;
		window.open(`https://wa.me/${tel}?text=${encodeURIComponent(msg)}`, '_blank');
	}

	async function verComprobantePago(url: string) {
		try {
			const r = await fetch(url);
			if (!r.ok) {
				toast_(r.status === 404 ? 'Comprobante no encontrado' : 'Error al abrir comprobante', 'err');
				return;
			}
			abrirPdf(await r.blob());
		} catch {
			toast_('Error de conexión', 'err');
		}
	}

	// ── Derivados: libro de movimientos con saldo corrido ──────────
	type FilaLibro = {
		mov: Movimiento;
		saldo: number;
		concepto: string;
		esHtml: boolean;
		obsLine: string;
		asignado: number;
		esDevolucion: boolean;
	};

	const asigPorVenta = $derived.by(() => {
		const m: Record<number, number> = {};
		for (const mv of movsActuales) {
			if (mv.tipo === 'pago' && mv.asignaciones) {
				for (const a of mv.asignaciones) {
					if (a.venta_id) m[a.venta_id] = (m[a.venta_id] || 0) + a.monto;
				}
			}
		}
		return m;
	});
	const asigPorCargo = $derived.by(() => {
		const m: Record<number, number> = {};
		for (const mv of movsActuales) {
			if (mv.tipo === 'pago' && mv.asignaciones) {
				for (const a of mv.asignaciones) {
					if (a.cargo_id) m[a.cargo_id] = (m[a.cargo_id] || 0) + a.monto;
				}
			}
		}
		return m;
	});

	const filasLibro = $derived.by((): FilaLibro[] => {
		let saldo = 0;
		const out: FilaLibro[] = [];
		for (const m of movsActuales) {
			saldo += m.tipo === 'cargo' ? m.monto : -m.monto;

			let concepto = '';
			let esHtml = false;
			let obsLine = '';
			if (m.tipo === 'cargo') {
				if (m.referencia_id && m.ref_tipo) concepto = `${m.ref_tipo} N°${pad(m.referencia_id)}`;
				else if (m.referencia_id) concepto = `${modoEntidad === 'proveedor' ? 'Compra' : 'Venta'} N°${pad(m.referencia_id)}`;
				else concepto = 'Cargo manual';
			} else {
				if (m.referencia_id) {
					concepto = 'Devolución';
					esHtml = true;
					if (m.observaciones) obsLine = m.observaciones;
				} else {
					concepto = 'Pago';
					esHtml = true;
					if (m.observaciones) obsLine = m.observaciones;
				}
			}

			const asignado =
				m.tipo !== 'cargo' ? 0 : (m.referencia_id ? asigPorVenta[m.referencia_id] || 0 : 0) + (asigPorCargo[m.id] || 0);
			const esDevolucion = m.tipo === 'pago' && !!m.referencia_id;

			out.push({ mov: m, saldo, concepto, esHtml, obsLine, asignado, esDevolucion });
		}
		return out;
	});

	function saldoClase(saldo: number): string {
		return saldo > 0.001 ? 'saldo-rojo' : saldo < -0.001 ? 'saldo-verde' : 'saldo-gris';
	}

	function onClickFilaCargo(f: FilaLibro) {
		if (f.mov.tipo === 'cargo' && f.mov.referencia_id) abrirModalRemito(f.mov);
	}
	function onClickFilaDev(f: FilaLibro) {
		if (f.esDevolucion && f.mov.referencia_id) abrirModalDevolucion(f.mov.referencia_id);
	}

	// ── Botón "Registrar pago" visible según permiso ────────────────
	const puedeCobrar = $derived(puede('cc_cobrar'));

	// ── Card cliente: clases de saldo / límite ──────────────────────
	const saldoClaseCard = $derived.by(() => {
		if (!clienteActual) return '';
		const s = clienteActual.saldo_cuenta_corriente;
		return s > 0.001 ? 'deuda' : s < -0.001 ? 'saldo-ok' : 'saldo-cero';
	});
	const limiteExcedido = $derived.by(() => {
		if (!clienteActual || modoEntidad === 'proveedor') return false;
		const lim = clienteActual.limite_credito;
		return !!lim && lim > 0 && clienteActual.saldo_cuenta_corriente > lim + 0.001;
	});

	// ── Deuda pendiente por comprobante ──────────────────────────────
	const totalDeuda = $derived(ventasPendientes.reduce((s, v) => s + v.saldo_pendiente, 0));

	function vencInfo(v: VentaPendiente): { texto: string; clase: string; filaVencida: boolean } {
		const plazo = clienteActual?.plazo_pago_dias ?? null;
		if (plazo === null) return { texto: '—', clase: 'gris', filaVencida: false };
		const diasVenc = diasDesde(v.fecha) - plazo;
		if (diasVenc > 0) return { texto: `venció hace ${diasVenc}d`, clase: 'rojo', filaVencida: true };
		if (diasVenc === 0) return { texto: 'vence hoy', clase: 'ambar', filaVencida: false };
		if (diasVenc >= -3) return { texto: `vence en ${-diasVenc}d`, clase: 'ambar', filaVencida: false };
		return { texto: `en ${-diasVenc}d`, clase: 'gris', filaVencida: false };
	}

	// ── Editar ficha (ContactModal compartido) ──────────────────────
	function editarFicha() {
		if (!clienteActual) return;
		abrirContacto(clienteActual.id, recurso as 'clientes' | 'proveedores', {
			onGuardado: async () => {
				toast_('Ficha actualizada', 'ok');
				await cargarCC(clienteActual!.id);
			}
		});
	}

	// ── Mini-dashboard de vencimientos en la ficha ───────────────────
	let miniAging = $state<AgingFila | null>(null);

	async function cargarMiniAging(entidadId: number) {
		miniAging = null;
		try {
			const r = await api(`/cc/aging?entidad_tipo=${modoEntidad}&entidad_id=${entidadId}`);
			const d = await r.json();
			if (!r.ok) return;
			miniAging = d.filas?.[0] ?? null;
		} catch {
			/* la ficha funciona igual sin el resumen */
		}
	}

	const miniAgingSegs = $derived.by(() => {
		if (!miniAging) return [];
		const segs = [
			{ v: miniAging.a_vencer, cls: 'ok' },
			{ v: miniAging.v1_30, cls: 'v30' },
			{ v: miniAging.v31_60, cls: 'v60' },
			{ v: miniAging.v61_90, cls: 'v90' },
			{ v: miniAging.v90, cls: 'vmax' }
		].filter((s) => s.v > 0.001);
		const suma = segs.reduce((a, s) => a + s.v, 0) || 1;
		return segs.map((s) => ({ ...s, pct: (s.v / suma) * 100 }));
	});
	const miniAgingDetalle = $derived.by(() => {
		if (!miniAging) return '';
		return [
			miniAging.v1_30 > 0.001 ? `1–30d: ${fmt(miniAging.v1_30)}` : null,
			miniAging.v31_60 > 0.001 ? `31–60d: ${fmt(miniAging.v31_60)}` : null,
			miniAging.v61_90 > 0.001 ? `61–90d: ${fmt(miniAging.v61_90)}` : null,
			miniAging.v90 > 0.001 ? `+90d: ${fmt(miniAging.v90)}` : null
		]
			.filter(Boolean)
			.join(' · ');
	});

	// ── Lista rápida ───────────────────────────────────────────────
	let vistaMostrandoLista = $state(true);
	let listaOrden = $state<'ultima_compra' | 'saldo' | 'nombre'>('ultima_compra');
	let listaPagina = $state(1);
	let listaTotalPag = $state(1);
	let listaCargando = $state(true);
	let listaDatos = $state<(Cliente & { ult_fecha?: string | null })[]>([]);
	const LISTA_POR_PAG = 10;

	async function cargarLista(pagina?: number) {
		listaPagina = pagina ?? listaPagina;
		listaCargando = true;
		try {
			const url = `/${recurso}?page=${listaPagina}&per_page=${LISTA_POR_PAG}&orden=${listaOrden}&cc_only=1&activo=1`;
			const r = await api(url);
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al cargar');
			listaTotalPag = d.paginas;
			listaDatos = d.datos;
		} catch (e) {
			listaDatos = [];
			toast_(e instanceof Error ? e.message : 'Error al cargar', 'err');
		} finally {
			listaCargando = false;
		}
	}

	function cambiarOrden(o: typeof listaOrden) {
		listaOrden = o;
		cargarLista(1);
	}

	function volverALista() {
		clienteActual = null;
		inputBusq = '';
		vistaMostrandoLista = true;
		cargarLista(1);
	}

	// ── Modal: Registrar pago ────────────────────────────────────────
	let pagoAbierto = $state(false);
	let pagoMonto = $state('');
	let pagoFecha = $state('');
	let pagoObs = $state('');
	let pagoMedio = $state<'efectivo' | 'transferencia' | 'cheque'>('efectivo');
	let transfBanco = $state('');
	let transfRef = $state('');
	let transfFile = $state<FileList | null>(null);
	let chequeBanco = $state('');
	let chequeNum = $state('');
	let chequeFechaEmision = $state('');
	let chequeVenc = $state('');
	let chequeTitular = $state('');
	let chequeCuit = $state('');
	let chequeFile = $state<FileList | null>(null);
	let asigMontos = $state<Record<string, number>>({});
	let pagoGuardando = $state(false);
	let pagoError = $state('');

	const esAdminCC = $derived(puede('cajas_todas'));
	let cajasVenta = $state<{ id: number; nombre: string }[] | null>(null);
	let pagoCajaId = $state('');

	async function poblarSelectorCajaPago() {
		if (!esAdminCC) return;
		if (!cajasVenta) {
			try {
				const r = await api('/cajas');
				const d = await r.json();
				cajasVenta = (Array.isArray(d) ? d : []).filter((c: any) => c.activo && c.tipo === 'venta');
			} catch {
				cajasVenta = [];
			}
		}
		const operativa = cajaOperativaId();
		if (cajasVenta!.some((c) => c.id === operativa)) {
			pagoCajaId = String(operativa);
		} else if (cajasVenta!.length === 1) {
			pagoCajaId = String(cajasVenta![0].id);
		} else {
			pagoCajaId = '';
		}
	}

	const mostrarCajaPago = $derived(esAdminCC && pagoMedio !== 'cheque');

	function todayStr(): string {
		const d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	async function abrirModalPago() {
		if (!clienteActual) return;
		pagoMonto = '';
		pagoFecha = todayStr();
		pagoObs = '';
		pagoMedio = 'efectivo';
		transfBanco = '';
		transfRef = '';
		transfFile = null;
		chequeBanco = '';
		chequeNum = '';
		chequeFechaEmision = '';
		chequeVenc = '';
		chequeTitular = '';
		chequeCuit = '';
		chequeFile = null;
		pagoError = '';
		asigMontos = {};
		await poblarSelectorCajaPago();
		pagoAbierto = true;
	}

	function cerrarModalPago() {
		pagoAbierto = false;
	}

	function claveAsig(v: VentaPendiente): string {
		return v.tipo_ref === 'cargo' ? `cargo:${v.id}` : `venta:${v.id}`;
	}

	function totalAsignado(): number {
		return Object.values(asigMontos).reduce((s, v) => s + (v || 0), 0);
	}
	const totalAsignadoActual = $derived.by(() => {
		let t = 0;
		for (const k in asigMontos) t += asigMontos[k] || 0;
		return t;
	});
	const asigExcede = $derived((parseFloat(pagoMonto) || 0) + 0.001 < totalAsignadoActual);

	function autoDistribuir() {
		let restante = parseFloat(pagoMonto) || 0;
		if (!restante) {
			toast_('Ingresá el monto del pago primero', 'err');
			return;
		}
		const nuevo: Record<string, number> = {};
		for (const v of ventasPendientes) {
			const max = v.saldo_pendiente;
			const asig = Math.min(restante, max);
			nuevo[claveAsig(v)] = asig > 0 ? Math.round(asig * 100) / 100 : 0;
			restante = Math.max(0, restante - asig);
		}
		asigMontos = nuevo;
	}

	function autoDistribuirSilent() {
		let restante = parseFloat(pagoMonto) || 0;
		const nuevo: Record<string, number> = {};
		for (const v of ventasPendientes) {
			if (restante <= 0) {
				nuevo[claveAsig(v)] = 0;
				continue;
			}
			const max = v.saldo_pendiente;
			const asig = Math.min(restante, max);
			nuevo[claveAsig(v)] = asig > 0 ? Math.round(asig * 100) / 100 : 0;
			restante = Math.max(0, restante - asig);
		}
		asigMontos = nuevo;
	}

	let autoDistTimer: ReturnType<typeof setTimeout>;
	function onPagoMontoInput() {
		if (!ventasPendientes.length) return;
		clearTimeout(autoDistTimer);
		autoDistTimer = setTimeout(autoDistribuirSilent, 400);
	}

	async function subirArchivo(files: FileList | null): Promise<string | null> {
		const file = files?.[0];
		if (!file) return null;
		if (file.size > 5 * 1024 * 1024) {
			throw new Error(`El archivo supera el máximo de 5 MB (${(file.size / 1024 / 1024).toFixed(1)} MB)`);
		}
		const fd = new FormData();
		fd.append('archivo', file);
		const r = await api('/uploads', { method: 'POST', body: fd });
		const d = await r.json();
		if (!r.ok) throw new Error(d.error ?? 'Error al subir archivo');
		return d.path;
	}

	async function confirmarPago() {
		const monto = parseFloat(pagoMonto);
		if (isNaN(monto) || monto <= 0) {
			pagoError = 'Ingresá un monto válido';
			return;
		}

		const asignaciones: { venta_id?: number; cargo_id?: number; monto: number }[] = [];
		for (const v of ventasPendientes) {
			const m = asigMontos[claveAsig(v)] || 0;
			if (m < 0.001) continue;
			if (v.tipo_ref === 'cargo') asignaciones.push({ cargo_id: v.id, monto: m });
			else asignaciones.push({ venta_id: v.id, monto: m });
		}

		let pagoDatos: Record<string, string | null> | null = null;
		let comprobante: string | null = null;
		let fileList: FileList | null = null;

		if (pagoMedio === 'transferencia') {
			pagoDatos = { banco: transfBanco.trim() || null, referencia: transfRef.trim() || null };
			fileList = transfFile;
		} else if (pagoMedio === 'cheque') {
			if (!chequeBanco.trim()) {
				pagoError = 'Ingresá el banco del cheque';
				return;
			}
			if (!chequeNum.trim()) {
				pagoError = 'Ingresá el número de cheque';
				return;
			}
			if (!chequeVenc) {
				pagoError = 'Ingresá la fecha de vencimiento del cheque';
				return;
			}
			pagoDatos = {
				banco: chequeBanco.trim(),
				numero: chequeNum.trim(),
				fecha: chequeFechaEmision || null,
				venc: chequeVenc,
				titular: chequeTitular.trim() || null,
				cuit: chequeCuit.trim() || null
			};
			fileList = chequeFile;
		}

		pagoGuardando = true;
		pagoError = '';
		try {
			if (fileList?.[0]) comprobante = await subirArchivo(fileList);

			const cajaIdEnviar = esAdminCC ? (pagoCajaId ? Number(pagoCajaId) : null) : cajaOperativaId();

			const r = await api('/cc', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					entidad_tipo: modoEntidad,
					entidad_id: clienteActual!.id,
					monto,
					tipo: 'pago',
					fecha: pagoFecha,
					observaciones: pagoObs.trim() || null,
					asignaciones,
					medio_pago: pagoMedio,
					pago_datos: pagoDatos,
					comprobante,
					caja_id: cajaIdEnviar
				})
			});
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al registrar');

			if (pagoMedio === 'cheque' && pagoDatos) {
				await api('/cheques', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						tipo: 'recibido',
						numero: pagoDatos.numero,
						banco: pagoDatos.banco,
						librador: pagoDatos.titular || null,
						cuit: pagoDatos.cuit || null,
						monto,
						fecha_emision: pagoDatos.fecha || null,
						fecha_vencimiento: pagoDatos.venc,
						cliente_id: modoEntidad === 'cliente' ? clienteActual!.id : null,
						cc_movimiento_id: d.id ?? null
					})
				});
			}

			cerrarModalPago();
			toast_(`Pago de ${fmt(monto)} registrado`, 'ok');
			await cargarCC(clienteActual!.id);
		} catch (e) {
			pagoError = e instanceof Error ? e.message : 'Error al registrar';
		} finally {
			pagoGuardando = false;
		}
	}

	// ── Modal: Detalle de remito ─────────────────────────────────────
	let remitoAbierto = $state(false);
	let remitoVentaId = $state<number | null>(null);
	let remitoTitulo = $state('');
	let remitoMeta = $state('');
	let remitoCargando = $state(true);
	let remitoItems = $state<{ codigo: string; nombre: string; cantidad: number; precio_unitario: number; subtotal: number }[]>([]);
	let remitoSubtotal = $state(0);
	let remitoEnvio = $state(0);
	let remitoTotal = $state(0);
	let remitoError = $state('');
	let remitoPagos = $state<{ fecha: string; monto: number; obs?: string | null; medio_pago?: string | null; pago_datos?: Record<string, string | null> | null; comprobante?: string | null }[]>([]);

	function abrirModalRemito(mov: Movimiento) {
		const ventaId = mov.referencia_id!;
		remitoVentaId = ventaId;
		remitoTitulo = `${mov.ref_tipo || (modoEntidad === 'proveedor' ? 'Compra' : 'Venta')} N°${pad(ventaId)}`;
		remitoMeta = `Fecha: ${fmtFechaStr(mov.fecha)} · Total: ${fmt(mov.monto)}`;
		remitoCargando = true;
		remitoItems = [];
		remitoError = '';
		remitoAbierto = true;

		const pagosAsig: typeof remitoPagos = [];
		for (const m of movsActuales) {
			if (m.tipo === 'pago' && m.asignaciones) {
				for (const a of m.asignaciones) {
					if (a.venta_id === ventaId) {
						pagosAsig.push({
							fecha: m.fecha,
							monto: a.monto,
							obs: m.observaciones,
							medio_pago: m.medio_pago,
							pago_datos: m.pago_datos,
							comprobante: m.comprobante
						});
					}
				}
			}
		}
		remitoPagos = pagosAsig;

		const recursoDet = modoEntidad === 'proveedor' ? 'compras' : 'ventas';
		api(`/${recursoDet}/${ventaId}`)
			.then((r) => r.json())
			.then((venta) => {
				if (!venta.items) throw new Error('Sin detalle de productos');
				remitoItems = venta.items.map((it: any) => ({
					codigo: it.producto_codigo || it.codigo || '',
					nombre: it.producto_nombre || it.nombre || '',
					cantidad: parseFloat(it.cantidad),
					precio_unitario: it.precio_unitario ?? it.costo_unitario ?? 0,
					subtotal: parseFloat(it.subtotal || 0)
				}));
				remitoSubtotal = remitoItems.reduce((s, it) => s + it.subtotal, 0);
				remitoEnvio = venta.envio_precio ? parseFloat(venta.envio_precio) : 0;
				remitoTotal = parseFloat(venta.total);
				remitoCargando = false;
			})
			.catch((e) => {
				remitoError = e instanceof Error ? e.message : 'Error al cargar';
				remitoCargando = false;
			});
	}

	function cerrarModalRemito() {
		remitoAbierto = false;
	}

	async function imprimirRemito() {
		if (!remitoVentaId) return;
		try {
			const r = await api(`/ventas/${remitoVentaId}/imprimir`, { method: 'POST' });
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

	const remitoTotalPagado = $derived(remitoPagos.reduce((s, p) => s + p.monto, 0));
	const remitoPendiente = $derived(remitoTotal - remitoTotalPagado);

	function extraPago(d: Record<string, string | null> | null | undefined): string[] {
		if (!d) return [];
		const parts: string[] = [];
		if (d.banco) parts.push(`Banco: ${d.banco}`);
		if (d.referencia) parts.push(`Ref: ${d.referencia}`);
		if (d.numero) parts.push(`N°${d.numero}`);
		if (d.fecha) parts.push(fmtFechaStr(d.fecha));
		if (d.titular) parts.push(d.titular);
		return parts;
	}

	// ── Modal: Detalle de devolución ─────────────────────────────────
	let devAbierto = $state(false);
	let devVentaId = $state<number | null>(null);
	let devTitulo = $state('Cargando devolución…');
	let devMeta = $state('');
	let devMotivo = $state('');
	let devItems = $state<{ nombre: string; cantidad: number; precio_unitario: number }[]>([]);
	let devTotal = $state(0);
	let devError = $state('');

	async function abrirModalDevolucion(devId: number) {
		devVentaId = null;
		devTitulo = 'Cargando devolución…';
		devMeta = '';
		devItems = [];
		devMotivo = '';
		devError = '';
		devAbierto = true;
		try {
			const r = await api(`/devoluciones/${devId}`);
			const dev = await r.json();
			if (!r.ok) throw new Error(dev.error || 'Error al cargar la devolución');
			devVentaId = dev.venta_id;
			devTitulo = `Devolución N°${pad(dev.id)} — Venta N°${pad(dev.venta_id)}`;
			devMeta = `Fecha: ${fmtFechaStr(dev.creado_en)} · Crédito acreditado: ${fmt(dev.monto_total)}`;
			devMotivo = dev.motivo || '';
			devItems = (dev.items || []).map((it: any) => ({
				nombre: it.nombre,
				cantidad: parseFloat(it.cantidad),
				precio_unitario: it.precio_unitario
			}));
			devTotal = dev.monto_total;
		} catch (e) {
			devError = e instanceof Error ? e.message : 'Error al cargar';
		}
	}

	function cerrarModalDev() {
		devAbierto = false;
	}

	async function imprimirDevolucion() {
		if (!devVentaId) return;
		try {
			const r = await api(`/ventas/${devVentaId}/imprimir`, { method: 'POST' });
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

	// ── Modal: Eliminar movimiento ────────────────────────────────────
	let eliminarAbierto = $state(false);
	let movAEliminar = $state<{ id: number; tipo: 'cargo' | 'pago'; monto: number; fecha: string; ref: number | null } | null>(null);
	let eliminarGuardando = $state(false);

	function abrirModalEliminar(mov: Movimiento) {
		movAEliminar = { id: mov.id, tipo: mov.tipo, monto: mov.monto, fecha: mov.fecha, ref: mov.referencia_id };
		eliminarAbierto = true;
	}
	function cerrarModalEliminar() {
		eliminarAbierto = false;
		movAEliminar = null;
	}
	async function confirmarEliminarMov() {
		if (!movAEliminar) return;
		eliminarGuardando = true;
		try {
			const r = await api(`/cc/${movAEliminar.id}`, { method: 'DELETE' });
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al eliminar');
			const label = movAEliminar.tipo === 'pago' ? 'Pago' : 'Cargo';
			cerrarModalEliminar();
			toast_(`${label} eliminado`, 'ok');
			await cargarCC(clienteActual!.id);
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al eliminar', 'err');
		} finally {
			eliminarGuardando = false;
		}
	}

	// ── Recibo de pago ────────────────────────────────────────────────
	let configEmpresaCache: { punto_venta?: string } | null = null;
	async function cargarConfigEmpresa() {
		if (configEmpresaCache) return configEmpresaCache;
		try {
			const r = await api('/configuracion');
			configEmpresaCache = await r.json();
		} catch {
			configEmpresaCache = {};
		}
		return configEmpresaCache;
	}

	async function imprimirReciboPago(movId: number) {
		const [r, config] = await Promise.all([api(`/cc/${movId}/recibo-pdf`), cargarConfigEmpresa()]);
		if (!r.ok) {
			const d = await r.json().catch(() => ({}));
			toast_(d.error || 'Error al generar recibo', 'err');
			return;
		}
		const pv = String(config?.punto_venta || '1').padStart(4, '0');
		const nRecibo = `${pv}-${String(movId).padStart(8, '0')}`;
		abrirPdf(await r.blob(), { printBtn: true, downloadFilename: `recibo-${nRecibo}.pdf` });
	}

	// ── Vencimientos (aging) ────────────────────────────────────────
	let agingAbierto = $state(false);
	let agingCargando = $state(true);
	let agingFilas = $state<AgingFila[]>([]);
	let agingTotales = $state<Record<string, number> | null>(null);
	let agingError = $state('');
	let agingBadge = $state(0);

	async function cargarAging(): Promise<{ filas: AgingFila[]; totales: Record<string, number> }> {
		const r = await api(`/cc/aging?entidad_tipo=${modoEntidad}`);
		const d = await r.json();
		if (!r.ok) throw new Error(d.error ?? 'Error al cargar vencimientos');
		return d;
	}

	async function abrirModalAging() {
		agingCargando = true;
		agingError = '';
		agingAbierto = true;
		try {
			const d = await cargarAging();
			agingFilas = d.filas;
			agingTotales = d.totales;
		} catch (e) {
			agingError = e instanceof Error ? e.message : 'Error al cargar';
		} finally {
			agingCargando = false;
		}
	}
	function cerrarModalAging() {
		agingAbierto = false;
	}

	function celdaClase(v: number, venc = false): string {
		return v > 0.001 ? (venc ? 'celda-venc' : '') : 'celda-cero';
	}

	function onClickFilaAging(f: AgingFila) {
		cerrarModalAging();
		seleccionarCliente({ id: f.id, nombre: f.nombre });
	}

	async function actualizarBadgeAging() {
		try {
			const d = await cargarAging();
			agingBadge = d.filas.filter((f) => f.vencido > 0.001).length;
		} catch {
			/* sin badge si falla */
		}
	}

	// ── Escape cierra modales (orden de precedencia igual al legacy) ────
	function onKeydownGlobal(e: KeyboardEvent) {
		if (e.key !== 'Escape') return;
		if (eliminarAbierto) { cerrarModalEliminar(); return; }
		if (agingAbierto) { cerrarModalAging(); return; }
		if (remitoAbierto) { cerrarModalRemito(); return; }
		if (devAbierto) { cerrarModalDev(); return; }
		if (pagoAbierto) { cerrarModalPago(); return; }
	}

	// ── Init ─────────────────────────────────────────────────────────
	// Solo page.url.searchParams debe ser la dependencia reactiva de este
	// efecto. cargarCC()/cargarLista() leen otro estado (listaOrden, etc.)
	// de forma síncrona antes de su primer await — si esas lecturas
	// ocurrieran dentro de la ventana síncrona del efecto, Svelte las
	// registraría también como dependencias, y el efecto se re-dispararía
	// cada vez que cambia ese estado no relacionado (ej. al tocar el orden
	// de la lista), reseteando la carga. Diferir a un microtask saca esas
	// llamadas de esa ventana.
	$effect(() => {
		const idParam = parseInt(page.url.searchParams.get('id') || '');
		const wantsAging = !!page.url.searchParams.get('vencimientos');
		// Leído acá (no solo derivado arriba) para que este mismo efecto sea
		// la única fuente de "hay que recargar" — cambiar ?tipo= sin id (el
		// caso del dropdown del nav) tiene que volver a la vista de lista del
		// modo nuevo, no solo recalcular el label.
		page.url.searchParams.get('tipo');
		queueMicrotask(() => {
			if (idParam) {
				cargarCC(idParam);
			} else {
				volverALista();
			}
			if (wantsAging) abrirModalAging();
			actualizarBadgeAging();
		});
	});

	// ── Tour guiado — port de pos/cuentacorriente.html (14 pasos). El
	// intro busca "CLIENTE DEMO" para tener datos reales que mostrar en el
	// resto del recorrido — si esa entidad no existe en esta instalación,
	// los pasos siguientes simplemente muestran menos contenido, no rompen.
	const TOUR_STEPS: TourStep[] = [
		{
			el: null,
			title: 'Módulo de Cuenta Corriente',
			body: 'Acá gestionás los saldos, deudas y pagos de clientes y proveedores que operan en cuenta corriente. Vamos a recorrerlo con un cliente de demo.',
			onEnter: async ({ delay, waitFor }) => {
				const inp = document.querySelector('.busq-wrap input') as HTMLInputElement | null;
				if (!inp) return;
				inp.value = 'CLIENTE DEMO';
				inp.dispatchEvent(new Event('input', { bubbles: true }));
				await delay(700);
				const item = document.querySelector('.busq-dd .dd-item') as HTMLElement | null;
				item?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
				try {
					await waitFor('.cli-card', 3000);
				} catch {
					/* sin CLIENTE DEMO en esta instalación */
				}
			}
		},
		{
			el: '.busq-wrap',
			title: 'Búsqueda de entidad',
			body: 'Escribí el nombre o CUIT del cliente (o proveedor) y el sistema autocompleta en tiempo real. El demo ya cargó <strong>CLIENTE DEMO</strong> para mostrarte las funciones.'
		},
		{
			el: '.cli-card',
			title: 'Ficha del cliente',
			body: 'Muestra el nombre, saldo actual y datos de la ficha. El saldo en rojo indica que el cliente tiene deuda; en verde significa que tiene saldo a favor.'
		},
		{
			el: '.cli-saldo-block',
			title: 'Saldo actual',
			body: 'El saldo se actualiza automáticamente cada vez que se registra una venta o un pago en cuenta corriente.'
		},
		{
			el: '.pend-card',
			title: 'Deuda por comprobante',
			body: 'Desglosa cuánto debe el cliente comprobante por comprobante: tipo, número, total original y saldo pendiente. Muy útil para saber qué facturas o remitos están impagos.'
		},
		{
			el: '.libro-tabla-wrap',
			title: 'Libro de movimientos',
			body: 'Historial completo de la cuenta: <strong>Débito</strong> (cargos por ventas), <strong>Crédito</strong> (pagos recibidos) y <strong>Saldo</strong> acumulado en cada movimiento. Hacé click en una fila de cargo para ver el comprobante.'
		},
		{
			el: '.overlay.abierto .modal',
			pad: 0,
			title: 'Detalle del comprobante',
			body: 'Al hacer click en una fila de cargo se abre el comprobante completo: productos, cantidades, precios y total. También muestra los pagos ya asignados a ese comprobante.',
			onEnter: async ({ delay, waitFor }) => {
				try {
					const tr = (await waitFor('.libro-tabla .row-cargo', 2000)) as HTMLElement;
					tr.click();
					await delay(500);
				} catch {
					/* sin cargos en el libro de esta instalación */
				}
			}
		},
		{
			el: '.remito-tabla',
			title: 'Productos del comprobante',
			body: 'Listado de todos los ítems con código, nombre, cantidad, precio unitario y subtotal. En la parte inferior aparece el total y cuánto ya fue pagado.'
		},
		{
			el: '.overlay.abierto .modal',
			pad: 0,
			title: 'Registrar pago',
			body: 'Ingresá el monto, la fecha y el medio de pago (efectivo, transferencia o cheque). Para transferencias podés adjuntar el comprobante bancario como imagen o PDF.',
			onEnter: async ({ delay }) => {
				cerrarModalRemito();
				await delay(300);
				abrirModalPago();
				await delay(400);
			}
		},
		{
			el: '.asig-section',
			title: 'Asignar a comprobantes',
			body: 'Podés asignar el pago a comprobantes específicos para reducir su saldo pendiente. Si no asignás nada, el pago queda como crédito disponible para imputar después.'
		},
		{
			el: '.btn-auto-dist',
			title: 'Distribución automática',
			body: 'Distribuye el monto ingresado entre los comprobantes en orden cronológico, cubriendo las deudas más antiguas primero. Una sola acción salda múltiples comprobantes.'
		},
		{
			el: '.btn-edit-ficha',
			title: 'Editar ficha del cliente',
			body: 'Abrí la ficha para editar datos de contacto (email, teléfono, domicilio), límite de crédito y condición de IVA. Los cambios se reflejan en todos los módulos.',
			onEnter: async ({ delay }) => {
				cerrarModalPago();
				await delay(300);
			}
		},
		{
			el: 'a[href="/cuentacorriente"]',
			title: 'Modo proveedor',
			body: 'El mismo módulo funciona para proveedores. Pasá el mouse por <strong>Cta. Cte.</strong> en la barra de navegación y elegí <strong>Proveedores</strong> para ver lo que le debés a tus proveedores.'
		},
		{
			el: 'a[href="/contactos"]',
			title: 'Contactos',
			body: 'El próximo módulo es <strong>Contactos</strong>, donde gestionás toda la base de clientes y proveedores con sus datos completos. ¡Eso es todo para Cta. Cte.!'
		}
	];

	onMount(() => {
		setTourSteps('cuentacorriente', TOUR_STEPS);
	});
</script>

<svelte:window onkeydown={onKeydownGlobal} />

<svelte:head>
	<title>Logos — Cuenta Corriente</title>
</svelte:head>

<div class="cc-main">
	<!-- ── Panel izquierdo ── -->
	<div class="cc-left">
		<div class="busq-wrap">
			<input
				type="text"
				placeholder={modoEntidad === 'proveedor' ? 'Buscar proveedor por nombre o CUIT…' : 'Buscar cliente por nombre o CUIT…'}
				autocomplete="off"
				spellcheck="false"
				bind:value={inputBusq}
				oninput={onInputBusq}
				onkeydown={onBusqKeydown}
				onblur={() => setTimeout(cerrarDD, 150)}
			/>
			{#if ddVisible}
				<div class="busq-dd visible">
					{#each ddResultados as c, i (c.id)}
						<div
							class="dd-item"
							class:activo={i === ddIdx}
							onmousedown={() => seleccionarCliente(c)}
							role="button"
							tabindex="-1"
						>
							<div class="dd-nom">{c.nombre}</div>
							<div class="dd-det">{c.cuit ?? ''} · Saldo: {fmt(c.saldo_cuenta_corriente)}</div>
						</div>
					{/each}
				</div>
			{/if}
		</div>

		{#if clienteActual}
			<!-- Card cliente -->
			<div class="cli-card">
				<div class="cli-card-top">
					<div class="cli-nombre">{clienteActual.nombre}</div>
					<div class="cli-saldo-block">
						<span class="cli-saldo-label">Saldo actual</span>
						<span class="cli-saldo-val {saldoClaseCard}">{fmt(clienteActual.saldo_cuenta_corriente)}</span>
					</div>
					{#if limiteExcedido}
						<div class="cc-limite-warn">
							⚠ Límite excedido: deuda {fmt(clienteActual.saldo_cuenta_corriente)} / límite {fmt(clienteActual.limite_credito)}
						</div>
					{/if}
				</div>

				{#if miniAging && miniAging.saldo >= 0.001}
					<div class="cli-aging">
						<div class="ca-head">
							<span class="ca-titulo">Vencimientos</span>
							{#if miniAging.vencido > 0.001}
								<span class="venc-chip rojo">hasta {miniAging.max_dias_vencido}d vencido</span>
							{:else if miniAging.plazo_pago_dias === null}
								<span class="venc-chip gris">sin plazo configurado</span>
							{:else}
								<span class="venc-chip gris">al día</span>
							{/if}
						</div>
						<div class="ca-bar">
							{#each miniAgingSegs as s, i (i)}
								<div class="ca-seg {s.cls}" style="width:{s.pct.toFixed(1)}%"></div>
							{/each}
						</div>
						<div class="ca-row">
							<span>A vencer <b>{fmt(miniAging.a_vencer)}</b></span>
							<span class:ca-venc={miniAging.vencido > 0.001}>Vencido <b>{fmt(miniAging.vencido)}</b></span>
						</div>
						{#if miniAgingDetalle}<div class="ca-detalle">{miniAgingDetalle}</div>{/if}
						{#if miniAging.vencido > 0.001 && modoEntidad !== 'proveedor'}
							<button
								class="btn-wa"
								onclick={() => abrirReclamoWa({ ...miniAging!, telefono: miniAging!.telefono ?? clienteActual?.telefono })}
								>Reclamar por WhatsApp</button
							>
						{/if}
					</div>
				{/if}

				<div class="cli-datos">
					<div class="cli-dato"><span class="cli-dato-k">CUIT</span><span class="cli-dato-v">{clienteActual.cuit || '—'}</span></div>
					<div class="cli-dato"><span class="cli-dato-k">Cond. IVA</span><span class="cli-dato-v">{clienteActual.condicion_iva || '—'}</span></div>
					{#if modoEntidad !== 'proveedor'}
						<div class="cli-dato"><span class="cli-dato-k">Límite CC</span><span class="cli-dato-v">{clienteActual.limite_credito ? fmt(clienteActual.limite_credito) : '—'}</span></div>
						<div class="cli-dato"><span class="cli-dato-k">Plazo de pago</span><span class="cli-dato-v">{clienteActual.plazo_pago_dias ? clienteActual.plazo_pago_dias + ' días' : 'Sin plazo'}</span></div>
						{#if clienteActual.email}<div class="cli-dato"><span class="cli-dato-k">Email</span><span class="cli-dato-v">{clienteActual.email}</span></div>{/if}
						{#if clienteActual.telefono}<div class="cli-dato"><span class="cli-dato-k">Teléfono</span><span class="cli-dato-v">{clienteActual.telefono}</span></div>{/if}
						{#if clienteActual.domicilio}<div class="cli-dato"><span class="cli-dato-k">Domicilio</span><span class="cli-dato-v">{clienteActual.domicilio}</span></div>{/if}
						{#if clienteActual.localidad}<div class="cli-dato"><span class="cli-dato-k">Localidad</span><span class="cli-dato-v">{clienteActual.localidad}</span></div>{/if}
						{#if clienteActual.provincia}<div class="cli-dato"><span class="cli-dato-k">Provincia</span><span class="cli-dato-v">{clienteActual.provincia}</span></div>{/if}
					{/if}
				</div>

				{#if modoEntidad !== 'proveedor' && clienteActual.observaciones}
					<div class="cli-obs-block">
						<div class="cli-obs-titulo">Observaciones</div>
						<div class="cli-obs-texto">{clienteActual.observaciones}</div>
					</div>
				{/if}

				<div class="cli-card-footer">
					<button class="btn-volver-lista" onclick={volverALista}>← Lista</button>
					<button class="btn-edit-ficha" onclick={editarFicha}>✎ Editar ficha</button>
				</div>
			</div>

			<!-- Deuda pendiente -->
			<div class="pend-card">
				<div class="pend-header"><span class="pend-titulo">Deuda pendiente por comprobante</span></div>
				<div>
					{#if !ventasPendientes.length}
						<div class="pend-sin-deuda">✓ Sin deuda pendiente</div>
					{:else}
						<table class="pend-tabla">
							<thead>
								<tr><th>Comprobante</th><th>Fecha</th><th>Vence</th><th class="r">Saldo</th></tr>
							</thead>
							<tbody>
								{#each ventasPendientes as v (v.id)}
									{@const info = vencInfo(v)}
									<tr class:fila-vencida={info.filaVencida}>
										<td><span class="tipo-badge">{(v.tipo_comprobante ?? 'VTA').substring(0, 6).toUpperCase()}</span><span class="pend-num">N°{pad(v.id)}</span></td>
										<td>{fmtFechaCorta(v.fecha)}</td>
										<td><span class="venc-chip {info.clase}">{info.texto}</span></td>
										<td class="r saldo-pend">{fmt(v.saldo_pendiente)}</td>
									</tr>
								{/each}
							</tbody>
						</table>
						<div class="pend-total-row">
							<span class="pend-total-label">Total deuda</span>
							<span class="pend-total-val">{fmt(totalDeuda)}</span>
						</div>
					{/if}
				</div>
			</div>
		{:else if vistaMostrandoLista}
			<div class="cc-lista-wrap">
				<div class="cc-lista-hdr">
					<span class="cc-lista-tit">{modoEntidad === 'proveedor' ? 'Proveedores' : 'Clientes'}</span>
					<div class="cc-sort-btns">
						<button class="cc-sort-btn" class:activo={listaOrden === 'ultima_compra'} onclick={() => cambiarOrden('ultima_compra')}>Recientes</button>
						<button class="cc-sort-btn" class:activo={listaOrden === 'saldo'} onclick={() => cambiarOrden('saldo')}>Saldo</button>
						<button class="cc-sort-btn" class:activo={listaOrden === 'nombre'} onclick={() => cambiarOrden('nombre')}>A–Z</button>
					</div>
				</div>
				<div class="cc-lista">
					{#if listaCargando}
						<div class="cc-lista-msg">Cargando…</div>
					{:else if !listaDatos.length}
						<div class="cc-lista-msg">No hay {modoEntidad === 'proveedor' ? 'proveedores' : 'clientes'} en cuenta corriente</div>
					{:else}
						{#each listaDatos as c (c.id)}
							{@const s = c.saldo_cuenta_corriente}
							{@const sc = s > 0.001 ? 'deuda' : s < -0.001 ? 'saldo-ok' : 'saldo-cero'}
							<div class="cc-li" onclick={() => seleccionarCliente(c)} role="button" tabindex="0" onkeydown={() => {}}>
								<div class="cc-li-izq">
									<div class="cc-li-nombre">{c.nombre}</div>
									{#if c.cuit}<div class="cc-li-sub">{c.cuit}</div>{/if}
								</div>
								<div class="cc-li-der">
									<div class="cc-li-saldo {sc}">$ {Math.abs(s).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
									<div class="cc-li-fecha">{c.ult_fecha ? fmtFechaCorta(c.ult_fecha) : '—'}</div>
								</div>
							</div>
						{/each}
					{/if}
				</div>
				{#if listaTotalPag > 1}
					<div class="cc-lista-pag">
						<button class="cc-pag-btn" disabled={listaPagina <= 1} onclick={() => cargarLista(listaPagina - 1)}>‹</button>
						<span class="cc-pag-info">Pág. {listaPagina} / {listaTotalPag}</span>
						<button class="cc-pag-btn" disabled={listaPagina >= listaTotalPag} onclick={() => cargarLista(listaPagina + 1)}>›</button>
					</div>
				{/if}
			</div>
		{/if}
	</div>

	<!-- ── Panel derecho ── -->
	<div class="cc-right">
		<div class="libro-header">
			<span class="libro-titulo">Libro de movimientos</span>
			<div style="display:flex;gap:8px;align-items:center">
				<button class="btn-aging" onclick={abrirModalAging}>
					Vencimientos{#if agingBadge > 0}<span class="aging-badge">{agingBadge}</span>{/if}
				</button>
				{#if clienteActual && puedeCobrar}
					<button class="btn-reg-pago" style="display:block" onclick={abrirModalPago}>+ Registrar pago</button>
				{/if}
			</div>
		</div>
		<div class="libro-tabla-wrap">
			{#if vistaLibro === 'cargando'}
				<div class="libro-empty">Cargando…</div>
			{:else if vistaLibro === 'error'}
				<div class="libro-empty">{libroError}</div>
			{:else if !clienteActual}
				<div class="libro-empty">{modoEntidad === 'proveedor' ? 'Seleccioná un proveedor para ver sus movimientos' : 'Seleccioná un cliente para ver sus movimientos'}</div>
			{:else if vistaLibro === 'vacio'}
				<div class="libro-empty">Sin movimientos registrados</div>
			{:else}
				<table class="libro-tabla">
					<thead>
						<tr>
							<th class="col-fecha">Fecha</th>
							<th class="col-concepto">Concepto</th>
							<th class="col-debito r">Débito</th>
							<th class="col-asig r">Asignado</th>
							<th class="col-credito r">Crédito</th>
							<th class="col-saldo r">Saldo</th>
							<th class="col-acciones"></th>
						</tr>
					</thead>
					<tbody>
						{#each filasLibro as f (f.mov.id)}
							<tr
								class="row-{f.mov.tipo}"
								class:row-dev={f.esDevolucion}
								class:row-cargo={f.mov.tipo === 'cargo'}
								onclick={() => (f.mov.tipo === 'cargo' ? onClickFilaCargo(f) : f.esDevolucion ? onClickFilaDev(f) : undefined)}
							>
								<td class="col-fecha">{fmtFechaStr(f.mov.fecha)}</td>
								<td class="col-concepto">
									{#if f.esHtml}
										{#if f.esDevolucion}<span class="dev-link">Devolución</span>{:else}<strong>Pago</strong>{#if f.mov.medio_pago}<span class="medio-badge medio-{f.mov.medio_pago}">{f.mov.medio_pago}</span>{/if}{/if}
									{:else}
										{f.concepto}
									{/if}
									{#if f.obsLine}<div class="concepto-obs">{f.obsLine}</div>{/if}
								</td>
								<td class="col-debito">{f.mov.tipo === 'cargo' ? fmt(f.mov.monto) : ''}</td>
								<td class="col-asig">{f.mov.tipo === 'cargo' && f.asignado > 0.001 ? fmt(f.asignado) : ''}</td>
								<td class="col-credito">{f.mov.tipo === 'pago' ? fmt(f.mov.monto) : ''}</td>
								<td class="col-saldo {saldoClase(f.saldo)}">{fmt(f.saldo)}</td>
								<td class="col-acciones">
									{#if f.mov.tipo === 'pago' && !f.esDevolucion}
										<button class="btn-print-recibo" title="Ver recibo" onclick={(e) => { e.stopPropagation(); imprimirReciboPago(f.mov.id); }}>🖶</button>
									{/if}
									<button class="btn-del-mov" title="Eliminar" onclick={(e) => { e.stopPropagation(); abrirModalEliminar(f.mov); }}>🗑</button>
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			{/if}
		</div>
	</div>
</div>

<!-- ═══ Modal vencimientos (aging) ══ -->
{#if agingAbierto}
	<div class="overlay aging-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalAging()}>
		<div class="aging-modal" role="dialog" aria-modal="true">
			<div class="aging-mheader">
				<h2>Vencimientos de cuenta corriente</h2>
				<button class="aging-mcerrar" aria-label="Cerrar" onclick={cerrarModalAging}>✕</button>
			</div>
			<div class="aging-mbody">
				{#if agingCargando}
					<div style="padding:20px;color:#9B9590">Cargando…</div>
				{:else if agingError}
					<div style="padding:20px;color:var(--neo-danger)">{agingError}</div>
				{:else if !agingFilas.length}
					<div style="padding:24px;text-align:center;color:#9B9590">
						Sin {modoEntidad === 'proveedor' ? 'deudas a proveedores' : 'deudores'} en cuenta corriente.
					</div>
				{:else}
					<table class="aging-tabla">
						<thead>
							<tr>
								<th>{modoEntidad === 'proveedor' ? 'Proveedor' : 'Cliente'}</th>
								<th>Plazo</th>
								<th>Estado</th>
								<th class="r">A vencer</th>
								<th class="r">1–30d</th>
								<th class="r">31–60d</th>
								<th class="r">61–90d</th>
								<th class="r">+90d</th>
								<th class="r">Vencido</th>
								<th class="r">Saldo</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{#each agingFilas as f (f.id)}
								<tr onclick={() => onClickFilaAging(f)}>
									<td><strong>{f.nombre}</strong><div style="font-size:11px;color:#9B9590">{f.telefono ?? ''}</div></td>
									<td>{f.plazo_pago_dias ? f.plazo_pago_dias + 'd' : '—'}</td>
									<td>
										{#if f.max_dias_vencido > 0}<span class="venc-chip rojo">hasta {f.max_dias_vencido}d vencido</span>
										{:else if f.plazo_pago_dias === null}<span class="venc-chip gris">sin plazo</span>
										{:else}<span class="venc-chip gris">al día</span>{/if}
									</td>
									<td class="r {celdaClase(f.a_vencer)}">{f.a_vencer > 0.001 ? fmt(f.a_vencer) : '—'}</td>
									<td class="r {celdaClase(f.v1_30, true)}">{f.v1_30 > 0.001 ? fmt(f.v1_30) : '—'}</td>
									<td class="r {celdaClase(f.v31_60, true)}">{f.v31_60 > 0.001 ? fmt(f.v31_60) : '—'}</td>
									<td class="r {celdaClase(f.v61_90, true)}">{f.v61_90 > 0.001 ? fmt(f.v61_90) : '—'}</td>
									<td class="r {celdaClase(f.v90, true)}">{f.v90 > 0.001 ? fmt(f.v90) : '—'}</td>
									<td class="r {f.vencido > 0.001 ? 'celda-venc' : 'celda-cero'}">{fmt(f.vencido)}</td>
									<td class="r"><strong>{fmt(f.saldo)}</strong></td>
									<td>
										{#if f.vencido > 0.001 && f.telefono && modoEntidad !== 'proveedor'}
											<button class="btn-wa btn-wa-mini" title="Reclamar por WhatsApp" onclick={(e) => { e.stopPropagation(); abrirReclamoWa(f); }}>WA</button>
										{/if}
									</td>
								</tr>
							{/each}
						</tbody>
						{#if agingTotales}
							<tfoot>
								<tr>
									<td colspan="3">TOTALES</td>
									<td class="r">{fmt(agingTotales.a_vencer)}</td>
									<td class="r">{fmt(agingTotales.v1_30)}</td>
									<td class="r">{fmt(agingTotales.v31_60)}</td>
									<td class="r">{fmt(agingTotales.v61_90)}</td>
									<td class="r">{fmt(agingTotales.v90)}</td>
									<td class="r {agingTotales.vencido > 0.001 ? 'celda-venc' : ''}">{fmt(agingTotales.vencido)}</td>
									<td class="r">{fmt(agingTotales.saldo)}</td>
									<td></td>
								</tr>
							</tfoot>
						{/if}
					</table>
				{/if}
			</div>
		</div>
	</div>
{/if}

<!-- ── Modal: Registrar pago ── -->
{#if pagoAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalPago()}>
		<div class="modal modal-lg" role="dialog" aria-modal="true">
			<div class="modal-header">
				<span class="modal-titulo">Registrar pago</span>
				<span class="modal-subtitulo">{clienteActual?.nombre}</span>
				<button class="modal-cerrar" aria-label="Cerrar" onclick={cerrarModalPago}>×</button>
			</div>
			<div class="modal-body">
				{#if modoEntidad !== 'proveedor' && clienteActual?.limite_credito && clienteActual.limite_credito > 0 && clienteActual.saldo_cuenta_corriente > clienteActual.limite_credito + 0.001}
					<div class="pago-limit-banner">⚠ Este cliente supera su límite de crédito. Este pago lo reducirá.</div>
				{/if}
				<div class="pago-form-row">
					<div class="form-campo">
						<label for="pago-monto">Monto *</label>
						<input id="pago-monto" type="number" min="0.01" step="any" placeholder="0,00" bind:value={pagoMonto} oninput={onPagoMontoInput} />
					</div>
					<div class="form-campo">
						<label for="pago-fecha">Fecha</label>
						<input id="pago-fecha" type="date" max={todayStr()} bind:value={pagoFecha} />
					</div>
					<div class="form-campo">
						<label for="pago-medio">Medio de pago</label>
						<select id="pago-medio" bind:value={pagoMedio}>
							<option value="efectivo">Efectivo</option>
							<option value="transferencia">Transferencia</option>
							<option value="cheque">Cheque</option>
						</select>
					</div>
					{#if mostrarCajaPago}
						<div class="form-campo">
							<label for="pago-caja">Registrar en caja</label>
							<select id="pago-caja" bind:value={pagoCajaId}>
								<option value="">— No registrar en caja —</option>
								{#each cajasVenta ?? [] as c (c.id)}
									<option value={String(c.id)}>{c.nombre}</option>
								{/each}
							</select>
						</div>
					{/if}
				</div>

				{#if pagoMedio === 'transferencia'}
					<div class="medio-extra">
						<div class="form-campo">
							<label for="transf-banco">Banco</label>
							<input id="transf-banco" type="text" placeholder="Santander, Galicia…" autocomplete="off" bind:value={transfBanco} />
						</div>
						<div class="form-campo">
							<label for="transf-ref">N° de referencia / operación</label>
							<input id="transf-ref" type="text" placeholder="Número de transferencia" autocomplete="off" bind:value={transfRef} />
						</div>
						<div class="form-campo span2">
							<label for="transf-file">Comprobante (imagen o PDF, máx 5 MB)</label>
							<div class="file-drop">
								<input id="transf-file" type="file" accept="image/*,.pdf" onchange={(e) => (transfFile = (e.target as HTMLInputElement).files)} />
								<div class="file-drop-txt">{transfFile?.[0] ? transfFile[0].name : '📎 Seleccionar archivo…'}</div>
							</div>
						</div>
					</div>
				{:else if pagoMedio === 'cheque'}
					<div class="medio-extra tres-col">
						<div class="form-campo">
							<label for="cheque-banco">Banco emisor *</label>
							<input id="cheque-banco" type="text" placeholder="Banco Nación…" autocomplete="off" bind:value={chequeBanco} />
						</div>
						<div class="form-campo">
							<label for="cheque-num">N° de cheque *</label>
							<input id="cheque-num" type="text" placeholder="00012345" autocomplete="off" bind:value={chequeNum} />
						</div>
						<div class="form-campo">
							<label for="cheque-fecha">Fecha de emisión</label>
							<input id="cheque-fecha" type="date" bind:value={chequeFechaEmision} />
						</div>
						<div class="form-campo">
							<label for="cheque-venc">Fecha de vencimiento *</label>
							<input id="cheque-venc" type="date" bind:value={chequeVenc} />
						</div>
						<div class="form-campo">
							<label for="cheque-titular">Titular (librador)</label>
							<input id="cheque-titular" type="text" placeholder="Nombre del firmante" autocomplete="off" bind:value={chequeTitular} />
						</div>
						<div class="form-campo">
							<label for="cheque-cuit">CUIT del librador</label>
							<input id="cheque-cuit" type="text" placeholder="20-12345678-9" autocomplete="off" maxlength="13" bind:value={chequeCuit} />
						</div>
						<div class="form-campo span2">
							<label for="cheque-file">Imagen del cheque (opcional, máx 5 MB)</label>
							<div class="file-drop">
								<input id="cheque-file" type="file" accept="image/*,.pdf" onchange={(e) => (chequeFile = (e.target as HTMLInputElement).files)} />
								<div class="file-drop-txt">{chequeFile?.[0] ? chequeFile[0].name : '📎 Seleccionar archivo…'}</div>
							</div>
						</div>
					</div>
				{/if}

				<div class="form-campo pago-obs-row">
					<label for="pago-obs">Observaciones</label>
					<input id="pago-obs" type="text" placeholder="Notas adicionales…" bind:value={pagoObs} />
				</div>

				{#if ventasPendientes.length}
					<div class="asig-section">
						<div class="asig-section-titulo">
							<span>{modoEntidad === 'proveedor' ? 'Asignar a compras pendientes ' : 'Asignar a ventas pendientes '}</span>
							<span style="font-weight:500;text-transform:none;letter-spacing:0">(opcional)</span>
						</div>
						<div class="asig-lista">
							{#each ventasPendientes as v (v.id)}
								{@const badge = (v.tipo_comprobante ?? (modoEntidad === 'proveedor' ? 'Compra' : 'Venta')).substring(0, 8)}
								<div class="asig-fila">
									<div class="asig-info">
										<div class="asig-tipo-num">{v.tipo_ref === 'cargo' ? (v.tipo_comprobante || 'Cargo') : `${badge} N°${pad(v.id)}`}</div>
										<div class="asig-meta">{fmtFechaStr(v.fecha)} · Saldo: {fmt(v.saldo_pendiente)}</div>
									</div>
									<div class="asig-input-wrap">
										<span class="asig-peso">$</span>
										<input
											class="asig-input"
											type="number"
											min="0"
											max={v.saldo_pendiente}
											step="any"
											value={asigMontos[claveAsig(v)] || 0}
											oninput={(e) => (asigMontos = { ...asigMontos, [claveAsig(v)]: parseFloat((e.target as HTMLInputElement).value) || 0 })}
										/>
									</div>
								</div>
							{/each}
						</div>
						<div class="asig-footer">
							<button class="btn-auto-dist" onclick={autoDistribuir}>Distribuir automáticamente</button>
							<div class="asig-total" class:asig-excede={asigExcede} class:asig-ok={!asigExcede && totalAsignadoActual > 0.001}>
								{totalAsignadoActual > 0 ? `Asignado: ${fmt(totalAsignadoActual)} de ${fmt(parseFloat(pagoMonto) || 0)}` : ''}
							</div>
						</div>
					</div>
				{:else}
					<div class="sin-pendientes-info">Esta entidad no tiene deuda pendiente — el pago quedará sin asignar a comprobantes.</div>
				{/if}
				{#if pagoError}<div style="color:#B91C1C;font-size:12px;margin-top:8px">{pagoError}</div>{/if}
			</div>
			<div class="modal-footer">
				<button class="btn-sec" onclick={cerrarModalPago}>Cancelar</button>
				<button class="btn-prim" disabled={pagoGuardando || asigExcede} onclick={confirmarPago}>
					{pagoGuardando ? 'Guardando…' : 'Registrar pago'}
				</button>
			</div>
		</div>
	</div>
{/if}

<!-- ── Modal: Detalle de remito ── -->
{#if remitoAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalRemito()}>
		<div class="modal modal-lg" role="dialog" aria-modal="true">
			<div class="modal-header" style="align-items:flex-start">
				<div style="flex:1;min-width:0">
					<div class="modal-titulo">{remitoTitulo}</div>
					<div style="font-size:12px;color:var(--gris3);margin-top:3px">{remitoMeta}</div>
				</div>
				<div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
					{#if modoEntidad !== 'proveedor'}
						<button class="btn-print" onclick={imprimirRemito}>🖶 Imprimir</button>
					{/if}
					<button class="modal-cerrar" aria-label="Cerrar" onclick={cerrarModalRemito}>×</button>
				</div>
			</div>
			<div class="modal-body">
				<div class="remito-seccion">
					<div class="remito-sec-titulo">Detalle de productos</div>
					<table class="remito-tabla">
						<thead>
							<tr>
								<th style="width:80px">Código</th>
								<th>Producto</th>
								<th class="r" style="width:70px">Cant.</th>
								<th class="r" style="width:100px">Precio unit.</th>
								<th class="r" style="width:100px">Subtotal</th>
							</tr>
						</thead>
						<tbody>
							{#if remitoCargando}
								<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--gris3)">Cargando…</td></tr>
							{:else if remitoError}
								<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--rojo)">{remitoError}</td></tr>
							{:else if !remitoItems.length}
								<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--gris3)">Sin ítems registrados</td></tr>
							{:else}
								{#each remitoItems as it, i (i)}
									<tr>
										<td class="remito-cod">{it.codigo}</td>
										<td>{it.nombre}</td>
										<td class="r">{it.cantidad.toLocaleString('es-AR', { maximumFractionDigits: 3 })}</td>
										<td class="r">{fmt(it.precio_unitario)}</td>
										<td class="r">{fmt(it.subtotal)}</td>
									</tr>
								{/each}
							{/if}
						</tbody>
					</table>
					{#if !remitoCargando && !remitoError && remitoItems.length}
						<div class="remito-totales">
							<div class="tot-row"><span class="tot-label">Subtotal</span><span class="tot-val">{fmt(remitoSubtotal)}</span></div>
							{#if remitoEnvio > 0}
								<div class="tot-row"><span class="tot-label">Envío</span><span class="tot-val">{fmt(remitoEnvio)}</span></div>
							{/if}
							<div class="tot-row tot-total"><span class="tot-label">Total</span><span class="tot-val">{fmt(remitoTotal)}</span></div>
						</div>
					{/if}
				</div>

				<div class="remito-seccion">
					<div class="remito-sec-titulo">Pagos asignados</div>
					{#if !remitoPagos.length}
						<p class="remito-sin-pagos">Sin pagos asignados a este comprobante.</p>
					{:else}
						<table class="remito-tabla">
							<thead>
								<tr><th>Fecha</th><th>Medio / Detalle</th><th class="r">Monto asignado</th></tr>
							</thead>
							<tbody>
								{#each remitoPagos as p, i (i)}
									<tr>
										<td>{fmtFechaStr(p.fecha)}</td>
										<td>
											{#if p.medio_pago}<span class="medio-badge medio-{p.medio_pago}">{p.medio_pago}</span>{/if}
											{#if p.obs}<span style="font-size:12px;color:var(--gris3);margin-left:4px">{p.obs}</span>{/if}
											{#if extraPago(p.pago_datos).length}<div style="font-size:11px;color:var(--gris3);margin-top:2px">{extraPago(p.pago_datos).join(' · ')}</div>{/if}
											{#if p.comprobante}<div style="margin-top:3px"><button class="comprobante-link" onclick={() => verComprobantePago(p.comprobante!)}>📄 Ver comprobante</button></div>{/if}
										</td>
										<td class="r" style="color:var(--verde);font-weight:600">{fmt(p.monto)}</td>
									</tr>
								{/each}
							</tbody>
						</table>
						<div class="remito-totales" style="margin-top:8px">
							<div class="tot-row"><span class="tot-label">Total pagado</span><span class="tot-val" style="color:var(--verde)">{fmt(remitoTotalPagado)}</span></div>
							<div class="tot-row" class:tot-total={remitoPendiente > 0.001}>
								<span class="tot-label">{remitoPendiente > 0.001 ? 'Saldo pendiente' : 'Saldo'}</span>
								<span class="tot-val" class:tot-pend-rojo={remitoPendiente > 0.001} class:tot-pend-ok={remitoPendiente <= 0.001}>{fmt(Math.abs(remitoPendiente))}</span>
							</div>
						</div>
					{/if}
				</div>
			</div>
		</div>
	</div>
{/if}

<!-- ── Modal: Detalle de devolución ── -->
{#if devAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalDev()}>
		<div class="modal modal-lg" role="dialog" aria-modal="true">
			<div class="modal-header" style="align-items:flex-start">
				<div style="flex:1;min-width:0">
					<div class="modal-titulo">{devTitulo}</div>
					<div style="font-size:12px;color:var(--gris3);margin-top:3px">{devMeta}</div>
				</div>
				<div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
					<button class="btn-print" onclick={imprimirDevolucion}>🖶 Imprimir</button>
					<button class="modal-cerrar" aria-label="Cerrar" onclick={cerrarModalDev}>×</button>
				</div>
			</div>
			<div class="modal-body">
				<div class="remito-seccion">
					<div class="remito-sec-titulo">Ítems devueltos</div>
					<table class="remito-tabla">
						<thead>
							<tr><th>Producto</th><th class="r" style="width:70px">Cant.</th><th class="r" style="width:110px">Precio unit.</th><th class="r" style="width:110px">Subtotal</th></tr>
						</thead>
						<tbody>
							{#if devError}
								<tr><td colspan="4" style="text-align:center;padding:16px;color:var(--rojo)">{devError}</td></tr>
							{:else if !devItems.length}
								<tr><td colspan="4" style="text-align:center;padding:16px;color:var(--gris3)">Sin ítems</td></tr>
							{:else}
								{#each devItems as it, i (i)}
									<tr>
										<td>{it.nombre}</td>
										<td class="r">{it.cantidad.toLocaleString('es-AR', { maximumFractionDigits: 3 })}</td>
										<td class="r">{fmt(it.precio_unitario)}</td>
										<td class="r">{fmt(it.cantidad * it.precio_unitario)}</td>
									</tr>
								{/each}
							{/if}
						</tbody>
					</table>
					{#if !devError}
						<div class="remito-totales">
							<div class="tot-row tot-total"><span class="tot-label">Total acreditado</span><span class="tot-val">{fmt(devTotal)}</span></div>
						</div>
					{/if}
				</div>
				{#if devMotivo}
					<div class="remito-seccion">
						<div class="remito-sec-titulo">Motivo de devolución</div>
						<p style="margin:0;font-size:13px;color:var(--neo-text-2)">{devMotivo}</p>
					</div>
				{/if}
			</div>
		</div>
	</div>
{/if}

<!-- ── Modal: Confirmar eliminación ── -->
{#if eliminarAbierto && movAEliminar}
	{@const esProveedor = modoEntidad === 'proveedor'}
	{@const encabezado = movAEliminar.tipo === 'cargo' ? (movAEliminar.ref ? `${esProveedor ? 'Compra' : 'Remito / Venta'} N°${pad(movAEliminar.ref)}` : 'Cargo manual') : 'Pago'}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalEliminar()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-header">
				<span class="modal-titulo" style="color:var(--rojo)">Eliminar movimiento</span>
				<button class="modal-cerrar" aria-label="Cerrar" onclick={cerrarModalEliminar}>×</button>
			</div>
			<div class="modal-body">
				<p class="elim-info">¿Eliminar <strong>{encabezado}</strong> del <strong>{fmtFechaStr(movAEliminar.fecha)}</strong> por <strong>{fmt(movAEliminar.monto)}</strong>?</p>
				{#if movAEliminar.tipo === 'cargo' && movAEliminar.ref}
					<p class="elim-warning">⚠ También se eliminará {esProveedor ? 'la compra' : 'la venta'} N°<strong>{pad(movAEliminar.ref)}</strong> y todos sus ítems.</p>
				{:else if movAEliminar.tipo === 'pago'}
					<p class="elim-warning">⚠ También se eliminarán las asignaciones a comprobantes vinculadas a este pago.</p>
				{/if}
				<p class="elim-aviso">Esta acción no se puede deshacer.</p>
			</div>
			<div class="modal-footer">
				<button class="btn-sec" onclick={cerrarModalEliminar}>Cancelar</button>
				<button class="btn-danger" disabled={eliminarGuardando} onclick={confirmarEliminarMov}>
					{eliminarGuardando ? 'Eliminando…' : 'Eliminar'}
				</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.cc-main { flex: 1; min-height: 0; display: grid; grid-template-columns: 370px 1fr; gap: 12px; padding: 12px; overflow: hidden; background: var(--neo-bg-deep); }
	.cc-left { display: flex; flex-direction: column; gap: 10px; overflow-y: auto; padding-right: 2px; }
	.busq-row { display: flex; align-items: stretch; gap: 8px; flex-shrink: 0; }
	.busq-wrap { position: relative; }
	.busq-wrap input {
	  width: 100%; padding: 10px 14px; font-size: 14px; border: none;
	  border-radius: var(--neo-r-sm); outline: none;
	  background: var(--neo-bg); color: var(--neo-text); font-family: inherit;
	  box-shadow: var(--neo-i1); transition: box-shadow var(--neo-t-fast);
	  box-sizing: border-box;
	}
	.busq-wrap input:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.busq-dd {
	  position: absolute; top: calc(100% + 6px); left: 0; right: 0;
	  background: var(--neo-bg); border-radius: var(--neo-r-md); box-shadow: var(--neo-e4);
	  z-index: 200; max-height: 260px; overflow-y: auto; display: none; border: none;
	}
	.busq-dd.visible { display: block; }
	.dd-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid var(--borde); color: var(--neo-text); }
	.dd-item:last-child { border-bottom: none; }
	.dd-item:hover, .dd-item.activo { background: var(--primary-soft); }
	.dd-nom { font-weight: 600; font-size: 14px; }
	.dd-det { font-size: 11px; color: var(--neo-text-3); margin-top: 2px; }

	.cli-card { background: var(--neo-bg); border-radius: var(--neo-r-lg); box-shadow: var(--neo-e2); overflow: hidden; flex-shrink: 0; border: none !important; }
	.cli-card-top { padding: 16px 16px 14px; border-bottom: 1px solid var(--borde); }
	.cli-nombre { font-size: 18px; font-weight: 800; line-height: 1.2; margin-bottom: 10px; color: var(--neo-text); }
	.cli-saldo-block { background: var(--neo-bg-deep); border-radius: var(--neo-r-md); box-shadow: var(--neo-i1); padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; }
	.cli-saldo-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.cli-saldo-val { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--neo-text); }
	.cli-saldo-val.deuda    { color: var(--neo-danger); }
	.cli-saldo-val.saldo-ok { color: var(--neo-success); }
	.cli-saldo-val.saldo-cero { color: var(--neo-text-3); }

	.cli-datos { padding: 0 16px; }
	.cli-dato { display: flex; align-items: flex-start; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--borde); font-size: 12px; color: var(--neo-text); }
	.cli-dato:last-child { border-bottom: none; }
	.cli-dato-k { flex-shrink: 0; width: 88px; color: var(--neo-text-3); font-size: 11px; padding-top: 1px; }
	.cli-dato-v { flex: 1; font-weight: 500; word-break: break-word; }

	.cli-obs-block { padding: 10px 16px 14px; border-top: 1px solid var(--borde); }
	.cli-obs-titulo { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 6px; }
	.cli-obs-texto { font-size: 12px; color: var(--neo-text); white-space: pre-wrap; line-height: 1.5; }

	.cli-card-footer { padding: 10px 16px; background: var(--neo-bg-deep); border-top: 1px solid var(--borde); display: flex; justify-content: flex-end; gap: 8px; }
	.btn-edit-ficha {
	  padding: 6px 14px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); box-shadow: var(--neo-e1);
	  font-size: 12px; font-weight: 600; color: var(--neo-text-2);
	  cursor: pointer; display: flex; align-items: center; gap: 6px;
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.btn-edit-ficha:hover { box-shadow: var(--neo-e2); color: var(--neo-accent); }

	.pend-card { background: var(--neo-bg); border-radius: var(--neo-r-lg); box-shadow: var(--neo-e2); overflow: hidden; flex-shrink: 0; border: none !important; }
	.pend-header { padding: 10px 16px; border-bottom: 1px solid var(--borde); display: flex; align-items: center; justify-content: space-between; }
	.pend-titulo { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.pend-sin-deuda { padding: 12px 16px; font-size: 12px; color: var(--neo-success); font-weight: 600; display: flex; align-items: center; gap: 6px; }
	.pend-tabla { width: 100%; border-collapse: collapse; font-size: 12px; }
	.pend-tabla th { padding: 6px 10px; text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--color-ink); background: var(--neo-bg-deep); border-bottom: 1px solid var(--borde-fuerte); }
	.pend-tabla th.r { text-align: right; }
	.pend-tabla td { padding: 6px 10px; border-bottom: 1px solid var(--borde); vertical-align: middle; color: var(--neo-text); }
	.pend-tabla td.r { text-align: right; font-variant-numeric: tabular-nums; }
	.pend-tabla tr:last-child td { border-bottom: none; }
	.pend-tabla .saldo-pend { font-weight: 700; color: var(--neo-danger); }
	.tipo-badge { display: inline-block; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; background: var(--color-bg-alt); color: var(--neo-text-3); padding: 2px 5px; border-radius: var(--neo-r-xs); margin-right: 4px; vertical-align: middle; }
	.pend-num { font-size: 11px; font-variant-numeric: tabular-nums; color: var(--neo-text-3); }
	.pend-total-row { padding: 8px 16px; background: var(--neo-bg-deep); border-top: 1px solid var(--borde); display: flex; justify-content: space-between; align-items: center; }
	.pend-total-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.pend-total-val { font-size: 14px; font-weight: 800; color: var(--neo-danger); font-variant-numeric: tabular-nums; }

	.cc-right { display: flex; flex-direction: column; min-height: 0; border-radius: var(--neo-r-lg); overflow: hidden; box-shadow: var(--neo-e2); }
	.libro-header {
	  background: var(--neo-bg); border-bottom: 1px solid var(--borde-fuerte);
	  padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
	}
	.libro-titulo { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.btn-reg-pago {
	  padding: 7px 16px; border: none; border-radius: var(--neo-r-sm);
	  background: var(--neo-accent); color: white; box-shadow: 3px 3px 7px var(--neo-accent-glow);
	  font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit;
	}
	.btn-reg-pago:hover { background: var(--neo-accent-h); }

	.btn-aging {
	  display: inline-flex; align-items: center; gap: 7px;
	  padding: 8px 14px; border-radius: 8px; border: 1.5px solid var(--borde-fuerte);
	  background: transparent; color: var(--neo-text-2); font-size: 13px; font-weight: 700;
	  cursor: pointer; font-family: inherit; transition: border-color .15s, color .15s;
	}
	.btn-aging:hover { border-color: var(--neo-accent); color: var(--neo-accent); }
	.aging-badge {
	  background: var(--neo-danger); color: #fff; font-size: 11px; font-weight: 800;
	  border-radius: 10px; padding: 1px 7px; line-height: 1.5; margin-left: 6px;
	}
	.aging-overlay { background: rgba(0,0,0,.45); backdrop-filter: none; -webkit-backdrop-filter: none; }
	.aging-modal {
	  background: #fff; border: 1px solid var(--borde-fuerte); border-radius: 0; box-shadow: none;
	  width: min(96vw, 980px); max-height: 88vh;
	  display: flex; flex-direction: column;
	}
	.aging-mheader {
	  display: flex; align-items: center; justify-content: space-between;
	  padding: 12px 20px; border-bottom: 1px solid var(--borde-fuerte); background: var(--color-bg-alt); flex-shrink: 0;
	}
	.aging-mheader h2 {
	  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px;
	  color: #9B9590; margin: 0;
	}
	.aging-mcerrar { background: none; border: none; cursor: pointer; color: #9B9590; font-size: 16px; line-height: 1; padding: 2px 6px; }
	.aging-mcerrar:hover { color: #111; }
	.aging-mbody { flex: 1; overflow-y: auto; min-height: 0; }

	.aging-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
	.aging-tabla thead th {
	  position: sticky; top: 0; padding: 8px 12px; text-align: left; font-size: 10px;
	  font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
	  color: #9B9590; background: var(--color-bg-alt);
	  border-bottom: 1px solid var(--borde-fuerte); white-space: nowrap;
	}
	.aging-tabla thead th.r { text-align: right; }
	.aging-tabla tbody td { padding: 8px 12px; border-bottom: 1px solid var(--color-bg-alt); color: #111; vertical-align: middle; }
	.aging-tabla tbody td.r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
	.aging-tabla tbody tr { cursor: pointer; }
	.aging-tabla tbody tr:hover td { background: #FAFAF9; }
	.aging-tabla td.celda-cero { color: #B5B0A9; }
	.aging-tabla td.celda-venc { color: #B91C1C; font-weight: 700; }
	.aging-tabla tfoot td { padding: 9px 12px; font-weight: 800; border-top: 2px solid var(--borde-fuerte); color: #111; font-variant-numeric: tabular-nums; }
	.aging-tabla tfoot td.r { text-align: right; }
	.venc-chip {
	  display: inline-block; font-size: 10.5px; font-weight: 800; border-radius: 0;
	  padding: 2px 7px; white-space: nowrap;
	}
	.venc-chip.rojo    { background: #FEE2E2; color: #B91C1C; }
	.venc-chip.ambar   { background: #FEF3C7; color: #B7791F; }
	.venc-chip.gris    { background: var(--color-bg-alt); color: #9B9590; }
	.pend-tabla tr.fila-vencida td { background: rgba(231,76,60,.05); }

	.cli-aging { margin: 10px 16px 0; padding: 10px 12px; border-radius: 10px; background: var(--neo-bg-deep); }
	.ca-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
	.ca-titulo { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.ca-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; background: var(--color-bg-alt); margin-bottom: 8px; }
	.ca-seg { height: 100%; }
	.ca-seg.ok  { background: var(--neo-success); }
	.ca-seg.v30 { background: #F39C12; }
	.ca-seg.v60 { background: #E67E22; }
	.ca-seg.v90 { background: var(--neo-danger); }
	.ca-seg.vmax{ background: #922B21; }
	.ca-row { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; color: var(--neo-text-2); }
	.ca-row b { font-variant-numeric: tabular-nums; }
	.ca-row .ca-venc { color: var(--neo-danger); font-weight: 700; }
	.ca-detalle { margin-top: 6px; font-size: 11px; color: var(--neo-text-3); font-variant-numeric: tabular-nums; }
	.btn-wa {
	  display: inline-flex; align-items: center; gap: 6px; margin-top: 9px;
	  padding: 6px 12px; border-radius: 0; border: none; cursor: pointer;
	  background: #25D366; color: #fff; font-size: 12px; font-weight: 700; font-family: inherit;
	  transition: opacity .15s;
	}
	.btn-wa:hover { opacity: .85; }
	.btn-wa-mini { padding: 4px 9px; font-size: 11px; margin-top: 0; }

	.libro-tabla-wrap { flex: 1; min-height: 0; overflow-y: auto; background: var(--neo-bg); }
	.libro-empty { padding: 60px 20px; text-align: center; color: var(--neo-text-3); font-size: 14px; }

	.libro-tabla { width: 100%; border-collapse: collapse; }
	.libro-tabla thead th {
	  position: sticky; top: 0; background: var(--neo-bg); z-index: 10;
	  padding: 9px 12px; text-align: left; font-size: 10px; font-weight: 700;
	  text-transform: uppercase; letter-spacing: .4px; color: var(--color-ink);
	  border-bottom: 1px solid var(--borde-fuerte);
	}
	.libro-tabla thead th.r { text-align: right; }
	.libro-tabla tbody td { border-bottom: 1px solid var(--borde); color: var(--neo-text); }
	.libro-tabla tbody tr:last-child td { border-bottom: none; }
	.libro-tabla td { padding: 9px 12px; font-size: 13px; vertical-align: top; }
	.libro-tabla td.r { text-align: right; font-variant-numeric: tabular-nums; }
	.col-fecha   { width: 88px; color: var(--neo-text-3); font-size: 12px; white-space: nowrap; }
	.col-debito  { width: 110px; text-align: right; font-variant-numeric: tabular-nums; color: var(--neo-danger); font-weight: 600; white-space: nowrap; }
	.col-asig    { width: 110px; text-align: right; font-variant-numeric: tabular-nums; color: var(--neo-success); font-size: 12px; white-space: nowrap; }
	.col-credito { width: 110px; text-align: right; font-variant-numeric: tabular-nums; color: var(--neo-success); font-weight: 600; white-space: nowrap; }
	.col-saldo   { width: 120px; text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
	.col-saldo.saldo-rojo { color: var(--neo-danger); }
	.col-saldo.saldo-verde { color: var(--neo-success); }
	.col-saldo.saldo-gris { color: var(--neo-text-3); }
	.concepto-obs { font-size: 11px; color: var(--neo-text-3); margin-top: 2px; font-style: italic; }

	.libro-tabla tr.row-cargo { cursor: pointer; }
	.libro-tabla tr.row-cargo:hover td { background: rgba(231,76,60,.04); }
	.libro-tabla tr.row-pago.row-dev { cursor: pointer; }
	.libro-tabla tr.row-pago.row-dev:hover td { background: var(--primary-soft); }
	.dev-link { color: var(--neo-accent); font-weight: 600; }

	.remito-seccion { margin-bottom: 20px; }
	.remito-seccion:last-child { margin-bottom: 0; }
	.remito-sec-titulo { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 8px; }
	.remito-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
	.remito-tabla thead th { padding: 7px 10px; text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--color-ink); background: var(--neo-bg-deep); border-bottom: 1px solid var(--borde-fuerte); white-space: nowrap; }
	.remito-tabla thead th.r { text-align: right; }
	.remito-tabla tbody td { padding: 7px 10px; border-bottom: 1px solid var(--borde); vertical-align: middle; color: var(--neo-text); }
	.remito-tabla tbody td.r { text-align: right; font-variant-numeric: tabular-nums; }
	.remito-tabla tbody tr:last-child td { border-bottom: none; }
	.remito-cod { font-family: monospace; font-size: 11px; color: var(--neo-text-3); }
	.remito-totales { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; padding: 10px 10px 0; font-size: 12px; }
	.remito-totales .tot-row { display: flex; gap: 16px; }
	.remito-totales .tot-label { color: var(--neo-text-3); }
	.remito-totales .tot-val { font-variant-numeric: tabular-nums; font-weight: 600; min-width: 100px; text-align: right; color: var(--neo-text); }
	.remito-totales .tot-total { font-size: 14px; font-weight: 800; }
	.remito-totales .tot-pend-rojo { color: var(--neo-danger); }
	.remito-totales .tot-pend-ok   { color: var(--neo-success); }
	.remito-sin-pagos { font-size: 12px; color: var(--neo-text-3); padding: 8px 0; }
	.btn-print {
	  padding: 6px 14px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); box-shadow: var(--neo-e1); font-size: 12px; font-weight: 600;
	  cursor: pointer; color: var(--neo-text-2); display: flex; align-items: center; gap: 6px;
	  transition: box-shadow var(--neo-t-fast);
	}
	.btn-print:hover { box-shadow: var(--neo-e2); }

	.overlay {
	  position: fixed; inset: 0; display: none; align-items: center; justify-content: center;
	  z-index: 1000; padding: 20px;
	  background: rgba(49,52,75,.48); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
	}
	.overlay.abierto { display: flex; }
	.modal { background: var(--neo-bg); border-radius: var(--neo-r-xl); box-shadow: var(--neo-e4); display: flex; flex-direction: column; max-height: 90vh; border: none !important; }
	.modal-sm { width: 480px; }
	.modal-lg { width: 660px; }

	.modal-header { padding: 16px 20px 14px; border-bottom: 1px solid var(--borde-fuerte); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
	.modal-titulo { font-size: 16px; font-weight: 800; flex: 1; color: var(--neo-text); }
	.modal-subtitulo { font-size: 12px; color: var(--neo-text-3); font-weight: 500; }
	.modal-cerrar {
	  width: 28px; height: 28px; background: var(--neo-bg); border: none; border-radius: var(--neo-r-xs);
	  box-shadow: var(--neo-e1); font-size: 16px; cursor: pointer;
	  display: flex; align-items: center; justify-content: center; color: var(--neo-text-2); flex-shrink: 0;
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.modal-cerrar:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
	.modal-body { padding: 20px; overflow-y: auto; flex: 1; }
	.modal-footer { padding: 14px 20px; border-top: 1px solid var(--borde-fuerte); display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-shrink: 0; }

	.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
	.form-campo { display: flex; flex-direction: column; gap: 5px; }
	.form-campo.span2 { grid-column: span 2; }
	.form-campo label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.form-campo input, .form-campo select {
	  padding: 9px 11px; border: none; border-radius: var(--neo-r-sm);
	  font-size: 13px; font-family: inherit; outline: none;
	  background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-i1);
	  transition: box-shadow var(--neo-t-fast); box-sizing: border-box; width: 100%;
	}
	.form-campo input:focus, .form-campo select:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }

	.btn-prim {
	  padding: 9px 18px; border: none; border-radius: var(--neo-r-sm);
	  background: var(--neo-accent); color: white; box-shadow: 4px 4px 10px var(--neo-accent-glow), -2px -2px 5px rgba(255,255,255,.15);
	  font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
	}
	.btn-prim:hover { background: var(--neo-accent-h); }
	.btn-prim:disabled { opacity: .5; cursor: not-allowed; }
	.btn-sec {
	  padding: 9px 18px; border: none; border-radius: var(--neo-r-sm);
	  background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-e2);
	  font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit;
	  transition: box-shadow var(--neo-t-fast);
	}
	.btn-sec:hover { box-shadow: var(--neo-e3); }

	.medio-badge { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; padding: 2px 6px; border-radius: var(--neo-r-xs); vertical-align: middle; margin-left: 4px; box-shadow: var(--neo-e1); }
	.medio-efectivo      { background: rgba(39,174,96,.1);  color: var(--neo-success); }
	.medio-transferencia { background: var(--primary-soft-2); color: var(--neo-accent);  }
	.medio-cheque        { background: rgba(243,156,18,.1); color: var(--neo-warning); }

	.pago-form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 12px; }
	.medio-extra {
	  background: var(--neo-bg-deep); border-radius: var(--neo-r-md); box-shadow: var(--neo-i1);
	  padding: 14px; margin-bottom: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; border: none;
	}
	.medio-extra.tres-col { grid-template-columns: 1fr 1fr 1fr; }
	.medio-extra .span2 { grid-column: span 2; }
	.file-drop {
	  position: relative; border: 2px dashed var(--borde-fuerte); border-radius: var(--neo-r-sm);
	  padding: 10px 14px; text-align: center; cursor: pointer;
	  background: var(--neo-bg); box-shadow: var(--neo-i1); transition: border-color var(--neo-t-fast);
	}
	.file-drop:hover { border-color: var(--neo-accent); }
	.file-drop input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
	.file-drop-txt { font-size: 12px; color: var(--neo-text-3); pointer-events: none; }
	.pago-obs-row { margin-bottom: 14px; }
	.comprobante-link { font-size: 12px; color: var(--neo-accent); text-decoration: none; display: inline-flex; align-items: center; gap: 4px; background: none; border: none; cursor: pointer; padding: 0; }
	.comprobante-link:hover { text-decoration: underline; }

	.asig-section { border-top: 1px solid var(--borde-fuerte); padding-top: 16px; margin-top: 4px; }
	.asig-section-titulo { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 10px; }
	.asig-lista { display: flex; flex-direction: column; gap: 8px; max-height: 240px; overflow-y: auto; margin-bottom: 10px; }
	.asig-fila { background: var(--neo-bg-deep); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1); padding: 8px 12px; display: flex; align-items: center; gap: 12px; border: none; }
	.asig-info { flex: 1; min-width: 0; }
	.asig-tipo-num { font-size: 12px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--neo-text); }
	.asig-meta { font-size: 11px; color: var(--neo-text-3); margin-top: 2px; }
	.asig-input-wrap { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
	.asig-peso { font-size: 12px; color: var(--neo-text-3); font-weight: 600; }
	.asig-input {
	  width: 100px; padding: 6px 8px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 13px; font-family: inherit; outline: none; text-align: right;
	  font-variant-numeric: tabular-nums; background: var(--neo-bg); color: var(--neo-text);
	  box-shadow: var(--neo-i1); transition: box-shadow var(--neo-t-fast);
	}
	.asig-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 6px; }
	.btn-auto-dist {
	  padding: 5px 12px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); box-shadow: var(--neo-e1); font-size: 12px; font-weight: 600;
	  cursor: pointer; color: var(--neo-text-2); transition: box-shadow var(--neo-t-fast);
	}
	.btn-auto-dist:hover { box-shadow: var(--neo-e2); color: var(--neo-accent); }
	.asig-total { font-size: 12px; font-weight: 600; }
	.asig-total.asig-ok     { color: var(--neo-success); }
	.asig-total.asig-excede { color: var(--neo-danger); }
	.sin-pendientes-info { font-size: 12px; color: var(--neo-text-3); text-align: center; padding: 12px 0; }

	.col-acciones { width: 62px; white-space: nowrap; }
	.btn-del-mov { width: 26px; height: 26px; background: none; border: none; border-radius: var(--neo-r-xs); color: var(--neo-text-3); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .12s, background .12s, color .12s; padding: 0; }
	.row-cargo:hover .btn-del-mov,
	.row-pago:hover .btn-del-mov { opacity: 1; }
	.btn-del-mov:hover { background: rgba(231,76,60,.1); color: var(--neo-danger); }
	.btn-print-recibo { width: 26px; height: 26px; background: none; border: none; border-radius: var(--neo-r-xs); color: var(--neo-text-3); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .12s, background .12s, color .12s; padding: 0; }
	.row-pago:hover .btn-print-recibo { opacity: 1; }
	.btn-print-recibo:hover { background: var(--primary-soft-2); color: var(--neo-accent); }

	.btn-danger {
	  padding: 9px 18px; border: none; border-radius: var(--neo-r-sm);
	  background: var(--neo-danger); color: white; box-shadow: 4px 4px 10px var(--neo-danger-glow), -2px -2px 5px rgba(255,255,255,.15);
	  font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
	}
	.btn-danger:hover { background: var(--neo-danger-h); }
	.btn-danger:disabled { opacity: .5; cursor: not-allowed; }

	.elim-info { font-size: 13px; line-height: 1.6; margin-bottom: 10px; color: var(--neo-text); }
	.elim-warning { font-size: 12px; color: var(--neo-warning); background: rgba(243,156,18,.1); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1); padding: 8px 10px; margin-bottom: 8px; border: none; }
	.elim-aviso { font-size: 12px; color: var(--neo-text-3); }

	.cc-limite-warn {
	  background: rgba(231,76,60,.08); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1);
	  padding: 7px 12px; margin-top: 8px; border: none;
	  display: flex; align-items: center; gap: 6px;
	  font-size: 12px; font-weight: 600; color: var(--neo-danger);
	}
	.pago-limit-banner {
	  background: rgba(231,76,60,.08); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1);
	  padding: 10px 14px; margin-bottom: 12px; border: none;
	  display: flex; align-items: center; gap: 8px;
	  font-size: 12px; font-weight: 600; color: var(--neo-danger);
	}

	.cc-lista-wrap { display: flex; flex-direction: column; gap: 0; }
	.cc-lista-hdr {
	  display: flex; align-items: center; justify-content: space-between;
	  padding: 0 2px 8px;
	}
	.cc-lista-tit { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.cc-sort-btns { display: flex; gap: 4px; }
	.cc-sort-btn {
	  padding: 3px 9px; border-radius: var(--neo-r-xs); border: none;
	  font-size: 11px; font-weight: 600; cursor: pointer; font-family: inherit;
	  background: var(--neo-bg); color: var(--neo-text-2); box-shadow: var(--neo-e1);
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.cc-sort-btn:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
	.cc-sort-btn.activo { box-shadow: var(--neo-i1); color: var(--neo-accent); }
	.cc-lista { background: var(--neo-bg); border-radius: var(--neo-r-lg); box-shadow: var(--neo-e2); overflow: hidden; }
	.cc-li {
	  display: flex; align-items: center; gap: 10px;
	  padding: 10px 14px; cursor: pointer;
	  border-bottom: 1px solid var(--borde);
	  transition: background var(--neo-t-fast);
	}
	.cc-li:last-child { border-bottom: none; }
	.cc-li:hover { background: var(--primary-soft); }
	.cc-li-izq { min-width: 0; flex: 1; }
	.cc-li-nombre { font-weight: 600; font-size: 13px; color: var(--neo-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
	.cc-li-sub { font-size: 10px; color: var(--neo-text-3); margin-top: 1px; }
	.cc-li-der { text-align: right; flex-shrink: 0; }
	.cc-li-saldo { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
	.cc-li-saldo.deuda    { color: var(--neo-danger); }
	.cc-li-saldo.saldo-ok { color: var(--neo-success); }
	.cc-li-saldo.saldo-cero { color: var(--neo-text-3); }
	.cc-li-fecha { font-size: 10px; color: var(--neo-text-3); margin-top: 1px; }
	.cc-lista-msg { padding: 24px 14px; text-align: center; font-size: 13px; color: var(--neo-text-3); }
	.cc-lista-pag {
	  display: flex; align-items: center; justify-content: center; gap: 8px;
	  padding: 8px 0 0;
	}
	.cc-pag-btn {
	  padding: 4px 12px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); box-shadow: var(--neo-e1); cursor: pointer;
	  font-size: 13px; font-weight: 600; color: var(--neo-text-2); font-family: inherit;
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.cc-pag-btn:hover:not(:disabled) { box-shadow: var(--neo-e2); color: var(--neo-text); }
	.cc-pag-btn:disabled { opacity: .35; cursor: default; }
	.cc-pag-info { font-size: 11px; color: var(--neo-text-3); min-width: 70px; text-align: center; }
	.btn-volver-lista {
	  padding: 6px 12px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); box-shadow: var(--neo-e1);
	  font-size: 12px; font-weight: 600; color: var(--neo-text-2);
	  cursor: pointer; font-family: inherit; margin-right: auto;
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.btn-volver-lista:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
</style>
