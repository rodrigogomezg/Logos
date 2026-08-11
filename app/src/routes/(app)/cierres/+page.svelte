<script lang="ts">
	import { api } from '$lib/api';
	import { leerSesion, puede } from '$lib/session';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';

	type Turno = {
		id: number;
		abierto_en: string;
		cerrado_en: string | null;
		caja_nombre: string;
		usuario_nombre: string;
		device_name: string | null;
		estado: 'abierto' | 'cerrado';
		fondo_inicial: number | null;
		efectivo_esperado: number | null;
		efectivo_contado: number | null;
		diferencia: number | null;
	};

	const sesion = leerSesion();
	const esAdmin = puede('cajas_todas');
	const esAdminReal = sesion?.rol === 'admin';

	let sucursales = $state<{ id: number; nombre: string }[]>([]);
	let sucursalFiltro = $state('');
	let cajas = $state<{ id: number; nombre: string }[]>([]);
	let cajaFiltro = $state('');
	let desde = $state('');
	let hasta = $state('');
	let datos = $state<Turno[]>([]);
	let cargando = $state(true);

	function fmt(n: number | null) {
		return n === null || n === undefined
			? '—'
			: '$' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function fechaHora(s: string | null) {
		return s ? new Date(s).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
	}
	function difClase(t: Turno) {
		if (t.diferencia === null) return '';
		const d = Number(t.diferencia);
		return Math.abs(d) < 0.01 ? 'dif-ok' : d < 0 ? 'dif-falta' : 'dif-sobra';
	}

	async function initFiltros() {
		if (sesion?.rol === 'admin') {
			const r = await api('/sucursales');
			if (r.ok) sucursales = await r.json();
		} else {
			sucursales = sesion?.sucursales || [];
		}
		if (esAdmin) {
			const r = await api('/cajas');
			if (r.ok) cajas = await r.json();
		}
	}

	async function cargar() {
		cargando = true;
		const params = new URLSearchParams();
		const cajaId = esAdmin ? cajaFiltro : String(sesion?.caja_id ?? '');
		if (cajaId) params.set('caja_id', cajaId);
		if (desde) params.set('desde', desde);
		if (hasta) params.set('hasta', hasta);
		if (sucursalFiltro) params.set('sucursal_id', sucursalFiltro);

		const res = await api(`/caja-turnos?${params.toString()}`);
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al cargar los cierres', 'err');
			cargando = false;
			return;
		}
		datos = data;
		cargando = false;
	}

	async function eliminarTurno(t: Turno) {
		const detalle = `¿Eliminar este cierre?\n\n${t.caja_nombre} · ${t.usuario_nombre}\n${fechaHora(t.abierto_en)} → ${fechaHora(t.cerrado_en)}\n\nSolo se puede eliminar si el turno no tiene ventas ni movimientos asociados.`;
		const ok = await confirmar(detalle, { titulo: 'Eliminar cierre', confirmLabel: 'Eliminar', danger: true });
		if (!ok) return;
		const res = await api(`/caja-turnos/${t.id}`, { method: 'DELETE' });
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al eliminar el cierre', 'err');
			return;
		}
		toast_('Cierre eliminado', 'ok');
		cargar();
	}

	initFiltros();
	cargar();
</script>

<svelte:head>
	<title>Logos — Cierres históricos</title>
</svelte:head>

