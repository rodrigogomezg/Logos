<script lang="ts">
	import { page } from '$app/state';
	import { api } from '$lib/api';
	import { leerSesion } from '$lib/session';
	import { cajaOperativaId } from '$lib/operativa';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';

	type Proveedor = { id: number; nombre: string; cuit?: string | null; condicion_iva?: string | null };
	type ProductoBusq = { id: number; nombre: string; codigo: string; proveedor?: string | null; costo_actual?: number | string; iva_porcentaje?: number | string };
	type Item = { producto_id: number; nombre: string; codigo: string; cantidad: number; costo_unitario: number; iva_porcentaje: number; desc_porcentaje: number; desc_monto: number };
	type PagoMixto = { tipo: string; monto: number };
	type Caja = { id: number; nombre: string; tipo: string; sucursal_id: number | null };
	type Deposito = { id: number; nombre: string; activo: boolean | number; es_principal: boolean | number };

	function fmt(n: number | null | undefined) {
		return '$ ' + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function fechaHoy() {
		const d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	const sesion = leerSesion();
	const editId = parseInt(page.url.searchParams.get('id') || '') || null;

	let pasoActual = $state(1);
	let esAnulada = $state(false);
	let modoEdicion = $state(false);
	let proveedorNombreEdicion = $state('');
	let numeroCompraEdicion = $state('');
	let anularConfirmVisible = $state(false);
	let anulando = $state(false);

	// ── Paso 1: proveedor ────────────────────────────────────────
	let proveedorSel = $state<Proveedor | null>(null);
	let filtrarPorProveedor = $state(false);
	let provQuery = $state('');
	let provResultados = $state<Proveedor[]>([]);
	let provDDVisible = $state(false);
	let provDDIdx = $state(-1);
	let provTimeout: ReturnType<typeof setTimeout>;

	let provNuevoVisible = $state(false);
	let pnNombre = $state('');
	let pnCuit = $state('');
	let pnCondicion = $state('');
	let creandoProveedor = $state(false);

	let c1Tipo = $state<'factura_a' | 'factura_b' | 'remito'>('factura_a');
	let c1Numero = $state('');
	let c1Fecha = $state(fechaHoy());
	let dupWarnVisible = $state(false);
	let dupDetalle = $state('');
	let verificandoDup = $state(false);

	function onProvInput(v: string) {
		provQuery = v;
		clearTimeout(provTimeout);
		if (v.trim().length < 2) {
			provDDVisible = false;
			return;
		}
		provTimeout = setTimeout(() => buscarProveedores(v.trim()), 250);
	}
	async function buscarProveedores(q: string) {
		try {
			const res = await api(`/proveedores?q=${encodeURIComponent(q)}`);
			const data = await res.json();
			if (!Array.isArray(data) || !data.length) {
				provDDVisible = false;
				return;
			}
			provResultados = data;
			provDDIdx = -1;
			provDDVisible = true;
		} catch {
			// no bloquea
		}
	}
	function cerrarProvDD() {
		provDDVisible = false;
		provDDIdx = -1;
	}
	function seleccionarProveedor(p: Proveedor) {
		proveedorSel = p;
		cerrarProvDD();
		provQuery = '';
		provNuevoVisible = false;
		resetDupWarn();
	}
	function quitarProveedor() {
		proveedorSel = null;
		filtrarPorProveedor = false;
		provQuery = '';
		resetDupWarn();
	}
	function onProvKeydown(e: KeyboardEvent) {
		if (!provDDVisible || !provResultados.length) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			provDDIdx = Math.min(provDDIdx + 1, provResultados.length - 1);
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			provDDIdx = provDDIdx > 0 ? provDDIdx - 1 : -1;
		} else if (e.key === 'Enter') {
			e.preventDefault();
			const p = provResultados[provDDIdx >= 0 ? provDDIdx : 0];
			if (p) seleccionarProveedor(p);
		} else if (e.key === 'Escape') {
			e.preventDefault();
			cerrarProvDD();
		}
	}

	async function crearProveedor() {
		const nombre = pnNombre.trim();
		if (!nombre) {
			toast_('Ingresá el nombre del proveedor', 'err');
			return;
		}
		creandoProveedor = true;
		try {
			const res = await api('/proveedores', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ nombre, cuit: pnCuit.trim(), condicion_iva: pnCondicion.trim() }) });
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al crear el proveedor');
			seleccionarProveedor(data);
			toast_('Proveedor creado', 'ok');
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
		} finally {
			creandoProveedor = false;
		}
	}

	function resetDupWarn() {
		dupWarnVisible = false;
	}

	async function continuarPaso1() {
		if (!proveedorSel) {
			toast_('Elegí o creá un proveedor', 'err');
			return;
		}
		const numero = c1Numero.trim();
		if (numero) {
			verificandoDup = true;
			try {
				const res = await api(`/compras?proveedor_id=${proveedorSel.id}&tipo_comprobante=${encodeURIComponent(c1Tipo)}&numero_comprobante=${encodeURIComponent(numero)}&limit=5`);
				const data = await res.json();
				const dups = (Array.isArray(data) ? data : []).filter((c: { estado: string; id: number }) => c.estado !== 'anulado' && c.id !== editId);
				if (dups.length) {
					const d = dups[0];
					const fecha = d.fecha ? d.fecha.split('-').reverse().join('/') : '—';
					dupDetalle = `Ya existe la compra N° ${d.numero} del ${fecha} por ${fmt(parseFloat(d.total || 0))} con el mismo proveedor y comprobante.`;
					dupWarnVisible = true;
					verificandoDup = false;
					return;
				}
			} catch {
				// si falla la consulta dejamos pasar
			}
			verificandoDup = false;
		}
		pasoActual = 2;
	}
	function forzarContinuar() {
		resetDupWarn();
		pasoActual = 2;
	}

	// ── Paso 2: productos ────────────────────────────────────────
	let items = $state<Item[]>([]);
	let p2Query = $state('');
	let p2Resultados = $state<ProductoBusq[]>([]);
	let p2DDVisible = $state(false);
	let p2DDIdx = $state(-1);
	let p2Timeout: ReturnType<typeof setTimeout>;
	let p2Archivo = $state<HTMLInputElement | undefined>();
	let leyendoExcel = $state(false);
	let excelErrores = $state<{ fila: number; codigo: string; mensaje: string }[]>([]);
	let p2Percepcion = $state('');
	let p2DescGenPct = $state('');
	let p2DescGenMonto = $state('');
	let actualizarStock = $state(true);
	let actualizarCostos = $state(true);

	function onP2Input(v: string) {
		p2Query = v;
		clearTimeout(p2Timeout);
		if (v.trim().length < 2) {
			p2DDVisible = false;
			return;
		}
		p2Timeout = setTimeout(() => buscarProductos(v.trim()), 250);
	}
	async function buscarProductos(q: string) {
		try {
			let url = `/stock?q=${encodeURIComponent(q)}`;
			if (filtrarPorProveedor && proveedorSel) url += `&proveedor=${encodeURIComponent(proveedorSel.nombre)}`;
			const res = await api(url);
			const data = await res.json();
			const lista: ProductoBusq[] = (data.items || []).slice(0, 8);
			if (!lista.length) {
				p2DDVisible = false;
				return;
			}
			p2Resultados = lista;
			p2DDIdx = -1;
			p2DDVisible = true;
		} catch {
			// no bloquea
		}
	}
	function cerrarP2DD() {
		p2DDVisible = false;
		p2DDIdx = -1;
	}
	function onP2Keydown(e: KeyboardEvent) {
		if (!p2DDVisible || !p2Resultados.length) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			p2DDIdx = Math.min(p2DDIdx + 1, p2Resultados.length - 1);
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			p2DDIdx = p2DDIdx > 0 ? p2DDIdx - 1 : -1;
		} else if (e.key === 'Enter') {
			e.preventDefault();
			const p = p2Resultados[p2DDIdx >= 0 ? p2DDIdx : 0];
			if (p) {
				agregarItem(p);
				cerrarP2DD();
				p2Query = '';
			}
		} else if (e.key === 'Escape') {
			e.preventDefault();
			cerrarP2DD();
		}
	}
	function seleccionarProducto(p: ProductoBusq) {
		agregarItem(p);
		cerrarP2DD();
		p2Query = '';
	}

	function agregarItem(p: ProductoBusq, cantidad?: number, costo?: number) {
		if (filtrarPorProveedor && proveedorSel) {
			const pProv = (p.proveedor || '').trim().toLowerCase();
			const sProv = proveedorSel.nombre.trim().toLowerCase();
			if (pProv !== sProv) {
				toast_(`"${p.nombre || p.codigo}" no pertenece a ${proveedorSel.nombre} — su proveedor es "${p.proveedor || 'ninguno'}"`, 'err');
				return;
			}
		}
		const idx = items.findIndex((i) => i.producto_id === p.id);
		if (idx !== -1 && cantidad === undefined) {
			items[idx].cantidad += 1;
		} else if (idx !== -1) {
			items[idx].cantidad += cantidad!;
		} else {
			items.push({
				producto_id: p.id,
				nombre: p.nombre,
				codigo: p.codigo,
				cantidad: cantidad ?? 1,
				costo_unitario: costo ?? parseFloat(String(p.costo_actual)) ?? 0,
				iva_porcentaje: parseFloat(String(p.iva_porcentaje ?? 21)),
				desc_porcentaje: 0,
				desc_monto: 0
			});
		}
		items = items;
	}
	function quitarItem(idx: number) {
		items.splice(idx, 1);
		items = items;
	}

	async function leerExcel() {
		const archivo = p2Archivo?.files?.[0];
		if (!archivo) {
			toast_('Elegí un archivo', 'err');
			return;
		}
		leyendoExcel = true;
		try {
			const fd = new FormData();
			fd.append('archivo', archivo);
			const res = await api('/compras/leer-excel', { method: 'POST', body: fd });
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al leer el archivo');

			(data.filas || []).forEach((f: { producto_id: number; producto_nombre: string; codigo: string; proveedor?: string; costo_unitario: number; iva_porcentaje: number; cantidad: number }) => {
				agregarItem({ id: f.producto_id, nombre: f.producto_nombre, codigo: f.codigo, proveedor: f.proveedor, costo_actual: f.costo_unitario, iva_porcentaje: f.iva_porcentaje }, f.cantidad, f.costo_unitario);
			});

			excelErrores = data.errores && data.errores.length ? data.errores : [];
			if (p2Archivo) p2Archivo.value = '';
			toast_(`${data.filas.length} producto(s) cargado(s)`, 'ok');
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
		} finally {
			leyendoExcel = false;
		}
	}

	function calcularTotales() {
		let subtotal = 0,
			ivaTotal = 0;
		items.forEach((i) => {
			const sub_bruto = i.cantidad * i.costo_unitario;
			const sub_neto = sub_bruto - Math.min(sub_bruto, Math.max(0, i.desc_monto ?? 0));
			subtotal += sub_neto;
			ivaTotal += (sub_neto * i.iva_porcentaje) / 100;
		});
		const percepPct = parseFloat(p2Percepcion) || 0;
		const percepMonto = percepPct > 0 ? (subtotal * percepPct) / 100 : 0;
		const totalBruto = subtotal + ivaTotal + percepMonto;
		const descGenMonto = Math.min(totalBruto, Math.max(0, parseFloat(p2DescGenMonto) || 0));
		const total = totalBruto - descGenMonto;
		return { subtotal, ivaTotal, percepMonto, totalBruto, descGenMonto, total };
	}
	const totales = $derived(calcularTotales());

	function itemSubNeto(i: Item) {
		return i.cantidad * i.costo_unitario - (i.desc_monto ?? 0);
	}
	function itemIva(i: Item) {
		return (itemSubNeto(i) * i.iva_porcentaje) / 100;
	}

	function onItemCantidad(idx: number, v: string) {
		const val = parseFloat(v);
		if (!isNaN(val) && val > 0) items[idx].cantidad = val;
		reaplicarDescPct(idx);
	}
	function onItemCosto(idx: number, v: string) {
		const val = parseFloat(v);
		if (!isNaN(val) && val >= 0) items[idx].costo_unitario = val;
		reaplicarDescPct(idx);
	}
	function onItemIva(idx: number, v: string) {
		const val = parseFloat(v);
		if (!isNaN(val) && val >= 0) items[idx].iva_porcentaje = val;
	}
	function onItemDescPct(idx: number, v: string) {
		const val = parseFloat(v);
		if (!isNaN(val) && val >= 0) {
			const pct = Math.min(100, val);
			items[idx].desc_porcentaje = pct;
			const sub_bruto = items[idx].cantidad * items[idx].costo_unitario;
			items[idx].desc_monto = (sub_bruto * pct) / 100;
		}
	}
	function onItemDescMonto(idx: number, v: string) {
		const val = parseFloat(v);
		if (!isNaN(val) && val >= 0) {
			const sub_bruto = items[idx].cantidad * items[idx].costo_unitario;
			items[idx].desc_monto = Math.min(sub_bruto, val);
			items[idx].desc_porcentaje = sub_bruto > 0 ? (items[idx].desc_monto / sub_bruto) * 100 : 0;
		}
	}
	function reaplicarDescPct(idx: number) {
		if (items[idx].desc_porcentaje > 0) {
			const sub_bruto = items[idx].cantidad * items[idx].costo_unitario;
			items[idx].desc_monto = (sub_bruto * items[idx].desc_porcentaje) / 100;
		}
	}
	function onDescGenPctInput() {
		const pct = parseFloat(p2DescGenPct) || 0;
		const t = calcularTotales();
		p2DescGenMonto = pct > 0 ? ((t.totalBruto * pct) / 100).toFixed(2) : '';
	}
	function onDescGenMontoInput() {
		const monto = parseFloat(p2DescGenMonto) || 0;
		const t = calcularTotales();
		p2DescGenPct = t.totalBruto > 0 && monto > 0 ? ((monto / t.totalBruto) * 100).toFixed(2) : '';
	}

	function volverPaso1() {
		pasoActual = 1;
	}
	function continuarPaso2() {
		if (!items.length) {
			toast_('Agregá al menos un producto', 'err');
			return;
		}
		prepararPaso3();
		pasoActual = 3;
	}

	// ── Paso 3: pago ─────────────────────────────────────────────
	let pagoModo = $state<'simple' | 'mixto'>('simple');
	let p3MedioSimple = $state('efectivo');
	let pagosMixto = $state<PagoMixto[]>([]);
	let cajas = $state<Caja[]>([]);
	let p3CajaId = $state('');
	let depositos = $state<Deposito[]>([]);
	let p3DepositoId = $state('');
	let mostrarDepositoSelector = $state(false);
	let confirmandoCompra = $state(false);

	function setPagoModo(m: 'simple' | 'mixto') {
		pagoModo = m;
		if (m === 'mixto' && pagosMixto.length < 2) {
			pagosMixto = [
				{ tipo: 'efectivo', monto: 0 },
				{ tipo: 'cc', monto: 0 }
			];
		}
		actualizarCajaVisible();
	}
	function agregarLineaPago() {
		pagosMixto = [...pagosMixto, { tipo: 'efectivo', monto: 0 }];
	}
	function quitarLineaPago(idx: number) {
		pagosMixto = pagosMixto.filter((_, i) => i !== idx);
		actualizarCajaVisible();
	}
	const sumaMixto = $derived(pagosMixto.reduce((a, p) => a + p.monto, 0));
	const difMixto = $derived(totales.total - sumaMixto);
	const mixtoOk = $derived(Math.abs(difMixto) < 0.01);

	const noCCMonto = $derived.by(() => {
		if (pagoModo === 'simple') return p3MedioSimple !== 'cc' ? totales.total : 0;
		return pagosMixto.filter((p) => p.tipo !== 'cc').reduce((a, p) => a + p.monto, 0);
	});
	const mostrarCajaSelector = $derived(noCCMonto > 0.001);

	function actualizarCajaVisible() {
		// noCCMonto/mostrarCajaSelector son $derived — se recalculan solos.
	}

	async function actualizarDepositoSelector(sucursalId: number | null) {
		try {
			const url = sucursalId ? `/sucursales/depositos?sucursal_id=${sucursalId}` : '/sucursales/depositos';
			const res = await api(url);
			const deps = await res.json();
			const activos: Deposito[] = Array.isArray(deps) ? deps.filter((d: Deposito) => d.activo) : [];
			if (activos.length <= 1) {
				mostrarDepositoSelector = false;
				depositos = [];
				return;
			}
			depositos = activos;
			const principal = activos.find((d) => d.es_principal);
			p3DepositoId = String(principal ? principal.id : activos[0].id);
			mostrarDepositoSelector = true;
		} catch {
			mostrarDepositoSelector = false;
		}
	}

	async function prepararPaso3() {
		try {
			const res = await api('/cajas');
			cajas = await res.json();
			const cajaActual = cajaOperativaId() || sesion?.caja_id;
			if (cajaActual) p3CajaId = String(cajaActual);
		} catch {
			// no bloquea
		}
		const cajaSel = cajas.find((c) => String(c.id) === p3CajaId);
		if (cajaSel?.sucursal_id) {
			await actualizarDepositoSelector(cajaSel.sucursal_id);
		} else {
			await actualizarDepositoSelector(sesion?.sucursal_id || null);
		}
	}
	async function onCajaChange() {
		const cajaSel = cajas.find((c) => String(c.id) === p3CajaId);
		if (cajaSel?.sucursal_id) {
			await actualizarDepositoSelector(cajaSel.sucursal_id);
		} else {
			await actualizarDepositoSelector(sesion?.sucursal_id || null);
		}
	}

	function volverPaso2() {
		pasoActual = 2;
	}

	// ── Paso 4: resultado ────────────────────────────────────────
	let p4Numero = $state('');
	let p4Monto = $state('');
	let p4ProveedorId = $state<number | null>(null);

	async function confirmarCompra() {
		let tipo_pago: string;
		let pagos: PagoMixto[] | null = null;

		if (pagoModo === 'simple') {
			tipo_pago = p3MedioSimple;
		} else {
			tipo_pago = 'mixto';
			if (!mixtoOk) {
				toast_('La suma de los pagos no coincide con el total', 'err');
				return;
			}
			pagos = pagosMixto.filter((p) => p.monto > 0);
		}

		const caja_id = mostrarCajaSelector ? parseInt(p3CajaId) || null : null;
		const deposito_id = mostrarDepositoSelector ? parseInt(p3DepositoId) || null : null;

		const body = {
			proveedor_id: proveedorSel!.id,
			tipo_comprobante: c1Tipo,
			numero_comprobante: c1Numero.trim() || null,
			fecha: c1Fecha,
			items: items.map((i) => ({
				producto_id: i.producto_id,
				cantidad: i.cantidad,
				costo_unitario: i.costo_unitario,
				iva_porcentaje: i.iva_porcentaje,
				descuento_porcentaje: i.desc_porcentaje || 0,
				descuento_monto: i.desc_monto || 0
			})),
			percepcion_iibb_porcentaje: parseFloat(p2Percepcion) || null,
			descuento_general_porcentaje: parseFloat(p2DescGenPct) || null,
			descuento_general_monto: parseFloat(p2DescGenMonto) || null,
			actualiza_stock: actualizarStock,
			actualiza_costos: actualizarCostos,
			tipo_pago,
			pagos,
			caja_id,
			deposito_id
		};

		confirmandoCompra = true;
		try {
			const url = editId ? `/compras/${editId}` : '/compras';
			const method = editId ? 'PUT' : 'POST';
			const res = await api(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al guardar la compra');

			p4Numero = editId ? `Compra N° ${data.numero} — guardada` : `Compra N° ${data.numero}`;
			p4Monto = fmt(data.total);
			p4ProveedorId = data.proveedor_id;
			pasoActual = 4;
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
		} finally {
			confirmandoCompra = false;
		}
	}

	function verTodasLasCompras() {
		location.href = '/compras';
	}
	function cargarOtra() {
		location.reload();
	}
	function verCCProveedor() {
		location.href = `/cuentacorriente?tipo=proveedor&id=${p4ProveedorId}`;
	}
	function cancelarEdicion() {
		location.href = '/compras';
	}

	// ── Modo edición ─────────────────────────────────────────────
	async function cargarModoEdicion(id: number) {
		try {
			const res = await api(`/compras/${id}`);
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al cargar la compra');

			esAnulada = data.estado === 'anulado';
			modoEdicion = true;
			proveedorNombreEdicion = data.proveedor_nombre;
			numeroCompraEdicion = data.numero;

			seleccionarProveedor({ id: data.proveedor_id, nombre: data.proveedor_nombre });
			c1Tipo = data.tipo_comprobante || 'factura_a';
			c1Numero = data.numero_comprobante || '';
			c1Fecha = data.fecha || fechaHoy();

			items = (data.items || []).map((it: { producto_id: number; producto_nombre: string; producto_codigo: string; cantidad: string; costo_unitario: string; descuento_porcentaje: string; descuento_monto: string; iva_porcentaje: string }) => ({
				producto_id: it.producto_id,
				nombre: it.producto_nombre,
				codigo: it.producto_codigo,
				cantidad: parseFloat(it.cantidad),
				costo_unitario: parseFloat(it.costo_unitario),
				iva_porcentaje: parseFloat(it.iva_porcentaje),
				desc_porcentaje: parseFloat(it.descuento_porcentaje) || 0,
				desc_monto: parseFloat(it.descuento_monto) || 0
			}));
			p2Percepcion = data.percepcion_iibb_porcentaje || '';
			p2DescGenPct = data.descuento_general_porcentaje || '';
			p2DescGenMonto = data.descuento_general_monto > 0 ? String(data.descuento_general_monto) : '';
			actualizarStock = data.actualiza_stock !== false;
			actualizarCostos = data.actualiza_costos !== false;

			if (data.tipo_pago === 'mixto' && data.pagos?.length) {
				pagoModo = 'mixto';
				pagosMixto = data.pagos.map((p: { tipo_pago: string; monto: string }) => ({ tipo: p.tipo_pago, monto: parseFloat(p.monto) }));
			} else {
				p3MedioSimple = data.tipo_pago || 'efectivo';
			}
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
		}
	}

	function irAPaso(n: number) {
		if (modoEdicion && !esAnulada) pasoActual = n;
	}

	function abrirAnularConfirm() {
		anularConfirmVisible = true;
	}
	function cancelarAnular() {
		anularConfirmVisible = false;
	}
	async function confirmarAnular() {
		if (!editId) return;
		anulando = true;
		try {
			const res = await api(`/compras/${editId}`, { method: 'DELETE' });
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al anular');
			toast_('Compra anulada', 'ok');
			setTimeout(() => (location.href = '/compras'), 1800);
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
			anularConfirmVisible = false;
		} finally {
			anulando = false;
		}
	}

	if (editId) cargarModoEdicion(editId);
</script>

<svelte:head>
	<title>Logos — Nueva compra</title>
</svelte:head>

<svelte:window
	onclick={(e) => {
		const target = e.target as HTMLElement;
		if (!target.closest('.prov-wrap')) cerrarProvDD();
		if (!target.closest('.busq-wrap')) cerrarP2DD();
	}}
/>

<div class="page-header">
	<h1>{modoEdicion ? (esAnulada ? `Compra N° ${numeroCompraEdicion} — ANULADA` : `Editando compra N° ${numeroCompraEdicion}`) : 'Nueva compra'}</h1>
	<p>{modoEdicion ? (esAnulada ? 'Esta compra está anulada. El stock y la cuenta corriente ya fueron revertidos.' : 'Modificá los datos y confirmá para guardar los cambios.') : 'Cargá una factura o remito de compra en 3 pasos'}</p>
</div>

<div class="contenido">
	<div class="contenido-inner">
		{#if modoEdicion && !anularConfirmVisible}
			<div style="display:flex;background:#FEF2F2;border:1.5px solid #FECACA;border-radius:8px;padding:10px 16px;margin-bottom:14px;align-items:center;justify-content:space-between;gap:12px">
				<span style="font-weight:700;color:var(--rojo);font-size:14px">{esAnulada ? `Compra N° ${numeroCompraEdicion} ANULADA — solo lectura` : `Editando compra N° ${numeroCompraEdicion} — ${proveedorNombreEdicion}`}</span>
				{#if !esAnulada}
					<button class="btn-mini" style="color:var(--rojo);border-color:#FECACA;flex-shrink:0" onclick={abrirAnularConfirm}>Anular compra</button>
				{/if}
			</div>
		{/if}
		{#if anularConfirmVisible}
			<div style="background:#FEF2F2;border:1.5px solid #FECACA;border-radius:8px;padding:12px 16px;margin-bottom:14px">
				<div style="font-weight:700;color:var(--rojo);margin-bottom:8px">¿Confirmar anulación?</div>
				<div style="font-size:13px;margin-bottom:10px">Se revertirán el stock y la cuenta corriente. Esta acción no se puede deshacer.</div>
				<div style="display:flex;gap:8px">
					<button class="btn-sec" onclick={cancelarAnular}>Cancelar</button>
					<button class="btn-pri" style="background:var(--rojo)" disabled={anulando} onclick={confirmarAnular}>{anulando ? 'Anulando…' : 'Sí, anular'}</button>
				</div>
			</div>
		{/if}

		<div class="pasos">
			<button class="paso-pill" class:activo={pasoActual === 1} class:hecho={pasoActual > 1} style={modoEdicion && !esAnulada ? 'cursor:pointer' : ''} onclick={() => irAPaso(1)}>1. Comprobante</button>
			<button class="paso-pill" class:activo={pasoActual === 2} class:hecho={pasoActual > 2} style={modoEdicion && !esAnulada ? 'cursor:pointer' : ''} onclick={() => irAPaso(2)}>2. Productos</button>
			<button class="paso-pill" class:activo={pasoActual === 3} class:hecho={pasoActual > 3} style={modoEdicion && !esAnulada ? 'cursor:pointer' : ''} onclick={() => irAPaso(3)}>3. Pago</button>
			<button class="paso-pill" class:activo={pasoActual === 4}>4. Listo</button>
		</div>

		{#if pasoActual === 1}
			<div class="card">
				<h2>Proveedor y comprobante</h2>
				<p class="sub">Buscá el proveedor o creá uno nuevo, y completá los datos del comprobante.</p>

				<div class="form-group" style="margin-bottom:14px">
					<label class="form-label" for="prov-input">Proveedor</label>
					<div class="prov-wrap">
						{#if !proveedorSel}
							<input type="text" id="prov-input" class="form-input" style="width:100%" placeholder="Buscar proveedor por nombre o CUIT..." autocomplete="off" value={provQuery} oninput={(e) => onProvInput((e.target as HTMLInputElement).value)} onkeydown={onProvKeydown} />
						{/if}
						{#if provDDVisible}
							<div class="prov-dd visible">
								{#each provResultados as p, i (p.id)}
									<div class="prov-dd-item" class:dd-activo={i === provDDIdx} onclick={() => seleccionarProveedor(p)}>
										<div>{p.nombre}</div>
										<div class="cuit">{p.cuit || 'Sin CUIT'}</div>
									</div>
								{/each}
							</div>
						{/if}
						{#if proveedorSel}
							<div class="prov-tag visible">
								<span class="nom">{proveedorSel.nombre}</span>
								<button class="x" aria-label="Quitar" onclick={quitarProveedor}>×</button>
							</div>
						{/if}
					</div>
					<button class="btn-mini" style="margin-top:8px;width:fit-content" onclick={() => (provNuevoVisible = !provNuevoVisible)}>+ Crear proveedor nuevo</button>
					{#if provNuevoVisible}
						<div class="prov-nuevo-form visible">
							<div class="form-group">
								<label class="form-label" for="pn-nombre">Nombre</label>
								<input type="text" id="pn-nombre" class="form-input" bind:value={pnNombre} />
							</div>
							<div class="form-group">
								<label class="form-label" for="pn-cuit">CUIT</label>
								<input type="text" id="pn-cuit" class="form-input" style="min-width:140px" bind:value={pnCuit} />
							</div>
							<div class="form-group">
								<label class="form-label" for="pn-condicion">Condición IVA</label>
								<select id="pn-condicion" class="form-input" style="min-width:180px" bind:value={pnCondicion}>
									<option value="">—</option>
									<option>Responsable Inscripto</option>
									<option>Monotributista</option>
									<option>Consumidor Final</option>
									<option>Exento</option>
									<option>No Responsable</option>
								</select>
							</div>
							<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
								<button class="btn-pri" disabled={creandoProveedor} onclick={crearProveedor}>Crear y usar</button>
							</div>
						</div>
					{/if}
				</div>

				{#if proveedorSel}
					<div style="margin-bottom:14px;padding:10px 14px;background:var(--neo-bg-deep);border-radius:var(--neo-r-sm);box-shadow:var(--neo-i1)">
						<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--neo-text);user-select:none">
							<input type="checkbox" style="accent-color:var(--neo-accent);width:15px;height:15px;cursor:pointer" bind:checked={filtrarPorProveedor} />
							Filtrar productos de esta compra por proveedor
						</label>
						<p class="form-hint" style="margin:5px 0 0 23px">Solo mostrará y aceptará productos cuyo proveedor registrado sea <strong>{proveedorSel.nombre}</strong></p>
					</div>
				{/if}

				<div class="form-row">
					<div class="form-group">
						<label class="form-label" for="c1-tipo">Tipo de comprobante</label>
						<select id="c1-tipo" class="form-input" bind:value={c1Tipo} onchange={resetDupWarn}>
							<option value="factura_a">Factura A</option>
							<option value="factura_b">Factura B</option>
							<option value="remito">Remito</option>
						</select>
					</div>
					<div class="form-group">
						<label class="form-label" for="c1-numero">Número de comprobante</label>
						<input type="text" id="c1-numero" class="form-input" placeholder="Ej: 0001-00001234" bind:value={c1Numero} oninput={resetDupWarn} />
					</div>
					<div class="form-group">
						<label class="form-label" for="c1-fecha">Fecha</label>
						<input type="date" id="c1-fecha" class="form-input" style="min-width:150px" bind:value={c1Fecha} />
					</div>
				</div>

				{#if dupWarnVisible}
					<div style="background:#FEF2F2;border:1.5px solid #FECACA;border-radius:8px;padding:14px 16px;margin-bottom:14px">
						<div style="font-weight:700;color:var(--rojo);font-size:14px;margin-bottom:6px">Comprobante duplicado</div>
						<div style="font-size:13px;margin-bottom:12px">{dupDetalle}</div>
						<div style="display:flex;gap:8px">
							<button class="btn-sec" onclick={resetDupWarn}>Cancelar</button>
							<button class="btn-pri" style="background:var(--rojo)" onclick={forzarContinuar}>Cargar de todas formas</button>
						</div>
					</div>
				{/if}
				<div class="btn-row">
					{#if modoEdicion}
						<button class="btn-sec" onclick={cancelarEdicion}>Cancelar</button>
					{/if}
					{#if !dupWarnVisible}
						<button class="btn-pri" disabled={verificandoDup} onclick={continuarPaso1}>Continuar</button>
					{/if}
				</div>
			</div>
		{:else if pasoActual === 2}
			<div class="card">
				<h2>Productos</h2>
				<p class="sub">Cargá los productos a mano o importá un Excel/CSV con 3 columnas: código, cantidad y costo.</p>

				<div class="busq-wrap">
					<input type="text" class="form-input" style="width:100%" placeholder={filtrarPorProveedor && proveedorSel ? `Buscar producto de ${proveedorSel.nombre}...` : 'Buscar producto por nombre o código...'} autocomplete="off" value={p2Query} oninput={(e) => onP2Input((e.target as HTMLInputElement).value)} onkeydown={onP2Keydown} />
					{#if p2DDVisible}
						<div class="dropdown visible">
							{#each p2Resultados as p, i (p.id)}
								<div class="dd-item" class:dd-activo={i === p2DDIdx} onclick={() => seleccionarProducto(p)}>
									<span class="cod">{p.codigo}</span><span>{p.nombre}</span>
								</div>
							{/each}
						</div>
					{/if}
				</div>
				{#if filtrarPorProveedor && proveedorSel}
					<div style="margin-bottom:10px;padding:8px 12px;background:var(--primary-soft);border-radius:var(--neo-r-sm);font-size:12px;color:var(--neo-accent)">
						Filtro activo: solo productos de <strong>{proveedorSel.nombre}</strong>
					</div>
				{/if}

				<div class="excel-row">
					<input type="file" class="form-input" accept=".xlsx,.xls,.csv" style="min-width:auto" bind:this={p2Archivo} />
					<button class="btn-mini" disabled={leyendoExcel} onclick={leerExcel}>{leyendoExcel ? 'Leyendo…' : 'Leer archivo'}</button>
				</div>
				{#if excelErrores.length}
					<div class="excel-errores">
						{#each excelErrores as e, i (i)}
							<div>Fila {e.fila} ({e.codigo}): {e.mensaje}</div>
						{/each}
					</div>
				{/if}

				<div class="table-wrap">
					<table class="items">
						<colgroup>
							<col />
							<col style="width:90px" />
							<col style="width:140px" />
							<col style="width:78px" />
							<col style="width:78px" />
							<col style="width:120px" />
							<col style="width:150px" />
							<col style="width:125px" />
							<col style="width:150px" />
							<col style="width:38px" />
						</colgroup>
						<thead>
							<tr><th>Producto</th><th>Cant.</th><th>Costo unit.</th><th>% IVA</th><th>% Desc.</th><th>$ Desc.</th><th>Subtotal</th><th>IVA</th><th>Total</th><th></th></tr>
						</thead>
						<tbody>
							{#each items as it, idx (idx)}
								<tr>
									<td>{it.nombre}<br /><small style="color:var(--gris3)">{it.codigo}</small></td>
									<td><input type="number" min="0.001" step="any" value={it.cantidad} oninput={(e) => onItemCantidad(idx, (e.target as HTMLInputElement).value)} /></td>
									<td><input type="number" min="0" step="any" value={it.costo_unitario} oninput={(e) => onItemCosto(idx, (e.target as HTMLInputElement).value)} /></td>
									<td><input type="number" min="0" max="100" step="0.01" value={it.iva_porcentaje} oninput={(e) => onItemIva(idx, (e.target as HTMLInputElement).value)} /></td>
									<td><input type="number" min="0" max="100" step="0.01" value={it.desc_porcentaje || ''} placeholder="0" oninput={(e) => onItemDescPct(idx, (e.target as HTMLInputElement).value)} /></td>
									<td><input type="number" min="0" step="0.01" value={it.desc_monto || ''} placeholder="0" oninput={(e) => onItemDescMonto(idx, (e.target as HTMLInputElement).value)} /></td>
									<td class="td-precio">{fmt(itemSubNeto(it))}</td>
									<td class="td-precio">{fmt(itemIva(it))}</td>
									<td class="td-precio" style="font-weight:700">{fmt(itemSubNeto(it) + itemIva(it))}</td>
									<td><button class="btn-del" onclick={() => quitarItem(idx)}>×</button></td>
								</tr>
							{/each}
						</tbody>
					</table>
					{#if !items.length}
						<div class="vacio-mini">Todavía no agregaste productos</div>
					{/if}
				</div>

				<div class="subseccion">
					<div class="subseccion-titulo">Percepción y descuento general</div>
					<div class="form-row" style="margin-bottom:0;align-items:flex-end;flex-wrap:wrap">
						<div class="form-group" style="max-width:180px">
							<label class="form-label" for="p2-percepcion">Percepción IIBB (%)</label>
							<input type="number" id="p2-percepcion" class="form-input" min="0" step="0.01" placeholder="Opcional" bind:value={p2Percepcion} />
						</div>
						<div class="form-group">
							<label class="form-label" for="p2-desc-gen-pct">Descuento general</label>
							<div style="display:flex;gap:6px;align-items:center">
								<input type="number" id="p2-desc-gen-pct" class="form-input" min="0" max="100" step="0.01" placeholder="%" style="max-width:90px" bind:value={p2DescGenPct} oninput={onDescGenPctInput} />
								<span style="font-size:12px;color:var(--gris3)">% ó</span>
								<input type="number" class="form-input" min="0" step="0.01" placeholder="$ monto" style="max-width:150px" bind:value={p2DescGenMonto} oninput={onDescGenMontoInput} />
							</div>
						</div>
					</div>
				</div>

				<div class="totales-grid">
					<div class="total-box"><div class="lbl">Subtotal</div><div class="val">{fmt(totales.subtotal)}</div></div>
					<div class="total-box"><div class="lbl">IVA</div><div class="val">{fmt(totales.ivaTotal)}</div></div>
					<div class="total-box"><div class="lbl">Percepción</div><div class="val">{fmt(totales.percepMonto)}</div></div>
					{#if totales.descGenMonto > 0.001}
						<div class="total-box"><div class="lbl">Descuento</div><div class="val" style="color:var(--verde)">{fmt(totales.descGenMonto)}</div></div>
					{/if}
					<div class="total-box total"><div class="lbl">Total</div><div class="val">{fmt(totales.total)}</div></div>
				</div>

				<div class="btn-row">
					<button class="btn-sec" onclick={volverPaso1}>Volver</button>
					<button class="btn-pri" onclick={continuarPaso2}>Continuar</button>
				</div>
			</div>
		{:else if pasoActual === 3}
			<div class="card">
				<h2>Pago</h2>
				<p class="sub">Total a pagar: <strong>{fmt(totales.total)}</strong></p>

				<div class="pago-modo-row">
					<button class="pago-modo-btn" class:activo={pagoModo === 'simple'} onclick={() => setPagoModo('simple')}>Un solo medio</button>
					<button class="pago-modo-btn" class:activo={pagoModo === 'mixto'} onclick={() => setPagoModo('mixto')}>Combinar varios medios</button>
				</div>

				<div class="subseccion">
					{#if pagoModo === 'simple'}
						<div class="form-group" style="max-width:220px">
							<label class="form-label" for="p3-medio-simple">Medio de pago</label>
							<select id="p3-medio-simple" class="form-input" bind:value={p3MedioSimple}>
								<option value="efectivo">Efectivo</option>
								<option value="transferencia">Transferencia</option>
								<option value="tarjeta">Tarjeta</option>
								<option value="cc">Cuenta corriente</option>
							</select>
						</div>
					{:else}
						<div>
							{#each pagosMixto as p, idx (idx)}
								<div class="pago-linea">
									<select bind:value={p.tipo}>
										<option value="efectivo">Efectivo</option>
										<option value="transferencia">Transferencia</option>
										<option value="tarjeta">Tarjeta</option>
										<option value="cc">Cuenta corriente</option>
									</select>
									<input type="number" min="0" step="any" bind:value={p.monto} style="width:140px;padding:8px 10px;border:1.5px solid var(--borde);border-radius:8px" />
									<button class="btn-del" onclick={() => quitarLineaPago(idx)}>×</button>
								</div>
							{/each}
							<button class="btn-mini" onclick={agregarLineaPago}>+ Agregar medio</button>
							<div class="pago-resumen {mixtoOk ? 'ok' : 'err'}">
								{mixtoOk ? `Suma: ${fmt(sumaMixto)} — coincide con el total` : `Suma: ${fmt(sumaMixto)} — faltan ${fmt(difMixto)} para llegar al total`}
							</div>
						</div>
					{/if}
				</div>

				{#if mostrarCajaSelector || mostrarDepositoSelector}
					<div class="subseccion">
						<div class="subseccion-titulo">Destino de la compra</div>
						{#if mostrarCajaSelector}
							<div class="form-group" style="max-width:260px">
								<label class="form-label" for="p3-caja">Descuento de caja <em style="font-weight:400;font-style:normal;color:var(--neo-text-3)">(opcional)</em></label>
								<select id="p3-caja" class="form-input" bind:value={p3CajaId} onchange={onCajaChange}>
									<option value="">Sin asignar a caja</option>
									{#each cajas as c (c.id)}
										<option value={c.id}>{c.nombre} ({c.tipo})</option>
									{/each}
								</select>
								<span class="form-hint">Si la caja tiene turno abierto, se registra el egreso. Si no, el pago queda solo en la compra.</span>
							</div>
						{/if}

						{#if mostrarDepositoSelector}
							<div class="form-group" style="max-width:260px; margin-top:{mostrarCajaSelector ? '14px' : '0'}">
								<label class="form-label" for="p3-deposito">Depósito destino</label>
								<select id="p3-deposito" class="form-input" bind:value={p3DepositoId}>
									{#each depositos as d (d.id)}
										<option value={d.id}>{d.nombre}</option>
									{/each}
								</select>
								<span class="form-hint">El stock de esta compra ingresa a este depósito.</span>
							</div>
						{/if}
					</div>
				{/if}

				<div class="subseccion">
					<div class="subseccion-titulo">Qué actualiza esta compra</div>
					<label class="check-row">
						<input type="checkbox" bind:checked={actualizarStock} />
						<span>Actualizar stock</span>
					</label>
					<span class="form-hint" style="margin-left:23px">Suma la cantidad de cada ítem al stock del depósito destino.</span>
					<label class="check-row" style="margin-top:10px">
						<input type="checkbox" bind:checked={actualizarCostos} />
						<span>Actualizar costos</span>
					</label>
					<span class="form-hint" style="margin-left:23px">Carga el costo final (con descuentos aplicados) como costo actual de cada producto y recalcula precios.</span>
				</div>

				<div class="btn-row">
					<button class="btn-sec" onclick={volverPaso2}>Volver</button>
					<button class="btn-pri" disabled={confirmandoCompra || esAnulada} onclick={confirmarCompra}>{confirmandoCompra ? 'Guardando…' : editId ? 'Guardar cambios' : 'Confirmar compra'}</button>
				</div>
			</div>
		{:else if pasoActual === 4}
			<div class="card">
				<div class="exito-box">
					<div class="check">✓</div>
					<h2>{modoEdicion ? 'Compra actualizada' : 'Compra registrada'}</h2>
					<div class="sub">{p4Numero}</div>
					<div class="monto">{p4Monto}</div>
					<div class="exito-acciones">
						<button class="btn-sec" onclick={verTodasLasCompras}>Ver todas las compras</button>
						{#if !modoEdicion}
							<button class="btn-sec" onclick={cargarOtra}>Cargar otra</button>
						{/if}
						<button class="btn-pri" onclick={verCCProveedor}>Ver cuenta corriente del proveedor</button>
					</div>
				</div>
			</div>
		{/if}
	</div>
</div>

<style>
	.page-header {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd), 0 -1px 4px var(--neo-sl);
		padding: 20px clamp(20px, 4vw, 48px);
		flex-shrink: 0;
		position: relative;
		z-index: 10;
	}
	.page-header h1 {
		font-size: 20px;
		font-weight: 700;
		letter-spacing: -0.3px;
		color: var(--neo-text);
	}
	.page-header p {
		font-size: 13px;
		color: var(--neo-text-3);
		margin-top: 4px;
	}
	.contenido {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: clamp(16px, 2vw, 28px) clamp(16px, 2.5vw, 32px);
		background: var(--neo-bg-deep);
	}
	.pasos {
		display: flex;
		gap: 6px;
		margin-bottom: 18px;
	}
	.paso-pill {
		flex: 1;
		padding: 9px 14px;
		border-radius: var(--neo-r-md);
		font-size: 12px;
		font-weight: 700;
		color: var(--neo-text-3);
		text-align: center;
		background: var(--neo-bg);
		box-shadow: var(--neo-e1);
		border: none;
		font-family: inherit;
	}
	.paso-pill.activo {
		color: var(--neo-accent);
		box-shadow: var(--neo-i1);
	}
	.paso-pill.hecho {
		color: var(--neo-success);
		box-shadow: var(--neo-i1);
	}
	.subseccion {
		background: var(--neo-bg);
		border-radius: var(--neo-r-md);
		box-shadow: var(--neo-e1);
		padding: 16px 18px;
		margin-bottom: 16px;
	}
	.subseccion-titulo {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		margin-bottom: 12px;
	}
	.check-row {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 13px;
		color: var(--neo-text);
		cursor: pointer;
		user-select: none;
	}
	.check-row input[type='checkbox'] {
		accent-color: var(--neo-accent);
		width: 15px;
		height: 15px;
		cursor: pointer;
	}
	.card {
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e2);
		padding: 22px;
		margin-bottom: 16px;
	}
	.card h2 {
		font-size: 15px;
		font-weight: 800;
		margin-bottom: 4px;
		color: var(--neo-text);
	}
	.card .sub {
		font-size: 13px;
		color: var(--neo-text-3);
		margin-bottom: 16px;
	}
	.form-row {
		display: flex;
		gap: 14px;
		align-items: flex-end;
		flex-wrap: wrap;
		margin-bottom: 14px;
	}
	.form-group {
		display: flex;
		flex-direction: column;
		gap: 5px;
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
		color: var(--neo-text);
		box-shadow: var(--neo-i1);
		min-width: 200px;
	}
	.form-hint {
		font-size: 12px;
		color: var(--neo-text-3);
		margin-top: 4px;
	}
	.btn-pri {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 10px 20px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 14px;
		font-weight: 700;
		cursor: pointer;
		font-family: inherit;
		background: var(--neo-accent);
		color: white;
		box-shadow: 4px 4px 10px var(--neo-accent-glow), -2px -2px 5px rgba(255, 255, 255, 0.15);
	}
	.btn-pri:hover:not(:disabled) {
		background: var(--neo-accent-h);
	}
	.btn-pri:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
	.btn-sec {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 10px 20px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 14px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-e2);
	}
	.btn-sec:hover {
		box-shadow: var(--neo-e3);
	}
	.btn-mini {
		padding: 7px 14px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-weight: 700;
		cursor: pointer;
		font-family: inherit;
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-e1);
	}
	.btn-mini:hover {
		box-shadow: var(--neo-e2);
	}
	.btn-row {
		display: flex;
		gap: 10px;
		justify-content: flex-end;
		margin-top: 18px;
	}
	.prov-wrap {
		position: relative;
		max-width: 360px;
	}
	.prov-tag {
		display: flex;
		align-items: center;
		gap: 8px;
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-e1);
		background: var(--primary-soft-2);
		padding: 9px 12px;
	}
	.prov-tag .nom {
		font-weight: 700;
		flex: 1;
		color: var(--neo-accent);
	}
	.prov-tag .x {
		background: none;
		border: none;
		cursor: pointer;
		color: var(--neo-danger);
		font-size: 18px;
		line-height: 1;
	}
	.prov-dd {
		position: absolute;
		top: calc(100% + 6px);
		left: 0;
		right: 0;
		background: var(--neo-bg);
		border-radius: var(--neo-r-md);
		box-shadow: var(--neo-e3);
		z-index: 100;
		max-height: 240px;
		overflow-y: auto;
	}
	.prov-dd-item {
		padding: 9px 14px;
		cursor: pointer;
		border-bottom: 1px solid var(--borde);
		font-size: 13px;
		color: var(--neo-text);
	}
	.prov-dd-item:last-child {
		border-bottom: none;
	}
	.prov-dd-item:hover,
	.prov-dd-item.dd-activo {
		background: rgba(255, 255, 255, 0.55);
	}
	.prov-dd-item .cuit {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 1px;
	}
	.prov-nuevo-form {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
		align-items: flex-end;
		margin-top: 10px;
		padding: 14px;
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-md);
		box-shadow: var(--neo-i1);
	}
	.busq-wrap {
		position: relative;
		max-width: 420px;
		margin-bottom: 10px;
	}
	.dropdown {
		position: absolute;
		top: calc(100% + 6px);
		left: 0;
		right: 0;
		background: var(--neo-bg);
		border-radius: var(--neo-r-md);
		box-shadow: var(--neo-e3);
		z-index: 100;
		max-height: 240px;
		overflow-y: auto;
	}
	.dd-item {
		padding: 9px 14px;
		cursor: pointer;
		border-bottom: 1px solid var(--borde);
		font-size: 13px;
		display: flex;
		gap: 10px;
		color: var(--neo-text);
	}
	.dd-item:last-child {
		border-bottom: none;
	}
	.dd-item:hover,
	.dd-item.dd-activo {
		background: rgba(255, 255, 255, 0.55);
	}
	.dd-item .cod {
		font-family: monospace;
		color: var(--neo-text-3);
		font-size: 12px;
	}
	.excel-row {
		display: flex;
		gap: 10px;
		align-items: center;
		margin-bottom: 6px;
	}
	.excel-errores {
		background: rgba(231, 76, 60, 0.08);
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-i1);
		padding: 10px 14px;
		margin-bottom: 14px;
		font-size: 13px;
		color: var(--neo-danger);
	}
	.excel-errores div {
		padding: 2px 0;
	}
	.table-wrap {
		overflow-x: auto;
		border-radius: var(--neo-r-md);
		box-shadow: var(--neo-i1);
		margin-bottom: 16px;
		background: var(--neo-bg-deep);
	}
	table.items {
		width: 100%;
		border-collapse: collapse;
		table-layout: fixed;
	}
	table.items th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--color-ink);
		padding: 9px 10px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		overflow: hidden;
		background: var(--neo-bg-deep);
	}
	table.items td {
		padding: 6px 10px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		vertical-align: middle;
		white-space: nowrap;
		overflow: hidden;
		color: var(--neo-text);
	}
	table.items input {
		width: 100%;
		padding: 5px 7px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 13px;
		font-family: inherit;
		text-align: right;
		background: var(--neo-bg);
		box-shadow: var(--neo-i1);
		color: var(--neo-text);
		box-sizing: border-box;
		outline: none;
	}
	.td-precio {
		text-align: right;
		font-variant-numeric: tabular-nums;
	}
	.btn-del {
		background: none;
		border: none;
		cursor: pointer;
		color: var(--neo-danger);
		font-size: 16px;
		padding: 4px;
		opacity: 0.7;
	}
	.btn-del:hover {
		opacity: 1;
	}
	.vacio-mini {
		padding: 18px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 13px;
	}
	.totales-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
		gap: 14px;
		margin-bottom: 6px;
	}
	.total-box {
		background: var(--neo-bg);
		border-radius: var(--neo-r-md);
		box-shadow: var(--neo-e1);
		padding: 14px 16px;
	}
	.total-box .lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--neo-text-3);
		margin-bottom: 4px;
	}
	.total-box .val {
		font-size: 20px;
		font-weight: 800;
		font-variant-numeric: tabular-nums;
		color: var(--neo-text);
	}
	.total-box.total {
		box-shadow: var(--neo-e2);
	}
	.total-box.total .val {
		color: var(--neo-accent);
	}
	.pago-modo-row {
		display: flex;
		gap: 10px;
		margin-bottom: 14px;
	}
	.pago-modo-btn {
		padding: 10px 16px;
		border: none;
		border-radius: var(--neo-r-sm);
		background: var(--neo-bg);
		box-shadow: var(--neo-e1);
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
		color: var(--neo-text-2);
	}
	.pago-modo-btn.activo {
		box-shadow: var(--neo-i1);
		color: var(--neo-accent);
	}
	.pago-linea {
		display: flex;
		gap: 10px;
		align-items: center;
		margin-bottom: 8px;
	}
	.pago-linea select {
		padding: 8px 10px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		box-shadow: var(--neo-i1);
		color: var(--neo-text);
	}
	.pago-resumen {
		font-size: 13px;
		margin-top: 8px;
		padding: 10px 14px;
		border-radius: var(--neo-r-sm);
		box-shadow: var(--neo-i1);
	}
	.pago-resumen.ok {
		background: rgba(39, 174, 96, 0.1);
		color: var(--neo-success);
	}
	.pago-resumen.err {
		background: rgba(231, 76, 60, 0.1);
		color: var(--neo-danger);
	}
	.exito-box {
		text-align: center;
		padding: 30px 0;
	}
	.exito-box .check {
		font-size: 48px;
		color: var(--neo-success);
		margin-bottom: 10px;
	}
	.exito-box h2 {
		font-size: 20px;
		margin-bottom: 6px;
		color: var(--neo-text);
	}
	.exito-box .monto {
		font-size: 30px;
		font-weight: 800;
		color: var(--neo-accent);
		margin: 14px 0;
		font-variant-numeric: tabular-nums;
	}
	.exito-acciones {
		display: flex;
		gap: 10px;
		justify-content: center;
		flex-wrap: wrap;
		margin-top: 18px;
	}
</style>
