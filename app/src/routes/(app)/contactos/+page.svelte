<script lang="ts">
	import { api } from '$lib/api';
	import { puede } from '$lib/session';

	// POC: subconjunto "core" de contacto-modal.php (pos/contacto-modal.php).
	// Deliberadamente afuera de este spike: domicilios de envío (sub-lista
	// editable), "solo remito", presets de plazo de pago, e importación CSV —
	// el objetivo de 1A es validar el patrón de lista+modal SPA sin recarga,
	// no lograr paridad de campo por campo. Ver plan Fase 1A.
	type Contacto = {
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
		cc_habilitada: boolean;
		limite_credito: number;
		plazo_pago_dias: number | null;
		lista_precio_id: number | null;
		lista_precio_nombre: string | null;
		regla_precio_id: number | null;
		descuento_extra: number;
		activo: boolean;
	};
	type ListaPrecio = { id: number; nombre: string; porcentaje: number };
	type ReglaPrecio = { id: number; nombre: string; porcentaje_recargo: number };

	const POR_PAGINA = 50;
	const CONDICIONES_IVA = [
		'Responsable Inscripto',
		'Monotributista',
		'Consumidor Final',
		'Exento',
		'No Responsable'
	];

	let tipo = $state<'clientes' | 'proveedores'>('clientes');
	let pagina = $state(1);
	let total = $state(0);
	let paginas = $state(1);
	let q = $state('');
	let filtroActivo = $state('1');
	let cargando = $state(true);
	let datos = $state<Contacto[]>([]);
	let debounceId: ReturnType<typeof setTimeout>;

	let listas = $state<ListaPrecio[]>([]);
	let reglas = $state<ReglaPrecio[]>([]);

	let modalAbierto = $state(false);
	let confirmarEliminar = $state(false);
	let guardando = $state(false);
	let errorModal = $state('');
	let form = $state(vacio());

	function vacio() {
		return {
			id: 0,
			nombre: '',
			cuit: '',
			condicion_iva: '',
			email: '',
			telefono: '',
			domicilio: '',
			localidad: '',
			provincia: '',
			observaciones: '',
			cc_habilitada: false,
			limite_credito: 0,
			plazo_pago_dias: null as number | null,
			lista_precio_id: null as number | null,
			regla_precio_id: null as number | null,
			descuento_extra: 0,
			activo: true
		};
	}

	$effect(() => {
		cargar();
	});

	function cambiarTipo(t: 'clientes' | 'proveedores') {
		tipo = t;
		pagina = 1;
	}

	function onBuscar() {
		clearTimeout(debounceId);
		debounceId = setTimeout(() => {
			pagina = 1;
			cargar();
		}, 280);
	}

	async function cargar() {
		cargando = true;
		let url = `/${tipo}?page=${pagina}&per_page=${POR_PAGINA}`;
		if (q.trim()) url += `&q=${encodeURIComponent(q.trim())}`;
		if (filtroActivo !== '') url += `&activo=${filtroActivo}`;

		const res = await api(url);
		if (!res.ok) {
			datos = [];
			cargando = false;
			return;
		}
		const data = await res.json();
		total = data.total;
		paginas = data.paginas;
		datos = data.datos;
		cargando = false;
	}

	async function cargarListasYReglas() {
		if (tipo === 'clientes' && !listas.length) {
			const r = await api('/listas-precio?activas=1');
			if (r.ok) listas = await r.json();
		}
		if (tipo === 'proveedores' && !reglas.length) {
			const r = await api('/reglas-precio?activas=1');
			if (r.ok) reglas = await r.json();
		}
	}

	async function abrirNuevo() {
		form = vacio();
		errorModal = '';
		await cargarListasYReglas();
		modalAbierto = true;
	}

	// ── Importar CSV — port de pos/contactos.html (quedó deliberadamente
	// afuera del spike inicial a SvelteKit, nunca se retomó). Si ya existe un
	// registro con el mismo CUIT se actualiza en vez de duplicarse.
	let modalImportAbierto = $state(false);
	let csvFile = $state<File | null>(null);
	let csvArrastrando = $state(false);
	let importando = $state(false);
	let importResultado = $state<{ creados: number; actualizados: number; errores?: string[] } | null>(null);
	let importError = $state('');
	let csvInput = $state<HTMLInputElement | undefined>();

	function abrirImport() {
		csvFile = null;
		importResultado = null;
		importError = '';
		if (csvInput) csvInput.value = '';
		modalImportAbierto = true;
	}

	function setCSV(file: File) {
		csvFile = file;
		importResultado = null;
		importError = '';
	}

	function onDropCSV(e: DragEvent) {
		e.preventDefault();
		csvArrastrando = false;
		const file = e.dataTransfer?.files?.[0];
		if (file) setCSV(file);
	}

	async function subirCsv() {
		if (!csvFile) return;
		importando = true;
		importError = '';
		importResultado = null;
		try {
			const fd = new FormData();
			fd.append('csv', csvFile);
			const res = await api(`/${tipo}/importar`, { method: 'POST', body: fd });
			const data = await res.json();
			if (!res.ok) {
				importError = data.error || 'Error al importar el archivo.';
				return;
			}
			importResultado = data;
			if (!data.errores?.length) {
				pagina = 1;
				await cargar();
			}
		} catch {
			importError = 'Error de conexión.';
		} finally {
			importando = false;
		}
	}

	function descargarPlantilla() {
		window.location.href = `/Logos/api/${tipo}/plantilla-csv`;
	}

	async function abrirEditar(c: Contacto) {
		errorModal = '';
		await cargarListasYReglas();
		const res = await api(`/${tipo}/${c.id}`);
		if (!res.ok) {
			errorModal = 'Error al cargar los datos.';
			return;
		}
		const r = await res.json();
		form = {
			id: r.id,
			nombre: r.nombre ?? '',
			cuit: r.cuit ?? '',
			condicion_iva: r.condicion_iva ?? '',
			email: r.email ?? '',
			telefono: r.telefono ?? '',
			domicilio: r.domicilio ?? '',
			localidad: r.localidad ?? '',
			provincia: r.provincia ?? '',
			observaciones: r.observaciones ?? '',
			cc_habilitada: !!r.cc_habilitada,
			limite_credito: r.limite_credito ?? 0,
			plazo_pago_dias: r.plazo_pago_dias ?? null,
			lista_precio_id: r.lista_precio_id ?? null,
			regla_precio_id: r.regla_precio_id ?? null,
			descuento_extra: r.descuento_extra ?? 0,
			activo: r.activo !== false
		};
		modalAbierto = true;
	}

	function cerrarModal() {
		modalAbierto = false;
		confirmarEliminar = false;
	}

	async function guardar() {
		if (!form.nombre.trim()) {
			errorModal = 'El nombre es obligatorio.';
			return;
		}
		guardando = true;
		errorModal = '';

		const body: Record<string, unknown> = {
			nombre: form.nombre,
			cuit: form.cuit,
			condicion_iva: form.condicion_iva,
			email: form.email,
			telefono: form.telefono,
			domicilio: form.domicilio,
			localidad: form.localidad,
			provincia: form.provincia,
			observaciones: form.observaciones,
			cc_habilitada: form.cc_habilitada,
			limite_credito: Number(form.limite_credito) || 0,
			plazo_pago_dias: form.plazo_pago_dias,
			activo: form.activo
		};
		if (tipo === 'clientes') {
			body.lista_precio_id = form.lista_precio_id;
			body.descuento_extra = Number(form.descuento_extra) || 0;
		} else {
			body.regla_precio_id = form.regla_precio_id;
		}

		const url = form.id ? `/${tipo}/${form.id}` : `/${tipo}`;
		const method = form.id ? 'PUT' : 'POST';
		const res = await api(url, {
			method,
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		});
		const data = await res.json();
		guardando = false;

		if (!res.ok) {
			errorModal = data.error || 'Error al guardar.';
			return;
		}
		cerrarModal();
		await cargar();
	}

	async function eliminar() {
		if (!form.id) return;
		const res = await api(`/${tipo}/${form.id}`, { method: 'DELETE' });
		const data = await res.json();
		if (!res.ok) {
			errorModal = data.error || 'No se pudo eliminar.';
			confirmarEliminar = false;
			return;
		}
		cerrarModal();
		await cargar();
	}

	function irPagina(p: number) {
		pagina = p;
		cargar();
	}

	function fmtMoneda(n: number) {
		return n.toLocaleString('es-AR', { maximumFractionDigits: 0 });
	}

	function rangoPaginas(): (number | '…')[] {
		const out: (number | '…')[] = [];
		for (let p = 1; p <= paginas; p++) {
			if (paginas > 10 && Math.abs(p - pagina) > 2 && p !== 1 && p !== paginas) {
				if (p === 2 || p === paginas - 1) out.push('…');
				continue;
			}
			out.push(p);
		}
		return out;
	}