<div class="page-header">
	<div>
		<h1>Cierres históricos</h1>
		<p>Turnos de caja abiertos y cerrados</p>
	</div>
	<div class="filtros">
		{#if sucursales.length > 1}
			<select class="fil-select" bind:value={sucursalFiltro} onchange={cargar}>
				<option value="">Todas las sucursales</option>
				{#each sucursales as s (s.id)}
					<option value={s.id}>{s.nombre}</option>
				{/each}
			</select>
		{/if}
		{#if esAdmin}
			<select class="fil-select" bind:value={cajaFiltro} onchange={cargar}>
				<option value="">Todas las cajas</option>
				{#each cajas as c (c.id)}
					<option value={c.id}>{c.nombre}</option>
				{/each}
			</select>
		{/if}
		<input class="fil-input" type="date" bind:value={desde} onchange={cargar} />
		<input class="fil-input" type="date" bind:value={hasta} onchange={cargar} />
	</div>
</div>

<div class="contenido">
	<div class="contenido-inner">
		<div class="card">
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th>Apertura</th>
							<th>Cierre</th>
							<th>Caja</th>
							<th>Usuario</th>
							<th>Equipo</th>
							<th>Estado</th>
							<th class="r">Fondo inicial</th>
							<th class="r">Efectivo esperado</th>
							<th class="r">Efectivo contado</th>
							<th class="r">Diferencia</th>
							<th class="col-acciones"></th>
						</tr>
					</thead>
					<tbody>
						{#if cargando}
							<tr><td colspan="11"><div class="vacio">Cargando…</div></td></tr>
						{:else if !datos.length}
							<tr><td colspan="11"><div class="vacio">No hay turnos para los filtros aplicados.</div></td></tr>
						{:else}
							{#each datos as t (t.id)}
								<tr>
									<td>{fechaHora(t.abierto_en)}</td>
									<td>{fechaHora(t.cerrado_en)}</td>
									<td>{t.caja_nombre}</td>
									<td>{t.usuario_nombre}</td>
									<td>{t.device_name || '—'}</td>
									<td>
										<span class="estado-pill {t.estado}"><span class="dot"></span>{t.estado === 'abierto' ? 'Abierto' : 'Cerrado'}</span>
									</td>
									<td class="r">{fmt(t.fondo_inicial)}</td>
									<td class="r">{fmt(t.efectivo_esperado)}</td>
									<td class="r">{fmt(t.efectivo_contado)}</td>
									<td class="r {difClase(t)}">{fmt(t.diferencia)}</td>
									<td class="col-acciones">
										{#if esAdminReal && t.estado === 'cerrado'}
											<button class="btn-del-mov" title="Eliminar cierre" onclick={() => eliminarTurno(t)}>🗑</button>
										{/if}
									</td>
								</tr>
							{/each}
						{/if}
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>

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
		display: flex;
		gap: 10px;
		align-items: center;
		flex-wrap: wrap;
	}
	.fil-select,
	.fil-input {
		padding: 8px 11px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 12px;
		font-family: inherit;
		outline: none;
		background: var(--neo-bg);
		color: var(--neo-text);
		box-shadow: var(--neo-i1);
	}
	.contenido {
		flex: 1;
		min-height: 0;
		overflow-y: auto;
		padding: clamp(20px, 3vw, 32px) clamp(20px, 4vw, 48px);
		background: var(--neo-bg-deep);
	}
	.contenido-inner {
		max-width: 1300px;
		margin: 0 auto;
	}
	.card {
		background: var(--neo-bg-deep);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-i1);
		overflow: hidden;
	}
	.table-wrap {
		overflow-x: auto;
	}
	table {
		width: 100%;
		border-collapse: collapse;
		min-width: 920px;
	}
	thead th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		padding: 12px 14px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		background: var(--neo-bg-deep);
	}
	thead th.r {
		text-align: right;
	}
	tbody td {
		padding: 10px 14px;
		font-size: 13px;
		border-bottom: 1px solid var(--borde);
		white-space: nowrap;
		color: var(--neo-text);
	}
	tbody td.r {
		text-align: right;
		font-variant-numeric: tabular-nums;
		font-weight: 600;
	}
	tbody tr:last-child td {
		border-bottom: none;
	}
	tbody tr:hover {
		background: var(--color-bg-alt);
	}
	.estado-pill {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 3px 10px;
		border-radius: var(--neo-r-pill);
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		box-shadow: var(--neo-e1);
	}
	.estado-pill.abierto {
		background: rgba(39, 174, 96, 0.1);
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
	.dif-ok {
		color: var(--neo-success);
	}
	.dif-falta {
		color: var(--neo-danger);
	}
	.dif-sobra {
		color: var(--neo-warning);
	}
	.vacio {
		text-align: center;
		padding: 48px 20px;
		color: var(--neo-text-3);
		font-size: 13px;
	}
	.col-acciones {
		width: 34px;
		text-align: right;
	}
	.btn-del-mov {
		width: 26px;
		height: 26px;
		background: none;
		border: none;
		border-radius: var(--neo-r-xs);
		color: var(--neo-text-3);
		cursor: pointer;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		padding: 0;
		font-size: 13px;
	}
	tbody tr:hover .btn-del-mov {
		color: var(--neo-text-3);
	}
	.btn-del-mov:hover {
		background: rgba(231, 76, 60, 0.1);
		color: var(--neo-danger);
	}
</style>
