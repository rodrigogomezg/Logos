<script lang="ts">
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';
	import { puede } from '$lib/session';
	import { abrirPdf } from '$lib/pdf';
	import { combobox } from './combo-action';

	type Producto = {
		id: number;
		nombre: string;
		descripcion: string | null;
		codigo: string | null;
		codigo_secundario: string | null;
		precio_venta: number | null;
		costo_actual: number | null;
		regla_precio_id: number | null;
		marca: string | null;
		proveedor: string | null;
		categoria: string | null;
		subcategoria: string | null;
		stock_minimo: number | null;
		iva_porcentaje: number | null;
		unidad_medida: string | null;
		peso: number | null;
		activo: boolean;
		publicado_web: boolean;
		comisionable: boolean;
		comision_tipo: 'porcentaje' | 'fijo';
		comision_valor: number | null;
	};

	type StockItem = {
		id: number;
		codigo: string | null;
		nombre: string;
		proveedor: string | null;
		marca: string | null;
		categoria: string | null;
		stock_actual: number;
		stock_minimo: number;
		precio_venta: number | null;
		stock_deposito?: number;
		stock_total?: number;
	};

	type Escala = { desde: number; precio: number };
	type ReglaPrecio = { id: number; nombre: string; porcentaje_recargo: number };
	type TaxItem = { id: number; nombre: string };
	type AlertaItem = { codigo: string | null; nombre: string; stock_actual: number; stock_minimo: number; faltante: number };
	type AlertaGrupo = { proveedor: string | null; items: AlertaItem[] };
	type HistorialMov = { fecha: string; tipo: string; cantidad: number };

	const esAdmin = $derived(puede('productos_editar'));
	const veCostos = $derived(puede('costos'));

	// ── Utilidades ───────────────────────────────────────────────
	function fmt(n: number | string | null | undefined): string {
		if (n == null || n === '' || isNaN(Number(n))) return '—';
		return '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function fmtN(n: number | string | null | undefined): string {
		const v = parseFloat(String(n ?? 0));
		return v % 1 === 0 ? String(v) : v.toFixed(2);
	}
	function stockClass(stock: number, minimo: number): string {
		if (stock < 0) return 'stock-neg';
		if (stock === 0) return 'stock-cero';
		if (minimo > 0 && stock <= minimo) return 'stock-bajo';
		return 'stock-ok';
	}

	// ── Estado general ───────────────────────────────────────────
	let tabActivo = $state<'datos' | 'stock'>('datos');
	let busq = $state('');
	let filProveedor = $state('');
	let filMarca = $state('');
	let filRubro = $state('');
	let filOrden = $state('nombre_asc');
	let filStock = $state('');
	let filDeposito = $state('');
	let busqTimer: ReturnType<typeof setTimeout>;

	let proveedores = $state<string[]>([]);
	let subcategorias = $state<string[]>([]);
	let taxRubros = $state<TaxItem[]>([]);
	let taxMarcas = $state<TaxItem[]>([]);
	let reglasPrecio = $state<ReglaPrecio[]>([]);
	const rubrosNombres = $derived(taxRubros.map((r) => r.nombre));
	const marcasNombres = $derived(taxMarcas.map((m) => m.nombre));

	const hayFiltroComun = $derived(!!(busq || filProveedor || filMarca || filRubro));
	const hayFiltroStock = $derived(tabActivo === 'stock' && !!filStock);
	const mostrarLimpiar = $derived(hayFiltroComun || hayFiltroStock);

	function limpiarFiltros() {
		busq = '';
		filProveedor = '';
		filMarca = '';
		filRubro = '';
		filStock = '';
		filDeposito = '';
		filOrden = 'nombre_asc';
		if (tabActivo === 'datos') {
			pagDatos = 1;
			cargarDatos();
		} else {
			pagStock = 1;
			cargarStock();
		}
	}

	function onFiltroComunChange() {
		if (tabActivo === 'datos') {
			pagDatos = 1;
			cargarDatos();
		} else {
			pagStock = 1;
			cargarStock();
		}
	}

	function onBusqInput() {
		clearTimeout(busqTimer);
		busqTimer = setTimeout(onFiltroComunChange, 350);
	}

	function cambiarTab(tab: 'datos' | 'stock') {
		if (tab === tabActivo) return;
		if (editandoId !== null) cancelarEditarStock();
		tabActivo = tab;
		if (tab === 'datos') {
			pagDatos = 1;
			cargarDatos();
		} else {
			pagStock = 1;
			cargarStock();
		}
	}

	// ── Tab Datos ────────────────────────────────────────────────
	let pagDatos = $state(1);
	let perPage = $state(100);
	let totalPagsDatos = $state(1);
	let totalFiltradoDatos = $state(0);
	let itemsDatos = $state<Producto[]>([]);
	let cargandoDatos = $state(true);
	let seleccionados = $state<Set<number>>(new Set());
	let todosLosFiltrados = $state(false);
	let filtroCurrent = $state<Record<string, string>>({});
	let rowSaved = $state<number | null>(null);

	function getFiltros() {
		return { q: busq.trim(), proveedor: filProveedor, marca: filMarca, categoria: filRubro };
	}

	async function cargarDatos() {
		cargandoDatos = true;
		const f = getFiltros();
		filtroCurrent = f;
		const params = new URLSearchParams({ page: String(pagDatos), per_page: String(perPage), orden: filOrden });
		if (f.q) params.set('q', f.q);
		if (f.marca) params.set('marca', f.marca);
		if (f.categoria) params.set('categoria', f.categoria);
		if (f.proveedor) params.set('proveedor', f.proveedor);
		try {
			const r = await api(`/productos?${params}`);
			const data = await r.json();
			totalFiltradoDatos = data.total;
			totalPagsDatos = data.pages || 1;
			pagDatos = data.page;
			itemsDatos = data.items;
			if (todosLosFiltrados) {
				todosLosFiltrados = false;
				seleccionados = new Set();
			}
		} catch {
			itemsDatos = [];
			toast_('Error de conexión', 'err');
		} finally {
			cargandoDatos = false;
		}
	}

	// ── Selección ────────────────────────────────────────────────
	const chksVisibles = $derived(itemsDatos.map((p) => p.id));
	const todosMarcados = $derived(chksVisibles.length > 0 && chksVisibles.every((id) => seleccionados.has(id) || todosLosFiltrados));
	const algunosMarcados = $derived(chksVisibles.some((id) => seleccionados.has(id) || todosLosFiltrados));

	function onCheckChange(id: number, checked: boolean) {
		todosLosFiltrados = false;
		const s = new Set(seleccionados);
		if (checked) s.add(id);
		else s.delete(id);
		seleccionados = s;
	}
	function toggleTodosVisible(checked: boolean) {
		todosLosFiltrados = false;
		const s = new Set(seleccionados);
		for (const id of chksVisibles) {
			if (checked) s.add(id);
			else s.delete(id);
		}
		seleccionados = s;
	}
	function seleccionarTodosFiltrados() {
		todosLosFiltrados = true;
		seleccionados = new Set();
	}
	function deseleccionarTodo() {
		todosLosFiltrados = false;
		seleccionados = new Set();
	}
	const nSeleccionados = $derived(todosLosFiltrados ? totalFiltradoDatos : seleccionados.size);

	// ── Modal nuevo producto ────────────────────────────────────
	let modalNuevoAbierto = $state(false);
	function vacioNuevo() {
		return {
			nombre: '',
			descripcion: '',
			codigo: '',
			codigoSec: '',
			unidad: '',
			costoNeto: '',
			costoBruto: '',
			iva: '21',
			margen: '',
			reglaPrecio: '',
			precio: '',
			proveedor: '',
			marca: '',
			categoria: '',
			subcategoria: '',
			stockInicial: '',
			stockMin: '',
			peso: '',
			activo: true,
			publicadoWeb: false,
			comisionable: false,
			comisionTipo: 'porcentaje' as 'porcentaje' | 'fijo',
			comisionValor: ''
		};
	}
	let nuevoForm = $state(vacioNuevo());
	let nuevoCodigoError = $state('');
	let nuevoGuardando = $state(false);
	let nuevoPrecioDisabled = $state(false);
	let nuevoMargenDisabled = $state(false);
	let nuevoReglaHint = $state('');

	function abrirModalNuevo() {
		nuevoForm = vacioNuevo();
		nuevoCodigoError = '';
		nuevoPrecioDisabled = false;
		nuevoMargenDisabled = false;
		nuevoReglaHint = '';
		modalNuevoAbierto = true;
	}
	function cerrarModalNuevo() {
		modalNuevoAbierto = false;
	}

	function recalcNuevoPrecios(origen: 'neto' | 'bruto' | 'iva' | 'margen' | 'precio') {
		const iva = parseFloat(nuevoForm.iva) || 0;
		const mult = 1 + iva / 100;
		if (origen === 'neto') {
			const v = parseFloat(nuevoForm.costoNeto);
			if (!isNaN(v) && v >= 0) {
				const bruto = v * mult;
				nuevoForm.costoBruto = bruto.toFixed(2);
				const mg = parseFloat(nuevoForm.margen);
				if (!isNaN(mg)) nuevoForm.precio = (bruto * (1 + mg / 100)).toFixed(2);
			} else nuevoForm.costoBruto = '';
		} else if (origen === 'bruto') {
			const v = parseFloat(nuevoForm.costoBruto);
			if (!isNaN(v) && v >= 0) {
				nuevoForm.costoNeto = (mult > 0 ? v / mult : v).toFixed(2);
				const mg = parseFloat(nuevoForm.margen);
				if (!isNaN(mg)) nuevoForm.precio = (v * (1 + mg / 100)).toFixed(2);
			} else nuevoForm.costoNeto = '';
		} else if (origen === 'iva') {
			const neto = parseFloat(nuevoForm.costoNeto);
			if (!isNaN(neto) && neto >= 0) {
				const bruto = neto * mult;
				nuevoForm.costoBruto = bruto.toFixed(2);
				const mg = parseFloat(nuevoForm.margen);
				if (!isNaN(mg)) nuevoForm.precio = (bruto * (1 + mg / 100)).toFixed(2);
			}
		} else if (origen === 'margen') {
			const bruto = parseFloat(nuevoForm.costoBruto);
			const mg = parseFloat(nuevoForm.margen);
			if (!isNaN(bruto) && !isNaN(mg)) nuevoForm.precio = (bruto * (1 + mg / 100)).toFixed(2);
		} else if (origen === 'precio') {
			const precio = parseFloat(nuevoForm.precio);
			const bruto = parseFloat(nuevoForm.costoBruto);
			if (!isNaN(precio) && !isNaN(bruto) && bruto > 0) nuevoForm.margen = ((precio / bruto - 1) * 100).toFixed(1);
		}
		previewPrecioRegla('n');
	}

	function previewPrecioRegla(prefix: 'n' | 'e') {
		const reglaId = prefix === 'n' ? nuevoForm.reglaPrecio : editForm.reglaPrecio;
		if (!reglaId) {
			if (prefix === 'n') {
				nuevoPrecioDisabled = false;
				nuevoMargenDisabled = false;
				nuevoReglaHint = '';
			} else {
				editPrecioDisabled = false;
				editReglaHint = '';
			}
			return;
		}
		const regla = reglasPrecio.find((r) => String(r.id) === reglaId);
		if (!regla) return;
		const costoNeto = prefix === 'e' ? parseFloat(editForm.costo) : parseFloat(nuevoForm.costoNeto);
		const iva = parseFloat(prefix === 'n' ? nuevoForm.iva : editForm.iva) || 0;
		if (prefix === 'n') nuevoForm.margen = String(regla.porcentaje_recargo);
		if (!isNaN(costoNeto) && costoNeto >= 0) {
			const precio = costoNeto * (1 + iva / 100) * (1 + regla.porcentaje_recargo / 100);
			if (prefix === 'n') nuevoForm.precio = precio.toFixed(2);
			else editForm.precio = precio.toFixed(2);
		}
		const hint = `Precio fijado por la regla "${regla.nombre}" — se recalcula solo cuando cambia el costo.`;
		if (prefix === 'n') {
			nuevoPrecioDisabled = true;
			nuevoMargenDisabled = true;
			nuevoReglaHint = hint;
		} else {
			editPrecioDisabled = true;
			editReglaHint = hint;
		}
	}

	async function guardarNuevoProducto() {
		const nombre = nuevoForm.nombre.trim();
		if (!nombre) {
			toast_('El nombre es requerido', 'err');
			return;
		}
		nuevoCodigoError = '';
		const body = {
			nombre,
			descripcion: nuevoForm.descripcion.trim() || null,
			codigo: nuevoForm.codigo.trim() || null,
			codigo_secundario: nuevoForm.codigoSec.trim() || null,
			precio_venta: nuevoForm.precio !== '' ? parseFloat(nuevoForm.precio) : null,
			costo_actual: nuevoForm.costoNeto !== '' ? parseFloat(nuevoForm.costoNeto) : null,
			regla_precio_id: nuevoForm.reglaPrecio !== '' ? parseInt(nuevoForm.reglaPrecio) : null,
			proveedor: nuevoForm.proveedor.trim() || null,
			marca: nuevoForm.marca.trim() || null,
			categoria: nuevoForm.categoria.trim() || null,
			subcategoria: nuevoForm.subcategoria.trim() || null,
			stock_inicial: nuevoForm.stockInicial !== '' ? parseFloat(nuevoForm.stockInicial) : null,
			stock_minimo: nuevoForm.stockMin !== '' ? parseFloat(nuevoForm.stockMin) : null,
			iva_porcentaje: parseFloat(nuevoForm.iva),
			unidad_medida: nuevoForm.unidad.trim() || null,
			peso: nuevoForm.peso !== '' ? parseFloat(nuevoForm.peso) : null,
			activo: nuevoForm.activo,
			publicado_web: nuevoForm.publicadoWeb,
			comisionable: nuevoForm.comisionable,
			comision_tipo: nuevoForm.comisionTipo,
			comision_valor: nuevoForm.comisionable && nuevoForm.comisionValor !== '' ? parseFloat(nuevoForm.comisionValor) : null
		};
		nuevoGuardando = true;
		try {
			const r = await api('/productos', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
			const data = await r.json();
			if (r.status === 409) {
				nuevoCodigoError = data.error;
				return;
			}
			if (!r.ok) throw new Error(data.error || 'Error al crear');
			cerrarModalNuevo();
			toast_(`${nombre} — producto creado`, 'ok');
			pagDatos = 1;
			cargarDatos();
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al crear', 'err');
		} finally {
			nuevoGuardando = false;
		}
	}

	// ── Modal editar producto ───────────────────────────────────
	let modalEditarAbierto = $state(false);
	let productoActual = $state<Producto | null>(null);
	function vacioEdit() {
		return {
			nombre: '',
			descripcion: '',
			codigo: '',
			codigoSec: '',
			precio: '',
			costo: '',
			reglaPrecio: '',
			marca: '',
			proveedor: '',
			categoria: '',
			subcategoria: '',
			stockMin: '',
			iva: '21',
			unidad: '',
			peso: '',
			activo: true,
			publicadoWeb: false,
			comisionable: false,
			comisionTipo: 'porcentaje' as 'porcentaje' | 'fijo',
			comisionValor: ''
		};
	}
	let editForm = $state(vacioEdit());
	let editPrecioDisabled = $state(false);
	let editReglaHint = $state('');
	let editGuardando = $state(false);
	let escalasFila = $state<Escala[]>([]);

	function abrirModalEditar(p: Producto) {
		productoActual = p;
		editForm = {
			nombre: p.nombre || '',
			descripcion: p.descripcion || '',
			codigo: p.codigo || '',
			codigoSec: p.codigo_secundario || '',
			precio: p.precio_venta != null ? String(p.precio_venta) : '',
			costo: p.costo_actual != null ? String(p.costo_actual) : '',
			reglaPrecio: p.regla_precio_id != null ? String(p.regla_precio_id) : '',
			marca: p.marca || '',
			proveedor: p.proveedor || '',
			categoria: p.categoria || '',
			subcategoria: p.subcategoria || '',
			stockMin: p.stock_minimo != null ? String(p.stock_minimo) : '',
			iva: p.iva_porcentaje != null ? String(p.iva_porcentaje) : '21',
			unidad: p.unidad_medida || '',
			peso: p.peso != null ? String(p.peso) : '',
			activo: p.activo !== false,
			publicadoWeb: !!p.publicado_web,
			comisionable: !!p.comisionable,
			comisionTipo: p.comision_tipo || 'porcentaje',
			comisionValor: p.comision_valor != null ? String(p.comision_valor) : ''
		};
		editPrecioDisabled = false;
		editReglaHint = '';
		previewPrecioRegla('e');
		cargarEscalas(p.id);
		modalEditarAbierto = true;
	}
	function cerrarModalEditar() {
		modalEditarAbierto = false;
		productoActual = null;
		escalasFila = [];
	}

	async function cargarEscalas(productoId: number) {
		escalasFila = [];
		try {
			const r = await api(`/productos/${productoId}/escalas`);
			if (!r.ok) return;
			const data = await r.json();
			escalasFila = data.map((e: Escala) => ({ desde: e.desde, precio: e.precio }));
		} catch {
			/* sin escalas si falla */
		}
	}
	function agregarFilaEscala() {
		const ultimaDesde = escalasFila.length > 0 ? escalasFila[escalasFila.length - 1].desde + 1 : 2;
		escalasFila = [...escalasFila, { desde: ultimaDesde, precio: 0 }];
	}
	function eliminarFilaEscala(i: number) {
		escalasFila = escalasFila.filter((_, idx) => idx !== i);
	}

	async function guardarEscalas(productoId: number) {
		const payload = escalasFila
			.filter((e) => e.desde > 0 && e.precio >= 0)
			.sort((a, b) => a.desde - b.desde)
			.map((e) => ({ desde: e.desde, precio: e.precio }));
		const r = await api(`/productos/${productoId}/escalas`, {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		});
		if (!r.ok) {
			const d = await r.json().catch(() => ({}));
			throw new Error(d.error || 'Error al guardar escalas');
		}
	}

	function toggleComisionUpdateLbl(prefix: 'n' | 'e') {
		// no-op: el label reactivo ya se deriva de comisionTipo en el template
	}

	async function guardarProducto() {
		if (!productoActual) return;
		const nombre = editForm.nombre.trim();
		if (!nombre) {
			toast_('El nombre es requerido', 'err');
			return;
		}
		const body = {
			nombre,
			descripcion: editForm.descripcion.trim() || null,
			codigo: editForm.codigo.trim() || null,
			codigo_secundario: editForm.codigoSec.trim() || null,
			precio_venta: editForm.precio !== '' ? parseFloat(editForm.precio) : null,
			costo_actual: editForm.costo !== '' ? parseFloat(editForm.costo) : null,
			regla_precio_id: editForm.reglaPrecio !== '' ? parseInt(editForm.reglaPrecio) : null,
			marca: editForm.marca.trim() || null,
			proveedor: editForm.proveedor.trim() || null,
			categoria: editForm.categoria.trim() || null,
			subcategoria: editForm.subcategoria.trim() || null,
			stock_minimo: editForm.stockMin !== '' ? parseFloat(editForm.stockMin) : null,
			iva_porcentaje: parseFloat(editForm.iva),
			unidad_medida: editForm.unidad.trim() || null,
			peso: editForm.peso !== '' ? parseFloat(editForm.peso) : null,
			activo: editForm.activo,
			publicado_web: editForm.publicadoWeb,
			comisionable: editForm.comisionable,
			comision_tipo: editForm.comisionTipo,
			comision_valor: editForm.comisionable && editForm.comisionValor !== '' ? parseFloat(editForm.comisionValor) : null
		};
		editGuardando = true;
		try {
			const r = await api(`/productos/${productoActual.id}`, {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body)
			});
			const data = await r.json();
			if (!r.ok) throw new Error(data.error || 'Error al guardar');
			await guardarEscalas(productoActual.id);
			const id = productoActual.id;
			cerrarModalEditar();
			toast_(`${nombre} — cambios guardados`, 'ok');
			const idx = itemsDatos.findIndex((p) => p.id === id);
			if (idx !== -1) itemsDatos[idx] = { ...itemsDatos[idx], ...body, id };
			rowSaved = id;
			setTimeout(() => (rowSaved = null), 1500);
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al guardar', 'err');
		} finally {
			editGuardando = false;
		}
	}

	// ── Modal bulk ───────────────────────────────────────────────
	let modalBulkAbierto = $state(false);
	let bulkCampo = $state<'proveedor' | 'marca' | 'categoria' | 'precio_pct' | 'regla_precio_id' | null>(null);
	let bulkVal = $state('');
	let bulkGuardando = $state(false);
	const BULK_LABELS: Record<string, string> = { proveedor: 'Proveedor', marca: 'Marca', categoria: 'Rubro', precio_pct: 'Precio', regla_precio_id: 'Regla de precio' };

	function abrirModalBulk(campo: typeof bulkCampo) {
		bulkCampo = campo;
		bulkVal = '';
		modalBulkAbierto = true;
	}
	function cerrarModalBulk() {
		modalBulkAbierto = false;
		bulkCampo = null;
	}
	const bulkTipoExistente = $derived.by(() => {
		if (bulkCampo !== 'marca' && bulkCampo !== 'categoria') return true;
		const v = bulkVal.trim();
		if (!v) return true;
		const lista = bulkCampo === 'marca' ? marcasNombres : rubrosNombres;
		return lista.some((n) => n.toLowerCase() === v.toLowerCase());
	});

	async function aplicarBulk() {
		if (!bulkCampo) return;
		// bulkVal está tipado como string, pero bind:value en <input type="number">
		// (usado para precio_pct) lo pisa con un number en tiempo real — .trim()
		// directo tira TypeError y corta la función en silencio antes de llegar
		// al fetch (caso real 18/08/2026: "Aplicar" en Aumento de precio no hacía
		// nada, sin ningún error visible para el usuario).
		const val = String(bulkVal).trim();
		let cambios: Record<string, unknown> = {};
		if (bulkCampo === 'precio_pct') {
			const pct = parseFloat(val);
			if (isNaN(pct) || pct === 0) {
				toast_('Ingresá un porcentaje válido', 'err');
				return;
			}
			if (pct <= -100) {
				toast_('El porcentaje no puede ser −100% o menor', 'err');
				return;
			}
			cambios = { precio_pct: pct };
		} else if (bulkCampo === 'regla_precio_id') {
			cambios = { regla_precio_id: val ? parseInt(val) : null };
		} else {
			if (!val) {
				toast_('Ingresá un valor', 'err');
				return;
			}
			const tipo = bulkCampo === 'marca' ? 'marcas' : bulkCampo === 'categoria' ? 'rubros' : null;
			if (tipo) {
				const lista = tipo === 'marcas' ? taxMarcas : taxRubros;
				if (!lista.some((n) => n.nombre.toLowerCase() === val.toLowerCase())) {
					const cr = await api('/taxonomias', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tipo, nombre: val }) });
					if (!cr.ok) {
						const d = await cr.json();
						toast_(d.error || 'Error al crear', 'err');
						return;
					}
					const nd = await cr.json();
					if (tipo === 'marcas') taxMarcas = [...taxMarcas, { id: nd.id, nombre: val }].sort((a, b) => a.nombre.localeCompare(b.nombre));
					else taxRubros = [...taxRubros, { id: nd.id, nombre: val }].sort((a, b) => a.nombre.localeCompare(b.nombre));
				}
			}
			cambios = { [bulkCampo]: val };
		}
		const n = nSeleccionados;
		if (n > 1000) {
			const ok = await confirmar(`Estás por modificar ${n.toLocaleString('es-AR')} productos a la vez.`, { titulo: 'Cambio masivo', confirmLabel: 'Aplicar igual', danger: true });
			if (!ok) return;
		}
		const bodyReq = todosLosFiltrados ? { modo: 'filtro', filtro: filtroCurrent, cambios } : { modo: 'ids', ids: [...seleccionados], cambios };
		bulkGuardando = true;
		try {
			const r = await api('/productos/bulk', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(bodyReq) });
			const data = await r.json();
			if (!r.ok) throw new Error(data.error || 'Error');
			cerrarModalBulk();
			const af = data.afectados;
			toast_(`${af} producto${af !== 1 ? 's' : ''} actualizado${af !== 1 ? 's' : ''}`, 'ok');
			deseleccionarTodo();
			cargarDatos();
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error', 'err');
		} finally {
			bulkGuardando = false;
		}
	}

	// ── Modal eliminar producto ──────────────────────────────────
	let modalEliminarAbierto = $state(false);
	let eliminarObjetivo = $state<{ tipo: 'individual'; id: number; nombre: string } | { tipo: 'ids'; ids: number[] } | { tipo: 'filtro'; filtro: Record<string, string> } | null>(null);
	let eliminarGuardando = $state(false);

	function abrirModalEliminarIndividual(p: Producto) {
		eliminarObjetivo = { tipo: 'individual', id: p.id, nombre: p.nombre };
		modalEliminarAbierto = true;
	}
	function abrirModalEliminarBulk() {
		eliminarObjetivo = todosLosFiltrados ? { tipo: 'filtro', filtro: filtroCurrent } : { tipo: 'ids', ids: [...seleccionados] };
		modalEliminarAbierto = true;
	}
	function cerrarModalEliminar() {
		modalEliminarAbierto = false;
		eliminarObjetivo = null;
	}

	async function confirmarEliminar() {
		if (!eliminarObjetivo) return;
		eliminarGuardando = true;
		try {
			if (eliminarObjetivo.tipo === 'individual') {
				const id = eliminarObjetivo.id;
				const r = await api(`/productos/${id}`, { method: 'DELETE' });
				const data = await r.json();
				if (!r.ok) throw new Error(data.error || 'Error al eliminar');
				cerrarModalEliminar();
				toast_('Producto eliminado', 'ok');
				const s = new Set(seleccionados);
				s.delete(id);
				seleccionados = s;
				cargarDatos();
			} else {
				const bodyReq = eliminarObjetivo.tipo === 'filtro' ? { modo: 'filtro', filtro: eliminarObjetivo.filtro } : { modo: 'ids', ids: eliminarObjetivo.ids };
				const r = await api('/productos/eliminar-bulk', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(bodyReq) });
				const data = await r.json();
				if (!r.ok) throw new Error(data.error || 'Error al eliminar');
				cerrarModalEliminar();
				const af = data.afectados;
				toast_(`${af} producto${af !== 1 ? 's' : ''} eliminado${af !== 1 ? 's' : ''}`, 'ok');
				deseleccionarTodo();
				cargarDatos();
			}
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al eliminar', 'err');
		} finally {
			eliminarGuardando = false;
		}
	}

	// ── Tab Stock ────────────────────────────────────────────────
	let pagStock = $state(1);
	let totalPagsStock = $state(1);
	let totalItemsStock = $state(0);
	let itemsStock = $state<StockItem[]>([]);
	let cargandoStock = $state(true);
	let stockError = $state('');
	let stockInfoTexto = $state('');
	let depositos = $state<{ id: number; nombre: string; sucursal_nombre: string }[]>([]);
	let mostrarSelectorDeposito = $state(false);

	async function cargarStock() {
		cargandoStock = true;
		stockError = '';
		const f = getFiltros();
		if (filDeposito) {
			try {
				const r = await api(`/sucursales/stock-deposito?deposito_id=${filDeposito}`);
				let items: StockItem[] = await r.json();
				if (!r.ok) throw new Error((items as unknown as { error?: string }).error ?? 'Error al cargar');
				const q = f.q?.toLowerCase() || '';
				if (q) items = items.filter((p) => (p.nombre || '').toLowerCase().includes(q) || (p.codigo || '').toLowerCase().includes(q));
				if (f.proveedor) items = items.filter((p) => (p.proveedor || '').toLowerCase().includes(f.proveedor.toLowerCase()));
				if (f.marca) items = items.filter((p) => (p.marca || '').toLowerCase().includes(f.marca.toLowerCase()));
				if (f.categoria) items = items.filter((p) => (p.categoria || '').toLowerCase().includes(f.categoria.toLowerCase()));
				stockInfoTexto = `${items.length.toLocaleString('es-AR')} productos`;
				itemsStock = items;
				totalPagsStock = 1;
			} catch (e) {
				stockError = e instanceof Error ? e.message : 'Error al cargar';
				toast_(stockError, 'err');
			} finally {
				cargandoStock = false;
			}
			return;
		}

		const params = new URLSearchParams({ page: String(pagStock), per_page: '100' });
		if (f.q) params.set('q', f.q);
		if (f.proveedor) params.set('proveedor', f.proveedor);
		if (f.marca) params.set('marca', f.marca);
		if (f.categoria) params.set('categoria', f.categoria);
		if (filStock) params.set('stock_filter', filStock);
		try {
			const r = await api(`/stock?${params}`);
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al cargar');
			totalItemsStock = d.total;
			totalPagsStock = d.pages || 1;
			pagStock = d.page;
			const offset = (pagStock - 1) * 100;
			const desde = d.total === 0 ? 0 : offset + 1;
			const hasta = Math.min(offset + 100, d.total);
			stockInfoTexto = d.total > 0 ? `${d.total.toLocaleString('es-AR')} productos · Mostrando ${desde}–${hasta}` : '0 resultados';
			itemsStock = d.items;
		} catch (e) {
			stockError = e instanceof Error ? e.message : 'Error al cargar';
			toast_(stockError, 'err');
		} finally {
			cargandoStock = false;
		}
	}

	// ── Edición inline de stock ──────────────────────────────────
	let editandoId = $state<number | null>(null);
	let editandoValor = $state('');
	let editandoOriginal = $state(0);
	let editandoMinimo = $state(0);
	let editandoGuardando = $state(false);
	let editandoError = $state(false);

	function iniciarEditarStock(p: StockItem) {
		if (editandoId === p.id) return;
		editandoId = p.id;
		editandoValor = String(filDeposito ? p.stock_deposito : p.stock_actual);
		editandoOriginal = filDeposito ? (p.stock_deposito ?? 0) : p.stock_actual;
		editandoMinimo = p.stock_minimo;
		editandoError = false;
	}
	function cancelarEditarStock() {
		editandoId = null;
		editandoError = false;
	}
	async function guardarEditarStock() {
		if (editandoId === null) return;
		const id = editandoId;
		const nuevo = parseFloat(editandoValor);
		if (isNaN(nuevo) || nuevo < 0) {
			editandoError = true;
			return;
		}
		if (nuevo === editandoOriginal) {
			cancelarEditarStock();
			return;
		}
		editandoGuardando = true;
		try {
			const r = await api('/stock', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ producto_id: id, tipo: 'ajuste', cantidad: nuevo }) });
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error al ajustar');
			const idx = itemsStock.findIndex((p) => p.id === id);
			if (idx !== -1) {
				if (filDeposito) itemsStock[idx] = { ...itemsStock[idx], stock_deposito: nuevo };
				else itemsStock[idx] = { ...itemsStock[idx], stock_actual: nuevo };
			}
			const nombre = itemsStock[idx]?.nombre ?? '';
			editandoId = null;
			rowSaved = id;
			setTimeout(() => (rowSaved = null), 1500);
			toast_(`${nombre}: ${fmtN(editandoOriginal)} → ${fmtN(nuevo)}`, 'ok');
		} catch (e) {
			toast_(e instanceof Error ? e.message : 'Error al ajustar', 'err');
			editandoGuardando = false;
			return;
		}
		editandoGuardando = false;
	}

	// ── Modal historial de stock ──────────────────────────────────
	let modalHistorialAbierto = $state(false);
	let histNombre = $state('');
	let histStockActual = $state(0);
	let histMinimo = $state(0);
	let histCargando = $state(true);
	let histFilas = $state<HistorialMov[]>([]);
	let histError = $state('');
	const TIPO_LABEL: Record<string, string> = { entrada: 'Entrada', salida: 'Salida', ajuste: 'Ajuste', compra: 'Compra', venta: 'Venta' };
	const TIPO_CLASS: Record<string, string> = { entrada: 'tipo-entrada', salida: 'tipo-salida', ajuste: 'tipo-ajuste', compra: 'tipo-compra', venta: 'tipo-venta' };

	async function abrirHistorial(id: number, nombre: string, stockActual: number, minimo: number) {
		histNombre = nombre;
		histStockActual = stockActual;
		histMinimo = minimo;
		histCargando = true;
		histError = '';
		histFilas = [];
		modalHistorialAbierto = true;
		try {
			const r = await api(`/stock?historial=1&producto_id=${id}&limit=100`);
			const d = await r.json();
			if (!r.ok) throw new Error(d.error ?? 'Error');
			histFilas = d;
		} catch (e) {
			histError = e instanceof Error ? e.message : 'Error';
		} finally {
			histCargando = false;
		}
	}
	function cerrarHistorial() {
		modalHistorialAbierto = false;
	}
	function histCantidad(m: HistorialMov): number {
		const raw = m.cantidad;
		return m.tipo === 'venta' ? -Math.abs(raw) : raw;
	}
	function histFecha(f: string): string {
		return String(f).replace('T', ' ').slice(0, 16);
	}

	// ── Alertas de stock / Pedido sugerido ────────────────────────
	let alertasData = $state<AlertaGrupo[]>([]);
	let bannerVisible = $state(false);
	let bannerTexto = $state('');

	let modalPedidoAbierto = $state(false);
	let pedidoGrupos = $state<{ proveedor: string | null; items: (AlertaItem & { aPedir: number })[] }[]>([]);
	let pedidoCopiado = $state(false);
	let pedidoPdfGenerando = $state(false);

	function abrirPedido() {
		pedidoGrupos = alertasData.map((g) => ({ proveedor: g.proveedor, items: g.items.map((i) => ({ ...i, aPedir: i.faltante })) }));
		modalPedidoAbierto = true;
	}
	function cerrarPedido() {
		modalPedidoAbierto = false;
	}
	async function copiarPedido() {
		const lines = ['Pedido Automatico:'];
		pedidoGrupos.forEach((g) => g.items.forEach((i) => lines.push(`${i.codigo ?? '—'} - ${i.nombre} - ${i.aPedir}`)));
		try {
			await navigator.clipboard.writeText(lines.join('\n'));
			pedidoCopiado = true;
			setTimeout(() => (pedidoCopiado = false), 1800);
		} catch {
			toast_('No se pudo copiar al portapapeles', 'err');
		}
	}
	function exportarExcelPedido() {
		const filas: (string | number)[][] = [['Proveedor', 'Código', 'Nombre', 'A pedir']];
		pedidoGrupos.forEach((g) => g.items.forEach((i) => filas.push([g.proveedor || 'Sin proveedor asignado', i.codigo ?? '—', i.nombre, i.aPedir])));
		const xlsEsc = (v: string | number) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
		const rows = filas.map((r) => `<Row>${r.map((v) => `<Cell><Data ss:Type="${typeof v === 'number' ? 'Number' : 'String'}">${xlsEsc(v)}</Data></Cell>`).join('')}</Row>`).join('');
		const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<?mso-application progid="Excel.Sheet"?>\n<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n <Styles><Style ss:ID="h"><Font ss:Bold="1"/></Style></Styles>\n <Worksheet ss:Name="Pedido Stock"><Table>${rows.replace('<Row>', '<Row ss:StyleID="h">')}</Table></Worksheet>\n</Workbook>`;
		const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
		const url = URL.createObjectURL(blob);
		const a = Object.assign(document.createElement('a'), { href: url, download: `pedido-stock-${new Date().toISOString().slice(0, 10)}.xls` });
		a.click();
		setTimeout(() => URL.revokeObjectURL(url), 5000);
	}
	async function exportarPdfPedido() {
		pedidoPdfGenerando = true;
		try {
			const grupos = pedidoGrupos.map((g) => ({ proveedor: g.proveedor || 'Sin proveedor asignado', items: g.items.map((i) => ({ codigo: i.codigo ?? '—', nombre: i.nombre, stock_actual: i.stock_actual, stock_minimo: i.stock_minimo, a_pedir: i.aPedir })) }));
			const r = await api('/pedido/pdf', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ grupos }) });
			if (!r.ok) {
				toast_('Error al generar PDF', 'err');
				return;
			}
			abrirPdf(await r.blob());
		} catch {
			toast_('Error de conexión', 'err');
		} finally {
			pedidoPdfGenerando = false;
		}
	}

	// ── Taxonomías quick-add (marca/rubro) ────────────────────────
	let taxQuickAbierto = $state<'e-marca' | 'e-categoria' | 'n-marca' | 'n-categoria' | null>(null);
	let taxQuickValor = $state('');

	function taxQuickOpen(campo: typeof taxQuickAbierto) {
		taxQuickAbierto = campo;
		taxQuickValor = '';
	}
	function taxQuickClose() {
		taxQuickAbierto = null;
		taxQuickValor = '';
	}
	async function taxQuickSave() {
		const campo = taxQuickAbierto;
		const nombre = taxQuickValor.trim();
		if (!nombre || !campo) return;
		const tipo = campo.endsWith('marca') ? 'marcas' : 'rubros';
		const r = await api('/taxonomias', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tipo, nombre }) });
		const data = await r.json();
		if (!r.ok) {
			if (data.error && data.error.includes('ya existe')) {
				asignarTax(campo, nombre);
				taxQuickClose();
			} else {
				toast_(data.error || 'Error al crear', 'err');
			}
			return;
		}
		if (tipo === 'marcas') taxMarcas = [...taxMarcas, { id: data.id, nombre }].sort((a, b) => a.nombre.localeCompare(b.nombre));
		else taxRubros = [...taxRubros, { id: data.id, nombre }].sort((a, b) => a.nombre.localeCompare(b.nombre));
		asignarTax(campo, nombre);
		taxQuickClose();
	}
	function asignarTax(campo: NonNullable<typeof taxQuickAbierto>, nombre: string) {
		if (campo === 'e-marca') editForm.marca = nombre;
		else if (campo === 'e-categoria') editForm.categoria = nombre;
		else if (campo === 'n-marca') nuevoForm.marca = nombre;
		else if (campo === 'n-categoria') nuevoForm.categoria = nombre;
	}

	// ── Escape cierra modales (misma precedencia que legacy) ───────
	function onKeydownGlobal(e: KeyboardEvent) {
		if (e.key !== 'Escape') return;
		if (modalEliminarAbierto) { cerrarModalEliminar(); return; }
		if (modalHistorialAbierto) { cerrarHistorial(); return; }
		if (modalPedidoAbierto) { cerrarPedido(); return; }
		if (modalBulkAbierto) { cerrarModalBulk(); return; }
		if (modalNuevoAbierto) { cerrarModalNuevo(); return; }
		if (modalEditarAbierto) { cerrarModalEditar(); return; }
		if (editandoId !== null) cancelarEditarStock();
	}

	// ── Init ─────────────────────────────────────────────────────
	async function cargarFiltros() {
		try {
			const d = await (await api('/filtros')).json();
			proveedores = d.proveedores || [];
			subcategorias = d.subcategorias || [];
		} catch {
			/* filtros vacíos si falla */
		}
	}
	async function cargarTaxonomias() {
		try {
			const [rubros, marcas] = await Promise.all([api('/taxonomias?tipo=rubros').then((r) => r.json()), api('/taxonomias?tipo=marcas').then((r) => r.json())]);
			taxRubros = Array.isArray(rubros) ? rubros : [];
			taxMarcas = Array.isArray(marcas) ? marcas : [];
		} catch {
			/* taxonomías vacías si falla */
		}
	}
	async function cargarReglasPrecio() {
		try {
			const r = await (await api('/reglas-precio?activas=1')).json();
			reglasPrecio = Array.isArray(r) ? r : [];
		} catch {
			/* reglas vacías si falla */
		}
	}
	async function cargarAlertas() {
		try {
			const d = await (await api('/stock/alertas')).json();
			if (d.count > 0) {
				alertasData = d.por_proveedor || [];
				bannerTexto = `${d.count} producto${d.count !== 1 ? 's' : ''} con stock por debajo del mínimo.`;
				bannerVisible = true;
			}
		} catch {
			/* sin banner si falla */
		}
	}
	async function initFiltroDeposito() {
		try {
			const raw = localStorage.getItem('logos_sesion');
			const s = raw ? JSON.parse(raw) : {};
			if (!Array.isArray(s.sucursales) || s.sucursales.length <= 1) return;
			if (!esAdmin && !s.permisos?.costos) return;
			const deps = await (await api('/sucursales/depositos')).json();
			if (!Array.isArray(deps) || !deps.length) return;
			depositos = deps;
			mostrarSelectorDeposito = true;
		} catch {
			/* sin selector de depósito si falla */
		}
	}

	function verStockBajo() {
		cambiarTab('stock');
		filStock = 'stock_bajo';
		pagStock = 1;
		cargarStock();
	}

	$effect(() => {
		cargarAlertas();
		Promise.all([cargarFiltros(), cargarTaxonomias(), cargarReglasPrecio()]).then(cargarDatos);
		initFiltroDeposito();
	});
</script>

<svelte:window onkeydown={onKeydownGlobal} />

<svelte:head>
	<title>Logos — Productos</title>
</svelte:head>

<div class="toolbar">
	<div class="busq-wrap">
		<svg class="busq-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
			><circle cx="11" cy="11" r="8" /><line x1="21" y1="21" x2="16.65" y2="16.65" /></svg
		>
		<input type="text" placeholder="Buscar por nombre o código…" autocomplete="off" spellcheck="false" bind:value={busq} oninput={onBusqInput} />
	</div>
	<select class="fil-select" class:activo={!!filProveedor} bind:value={filProveedor} onchange={onFiltroComunChange}>
		<option value="">Todos los proveedores</option>
		{#each proveedores as p (p)}<option value={p}>{p}</option>{/each}
	</select>
	<select class="fil-select" class:activo={!!filMarca} bind:value={filMarca} onchange={onFiltroComunChange}>
		<option value="">Todas las marcas</option>
		{#each taxMarcas as m (m.id)}<option value={m.nombre}>{m.nombre}</option>{/each}
	</select>
	<select class="fil-select" class:activo={!!filRubro} bind:value={filRubro} onchange={onFiltroComunChange}>
		<option value="">Todos los rubros</option>
		{#each taxRubros as r (r.id)}<option value={r.nombre}>{r.nombre}</option>{/each}
	</select>
	{#if tabActivo === 'datos'}
		<select class="fil-select" bind:value={filOrden} onchange={() => { pagDatos = 1; cargarDatos(); }}>
			<option value="nombre_asc">Nombre A→Z</option>
			<option value="nombre_desc">Nombre Z→A</option>
			<option value="precio_asc">Precio ↑</option>
			<option value="precio_desc">Precio ↓</option>
			<option value="costo_asc">Costo ↑</option>
			<option value="costo_desc">Costo ↓</option>
		</select>
	{:else}
		<select class="fil-select" class:activo={!!filStock} bind:value={filStock} onchange={() => { pagStock = 1; cargarStock(); }}>
			<option value="">Todo el stock</option>
			<option value="con_stock">Con stock</option>
			<option value="sin_stock">Sin stock</option>
			<option value="stock_bajo">Stock bajo</option>
		</select>
		{#if mostrarSelectorDeposito}
			<select class="fil-select" title="Ver stock de un depósito específico" bind:value={filDeposito} onchange={() => { pagStock = 1; cargarStock(); }}>
				<option value="">Todos los depósitos</option>
				{#each depositos as d (d.id)}<option value={d.id}>{d.sucursal_nombre} — {d.nombre}</option>{/each}
			</select>
		{/if}
	{/if}
	<button class="btn-limpiar" class:visible={mostrarLimpiar} onclick={limpiarFiltros}>× Limpiar filtros</button>
	<div class="toolbar-sep"></div>
	{#if esAdmin}
		<div class="btn-group">
			<button class="btn-group-item" id="btn-nuevo-prod" onclick={abrirModalNuevo}>+ Nuevo producto</button>
			<a href="/importar" class="btn-group-item">↑ Importar</a>
		</div>
	{/if}
</div>

<div class="tab-bar">
	<button class="tab-btn" class:activo={tabActivo === 'datos'} onclick={() => cambiarTab('datos')}>Datos del producto</button>
	<button class="tab-btn" class:activo={tabActivo === 'stock'} onclick={() => cambiarTab('stock')}>Stock</button>
</div>

{#if esAdmin && tabActivo === 'datos' && nSeleccionados > 0}
	<div class="bulk-bar visible">
		<span class="bulk-count">
			{#if todosLosFiltrados}Todos los {totalFiltradoDatos.toLocaleString('es-AR')} del filtro seleccionados
			{:else}{nSeleccionados.toLocaleString('es-AR')} producto{nSeleccionados !== 1 ? 's' : ''} seleccionado{nSeleccionados !== 1 ? 's' : ''}{/if}
		</span>
		<div class="bulk-div"></div>
		<button class="btn-bulk" onclick={() => abrirModalBulk('proveedor')}>Cambiar proveedor</button>
		<button class="btn-bulk" onclick={() => abrirModalBulk('marca')}>Cambiar marca</button>
		<button class="btn-bulk" onclick={() => abrirModalBulk('categoria')}>Cambiar rubro</button>
		<button class="btn-bulk" onclick={() => abrirModalBulk('precio_pct')}>Aumento de precio</button>
		<button class="btn-bulk" onclick={() => abrirModalBulk('regla_precio_id')}>Asignar regla de precio</button>
		<div class="bulk-div"></div>
		<button class="btn-bulk-danger" onclick={abrirModalEliminarBulk}>Eliminar</button>
		<div class="bulk-div"></div>
		{#if !todosLosFiltrados}
			<button class="btn-bulk-alt" onclick={seleccionarTodosFiltrados}>Seleccionar todos los {totalFiltradoDatos.toLocaleString('es-AR')} del filtro</button>
		{/if}
		<button class="btn-bulk-alt" onclick={deseleccionarTodo}>Deseleccionar todo</button>
	</div>
{/if}

{#if bannerVisible}
	<div class="banner-stock visible">
		<span>{bannerTexto}</span>
		<button class="banner-stock-link" onclick={verStockBajo}>Ver productos</button>
		<button class="banner-stock-link" onclick={abrirPedido}>Ver pedido sugerido</button>
	</div>
{/if}

<div class="tabla-container">
	<div class="tabla-wrap">
		{#if tabActivo === 'datos'}
			<table>
				<colgroup>
					<col class="cd-chk" /><col class="cd-cod" /><col class="cd-nom" /><col class="cd-prov" />
					<col class="cd-marc" /><col class="cd-rub" /><col class="cd-prec" /><col class="cd-cost" />
				</colgroup>
				<thead>
					<tr>
						<th class="th-chk"><input type="checkbox" title="Seleccionar página" checked={todosMarcados} indeterminate={!todosMarcados && algunosMarcados} onchange={(e) => toggleTodosVisible((e.target as HTMLInputElement).checked)} /></th>
						<th>Código</th><th>Nombre</th><th>Proveedor</th>
						<th>Marca</th><th>Rubro</th><th class="r">Precio</th><th class="r">Costo</th>
					</tr>
				</thead>
				<tbody>
					{#if cargandoDatos}
						<tr><td colspan="8" class="estado-vacio">Cargando…</td></tr>
					{:else if !itemsDatos.length}
						<tr><td colspan="8" class="estado-vacio">No se encontraron productos</td></tr>
					{:else}
						{#each itemsDatos as p (p.id)}
							{@const chk = seleccionados.has(p.id) || todosLosFiltrados}
							<tr class="fila-prod" class:sel={chk} class:row-saved={rowSaved === p.id} ondblclick={() => esAdmin && abrirModalEditar(p)}>
								<td class="td-chk"><input type="checkbox" class="chk-row" checked={chk} onclick={(e) => e.stopPropagation()} onchange={(e) => onCheckChange(p.id, (e.target as HTMLInputElement).checked)} /></td>
								<td class="col-codigo">{p.codigo || ''}{#if p.codigo_secundario}<br /><span style="font-size:10px;opacity:.55">{p.codigo_secundario}</span>{/if}</td>
								<td class="col-nombre">{p.nombre}</td>
								<td class="col-sub">{p.proveedor || ''}</td>
								<td class="col-sub">{p.marca || ''}</td>
								<td class="col-sub">{p.categoria || ''}</td>
								<td class="r col-precio">{fmt(p.precio_venta)}</td>
								<td class="r col-costo">{veCostos && p.costo_actual != null ? fmt(p.costo_actual) : '—'}</td>
							</tr>
						{/each}
					{/if}
				</tbody>
			</table>
		{:else}
			<table>
				<colgroup>
					<col class="cs-cod" /><col class="cs-nom" /><col class="cs-prov" /><col class="cs-marc" />
					<col class="cs-rub" /><col class="cs-stk" /><col class="cs-min" /><col class="cs-prec" />
				</colgroup>
				<thead>
					<tr>
						<th>Código</th><th>Nombre</th><th>Proveedor</th><th>Marca</th>
						<th>Rubro</th><th class="r" title={esAdmin ? 'Click para editar' : undefined}>{filDeposito ? 'Stock depósito' : 'Stock'}{esAdmin ? ' ✎' : ''}</th><th class="r">Mín.</th><th class="r">Precio</th>
					</tr>
				</thead>
				<tbody>
					{#if cargandoStock}
						<tr><td colspan="8" class="estado-vacio">Cargando…</td></tr>
					{:else if stockError}
						<tr><td colspan="8" class="estado-vacio">{stockError}</td></tr>
					{:else if !itemsStock.length}
						<tr><td colspan="8" class="estado-vacio">{filDeposito ? 'Sin productos en este depósito.' : 'Sin resultados. Probá con otros filtros.'}</td></tr>
					{:else}
						{#each itemsStock as p (p.id)}
							{@const stockVal = filDeposito ? (p.stock_deposito ?? 0) : p.stock_actual}
							{@const sc = stockClass(stockVal, p.stock_minimo)}
							<tr class="fila-prod" class:row-saved={rowSaved === p.id} ondblclick={(e) => { if ((e.target as HTMLElement).closest('.col-stock')) return; abrirHistorial(p.id, p.nombre, stockVal, p.stock_minimo); }}>
								<td class="col-codigo">{p.codigo ?? ''}</td>
								<td class="col-nombre">{p.nombre}</td>
								<td class="col-sub">{filDeposito ? '' : (p.proveedor ?? '')}</td>
								<td class="col-sub">{p.marca ?? ''}</td>
								<td class="col-sub">{p.categoria ?? ''}</td>
								{#if editandoId === p.id}
									<td class="col-stock editando">
										<div class="stock-edit-wrap">
											<input
												class="stock-edit-input"
												type="number"
												min="0"
												step="any"
												bind:value={editandoValor}
												style={editandoError ? 'box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-danger)' : undefined}
												onkeydown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); guardarEditarStock(); } if (e.key === 'Escape') { e.stopPropagation(); cancelarEditarStock(); } }}
												onclick={(e) => e.stopPropagation()}
											/>
											<button class="sbtn sbtn-ok" title="Guardar (Enter)" disabled={editandoGuardando} onclick={(e) => { e.stopPropagation(); guardarEditarStock(); }}
												><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12" /></svg></button
											>
											<button class="sbtn sbtn-x" title="Cancelar (Esc)" onclick={(e) => { e.stopPropagation(); cancelarEditarStock(); }}
												><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg></button
											>
										</div>
									</td>
								{:else}
									<td class="col-stock" style={esAdmin ? undefined : 'cursor:default'} title={esAdmin ? (filDeposito ? `Stock en este depósito / Total: ${fmtN(p.stock_total)}` : 'Click para editar') : undefined} onclick={(e) => { e.stopPropagation(); if (esAdmin) iniciarEditarStock(p); }}>
										<span class={sc}>{fmtN(stockVal)}</span>
										{#if filDeposito && p.stock_total !== undefined && p.stock_total !== p.stock_deposito}
											<small style="color:var(--neo-text-3);font-size:10px;margin-left:4px">/{fmtN(p.stock_total)}</small>
										{/if}
									</td>
								{/if}
								<td class="r col-sub">{fmtN(p.stock_minimo)}</td>
								<td class="r col-sub">{fmt(p.precio_venta)}</td>
							</tr>
						{/each}
					{/if}
				</tbody>
			</table>
		{/if}
	</div>
	<div class="pag-bar">
		<span class="info-total" style="min-width:140px">{tabActivo === 'datos' ? `${totalFiltradoDatos.toLocaleString('es-AR')} producto${totalFiltradoDatos !== 1 ? 's' : ''}` : stockInfoTexto}</span>
		{#if !(tabActivo === 'stock' && filDeposito)}
			<div class="pag-nav">
				<button class="btn-pag" disabled={(tabActivo === 'datos' ? pagDatos : pagStock) <= 1} onclick={() => { if (tabActivo === 'datos') { pagDatos--; cargarDatos(); } else { pagStock--; cargarStock(); } }}>← Anterior</button>
				<div class="pag-info">
					Página
					<input
						type="number"
						min="1"
						value={tabActivo === 'datos' ? pagDatos : pagStock}
						onchange={(e) => {
							const n = parseInt((e.target as HTMLInputElement).value);
							if (tabActivo === 'datos') { pagDatos = Math.max(1, Math.min(totalPagsDatos, isNaN(n) ? 1 : n)); cargarDatos(); }
							else { pagStock = Math.max(1, Math.min(totalPagsStock, isNaN(n) ? 1 : n)); cargarStock(); }
						}}
					/>
					de <span>{tabActivo === 'datos' ? totalPagsDatos : totalPagsStock}</span>
				</div>
				<button class="btn-pag" disabled={(tabActivo === 'datos' ? pagDatos >= totalPagsDatos : pagStock >= totalPagsStock)} onclick={() => { if (tabActivo === 'datos') { pagDatos++; cargarDatos(); } else { pagStock++; cargarStock(); } }}>Siguiente →</button>
			</div>
		{:else}
			<div></div>
		{/if}
		{#if tabActivo === 'datos'}
			<div class="pag-right">
				<div class="pag-sep"></div>
				<select bind:value={perPage} onchange={() => { pagDatos = 1; cargarDatos(); }}>
					<option value={100}>100 / pág</option>
					<option value={200}>200 / pág</option>
					<option value={500}>500 / pág</option>
					<option value={1000}>1000 / pág</option>
				</select>
			</div>
		{/if}
	</div>
</div>

<!-- Modal: Editar producto -->
{#if modalEditarAbierto && productoActual}
	<div class="m-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalEditar()}>
		<div class="modal modal-xl" role="dialog" aria-modal="true">
			<div class="m-head">
				<div class="m-head-info">
					<h3>Editar producto</h3>
					<div class="m-head-sub">{productoActual.codigo ? `Cód. ${productoActual.codigo}` : ''}</div>
				</div>
				<button class="m-cerrar" aria-label="Cerrar" onclick={cerrarModalEditar}>×</button>
			</div>
			<div class="m-body">
				<div class="form-grid-3">
					<div class="form-group fg-full">
						<label class="form-label" for="e-nombre">Nombre</label>
						<input id="e-nombre" class="form-input" autocomplete="off" bind:value={editForm.nombre} />
					</div>
					<div class="form-group fg-full">
						<label class="form-label" for="e-descripcion">Descripción</label>
						<textarea id="e-descripcion" class="form-input" rows="2" placeholder="Descripción breve del producto…" bind:value={editForm.descripcion}></textarea>
					</div>
					<div class="form-group">
						<label class="form-label" for="e-codigo">Código</label>
						<input id="e-codigo" class="form-input" autocomplete="off" bind:value={editForm.codigo} />
					</div>
					<div class="form-group">
						<label class="form-label" for="e-codigo-sec">Código secundario</label>
						<input id="e-codigo-sec" class="form-input" autocomplete="off" placeholder="EAN, cód. proveedor…" bind:value={editForm.codigoSec} />
					</div>
					<div class="form-group">
						<label class="form-label" for="e-unidad">Unidad de medida</label>
						<input id="e-unidad" class="form-input" list="dl-unidades" autocomplete="off" placeholder="u, kg, litro…" bind:value={editForm.unidad} />
					</div>
					<div class="form-group">
						<label class="form-label" for="e-precio">Precio venta</label>
						<input id="e-precio" class="form-input" type="number" min="0" step="0.01" disabled={editPrecioDisabled} bind:value={editForm.precio} />
					</div>
					<div class="form-group">
						<label class="form-label" for="e-costo">Costo actual</label>
						<input id="e-costo" class="form-input" type="number" min="0" step="0.01" bind:value={editForm.costo} oninput={() => previewPrecioRegla('e')} />
					</div>
					<div class="form-group">
						<label class="form-label" for="e-iva">IVA</label>
						<select id="e-iva" class="form-input" bind:value={editForm.iva} onchange={() => previewPrecioRegla('e')}>
							<option value="21">21%</option>
							<option value="10.5">10,5%</option>
							<option value="0">Exento (0%)</option>
						</select>
					</div>
					<div class="form-group">
						<label class="form-label" for="e-regla-precio">Regla de precio</label>
						<select id="e-regla-precio" class="form-input form-select" bind:value={editForm.reglaPrecio} onchange={() => previewPrecioRegla('e')}>
							<option value="">— Sin regla —</option>
							{#each reglasPrecio as rg (rg.id)}<option value={String(rg.id)}>{rg.nombre} (+{rg.porcentaje_recargo}%)</option>{/each}
						</select>
						{#if editReglaHint}<p class="bulk-hint" style="margin-top:4px">{editReglaHint}</p>{/if}
					</div>
					<div class="form-group">
						<label class="form-label" for="e-proveedor">Proveedor</label>
						<input id="e-proveedor" class="form-input" autocomplete="off" bind:value={editForm.proveedor} use:combobox={proveedores} />
					</div>
					<div class="form-group">
						<div class="tax-lbl-row">
							<label class="form-label" for="e-marca">Marca</label>
							<button type="button" class="tax-quick-btn" onclick={() => taxQuickOpen('e-marca')}>+ Nuevo</button>
						</div>
						<select id="e-marca" class="form-input form-select" bind:value={editForm.marca}>
							<option value="">— Sin marca —</option>
							{#each taxMarcas as m (m.id)}<option value={m.nombre}>{m.nombre}</option>{/each}
						</select>
						{#if taxQuickAbierto === 'e-marca'}
							<div class="tax-quick-row open">
								<input type="text" class="form-input" placeholder="Nombre…" maxlength="100" bind:value={taxQuickValor} onkeydown={(e) => { if (e.key === 'Enter') taxQuickSave(); if (e.key === 'Escape') taxQuickClose(); }} />
								<button type="button" class="btn btn-primary btn-sm" style="padding:5px 9px" onclick={taxQuickSave}>✓</button>
								<button type="button" class="btn btn-secondary btn-sm" style="padding:5px 9px" onclick={taxQuickClose}>✕</button>
							</div>
						{/if}
					</div>
					<div class="form-group">
						<div class="tax-lbl-row">
							<label class="form-label" for="e-categoria">Rubro</label>
							<button type="button" class="tax-quick-btn" onclick={() => taxQuickOpen('e-categoria')}>+ Nuevo</button>
						</div>
						<select id="e-categoria" class="form-input form-select" bind:value={editForm.categoria}>
							<option value="">— Sin rubro —</option>
							{#each taxRubros as r (r.id)}<option value={r.nombre}>{r.nombre}</option>{/each}
						</select>
						{#if taxQuickAbierto === 'e-categoria'}
							<div class="tax-quick-row open">
								<input type="text" class="form-input" placeholder="Nombre…" maxlength="100" bind:value={taxQuickValor} onkeydown={(e) => { if (e.key === 'Enter') taxQuickSave(); if (e.key === 'Escape') taxQuickClose(); }} />
								<button type="button" class="btn btn-primary btn-sm" style="padding:5px 9px" onclick={taxQuickSave}>✓</button>
								<button type="button" class="btn btn-secondary btn-sm" style="padding:5px 9px" onclick={taxQuickClose}>✕</button>
							</div>
						{/if}
					</div>
					<div class="form-group">
						<label class="form-label" for="e-subcategoria">Subcategoría</label>
						<input id="e-subcategoria" class="form-input" autocomplete="off" bind:value={editForm.subcategoria} use:combobox={subcategorias} />
					</div>
					<div class="form-group">
						<label class="form-label" for="e-stock-min">Stock mínimo</label>
						<input id="e-stock-min" class="form-input" type="number" min="0" step="0.001" bind:value={editForm.stockMin} />
					</div>
					<div class="form-group">
						<label class="form-label" for="e-peso">Peso (kg)</label>
						<input id="e-peso" class="form-input" type="number" min="0" step="0.001" placeholder="0.000" bind:value={editForm.peso} />
					</div>
					<div class="fg-full" style="display:flex;align-items:flex-end;gap:20px;flex-wrap:wrap;padding-top:6px">
						<div style="display:flex;align-items:center;gap:18px;padding-bottom:8px">
							<label class="check-label"><input type="checkbox" bind:checked={editForm.activo} /> Activo</label>
							<label class="check-label"><input type="checkbox" bind:checked={editForm.publicadoWeb} /> Publicado web</label>
							<label class="check-label"><input type="checkbox" bind:checked={editForm.comisionable} /> Comisionable</label>
						</div>
						<div class="form-group" style="flex:0 0 165px">
							<label class="form-label" for="e-comision-tipo">Tipo de comisión</label>
							<select id="e-comision-tipo" class="form-input form-select" disabled={!editForm.comisionable} bind:value={editForm.comisionTipo}>
								<option value="porcentaje">% sobre el subtotal</option>
								<option value="fijo">$ fijo por unidad</option>
							</select>
						</div>
						<div class="form-group" style="flex:0 0 110px">
							<label class="form-label" for="e-comision-valor">{editForm.comisionTipo === 'porcentaje' ? '% de comisión' : '$ por unidad'}</label>
							<input id="e-comision-valor" class="form-input" type="number" min="0" step="0.01" placeholder="0.00" disabled={!editForm.comisionable} bind:value={editForm.comisionValor} />
						</div>
					</div>
				</div>

				<div class="escalas-section">
					<div class="escalas-header">
						<span class="escalas-titulo">Escalas de precio por volumen</span>
						<button type="button" class="btn-escala-add" onclick={agregarFilaEscala}>+ Agregar</button>
					</div>
					{#if escalasFila.length === 0}
						<div class="escalas-vacio">Sin escalas — el precio base aplica para cualquier cantidad.</div>
					{:else}
						{#each escalasFila as e, i (i)}
							<div class="escala-fila">
								<div>
									<div class="escala-label">A partir de</div>
									<input type="number" class="form-input" min="1" step="1" bind:value={e.desde} placeholder="cantidad" />
								</div>
								<div>
									<div class="escala-label">Precio unitario</div>
									<input type="number" class="form-input" min="0" step="0.01" bind:value={e.precio} placeholder="$" />
								</div>
								<button class="btn-del-escala" title="Eliminar" onclick={() => eliminarFilaEscala(i)}>×</button>
							</div>
						{/each}
					{/if}
				</div>
			</div>
			<div class="m-foot">
				<button class="m-foot-link" onclick={() => { const p = productoActual!; cerrarModalEditar(); abrirModalEliminarIndividual(p); }}>Eliminar producto</button>
				<button class="btn-sec" onclick={cerrarModalEditar}>Cancelar</button>
				<button class="btn-pri" disabled={editGuardando} onclick={guardarProducto}>{editGuardando ? 'Guardando…' : 'Guardar cambios'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal: Nuevo producto -->
{#if modalNuevoAbierto}
	<div class="m-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalNuevo()}>
		<div class="modal modal-xl" role="dialog" aria-modal="true">
			<div class="m-head">
				<div class="m-head-info">
					<h3>Nuevo producto</h3>
					<div class="m-head-sub">Completá los datos del producto. Solo el nombre es obligatorio.</div>
				</div>
				<button class="m-cerrar" aria-label="Cerrar" onclick={cerrarModalNuevo}>×</button>
			</div>
			<div class="m-body">
				<div class="form-grid-3">
					<div class="form-section fg-full"><span class="form-section-label">Identificación</span><div class="form-section-line"></div></div>
					<div class="form-group fg-full">
						<label class="form-label" for="n-nombre">Nombre *</label>
						<input id="n-nombre" class="form-input" autocomplete="off" bind:value={nuevoForm.nombre} />
					</div>
					<div class="form-group fg-full">
						<label class="form-label" for="n-descripcion">Descripción</label>
						<textarea id="n-descripcion" class="form-input" rows="2" placeholder="Descripción breve del producto…" bind:value={nuevoForm.descripcion}></textarea>
					</div>
					<div class="form-group">
						<label class="form-label" for="n-codigo">Código</label>
						<input id="n-codigo" class="form-input" autocomplete="off" bind:value={nuevoForm.codigo} />
						{#if nuevoCodigoError}<span style="font-size:11px;color:var(--rojo)">{nuevoCodigoError}</span>{/if}
					</div>
					<div class="form-group">
						<label class="form-label" for="n-codigo-sec">Código secundario</label>
						<input id="n-codigo-sec" class="form-input" autocomplete="off" placeholder="EAN, cód. proveedor…" bind:value={nuevoForm.codigoSec} />
					</div>
					<div class="form-group">
						<label class="form-label" for="n-unidad">Unidad de medida</label>
						<input id="n-unidad" class="form-input" list="dl-unidades" autocomplete="off" placeholder="u, kg, litro…" bind:value={nuevoForm.unidad} />
						<datalist id="dl-unidades">
							<option value="u"></option><option value="kg"></option><option value="g"></option>
							<option value="litro"></option><option value="ml"></option><option value="metro"></option>
							<option value="cm"></option><option value="m²"></option><option value="caja"></option>
							<option value="pack"></option><option value="par"></option><option value="docena"></option>
						</datalist>
					</div>

					<div class="form-section fg-full"><span class="form-section-label">Precios</span><div class="form-section-line"></div></div>
					<div class="form-group">
						<label class="form-label" for="n-costo-neto">Costo s/IVA</label>
						<input id="n-costo-neto" class="form-input" type="number" min="0" step="0.01" placeholder="0.00" bind:value={nuevoForm.costoNeto} oninput={() => recalcNuevoPrecios('neto')} />
					</div>
					<div class="form-group">
						<label class="form-label" for="n-costo-bruto">Costo c/IVA</label>
						<input id="n-costo-bruto" class="form-input" type="number" min="0" step="0.01" placeholder="0.00" bind:value={nuevoForm.costoBruto} oninput={() => recalcNuevoPrecios('bruto')} />
					</div>
					<div class="form-group">
						<label class="form-label" for="n-iva">IVA</label>
						<select id="n-iva" class="form-input" bind:value={nuevoForm.iva} onchange={() => recalcNuevoPrecios('iva')}>
							<option value="21">21%</option>
							<option value="10.5">10,5%</option>
							<option value="0">Exento (0%)</option>
						</select>
					</div>
					<div class="form-group">
						<label class="form-label" for="n-margen">Margen (%)</label>
						<input id="n-margen" class="form-input" type="number" min="-99" step="0.1" placeholder="0.0" disabled={nuevoMargenDisabled} bind:value={nuevoForm.margen} oninput={() => recalcNuevoPrecios('margen')} />
					</div>
					<div class="form-group">
						<label class="form-label" for="n-regla-precio">Regla de precio</label>
						<select id="n-regla-precio" class="form-input form-select" bind:value={nuevoForm.reglaPrecio} onchange={() => previewPrecioRegla('n')}>
							<option value="">— Sin regla —</option>
							{#each reglasPrecio as rg (rg.id)}<option value={String(rg.id)}>{rg.nombre} (+{rg.porcentaje_recargo}%)</option>{/each}
						</select>
					</div>
					<div class="form-group fg-2">
						<label class="form-label" for="n-precio">Precio venta c/IVA</label>
						<input id="n-precio" class="form-input destacado" type="number" min="0" step="0.01" placeholder="0.00" disabled={nuevoPrecioDisabled} bind:value={nuevoForm.precio} oninput={() => recalcNuevoPrecios('precio')} />
						{#if nuevoReglaHint}<p class="bulk-hint" style="margin-top:4px">{nuevoReglaHint}</p>{/if}
					</div>

					<div class="form-section fg-full"><span class="form-section-label">Clasificación</span><div class="form-section-line"></div></div>
					<div class="form-group">
						<label class="form-label" for="n-proveedor">Proveedor</label>
						<input id="n-proveedor" class="form-input" autocomplete="off" bind:value={nuevoForm.proveedor} use:combobox={proveedores} />
					</div>
					<div class="form-group">
						<div class="tax-lbl-row">
							<label class="form-label" for="n-marca">Marca</label>
							<button type="button" class="tax-quick-btn" onclick={() => taxQuickOpen('n-marca')}>+ Nuevo</button>
						</div>
						<select id="n-marca" class="form-input form-select" bind:value={nuevoForm.marca}>
							<option value="">— Sin marca —</option>
							{#each taxMarcas as m (m.id)}<option value={m.nombre}>{m.nombre}</option>{/each}
						</select>
						{#if taxQuickAbierto === 'n-marca'}
							<div class="tax-quick-row open">
								<input type="text" class="form-input" placeholder="Nombre…" maxlength="100" bind:value={taxQuickValor} onkeydown={(e) => { if (e.key === 'Enter') taxQuickSave(); if (e.key === 'Escape') taxQuickClose(); }} />
								<button type="button" class="btn btn-primary btn-sm" style="padding:5px 9px" onclick={taxQuickSave}>✓</button>
								<button type="button" class="btn btn-secondary btn-sm" style="padding:5px 9px" onclick={taxQuickClose}>✕</button>
							</div>
						{/if}
					</div>
					<div class="form-group">
						<div class="tax-lbl-row">
							<label class="form-label" for="n-categoria">Rubro</label>
							<button type="button" class="tax-quick-btn" onclick={() => taxQuickOpen('n-categoria')}>+ Nuevo</button>
						</div>
						<select id="n-categoria" class="form-input form-select" bind:value={nuevoForm.categoria}>
							<option value="">— Sin rubro —</option>
							{#each taxRubros as r (r.id)}<option value={r.nombre}>{r.nombre}</option>{/each}
						</select>
						{#if taxQuickAbierto === 'n-categoria'}
							<div class="tax-quick-row open">
								<input type="text" class="form-input" placeholder="Nombre…" maxlength="100" bind:value={taxQuickValor} onkeydown={(e) => { if (e.key === 'Enter') taxQuickSave(); if (e.key === 'Escape') taxQuickClose(); }} />
								<button type="button" class="btn btn-primary btn-sm" style="padding:5px 9px" onclick={taxQuickSave}>✓</button>
								<button type="button" class="btn btn-secondary btn-sm" style="padding:5px 9px" onclick={taxQuickClose}>✕</button>
							</div>
						{/if}
					</div>
					<div class="form-group">
						<label class="form-label" for="n-subcategoria">Subcategoría</label>
						<input id="n-subcategoria" class="form-input" autocomplete="off" bind:value={nuevoForm.subcategoria} use:combobox={subcategorias} />
					</div>

					<div class="form-section fg-full"><span class="form-section-label">Stock y logística</span><div class="form-section-line"></div></div>
					<div class="form-group">
						<label class="form-label" for="n-stock-inicial">Stock inicial</label>
						<input id="n-stock-inicial" class="form-input" type="number" min="0" step="0.001" placeholder="0" bind:value={nuevoForm.stockInicial} />
					</div>
					<div class="form-group">
						<label class="form-label" for="n-stock-min">Stock mínimo</label>
						<input id="n-stock-min" class="form-input" type="number" min="0" step="0.001" placeholder="0" bind:value={nuevoForm.stockMin} />
					</div>
					<div class="form-group">
						<label class="form-label" for="n-peso">Peso (kg)</label>
						<input id="n-peso" class="form-input" type="number" min="0" step="0.001" placeholder="0.000" bind:value={nuevoForm.peso} />
					</div>

					<div class="fg-full" style="display:flex;align-items:flex-end;gap:20px;flex-wrap:wrap;padding-top:6px">
						<div style="display:flex;align-items:center;gap:18px;padding-bottom:8px">
							<label class="check-label"><input type="checkbox" bind:checked={nuevoForm.activo} /> Activo</label>
							<label class="check-label"><input type="checkbox" bind:checked={nuevoForm.publicadoWeb} /> Publicado web</label>
							<label class="check-label"><input type="checkbox" bind:checked={nuevoForm.comisionable} /> Comisionable</label>
						</div>
						<div class="form-group" style="flex:0 0 165px">
							<label class="form-label" for="n-comision-tipo">Tipo de comisión</label>
							<select id="n-comision-tipo" class="form-input form-select" disabled={!nuevoForm.comisionable} bind:value={nuevoForm.comisionTipo}>
								<option value="porcentaje">% sobre el subtotal</option>
								<option value="fijo">$ fijo por unidad</option>
							</select>
						</div>
						<div class="form-group" style="flex:0 0 110px">
							<label class="form-label" for="n-comision-valor">{nuevoForm.comisionTipo === 'porcentaje' ? '% de comisión' : '$ por unidad'}</label>
							<input id="n-comision-valor" class="form-input" type="number" min="0" step="0.01" placeholder="0.00" disabled={!nuevoForm.comisionable} bind:value={nuevoForm.comisionValor} />
						</div>
					</div>
				</div>
			</div>
			<div class="m-foot">
				<button class="btn-sec" onclick={cerrarModalNuevo}>Cancelar</button>
				<button class="btn-pri" disabled={nuevoGuardando} onclick={guardarNuevoProducto}>{nuevoGuardando ? 'Creando…' : 'Crear producto'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal: Bulk action -->
{#if modalBulkAbierto && bulkCampo}
	<div class="m-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalBulk()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="m-head">
				<div class="m-head-info"><h3>Cambiar {BULK_LABELS[bulkCampo]}</h3></div>
				<button class="m-cerrar" aria-label="Cerrar" onclick={cerrarModalBulk}>×</button>
			</div>
			<div class="m-body">
				<p class="bulk-desc">
					{#if todosLosFiltrados}Se aplicará a los {nSeleccionados.toLocaleString('es-AR')} productos del filtro actual.
					{:else}Se aplicará a {nSeleccionados} producto{nSeleccionados !== 1 ? 's' : ''} seleccionado{nSeleccionados !== 1 ? 's' : ''}.{/if}
				</p>
				{#if nSeleccionados > 500}<div class="bulk-warn">Vas a modificar {nSeleccionados.toLocaleString('es-AR')} productos. Revisá bien antes de confirmar.</div>{/if}
				<div class="form-group">
					{#if bulkCampo === 'precio_pct'}
						<label class="form-label" for="bulk-val">Porcentaje de aumento</label>
						<div class="pct-wrap"><input id="bulk-val" type="number" class="form-input" placeholder="ej: 10" step="0.1" min="-99" max="10000" bind:value={bulkVal} /><span class="pct-sym">%</span></div>
						<p class="bulk-hint">Positivo = sube precio · Negativo = baja precio</p>
					{:else if bulkCampo === 'regla_precio_id'}
						<label class="form-label" for="bulk-val">Regla de precio</label>
						<select id="bulk-val" class="form-input form-select" bind:value={bulkVal}>
							<option value="">— Quitar regla —</option>
							{#each reglasPrecio as r (r.id)}<option value={String(r.id)}>{r.nombre} (+{r.porcentaje_recargo}%)</option>{/each}
						</select>
						<p class="bulk-hint">El precio de venta se recalcula al instante para los productos afectados.</p>
					{:else}
						<label class="form-label" for="bulk-val">Nuevo valor</label>
						{#if bulkCampo === 'marca'}
							<input id="bulk-val" type="text" class="form-input" autocomplete="off" placeholder="Elegí o escribí un valor…" bind:value={bulkVal} use:combobox={marcasNombres} />
						{:else if bulkCampo === 'categoria'}
							<input id="bulk-val" type="text" class="form-input" autocomplete="off" placeholder="Elegí o escribí un valor…" bind:value={bulkVal} use:combobox={rubrosNombres} />
						{:else}
							<input id="bulk-val" type="text" class="form-input" autocomplete="off" placeholder="Elegí o escribí un valor…" bind:value={bulkVal} use:combobox={proveedores} />
						{/if}
					{/if}
				</div>
			</div>
			<div class="m-foot">
				<button class="btn-sec" onclick={cerrarModalBulk}>Cancelar</button>
				<button class="btn-pri" disabled={bulkGuardando} onclick={aplicarBulk}>{bulkGuardando ? 'Aplicando…' : bulkTipoExistente ? 'Aplicar' : 'Crear y aplicar'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal: Confirmar eliminación de producto(s) -->
{#if modalEliminarAbierto && eliminarObjetivo}
	<div class="m-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModalEliminar()}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="m-head">
				<div class="m-head-info">
					<h3>{eliminarObjetivo.tipo === 'individual' ? 'Eliminar producto' : `Eliminar ${nSeleccionados.toLocaleString('es-AR')} producto${nSeleccionados !== 1 ? 's' : ''}`}</h3>
				</div>
				<button class="m-cerrar" aria-label="Cerrar" onclick={cerrarModalEliminar}>×</button>
			</div>
			<div class="m-body">
				<p class="bulk-desc">
					{#if eliminarObjetivo.tipo === 'individual'}
						¿Eliminar <strong>{eliminarObjetivo.nombre}</strong>? Deja de aparecer en listados, búsquedas y el POS. Las ventas e historiales pasados no se modifican.
					{:else if eliminarObjetivo.tipo === 'filtro'}
						Se eliminarán los <strong>{nSeleccionados.toLocaleString('es-AR')}</strong> productos del filtro actual.
					{:else}
						Se eliminará{nSeleccionados !== 1 ? 'n' : ''} <strong>{nSeleccionados}</strong> producto{nSeleccionados !== 1 ? 's' : ''} seleccionado{nSeleccionados !== 1 ? 's' : ''}.
					{/if}
				</p>
				{#if eliminarObjetivo.tipo !== 'individual' && nSeleccionados > 200}
					<div class="bulk-warn">Vas a eliminar {nSeleccionados.toLocaleString('es-AR')} productos. Revisá bien antes de confirmar.</div>
				{/if}
			</div>
			<div class="m-foot">
				<button class="btn-sec" onclick={cerrarModalEliminar}>Cancelar</button>
				<button class="btn-danger" disabled={eliminarGuardando} onclick={confirmarEliminar}>{eliminarGuardando ? 'Eliminando…' : 'Eliminar'}</button>
			</div>
		</div>
	</div>
{/if}

<!-- Modal: Historial de stock -->
{#if modalHistorialAbierto}
	<div class="h-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarHistorial()}>
		<div class="modal-hist" role="dialog" aria-modal="true">
			<div class="hist-header">
				<div class="hist-header-info">
					<div class="hist-nombre">{histNombre}</div>
					<div class="hist-meta">Stock actual: <span class={stockClass(histStockActual, histMinimo)}>{fmtN(histStockActual)}</span> · Mínimo: {fmtN(histMinimo)}</div>
				</div>
				<button class="hist-cerrar" aria-label="Cerrar" onclick={cerrarHistorial}>×</button>
			</div>
			<div class="hist-body">
				{#if histCargando}
					<div class="estado-vacio">Cargando…</div>
				{:else if histError}
					<div class="estado-vacio">{histError}</div>
				{:else if !histFilas.length}
					<div class="estado-vacio">Sin movimientos registrados</div>
				{:else}
					<table class="hist-tabla">
						<thead><tr><th>Fecha</th><th>Tipo</th><th class="r">Cantidad</th></tr></thead>
						<tbody>
							{#each histFilas as m, i (i)}
								{@const cant = histCantidad(m)}
								<tr>
									<td>{histFecha(m.fecha)}</td>
									<td><span class={TIPO_CLASS[m.tipo] ?? ''}>{TIPO_LABEL[m.tipo] ?? m.tipo}</span></td>
									<td class="r {cant >= 0 ? 'cant-pos' : 'cant-neg'}">{cant >= 0 ? '+' : ''}{fmtN(cant)}</td>
								</tr>
							{/each}
						</tbody>
					</table>
				{/if}
			</div>
		</div>
	</div>
{/if}

<!-- Modal: Pedido sugerido -->
{#if modalPedidoAbierto}
	<div class="m-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarPedido()}>
		<div class="modal" role="dialog" aria-modal="true" style="width:700px;max-width:96vw">
			<div class="m-head">
				<div class="m-head-info">
					<h3>Pedido sugerido de stock</h3>
					<div class="m-head-sub">Editá las cantidades antes de exportar</div>
				</div>
				<button class="m-cerrar" aria-label="Cerrar" onclick={cerrarPedido}>×</button>
			</div>
			<div class="m-body" style="max-height:55vh;overflow-y:auto">
				{#if !pedidoGrupos.length}
					<p style="color:var(--neo-text-3);font-size:13px;padding:16px 0">Sin productos con stock bajo.</p>
				{:else}
					{#each pedidoGrupos as g, gi (gi)}
						<div class="pedido-grupo">
							<div class="pedido-prov-title">{g.proveedor ? g.proveedor : ''}{#if !g.proveedor}<span class="pedido-sin-prov">Sin proveedor asignado</span>{/if}</div>
							<table class="pedido-tbl">
								<thead>
									<tr><th style="width:80px">Código</th><th>Nombre</th><th class="r" style="width:64px">Stock</th><th class="r" style="width:54px">Mín.</th><th class="r" style="width:80px">A pedir</th></tr>
								</thead>
								<tbody>
									{#each g.items as item, ii (ii)}
										<tr>
											<td class="cod">{item.codigo ?? '—'}</td>
											<td>{item.nombre}</td>
											<td class="r stk">{fmtN(item.stock_actual)}</td>
											<td class="r">{fmtN(item.stock_minimo)}</td>
											<td class="r"><input class="pedido-inp" type="number" min="0" step="any" bind:value={item.aPedir} /></td>
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
					{/each}
				{/if}
			</div>
			<div class="m-foot" style="justify-content:space-between">
				<div style="display:flex;gap:6px">
					<button class="btn-sec" onclick={copiarPedido}>{pedidoCopiado ? '¡Copiado!' : 'Copiar texto'}</button>
					<button class="btn-sec" onclick={exportarExcelPedido}>Excel / CSV</button>
					<button class="btn-sec" disabled={pedidoPdfGenerando} onclick={exportarPdfPedido}>{pedidoPdfGenerando ? 'Generando…' : 'PDF'}</button>
				</div>
				<button class="btn-sec" onclick={cerrarPedido}>Cerrar</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.toolbar {
	  background: var(--neo-bg);
	  box-shadow: 0 3px 8px var(--neo-sd), 0 -1px 4px var(--neo-sl);
	  padding: 8px 14px; display: flex; align-items: center; gap: 8px;
	  flex-shrink: 0; flex-wrap: wrap; position: relative; z-index: 10;
	}
	.busq-wrap { position: relative; flex: 0 0 260px; }
	.busq-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--neo-text-3); pointer-events: none; }
	.busq-wrap input {
	  width: 100%; padding: 8px 10px 8px 34px; border: none; border-radius: var(--neo-r-sm);
	  font-size: 13px; font-family: inherit; outline: none;
	  background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-i1);
	  transition: box-shadow var(--neo-t-fast); box-sizing: border-box;
	}
	.busq-wrap input:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.fil-select {
	  padding: 7px 10px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 12px; background: var(--neo-bg); color: var(--neo-text);
	  font-family: inherit; cursor: pointer; outline: none; max-width: 160px;
	  box-shadow: var(--neo-i1); transition: color var(--neo-t-fast);
	}
	.fil-select.activo { color: var(--neo-accent); }
	.btn-limpiar {
	  padding: 7px 12px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); color: var(--neo-text-3); box-shadow: var(--neo-e1);
	  font-size: 12px; font-weight: 600; cursor: pointer; display: none; white-space: nowrap;
	  transition: box-shadow var(--neo-t-fast), color var(--neo-t-fast);
	}
	.btn-limpiar.visible { display: block; }
	.btn-limpiar:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }
	.toolbar-sep { flex: 1; }
	.info-total { font-size: 12px; color: var(--neo-text-3); white-space: nowrap; }

	.tab-bar { background: var(--neo-bg); border-bottom: 1px solid var(--borde-fuerte); display: flex; padding: 0 14px; flex-shrink: 0; }
	.tab-btn {
	  padding: 10px 20px; font-size: 12px; font-weight: 600; border: none; background: none;
	  cursor: pointer; color: var(--neo-text-3); border-bottom: 2px solid transparent;
	  margin-bottom: -1px; transition: color var(--neo-t-fast), border-color var(--neo-t-fast); white-space: nowrap;
	}
	.tab-btn:hover { color: var(--neo-text); }
	.tab-btn.activo { color: var(--neo-accent); border-bottom-color: var(--neo-accent); }

	.bulk-bar {
	  display: none; background: var(--neo-bg);
	  box-shadow: 0 3px 8px var(--neo-sd);
	  border-bottom: 2px solid var(--neo-accent);
	  padding: 7px 14px; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap;
	}
	.bulk-bar.visible { display: flex; }
	.bulk-count { font-size: 12px; font-weight: 700; color: var(--neo-accent); white-space: nowrap; margin-right: 4px; }
	.btn-bulk {
	  padding: 5px 11px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-accent); color: white; box-shadow: 3px 3px 6px var(--neo-accent-glow);
	  font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;
	}
	.btn-bulk:hover { background: var(--neo-accent-h); }
	.btn-bulk-alt {
	  padding: 5px 11px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-bg); color: var(--neo-text); box-shadow: var(--neo-e1);
	  font-size: 12px; font-weight: 500; cursor: pointer; white-space: nowrap;
	  transition: box-shadow var(--neo-t-fast);
	}
	.btn-bulk-alt:hover { box-shadow: var(--neo-e2); }
	.bulk-div { width: 1px; height: 20px; background: var(--color-bg-alt); flex-shrink: 0; margin: 0 2px; }

	.tabla-container {
	  flex: 1; min-height: 0; display: flex; flex-direction: column;
	  background: var(--neo-bg-deep); margin: 10px 12px 12px;
	  border-radius: var(--neo-r-lg); box-shadow: var(--neo-i1); overflow: hidden; border: none !important;
	}
	.tabla-wrap { flex: 1; overflow-y: auto; min-height: 0; }

	table { width: 100%; border-collapse: collapse; table-layout: fixed; }
	thead th {
	  position: sticky; top: 0; background: var(--neo-bg-deep); z-index: 10;
	  padding: 9px 12px; text-align: left; font-size: 10px; font-weight: 700;
	  text-transform: uppercase; letter-spacing: .5px; color: var(--color-ink);
	  border-bottom: 1px solid var(--borde-fuerte); white-space: nowrap; user-select: none;
	}
	thead th.r { text-align: right; }
	thead th.th-chk { padding: 9px 0 9px 12px; }
	tbody td {
	  padding: 8px 12px; font-size: 13px; border-bottom: 1px solid var(--borde);
	  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; color: var(--neo-text);
	}
	tbody td.r { text-align: right; font-variant-numeric: tabular-nums; }
	tbody tr:last-child td { border-bottom: none; }

	.cd-chk  { width: 38px; }
	.cd-cod  { width: 120px; }
	.cd-nom  { width: 240px; }
	.cd-prov { width: 140px; }
	.cd-marc { width: 110px; }
	.cd-rub  { width: 110px; }
	.cd-prec { width: 110px; }
	.cd-cost { width: 100px; }
	.cs-cod  { width: 120px; }
	.cs-nom  { width: 240px; }
	.cs-prov { width: 150px; }
	.cs-marc { width: 120px; }
	.cs-rub  { width: 120px; }
	.cs-stk  { width: 90px; }
	.cs-min  { width: 80px; }
	.cs-prec { width: 100px; }

	.td-chk { padding: 8px 0 8px 12px !important; }
	.chk-row { cursor: pointer; accent-color: var(--neo-accent); width: 15px; height: 15px; }
	thead .th-chk input { cursor: pointer; accent-color: var(--neo-accent); width: 15px; height: 15px; }
	.fila-prod { cursor: default; }
	.fila-prod:hover { background: var(--color-bg-alt); }
	.fila-prod.sel  { background: var(--primary-soft-2); }
	.col-codigo { font-family: monospace; font-size: 12px; color: var(--neo-text-3); }
	.col-nombre { font-weight: 500; color: var(--neo-text); }
	.col-sub    { font-size: 12px; color: var(--neo-text-3); }
	.col-precio { font-weight: 600; font-variant-numeric: tabular-nums; }
	.col-costo  { color: var(--neo-text-3); font-variant-numeric: tabular-nums; }

	.col-stock { text-align: right; cursor: pointer; user-select: none; }
	.col-stock:not(.editando):hover { background: rgba(243,156,18,.06); }
	.col-stock.editando { background: rgba(243,156,18,.08); cursor: default; padding-left: 2px; padding-right: 2px; overflow: visible; }
	.stock-ok   { color: var(--neo-success); font-weight: 700; }
	.stock-bajo { color: var(--neo-warning); font-weight: 700; }
	.stock-cero { color: var(--neo-danger);  font-weight: 700; }
	.stock-neg  { color: var(--neo-danger);  font-weight: 700; }
	.stock-edit-wrap { display: flex; align-items: center; gap: 2px; justify-content: flex-end; }
	.stock-edit-input {
	  width: 50px; padding: 3px 5px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 13px; font-family: inherit; outline: none; text-align: right;
	  background: var(--neo-bg); box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-warning);
	  color: var(--neo-text); font-variant-numeric: tabular-nums; -moz-appearance: textfield;
	}
	.sbtn { width: 20px; height: 20px; border: none; border-radius: var(--neo-r-xs); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: box-shadow var(--neo-t-fast); }
	.sbtn-ok { background: var(--neo-success); color: white; box-shadow: 2px 2px 5px var(--neo-success-glow); }
	.sbtn-ok:hover:not(:disabled) { background: var(--neo-success-h); }
	.sbtn-ok:disabled { background: var(--neo-text-3); cursor: not-allowed; }
	.sbtn-x { background: var(--neo-bg); color: var(--neo-text-2); box-shadow: var(--neo-e1); }
	.sbtn-x:hover { box-shadow: var(--neo-e2); color: var(--neo-text); }

	@keyframes rowFlash { 0%,65%{ background: rgba(39,174,96,.15); } 100%{ background: transparent; } }
	:global(tr.row-saved) { animation: rowFlash 1.5s ease-out forwards; }
	.estado-vacio { padding: 50px 20px; text-align: center; color: var(--neo-text-3); font-size: 14px; }

	.pag-bar {
	  padding: 8px 16px; border-top: 1px solid var(--borde-fuerte);
	  background: var(--neo-bg-deep); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
	}
	.pag-nav { display: flex; align-items: center; gap: 12px; }
	.pag-right { display: flex; align-items: center; gap: 8px; }
	.btn-group { display: flex; border-radius: var(--neo-r-sm); overflow: hidden; flex-shrink: 0; border: 1px solid var(--borde-fuerte); }
	.btn-group-item {
	  padding: 7px 14px; background: #fff; color: var(--neo-text); border: none;
	  font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;
	  text-decoration: none; display: flex; align-items: center; transition: background var(--neo-t-fast), color var(--neo-t-fast);
	}
	.btn-group-item:hover { color: var(--color-primary); }
	.btn-group-item + .btn-group-item { border-left: 1px solid var(--borde-fuerte); }
	#btn-nuevo-prod { background: var(--color-accent); color: var(--color-primary-dark); }
	#btn-nuevo-prod:hover { background: var(--color-accent-h); color: var(--color-primary-dark); }
	.btn-pag {
	  padding: 5px 14px; border: none; border-radius: var(--neo-r-xs); font-family: inherit;
	  font-size: 12px; font-weight: 600; cursor: pointer; color: var(--neo-text);
	  background: var(--neo-bg); box-shadow: var(--neo-e1); white-space: nowrap;
	  transition: box-shadow var(--neo-t-fast);
	}
	.btn-pag:hover:not(:disabled) { box-shadow: var(--neo-e2); }
	.btn-pag:disabled { color: var(--neo-text-3); cursor: not-allowed; }
	.pag-info { font-size: 12px; color: var(--neo-text-3); display: flex; align-items: center; gap: 6px; }
	.pag-info input {
	  width: 54px; padding: 4px 6px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 12px; text-align: center; font-family: inherit; outline: none;
	  background: var(--neo-bg); box-shadow: var(--neo-i1); color: var(--neo-text);
	  -moz-appearance: textfield;
	}
	.pag-info input:focus { box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent); }
	.pag-sep { width: 1px; height: 20px; background: var(--color-bg-alt); }
	.pag-right select {
	  padding: 5px 8px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 12px; background: var(--neo-bg); color: var(--neo-text);
	  font-family: inherit; cursor: pointer; outline: none; box-shadow: var(--neo-i1);
	}

	:global(.m-overlay) {
	  position: fixed; inset: 0; z-index: 1000; display: none; align-items: center; justify-content: center;
	  background: rgba(49,52,75,.48); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
	}
	:global(.m-overlay.abierto) { display: flex; }
	:global(.m-overlay .modal),
	:global(.h-overlay .modal-hist) {
	  background: #fff; border: 1px solid var(--borde-fuerte); border-radius: 0;
	  box-shadow: 0 4px 24px rgba(0,0,0,.13);
	  max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;
	}
	.modal-lg { width: 660px; }
	.modal-xl { width: 920px; max-width: 95vw; }
	.modal-sm { width: 420px; }
	:global(.m-overlay .m-head),
	:global(.h-overlay .hist-header) {
	  background: var(--color-bg-alt); border-bottom-color: var(--borde-fuerte); border-radius: 0;
	  padding: 16px 22px; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-shrink: 0;
	}
	.m-head-info { flex: 1; min-width: 0; }
	.m-head-info h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--neo-text-3); }
	.m-head-sub { font-size: 11px; color: var(--neo-text-3); margin-top: 2px; }
	.m-cerrar {
	  background: none; border: none; cursor: pointer;
	  color: var(--neo-text-3); font-size: 18px; line-height: 1; padding: 2px 6px;
	  border-radius: var(--neo-r-xs); flex-shrink: 0;
	}
	.m-cerrar:hover { background: var(--color-bg-alt); color: var(--neo-text); }
	.m-body { padding: 20px 22px; overflow-y: auto; flex: 1; }
	:global(.m-overlay .m-foot) {
	  border-top-color: var(--borde-fuerte);
	  padding: 14px 22px; border-top: 1px solid var(--borde-fuerte);
	  display: flex; justify-content: flex-end; gap: 8px; flex-shrink: 0;
	}
	.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px 16px; }
	.fg-full { grid-column: 1 / -1; }
	.fg-2 { grid-column: span 2; }
	textarea.form-input { resize: vertical; min-height: 64px; }
	.tax-lbl-row { display:flex; align-items:center; justify-content:space-between; }
	.tax-lbl-row .form-label { margin:0; }
	.tax-quick-btn { background:none; border:none; color:var(--neo-accent); font-size:11px; font-weight:700; cursor:pointer; padding:0 2px; letter-spacing:.3px; }
	.tax-quick-btn:hover { text-decoration:underline; }
	.tax-quick-row { display:none; gap:5px; align-items:center; margin-top:4px; }
	.tax-quick-row.open { display:flex; }
	.tax-quick-row input { flex:1; min-width:0; }
	.form-group { display: flex; flex-direction: column; gap: 4px; }
	.form-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--neo-text-3); }
	:global(.m-overlay .form-input) {
	  box-shadow: none; border: 1px solid var(--borde-fuerte); border-radius: 0; background: #fff;
	  padding: 8px 10px; font-size: 13px; font-family: inherit; outline: none; color: var(--neo-text);
	  transition: box-shadow var(--neo-t-fast), border-color var(--neo-t-fast);
	}
	:global(.m-overlay .form-input:focus) { box-shadow: none; border-color: var(--color-primary); }
	:global(.m-overlay .form-input:disabled) { opacity: .45; cursor: not-allowed; }
	.check-label { display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; color: var(--neo-text); }
	.check-label input { width: 16px; height: 16px; accent-color: var(--neo-accent); cursor: pointer; }

	.form-section { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
	.form-section-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #888; white-space: nowrap; flex-shrink: 0; }
	.form-section-line { flex: 1; height: 1px; background: var(--borde-fuerte); }
	.form-input.destacado { font-size: 15px; font-weight: 700; }

	:global(.combo-dropdown) {
	  position: fixed; z-index: 3000;
	  background: #fff; border: 1px solid var(--borde-fuerte);
	  box-shadow: 0 4px 16px rgba(0,0,0,.13);
	  max-height: 200px; overflow-y: auto; min-width: 120px;
	  display: none;
	}
	:global(.combo-dropdown.abierto) { display: block; }
	:global(.combo-item) {
	  padding: 8px 11px; font-size: 13px; font-family: inherit;
	  cursor: pointer; color: #111;
	  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
	}
	:global(.combo-item:hover), :global(.combo-item.activo) { background: var(--primary-soft-2); color: var(--neo-accent); }
	:global(.combo-vacio) { padding: 8px 11px; font-size: 12px; color: #aaa; font-family: inherit; }

	.btn-pri {
	  padding: 9px 18px; background: var(--neo-accent); color: white; border: none; border-radius: 0;
	  font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit;
	  transition: box-shadow var(--neo-t-fast);
	}
	.btn-pri:disabled { opacity: .5; cursor: not-allowed; }
	.btn-sec {
	  padding: 9px 18px; border: 1.5px solid #888; border-radius: 0;
	  background: #fff; color: #111;
	  font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit;
	}
	.btn-danger {
	  padding: 9px 18px; background: #fff; color: #B91C1C; border: 1.5px solid #B91C1C; border-radius: 0;
	  font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit;
	}
	.btn-danger:hover:not(:disabled) { background: #fff; }
	.btn-danger:disabled { opacity: .5; cursor: not-allowed; }
	.m-foot-link { background: none; border: none; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 600; color: var(--neo-danger); padding: 9px 4px; margin-right: auto; }
	.m-foot-link:hover { text-decoration: underline; }
	.btn-bulk-danger {
	  padding: 5px 11px; border: none; border-radius: var(--neo-r-xs);
	  background: var(--neo-danger); color: white; box-shadow: 3px 3px 6px var(--neo-danger-glow);
	  font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap;
	}
	.btn-bulk-danger:hover { background: var(--neo-danger-h); }
	.bulk-desc { font-size: 12px; color: var(--neo-text-2); margin-bottom: 14px; line-height: 1.5; }
	.bulk-warn { font-size: 12px; color: var(--neo-warning); background: rgba(243,156,18,.1); border-radius: var(--neo-r-sm); box-shadow: var(--neo-e1); padding: 8px 10px; margin-bottom: 14px; }
	.pct-wrap { display: flex; align-items: center; gap: 8px; }
	.pct-sym { font-size: 16px; font-weight: 700; color: var(--neo-text-3); }
	.bulk-hint { font-size: 12px; color: var(--neo-text-3); margin-top: 6px; }

	:global(.h-overlay) {
	  position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 1000;
	  background: rgba(49,52,75,.48); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
	}
	:global(.h-overlay.abierto) { display: flex; }
	.modal-hist { width: 500px; max-height: 85vh; }
	.hist-header-info { flex: 1; min-width: 0; }
	.hist-nombre { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--neo-text-3); }
	.hist-meta { font-size: 11px; color: var(--neo-text-3); margin-top: 3px; }
	.hist-cerrar {
	  background: none; border: none; cursor: pointer;
	  color: var(--neo-text-3); font-size: 18px; line-height: 1; padding: 2px 6px;
	  border-radius: var(--neo-r-xs); flex-shrink: 0;
	}
	.hist-cerrar:hover { background: var(--color-bg-alt); color: var(--neo-text); }
	.hist-body { overflow-y: auto; flex: 1; }
	.hist-tabla { width: 100%; border-collapse: collapse; }
	.hist-tabla thead th {
	  position: sticky; top: 0; background: var(--neo-bg); padding: 8px 14px;
	  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
	  color: var(--color-ink); border-bottom: 1px solid var(--borde-fuerte); text-align: left;
	}
	.hist-tabla thead th.r { text-align: right; }
	.hist-tabla tbody td { padding: 9px 14px; font-size: 13px; border-bottom: 1px solid var(--borde); color: var(--neo-text); }
	.hist-tabla tbody td.r { text-align: right; font-variant-numeric: tabular-nums; }
	.hist-tabla tbody tr:last-child td { border-bottom: none; }
	.tipo-entrada { color: var(--neo-success); font-weight: 600; }
	.tipo-salida  { color: var(--neo-danger);  font-weight: 600; }
	.tipo-ajuste  { color: var(--neo-accent);  font-weight: 600; }
	.tipo-compra  { color: #7c3aed;            font-weight: 600; }
	.tipo-venta   { color: var(--neo-text-3);  font-weight: 500; }
	.cant-pos { color: var(--neo-success); font-weight: 700; font-variant-numeric: tabular-nums; }
	.cant-neg { color: var(--neo-danger);  font-weight: 700; font-variant-numeric: tabular-nums; }

	.banner-stock {
	  display: flex; padding: 9px 16px;
	  background: rgba(243,156,18,.1); border-bottom: 2px solid var(--neo-warning);
	  font-size: 13px; color: var(--neo-warning); align-items: center; gap: 10px; flex-shrink: 0;
	}
	.banner-stock-link { font-weight: 700; color: var(--neo-warning); text-decoration: underline; cursor: pointer; background: none; border: none; font-family: inherit; font-size: inherit; padding: 0; }

	.pedido-grupo        { margin-bottom: 20px; }
	.pedido-grupo:last-child { margin-bottom: 0; }
	.pedido-prov-title   { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
	                        color: var(--neo-accent); padding: 6px 0 5px; border-bottom: 2px solid var(--neo-accent);
	                        margin-bottom: 0; }
	table.pedido-tbl     { width: 100%; border-collapse: collapse; }
	table.pedido-tbl th  { padding: 6px 8px; font-size: 10px; font-weight: 700; text-transform: uppercase;
	                        letter-spacing: .04em; color: var(--neo-text-3); background: var(--neo-bg-deep);
	                        border-bottom: 1px solid var(--borde-fuerte); text-align: left; white-space: nowrap; }
	table.pedido-tbl th.r { text-align: right; }
	table.pedido-tbl td  { padding: 6px 8px; font-size: 12px; border-bottom: 1px solid var(--borde);
	                        color: var(--neo-text); vertical-align: middle; }
	table.pedido-tbl tr:last-child td { border-bottom: none; }
	table.pedido-tbl td.cod  { font-family: monospace; font-size: 11px; color: var(--neo-text-3); }
	table.pedido-tbl td.r    { text-align: right; font-variant-numeric: tabular-nums; }
	table.pedido-tbl td.stk  { color: var(--neo-danger); font-weight: 700; }
	.pedido-inp {
	  width: 64px; padding: 3px 6px; border: none; border-radius: var(--neo-r-xs);
	  font-size: 13px; font-family: inherit; text-align: right; font-weight: 700;
	  background: var(--neo-bg); color: var(--neo-success); box-shadow: var(--neo-i1);
	  outline: none; font-variant-numeric: tabular-nums; -moz-appearance: textfield;
	}
	.pedido-sin-prov { color: var(--neo-text-3); font-style: italic; font-size: 10px; }

	.escalas-section { margin-top: 18px; border-top: 1px solid #E8E3DC; padding-top: 14px; }
	.escalas-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
	.escalas-titulo { font-size: 12px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: .05em; }
	.btn-escala-add { background: none; border: 1.5px solid var(--neo-accent); color: var(--neo-accent); border-radius: 4px; padding: 3px 10px; font-size: 12px; font-weight: 600; cursor: pointer; }
	.btn-escala-add:hover { background: var(--neo-accent); color: #fff; }
	.escala-fila { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; align-items: center; margin-bottom: 6px; }
	.escala-fila :global(.form-input) { font-size: 13px; padding: 5px 8px; }
	.escala-fila .btn-del-escala { background: none; border: none; cursor: pointer; color: #B91C1C; font-size: 16px; padding: 0 4px; line-height: 1; }
	.escala-fila .escala-label { font-size: 11px; color: #888; margin-bottom: 2px; }
	.escalas-vacio { font-size: 12px; color: #aaa; font-style: italic; padding: 6px 0; }
</style>
