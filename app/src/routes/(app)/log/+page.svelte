<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { api } from '$lib/api';
	import { puede, leerSesion } from '$lib/session';
	import { setTourSteps, type TourStep } from '$lib/tour';

	type LogRow = {
		fecha: string;
		usuario_nom: string | null;
		accion: string;
		entidad: string | null;
		entidad_id: number | null;
		detalle: string | null;
		ip: string | null;
		device_name: string | null;
	};
	type Chip = { k: string; v: string; cls?: string };

	const ACCION_LABELS: Record<string, string> = {
		anular_venta: 'Anular venta',
		editar_venta: 'Editar venta',
		descuento_manual: 'Descuento manual',
		anular_compra: 'Anular compra',
		editar_compra: 'Editar compra',
		cierre_caja: 'Cierre Z',
		cierre_parcial: 'Cierre parcial',
		ajuste_stock: 'Ajuste stock',
		login: 'Login',
		crear_usuario: 'Crear usuario',
		editar_usuario: 'Editar usuario',
		eliminar_usuario: 'Eliminar usuario'
	};
	const TIPO_PAGO: Record<string, string> = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', transferencia: 'Transf.', cuenta_corriente: 'Cta. cte.', mixto: 'Mixto' };
	const TIPO_MOV: Record<string, string> = { entrada: 'Entrada', salida: 'Salida', ajuste: 'Ajuste', inicial: 'Inicial' };
	const KNOWN_KEYS = new Set([
		'total', 'tipo_pago', 'tiene_cae', 'diferencia', 'efectivo_contado',
		'items_con_descuento', 'nombre', 'rol', 'activo', 'pin_cambiado', 'campos',
		'tipo', 'delta', 'stock_anterior', 'stock_actual', 'proveedor_id'
	]);

	function mon(v: unknown) {
		return '$ ' + Number(v).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
	}

	function detalleChips(str: string | null): Chip[] | null {
		if (!str) return null;
		let obj: Record<string, unknown>;
		try {
			obj = JSON.parse(str);
		} catch {
			return [{ k: '', v: str }];
		}
		if (typeof obj !== 'object' || obj === null) return [{ k: '', v: String(obj) }];

		const chips: Chip[] = [];
		if ('total' in obj) chips.push({ k: 'Total', v: mon(obj.total) });
		if ('tipo_pago' in obj && obj.tipo_pago != null) chips.push({ k: 'Tipo', v: TIPO_PAGO[obj.tipo_pago as string] ?? String(obj.tipo_pago) });
		if ('tiene_cae' in obj) chips.push({ k: 'CAE', v: obj.tiene_cae ? 'sí' : 'no', cls: obj.tiene_cae ? 'p' : 'n' });
		if ('diferencia' in obj) {
			const d = Number(obj.diferencia);
			chips.push({ k: 'Diferencia', v: (d > 0 ? '+' : '') + mon(d), cls: d < 0 ? 'n' : d > 0 ? 'p' : '' });
		}
		if ('efectivo_contado' in obj) chips.push({ k: 'Efectivo', v: mon(obj.efectivo_contado) });
		if ('items_con_descuento' in obj) {
			const n = obj.items_con_descuento as number;
			chips.push({ k: 'Con desc.', v: n + ' ítem' + (n !== 1 ? 's' : '') });
		}
		if ('nombre' in obj) chips.push({ k: 'Usuario', v: String(obj.nombre) });
		if ('rol' in obj) chips.push({ k: 'Rol', v: String(obj.rol) });
		if ('activo' in obj && obj.activo != null) chips.push({ k: 'Activo', v: obj.activo ? 'sí' : 'no', cls: obj.activo ? 'p' : 'n' });
		if ('pin_cambiado' in obj) chips.push({ k: 'PIN', v: obj.pin_cambiado ? 'cambiado' : 'sin cambios', cls: obj.pin_cambiado ? '' : 'd' });
		if ('campos' in obj) {
			const val = Array.isArray(obj.campos) ? obj.campos.join(', ') : String(obj.campos ?? '');
			chips.push({ k: 'Campos', v: val });
		}
		if ('tipo' in obj) chips.push({ k: 'Movim.', v: TIPO_MOV[obj.tipo as string] ?? String(obj.tipo) });
		if ('delta' in obj) {
			const d = Number(obj.delta);
			chips.push({ k: 'Δ stock', v: (d > 0 ? '+' : '') + d, cls: d < 0 ? 'n' : 'p' });
		}
		if ('stock_anterior' in obj) chips.push({ k: 'Antes', v: String(obj.stock_anterior) });
		if ('stock_actual' in obj) chips.push({ k: 'Ahora', v: String(obj.stock_actual) });
		if ('proveedor_id' in obj && obj.proveedor_id != null) chips.push({ k: 'Prov.', v: '#' + obj.proveedor_id });

		for (const [k, val] of Object.entries(obj)) {
			if (KNOWN_KEYS.has(k)) continue;
			chips.push({ k, v: typeof val === 'object' ? JSON.stringify(val) : String(val ?? '') });
		}

		return chips.length ? chips : null;
	}

	const hoy = new Date();
	const pad = (n: number) => String(n).padStart(2, '0');

	let desde = $state(`${hoy.getFullYear()}-${pad(hoy.getMonth() + 1)}-01`);
	let hasta = $state(`${hoy.getFullYear()}-${pad(hoy.getMonth() + 1)}-${pad(hoy.getDate())}`);
	let accion = $state('');
	let equipo = $state('');
	let datos = $state<LogRow[]>([]);
	let cargando = $state(true);
	let error = $state('');
	let listo = $state(false);

	async function cargar() {
		cargando = true;
		error = '';
		const params = new URLSearchParams({ limit: '200' });
		if (desde) params.set('desde', desde);
		if (hasta) params.set('hasta', hasta);
		if (accion) params.set('accion', accion);
		if (equipo.trim()) params.set('equipo', equipo.trim());

		const res = await api(`/log-acciones?${params}`);
		const data = await res.json();
		if (!res.ok) {
			error = data.error ?? 'Error';
			datos = [];
			cargando = false;
			return;
		}
		datos = data;
		cargando = false;
	}

	// ── Tour guiado — port de pos/log.html (4 pasos, sin onEnter). ──────
	const TOUR_STEPS: TourStep[] = [
		{
			el: null,
			title: 'Bitácora de acciones',
			body: 'El Log registra automáticamente todas las operaciones sensibles del sistema: anulaciones, ediciones, cierres de caja, ajustes de stock, logins y cambios de usuarios. No se puede editar ni borrar.'
		},
		{
			el: '.filtros',
			title: 'Filtros de búsqueda',
			body: 'Filtrá por rango de fechas, tipo de acción y nombre del equipo desde el que se realizó la operación. Combiná filtros para encontrar exactamente lo que buscás.'
		},
		{
			el: '#f-accion',
			title: 'Tipo de acción',
			body: 'Las acciones están agrupadas por categoría: Ventas, Compras, Caja y Usuarios. Seleccioná una para ver solo ese tipo de eventos y reducir el ruido.'
		},
		{
			el: '.contenido table',
			title: 'Registro de operaciones',
			body: 'Cada fila muestra: fecha/hora exacta, qué usuario hizo qué, desde qué equipo y con qué detalle. Útil para auditorías, reclamos de clientes o detectar usos incorrectos del sistema.'
		}
	];

	onMount(() => {
		const sesion = leerSesion();
		if (!(puede('log') || sesion?.rol === 'admin')) {
			goto('/');
			return;
		}
		listo = true;
		cargar();
		setTourSteps('log', TOUR_STEPS);
	});
