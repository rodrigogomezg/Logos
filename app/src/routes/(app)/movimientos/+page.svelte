<script lang="ts">
	import { onMount, onDestroy } from 'svelte';
	import { api } from '$lib/api';
	import { leerSesion, puede } from '$lib/session';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';
	import { cajaOperativaId } from '$lib/operativa';

	type Movimiento = {
		id: number;
		caja_id: number;
		caja_nombre: string;
		tipo: 'ingreso' | 'retiro' | 'transferencia';
		medio_pago: 'efectivo' | 'transferencia' | 'tarjeta';
		medio_pago_destino: 'efectivo' | 'transferencia' | 'tarjeta' | null;
		motivo: string | null;
		usuario_nombre: string;
		monto: number;
		creado_en: string;
	};

	const MEDIOS: Record<string, string> = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta: 'Tarjeta' };
	const TIPOS: Record<string, string> = { ingreso: 'Ingreso', retiro: 'Retiro', transferencia: 'Transferencia' };
	const SIGNOS: Record<string, string> = { ingreso: '+', retiro: '−', transferencia: '⇄' };

	const sesion = leerSesion();
	const esAdmin = puede('cajas_todas');

	let sucursales = $state<{ id: number; nombre: string }[]>([]);
	let sucursalFiltro = $state('');
	let cajas = $state<{ id: number; nombre: string }[]>([]);
	let cajaFiltro = $state('');
	let desde = $state('');
	let hasta = $state('');
	let datos = $state<Movimiento[]>([]);
	let cargando = $state(true);

	function fmt(n: number | null) {
		return '$' + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function fechaHora(s: string) {
		return new Date(s).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' });
	}
	function medioTexto(m: Movimiento) {
		return m.tipo === 'transferencia'
			? `${MEDIOS[m.medio_pago]} → ${MEDIOS[m.medio_pago_destino ?? '']}`
			: MEDIOS[m.medio_pago] || '—';
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

	async function fijarFiltroTurnoActual() {
		const cajaId = esAdmin ? cajaOperativaId() : sesion?.caja_id;
		if (!cajaId) return;
		try {
			const res = await api(`/caja-turnos/actual?caja_id=${cajaId}`);
			const data = await res.json();
			if (data.turno?.abierto_en) desde = data.turno.abierto_en.slice(0, 10);
		} catch {
			// sin turno abierto — sin filtro por defecto
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

		const res = await api(`/caja-movimientos?${params.toString()}`);
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al cargar movimientos', 'err');
			cargando = false;
			return;
		}
		datos = data;
		cargando = false;
	}

	// ── Modal: registrar movimiento ─────────────────────────────
	let modalMov = $state(false);
	let tipoMovActivo = $state<'ingreso' | 'retiro' | 'transferencia'>('ingreso');
	let regMonto = $state('');
	let regMotivo = $state('');
	let regMedio = $state('efectivo');
	let regMedioOrigen = $state('efectivo');
	let regMedioDestino = $state('transferencia');
	let regCajaNombre = $state('');
	let hayTurno = $state(true);
	let guardandoMov = $state(false);

	async function cargarEstadoRegistro() {
		const cajaId = cajaOperativaId();
		if (!cajaId) return;
		const caja = cajas.find((c) => c.id === cajaId);
		regCajaNombre = '— ' + (caja ? caja.nombre : sesion?.caja_nombre || '—');

		const res = await api(`/caja-turnos/actual?caja_id=${cajaId}`);
		const data = await res.json();
		hayTurno = !data.no_aplica && !!data.turno;
	}

	function onCajaCambiada() {
		if (modalMov) cargarEstadoRegistro();
	}

	function abrirModalMov() {
		regMonto = '';
		regMotivo = '';
		regMedio = 'efectivo';
		regMedioOrigen = 'efectivo';
		regMedioDestino = 'transferencia';
		tipoMovActivo = 'ingreso';
		cargarEstadoRegistro();
		modalMov = true;
	}

	async function registrarMovimiento() {
		const cajaId = cajaOperativaId();
		const monto = parseFloat(regMonto);
		if (!cajaId || !monto || monto <= 0) {
			toast_('Ingresá un monto válido', 'err');
			return;
		}
		const body: Record<string, unknown> = {
			caja_id: cajaId,
			tipo: tipoMovActivo,
			monto,
			motivo: regMotivo.trim() || null
		};
		if (tipoMovActivo === 'transferencia') {
			if (regMedioOrigen === regMedioDestino) {
				toast_('Elegí dos medios de pago distintos', 'err');
				return;
			}
			body.medio_pago = regMedioOrigen;
			body.medio_pago_destino = regMedioDestino;
		} else {
			body.medio_pago = regMedio;
		}

		guardandoMov = true;
		const res = await api('/caja-movimientos', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		});
		guardandoMov = false;
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al registrar el movimiento', 'err');
			return;
		}
		toast_('Movimiento registrado', 'ok');
		modalMov = false;
		cargar();
	}

	// ── Eliminar ─────────────────────────────────────────────────
	async function eliminarMovimiento(m: Movimiento) {
		const detalle = `¿Eliminar este movimiento?\n\n${TIPOS[m.tipo]} de ${fmt(m.monto)} — ${m.caja_nombre}\n${medioTexto(m)}${m.motivo ? ' · ' + m.motivo : ''}\n${fechaHora(m.creado_en)}`;
		const ok = await confirmar(detalle, { titulo: 'Eliminar movimiento', confirmLabel: 'Eliminar', danger: true });
		if (!ok) return;
		const res = await api(`/caja-movimientos/${m.id}?caja_id=${m.caja_id}`, { method: 'DELETE' });
		const data = await res.json();
		if (!res.ok) {
			toast_(data.error || 'Error al eliminar el movimiento', 'err');
			return;
		}
		toast_('Movimiento eliminado', 'ok');
		cargar();
	}

	onMount(() => {
		window.addEventListener('caja-changed', onCajaCambiada);
	});
	onDestroy(() => {
		window.removeEventListener('caja-changed', onCajaCambiada);
	});

	(async () => {
		await initFiltros();
		await fijarFiltroTurnoActual();
		cargar();
	})();
