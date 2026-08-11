<script lang="ts">
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';

	type MapeoSugerido = { columnas?: Record<string, number | null>; fila_inicio?: number } | null;
	type OpcionesSugeridas = { iva?: string; margen_pct?: number | null; iva_defecto?: number; campos_actualizar?: string[] } | null;
	type FilaCrear = { codigo: string; nombre: string; costo_actual: number | null; precio_venta: number | null };
	type FilaActualizar = { codigo: string; nombre: string; antes: Record<string, unknown>; despues: Record<string, unknown>; reactiva: boolean };
	type FilaError = { fila: number; codigo: string | null; mensaje: string };
	type FilaDisc = { id: number; codigo: string; nombre: string };
	type PreviewData = {
		resumen: { crear: number; actualizar: number; errores: number; discontinuados: number };
		crear: FilaCrear[];
		actualizar: FilaActualizar[];
		errores: FilaError[];
		discontinuados: FilaDisc[];
	};
	type LoteHistorial = { id: number; creado_en: string; proveedor: string; archivo: string; usuario_nombre: string; total_filas: number; creados: number; actualizados: number; errores: number; desactivados: number };
	type DetalleItem = { codigo: string | null; accion: string; mensaje?: string; antes?: Record<string, unknown>; despues?: Record<string, unknown> };
	type DetalleLote = { lote: { proveedor: string; archivo: string; usuario_nombre: string; creado_en: string }; detalle: DetalleItem[] };

	const CAMPO_LABEL: Record<string, string> = {
		codigo: 'Código',
		codigo_secundario: 'Cód. secundario',
		nombre: 'Nombre',
		marca: 'Marca',
		categoria: 'Categoría',
		subcategoria: 'Subcategoría',
		proveedor: 'Proveedor',
		costo_actual: 'Costo',
		precio_venta: 'Precio venta',
		stock_minimo: 'Stock mínimo',
		iva_porcentaje: 'IVA %',
		unidad_medida: 'Unidad',
		activo: 'Activo'
	};
	const CAMPOS_MAPEABLES = ['codigo', 'codigo_secundario', 'nombre', 'marca', 'categoria', 'subcategoria', 'costo_actual', 'precio_venta', 'stock_minimo', 'iva_porcentaje', 'unidad_medida'];
	const CAMPOS_ACTUALIZABLES = ['nombre', 'codigo_secundario', 'marca', 'categoria', 'subcategoria', 'proveedor', 'costo_actual', 'precio_venta', 'stock_minimo', 'iva_porcentaje', 'unidad_medida'];

	function fmt(n: number | null | undefined) {
		if (n == null || isNaN(n)) return '—';
		return '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	let tabActual = $state<'nueva' | 'historial'>('nueva');
	let pasoActual = $state(1);

	// ── Estado del wizard ────────────────────────────────────────
	let proveedores = $state<string[]>([]);
	let p1Proveedor = $state('');
	let archivoInput = $state<HTMLInputElement | undefined>();
	let leyendo = $state(false);

	let token = $state<string | null>(null);
	let proveedorActivo = $state('');
	let columnas = $state<string[]>([]);
	let primeraFila = $state<(string | number)[]>([]);
	let muestra = $state<(string | number)[][]>([]);
	let totalFilas = $state(0);
	let mapeoSugeridoActual = $state<MapeoSugerido>(null);

	let colCampo = $state<(string | null)[]>([]);
	let filaInicio = $state(2);
	let ivaTratamiento = $state('no_aplica');
	let margenPct = $state('');
	let ivaDefecto = $state('21');
	let camposActualizar = $state<Set<string>>(new Set(CAMPOS_ACTUALIZABLES));
	let guardarPlantilla = $state(true);
	let generandoPreview = $state(false);

	let mapeoAplicado: { fila_inicio: number; columnas: Record<string, number | null> } | null = null;
	let opcionesAplicadas: { iva: string; margen_pct: number | null; iva_defecto: number; campos_actualizar: string[] } | null = null;
	let preview = $state<PreviewData | null>(null);
	let discSeleccionados = $state<Set<number>>(new Set());
	let chkTodosDisc = $state(false);
	let confirmando = $state(false);

	let resultado = $state<{ loteId: number; creados: number; actualizados: number; errores: number; desactivados: number } | null>(null);

	async function cargarProveedores() {
		try {
			const r = await api('/filtros');
			const d = await r.json();
			proveedores = d.proveedores || [];
		} catch {
			// no bloquea el flujo
		}
	}

	function colMapeadaA(campo: string): number | null {
		const idx = colCampo.findIndex((c) => c === campo);
		return idx === -1 ? null : idx;
	}
	const tienePrecioMapeado = $derived(colMapeadaA('precio_venta') !== null);
	const tieneIvaMapeado = $derived(colMapeadaA('iva_porcentaje') !== null);

	async function leerArchivo() {
		const proveedor = p1Proveedor.trim();
		const archivo = archivoInput?.files?.[0];
		if (!proveedor) {
			toast_('Ingresá el proveedor', 'err');
			return;
		}
		if (!archivo) {
			toast_('Elegí un archivo', 'err');
			return;
		}
		leyendo = true;
		try {
			const fd = new FormData();
			fd.append('archivo', archivo);
			fd.append('proveedor', proveedor);
			const res = await api('/productos-import/leer', { method: 'POST', body: fd });
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al leer el archivo');

			token = data.token;
			proveedorActivo = proveedor;
			columnas = data.columnas;
			primeraFila = data.primera_fila;
			muestra = data.muestra;
			totalFilas = data.total_filas;

			renderMapeo(data.mapeo_sugerido, data.opciones_sugeridas);
			pasoActual = 2;
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
		} finally {
			leyendo = false;
		}
	}

	function renderMapeo(mapeoSugerido: MapeoSugerido, opcionesSugeridas: OpcionesSugeridas) {
		mapeoSugeridoActual = mapeoSugerido;
		const colsSugeridas = mapeoSugerido?.columnas || {};
		const idxAField: Record<number, string> = {};
		for (const campo of CAMPOS_MAPEABLES) {
			const idx = colsSugeridas[campo];
			if (idx !== undefined && idx !== null) idxAField[idx] = campo;
		}
		colCampo = columnas.map((_, i) => idxAField[i] ?? null);
		filaInicio = mapeoSugerido?.fila_inicio || 2;

		ivaTratamiento = opcionesSugeridas?.iva || 'no_aplica';
		margenPct = opcionesSugeridas?.margen_pct != null ? String(opcionesSugeridas.margen_pct) : '';
		ivaDefecto = String(opcionesSugeridas?.iva_defecto ?? 21);
		camposActualizar = new Set(opcionesSugeridas?.campos_actualizar || CAMPOS_ACTUALIZABLES);
	}

	function recolectarMapeoYOpciones() {
		const cols: Record<string, number | null> = {};
		for (const campo of CAMPOS_MAPEABLES) cols[campo] = colMapeadaA(campo);
		const mapeo = { fila_inicio: parseInt(String(filaInicio)) || 1, columnas: cols };
		const margenVal = margenPct.trim();
		const opciones = {
			iva: ivaTratamiento,
			margen_pct: margenVal !== '' ? parseFloat(margenVal) : null,
			iva_defecto: parseFloat(ivaDefecto) || 21,
			campos_actualizar: [...camposActualizar]
		};
		return { mapeo, opciones };
	}

	async function generarPreview() {
		const { mapeo, opciones } = recolectarMapeoYOpciones();
		if (mapeo.columnas.codigo === null) {
			toast_('Mapeá la columna de Código', 'err');
			return;
		}
		generandoPreview = true;
		try {
			const res = await api('/productos-import/preview', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ token, proveedor: proveedorActivo, mapeo, opciones })
			});
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al generar la vista previa');
			preview = data;
			mapeoAplicado = mapeo;
			opcionesAplicadas = opciones;
			discSeleccionados = new Set();
			chkTodosDisc = false;
			pasoActual = 3;
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
		} finally {
			generandoPreview = false;
		}
	}

	function toggleDisc(id: number, checked: boolean) {
		const s = new Set(discSeleccionados);
		checked ? s.add(id) : s.delete(id);
		discSeleccionados = s;
	}
	function toggleTodosDisc(checked: boolean) {
		chkTodosDisc = checked;
		discSeleccionados = checked ? new Set((preview?.discontinuados ?? []).map((d) => d.id)) : new Set();
	}

	async function confirmarImportacion() {
		confirmando = true;
		try {
			const res = await api('/productos-import/confirmar', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					token,
					proveedor: proveedorActivo,
					mapeo: mapeoAplicado,
					opciones: opcionesAplicadas,
					desactivar_ids: [...discSeleccionados],
					guardar_plantilla: guardarPlantilla
				})
			});
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al confirmar la importación');
			resultado = { loteId: data.lote_id, creados: data.resumen.creados, actualizados: data.resumen.actualizados, errores: data.resumen.errores, desactivados: data.resumen.desactivados };
			pasoActual = 4;
			toast_('Importación aplicada', 'ok');
		} catch (e) {
			toast_(e instanceof Error ? e.message : String(e), 'err');
		} finally {
			confirmando = false;
		}
	}

	function nuevaImportacion() {
		token = null;
		proveedorActivo = '';
		columnas = [];
		primeraFila = [];
		muestra = [];
		totalFilas = 0;
		preview = null;
		discSeleccionados = new Set();
		resultado = null;
		p1Proveedor = '';
		if (archivoInput) archivoInput.value = '';
		pasoActual = 1;
	}

	// ── Historial ────────────────────────────────────────────────
	let histItems = $state<LoteHistorial[]>([]);
	let histPagina = $state(1);
	let histTotalPags = $state(1);
	let histTotal = $state(0);
	let histCargando = $state(true);
	let histError = $state('');

	async function cargarHistorial(pagina: number) {
		histPagina = pagina;
		histCargando = true;
		histError = '';
		try {
			const res = await api(`/productos-import?page=${pagina}&per_page=20`);
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al cargar el historial');
			histTotalPags = data.pages || 1;
			histTotal = data.total || 0;
			histItems = data.items || [];
		} catch (e) {
			histError = e instanceof Error ? e.message : String(e);
			histItems = [];
		} finally {
			histCargando = false;
		}
	}

	function cambiarTab(tab: 'nueva' | 'historial') {
		tabActual = tab;
		if (tab === 'historial') cargarHistorial(1);
	}

	// ── Modal detalle de lote ───────────────────────────────────
	let modalDetalle = $state(false);
	let detalleId = $state<number | null>(null);
	let detalleLote = $state<DetalleLote | null>(null);
	let detalleCargando = $state(false);
	let detalleError = $state('');

	async function abrirDetalleLote(id: number) {
		detalleId = id;
		modalDetalle = true;
		detalleCargando = true;
		detalleError = '';
		detalleLote = null;
		try {
			const res = await api(`/productos-import/${id}`);
			const data = await res.json();
			if (!res.ok) throw new Error(data.error || 'Error al cargar el detalle');
			detalleLote = data;
		} catch (e) {
			detalleError = e instanceof Error ? e.message : String(e);
		} finally {
			detalleCargando = false;
		}
	}
	function detalleInfo(d: DetalleItem): string {
		if (d.despues) {
			return Object.entries(d.despues)
				.map(([campo, valor]) => {
					const antes = d.antes ? d.antes[campo] : undefined;
					return `${CAMPO_LABEL[campo] || campo}: ${antes ?? '—'} → ${valor}`;
				})
				.join(' · ');
		}
		return d.mensaje || '';
	}
	const ACC_LABEL: Record<string, string> = { crear: 'Creado', actualizar: 'Actualizado', error: 'Error', desactivado: 'Desactivado' };

	cargarProveedores();