</script>

<svelte:head>
	<title>Logos — Log de acciones</title>
</svelte:head>

{#if listo}
	<div class="page-header">
		<div>
			<h1>Log de acciones</h1>
			<p>Registro de operaciones críticas del sistema</p>
		</div>
	</div>

	<div class="filtros">
		<div>
			<label for="f-desde">Desde</label>
			<input id="f-desde" type="date" bind:value={desde} />
		</div>
		<div>
			<label for="f-hasta">Hasta</label>
			<input id="f-hasta" type="date" bind:value={hasta} />
		</div>
		<div>
			<label for="f-accion">Acción</label>
			<select id="f-accion" bind:value={accion}>
				<option value="">Todas</option>
				<optgroup label="Ventas">
					<option value="anular_venta">Anulación de venta</option>
					<option value="editar_venta">Edición de venta</option>
					<option value="descuento_manual">Descuento manual</option>
				</optgroup>
				<optgroup label="Compras">
					<option value="anular_compra">Anulación de compra</option>
					<option value="editar_compra">Edición de compra</option>
				</optgroup>
				<optgroup label="Caja">
					<option value="cierre_caja">Cierre de turno (Z)</option>
					<option value="cierre_parcial">Cierre parcial (X)</option>
					<option value="ajuste_stock">Ajuste de stock</option>
				</optgroup>
				<optgroup label="Usuarios">
					<option value="login">Inicio de sesión</option>
					<option value="crear_usuario">Crear usuario</option>
					<option value="editar_usuario">Editar usuario</option>
					<option value="eliminar_usuario">Eliminar usuario</option>
				</optgroup>
			</select>
		</div>
		<div>
			<label for="f-equipo">Equipo</label>
			<input id="f-equipo" type="text" placeholder="Nombre del equipo" style="width:150px" bind:value={equipo} />
		</div>
		<button class="btn-buscar" onclick={cargar}>Buscar</button>
	</div>

	<div class="contenido">
		<div class="card">
			<table>
				<thead>
					<tr>
						<th>Fecha/Hora</th>
						<th>Usuario</th>
						<th>Acción</th>
						<th>Entidad</th>
						<th>Detalle</th>
						<th>IP</th>
						<th>Equipo</th>
					</tr>
				</thead>
				<tbody>
					{#if cargando}
						<tr><td colspan="7" class="vacio">Cargando…</td></tr>
					{:else if error}
						<tr><td colspan="7" class="vacio">{error}</td></tr>
					{:else if !datos.length}
						<tr><td colspan="7" class="vacio">Sin registros para los filtros seleccionados.</td></tr>
					{:else}
						{#each datos as log, i (i)}
							{@const chips = detalleChips(log.detalle)}
							<tr>
								<td class="td-fecha">{log.fecha}</td>
								<td>{log.usuario_nom ?? '—'}</td>
								<td><span class="accion-badge {log.accion}">{ACCION_LABELS[log.accion] ?? log.accion}</span></td>
								<td>{log.entidad ? `${log.entidad} #${log.entidad_id ?? '?'}` : '—'}</td>
								<td class="td-detalle">
									{#if !chips}
										<span style="color:var(--neo-text-3)">—</span>
									{:else}
										{#each chips as chip, ci (ci)}
											<span class="dc">{#if chip.k}<span class="k">{chip.k}</span>{/if}<span class="v {chip.cls ?? ''}">{chip.v}</span></span>
										{/each}
									{/if}
								</td>
								<td style="font-size:11px;color:var(--neo-text-3)">{log.ip ?? '—'}</td>
								<td style="font-size:11px;color:var(--neo-text-3)">{log.device_name ?? '—'}</td>
							</tr>
						{/each}
					{/if}
				</tbody>
			</table>
		</div>
	</div>
{/if}

<style>
	.page-header {
		background: var(--neo-bg);
		box-shadow: 0 3px 8px var(--neo-sd), 0 -1px 4px var(--neo-sl);
		padding: 20px clamp(20px, 4vw, 48px);
		flex-shrink: 0;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
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
		margin-top: 4px;
	}
	.filtros {
		background: var(--neo-bg);
		border-bottom: 1px solid var(--borde-fuerte);
		padding: 10px 16px;
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
		align-items: flex-end;
		flex-shrink: 0;
	}
	.filtros label {
		display: block;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
		margin-bottom: 4px;
	}
	.filtros input,
	.filtros select {
		padding: 7px 10px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-family: inherit;
		background: var(--neo-bg);
		color: var(--neo-text);
		outline: none;
		box-shadow: var(--neo-i1);
	}
	.filtros input[type='date'] {
		width: 130px;
	}
	.filtros select {
		width: 180px;
	}
	.btn-buscar {
		padding: 7px 16px;
		background: var(--neo-accent);
		color: white;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
		box-shadow: 3px 3px 7px var(--neo-accent-glow);
	}
	.btn-buscar:hover {
		background: var(--neo-accent-h);
	}
	.contenido {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: 20px clamp(20px, 4vw, 48px);
		background: var(--neo-bg-deep);
	}
	.card {
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-i1);
		overflow: hidden;
	}
	table {
		width: 100%;
		border-collapse: collapse;
	}
	thead th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		padding: 11px 14px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		background: var(--neo-bg-deep);
	}
	tbody td {
		padding: 9px 14px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		vertical-align: top;
		color: var(--neo-text);
	}
	tbody tr:last-child td {
		border-bottom: none;
	}
	tbody tr:hover {
		background: var(--color-bg-alt);
	}
	.accion-badge {
		display: inline-block;
		padding: 2px 8px;
		border-radius: var(--neo-r-xs);
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.3px;
		background: var(--color-bg-alt);
		color: var(--neo-text-2);
	}
	.accion-badge.anular_venta,
	.accion-badge.anular_compra,
	.accion-badge.eliminar_usuario {
		background: rgba(231, 76, 60, 0.1);
		color: var(--neo-danger);
	}
	.accion-badge.cierre_caja {
		background: rgba(39, 174, 96, 0.12);
		color: var(--neo-success);
	}
	.accion-badge.cierre_parcial {
		background: rgba(39, 174, 96, 0.08);
		color: var(--neo-success);
	}
	.accion-badge.ajuste_stock,
	.accion-badge.descuento_manual {
		background: rgba(243, 156, 18, 0.1);
		color: var(--neo-warning);
	}
	.accion-badge.crear_usuario {
		background: var(--primary-soft-2);
		color: var(--neo-accent);
	}
	.accion-badge.login {
		background: var(--primary-soft);
		color: var(--neo-accent);
	}
	.accion-badge.editar_usuario,
	.accion-badge.editar_venta,
	.accion-badge.editar_compra {
		background: rgba(124, 58, 237, 0.1);
		color: #7c3aed;
	}
	.td-detalle {
		max-width: 380px;
	}
	.dc {
		display: inline-flex;
		align-items: baseline;
		gap: 4px;
		background: var(--color-bg-alt);
		border-radius: 4px;
		padding: 2px 7px;
		font-size: 11px;
		margin: 1px 2px;
		white-space: nowrap;
	}
	.dc .k {
		color: var(--neo-text-3);
		font-size: 9px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
	}
	.dc .v {
		color: var(--neo-text);
	}
	.dc .v.p {
		color: var(--neo-success);
		font-weight: 600;
	}
	.dc .v.n {
		color: var(--neo-danger);
		font-weight: 600;
	}
	.dc .v.d {
		color: var(--neo-text-3);
	}
	.td-fecha {
		white-space: nowrap;
		font-family: monospace;
		font-size: 12px;
	}
	.vacio {
		text-align: center;
		padding: 48px 20px;
		color: var(--neo-text-3);
	}
</style>
