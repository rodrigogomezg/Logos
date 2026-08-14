<script lang="ts">
	import { onMount } from 'svelte';
	import { goto, beforeNavigate } from '$app/navigation';
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';
	import { leerSesion } from '$lib/session';
	import { cajaOperativaId } from '$lib/operativa';
	import { setTourSteps, type TourStep } from '$lib/tour';

	type Escala = { desde: number; precio: number };
	type CartItem = {
		producto_id: number;
		nombre: string;
		codigo: string;
		cantidad: number;
		precio_unitario: number;
		precio_lista?: number;
		precio_original?: number | null;
		ajuste_desc?: string | null;
		ajuste_tipo?: 'desc' | 'inc';
		ajuste_unit?: 'pct' | 'fijo';
		ajuste_val?: number;
		ajuste_visible?: boolean;
		escalas?: Escala[];
	};
	type ProductoBusqueda = {
		id: number;
		codigo: string;
		nombre: string;
		precio_venta: number;
		stock_actual: number;
		marca?: string | null;
		proveedor?: string | null;
		categoria?: string | null;
		escalas?: Escala[];
	};
	type DomicilioEnvio = { etiqueta?: string; domicilio?: string; localidad?: string; provincia?: string };
	type Cliente = {
		id: number;
		nombre: string;
		cuit?: string | null;
		condicion_iva?: string | null;
		saldo_cuenta_corriente: number;
		limite_credito: number;
		domicilios_envio?: DomicilioEnvio[];
	};
	type PagoMixtoLinea = { tipo: string; monto: string };

	// ── Carrito ID (reservas de stock) ──────────────────────────────
	const CARRITO_ID = (() => {
		const k = 'logos_carrito_id';
		let id = sessionStorage.getItem(k);
		if (!id) {
			id = crypto.randomUUID();
			sessionStorage.setItem(k, id);
		}
		return id;
	})();

	// ── Utilidades ───────────────────────────────────────────────
	function fmt(n: number | string | null | undefined): string {
		return '$ ' + Number(n ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	// ── Estado principal ─────────────────────────────────────────
	let items = $state<CartItem[]>([]);
	let clienteActual = $state<Cliente | null>(null);
	let seleccionados = $state<Set<number>>(new Set());
	let editandoVentaId = $state<number | null>(null);
	let ultimaVentaData = $state<{ id: number; tipo_comprobante: string; numero: string; total: number } | null>(null);
	let afipGuardActivo = $state(false);
	let afipWarnMsg = $state('Este tipo de comprobante requiere cliente con CUIT registrado.');

	const TIPOS_PAGO = [
		{ val: 'efectivo', lbl: 'Efectivo' },
		{ val: 'tarjeta', lbl: 'Tarjeta' },
		{ val: 'transferencia', lbl: 'Transferencia' },
		{ val: 'cc', lbl: 'Cta. Cte.' },
		{ val: 'cheque', lbl: 'Cheque' },
		{ val: 'mercado_pago', lbl: 'Mercado Pago' }
	];
	const PAGO_ICONS: Record<string, string> = {
		efectivo: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><line x1="6" y1="9" x2="6" y2="15"/><line x1="18" y1="9" x2="18" y2="15"/>',
		tarjeta: '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/><line x1="5" y1="15" x2="10" y2="15"/>',
		transferencia: '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
		cc: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		cheque: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 15 11 17 15 13"/>',
		mercado_pago: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="5.5" y="5.5" width="3" height="3" fill="currentColor" stroke="none"/><rect x="15.5" y="5.5" width="3" height="3" fill="currentColor" stroke="none"/><rect x="5.5" y="15.5" width="3" height="3" fill="currentColor" stroke="none"/><path d="M14 14h3v3h-3M20 14v3M17 20h3M20 17v4"/>'
	};

	let tipoPagoSel = $state('efectivo');
	let esMixto = $state(false);
	let pagosMixto = $state<PagoMixtoLinea[]>([]);
	let pagosMixtoTemp = $state<PagoMixtoLinea[]>([]);
	let posChqNumero = $state('');
	let posChqBanco = $state('');
	let posChqLibrador = $state('');
	let posChqCuit = $state('');
	let posChqEmision = $state('');
	let posChqVenc = $state('');

	let tipoComp = $state('REMITO');
	let fechaVenta = $state('');
	let observaciones = $state('');
	let selVendedorId = $state('');
	let vendedores = $state<{ id: number; nombre: string }[]>([]);

	let chkEnvio = $state(false);
	let envioPrecio = $state('');
	let envioDir = $state('');
	let envioDirOpciones = $state<DomicilioEnvio[] | null>(null);
	let envioDirSelIdx = $state('');

	function todayStr(): string {
		const d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	const fechaModificada = $derived(fechaVenta !== todayStr());

	function subtotalProductos(): number {
		return items.reduce((s, i) => s + i.cantidad * i.precio_unitario, 0);
	}
	function totalConEnvio(): number {
		const envio = chkEnvio ? parseFloat(envioPrecio) || 0 : 0;
		return subtotalProductos() + envio;
	}
	const totalUnidades = $derived.by(() => {
		const c = items.reduce((s, i) => s + i.cantidad, 0);
		return c % 1 === 0 ? String(c) : c.toFixed(2);
	});

	// ── Búsqueda por código exacto ────────────────────────────────
	let inputCodigo = $state('');
	let codEstado = $state<'ok' | 'err' | ''>('');
	let codInputEl: HTMLInputElement | undefined = $state();

	async function fetchCodigo(cod: string) {
		try {
			const r = await api(`/productos?q=${encodeURIComponent(cod)}&limit=10&carrito_id=${encodeURIComponent(CARRITO_ID)}`);
			const d: ProductoBusqueda[] = await r.json();
			if (!Array.isArray(d)) return;
			const exacto = d.find((p) => p.codigo.toLowerCase() === cod.toLowerCase());
			if (exacto) {
				agregarProducto(exacto);
				inputCodigo = '';
				codEstado = 'ok';
				codInputEl?.focus();
				setTimeout(() => (codEstado = ''), 400);
			} else {
				codEstado = 'err';
				setTimeout(() => (codEstado = ''), 700);
				toast_(`Código "${cod}" no encontrado — F3 para buscar por nombre`, 'err');
			}
		} catch {
			toast_('Error al buscar', 'err');
		}
	}
	function onCodigoKeydown(e: KeyboardEvent) {
		if (e.key === 'Enter') {
			e.preventDefault();
			const q = inputCodigo.trim();
			if (q) fetchCodigo(q);
		}
		if (e.key === 'Escape') {
			inputCodigo = '';
			codEstado = '';
		}
	}

	// ── Búsqueda rápida (F2) ─────────────────────────────────────
	let inputRapido = $state('');
	let inputRapidoEl: HTMLInputElement | undefined = $state();
	let ddRapidoVisible = $state(false);
	let ddRapidoResultados = $state<ProductoBusqueda[]>([]);
	let ddRapidoIdx = $state(-1);
	let busqRapidaTimer: ReturnType<typeof setTimeout>;

	function onInputRapido() {
		clearTimeout(busqRapidaTimer);
		const q = inputRapido.trim();
		if (q.length < 2) {
			ddRapidoVisible = false;
			ddRapidoIdx = -1;
			return;
		}
		busqRapidaTimer = setTimeout(() => fetchRapido(q), 250);
	}
	async function fetchRapido(q: string) {
		try {
			const r = await api(`/productos?q=${encodeURIComponent(q)}&limit=25&carrito_id=${encodeURIComponent(CARRITO_ID)}`);
			const d = await r.json();
			if (!Array.isArray(d) || !d.length) {
				ddRapidoVisible = false;
				return;
			}
			ddRapidoIdx = -1;
			ddRapidoResultados = d;
			ddRapidoVisible = true;
		} catch {
			toast_('Error al buscar', 'err');
		}
	}
	function agregarYCerrarRapido(p: ProductoBusqueda) {
		agregarProducto(p);
		ddRapidoVisible = false;
		inputRapido = '';
	}
	function onRapidoKeydown(e: KeyboardEvent) {
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			ddRapidoIdx = Math.max(-1, Math.min(ddRapidoResultados.length - 1, ddRapidoIdx + 1));
		}
		if (e.key === 'ArrowUp') {
			e.preventDefault();
			ddRapidoIdx = Math.max(-1, Math.min(ddRapidoResultados.length - 1, ddRapidoIdx - 1));
		}
		if (e.key === 'Enter') {
			e.preventDefault();
			const p = ddRapidoResultados[ddRapidoIdx] ?? ddRapidoResultados[0];
			if (p) agregarYCerrarRapido(p);
		}
		if (e.key === 'Escape') {
			ddRapidoVisible = false;
			inputRapido = '';
		}
	}

	// ── Búsqueda de cliente (F4) ───────────────────────────────────
	let inputCliente = $state('');
	let inputClienteEl: HTMLInputElement | undefined = $state();
	let mostrarBuscadorCli = $state(true);
	let ddCliVisible = $state(false);
	let ddCliResultados = $state<Cliente[]>([]);
	let ddCliIdx = $state(-1);
	let busqCliTimer: ReturnType<typeof setTimeout>;

	function onInputCliente() {
		clearTimeout(busqCliTimer);
		const q = inputCliente.trim();
		if (q.length < 2) {
			ddCliVisible = false;
			ddCliIdx = -1;
			return;
		}
		busqCliTimer = setTimeout(() => fetchClientes(q), 250);
	}
	async function fetchClientes(q: string) {
		try {
			const r = await api(`/clientes?q=${encodeURIComponent(q)}&limit=8`);
			const d = await r.json();
			if (!Array.isArray(d) || !d.length) {
				ddCliVisible = false;
				return;
			}
			ddCliIdx = -1;
			ddCliResultados = d;
			ddCliVisible = true;
		} catch {
			toast_('Error al buscar clientes', 'err');
		}
	}
	function onClienteKeydown(e: KeyboardEvent) {
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			ddCliIdx = Math.max(-1, Math.min(ddCliResultados.length - 1, ddCliIdx + 1));
		}
		if (e.key === 'ArrowUp') {
			e.preventDefault();
			ddCliIdx = Math.max(-1, Math.min(ddCliResultados.length - 1, ddCliIdx - 1));
		}
		if (e.key === 'Enter') {
			e.preventDefault();
			const c = ddCliResultados[ddCliIdx] ?? ddCliResultados[0];
			if (c) seleccionarCliente(c);
		}
		if (e.key === 'Escape') {
			ddCliVisible = false;
			inputCliente = '';
		}
	}
	function seleccionarCliente(c: Cliente) {
		clienteActual = c;
		mostrarBuscadorCli = false;
		ddCliVisible = false;
		checkAfipGuard();
		actualizarLimiteCC();
		setTimeout(() => codInputEl?.focus(), 50);
	}
	function quitarCliente() {
		clienteActual = null;
		inputCliente = '';
		mostrarBuscadorCli = true;
		checkAfipGuard();
		actualizarLimiteCC();
	}

	// ── Modal nuevo cliente ──────────────────────────────────────
	let ncAbierto = $state(false);
	let ncNombre = $state('');
	let ncCuit = $state('');
	let ncCondicion = $state('');
	let ncDomicilio = $state('');
	let ncProvincia = $state('');
	let ncTelefono = $state('');
	let ncEmail = $state('');
	let ncGuardando = $state(false);

	function abrirModalNuevoCli() {
		ncNombre = '';
		ncCuit = '';
		ncCondicion = '';
		ncDomicilio = '';
		ncProvincia = '';
		ncTelefono = '';
		ncEmail = '';
		ncAbierto = true;
	}
	function cerrarModalNuevoCli() {
		ncAbierto = false;
	}
	async function guardarNuevoCli() {
		const nombre = ncNombre.trim();
		if (!nombre) {
			toast_('El nombre es requerido', 'err');
			return;
		}
		ncGuardando = true;
		try {
			const r = await api('/clientes', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					nombre,
					cuit: ncCuit.trim() || null,
					condicion_iva: ncCondicion || null,
					domicilio: ncDomicilio.trim() || null,
					provincia: ncProvincia.trim() || null,
					telefono: ncTelefono.trim() || null,
					email: ncEmail.trim() || null
				})
			});
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al guardar');
			cerrarModalNuevoCli();
			seleccionarCliente(d);
			toast_(`Cliente "${nombre}" creado`, 'ok');
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al guardar', 'err');
		} finally {
			ncGuardando = false;
		}
	}

	// ── Render items / carrito ─────────────────────────────────────
	function precioEscala(item: CartItem): number {
		if (!item.escalas || item.escalas.length === 0) return item.precio_lista ?? item.precio_unitario;
		let precio = item.precio_lista ?? item.precio_unitario;
		for (const e of item.escalas) {
			if (item.cantidad >= e.desde) precio = e.precio;
			else break;
		}
		return precio;
	}
	function aplicarEscalaAlItem(item: CartItem) {
		if (!item.escalas || item.escalas.length === 0) return;
		const p = precioEscala(item);
		if (p !== item.precio_unitario) {
			item.precio_unitario = p;
			item.precio_lista = p;
		}
	}

	function agregarProducto(p: ProductoBusqueda) {
		const precio = parseFloat(String(p.precio_venta));
		const escalas = (p.escalas || []).slice().sort((a, b) => a.desde - b.desde);
		const idx = items.findIndex((i) => i.producto_id === p.id);
		if (idx !== -1) {
			items[idx].cantidad += 1;
			aplicarEscalaAlItem(items[idx]);
		} else {
			items.push({ producto_id: p.id, nombre: p.nombre, codigo: p.codigo, cantidad: 1, precio_unitario: precio, precio_lista: precio, escalas });
		}
		ocultarUltimaVenta();
		sincronizarReservas();
		toast_(`+1  ${p.nombre}`);
	}

	function onItemCantidadInput(idx: number, valor: string) {
		const val = parseFloat(valor);
		if (isNaN(val) || val < 0) return;
		if (val === 0) {
			seleccionados.delete(items[idx].producto_id);
			items.splice(idx, 1);
		} else {
			items[idx].cantidad = val;
			aplicarEscalaAlItem(items[idx]);
		}
		sincronizarReservas();
	}
	function onItemPrecioInput(idx: number, valor: string) {
		const val = parseFloat(valor);
		if (isNaN(val) || val < 0) return;
		items[idx].precio_unitario = val;
	}
	function cambiarCantidad(idx: number, delta: number) {
		const nueva = Math.round((items[idx].cantidad + delta) * 1000) / 1000;
		if (nueva <= 0) {
			seleccionados.delete(items[idx].producto_id);
			items.splice(idx, 1);
		} else {
			items[idx].cantidad = nueva;
			aplicarEscalaAlItem(items[idx]);
		}
		sincronizarReservas();
	}
	function eliminarItem(idx: number) {
		seleccionados.delete(items[idx].producto_id);
		items.splice(idx, 1);
		sincronizarReservas();
	}
	function toggleItemChk(pid: number, checked: boolean) {
		if (checked) seleccionados.add(pid);
		else seleccionados.delete(pid);
	}

	const todosMarcados = $derived(items.length > 0 && seleccionados.size === items.length);
	const algunosMarcados = $derived(seleccionados.size > 0 && seleccionados.size < items.length);
	function toggleTodos(checked: boolean) {
		if (checked) items.forEach((i) => seleccionados.add(i.producto_id));
		else seleccionados.clear();
	}

	// ── Reservas de stock (debounce 400ms) ──────────────────────────
	let reservaTimer: ReturnType<typeof setTimeout>;
	function sincronizarReservas() {
		clearTimeout(reservaTimer);
		if (!items.length) {
			fetch(apiPath(`/reservas/${encodeURIComponent(CARRITO_ID)}`), { method: 'DELETE', headers: authHeaders() }).catch(() => {});
			return;
		}
		reservaTimer = setTimeout(async () => {
			try {
				const r = await api('/reservas', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ carrito_id: CARRITO_ID, items: items.map((i) => ({ producto_id: i.producto_id, cantidad: i.cantidad })) })
				});
				if (!r.ok) return;
				const d = await r.json();
				if (d.bloqueados?.length) {
					const lista = d.bloqueados.map((b: { producto_id: number; disponible: number; pedido: number }) => `• ${b.producto_id}: disponible ${b.disponible}, pedido ${b.pedido}`).join('\n');
					toast_(`Stock insuficiente para ${d.bloqueados.length} producto(s):\n${lista}`, 'err');
				}
			} catch {
				/* no bloquea el flujo */
			}
		}, 400);
	}
	function apiPath(p: string): string {
		return '/Logos/api' + p;
	}
	function authHeaders(): HeadersInit {
		const s = leerSesion();
		return s ? { 'X-Auth-Token': s.token } : {};
	}

	// ── Selección de ítems: ajuste masivo ───────────────────────────
	let selAjTipo = $state<'desc' | 'inc'>('desc');
	let selAjUnit = $state<'pct' | 'fijo'>('pct');
	let selAjVal = $state('');
	let selVisible = $state(true);

	$effect(() => {
		const n = seleccionados.size;
		if (n === 0) {
			return;
		}
		const seleccionadosItems = items.filter((i) => seleccionados.has(i.producto_id));
		const primero = seleccionadosItems[0];
		const mismoAjuste =
			primero?.ajuste_val !== undefined &&
			seleccionadosItems.every((i) => i.ajuste_val === primero.ajuste_val && i.ajuste_tipo === primero.ajuste_tipo && i.ajuste_unit === primero.ajuste_unit);
		if (mismoAjuste) {
			selAjVal = String(primero.ajuste_val);
			selAjTipo = primero.ajuste_tipo!;
			selAjUnit = primero.ajuste_unit!;
		}
	});

	function aplicarAjusteSeleccion() {
		if (!seleccionados.size) {
			toast_('Marcá al menos un ítem antes de aplicar el ajuste', 'err');
			return;
		}
		const val = parseFloat(selAjVal);
		if (isNaN(val) || val <= 0) {
			toast_('Ingresá un valor mayor a 0', 'err');
			return;
		}
		const isDesc = selAjTipo === 'desc';
		const n = seleccionados.size;
		items.forEach((item) => {
			if (!seleccionados.has(item.producto_id)) return;
			const base = item.precio_original ?? item.precio_unitario;
			let nuevo = selAjUnit === 'pct' ? base * (isDesc ? 1 - val / 100 : 1 + val / 100) : isDesc ? base - val : base + val;
			nuevo = Math.max(0, Math.round(nuevo * 100) / 100);
			item.precio_original = base;
			item.precio_unitario = nuevo;
			item.ajuste_desc = `${isDesc ? '−' : '+'}${val}${selAjUnit === 'pct' ? '%' : '$'}`;
			item.ajuste_tipo = selAjTipo;
			item.ajuste_unit = selAjUnit;
			item.ajuste_val = val;
			item.ajuste_visible = selVisible;
		});
		toast_(`Ajuste aplicado a ${n} ítem${n > 1 ? 's' : ''}`);
	}
	function limpiarAjusteSeleccion() {
		if (!seleccionados.size) return;
		const n = seleccionados.size;
		items.forEach((item) => {
			if (!seleccionados.has(item.producto_id)) return;
			if (item.precio_original !== undefined && item.precio_original !== null) item.precio_unitario = item.precio_original;
			item.precio_original = null;
			item.ajuste_desc = null;
			item.ajuste_tipo = undefined;
			item.ajuste_unit = undefined;
			item.ajuste_val = undefined;
		});
		selAjVal = '';
		toast_(`Ajuste eliminado de ${n} ítem${n > 1 ? 's' : ''}`);
	}
	async function restablecerPrecioLista() {
		if (!seleccionados.size) return;
		const selItems = items.filter((i) => seleccionados.has(i.producto_id));
		const sinLista = selItems.filter((i) => i.precio_lista === undefined);
		if (sinLista.length) {
			try {
				await Promise.all(
					sinLista.map(async (item) => {
						const r = await api(`/productos/${item.producto_id}`);
						if (!r.ok) return;
						const p = await r.json();
						item.precio_lista = parseFloat(p.precio_venta);
					})
				);
			} catch {
				toast_('Error al obtener precio de lista', 'err');
				return;
			}
		}
		const n = selItems.length;
		selItems.forEach((item) => {
			if (item.precio_lista === undefined) return;
			item.precio_unitario = item.precio_lista;
			item.precio_original = null;
			item.ajuste_desc = null;
			item.ajuste_tipo = undefined;
			item.ajuste_unit = undefined;
			item.ajuste_val = undefined;
		});
		selAjVal = '';
		toast_(`Precio de lista restaurado en ${n} ítem${n > 1 ? 's' : ''}`);
	}
	function onSelVisibleChange(checked: boolean) {
		selVisible = checked;
		if (!seleccionados.size) return;
		items.forEach((item) => {
			if (seleccionados.has(item.producto_id) && item.ajuste_desc) item.ajuste_visible = checked;
		});
	}

	// ── Envío ─────────────────────────────────────────────────────
	function onChkEnvioChange(checked: boolean) {
		chkEnvio = checked;
		if (checked) {
			autocompletarEnvio();
		} else {
			envioDir = '';
			envioDirOpciones = null;
		}
	}
	function autocompletarEnvio() {
		const dirs = clienteActual?.domicilios_envio;
		if (!dirs || !dirs.length) return;
		const fmtDir = (d: DomicilioEnvio) => [d.domicilio, d.localidad, d.provincia].filter(Boolean).join(', ');
		if (dirs.length === 1) {
			envioDir = fmtDir(dirs[0]);
			envioDirOpciones = null;
			return;
		}
		envioDirOpciones = dirs;
		envioDirSelIdx = '0';
		envioDir = fmtDir(dirs[0]);
	}
	function onEnvioDirSelChange() {
		if (!envioDirOpciones) return;
		if (envioDirSelIdx === 'manual') {
			envioDirOpciones = null;
			envioDir = '';
		} else {
			const fmtDir = (d: DomicilioEnvio) => [d.domicilio, d.localidad, d.provincia].filter(Boolean).join(', ');
			envioDir = fmtDir(envioDirOpciones[parseInt(envioDirSelIdx)]);
		}
	}

	// ── AFIP guard ────────────────────────────────────────────────
	function checkAfipGuard() {
		const tieneCuit = !!clienteActual?.cuit;
		const esRI = clienteActual?.condicion_iva === 'Responsable Inscripto';

		if (tipoComp !== 'FC A-ELECT' && tipoComp !== 'FC B-ELECT' && tipoComp !== 'FC C-ELECT') {
			afipGuardActivo = false;
			return;
		}
		if (tipoComp === 'FC A-ELECT') {
			if (tieneCuit && esRI) {
				afipGuardActivo = false;
			} else {
				afipGuardActivo = true;
				afipWarnMsg = !tieneCuit ? 'FC A-ELECT requiere cliente con CUIT y condición Responsable Inscripto.' : 'El cliente no tiene condición Responsable Inscripto (requerida para FC A-ELECT).';
			}
		} else {
			afipGuardActivo = false;
		}
	}

	// ── Límite de crédito CC ────────────────────────────────────────
	let ccAlertaTexto = $state('');
	let ccAlertaVisible = $state(false);
	function actualizarLimiteCC() {
		const pagoActual = esMixto ? 'mixto' : tipoPagoSel;
		if (items.length === 0 || pagoActual !== 'cc' || !clienteActual) {
			ccAlertaVisible = false;
			return;
		}
		const limite = parseFloat(String(clienteActual.limite_credito)) || 0;
		const saldo = parseFloat(String(clienteActual.saldo_cuenta_corriente)) || 0;
		const total = totalConEnvio();
		const disponible = limite - saldo;
		if (limite > 0 && total > disponible) {
			const fmtN = (n: number) => n.toLocaleString('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 2 });
			ccAlertaTexto = `Límite de crédito insuficiente|Saldo actual: ${fmtN(saldo)} · Límite: ${fmtN(limite)}|Disponible: ${fmtN(disponible)} · Esta venta: ${fmtN(total)}`;
			ccAlertaVisible = true;
		} else {
			ccAlertaVisible = false;
		}
	}

	const btnConfirmarDisabled = $derived(items.length === 0 || afipGuardActivo);

	// ── Pago mixto ────────────────────────────────────────────────
	let pmAbierto = $state(false);
	function sumTemp(excluir = -1): number {
		return pagosMixtoTemp.reduce((s, p, i) => (i === excluir ? s : s + (parseFloat(p.monto) || 0)), 0);
	}
	const pmRestante = $derived(totalConEnvio() - pagosMixtoTemp.reduce((s, p) => s + (parseFloat(p.monto) || 0), 0));
	const pmCubierto = $derived(Math.abs(pmRestante) < 0.005);
	function abrirPM() {
		pagosMixtoTemp = esMixto
			? JSON.parse(JSON.stringify(pagosMixto))
			: [
					{ tipo: tipoPagoSel, monto: '' },
					{ tipo: TIPOS_PAGO.find((t) => t.val !== tipoPagoSel)?.val ?? 'transferencia', monto: '' }
				];
		pmAbierto = true;
	}
	function cerrarPM() {
		pmAbierto = false;
	}
	function resetMixto() {
		esMixto = false;
		pagosMixto = [];
	}
	function pmAutoLinea(idx: number) {
		const v = Math.max(0, totalConEnvio() - sumTemp(idx)).toFixed(2);
		pagosMixtoTemp[idx].monto = v;
	}
	function pmAgregarLinea() {
		if (pagosMixtoTemp.length >= 5) return;
		const usados = new Set(pagosMixtoTemp.map((p) => p.tipo));
		const sig = TIPOS_PAGO.find((t) => !usados.has(t.val))?.val ?? TIPOS_PAGO[0].val;
		pagosMixtoTemp = [...pagosMixtoTemp, { tipo: sig, monto: '' }];
	}
	function pmQuitarLinea(idx: number) {
		pagosMixtoTemp = pagosMixtoTemp.filter((_, i) => i !== idx);
	}
	function confirmarPM() {
		pagosMixto = JSON.parse(JSON.stringify(pagosMixtoTemp));
		esMixto = true;
		cerrarPM();
		actualizarLimiteCC();
	}
	function elegirPagoTile(val: string) {
		tipoPagoSel = val;
		actualizarLimiteCC();
	}

	// ── Última venta ──────────────────────────────────────────────
	// La caja ya no le pide CAE a ARCA ni muestra su estado (decisión
	// explícita, 14/08/2026): eso corre solo en segundo plano
	// (VentasController::procesarPendientesAfip(), timer del shell Tauri) y
	// se monitorea desde Ventas — acá no hay más badge/cartel que reflejarlo.
	function mostrarUltimaVenta(data: { id: number; tipo_comprobante: string; numero: string; total: number }) {
		ultimaVentaData = data;
	}
	function ocultarUltimaVenta() {
		ultimaVentaData = null;
	}
	async function reimprimirUltimaVenta() {
		if (!ultimaVentaData) return;
		try {
			const r = await api(`/ventas/${ultimaVentaData.id}/imprimir`, { method: 'POST' });
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

	// ── Confirmar venta ──────────────────────────────────────────────
	let confirmando = $state(false);
	async function confirmarVenta() {
		if (!items.length || afipGuardActivo) return;
		if (!esMixto && tipoPagoSel === 'cheque') {
			if (!posChqNumero.trim()) {
				toast_('Ingresá el número de cheque', 'err');
				return;
			}
			if (!posChqBanco.trim()) {
				toast_('Ingresá el banco del cheque', 'err');
				return;
			}
			if (!posChqVenc) {
				toast_('Ingresá la fecha de vencimiento del cheque', 'err');
				return;
			}
		}

		confirmando = true;
		const body: Record<string, unknown> = {
			carrito_id: CARRITO_ID,
			cliente_id: clienteActual?.id ?? null,
			caja_id: cajaOperativaId(),
			usuario_id: leerSesion()?.usuario_id ?? null,
			tipo_comprobante: tipoComp,
			tipo_pago: esMixto ? 'mixto' : tipoPagoSel,
			pagos: esMixto ? pagosMixto.map((p) => ({ tipo: p.tipo, monto: parseFloat(p.monto) })) : undefined,
			fecha: fechaVenta || null,
			observaciones: observaciones.trim() || null,
			vendedor_id: parseInt(selVendedorId) || null,
			envio_precio: chkEnvio ? parseFloat(envioPrecio) || null : null,
			envio_direccion: chkEnvio ? envioDir.trim() || null : null,
			items: items.map((i) => ({
				producto_id: i.producto_id,
				cantidad: i.cantidad,
				precio_unitario: i.precio_unitario,
				precio_original: i.precio_original ?? null,
				ajuste_desc: i.ajuste_desc ?? null,
				ajuste_visible: i.ajuste_visible ?? null
			}))
		};

		try {
			const esModoEdicion = !!editandoVentaId;
			const url = editandoVentaId ? `/ventas/${editandoVentaId}` : '/ventas';
			const method = editandoVentaId ? 'PUT' : 'POST';
			const r = await api(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const d = await r.json();
			if (!r.ok) {
				toast_(d.error ?? 'Error al guardar', 'err');
				return;
			}

			if (!esModoEdicion && !esMixto && body.tipo_pago === 'cheque') {
				try {
					const rc = await api('/cheques', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({
							tipo: 'recibido',
							numero: posChqNumero.trim(),
							banco: posChqBanco.trim(),
							librador: posChqLibrador.trim() || null,
							cuit: posChqCuit.trim() || null,
							monto: d.total,
							fecha_emision: posChqEmision || null,
							fecha_vencimiento: posChqVenc,
							venta_id: d.id,
							cliente_id: clienteActual?.id ?? null
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

			const uvData = { id: d.id, tipo_comprobante: body.tipo_comprobante as string, numero: d.numero, total: d.total };
			const volverA = sessionStorage.getItem('logos_volver_tras_editar');
			if (volverA) {
				sessionStorage.removeItem('logos_volver_tras_editar');
				abandonandoIntencionalmente = true;
				await goto(volverA);
				return;
			}

			const mixtoMp = esMixto ? pagosMixto.find((p) => p.tipo === 'mercado_pago') : null;
			const esMp = !esModoEdicion && (body.tipo_pago === 'mercado_pago' || !!mixtoMp);
			if (esMp) {
				const prefBody: Record<string, unknown> = { venta_id: d.id };
				if (mixtoMp) prefBody.monto_mp = parseFloat(mixtoMp.monto);
				try {
					await api('/mercadopago/preferencia', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(prefBody) });
				} catch {
					/* no bloquea si MP no está configurado o falla */
				}
			}

			salirModoEdicion();
			nuevaVenta();
			mostrarUltimaVenta(uvData);

			// La caja NO le pide CAE a ARCA acá (decisión explícita, 14/08/2026):
			// eso demoraba el flujo atado a la latencia/disponibilidad de ARCA.
			// Corre solo en segundo plano (VentasController::procesarPendientesAfip(),
			// timer del shell Tauri cada 90s) y se monitorea/reintenta a mano
			// desde Ventas — acá no hay ningún estado ni aviso que reflejarlo.
			const accion = esModoEdicion ? 'Actualizado' : '✓';
			const sufijo = esMp ? ' — QR MercadoPago incluido en comprobante' : '';
			toast_(`${accion}  ${body.tipo_comprobante} N° ${d.numero}  —  ${fmt(d.total)}${sufijo}`, 'ok');
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			confirmando = false;
		}
	}

	// ── Nueva venta / reset ──────────────────────────────────────────
	function nuevaVenta() {
		ocultarUltimaVenta();
		items = [];
		clienteActual = null;
		inputRapido = '';
		inputCliente = '';
		mostrarBuscadorCli = true;
		seleccionados = new Set();
		esMixto = false;
		pagosMixto = [];
		tipoPagoSel = 'efectivo';
		posChqNumero = '';
		posChqBanco = '';
		posChqLibrador = '';
		posChqCuit = '';
		posChqEmision = '';
		posChqVenc = '';
		observaciones = '';
		fechaVenta = todayStr();
		chkEnvio = false;
		envioPrecio = '';
		envioDir = '';
		envioDirOpciones = null;
		inputCodigo = '';
		codEstado = '';
		catalogoSel = new Map();
		sincronizarReservas();
		codInputEl?.focus();
	}

	// ── Modo edición ──────────────────────────────────────────────
	let editBannerVisible = $state(false);
	let editBannerTexto = $state('');
	function salirModoEdicion() {
		editandoVentaId = null;
		editBannerVisible = false;
	}

	async function initEditarVenta() {
		const raw = localStorage.getItem('logos_editar_venta');
		if (!raw) return;
		localStorage.removeItem('logos_editar_venta');
		let v: any;
		try {
			v = JSON.parse(raw);
		} catch {
			return;
		}

		editandoVentaId = v.id;

		if (Array.isArray(v.items) && v.items.length) {
			items = v.items.map((i: any) => ({
				producto_id: i.producto_id,
				nombre: i.nombre,
				codigo: i.codigo ?? '',
				cantidad: Number(i.cantidad),
				precio_unitario: Number(i.precio_unitario),
				precio_original: i.precio_original ? Number(i.precio_original) : null,
				ajuste_desc: i.ajuste_desc ?? null,
				ajuste_visible: i.ajuste_visible ?? true
			}));
		}

		if (v.fecha) fechaVenta = v.fecha;
		if (v.tipo_comprobante) tipoComp = v.tipo_comprobante;

		const tiposSimples = TIPOS_PAGO.map((t) => t.val);
		if (v.tipo_pago === 'mixto' && Array.isArray(v.pagos) && v.pagos.length) {
			pagosMixto = v.pagos.map((p: any) => ({ tipo: p.tipo, monto: String(p.monto) }));
			esMixto = true;
		} else if (v.tipo_pago && tiposSimples.includes(v.tipo_pago)) {
			tipoPagoSel = v.tipo_pago;
		}

		if (v.observaciones) observaciones = v.observaciones;
		if (v.envio_precio) {
			chkEnvio = true;
			envioPrecio = String(v.envio_precio);
		}
		if (v.envio_direccion) envioDir = v.envio_direccion;

		codInputEl?.blur();

		editBannerVisible = true;
		editBannerTexto = `Editando ${v.tipo_comprobante ?? 'venta'} N° ${v.numero}`;

		if (v.cliente_id) {
			try {
				const r = await api(`/clientes/${v.cliente_id}`);
				const c = await r.json();
				seleccionarCliente(r.ok ? c : { id: v.cliente_id, nombre: v.cliente_nombre, saldo_cuenta_corriente: 0, limite_credito: 0 });
			} catch {
				seleccionarCliente({ id: v.cliente_id, nombre: v.cliente_nombre, saldo_cuenta_corriente: 0, limite_credito: 0 });
			}
		}
	}

	async function initCopiaVenta() {
		const raw = localStorage.getItem('logos_copia_venta');
		if (!raw) return;
		localStorage.removeItem('logos_copia_venta');
		let datos: any;
		try {
			datos = JSON.parse(raw);
		} catch {
			return;
		}
		if (Array.isArray(datos.items) && datos.items.length) {
			items = datos.items.map((i: any) => ({
				producto_id: i.producto_id,
				nombre: i.nombre,
				codigo: i.codigo ?? '',
				cantidad: Number(i.cantidad),
				precio_unitario: Number(i.precio_unitario)
			}));
		}
		if (datos.cliente?.id) {
			try {
				const r = await api(`/clientes/${datos.cliente.id}`);
				const c = await r.json();
				seleccionarCliente(r.ok ? c : { id: datos.cliente.id, nombre: datos.cliente.nombre, saldo_cuenta_corriente: 0, limite_credito: 0 });
			} catch {
				seleccionarCliente({ id: datos.cliente.id, nombre: datos.cliente.nombre, saldo_cuenta_corriente: 0, limite_credito: 0 });
			}
		}
		toast_(`Venta copiada — ${items.length} producto${items.length !== 1 ? 's' : ''}`, 'ok');
	}

	// ── Modal búsqueda avanzada (F3) ──────────────────────────────
	let overlayAbierto = $state(false);
	let modalInput = $state('');
	let modalInputEl: HTMLInputElement | undefined = $state();
	let modoExacto = $state(false);
	let fProveedor = $state('');
	let fMarca = $state('');
	let fCategoria = $state('');
	let fConStock = $state(false);
	let filtros = $state<{ marcas: string[]; proveedores: string[]; categorias: string[] }>({ marcas: [], proveedores: [], categorias: [] });
	let filtrosLoaded = false;
	let modalResultados = $state<ProductoBusqueda[]>([]);
	let modalIdx = $state(-1);
	let modalEstado = $state('Escribí un nombre o aplicá un filtro para buscar');
	let modalCargando = $state(false);
	let catalogoSel = $state<Map<number, ProductoBusqueda>>(new Map());
	let modalTimer: ReturnType<typeof setTimeout>;

	async function cargarFiltrosModal() {
		if (filtrosLoaded) return;
		try {
			const r = await api('/filtros');
			filtros = await r.json();
			filtrosLoaded = true;
		} catch {
			toast_('Error al cargar filtros', 'err');
		}
	}
	function abrirModal() {
		cargarFiltrosModal();
		overlayAbierto = true;
		setTimeout(() => {
			modalInputEl?.focus();
			modalInputEl?.select();
		}, 50);
	}
	function cerrarModal() {
		overlayAbierto = false;
		inputRapidoEl?.focus();
	}
	function onModalInput() {
		clearTimeout(modalTimer);
		modalTimer = setTimeout(buscarModal, 250);
	}
	async function buscarModal() {
		const q = modalInput.trim();
		const tieneFiltro = fProveedor || fMarca || fCategoria || fConStock;
		if (q.length < 2 && !tieneFiltro) {
			modalResultados = [];
			modalIdx = -1;
			modalEstado = 'Escribí al menos 2 caracteres o aplicá un filtro';
			return;
		}
		modalCargando = true;
		modalEstado = 'Buscando...';
		const params = new URLSearchParams({ limit: '100', carrito_id: CARRITO_ID });
		if (q) params.set('q', q);
		if (modoExacto) params.set('exacto', '1');
		if (fProveedor) params.set('proveedor', fProveedor);
		if (fMarca) params.set('marca', fMarca);
		if (fCategoria) params.set('categoria', fCategoria);
		if (fConStock) params.set('con_stock', '1');
		try {
			const r = await api(`/productos?${params}`);
			const d = await r.json();
			if (!r.ok || !Array.isArray(d)) {
				modalEstado = d.error ?? 'Sin resultados';
				modalResultados = [];
				return;
			}
			modalResultados = d;
			modalIdx = -1;
		} catch {
			modalEstado = 'Error de conexión';
			modalResultados = [];
		} finally {
			modalCargando = false;
		}
	}
	function onModalInputKeydown(e: KeyboardEvent) {
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			modalIdx = Math.max(0, Math.min(modalResultados.length - 1, modalIdx + 1));
		}
		if (e.key === 'ArrowUp') {
			e.preventDefault();
			modalIdx = Math.max(0, Math.min(modalResultados.length - 1, modalIdx - 1));
		}
		if (e.key === 'Enter') {
			e.preventDefault();
			const idx = modalIdx >= 0 ? modalIdx : 0;
			if (modalResultados[idx]) toggleSeleccion(modalResultados[idx]);
		}
		if (e.key === 'Escape') cerrarModal();
	}
	function toggleSeleccion(p: ProductoBusqueda) {
		const m = new Map(catalogoSel);
		if (m.has(p.id)) m.delete(p.id);
		else m.set(p.id, p);
		catalogoSel = m;
	}
	function quitarSeleccionado(id: number) {
		const m = new Map(catalogoSel);
		m.delete(id);
		catalogoSel = m;
	}
	function limpiarSeleccionCatalogo() {
		catalogoSel = new Map();
	}
	function confirmarSeleccionCatalogo() {
		catalogoSel.forEach((p) => agregarProducto(p));
		catalogoSel = new Map();
		cerrarModal();
	}
	function stockClass(stock: number): string {
		return stock > 0 ? 'stock-ok' : stock < 0 ? 'stock-no' : 'stock-cero';
	}

	// ── Atajos globales ────────────────────────────────────────────
	function onKeydownGlobal(e: KeyboardEvent) {
		const tag = (e.target as HTMLElement)?.tagName;
		const enInput = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA';
		const enItemInput = (e.target as HTMLElement)?.classList?.contains('item-input');

		if (e.key === 'F2') {
			e.preventDefault();
			if (overlayAbierto) cerrarModal();
			inputRapidoEl?.focus();
			inputRapidoEl?.select();
		}
		if (e.key === 'F3') {
			e.preventDefault();
			abrirModal();
		}
		if (e.key === 'F4') {
			e.preventDefault();
			if (overlayAbierto) cerrarModal();
			inputClienteEl?.focus();
			inputClienteEl?.select();
		}
		if (e.key === 'F5') {
			e.preventDefault();
			ocultarUltimaVenta();
			codInputEl?.focus();
		}
		if (e.key === 'F10') {
			e.preventDefault();
			if (!btnConfirmarDisabled) confirmarVenta();
		}
		if (e.shiftKey && !enInput && !enItemInput && /^Digit[1-6]$/.test(e.code) && !overlayAbierto && !pmAbierto) {
			const tipo = TIPOS_PAGO[Number(e.code.slice(5)) - 1];
			if (tipo) {
				e.preventDefault();
				elegirPagoTile(tipo.val);
			}
		}
		if (e.key === 'Escape' && overlayAbierto) cerrarModal();
		if (e.key === 'Escape' && pmAbierto) cerrarPM();
	}

	// ── Guardia de navegación ────────────────────────────────────────
	function hayVentaActiva(): boolean {
		return items.length > 0 || editandoVentaId !== null;
	}
	let abandonandoIntencionalmente = false;

	function onBeforeUnload(e: BeforeUnloadEvent) {
		if (!hayVentaActiva() || abandonandoIntencionalmente) return;
		e.preventDefault();
		e.returnValue = '';
	}

	beforeNavigate((nav) => {
		if (abandonandoIntencionalmente || !hayVentaActiva()) return;
		nav.cancel();
		(async () => {
			const ok = await confirmar('Los ítems cargados se perderán.', { titulo: '¿Abandonar la venta actual?', confirmLabel: 'Abandonar', danger: true });
			if (ok && nav.to?.url) {
				abandonandoIntencionalmente = true;
				await goto(nav.to.url.pathname + nav.to.url.search);
				abandonandoIntencionalmente = false;
			}
		})();
	});

	// ── Init ─────────────────────────────────────────────────────
	let tbVendedorVisible = $state(true);

	async function cargarVendedoresInit() {
		try {
			const r = await api('/vendedores');
			if (!r.ok) return;
			const lista = await r.json();
			vendedores = lista;
			if (!lista.length) tbVendedorVisible = false;
		} catch {
			/* sin vendedores si falla */
		}
	}
	async function filtrarTiposComprobanteInit() {
		try {
			const r = await api('/configuracion');
			const cfg = await r.json();
			const habilitados = cfg.tipos_habilitados;
			if (!Array.isArray(habilitados) || !habilitados.length) return;
			tiposCompHabilitados = habilitados;
			if (!habilitados.includes(tipoComp)) {
				const primera = ['REMITO', 'FC B-ELECT', 'FC A-ELECT', 'FC C-ELECT', 'PRESUPUESTO'].find((t) => habilitados.includes(t));
				if (primera) {
					tipoComp = primera;
					checkAfipGuard();
				}
			}
		} catch {
			/* sin filtro si falla */
		}
	}
	let tiposCompHabilitados = $state<string[] | null>(null);
	async function verificarTurnoAbiertoInit() {
		const cajaId = leerSesion()?.caja_id;
		if (!cajaId) return;
		try {
			const r = await api(`/caja-turnos/actual?caja_id=${cajaId}`);
			const d = await r.json();
			if (!d.no_aplica && !d.turno) {
				await goto('/caja');
			}
		} catch {
			/* si falla la verificación, no se bloquea la venta */
		}
	}

	$effect(() => {
		function onDocClick(e: MouseEvent) {
			const target = e.target as HTMLElement;
			if (!target.closest('.busqueda-rapida')) {
				ddRapidoVisible = false;
				ddCliVisible = false;
			}
		}
		document.addEventListener('click', onDocClick);
		return () => document.removeEventListener('click', onDocClick);
	});

	$effect(() => {
		actualizarLimiteCC();
	});

	// ── Tour guiado — port de pos/index.html (28 pasos). Selectores
	// reverificados en vivo contra esta página en Svelte: la mayoría del
	// naming se conservó igual, varios IDs de la versión legacy pasaron a
	// ser clases acá (ej. #sel-panel → .sel-panel). Los onEnter llaman
	// directo a las funciones/estado del componente en vez de simular clicks
	// de DOM como hacía la versión legacy — más simple y más confiable.
	const TOUR_STEPS: TourStep[] = [
		{
			el: null,
			title: 'Bienvenido al POS',
			body: 'Esta es la pantalla principal de ventas. En los próximos pasos te mostramos todas las funciones, paso a paso.'
		},
		{
			el: '.tb-cliente',
			title: 'Cliente',
			body: 'Escribí el nombre o CUIT para buscar un cliente existente (atajo: F4). Con el botón <strong>+</strong> podés crear uno nuevo. Por defecto la venta queda como <em>Consumidor Final</em>.'
		},
		{
			el: '#tipo-comp',
			title: 'Tipo de comprobante',
			body: 'Elegí entre Remito, Factura B, Factura A o Presupuesto. Las facturas electrónicas requieren cliente con CUIT y conectividad con AFIP.'
		},
		{
			el: '.prod-bar',
			title: 'Agregar productos',
			body: 'Podés escribir el código o escanear con una lectora. Con <strong>F2</strong> buscás por nombre. El botón <strong>Catálogo</strong> (o F3) abre el buscador avanzado — te lo mostramos ahora.'
		},
		{
			el: '.overlay .modal',
			pad: 0,
			title: 'Catálogo de productos',
			body: 'Desde acá buscás en todo el catálogo. Podés buscar por nombre, marca o cualquier texto.',
			onEnter: async ({ delay }) => {
				abrirModal();
				await delay(400);
			}
		},
		{
			el: '.modal-filtros',
			title: 'Filtros',
			body: 'Filtrá por proveedor, marca o rubro. El toggle <strong>Con stock</strong> muestra solo los productos con existencia disponible.'
		},
		{
			el: '.modal-tabla',
			title: 'Seleccionar productos',
			body: 'Hacé click en una fila para seleccionarla (se resalta). Podés seleccionar varios productos antes de agregarlos al carrito.',
			onEnter: async ({ delay }) => {
				modalInput = 'PRUEBA';
				await buscarModal();
				await delay(300);
				for (let i = 0; i < Math.min(2, modalResultados.length); i++) toggleSeleccion(modalResultados[i]);
			}
		},
		{
			el: '.sel-panel',
			title: 'Panel de selección',
			body: 'Cuando seleccionás productos aparece este panel mostrando los que elegiste. Revisá la lista antes de confirmar.'
		},
		{
			el: '.items-card',
			title: 'Carrito de productos',
			body: 'Los productos agregados aparecen acá. Podés ver el código, nombre, cantidad y precio de cada ítem.',
			onEnter: async ({ delay }) => {
				confirmarSeleccionCatalogo();
				await delay(500);
			}
		},
		{
			el: '.items-card',
			pad: 0,
			title: 'Editar cantidad y precio',
			body: 'Con los botones <strong>+</strong> y <strong>−</strong> ajustás la cantidad, o hacés click directo en el número para editarlo. El precio unitario también es editable en cada fila.'
		},
		{
			el: '.sel-bar',
			title: 'Barra de acciones',
			body: 'Seleccioná uno o más productos con el checkbox a la izquierda para activar esta barra. Permite aplicar cambios a todos los seleccionados a la vez.',
			onEnter: async ({ delay }) => {
				if (!todosMarcados) toggleTodos(true);
				await delay(250);
			}
		},
		{
			el: '.sel-btn-del',
			title: 'Eliminar seleccionados',
			body: 'Elimina del carrito todos los ítems seleccionados de una sola vez.'
		},
		{
			el: '.sel-aj-tipo-toggle',
			title: 'Descuento o Incremento',
			body: 'Elegí si querés aplicar un <strong>Descuento</strong> (−) o un <strong>Incremento</strong> (+) al precio de los ítems seleccionados.'
		},
		{
			el: '.sel-aj-input-wrap',
			title: 'Valor del ajuste',
			body: 'Ingresá el valor. Con <strong>%</strong> aplicás un porcentaje; con <strong>$</strong> definís un monto fijo de descuento o aumento.'
		},
		{
			el: '.sel-visible-wrap',
			title: 'Mostrar en el comprobante',
			body: 'Si está activo, el ajuste aparece detallado en el remito (ej: "−10%"). Si lo desactivás, el cliente ve solo el precio final, sin desglose.'
		},
		{
			el: '.sel-aj-grupo + button',
			title: 'Aplicar ajuste',
			body: 'Presioná acá para aplicar el descuento o incremento configurado a todos los ítems seleccionados.'
		},
		{
			el: '.sel-aj-grupo + button + button',
			title: 'Limpiar ajustes',
			body: 'Quita todos los ajustes manuales de los ítems seleccionados, dejando el precio modificado como estaba antes del ajuste.'
		},
		{
			el: '[title="Restablecer al precio de lista original"]',
			title: 'Precio de lista',
			body: 'Restablece el precio de venta original del producto, deshaciendo cualquier ajuste o modificación manual.'
		},
		{
			el: '.pago-grid',
			title: 'Forma de pago',
			body: 'Elegí entre 6 métodos: Efectivo, Transferencia, Cuenta Corriente, Tarjeta, Cheque o Mercado Pago. <strong>Cuenta Corriente</strong> suma el monto al saldo del cliente.'
		},
		{
			el: '.pago-mixto-link',
			title: 'Pago mixto',
			body: 'Si el cliente paga con más de un método, usá esta opción para combinarlos. Te mostramos el modal ahora.'
		},
		{
			el: '.pm-modal',
			pad: 0,
			title: 'Modal de pago mixto',
			body: 'Acá ves el total a cobrar y configurás los distintos medios de pago con sus montos.',
			onEnter: async ({ delay }) => {
				abrirPM();
				await delay(350);
			}
		},
		{
			el: '.pm-lineas',
			title: 'Métodos y montos',
			body: 'Elegí el medio de pago y el monto para cada línea. El botón <strong>Autocompletar</strong> calcula automáticamente el restante para cubrir el total.'
		},
		{
			el: '.pm-estado',
			title: 'Estado del cobro',
			body: 'Muestra si el total está cubierto ✓, cuánto falta, o si excede. El botón <strong>Confirmar</strong> se habilita solo cuando los montos cuadran exactamente.'
		},
		{
			el: '.pago-grid',
			title: 'Forma de pago',
			body: 'Seguimos con <strong>Efectivo</strong> para el resto del tour.',
			onEnter: async ({ delay }) => {
				cerrarPM();
				await delay(300);
				if (tipoPagoSel !== 'efectivo') elegirPagoTile('efectivo');
			}
		},
		{
			el: '.envio-toggle',
			title: 'Envío a domicilio',
			body: 'Activá este toggle para agregar un costo de envío y la dirección de entrega. El monto se suma automáticamente al total de la venta.'
		},
		{
			el: '.top-bar',
			title: 'Fecha y observaciones',
			body: 'La fecha del comprobante es editable (útil para correcciones del día). En <strong>Observaciones</strong> podés dejar una nota interna que aparece impresa en el comprobante.'
		},
		{
			el: '.col-der .btn.btn-ok',
			title: 'Confirmar venta',
			body: 'Con <strong>F10</strong> o este botón confirmás la venta: se descuenta stock, se genera el comprobante y queda guardada en el historial de Ventas.'
		},
		{
			el: 'a[href="/ventas"]',
			title: 'Ventas',
			body: 'Todas las ventas confirmadas aparecen en <strong>Ventas</strong>. Desde ahí podés ver el historial, descargar PDFs, emitir notas de crédito y generar notas de envío. ¡Eso es todo para el POS!'
		}
	];

	onMount(() => {
		fechaVenta = todayStr();
		verificarTurnoAbiertoInit();
		cargarVendedoresInit();
		filtrarTiposComprobanteInit();
		codInputEl?.focus();
		initEditarVenta().then(() => initCopiaVenta());
		setTourSteps('index', TOUR_STEPS);
	});
</script>

<svelte:window onkeydown={onKeydownGlobal} onbeforeunload={onBeforeUnload} />

<svelte:head>
	<title>Logos — POS</title>
</svelte:head>

<div class="top-bar">
	<div class="tb-cliente">
		<label for="input-cliente">Cliente <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:11px">(F4)</span></label>
		<div class="cli-search-wrap">
			<div class="busqueda-rapida" style="flex:1;min-width:0">
				{#if mostrarBuscadorCli}
					<input
						type="text"
						id="input-cliente"
						bind:this={inputClienteEl}
						placeholder="Consumidor final — buscar por nombre o CUIT..."
						autocomplete="off"
						spellcheck="false"
						bind:value={inputCliente}
						oninput={onInputCliente}
						onkeydown={onClienteKeydown}
					/>
					{#if ddCliVisible}
						<div class="dropdown visible">
							{#each ddCliResultados as c, i (c.id)}
								<div class="dd-item" class:activo={i === ddCliIdx} onmousedown={() => seleccionarCliente(c)} role="button" tabindex="-1">
									<span class="cod">{c.cuit ?? ''}</span>
									<span class="nom">{c.nombre}</span>
									<span class="pre" style="color:{parseFloat(String(c.saldo_cuenta_corriente)) > 0 ? 'var(--rojo)' : 'var(--gris3)'}">{fmt(c.saldo_cuenta_corriente)}</span>
								</div>
							{/each}
						</div>
					{/if}
				{:else if clienteActual}
					<div class="cliente-sel" style="display:block">
						<button class="cquitar" aria-label="Quitar cliente" onclick={quitarCliente}>×</button>
						<div class="cnombre">{clienteActual.nombre}</div>
						<div class="csaldo">CC: {fmt(clienteActual.saldo_cuenta_corriente)} · Límite: {fmt(clienteActual.limite_credito)}</div>
					</div>
				{/if}
			</div>
			<button class="btn-nuevo-cli" title="Nuevo cliente" onclick={abrirModalNuevoCli}>+</button>
		</div>
	</div>
	<div class="tb-comp">
		<label for="tipo-comp">Comprobante</label>
		<select id="tipo-comp" bind:value={tipoComp} onchange={checkAfipGuard}>
			<option value="REMITO" hidden={tiposCompHabilitados ? !tiposCompHabilitados.includes('REMITO') : false}>Remito</option>
			<option value="FC B-ELECT" hidden={tiposCompHabilitados ? !tiposCompHabilitados.includes('FC B-ELECT') : false}>Factura B</option>
			<option value="FC A-ELECT" hidden={tiposCompHabilitados ? !tiposCompHabilitados.includes('FC A-ELECT') : false}>Factura A</option>
			<option value="FC C-ELECT" hidden={tiposCompHabilitados ? !tiposCompHabilitados.includes('FC C-ELECT') : false}>Factura C</option>
			<option value="PRESUPUESTO" hidden={tiposCompHabilitados ? !tiposCompHabilitados.includes('PRESUPUESTO') : false}>Presupuesto</option>
		</select>
	</div>
	<div class="tb-fecha">
		<label for="fecha-venta">Fecha</label>
		<input type="date" id="fecha-venta" class:fecha-modificada={fechaModificada} bind:value={fechaVenta} />
	</div>
	{#if tbVendedorVisible}
		<div class="tb-obs">
			<label for="sel-vendedor">Vendedor</label>
			<select id="sel-vendedor" bind:value={selVendedorId}>
				<option value="">— Sin vendedor —</option>
				{#each vendedores as v (v.id)}<option value={String(v.id)}>{v.nombre}</option>{/each}
			</select>
		</div>
	{/if}
	<div class="tb-obs">
		<label for="observaciones">Observaciones</label>
		<input type="text" id="observaciones" placeholder="Opcional..." autocomplete="off" spellcheck="false" bind:value={observaciones} />
	</div>
</div>

{#if editBannerVisible}
	<div style="background:var(--color-primary-dark);color:white;padding:7px 16px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;flex-shrink:0">
		<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
		<span>{editBannerTexto}</span>
		<button
			style="margin-left:auto;background:rgba(255,255,255,.2);border:none;color:white;border-radius:4px;padding:3px 10px;cursor:pointer;font-size:12px;font-weight:600"
			onclick={() => {
				salirModoEdicion();
				nuevaVenta();
				const volverA = sessionStorage.getItem('logos_volver_tras_editar');
				if (volverA) {
					sessionStorage.removeItem('logos_volver_tras_editar');
					abandonandoIntencionalmente = true;
					goto(volverA);
				}
			}}>Cancelar edición</button
		>
	</div>
{/if}

<div class="main">
	<!-- ── IZQ ── -->
	<div class="col-izq">
		<div class="prod-bar">
			<div class="cod-rapido">
				<svg class="cod-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 5v14M7 5v14M11 5v14M15 5v14M19 5v14" /></svg>
				<input type="text" placeholder="Código / scan" autocomplete="off" spellcheck="false" class:cod-ok={codEstado === 'ok'} class:cod-err={codEstado === 'err'} bind:this={codInputEl} bind:value={inputCodigo} oninput={() => (codEstado = '')} onkeydown={onCodigoKeydown} />
			</div>
			<div class="busqueda-rapida">
				<svg class="busq-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4.35-4.35" /></svg>
				<input type="text" placeholder="F2 — Buscar por nombre o marca..." autocomplete="off" spellcheck="false" bind:this={inputRapidoEl} bind:value={inputRapido} oninput={onInputRapido} onkeydown={onRapidoKeydown} />
				{#if ddRapidoVisible}
					<div class="dropdown visible">
						{#each ddRapidoResultados as p, i (p.id)}
							<div class="dd-item" class:activo={i === ddRapidoIdx} onmousedown={() => agregarYCerrarRapido(p)} role="button" tabindex="-1">
								<span class="cod">{p.codigo}</span>
								<span class="nom">{p.nombre}</span>
								<span class="pre">{fmt(p.precio_venta)}</span>
							</div>
						{/each}
					</div>
				{/if}
			</div>
			<button class="cat-btn" onclick={abrirModal}>
				<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></svg>
				Catálogo
				<span class="cat-kbd">F3</span>
			</button>
		</div>

		<div class="sel-bar" class:activo={seleccionados.size > 0}>
			<span class="sel-count">{seleccionados.size > 0 ? `${seleccionados.size} ítem${seleccionados.size > 1 ? 's' : ''} seleccionado${seleccionados.size > 1 ? 's' : ''}` : 'Ninguno seleccionado'}</span>
			<div class="sel-acciones">
				<button class="sel-btn sel-btn-del" onclick={() => { items = items.filter((i) => !seleccionados.has(i.producto_id)); seleccionados = new Set(); }}
					><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14H6L5 6" /><path d="M10 11v6M14 11v6" /></svg>Eliminar</button
				>
				<div class="sel-sep"></div>
				<div class="sel-aj-grupo">
					<div class="sel-aj-tipo-toggle">
						<button class="sel-aj-tipo-btn" class:activo={selAjTipo === 'desc'} type="button" onclick={() => (selAjTipo = 'desc')}>− Descuento</button>
						<button class="sel-aj-tipo-btn" class:activo={selAjTipo === 'inc'} type="button" onclick={() => (selAjTipo = 'inc')}>+ Incremento</button>
					</div>
					<div class="sel-aj-input-wrap">
						<input type="number" class="sel-input" placeholder="0" step="any" min="0" bind:value={selAjVal} onkeydown={(e) => e.key === 'Enter' && aplicarAjusteSeleccion()} />
						<div class="sel-aj-unit-toggle">
							<button class="sel-aj-unit-btn" class:activo={selAjUnit === 'pct'} type="button" onclick={() => (selAjUnit = 'pct')}>%</button>
							<button class="sel-aj-unit-btn" class:activo={selAjUnit === 'fijo'} type="button" onclick={() => (selAjUnit = 'fijo')}>$</button>
						</div>
					</div>
					<label class="sel-visible-wrap" title="Muestra el ajuste detallado en el comprobante">
						<input type="checkbox" checked={selVisible} onchange={(e) => onSelVisibleChange((e.target as HTMLInputElement).checked)} />
						<span>en remito</span>
					</label>
				</div>
				<button class="sel-btn sel-btn-ghost" onclick={aplicarAjusteSeleccion}>Ajustar</button>
				<button class="sel-btn sel-btn-ghost" onclick={limpiarAjusteSeleccion}>Limpiar</button>
				<div class="sel-sep"></div>
				<button class="sel-btn sel-btn-ghost" title="Restablecer al precio de lista original" onclick={restablecerPrecioLista}>Precio lista</button>
			</div>
		</div>

		<div class="items-card">
			<div class="items-header">
				<input type="checkbox" class="hdr-chk" title="Seleccionar todo" checked={todosMarcados} indeterminate={algunosMarcados} onchange={(e) => toggleTodos((e.target as HTMLInputElement).checked)} />
				<span>Producto</span>
				<span class="r">Cant.</span>
				<span class="r">Precio unit.</span>
				<span class="r">Subtotal</span>
				<span></span>
			</div>
			<div class="items-lista">
				{#if !items.length}
					<div class="items-vacio">Usá <strong>F3</strong> para buscar productos<br />o <strong>F2</strong> para búsqueda rápida por código</div>
				{:else}
					{#each items as item, idx (item.producto_id)}
						<div class="item-row">
							<input type="checkbox" class="item-chk" checked={seleccionados.has(item.producto_id)} onchange={(e) => toggleItemChk(item.producto_id, (e.target as HTMLInputElement).checked)} />
							<div class="nom">
								{item.nombre}<small>{item.codigo}{#if item.ajuste_desc}<span class="ajuste-badge {item.ajuste_desc.startsWith('+') ? 'inc' : 'desc'}">{item.ajuste_desc}</span>{/if}</small>
							</div>
							<div class="cant-wrap">
								<button class="cant-btn" onclick={() => cambiarCantidad(idx, -1)}>−</button>
								<input class="item-input" type="number" min="0.001" step="any" value={item.cantidad} onchange={(e) => onItemCantidadInput(idx, (e.target as HTMLInputElement).value)} />
								<button class="cant-btn" onclick={() => cambiarCantidad(idx, 1)}>+</button>
							</div>
							<input class="item-input" type="number" min="0" step="any" value={item.precio_unitario.toFixed(2)} onchange={(e) => onItemPrecioInput(idx, (e.target as HTMLInputElement).value)} />
							<div class="sub">{fmt(item.cantidad * item.precio_unitario)}</div>
							<button class="btn-del" onclick={() => eliminarItem(idx)}>×</button>
						</div>
					{/each}
				{/if}
			</div>
		</div>
	</div>

	<!-- ── DER ── -->
	<div class="col-der">
		<div class="card" style="border-top:3px solid var(--azul)">
			<label for="_">Forma de pago — <span style="color:var(--color-primary)">{TIPOS_PAGO.find((t) => t.val === tipoPagoSel)?.lbl}</span></label>
			{#if !esMixto}
				<div class="pago-grid">
					{#each TIPOS_PAGO as t, i (t.val)}
						<button type="button" class="pago-tile" class:activo={t.val === tipoPagoSel} title="{t.lbl} (Shift+{i + 1})" onclick={() => elegirPagoTile(t.val)}>
							<span class="pago-tile-shortcut">⇧{i + 1}</span>
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{@html PAGO_ICONS[t.val]}</svg>
							<span class="pago-tile-lbl">{t.lbl}</span>
						</button>
					{/each}
				</div>
				{#if tipoPagoSel === 'cheque'}
					<div class="pos-cheque-wrap">
						<div class="pos-chq-titulo">Datos del cheque</div>
						<div class="pos-chq-grid">
							<div><span class="pos-chq-lbl">N° de cheque *</span><input type="text" placeholder="00123456" autocomplete="off" bind:value={posChqNumero} /></div>
							<div><span class="pos-chq-lbl">Banco *</span><input type="text" placeholder="Ej: Galicia" autocomplete="off" bind:value={posChqBanco} /></div>
							<div><span class="pos-chq-lbl">Librador</span><input type="text" placeholder="Nombre del firmante" autocomplete="off" bind:value={posChqLibrador} /></div>
							<div><span class="pos-chq-lbl">CUIT</span><input type="text" placeholder="20-12345678-9" autocomplete="off" bind:value={posChqCuit} /></div>
							<div><span class="pos-chq-lbl">Fecha de emisión</span><input type="date" bind:value={posChqEmision} /></div>
							<div><span class="pos-chq-lbl">Fecha de vencimiento *</span><input type="date" bind:value={posChqVenc} /></div>
						</div>
					</div>
				{/if}
				<div class="pago-mixto-footer"><button class="pago-mixto-link" onclick={abrirPM}>+ Pago mixto</button></div>
			{:else}
				<div class="pago-mixto-resumen" style="display:flex">
					<div class="pago-mixto-detalle">
						{#each pagosMixto as p, i (i)}
							{TIPOS_PAGO.find((t) => t.val === p.tipo)?.lbl ?? p.tipo}: <strong>{fmt(parseFloat(p.monto))}</strong>{#if i < pagosMixto.length - 1}<br />{/if}
						{/each}
					</div>
					<div class="pago-mixto-acciones">
						<button class="pago-mixto-link" onclick={abrirPM}>Editar</button>
						<button class="pago-mixto-reset" title="Volver a pago simple" onclick={resetMixto}>×</button>
					</div>
				</div>
			{/if}
		</div>

		<div class="card" style="padding:12px 14px">
			<label class="envio-toggle">
				<input type="checkbox" checked={chkEnvio} onchange={(e) => onChkEnvioChange((e.target as HTMLInputElement).checked)} />
				<span class="envio-switch"></span>
				Envío a domicilio
			</label>
			<div class="envio-reveal" class:visible={chkEnvio}>
				<div class="envio-fields">
					<div>
						<label for="envio-precio">Costo</label>
						<input type="number" id="envio-precio" placeholder="0,00" min="0" step="any" bind:value={envioPrecio} />
					</div>
					<div>
						<label for="_">Dirección</label>
						{#if envioDirOpciones}
							<select style="padding:7px 8px;border:none;border-radius:6px;font-size:12px;font-family:inherit;outline:none;background:var(--neo-bg);color:var(--neo-text);box-shadow:var(--neo-i1);width:100%" bind:value={envioDirSelIdx} onchange={onEnvioDirSelChange}>
								{#each envioDirOpciones as d, i (i)}<option value={String(i)}>{d.etiqueta ? d.etiqueta + ' — ' : ''}{[d.domicilio, d.localidad, d.provincia].filter(Boolean).join(', ')}</option>{/each}
								<option value="manual">Escribir a mano…</option>
							</select>
						{:else}
							<input type="text" placeholder="Calle, número, piso..." bind:value={envioDir} />
						{/if}
					</div>
				</div>
			</div>
		</div>

		<div class="card totales">
			<div class="t-row"><span>Ítems</span><span>{items.length}</span></div>
			<div class="t-row"><span>Unidades</span><span>{totalUnidades}</span></div>
			{#if chkEnvio && (parseFloat(envioPrecio) || 0) > 0}
				<div class="t-row" style="color:var(--gris3)"><span>Envío</span><span>{fmt(parseFloat(envioPrecio) || 0)}</span></div>
			{/if}
			<div class="t-total"><span>TOTAL</span><span>{fmt(totalConEnvio())}</span></div>
		</div>

		{#if afipGuardActivo}
			<div class="afip-warn visible" role="alert" aria-live="polite">
				<div class="afip-warn-titulo">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" /></svg>
					AFIP: falta datos fiscales
				</div>
				<div class="afip-warn-msg">{afipWarnMsg}</div>
				<button class="afip-fix-btn" type="button" onclick={() => { mostrarBuscadorCli = true; inputClienteEl?.focus(); inputClienteEl?.select(); }}>
					<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg>
					Buscar cliente
				</button>
			</div>
		{/if}

		<button class="btn btn-ok" disabled={btnConfirmarDisabled || confirmando} onclick={confirmarVenta}>
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><polyline points="20 6 9 17 4 12" /></svg>
			<span>{confirmando ? 'Guardando...' : editandoVentaId ? 'CONFIRMAR CAMBIOS' : 'CONFIRMAR VENTA'}</span>
			{#if !confirmando}<span style="font-size:11px;font-weight:500;opacity:.75;font-family:monospace;background:rgba(0,0,0,.2);padding:1px 5px;border-radius:3px;margin-left:2px">F10</span>{/if}
		</button>
		{#if ccAlertaVisible}
			{@const partes = ccAlertaTexto.split('|')}
			<div style="display:block;background:rgba(231,76,60,.08);border-radius:var(--neo-r-sm);box-shadow:var(--neo-e1), 0 0 0 1.5px var(--neo-danger);padding:9px 12px;font-size:12px;line-height:1.55;color:var(--neo-text-2)">
				<strong>{partes[0]}</strong><br />{@html partes[1]}<br />{@html partes[2]}
			</div>
		{/if}

		{#if ultimaVentaData}
			<div class="ultima-venta visible" aria-label="Última venta confirmada">
				<div class="uv-head">
					<span class="uv-etiqueta">Última venta</span>
					<button class="uv-cerrar" type="button" title="Cerrar" aria-label="Cerrar panel de última venta" onclick={ocultarUltimaVenta}>×</button>
				</div>
				<div class="uv-data">
					<span class="uv-comp-wrap">
						<span class="uv-comp">{ultimaVentaData.tipo_comprobante} N° {ultimaVentaData.numero}</span>
					</span>
					<span class="uv-total">{fmt(ultimaVentaData.total)}</span>
				</div>
				<div class="uv-btns">
					<button class="uv-btn" type="button" onclick={reimprimirUltimaVenta}>
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9" /><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" /><rect x="6" y="14" width="12" height="8" /></svg>
						Reimprimir
					</button>
					<button class="uv-btn" type="button" onclick={() => toast_('Para emitir NC: buscá la venta en Historial y usá "Nota de crédito"', 'ok')}>
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6l-1 14H6L5 6M10 11v6M14 11v6M9 6V4h6v2" /></svg>
						Nota de crédito
					</button>
				</div>
			</div>
		{/if}
	</div>
</div>

<!-- ════════ MODAL BÚSQUEDA AVANZADA ════════ -->
{#if overlayAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModal()}>
		<div class="modal" role="dialog" aria-modal="true">
			<div class="modal-head">
				<div class="modal-head-top">
					<h2>Búsqueda de productos</h2>
					<button class="modal-close" aria-label="Cerrar" onclick={cerrarModal}>×</button>
				</div>
				<div class="modal-search">
					<input type="text" placeholder="Nombre, código o descripción..." autocomplete="off" spellcheck="false" bind:this={modalInputEl} bind:value={modalInput} oninput={onModalInput} onkeydown={onModalInputKeydown} />
				</div>
				<div class="modal-filtros">
					<select bind:value={fProveedor} onchange={buscarModal}>
						<option value="">Todos los proveedores</option>
						{#each filtros.proveedores as p (p)}<option value={p}>{p}</option>{/each}
					</select>
					<select bind:value={fMarca} onchange={buscarModal}>
						<option value="">Todas las marcas</option>
						{#each filtros.marcas as m (m)}<option value={m}>{m}</option>{/each}
					</select>
					<select bind:value={fCategoria} onchange={buscarModal}>
						<option value="">Todos los rubros</option>
						{#each filtros.categorias as c (c)}<option value={c}>{c}</option>{/each}
					</select>
					<label class="toggle-wrap" class:activo={fConStock}>
						<input type="checkbox" checked={fConStock} onchange={() => { fConStock = !fConStock; buscarModal(); }} />
						Con stock
					</label>
					<div class="switch-wrap">
						<button class:activo={!modoExacto} title="Cada palabra puede estar en cualquier orden (ej: 'fratacho lijador')" onclick={() => { modoExacto = false; buscarModal(); }}>Similar</button>
						<button class:activo={modoExacto} title="Busca la frase completa, tal cual la escribís" onclick={() => { modoExacto = true; buscarModal(); }}>Exacto</button>
					</div>
				</div>
			</div>

			<div class="modal-count">{modalCargando ? '—' : modalResultados.length === 0 ? (modalEstado.startsWith('Sin') ? modalEstado : '—') : modalResultados.length === 100 ? 'Más de 100 resultados — refiná la búsqueda' : `${modalResultados.length} resultado${modalResultados.length === 1 ? '' : 's'}`}</div>

			<div class="modal-tabla">
				{#if !modalResultados.length}
					<div class="modal-estado">{modalEstado}</div>
				{:else}
					<table>
						<thead>
							<tr>
								<th class="check-col"></th>
								<th>Código</th>
								<th>Nombre / Marca</th>
								<th>Proveedor</th>
								<th>Rubro</th>
								<th class="r">Stock</th>
								<th class="r">Precio</th>
							</tr>
						</thead>
						<tbody>
							{#each modalResultados as p, i (p.id)}
								{@const isSel = catalogoSel.has(p.id)}
								{@const stockVal = Number(p.stock_actual) % 1 === 0 ? Number(p.stock_actual) : Number(p.stock_actual).toFixed(2)}
								<tr class:seleccionado={isSel} class:activo={i === modalIdx} onclick={() => toggleSeleccion(p)} onmouseenter={() => (modalIdx = i)}>
									<td class="check-col"><span class="check-mark">{isSel ? '✓' : ''}</span></td>
									<td><span class="codigo">{p.codigo}</span></td>
									<td><span class="nombre">{p.nombre}</span><br /><span class="marca">{p.marca ?? ''}</span></td>
									<td>{p.proveedor ?? '—'}</td>
									<td>{p.categoria ?? '—'}</td>
									<td class="r {stockClass(p.stock_actual)}">{stockVal}</td>
									<td class="r precio-td">{fmt(p.precio_venta)}</td>
								</tr>
							{/each}
						</tbody>
					</table>
				{/if}
			</div>

			{#if catalogoSel.size > 0}
				<div class="sel-panel">
					<div class="sel-panel-head">
						<span class="sel-panel-head-lbl">Seleccionados ({catalogoSel.size})</span>
						<button class="sel-btn-limpiar" onclick={limpiarSeleccionCatalogo}>✕ Limpiar todo</button>
					</div>
					{#each [...catalogoSel.values()] as p (p.id)}
						<div class="sel-item">
							<span class="sel-item-nombre">{p.nombre}</span>
							<span class="sel-item-precio">{fmt(p.precio_venta)}</span>
							<button class="sel-item-rm" title="Quitar" onclick={() => quitarSeleccionado(p.id)}>×</button>
						</div>
					{/each}
				</div>
				<div class="sel-footer">
					<span class="sel-footer-info">{catalogoSel.size} producto{catalogoSel.size > 1 ? 's' : ''} seleccionado{catalogoSel.size > 1 ? 's' : ''}</span>
					<button class="sel-btn-ok" onclick={confirmarSeleccionCatalogo}>Agregar {catalogoSel.size} al carrito →</button>
				</div>
			{/if}
		</div>
	</div>
{/if}

<!-- Modal Nuevo Cliente -->
{#if ncAbierto}
	<div class="nc-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalNuevoCli()}>
		<div class="nc-modal" role="dialog" aria-modal="true">
			<div class="nc-head">
				<h3>Nuevo cliente</h3>
				<button class="nc-cerrar" aria-label="Cerrar" onclick={cerrarModalNuevoCli}>×</button>
			</div>
			<div class="nc-body">
				<div class="nc-grid">
					<div class="nc-group nc-full">
						<label class="nc-label" for="nc-nombre">Nombre / Razón Social *</label>
						<input type="text" id="nc-nombre" class="nc-input" autocomplete="off" bind:value={ncNombre} onkeydown={(e) => e.key === 'Enter' && guardarNuevoCli()} />
					</div>
					<div class="nc-group">
						<label class="nc-label" for="nc-cuit">CUIT</label>
						<input type="text" id="nc-cuit" class="nc-input" placeholder="20-12345678-9" autocomplete="off" bind:value={ncCuit} />
					</div>
					<div class="nc-group">
						<label class="nc-label" for="nc-condicion">Condición fiscal</label>
						<select id="nc-condicion" class="nc-select" bind:value={ncCondicion}>
							<option value="">— Sin especificar —</option>
							<option value="Consumidor Final">Consumidor Final</option>
							<option value="Responsable Inscripto">Responsable Inscripto</option>
							<option value="Monotributista">Monotributista</option>
							<option value="Exento">Exento</option>
							<option value="No Responsable">No Responsable</option>
						</select>
					</div>
					<div class="nc-group nc-full">
						<label class="nc-label" for="nc-domicilio">Domicilio</label>
						<input type="text" id="nc-domicilio" class="nc-input" placeholder="Av. Corrientes 1234" autocomplete="off" bind:value={ncDomicilio} />
					</div>
					<div class="nc-group">
						<label class="nc-label" for="nc-provincia">Provincia</label>
						<input type="text" id="nc-provincia" class="nc-input" placeholder="Buenos Aires" autocomplete="off" bind:value={ncProvincia} />
					</div>
					<div class="nc-group">
						<label class="nc-label" for="nc-telefono">Teléfono</label>
						<input type="text" id="nc-telefono" class="nc-input" placeholder="011 4567-8901" autocomplete="off" bind:value={ncTelefono} />
					</div>
					<div class="nc-group nc-full">
						<label class="nc-label" for="nc-email">Email</label>
						<input type="email" id="nc-email" class="nc-input" placeholder="ejemplo@mail.com" autocomplete="off" bind:value={ncEmail} />
					</div>
				</div>
			</div>
			<div class="nc-foot">
				<button class="nc-btn-sec" onclick={cerrarModalNuevoCli}>Cancelar</button>
				<button class="nc-btn-pri" disabled={ncGuardando} onclick={guardarNuevoCli}>{ncGuardando ? 'Guardando…' : 'Guardar cliente'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal Pago Mixto -->
{#if pmAbierto}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarPM()}>
		<div class="modal pm-modal" role="dialog" aria-modal="true">
			<div class="pm-head">
				<h2>Pago Mixto</h2>
				<button class="pm-close" aria-label="Cerrar" onclick={cerrarPM}>×</button>
			</div>
			<div class="pm-body">
				<div class="pm-total">Total a cobrar: <strong>{fmt(totalConEnvio())}</strong></div>
				<div class="pm-lineas">
					{#each pagosMixtoTemp as p, i (i)}
						<div class="pm-linea">
							<select class="pm-tipo-sel" bind:value={pagosMixtoTemp[i].tipo}>
								{#each TIPOS_PAGO as t (t.val)}<option value={t.val}>{t.lbl}</option>{/each}
							</select>
							<input type="number" class="pm-monto-inp" placeholder="0,00" min="0" step="any" bind:value={pagosMixtoTemp[i].monto} />
							<button class="pm-auto-btn" onclick={() => pmAutoLinea(i)}>Autocompletar</button>
							{#if pagosMixtoTemp.length > 1}<button class="pm-del-btn" onclick={() => pmQuitarLinea(i)}>×</button>{/if}
						</div>
					{/each}
				</div>
				{#if pagosMixtoTemp.length < 5}
					<button class="pm-add-btn" onclick={pmAgregarLinea}>
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 5v14M5 12h14" /></svg>
						Agregar método
					</button>
				{/if}
				<div class="pm-estado">
					{#if pmCubierto}
						<span class="pm-estado-lbl">Total cubierto</span><span class="pm-estado-val ok">✓ {fmt(totalConEnvio())}</span>
					{:else if pmRestante > 0}
						<span class="pm-estado-lbl">Restante</span><span class="pm-estado-val">{fmt(pmRestante)}</span>
					{:else}
						<span class="pm-estado-lbl">Excede por</span><span class="pm-estado-val neg">{fmt(-pmRestante)}</span>
					{/if}
				</div>
			</div>
			<div class="pm-footer">
				<button class="pm-btn pm-btn-ghost" onclick={cerrarPM}>Cancelar</button>
				<button class="pm-btn pm-btn-primary" disabled={!pmCubierto} onclick={confirmarPM}>Confirmar pago</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.main {
	  display: grid; grid-template-columns: 1fr 320px; gap: 12px;
	  padding: 12px; flex: 1; overflow: hidden; min-height: 0;
	  background: var(--neo-bg-deep);
	}
	.col-izq { display: flex; flex-direction: column; gap: 10px; min-height: 0; }
	.busqueda-rapida { position: relative; }
	.busqueda-rapida .busq-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--neo-text-3); pointer-events: none; z-index: 1; }
	.busqueda-rapida input {
	  width: 100%; padding: 11px 14px 11px 40px; font-size: 14px; border: none;
	  border-radius: var(--neo-r-sm); outline: none;
	  background: var(--neo-bg); color: var(--neo-text); font-family: inherit;
	  box-shadow: var(--neo-i1); transition: box-shadow var(--neo-t-fast); box-sizing: border-box;
	}
	.busqueda-rapida input:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.dropdown {
	  position: absolute; top: calc(100% + 6px); left: 0; right: 0;
	  background: var(--neo-bg); border-radius: var(--neo-r-md); box-shadow: var(--neo-e4);
	  z-index: 100; max-height: 260px; overflow-y: auto; display: none; border: none;
	}
	.dropdown.visible { display: block; }
	.dd-item { padding: 9px 14px; cursor: pointer; display: grid; grid-template-columns: 110px 1fr auto; gap: 8px; align-items: center; border-bottom: 1px solid var(--borde); font-size: 12px; }
	.dd-item:last-child { border-bottom: none; }
	.dd-item:hover, .dd-item.activo { background: var(--primary-soft); }
	.dd-item .cod { color: var(--neo-text-2); font-family: monospace; font-size: 12px; white-space: nowrap; }
	.dd-item .nom { font-weight: 500; color: var(--neo-text); }
	.dd-item .pre { font-weight: 700; color: var(--neo-accent); }

	.items-card { background: var(--neo-bg-deep); border-radius: var(--neo-r-lg); box-shadow: var(--neo-i1); display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; border: 1px solid var(--borde-fuerte); }
	.items-header {
	  padding: 9px 14px; border-bottom: 1px solid var(--borde-fuerte);
	  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3);
	  display: grid; grid-template-columns: 24px 1fr 90px 96px 108px 32px; gap: 8px; align-items: center;
	  background: var(--neo-bg-deep);
	}
	.items-header .r { text-align: right; }
	.items-lista { overflow-y: auto; flex: 1; }
	.item-row { display: grid; grid-template-columns: 24px 1fr 90px 96px 108px 32px; gap: 8px; align-items: center; padding: 7px 14px; border-bottom: 1px solid var(--borde); }
	.item-row:hover { background: var(--color-bg-alt); }
	.item-row .nom { font-weight: 500; line-height: 1.3; min-width: 0; overflow-wrap: break-word; color: var(--neo-text); }
	.item-row .nom small { display: block; color: var(--neo-text-2); font-size: 11px; font-weight: 400; }
	.item-row .sub { text-align: right; font-weight: 600; color: var(--neo-text); white-space: nowrap; }
	.cant-wrap { display: flex; align-items: center; gap: 2px; }
	.cant-btn {
	  flex-shrink: 0; width: 24px; height: 28px; padding: 0; border: none;
	  border-radius: var(--neo-r-xs); background: var(--neo-bg); cursor: pointer; line-height: 1;
	  font-size: 16px; font-weight: 400; color: var(--neo-text-3);
	  display: flex; align-items: center; justify-content: center;
	  box-shadow: var(--neo-e1); transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast); user-select: none;
	}
	.cant-btn:hover { background: var(--color-bg-alt); box-shadow: none; color: var(--neo-accent); }
	.cant-wrap .item-input { text-align: center; min-width: 0; }
	.item-chk, .hdr-chk { accent-color: var(--neo-accent); width: 14px; height: 14px; cursor: pointer; margin: 0; display: block; }
	.ajuste-badge { display: inline-block; padding: 1px 5px; border-radius: var(--neo-r-xs); font-size: 10px; font-weight: 700; margin-left: 5px; vertical-align: middle; }
	.ajuste-badge.desc { background: rgba(231,76,60,.1); color: var(--neo-danger); }
	.ajuste-badge.inc { background: rgba(39,174,96,.1); color: var(--neo-success); }

	.sel-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 7px 12px; background: var(--neo-bg); border-radius: var(--neo-r-md); box-shadow: var(--neo-e1); border: none; font-size: 12px; }
	.sel-count { font-weight: 600; color: var(--neo-text-3); white-space: nowrap; }
	.sel-bar.activo { box-shadow: var(--neo-e2), 0 0 0 2px var(--neo-accent); }
	.sel-bar.activo .sel-count { color: var(--neo-accent); }
	.sel-acciones { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; opacity: .25; pointer-events: none; transition: opacity .2s; }
	.sel-bar.activo .sel-acciones { opacity: 1; pointer-events: auto; }
	.sel-sep { width: 1px; height: 18px; background: var(--color-bg-alt); flex-shrink: 0; }
	.sel-btn {
	  display: flex; align-items: center; gap: 4px; padding: 4px 10px; border: none;
	  border-radius: var(--neo-r-xs); box-shadow: var(--neo-e1);
	  font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap;
	  background: var(--neo-bg); color: var(--neo-text); transition: box-shadow var(--neo-t-fast);
	}
	.sel-btn:hover { background: var(--color-bg-alt); box-shadow: none; }
	.sel-btn-del { color: var(--neo-danger); }
	.sel-btn-del:hover { background: var(--neo-danger); color: white; box-shadow: 3px 3px 6px var(--neo-danger-glow); }
	.sel-aj-grupo { display: flex; align-items: center; gap: 6px; }
	.sel-aj-input-wrap { display: flex; align-items: stretch; }
	.sel-input { width: 68px; padding: 4px 6px; border: none; border-radius: var(--neo-r-xs) 0 0 var(--neo-r-xs); font-size: 12px; font-family: inherit; background: var(--neo-bg); color: var(--neo-text); outline: none; text-align: right; box-shadow: var(--neo-i1); }
	.sel-aj-unit-toggle { display: flex; box-shadow: var(--neo-e1); border-radius: 0 var(--neo-r-xs) var(--neo-r-xs) 0; overflow: hidden; }
	.sel-aj-unit-btn { padding: 0 8px; border: none; background: var(--neo-bg); font-size: 11px; font-weight: 700; cursor: pointer; color: var(--neo-text-3); font-family: inherit; }
	.sel-aj-unit-btn.activo { background: var(--neo-accent); color: white; }
	.sel-aj-tipo-toggle { display: flex; box-shadow: var(--neo-e1); border-radius: var(--neo-r-xs); overflow: hidden; }
	.sel-aj-tipo-btn { padding: 4px 10px; border: none; background: var(--neo-bg); font-size: 11px; font-weight: 700; cursor: pointer; color: var(--neo-text-3); font-family: inherit; white-space: nowrap; }
	.sel-aj-tipo-btn:first-child.activo { background: var(--neo-danger); color: white; }
	.sel-aj-tipo-btn:last-child.activo { background: var(--neo-success); color: white; }
	.sel-visible-wrap { display: flex; align-items: center; gap: 4px; cursor: pointer; user-select: none; font-size: 11px; color: var(--neo-text-3); white-space: nowrap; }
	.sel-visible-wrap input[type=checkbox] { accent-color: var(--neo-accent); cursor: pointer; }

	.item-input {
	  width: 100%; padding: 4px 6px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 13px; text-align: right; font-family: inherit;
	  background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-i1);
	  transition: box-shadow var(--neo-t-fast);
	}
	.item-input:focus { outline: none; box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.btn-del { background: none; border: none; cursor: pointer; color: var(--neo-danger); font-size: 17px; padding: 4px; border-radius: var(--neo-r-xs); line-height: 1; display: flex; align-items: center; justify-content: center; transition: background var(--neo-t-fast); }
	.btn-del:hover { background: rgba(231,76,60,.1); }
	.items-vacio { padding: 40px 20px; text-align: center; color: var(--neo-text-2); line-height: 2; }

	.col-der { display: flex; flex-direction: column; gap: 10px; overflow-y: auto; }
	.card { background: var(--neo-bg); border-radius: var(--neo-r-md); box-shadow: var(--neo-e2); padding: 14px; border: 1px solid var(--borde-fuerte); }
	.card label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 5px; }
	.card select, .card input[type=text], .card input[type=number] {
	  width: 100%; padding: 8px 10px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 13px; font-family: inherit; background: var(--neo-bg); color: var(--neo-text); outline: none;
	  box-shadow: var(--neo-i1); transition: box-shadow var(--neo-t-fast); box-sizing: border-box;
	}
	.card select:focus, .card input[type=text]:focus, .card input[type=number]:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }

	label.envio-toggle { display: flex; align-items: center; gap: 9px; cursor: pointer; user-select: none; margin: 0; font-size: 12px; font-weight: 600; color: var(--neo-text); text-transform: none; letter-spacing: 0; }
	.envio-toggle input[type=checkbox] { display: none; }
	.envio-switch { width: 36px; height: 20px; border-radius: 10px; background: var(--neo-bg-deep); border: none; box-shadow: var(--neo-i1); position: relative; flex-shrink: 0; transition: background 150ms ease-out, box-shadow 150ms ease-out; }
	.envio-switch::after { content: ''; position: absolute; top: 3px; left: 3px; width: 14px; height: 14px; border-radius: 50%; background: var(--neo-bg); box-shadow: var(--neo-e1); transition: transform 150ms cubic-bezier(0.23,1,0.32,1); }
	.envio-toggle input:checked ~ .envio-switch { background: var(--neo-accent); box-shadow: inset 2px 2px 4px rgba(0,0,0,.2), 0 0 0 2px var(--neo-accent); }
	.envio-toggle input:checked ~ .envio-switch::after { transform: translateX(16px); }
	.envio-reveal { max-height: 0; overflow: hidden; margin-top: 0; transition: max-height 180ms cubic-bezier(0.23,1,0.32,1), margin-top 180ms cubic-bezier(0.23,1,0.32,1); }
	.envio-reveal.visible { max-height: 70px; margin-top: 10px; }
	.envio-fields { display: grid; grid-template-columns: 110px 1fr; gap: 8px; padding: 0 3px 4px; }
	.envio-fields label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 4px; display: block; }

	.cliente-sel { flex: 1; min-width: 0; background: var(--primary-soft); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1), 0 0 0 2px var(--neo-accent); padding: 8px 10px; font-size: 12px; }
	.cliente-sel .cnombre { font-weight: 600; color: var(--neo-text); }
	.cliente-sel .csaldo { color: var(--neo-text-3); font-size: 12px; margin-top: 2px; }
	.cliente-sel .cquitar { float: right; background: none; border: none; cursor: pointer; color: var(--neo-text-3); font-size: 17px; line-height: 1; padding: 0; }
	.cliente-sel .cquitar:hover { color: var(--neo-danger); }

	.totales { display: flex; flex-direction: column; gap: 6px; }
	.t-row { display: flex; justify-content: space-between; font-size: 12px; color: var(--neo-text-2); }
	.t-total { display: flex; justify-content: space-between; font-size: 28px; font-weight: 800; color: var(--color-primary); margin-top: 6px; padding-top: 10px; border-top: 1px solid var(--borde-fuerte); }

	.btn { width: 100%; padding: 13px; border: none; border-radius: var(--neo-r-md); font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit; transition: box-shadow var(--neo-t-fast); letter-spacing: .3px; }
	.btn-ok { background: var(--color-accent); color: var(--color-primary-dark); display: flex; align-items: center; justify-content: center; gap: 10px; padding: 16px 13px; font-size: 16px; letter-spacing: .6px; box-shadow: none; }
	.btn-ok:hover { background: var(--color-accent-h); box-shadow: none; }
	.btn-ok:disabled { background: var(--neo-bg-deep); color: var(--neo-text-3); cursor: not-allowed; box-shadow: var(--neo-i1); opacity: 0.55; }

	.pago-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 6px; }
	.pago-tile {
	  position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center;
	  gap: 5px; padding: 10px 4px 8px; cursor: pointer; font-family: inherit;
	  border: 1px solid var(--borde-fuerte); background: #fff; border-radius: var(--neo-r-sm); box-shadow: none;
	  color: var(--neo-text-2); transition: border-color var(--neo-t-fast), color var(--neo-t-fast), background var(--neo-t-fast); user-select: none;
	}
	.pago-tile:hover { border-color: var(--color-primary); color: var(--color-primary); }
	.pago-tile.activo { border-color: var(--color-primary); background: var(--primary-soft); box-shadow: inset 0 0 0 1px var(--color-primary); color: var(--color-primary); }
	.pago-tile svg { width: 18px; height: 18px; flex-shrink: 0; }
	.pago-tile-lbl { display: block; font-size: 9.5px; font-weight: 700; letter-spacing: .2px; line-height: 1.15; text-align: center; overflow-wrap: break-word; max-width: 100%; }
	.pago-tile-shortcut { position: absolute; top: 3px; right: 4px; font-size: 8px; font-weight: 700; letter-spacing: .3px; color: var(--neo-text-3); opacity: .65; }
	.pago-tile.activo .pago-tile-shortcut { color: var(--color-primary); opacity: 1; }
	.pos-cheque-wrap { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--borde); }
	.pos-chq-titulo { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 6px; }
	.pos-chq-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
	.pos-chq-grid input { font-size: 12px; width: 100%; box-sizing: border-box; padding: 5px 8px; border-radius: var(--neo-r-sm); border: none; box-shadow: var(--neo-i1); background: var(--neo-bg); color: var(--neo-text); outline: none; }
	.pos-chq-lbl { font-size: 10px; color: var(--neo-text-3); margin-bottom: 2px; display: block; }
	.pago-mixto-footer { display: flex; justify-content: flex-end; margin-top: 7px; }
	.pago-mixto-link { background: none; border: none; cursor: pointer; padding: 0; font-size: 12px; font-weight: 600; color: var(--neo-accent); white-space: nowrap; text-decoration: underline; text-underline-offset: 2px; }
	.pago-mixto-resumen { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
	.pago-mixto-detalle { font-size: 12px; line-height: 1.7; flex: 1; color: var(--neo-text-2); }
	.pago-mixto-acciones { display: flex; align-items: center; gap: 6px; flex-shrink: 0; padding-top: 2px; }
	.pago-mixto-reset { background: none; border: none; cursor: pointer; color: var(--neo-text-3); font-size: 18px; line-height: 1; padding: 0; }
	.pago-mixto-reset:hover { color: var(--neo-danger); }

	.pm-modal { width: min(440px,95vw) !important; }
	.pm-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--borde-fuerte); flex-shrink: 0; }
	.pm-head h2 { font-size: 16px; font-weight: 700; color: var(--neo-text); }
	.pm-close { background: none; border: none; cursor: pointer; color: var(--neo-text-3); font-size: 22px; line-height: 1; padding: 4px 8px; border-radius: var(--neo-r-xs); }
	.pm-body { padding: 16px 20px; flex: 1; overflow-y: auto; }
	.pm-total { font-size: 12px; color: var(--neo-text-3); margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--borde-fuerte); }
	.pm-total strong { color: var(--neo-text); font-size: 22px; font-weight: 800; margin-left: 4px; }
	.pm-lineas { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
	.pm-linea { display: flex; gap: 6px; align-items: center; }
	.pm-tipo-sel { width: 130px; flex-shrink: 0; padding: 7px 6px; border: none; border-radius: var(--neo-r-xs); font-size: 12px; font-family: inherit; background: var(--neo-bg); color: var(--neo-text); outline: none; cursor: pointer; box-shadow: var(--neo-i1); }
	.pm-monto-inp { flex: 1; min-width: 0; padding: 7px 10px; border: none; border-radius: var(--neo-r-xs); font-size: 13px; font-family: inherit; background: var(--neo-bg); color: var(--neo-text); outline: none; text-align: right; box-shadow: var(--neo-i1); }
	.pm-auto-btn { flex-shrink: 0; padding: 5px 9px; border: none; border-radius: var(--neo-r-xs); background: var(--neo-bg); box-shadow: var(--neo-e1); font-size: 11px; font-weight: 600; cursor: pointer; color: var(--neo-text-3); white-space: nowrap; }
	.pm-del-btn { flex-shrink: 0; width: 28px; height: 28px; border: none; background: none; cursor: pointer; color: var(--neo-text-3); font-size: 18px; border-radius: var(--neo-r-xs); padding: 0; display: flex; align-items: center; justify-content: center; }
	.pm-del-btn:hover { background: rgba(231,76,60,.1); color: var(--neo-danger); }
	.pm-add-btn { display: flex; align-items: center; gap: 5px; width: 100%; padding: 7px; background: none; border: 2px dashed var(--borde-fuerte); border-radius: var(--neo-r-sm); font-size: 12px; font-weight: 600; color: var(--neo-text-3); cursor: pointer; margin-bottom: 10px; }
	.pm-estado { display: flex; justify-content: space-between; align-items: center; font-size: 12px; padding: 8px 12px; border-radius: var(--neo-r-sm); background: var(--neo-bg-deep); box-shadow: var(--neo-i1); }
	.pm-estado-lbl { color: var(--neo-text-3); }
	.pm-estado-val { font-weight: 700; color: var(--neo-text); }
	.pm-estado-val.neg { color: var(--neo-danger); }
	.pm-estado-val.ok { color: var(--neo-success); }
	.pm-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid var(--borde-fuerte); flex-shrink: 0; }
	.pm-btn { padding: 8px 18px; border-radius: var(--neo-r-sm); font-size: 12px; font-weight: 600; cursor: pointer; border: none; font-family: inherit; }
	.pm-btn-ghost { background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-e2); }
	.pm-btn-primary { background: var(--color-accent); color: var(--color-primary-dark); box-shadow: none; }
	.pm-btn-primary:disabled { background: var(--neo-bg-deep); color: var(--neo-text-3); cursor: not-allowed; box-shadow: var(--neo-i1); }

	.top-bar { background: var(--neo-bg); box-shadow: 0 4px 16px var(--neo-sd), 0 -2px 6px var(--neo-sl); padding: 12px 16px; display: flex; align-items: flex-end; gap: 14px; flex-shrink: 0; border-bottom: 1px solid var(--primary-soft-2); }
	.tb-cliente { flex: 1; min-width: 0; }
	.tb-comp { width: 148px; flex-shrink: 0; }
	.tb-fecha { width: 132px; flex-shrink: 0; }
	.tb-obs { width: 230px; flex-shrink: 0; }
	.top-bar label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); margin-bottom: 5px; }
	.top-bar input[type=text], .top-bar input[type=date], .top-bar select {
	  width: 100%; padding: 8px 10px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 12px; font-family: inherit; background: var(--neo-bg); color: var(--neo-text); outline: none;
	  box-shadow: var(--neo-i1); transition: box-shadow var(--neo-t-fast); box-sizing: border-box;
	}
	.top-bar input[type=text]:focus, .top-bar input[type=date]:focus, .top-bar select:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.fecha-modificada { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-warning) !important; }

	.prod-bar { display: flex; gap: 8px; }
	.prod-bar .busqueda-rapida { flex: 1; }
	.cod-rapido { position: relative; width: 150px; flex-shrink: 0; }
	.cod-rapido input {
	  width: 100%; padding: 9px 10px 9px 30px; box-sizing: border-box;
	  border: none; border-radius: var(--neo-r-sm); font-size: 12px; font-family: inherit;
	  background: var(--neo-bg); color: var(--neo-text); outline: none;
	  box-shadow: var(--neo-i1); transition: box-shadow var(--neo-t-fast);
	}
	.cod-rapido input:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.cod-rapido input.cod-ok { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-success); }
	.cod-rapido input.cod-err { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-danger); }
	.cod-icon { position: absolute; left: 8px; top: 50%; transform: translateY(-50%); color: var(--neo-text-3); pointer-events: none; }
	.cat-btn {
	  display: flex; align-items: center; gap: 8px; padding: 0 16px; border: none;
	  background: var(--neo-bg); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1);
	  cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 600;
	  color: var(--neo-text-2); white-space: nowrap; flex-shrink: 0;
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.cat-btn:hover { background: var(--color-bg-alt); box-shadow: none; color: var(--neo-accent); }
	.cat-kbd { font-family: monospace; font-size: 10px; color: var(--neo-text-3); background: var(--neo-bg-deep); padding: 1px 5px; border-radius: var(--neo-r-xs); box-shadow: var(--neo-e1); }

	:global(.overlay) {
	  display: none; position: fixed; inset: 0; z-index: 500;
	  align-items: flex-start; justify-content: center; padding-top: 40px;
	  background: rgba(49,52,75,.48); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
	}
	:global(.overlay.abierto) { display: flex; }
	:global(.overlay .modal) {
	  background: #fff; border: 1px solid var(--borde-fuerte); border-radius: 0;
	  box-shadow: 0 4px 24px rgba(0,0,0,.13);
	  width: min(1760px,98vw); max-width: min(1760px,98vw); max-height: calc(100vh - 80px);
	  display: flex; flex-direction: column; overflow: hidden;
	}
	:global(.overlay .modal-head) { background: var(--color-bg-alt); border-bottom: 1px solid var(--borde-fuerte); padding: 14px 20px 12px; flex-shrink: 0; flex-direction: column; align-items: stretch; }
	.modal-head-top { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
	.modal-head-top h2 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; flex: 1; color: var(--neo-text-3); }
	.modal-close { background: none; border: none; cursor: pointer; color: var(--neo-text-3); font-size: 18px; line-height: 1; padding: 2px 6px; border-radius: var(--neo-r-xs); }
	.modal-search input { width: 100%; padding: 11px 16px; font-size: 16px; border: none; border-bottom: 1px solid var(--borde-fuerte); border-radius: 0; outline: none; font-family: inherit; background: #fff; color: var(--neo-text); box-sizing: border-box; }
	.modal-filtros { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; align-items: center; }
	.modal-filtros select { padding: 6px 10px; border: 1px solid var(--borde-fuerte); border-radius: 0; font-size: 12px; font-family: inherit; background: #fff; color: var(--neo-text); outline: none; min-width: 130px; cursor: pointer; }
	.toggle-wrap { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12px; color: var(--neo-text-2); padding: 6px 10px; border: none; border-radius: var(--neo-r-xs); background: var(--neo-bg); box-shadow: var(--neo-e1); user-select: none; transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast); }
	.toggle-wrap input { display: none; }
	.toggle-wrap.activo { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); color: var(--neo-accent); }
	.switch-wrap { display: flex; border-radius: var(--neo-r-sm); overflow: hidden; margin-left: auto; box-shadow: var(--neo-e1); }
	.switch-wrap button { padding: 6px 14px; border: none; background: var(--neo-bg); font-size: 12px; font-family: inherit; cursor: pointer; color: var(--neo-text-3); font-weight: 500; }
	.switch-wrap button:first-child { border-right: 1px solid var(--borde-fuerte); }
	.switch-wrap button.activo { background: var(--neo-accent); color: white; font-weight: 700; }
	.modal-count { padding: 6px 20px; font-size: 12px; color: var(--neo-text-3); background: var(--neo-bg-deep); border-bottom: 1px solid var(--borde-fuerte); flex-shrink: 0; }
	.modal-tabla { overflow-y: auto; flex: 1; }
	.modal-tabla table { width: 100%; border-collapse: collapse; font-size: 13px; }
	.modal-tabla thead th { position: sticky; top: 0; background: var(--neo-bg-deep); padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--color-ink); border-bottom: 1px solid var(--borde-fuerte); }
	.modal-tabla thead th.r { text-align: right; }
	.modal-tabla tbody tr { cursor: pointer; border-bottom: 1px solid var(--borde); transition: background var(--neo-t-fast); }
	.modal-tabla tbody tr:hover, .modal-tabla tbody tr.activo { background: var(--primary-soft); }
	.modal-tabla tbody td { padding: 9px 12px; vertical-align: middle; color: var(--neo-text); }
	.modal-tabla tbody td.r { text-align: right; white-space: nowrap; }
	.modal-tabla tbody td :global(.codigo) { font-family: monospace; color: var(--neo-text-3); font-size: 12px; }
	.modal-tabla tbody td :global(.nombre) { font-weight: 500; }
	.modal-tabla tbody td :global(.marca) { color: var(--neo-text-3); font-size: 12px; }
	.stock-ok { color: var(--neo-success); font-weight: 600; }
	.stock-no { color: var(--neo-danger); }
	.stock-cero { color: var(--neo-text-3); }
	.precio-td { font-weight: 700; color: var(--neo-accent); }
	.modal-estado { padding: 50px 20px; text-align: center; color: var(--neo-text-3); font-size: 14px; }
	.modal-tabla table th.check-col, .modal-tabla table td.check-col { width: 36px; padding: 0 8px; text-align: center; }
	.check-mark { display: inline-flex; align-items: center; justify-content: center; width: 17px; height: 17px; border: 1.5px solid var(--borde-fuerte); border-radius: var(--neo-r-xs); font-size: 11px; font-weight: 700; color: transparent; transition: all .1s; pointer-events: none; }
	.modal-tabla tbody tr:hover .check-mark { border-color: var(--neo-accent); }
	.modal-tabla tbody tr.seleccionado .check-mark { background: var(--neo-accent); border-color: var(--neo-accent); color: white; }
	.modal-tabla tbody tr.seleccionado { background: var(--primary-soft) !important; }

	.sel-panel { border-top: 2px solid var(--neo-accent); background: var(--primary-soft); flex-shrink: 0; max-height: 190px; overflow-y: auto; }
	.sel-panel-head { display: flex; justify-content: space-between; align-items: center; padding: 7px 16px; border-bottom: 1px solid var(--primary-line); position: sticky; top: 0; background: var(--primary-soft); z-index: 1; }
	.sel-panel-head-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-accent); }
	.sel-btn-limpiar { font-size: 11px; color: var(--neo-text-3); background: none; border: none; cursor: pointer; padding: 2px 8px; border-radius: var(--neo-r-xs); }
	.sel-item { display: flex; align-items: center; gap: 8px; padding: 6px 16px; border-bottom: 1px solid var(--primary-soft-2); font-size: 13px; color: var(--neo-text); }
	.sel-item-nombre { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
	.sel-item-precio { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--neo-text-3); font-size: 12px; white-space: nowrap; }
	.sel-item-rm { background: none; border: none; cursor: pointer; color: var(--neo-text-3); font-size: 17px; line-height: 1; padding: 0 4px; border-radius: 3px; flex-shrink: 0; }
	.sel-item-rm:hover { background: rgba(231,76,60,.1); color: var(--neo-danger); }
	.sel-footer { display: flex; justify-content: space-between; align-items: center; padding: 11px 20px; border-top: 1px solid var(--borde-fuerte); background: var(--neo-bg); flex-shrink: 0; gap: 12px; }
	.sel-footer-info { font-size: 13px; color: var(--neo-text-3); }
	.sel-btn-ok { padding: 9px 22px; background: var(--color-accent); color: var(--color-primary-dark); border: none; border-radius: 0; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; white-space: nowrap; box-shadow: none; }
	.sel-btn-ok:hover { background: var(--neo-accent-h); }

	.cli-search-wrap { display: flex; gap: 6px; align-items: center; }
	.btn-nuevo-cli {
	  flex-shrink: 0; width: 40px; height: 40px; background: var(--color-accent); color: var(--color-primary-dark);
	  border: none; border-radius: var(--neo-r-sm); font-size: 22px; font-weight: 300;
	  cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; box-shadow: none;
	}
	.btn-nuevo-cli:hover { background: var(--neo-accent-h); }
	:global(.nc-overlay) { position: fixed; inset: 0; z-index: 1100; display: none; align-items: center; justify-content: center; background: rgba(49,52,75,.48); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
	:global(.nc-overlay.abierto) { display: flex; }
	.nc-modal { background: #fff; border: 1px solid var(--borde-fuerte); border-radius: 0; box-shadow: 0 4px 24px rgba(0,0,0,.13); width: 560px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
	.nc-head { padding: 14px 20px; background: var(--color-bg-alt); border-bottom: 1px solid var(--borde-fuerte); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
	.nc-head h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--neo-text-3); }
	.nc-cerrar { background: none; border: none; cursor: pointer; color: var(--neo-text-3); font-size: 18px; line-height: 1; padding: 2px 6px; border-radius: var(--neo-r-xs); }
	.nc-body { padding: 20px; overflow-y: auto; flex: 1; }
	.nc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
	.nc-full { grid-column: 1 / -1; }
	.nc-group { display: flex; flex-direction: column; gap: 4px; }
	.nc-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	.nc-input, .nc-select { padding: 8px 10px; border: 1px solid var(--borde-fuerte); border-radius: 0; font-size: 13px; font-family: inherit; outline: none; background: #fff; color: var(--neo-text); box-sizing: border-box; width: 100%; }
	.nc-input:focus, .nc-select:focus { border-color: var(--color-primary); }
	.nc-select { cursor: pointer; }
	.nc-foot { padding: 14px 20px; border-top: 1px solid var(--borde-fuerte); display: flex; justify-content: flex-end; gap: 8px; flex-shrink: 0; }
	.nc-btn-sec { padding: 9px 18px; border: 1.5px solid #888; border-radius: 0; background: #fff; color: #111; box-shadow: none; font-size: 13px; font-weight: 500; cursor: pointer; }
	.nc-btn-pri { padding: 9px 18px; border: none; border-radius: 0; background: var(--color-accent); color: var(--color-primary-dark); box-shadow: none; font-size: 13px; font-weight: 600; cursor: pointer; }
	.nc-btn-pri:disabled { opacity: .5; cursor: not-allowed; }

	.afip-warn { background: rgba(243,156,18,.08); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1), 0 0 0 1.5px var(--neo-warning); padding: 10px 13px; font-size: 12px; line-height: 1.55; border: none; }
	.afip-warn-titulo { font-weight: 700; display: flex; align-items: center; gap: 6px; margin-bottom: 4px; font-size: 12px; color: var(--neo-warning); }
	.afip-warn-msg { color: var(--neo-text-2); }
	.afip-fix-btn { display: inline-flex; align-items: center; gap: 4px; margin-top: 8px; padding: 4px 10px; background: var(--neo-bg); border: none; border-radius: var(--neo-r-xs); box-shadow: var(--neo-e1); font-size: 11px; font-weight: 700; cursor: pointer; color: var(--neo-warning); font-family: inherit; }

	.ultima-venta { background: rgba(39,174,96,.07); border-radius: var(--neo-r-md); box-shadow: var(--neo-e1), 0 0 0 1.5px var(--neo-success); padding: 11px 13px; border: none; }
	.uv-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; }
	.uv-etiqueta { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-success); }
	.uv-cerrar { background: none; border: none; cursor: pointer; color: var(--neo-success); font-size: 18px; line-height: 1; padding: 0; opacity: .45; }
	.uv-cerrar:hover { opacity: 1; }
	.uv-data { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 9px; }
	.uv-comp { font-size: 12px; color: var(--neo-success-h); font-weight: 500; }
	.uv-total { font-size: 18px; font-weight: 800; color: var(--neo-success); }
	.uv-btns { display: flex; gap: 6px; }
	.uv-btn { flex: 1; padding: 6px 8px; border-radius: var(--neo-r-xs); border: none; background: var(--neo-bg); box-shadow: var(--neo-e1); font-size: 11px; font-weight: 600; color: var(--neo-success); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; font-family: inherit; }
</style>