</script>

<svelte:head>
	<title>Contactos — Logos</title>
</svelte:head>

<main class="page-body">
	<div class="toolbar">
		<div class="tab-grupo">
			<button class="tab-tipo" class:activo={tipo === 'clientes'} onclick={() => cambiarTipo('clientes')}
				>Clientes</button
			>
			<button
				class="tab-tipo"
				class:activo={tipo === 'proveedores'}
				onclick={() => cambiarTipo('proveedores')}>Proveedores</button
			>
		</div>
		<div class="toolbar-sep"></div>
		<input
			type="search"
			class="busqueda"
			placeholder="Buscar por nombre, CUIT o email…"
			bind:value={q}
			oninput={onBuscar}
		/>
		<select class="filtro-activo" bind:value={filtroActivo} onchange={() => ((pagina = 1), cargar())}>
			<option value="">Todos</option>
			<option value="1">Activos</option>
			<option value="0">Inactivos</option>
		</select>
		<div style="flex:1"></div>
		{#if puede('importar')}
			<button class="btn btn-sec" onclick={abrirImport}>Importar CSV</button>
		{/if}
		<button class="btn btn-ok" onclick={abrirNuevo}>+ Nuevo</button>
	</div>

	<div class="card">
		<div class="tabla-wrap">
			<table>
				<thead>
					<tr>
						<th>Nombre</th>
						<th>CUIT</th>
						<th>Condición IVA</th>
						<th>Teléfono</th>
						<th>CC</th>
						<th>Límite CC</th>
						<th>Plazo pago</th>
						<th>Lista</th>
						{#if tipo === 'clientes'}<th>Dto extra</th>{/if}
						<th>Estado</th>
					</tr>
				</thead>
				<tbody>
					{#if cargando}
						<tr><td colspan="99" class="estado-tabla">Cargando…</td></tr>
					{:else if !datos.length}
						<tr><td colspan="99" class="estado-tabla">Sin resultados.</td></tr>
					{:else}
						{#each datos as c (c.id)}
							<tr class:inactivo={!c.activo} onclick={() => abrirEditar(c)}>
								<td>{c.nombre}</td>
								<td>{c.cuit || '—'}</td>
								<td>{c.condicion_iva || '—'}</td>
								<td>{c.telefono || '—'}</td>
								<td>
									{#if c.cc_habilitada}<span class="badge badge-verde">Sí</span
										>{:else}<span class="badge badge-gris">No</span>{/if}
								</td>
								<td>{c.cc_habilitada ? '$' + fmtMoneda(c.limite_credito) : '—'}</td>
								<td>{c.plazo_pago_dias ? c.plazo_pago_dias + ' días' : '—'}</td>
								<td>
									{#if c.lista_precio_nombre}<span class="badge badge-azul"
											>{c.lista_precio_nombre}</span
										>{:else}—{/if}
								</td>
								{#if tipo === 'clientes'}
									<td>{c.descuento_extra ? c.descuento_extra + '%' : '—'}</td>
								{/if}
								<td>
									{#if c.activo}<span class="badge badge-verde">Activo</span
										>{:else}<span class="badge badge-gris">Inactivo</span>{/if}
								</td>
							</tr>
						{/each}
					{/if}
				</tbody>
			</table>
		</div>
		<div class="pagination">
			{#if paginas <= 1}
				<span>{total} registro{total !== 1 ? 's' : ''}</span>
			{:else}
				<span>{total} registros</span>
				<button disabled={pagina === 1} onclick={() => irPagina(pagina - 1)}>‹</button>
				{#each rangoPaginas() as p (p)}
					{#if p === '…'}
						<span>…</span>
					{:else}
						<button class:activo={p === pagina} onclick={() => irPagina(p)}>{p}</button>
					{/if}
				{/each}
				<button disabled={pagina === paginas} onclick={() => irPagina(pagina + 1)}>›</button>
			{/if}
		</div>
	</div>
</main>

{#if modalAbierto}
	<div class="cm-overlay" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarModal()}>
		<div class="cm-modal">
			<div class="cm-header">
				<h3>
					{form.id
						? tipo === 'clientes'
							? 'Editar cliente'
							: 'Editar proveedor'
						: tipo === 'clientes'
							? 'Nuevo cliente'
							: 'Nuevo proveedor'}
				</h3>
				<button class="cm-header-cerrar" onclick={cerrarModal}>✕</button>
			</div>

			<div class="cm-body">
				<div class="cm-col">
					<span class="cm-section">Datos de contacto</span>
					<div class="cm-group">
						<label class="cm-label" for="f-nombre">Nombre / Razón social *</label>
						<input id="f-nombre" class="cm-input" bind:value={form.nombre} />
					</div>
					<div class="cm-row cm-row-2">
						<div class="cm-group">
							<label class="cm-label" for="f-cuit">CUIT</label>
							<input id="f-cuit" class="cm-input" placeholder="20-12345678-9" bind:value={form.cuit} />
						</div>
						<div class="cm-group">
							<label class="cm-label" for="f-iva">Condición IVA</label>
							<select id="f-iva" class="cm-select" bind:value={form.condicion_iva}>
								<option value="">—</option>
								{#each CONDICIONES_IVA as c (c)}<option value={c}>{c}</option>{/each}
							</select>
						</div>
					</div>
					<div class="cm-row cm-row-2">
						<div class="cm-group">
							<label class="cm-label" for="f-email">Email</label>
							<input id="f-email" class="cm-input" type="email" bind:value={form.email} />
						</div>
						<div class="cm-group">
							<label class="cm-label" for="f-tel">Teléfono</label>
							<input id="f-tel" class="cm-input" bind:value={form.telefono} />
						</div>
					</div>
					<div class="cm-group">
						<label class="cm-label" for="f-dom">Domicilio</label>
						<input id="f-dom" class="cm-input" bind:value={form.domicilio} />
					</div>
					<div class="cm-row cm-row-2">
						<div class="cm-group">
							<label class="cm-label" for="f-loc">Localidad</label>
							<input id="f-loc" class="cm-input" bind:value={form.localidad} />
						</div>
						<div class="cm-group">
							<label class="cm-label" for="f-prov">Provincia</label>
							<input id="f-prov" class="cm-input" bind:value={form.provincia} />
						</div>
					</div>
					<div class="cm-group" style="flex:1;display:flex;flex-direction:column">
						<label class="cm-label" for="f-obs">Observaciones</label>
						<textarea id="f-obs" class="cm-textarea" bind:value={form.observaciones}></textarea>
					</div>
				</div>

				<div class="cm-col cm-col-right">
					<div style="display:flex;flex-direction:column;gap:10px">
						<span class="cm-section">Cuenta corriente</span>
						<label class="cm-toggle">
							<input type="checkbox" bind:checked={form.cc_habilitada} />
							<span class="cm-track"></span>
							<span class="cm-toggle-lbl">Habilitar cuenta corriente</span>
						</label>
						<div class="cm-group">
							<label class="cm-label" for="f-limite">Límite de crédito ($)</label>
							<input
								id="f-limite"
								class="cm-input"
								type="number"
								min="0"
								step="0.01"
								bind:value={form.limite_credito}
							/>
						</div>
						<div class="cm-group">
							<label class="cm-label" for="f-plazo">Plazo de pago (días)</label>
							<input
								id="f-plazo"
								class="cm-input"
								type="number"
								min="1"
								step="1"
								placeholder="Sin plazo"
								bind:value={form.plazo_pago_dias}
							/>
						</div>
					</div>

					{#if tipo === 'clientes'}
						<div style="display:flex;flex-direction:column;gap:10px">
							<span class="cm-section">Configuración comercial</span>
							<div class="cm-group">
								<label class="cm-label" for="f-lista">Lista de precios</label>
								<select id="f-lista" class="cm-select" bind:value={form.lista_precio_id}>
									<option value={null}>Sin lista asignada</option>
									{#each listas as l (l.id)}
										<option value={l.id}
											>{l.nombre} ({l.porcentaje >= 0 ? '+' : ''}{l.porcentaje}%)</option
										>
									{/each}
								</select>
							</div>
							<div class="cm-group">
								<label class="cm-label" for="f-dto">Dto. extra (%)</label>
								<input
									id="f-dto"
									class="cm-input"
									type="number"
									min="-100"
									max="100"
									step="0.01"
									bind:value={form.descuento_extra}
								/>
							</div>
						</div>
					{:else}
						<div style="display:flex;flex-direction:column;gap:10px">
							<span class="cm-section">Configuración comercial</span>
							<div class="cm-group">
								<label class="cm-label" for="f-regla">Regla de precio</label>
								<select id="f-regla" class="cm-select" bind:value={form.regla_precio_id}>
									<option value={null}>Sin regla asignada</option>
									{#each reglas as r (r.id)}
										<option value={r.id}>{r.nombre} (+{r.porcentaje_recargo}%)</option>
									{/each}
								</select>
							</div>
						</div>
					{/if}

					<div
						style="display:flex;flex-direction:column;gap:10px;margin-top:auto;padding-top:12px;border-top:1px solid #D5D0CA"
					>
						{#if errorModal}<div style="color:#B91C1C;font-size:12px">{errorModal}</div>{/if}
						<label class="cm-toggle">
							<input type="checkbox" bind:checked={form.activo} />
							<span class="cm-track"></span>
							<span class="cm-toggle-lbl">Contacto activo</span>
						</label>
					</div>
				</div>
			</div>

			<div class="cm-footer">
				{#if form.id}
					<button
						class="cm-btn cm-btn-peligro"
						style="margin-right:auto"
						onclick={() => (confirmarEliminar = true)}>Eliminar</button
					>
				{/if}
				<button class="cm-btn cm-btn-sec" onclick={cerrarModal}>Cancelar</button>
				<button class="cm-btn cm-btn-ok" disabled={guardando} onclick={guardar}>
					{guardando ? 'Guardando…' : 'Guardar'}
				</button>
			</div>
		</div>
	</div>
{/if}

{#if modalImportAbierto}
	<div class="cm-overlay" role="presentation" onclick={(e) => e.target === e.currentTarget && (modalImportAbierto = false)}>
		<div class="cm-modal" style="width:min(96vw,540px);height:auto">
			<div class="cm-header">
				<h3>Importar {tipo === 'clientes' ? 'clientes' : 'proveedores'} — CSV</h3>
				<button class="cm-header-cerrar" onclick={() => (modalImportAbierto = false)}>✕</button>
			</div>
			<div class="cm-body cm-col" style="gap:12px">
				<p style="font-size:13px;color:#9B9590;margin:0">
					Subí un archivo CSV con los datos de {tipo === 'clientes' ? 'clientes' : 'proveedores'}. Si ya existe un
					registro con el mismo CUIT se actualizan sus datos.
					<button type="button" class="link-plantilla" onclick={descargarPlantilla}>Descargar plantilla</button>.
				</p>
				<div
					class="drop-zone"
					class:drag-over={csvArrastrando}
					role="button"
					tabindex="0"
					onclick={() => csvInput?.click()}
					onkeydown={(e) => e.key === 'Enter' && csvInput?.click()}
					ondragover={(e) => (e.preventDefault(), (csvArrastrando = true))}
					ondragleave={() => (csvArrastrando = false)}
					ondrop={onDropCSV}
				>
					<div>Arrastrá el archivo aquí o hacé click para seleccionar</div>
					<input
						type="file"
						accept=".csv,text/csv"
						style="display:none"
						bind:this={csvInput}
						onchange={() => csvInput?.files?.[0] && setCSV(csvInput.files[0])}
					/>
				</div>
				{#if csvFile}
					<div style="font-size:12px;color:#9B9590">{csvFile.name} ({(csvFile.size / 1024).toFixed(1)} KB)</div>
				{/if}
				{#if importError}
					<div class="import-result"><span class="err">Error: {importError}</span></div>
				{/if}
				{#if importResultado}
					<div class="import-result">
						<span class="ok">Creados: {importResultado.creados} · Actualizados: {importResultado.actualizados}</span>
						{#if importResultado.errores?.length}
							<div class="errores-lista">
								{#each importResultado.errores as e (e)}
									{e}<br />
								{/each}
							</div>
						{/if}
					</div>
				{/if}
			</div>
			<div class="cm-footer">
				<button class="cm-btn cm-btn-sec" onclick={() => (modalImportAbierto = false)}>Cancelar</button>
				<button class="cm-btn cm-btn-ok" disabled={!csvFile || importando} onclick={subirCsv}>
					{importando ? 'Importando…' : 'Subir e importar'}
				</button>
			</div>
		</div>
	</div>
{/if}

{#if confirmarEliminar}
	<div class="cm-overlay">
		<div class="cm-modal cm-confirm">
			<div class="cm-header">
				<h3>Confirmar eliminación</h3>
				<button class="cm-header-cerrar" onclick={() => (confirmarEliminar = false)}>✕</button>
			</div>
			<div class="cm-body">
				<p>¿Eliminar "{form.nombre}"? Esta acción no se puede deshacer.</p>
			</div>
			<div class="cm-footer">
				<button class="cm-btn cm-btn-sec" onclick={() => (confirmarEliminar = false)}>Cancelar</button>
				<button class="cm-btn" style="background:#B91C1C;color:#fff;border-color:#B91C1C" onclick={eliminar}
					>Eliminar</button
				>
			</div>
		</div>
	</div>
{/if}

<style>
	:global(body) {
		background: var(--neo-bg-deep, #f3f1ee);
	}
	.page-body {
		flex: 1;
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 12px;
		height: 100vh;
		box-sizing: border-box;
		background: var(--neo-bg-deep);
	}
	.toolbar {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		background: var(--neo-bg);
		border-radius: var(--neo-r-md);
		padding: 8px 12px;
		box-shadow: var(--neo-e2);
	}
	.tab-grupo {
		display: flex;
		border-radius: var(--neo-r-sm);
		overflow: hidden;
		box-shadow: var(--neo-e1);
	}
	.tab-tipo {
		padding: 7px 18px;
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
	.tab-tipo.activo {
		box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent);
		color: var(--neo-accent);
	}
	.toolbar-sep {
		width: 1px;
		background: var(--color-bg-alt);
		height: 30px;
		margin: 0 4px;
	}
	.busqueda {
		flex: 1;
		min-width: 180px;
		max-width: 360px;
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
	.filtro-activo {
		padding: 7px 12px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 11px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		cursor: pointer;
		box-shadow: var(--neo-i1);
	}
	.card {
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-i1);
		flex: 1;
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}
	.tabla-wrap {
		flex: 1;
		overflow: auto;
	}
	table {
		width: 100%;
		border-collapse: collapse;
		font-size: 13px;
	}
	thead th {
		position: sticky;
		top: 0;
		background: var(--neo-bg-deep);
		padding: 9px 12px;
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
	}
	tbody tr {
		border-bottom: 1px solid var(--borde);
		cursor: pointer;
	}
	tbody tr:hover {
		background: var(--color-bg-alt);
	}
	tbody tr.inactivo {
		opacity: 0.5;
	}
	tbody td {
		padding: 9px 12px;
		vertical-align: middle;
		color: var(--neo-text);
	}
	.badge {
		display: inline-block;
		padding: 2px 7px;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		border-radius: var(--neo-r-xs);
		box-shadow: var(--neo-e1);
	}
	.badge-verde {
		background: rgba(39, 174, 96, 0.1);
		color: var(--neo-success);
	}
	.badge-gris {
		background: var(--color-bg-alt);
		color: var(--neo-text-3);
	}
	.badge-azul {
		background: var(--primary-soft-2);
		color: var(--neo-accent);
	}
	.pagination {
		display: flex;
		align-items: center;
		justify-content: flex-end;
		gap: 6px;
		padding: 10px 14px;
		border-top: 1px solid var(--borde-fuerte);
		background: var(--neo-bg-deep);
		font-size: 12px;
		color: var(--neo-text-3);
	}
	.pagination button {
		padding: 4px 10px;
		border: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		font-size: 11px;
		cursor: pointer;
		font-family: inherit;
		border-radius: var(--neo-r-xs);
		box-shadow: var(--neo-e1);
	}
	.pagination button:disabled {
		opacity: 0.4;
		cursor: default;
	}
	.pagination button.activo {
		box-shadow: var(--neo-i1), 0 0 0 2px var(--neo-accent);
		color: var(--neo-accent);
	}
	.btn {
		padding: 7px 16px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		cursor: pointer;
		font-family: inherit;
	}
	.btn-ok {
		background: var(--neo-accent);
		color: white;
		box-shadow: 3px 3px 7px var(--neo-accent-glow);
	}
	.estado-tabla {
		text-align: center;
		padding: 48px 20px;
		color: var(--neo-text-3);
		font-size: 13px;
	}

	/* ── modal (mismo diseño que pos/contacto-modal.php) ── */
	.cm-overlay {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 300;
	}
	.cm-modal {
		background: #fff;
		border: 1px solid #d5d0ca;
		width: min(96vw, 1020px);
		height: min(88vh, 660px);
		display: flex;
		flex-direction: column;
	}
	.cm-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 12px 20px;
		border-bottom: 1px solid #d5d0ca;
		background: #f0eeeb;
		flex-shrink: 0;
	}
	.cm-header h3 {
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: #9b9590;
		margin: 0;
	}
	.cm-header-cerrar {
		background: none;
		border: none;
		cursor: pointer;
		color: #9b9590;
		font-size: 18px;
		line-height: 1;
		padding: 2px 6px;
	}
	.cm-body {
		flex: 1;
		overflow: hidden;
		display: grid;
		grid-template-columns: 1fr 320px;
		min-height: 0;
	}
	.cm-col {
		padding: 18px 20px;
		overflow-y: auto;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.cm-col-right {
		border-left: 1px solid #d5d0ca;
		background: #fafaf9;
		gap: 16px;
	}
	.cm-footer {
		padding: 12px 20px;
		border-top: 1px solid #d5d0ca;
		display: flex;
		gap: 8px;
		justify-content: flex-end;
		flex-shrink: 0;
	}
	.cm-group {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.cm-row {
		display: grid;
		gap: 10px;
	}
	.cm-row-2 {
		grid-template-columns: 1fr 1fr;
	}
	.cm-label {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: #9b9590;
	}
	.cm-input,
	.cm-select,
	.cm-textarea {
		padding: 8px 10px;
		border: 1px solid #d5d0ca;
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: #fff;
		width: 100%;
		box-sizing: border-box;
	}
	.cm-input:focus,
	.cm-select:focus,
	.cm-textarea:focus {
		border-color: #111;
	}
	.cm-textarea {
		resize: vertical;
		min-height: 56px;
		flex: 1;
	}
	.cm-section {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: #9b9590;
		padding-bottom: 6px;
		border-bottom: 1px solid #d5d0ca;
		display: block;
	}
	.cm-toggle {
		display: inline-flex;
		align-items: center;
		gap: 9px;
		cursor: pointer;
		user-select: none;
	}
	.cm-toggle input[type='checkbox'] {
		position: absolute;
		opacity: 0;
		width: 0;
		height: 0;
	}
	.cm-track {
		width: 36px;
		height: 20px;
		flex-shrink: 0;
		background: #e2ddd8;
		border: 1px solid #d5d0ca;
		position: relative;
		transition:
			background 140ms,
			border-color 140ms;
	}
	.cm-track::after {
		content: '';
		position: absolute;
		width: 14px;
		height: 14px;
		top: 2px;
		left: 2px;
		background: #fff;
		box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
		transition: transform 140ms;
	}
	.cm-toggle input:checked ~ .cm-track {
		background: var(--color-primary, #2563eb);
		border-color: var(--color-primary, #2563eb);
	}
	.cm-toggle input:checked ~ .cm-track::after {
		transform: translateX(16px);
	}
	.cm-toggle-lbl {
		font-size: 13px;
		color: #111;
	}
	.cm-btn {
		padding: 9px 18px;
		border: 1.5px solid transparent;
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.6px;
		cursor: pointer;
		font-family: inherit;
	}
	.cm-btn-ok {
		background: var(--color-primary, #2563eb);
		color: #fff;
		border-color: var(--color-primary, #2563eb);
	}
	.cm-btn-ok:disabled {
		opacity: 0.6;
		cursor: default;
	}
	.cm-btn-sec {
		background: #fff;
		color: #111;
		border-color: #888;
	}
	.cm-btn-peligro {
		background: #fff;
		color: #b91c1c;
		border-color: #b91c1c;
	}
	.cm-confirm {
		width: 420px;
		height: auto;
	}
	.cm-confirm .cm-body {
		display: flex;
		flex-direction: column;
		padding: 16px 20px;
		overflow: visible;
	}
	.cm-confirm p {
		font-size: 13px;
		margin: 0;
	}

	/* ── import CSV ──────────────────────────────────────────── */
	.link-plantilla {
		background: none;
		border: none;
		padding: 0;
		font: inherit;
		color: var(--color-primary);
		cursor: pointer;
		text-decoration: underline;
	}
	.drop-zone {
		border: 1px dashed var(--borde-fuerte);
		padding: 32px;
		text-align: center;
		cursor: pointer;
		font-size: 13px;
		color: #9b9590;
		background: #fafaf9;
		transition:
			border-color 120ms,
			background 120ms;
	}
	.drop-zone:hover,
	.drop-zone.drag-over {
		border-color: var(--color-primary);
		background: var(--primary-soft);
		color: #111;
	}
	.import-result {
		font-size: 13px;
		line-height: 1.7;
		color: #111;
	}
	.import-result .ok {
		color: #15803d;
		font-weight: 600;
	}
	.import-result .err {
		color: #b91c1c;
	}
	.errores-lista {
		max-height: 120px;
		overflow-y: auto;
		background: #fff;
		border: 1px solid var(--borde-fuerte);
		padding: 8px 10px;
		font-size: 12px;
		color: #b91c1c;
		margin-top: 6px;
	}
</style>