</script>

<svelte:head>
	<title>Logos — Movimientos de caja</title>
</svelte:head>

<div class="page-header">
	<div>
		<h1>Movimientos de caja</h1>
		<p>Ingresos y retiros manuales de efectivo</p>
	</div>
	<div class="filtros">
		<button class="btn-add" title="Registrar movimiento" type="button" onclick={abrirModalMov}>+</button>
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
							<th>Fecha</th>
							<th>Caja</th>
							<th>Tipo</th>
							<th>Medio</th>
							<th>Motivo</th>
							<th>Usuario</th>
							<th class="r">Monto</th>
							<th class="col-acciones"></th>
						</tr>
					</thead>
					<tbody>
						{#if cargando}
							<tr><td colspan="8"><div class="vacio">Cargando…</div></td></tr>
						{:else if !datos.length}
							<tr><td colspan="8"><div class="vacio">No hay movimientos para los filtros aplicados.</div></td></tr>
						{:else}
							{#each datos as m (m.id)}
								<tr>
									<td>{fechaHora(m.creado_en)}</td>
									<td>{m.caja_nombre}</td>
									<td><span class="tag {m.tipo}">{TIPOS[m.tipo]}</span></td>
									<td>{medioTexto(m)}</td>
									<td>{m.motivo || '—'}</td>
									<td>{m.usuario_nombre}</td>
									<td class="r">{SIGNOS[m.tipo]}{fmt(m.monto)}</td>
									<td class="col-acciones">
										<button class="btn-del-mov" title="Eliminar" onclick={() => eliminarMovimiento(m)}>🗑</button>
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

{#if modalMov}
	<div class="overlay abierto" role="presentation" onclick={(e) => e.target === e.currentTarget && (modalMov = false)}>
		<div class="modal modal-sm" role="dialog" aria-modal="true">
			<div class="modal-head">
				<h2>Registrar movimiento <span class="sub">{regCajaNombre}</span></h2>
				<button class="modal-close" type="button" aria-label="Cerrar" onclick={() => (modalMov = false)}>&times;</button>
			</div>
			<div class="modal-body">
				{#if !hayTurno}
					<div class="ef-aviso visible">Esta caja no tiene un turno abierto. Abrila antes de registrar movimientos.</div>
				{:else}
					<div class="mov-tipos">
						<button type="button" class="mov-tipo-btn ingreso" class:activo={tipoMovActivo === 'ingreso'} onclick={() => (tipoMovActivo = 'ingreso')}>+ Ingreso</button>
						<button type="button" class="mov-tipo-btn retiro" class:activo={tipoMovActivo === 'retiro'} onclick={() => (tipoMovActivo = 'retiro')}>− Retiro</button>
						<button type="button" class="mov-tipo-btn transferencia" class:activo={tipoMovActivo === 'transferencia'} onclick={() => (tipoMovActivo = 'transferencia')}>⇄ Transferencia</button>
					</div>
					{#if tipoMovActivo !== 'transferencia'}
						<div class="form-group">
							<label class="form-label" for="reg-medio">Medio de pago</label>
							<select class="form-input" id="reg-medio" bind:value={regMedio}>
								<option value="efectivo">Efectivo</option>
								<option value="transferencia">Transferencia</option>
								<option value="tarjeta">Tarjeta</option>
							</select>
						</div>
					{:else}
						<div class="transf-medios">
							<div class="form-group">
								<label class="form-label" for="reg-medio-origen">Desde</label>
								<select class="form-input" id="reg-medio-origen" bind:value={regMedioOrigen}>
									<option value="efectivo">Efectivo</option>
									<option value="transferencia">Transferencia</option>
									<option value="tarjeta">Tarjeta</option>
								</select>
							</div>
							<div class="transf-flecha">→</div>
							<div class="form-group">
								<label class="form-label" for="reg-medio-destino">Hacia</label>
								<select class="form-input" id="reg-medio-destino" bind:value={regMedioDestino}>
									<option value="efectivo">Efectivo</option>
									<option value="transferencia">Transferencia</option>
									<option value="tarjeta">Tarjeta</option>
								</select>
							</div>
						</div>
					{/if}
					<div class="form-group">
						<label class="form-label" for="reg-monto">Monto</label>
						<input class="form-input" id="reg-monto" type="number" min="0" step="0.01" placeholder="0,00" bind:value={regMonto} />
					</div>
					<div class="form-group">
						<label class="form-label" for="reg-motivo">Motivo</label>
						<input class="form-input" id="reg-motivo" type="text" placeholder="Opcional" bind:value={regMotivo} />
					</div>
					<button class="btn btn-ok" style="width:100%" disabled={guardandoMov} onclick={registrarMovimiento}>Registrar movimiento</button>
				{/if}
			</div>
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
		display: flex;
		gap: 10px;
		align-items: center;
		flex-wrap: wrap;
	}
	.btn-add {
		width: 36px;
		height: 36px;
		border-radius: var(--neo-r-sm);
		border: none;
		background: var(--neo-accent);
		color: white;
		font-size: 20px;
		font-weight: 700;
		line-height: 1;
		cursor: pointer;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		box-shadow: 3px 3px 7px var(--neo-accent-glow);
	}
	.btn-add:hover {
		box-shadow: 5px 5px 12px var(--neo-accent-glow);
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
		min-width: 680px;
	}
	thead th {
		text-align: left;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		padding: 12px 16px;
		border-bottom: 1px solid var(--borde-fuerte);
		white-space: nowrap;
		background: var(--neo-bg-deep);
	}
	thead th.r {
		text-align: right;
	}
	tbody td {
		padding: 10px 16px;
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
	.tag {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.3px;
		padding: 3px 9px;
		border-radius: var(--neo-r-pill);
		box-shadow: var(--neo-e1);
	}
	.tag.ingreso {
		background: rgba(39, 174, 96, 0.1);
		color: var(--neo-success);
	}
	.tag.retiro {
		background: rgba(231, 76, 60, 0.1);
		color: var(--neo-danger);
	}
	.tag.transferencia {
		background: var(--primary-soft-2);
		color: var(--neo-accent);
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
	.btn-del-mov:hover {
		background: rgba(231, 76, 60, 0.1);
		color: var(--neo-danger);
	}

	/* ── Modal (flat, igual a contacto-modal) ── */
	.overlay {
		position: fixed;
		inset: 0;
		z-index: 500;
		display: flex;
		align-items: flex-start;
		justify-content: center;
		padding-top: 40px;
		background: rgba(49, 52, 75, 0.48);
		backdrop-filter: blur(4px);
	}
	.modal {
		background: #fff;
		border: 1px solid var(--borde-fuerte);
		box-shadow: 0 4px 24px rgba(0, 0, 0, 0.13);
		width: min(720px, 95vw);
		max-height: calc(100vh - 80px);
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}
	.modal-sm {
		width: min(420px, 95vw);
	}
	.modal-head {
		padding: 14px 20px;
		background: var(--color-bg-alt);
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		align-items: center;
		gap: 12px;
		flex-shrink: 0;
	}
	.modal-head h2 {
		flex: 1;
		font-size: 11px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.8px;
		color: var(--neo-text-3);
	}
	.modal-head .sub {
		font-weight: 400;
		font-size: 11px;
		color: var(--neo-text-3);
		text-transform: none;
		letter-spacing: 0;
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
		overflow-y: auto;
		padding: 20px;
		flex: 1;
	}
	.mov-tipos {
		display: flex;
		gap: 10px;
		margin-bottom: 16px;
	}
	.mov-tipo-btn {
		flex: 1;
		padding: 9px;
		border: 1px solid var(--borde-fuerte);
		background: #fff;
		font-size: 12px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		cursor: pointer;
		color: #9b9590;
		font-family: inherit;
	}
	.mov-tipo-btn.activo.ingreso {
		border-color: var(--neo-success);
		color: var(--neo-success);
		background: rgba(39, 174, 96, 0.05);
	}
	.mov-tipo-btn.activo.retiro {
		border-color: var(--neo-danger);
		color: var(--neo-danger);
		background: rgba(231, 76, 60, 0.05);
	}
	.mov-tipo-btn.activo.transferencia {
		border-color: var(--neo-accent);
		color: var(--neo-accent);
		background: var(--primary-soft);
	}
	.form-group {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin-bottom: 14px;
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
		border: 1px solid var(--borde-fuerte);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		width: 100%;
		background: #fff;
		color: var(--neo-text);
		box-sizing: border-box;
	}
	.form-input:focus {
		border-color: var(--color-primary);
	}
	.transf-medios {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 14px;
	}
	.transf-medios .form-group {
		flex: 1;
		margin-bottom: 0;
	}
	.transf-flecha {
		color: var(--neo-text-3);
		font-size: 16px;
		padding-top: 18px;
	}
	.btn {
		padding: 10px 20px;
		border: none;
		font-size: 12px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.4px;
		cursor: pointer;
		font-family: inherit;
	}
	.btn:disabled {
		opacity: 0.5;
		cursor: default;
	}
	.btn-ok {
		background: var(--neo-accent);
		color: white;
	}
	.ef-aviso {
		background: rgba(231, 76, 60, 0.08);
		border: 1px solid rgba(231, 76, 60, 0.25);
		padding: 10px 12px;
		font-size: 12px;
		color: var(--neo-danger);
		line-height: 1.5;
	}
</style>