</script>

<svelte:head>
	<title>Logos — Importar productos</title>
</svelte:head>

<div class="page-header">
	<h1>Importar productos</h1>
	<p>Cargá y actualizá productos a partir de una lista de precios de un proveedor</p>
</div>

<div class="tab-bar">
	<button class="tab-btn" class:activo={tabActual === 'nueva'} onclick={() => cambiarTab('nueva')}>Nueva importación</button>
	<button class="tab-btn" class:activo={tabActual === 'historial'} onclick={() => cambiarTab('historial')}>Historial</button>
</div>

<div class="contenido">
	<div class="contenido-inner">
		{#if tabActual === 'nueva'}
			<div class="pasos">
				<div class="paso-pill" class:activo={pasoActual === 1} class:hecho={pasoActual > 1}>1. Archivo</div>
				<div class="paso-pill" class:activo={pasoActual === 2} class:hecho={pasoActual > 2}>2. Mapeo</div>
				<div class="paso-pill" class:activo={pasoActual === 3} class:hecho={pasoActual > 3}>3. Vista previa</div>
				<div class="paso-pill" class:activo={pasoActual === 4} class:hecho={pasoActual > 4}>4. Resultado</div>
			</div>

			{#if pasoActual === 1}
				<div class="card">
					<h2>Proveedor y archivo</h2>
					<p class="sub">Elegí el proveedor al que corresponde la lista y subí el archivo (.xlsx, .xls o .csv).</p>
					<div class="form-row">
						<div class="form-group">
							<label class="form-label" for="p1-proveedor">Proveedor</label>
							<input type="text" id="p1-proveedor" class="form-input" list="dl-proveedores" autocomplete="off" placeholder="Nombre del proveedor" bind:value={p1Proveedor} />
							<datalist id="dl-proveedores">
								{#each proveedores as p (p)}
									<option value={p}></option>
								{/each}
							</datalist>
						</div>
						<div class="form-group">
							<label class="form-label" for="p1-archivo">Archivo</label>
							<input type="file" id="p1-archivo" class="form-input" accept=".xlsx,.xls,.csv" bind:this={archivoInput} />
						</div>
					</div>
					<div class="btn-row">
						<button class="btn-pri" disabled={leyendo} onclick={leerArchivo}>{leyendo ? 'Leyendo…' : 'Leer archivo'}</button>
					</div>
				</div>
			{:else if pasoActual === 2}
				<div class="card">
					<h2>Mapeo de columnas</h2>
					<p class="sub">{totalFilas} filas detectadas en el archivo de {proveedorActivo}.</p>
					{#if mapeoSugeridoActual}
						<div class="aviso-plantilla">Se precargó el mapeo guardado para "{proveedorActivo}". Revisalo y ajustalo si hace falta.</div>
					{/if}

					<div class="map-grid">
						{#each columnas as letra, i (i)}
							<div class="map-col">
								<div class="map-col-letra">Columna {letra}</div>
								<select class="map-col-sel" class:mapeada={!!colCampo[i]} value={colCampo[i] ?? ''} onchange={(e) => (colCampo[i] = (e.target as HTMLSelectElement).value || null)}>
									<option value="">Ignorar</option>
									{#each CAMPOS_MAPEABLES as c (c)}
										<option value={c}>{CAMPO_LABEL[c]}</option>
									{/each}
								</select>
								<div class="map-col-muestra">
									<div class="header">{primeraFila[i] ?? ''}</div>
									{#each muestra.slice(0, 3) as fila, fi (fi)}
										<div>{fila[i] ?? ''}</div>
									{/each}
								</div>
							</div>
						{/each}
					</div>

					<div class="opciones-grid">
						<div class="form-group">
							<label class="form-label" for="p2-fila-inicio">Fila donde empiezan los datos</label>
							<input type="number" id="p2-fila-inicio" class="form-input" min="1" style="min-width:100px" bind:value={filaInicio} />
							<span class="form-hint">Si la fila 1 es un encabezado, dejá 2.</span>
						</div>
						<div class="radio-row">
							<span class="form-label">Los precios de esta lista…</span>
							<label class="radio-item"><input type="radio" name="p2-iva" value="sin_iva" checked={ivaTratamiento === 'sin_iva'} onchange={() => (ivaTratamiento = 'sin_iva')} /> son netos, sin IVA</label>
							<label class="radio-item"><input type="radio" name="p2-iva" value="con_iva" checked={ivaTratamiento === 'con_iva'} onchange={() => (ivaTratamiento = 'con_iva')} /> ya incluyen IVA</label>
							<label class="radio-item"><input type="radio" name="p2-iva" value="no_aplica" checked={ivaTratamiento === 'no_aplica'} onchange={() => (ivaTratamiento = 'no_aplica')} /> no aplica / cargar tal cual</label>
						</div>
						<div class="form-group">
							<label class="form-label" for="p2-margen">Margen sobre el costo (%)</label>
							<input
							type="number"
							id="p2-margen"
							class="form-input"
							step="0.1"
							placeholder="ej: 35"
							style="min-width:100px"
							disabled={tienePrecioMapeado}
							value={margenPct}
							oninput={(e) => (margenPct = (e.target as HTMLInputElement).value)}
						/>
							<span class="form-hint">
								{tienePrecioMapeado ? 'No se usa: ya mapeaste una columna de Precio venta, se va a tomar tal cual.' : 'Se usa para calcular el precio de venta solo si no mapeaste una columna de Precio venta.'}
							</span>
						</div>
						<div class="form-group">
							<label class="form-label" for="p2-iva-defecto">IVA por defecto</label>
							<select id="p2-iva-defecto" class="form-input" style="min-width:100px" disabled={tieneIvaMapeado} bind:value={ivaDefecto}>
								<option value="21">21%</option>
								<option value="10.5">10,5%</option>
								<option value="0">Exento (0%)</option>
							</select>
							<span class="form-hint">{tieneIvaMapeado ? 'No se usa: ya mapeaste una columna de IVA %.' : 'Se aplica a productos sin columna de IVA % mapeada.'}</span>
						</div>
					</div>

					<div class="form-group" style="margin-bottom:14px">
						<span class="form-label">Campos a actualizar en productos ya existentes</span>
						<div class="chk-grid">
							{#each CAMPOS_ACTUALIZABLES as c (c)}
								<label class="chk-item">
									<input
										type="checkbox"
										checked={camposActualizar.has(c)}
										onchange={(e) => {
											const s = new Set(camposActualizar);
											(e.target as HTMLInputElement).checked ? s.add(c) : s.delete(c);
											camposActualizar = s;
										}}
									/>
									{CAMPO_LABEL[c]}
								</label>
							{/each}
						</div>
						<span class="form-hint">Los productos nuevos siempre se crean con todos los campos mapeados.</span>
					</div>

					<label class="radio-item" style="margin-bottom:4px">
						<input type="checkbox" bind:checked={guardarPlantilla} />
						Guardar este mapeo para próximas importaciones de este proveedor
					</label>

					<div class="btn-row">
						<button class="btn-sec" onclick={() => (pasoActual = 1)}>Volver</button>
						<button class="btn-pri" disabled={generandoPreview} onclick={generarPreview}>{generandoPreview ? 'Generando…' : 'Generar vista previa'}</button>
					</div>
				</div>
			{:else if pasoActual === 3 && preview}
				<div class="card">
					<h2>Vista previa</h2>
					<p class="sub">Revisá los cambios antes de aplicarlos. Todavía no se modificó nada en la base.</p>

					<div class="totales-grid">
						<div class="total-box crear"><div class="lbl">Crear</div><div class="val">{preview.resumen.crear}</div></div>
						<div class="total-box actualizar"><div class="lbl">Actualizar</div><div class="val">{preview.resumen.actualizar}</div></div>
						<div class="total-box error"><div class="lbl">Errores</div><div class="val">{preview.resumen.errores}</div></div>
						<div class="total-box discontinuado"><div class="lbl">Discontinuados</div><div class="val">{preview.resumen.discontinuados}</div></div>
					</div>

					<details class="seccion" open>
						<summary>Productos a crear <span class="car">{preview.resumen.crear}</span></summary>
						<div class="table-wrap">
							<table class="tbl-prev">
								<thead><tr><th>Código</th><th>Nombre</th><th>Costo</th><th>Precio venta</th></tr></thead>
								<tbody>
									{#if preview.crear.length}
										{#each preview.crear as r, i (i)}
											<tr><td>{r.codigo}</td><td>{r.nombre}</td><td>{fmt(r.costo_actual)}</td><td>{fmt(r.precio_venta)}</td></tr>
										{/each}
									{:else}
										<tr><td colspan="4" class="vacio-mini">Nada para crear</td></tr>
									{/if}
								</tbody>
							</table>
						</div>
					</details>

					<details class="seccion">
						<summary>Productos a actualizar <span class="car">{preview.resumen.actualizar}</span></summary>
						<div class="table-wrap">
							<table class="tbl-prev">
								<thead><tr><th>Código</th><th>Nombre</th><th>Cambios</th></tr></thead>
								<tbody>
									{#if preview.actualizar.length}
										{#each preview.actualizar as r, i (i)}
											<tr>
												<td>{r.codigo}</td>
												<td>{r.nombre}{#if r.reactiva}<span class="badge-reactiva">Reactivado</span>{/if}</td>
												<td>
													{#each Object.keys(r.despues).filter((c) => c !== 'activo') as campo (campo)}
														{@const esNum = ['costo_actual', 'precio_venta', 'stock_minimo'].includes(campo)}
														<div class="diff-line">
															<span class="diff-campo">{CAMPO_LABEL[campo] || campo}:</span>
															<span class="diff-antes">{esNum ? fmt(r.antes[campo] as number) : (r.antes[campo] ?? '—')}</span>
															→
															<span class="diff-despues">{esNum ? fmt(r.despues[campo] as number) : (r.despues[campo] ?? '—')}</span>
														</div>
													{/each}
												</td>
											</tr>
										{/each}
									{:else}
										<tr><td colspan="3" class="vacio-mini">Nada para actualizar</td></tr>
									{/if}
								</tbody>
							</table>
						</div>
					</details>

					<details class="seccion">
						<summary>Errores <span class="car">{preview.resumen.errores}</span></summary>
						<div class="table-wrap">
							<table class="tbl-prev">
								<thead><tr><th>Fila</th><th>Código</th><th>Motivo</th></tr></thead>
								<tbody>
									{#if preview.errores.length}
										{#each preview.errores as r, i (i)}
											<tr><td>{r.fila}</td><td>{r.codigo || '—'}</td><td>{r.mensaje}</td></tr>
										{/each}
									{:else}
										<tr><td colspan="3" class="vacio-mini">Sin errores</td></tr>
									{/if}
								</tbody>
							</table>
						</div>
					</details>

					<details class="seccion">
						<summary>Productos discontinuados (de este proveedor, no aparecen en la lista nueva) <span class="car">{preview.resumen.discontinuados}</span></summary>
						<div style="padding:10px 14px">
							<label class="chk-item"><input type="checkbox" checked={chkTodosDisc} onchange={(e) => toggleTodosDisc((e.target as HTMLInputElement).checked)} /> Marcar todos para desactivar</label>
						</div>
						<div class="table-wrap">
							<table class="tbl-prev">
								<thead><tr><th style="width:40px">Desact.</th><th>Código</th><th>Nombre</th></tr></thead>
								<tbody>
									{#if preview.discontinuados.length}
										{#each preview.discontinuados as r (r.id)}
											<tr><td><input type="checkbox" checked={discSeleccionados.has(r.id)} onchange={(e) => toggleDisc(r.id, (e.target as HTMLInputElement).checked)} /></td><td>{r.codigo}</td><td>{r.nombre}</td></tr>
										{/each}
									{:else}
										<tr><td colspan="3" class="vacio-mini">No hay productos discontinuados</td></tr>
									{/if}
								</tbody>
							</table>
						</div>
					</details>

					<div class="btn-row">
						<button class="btn-sec" onclick={() => (pasoActual = 2)}>Volver</button>
						<button class="btn-pri" disabled={confirmando} onclick={confirmarImportacion}>{confirmando ? 'Aplicando…' : 'Confirmar importación'}</button>
					</div>
				</div>
			{:else if pasoActual === 4 && resultado}
				<div class="card">
					<h2>Importación aplicada</h2>
					<p class="sub">Importación #{resultado.loteId} de {proveedorActivo} aplicada correctamente.</p>
					<div class="totales-grid">
						<div class="total-box crear"><div class="lbl">Creados</div><div class="val">{resultado.creados}</div></div>
						<div class="total-box actualizar"><div class="lbl">Actualizados</div><div class="val">{resultado.actualizados}</div></div>
						<div class="total-box error"><div class="lbl">Errores</div><div class="val">{resultado.errores}</div></div>
						<div class="total-box discontinuado"><div class="lbl">Desactivados</div><div class="val">{resultado.desactivados}</div></div>
					</div>
					<div class="btn-row">
						<button class="btn-sec" onclick={() => { cambiarTab('historial'); if (resultado) abrirDetalleLote(resultado.loteId); }}>Ver en historial</button>
						<button class="btn-pri" onclick={nuevaImportacion}>Nueva importación</button>
					</div>
				</div>
			{/if}
		{:else}
			<div class="card" style="padding:0">
				<div class="table-wrap">
					<table class="tbl-prev">
						<thead><tr><th>Fecha</th><th>Proveedor</th><th>Archivo</th><th>Usuario</th><th>Filas</th><th>Creados</th><th>Actualizados</th><th>Errores</th><th>Desactivados</th></tr></thead>
						<tbody>
							{#if histCargando}
								<tr><td colspan="9" class="estado-vacio">Cargando…</td></tr>
							{:else if histError}
								<tr><td colspan="9" class="estado-vacio">{histError}</td></tr>
							{:else if !histItems.length}
								<tr><td colspan="9" class="estado-vacio">Todavía no se hizo ninguna importación</td></tr>
							{:else}
								{#each histItems as l (l.id)}
									<tr style="cursor:pointer" onclick={() => abrirDetalleLote(l.id)}>
										<td>{new Date(l.creado_en).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })}</td>
										<td>{l.proveedor}</td>
										<td>{l.archivo}</td>
										<td>{l.usuario_nombre}</td>
										<td>{l.total_filas}</td>
										<td>{l.creados}</td>
										<td>{l.actualizados}</td>
										<td>{l.errores}</td>
										<td>{l.desactivados}</td>
									</tr>
								{/each}
							{/if}
						</tbody>
					</table>
				</div>
				<div class="pag-bar">
					<button class="btn-pag" disabled={histPagina <= 1} onclick={() => cargarHistorial(histPagina - 1)}>← Anterior</button>
					<span class="pag-info">Página {histPagina} de {histTotalPags} · {histTotal} importaciones</span>
					<button class="btn-pag" disabled={histPagina >= histTotalPags} onclick={() => cargarHistorial(histPagina + 1)}>Siguiente →</button>
				</div>
			</div>
		{/if}
	</div>
</div>

{#if modalDetalle}
	<div class="m-overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && (modalDetalle = false)}>
		<div class="modal" role="dialog" aria-modal="true">
			<div class="m-head">
				<div>
					<h3>Importación #{detalleId}</h3>
					<div class="sub">
						{#if detalleCargando}
							Cargando…
						{:else if detalleLote}
							{detalleLote.lote.proveedor} · {detalleLote.lote.archivo} · {detalleLote.lote.usuario_nombre} · {new Date(detalleLote.lote.creado_en).toLocaleString('es-AR')}
						{/if}
					</div>
				</div>
				<button class="m-cerrar" aria-label="Cerrar" onclick={() => (modalDetalle = false)}>×</button>
			</div>
			<div class="m-body">
				<table class="tbl-prev">
					<thead><tr><th>Código</th><th>Acción</th><th>Detalle</th></tr></thead>
					<tbody>
						{#if detalleCargando}
							<tr><td colspan="3" class="vacio-mini">Cargando…</td></tr>
						{:else if detalleError}
							<tr><td colspan="3" class="vacio-mini">{detalleError}</td></tr>
						{:else if !detalleLote?.detalle.length}
							<tr><td colspan="3" class="vacio-mini">Sin detalle</td></tr>
						{:else}
							{#each detalleLote.detalle as d, i (i)}
								<tr><td>{d.codigo || '—'}</td><td>{ACC_LABEL[d.accion] || d.accion}</td><td>{detalleInfo(d)}</td></tr>
							{/each}
						{/if}
					</tbody>
				</table>
			</div>
		</div>
	</div>
{/if}

<style>
	.page-header {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd);
		padding: 16px clamp(16px, 4vw, 48px);
		flex-shrink: 0;
	}
	.page-header h1 {
		font-size: 22px;
		font-weight: 700;
		letter-spacing: -0.3px;
		color: var(--neo-text);
	}
	.page-header p {
		font-size: 12px;
		color: var(--neo-text-3);
		margin-top: 3px;
	}
	.tab-bar {
		background: var(--neo-bg);
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		padding: 0 clamp(16px, 4vw, 48px);
		flex-shrink: 0;
	}
	.tab-btn {
		padding: 9px 20px;
		font-size: 12px;
		font-weight: 600;
		border: none;
		background: none;
		cursor: pointer;
		color: var(--neo-text-3);
		border-bottom: 2px solid transparent;
		margin-bottom: -1px;
		font-family: inherit;
	}
	.tab-btn:hover {
		color: var(--neo-text);
	}
	.tab-btn.activo {
		color: var(--neo-accent);
		border-bottom-color: var(--neo-accent);
	}
	.contenido {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: clamp(16px, 3vw, 32px) clamp(16px, 4vw, 48px);
		background: var(--neo-bg-deep);
	}
	.contenido-inner {
		max-width: 1200px;
		margin: 0 auto;
	}
	.pasos {
		display: flex;
		gap: 6px;
		margin-bottom: 18px;
	}
	.paso-pill {
		flex: 1;
		padding: 9px 14px;
		border: none;
		border-radius: var(--neo-r-md);
		font-size: 12px;
		font-weight: 700;
		color: var(--neo-text-3);
		text-align: center;
		background: var(--neo-bg);
		box-shadow: var(--neo-e2);
	}
	.paso-pill.activo {
		box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent);
		color: var(--neo-accent);
	}
	.paso-pill.hecho {
		box-shadow: var(--neo-e1), 0 0 0 1px var(--neo-success);
		color: var(--neo-success);
	}
	.card {
		background: var(--neo-bg);
		border: none;
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e2);
		padding: 22px;
		margin-bottom: 16px;
	}
	.card h2 {
		font-size: 16px;
		font-weight: 800;
		margin-bottom: 4px;
		color: var(--neo-text);
	}
	.card .sub {
		font-size: 12px;
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
		padding: 9px 11px;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		min-width: 220px;
		box-shadow: var(--neo-i1);
	}
	.form-hint {
		font-size: 11px;
		color: var(--neo-text-3);
		margin-top: 4px;
	}
	.btn-pri {
		padding: 10px 20px;
		background: var(--neo-accent);
		color: white;
		border: none;
		border-radius: var(--neo-r-sm);
		font-size: 13px;
		font-weight: 700;
		cursor: pointer;
		box-shadow: 4px 4px 10px var(--neo-accent-glow);
		font-family: inherit;
	}
	.btn-pri:hover:not(:disabled) {
		background: var(--neo-accent-h);
	}
	.btn-pri:disabled {
		background: var(--neo-bg-deep);
		color: var(--neo-text-3);
		cursor: not-allowed;
		box-shadow: var(--neo-i1);
	}
	.btn-sec {
		padding: 10px 20px;
		border: none;
		border-radius: var(--neo-r-sm);
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-e2);
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
	}
	.btn-sec:hover {
		box-shadow: var(--neo-e3);
	}
	.btn-row {
		display: flex;
		gap: 10px;
		justify-content: flex-end;
		margin-top: 18px;
	}
	.aviso-plantilla {
		background: var(--primary-soft);
		border: none;
		box-shadow: var(--neo-e1), 0 0 0 1px var(--primary-line);
		color: var(--neo-accent);
		border-radius: var(--neo-r-sm);
		padding: 10px 14px;
		font-size: 12px;
		margin-bottom: 16px;
	}
	.map-grid {
		display: flex;
		gap: 10px;
		overflow-x: auto;
		padding-bottom: 6px;
		margin-bottom: 18px;
	}
	.map-col {
		flex: 0 0 170px;
		border: none;
		border-radius: var(--neo-r-md);
		padding: 10px;
		background: var(--neo-bg);
		box-shadow: var(--neo-e1);
	}
	.map-col-letra {
		font-size: 10px;
		font-weight: 700;
		color: var(--neo-text-3);
		margin-bottom: 6px;
	}
	.map-col-sel {
		width: 100%;
		padding: 6px 8px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-family: inherit;
		background: var(--neo-bg);
		color: var(--neo-text);
		margin-bottom: 8px;
		box-shadow: var(--neo-i1);
		outline: none;
	}
	.map-col-sel.mapeada {
		box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent);
	}
	.map-col-muestra {
		font-size: 11px;
		color: var(--neo-text-3);
		line-height: 1.6;
	}
	.map-col-muestra div {
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.map-col-muestra .header {
		font-weight: 700;
		color: var(--neo-text);
	}
	.opciones-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: 18px;
		margin-bottom: 14px;
	}
	.radio-row {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.radio-item {
		display: flex;
		align-items: center;
		gap: 7px;
		font-size: 12px;
		cursor: pointer;
		color: var(--neo-text);
	}
	.radio-item input {
		accent-color: var(--neo-accent);
		cursor: pointer;
	}
	.chk-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
		gap: 8px;
	}
	.chk-item {
		display: flex;
		align-items: center;
		gap: 7px;
		font-size: 12px;
		cursor: pointer;
		color: var(--neo-text);
	}
	.chk-item input {
		accent-color: var(--neo-accent);
		cursor: pointer;
		width: 15px;
		height: 15px;
	}
	.totales-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
		gap: 14px;
		margin-bottom: 18px;
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
		font-size: 22px;
		font-weight: 800;
		font-variant-numeric: tabular-nums;
		color: var(--neo-text);
	}
	.total-box.crear .val {
		color: var(--neo-success);
	}
	.total-box.actualizar .val {
		color: var(--neo-accent);
	}
	.total-box.error .val {
		color: var(--neo-danger);
	}
	.total-box.discontinuado .val {
		color: var(--neo-warning);
	}
	.seccion {
		border: none;
		border-radius: var(--neo-r-md);
		margin-bottom: 10px;
		overflow: hidden;
		box-shadow: var(--neo-e1);
	}
	.seccion summary {
		padding: 11px 14px;
		font-weight: 700;
		font-size: 12px;
		cursor: pointer;
		background: var(--neo-bg);
		list-style: none;
		display: flex;
		align-items: center;
		gap: 8px;
		color: var(--neo-text);
	}
	.seccion summary::-webkit-details-marker {
		display: none;
	}
	.seccion summary .car {
		margin-left: auto;
		font-weight: 800;
	}
	.table-wrap {
		overflow-x: auto;
	}
	table.tbl-prev {
		width: 100%;
		border-collapse: collapse;
	}
	table.tbl-prev th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		color: var(--color-ink);
		padding: 8px 14px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		background: var(--neo-bg-deep);
	}
	table.tbl-prev td {
		padding: 8px 14px;
		font-size: 12px;
		border-bottom: 1px solid var(--borde);
		vertical-align: top;
		color: var(--neo-text);
	}
	table.tbl-prev tr:last-child td {
		border-bottom: none;
	}
	.diff-line {
		white-space: nowrap;
	}
	.diff-campo {
		color: var(--neo-text-3);
		font-weight: 600;
	}
	.diff-antes {
		color: var(--neo-danger);
		text-decoration: line-through;
	}
	.diff-despues {
		color: var(--neo-success);
		font-weight: 700;
	}
	.badge-reactiva {
		display: inline-block;
		background: var(--primary-soft-2);
		color: var(--neo-accent);
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		padding: 2px 7px;
		border-radius: var(--neo-r-xs);
		margin-left: 6px;
	}
	.vacio-mini {
		padding: 14px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 12px;
	}
	.pag-bar {
		padding: 10px 0;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 12px;
	}
	.btn-pag {
		padding: 5px 14px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		color: var(--neo-text);
		background: var(--neo-bg);
		box-shadow: var(--neo-e1);
		font-family: inherit;
	}
	.btn-pag:hover:not(:disabled) {
		box-shadow: var(--neo-e2);
	}
	.btn-pag:disabled {
		color: var(--neo-text-3);
		cursor: not-allowed;
		box-shadow: none;
	}
	.pag-info {
		font-size: 12px;
		color: var(--neo-text-3);
	}
	.m-overlay {
		position: fixed;
		inset: 0;
		z-index: 1000;
		display: flex;
		align-items: center;
		justify-content: center;
		background: rgba(49, 52, 75, 0.48);
		backdrop-filter: blur(4px);
	}
	.modal {
		background: var(--neo-bg);
		border-radius: var(--neo-r-xl);
		box-shadow: var(--neo-e4);
		max-height: 85vh;
		width: min(720px, 95vw);
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}
	.m-head {
		padding: 16px 20px;
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 12px;
		flex-shrink: 0;
	}
	.m-head h3 {
		font-size: 15px;
		font-weight: 800;
		color: var(--neo-text);
	}
	.m-head .sub {
		font-size: 12px;
		color: var(--neo-text-3);
		margin-top: 2px;
	}
	.m-cerrar {
		width: 28px;
		height: 28px;
		border: none;
		border-radius: var(--neo-r-xs);
		background: var(--neo-bg);
		box-shadow: var(--neo-e1);
		font-size: 16px;
		cursor: pointer;
		flex-shrink: 0;
		color: var(--neo-text-2);
	}
	.m-cerrar:hover {
		box-shadow: var(--neo-e2);
	}
	.m-body {
		padding: 0;
		overflow-y: auto;
		flex: 1;
	}
	.estado-vacio {
		padding: 50px 20px;
		text-align: center;
		color: var(--neo-text-3);
		font-size: 13px;
	}
</style>
