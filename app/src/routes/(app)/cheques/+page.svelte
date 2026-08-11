<script lang="ts">
	import { api } from '$lib/api';
	import { toast_ } from '$lib/toast';
	import { confirmar } from '$lib/confirm';

	type Cheque = {
		id: number;
		tipo: 'recibido' | 'emitido';
		numero: string;
		banco: string;
		monto: number;
		fecha_emision: string | null;
		fecha_vencimiento: string;
		estado: string;
		librador: string | null;
		beneficiario: string | null;
		proveedor_nombre: string | null;
		venta_id: number | null;
		cliente_nombre: string | null;
		cc_movimiento_id: number | null;
	};
	type Resumen = { cantidad: number; total: number };
	type Proveedor = { id: number; nombre: string; activo: number };

	const hoy = new Date().toISOString().slice(0, 10);

	let tipoActual = $state<'recibido' | 'emitido'>('recibido');
	let filas = $state<Cheque[]>([]);
	let proveedores = $state<Proveedor[]>([]);
	let cargando = $state(true);

	let filEstado = $state('');
	let busqueda = $state('');
	let filDesde = $state('');
	let filHasta = $state('');
	let busqTimer: ReturnType<typeof setTimeout>;

	// Resumen
	let cartera = $state<Resumen>({ cantidad: 0, total: 0 });
	let depositado = $state<Resumen>({ cantidad: 0, total: 0 });
	let endosado = $state<Resumen>({ cantidad: 0, total: 0 });
	let emitidoPend = $state<Resumen>({ cantidad: 0, total: 0 });
	let vencidos = $state<Resumen | null>(null);

	const opcionesEstadoTodas = [
		{ value: '', label: 'Todos los estados' },
		{ value: 'cartera', label: 'En cartera' },
		{ value: 'depositado', label: 'Depositados' },
		{ value: 'endosado', label: 'Endosados' },
		{ value: 'rechazado', label: 'Rechazados' },
		{ value: 'pendiente', label: 'Pendientes' },
		{ value: 'debitado', label: 'Debitados' }
	];
	const opcionesEstado = $derived(
		opcionesEstadoTodas.filter((o) =>
			tipoActual === 'emitido'
				? !['cartera', 'depositado', 'endosado'].includes(o.value)
				: !['pendiente', 'debitado'].includes(o.value)
		)
	);

	function fmt(n: number | null) {
		if (n == null) return '—';
		return '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}
	function fmtFecha(s: string | null) {
		if (!s) return '—';
		const [y, m, d] = s.split('-');
		return `${d}/${m}/${y}`;
	}
	function pl(n: number, w: string) {
		return `${n} ${w}${n !== 1 ? 's' : ''}`;
	}
	function vencClase(f: Cheque) {
		const diff = Math.floor((new Date(f.fecha_vencimiento).getTime() - new Date(hoy).getTime()) / 86400000);
		return diff < 0 ? 'venc-venc' : diff <= 7 ? 'venc-prox' : 'venc-ok';
	}
	function vencNota(f: Cheque) {
		const diff = Math.floor((new Date(f.fecha_vencimiento).getTime() - new Date(hoy).getTime()) / 86400000);
		if (f.estado !== 'cartera') return '';
		if (diff < 0) return ' (vencido)';
		if (diff <= 7 && diff >= 0) return ' (próximo)';
		return '';
	}
	function origenRecibido(f: Cheque) {
		if (f.venta_id) return `Venta #${f.venta_id}`;
		if (f.cliente_nombre) return f.cliente_nombre;
		if (f.cc_movimiento_id) return `CC mov. #${f.cc_movimiento_id}`;
		return '—';
	}

	async function cambiarTab(tipo: 'recibido' | 'emitido') {
		tipoActual = tipo;
		if (tipo === 'emitido' && ['cartera', 'depositado', 'endosado'].includes(filEstado)) filEstado = '';
		if (tipo === 'recibido' && ['pendiente', 'debitado'].includes(filEstado)) filEstado = '';
		cargar();
	}

	async function cargarResumen() {
		const r = await api('/cheques/resumen');
		if (!r.ok) return;
		const d = await r.json();
		const rec = d.data?.recibido ?? {};
		const emi = d.data?.emitido ?? {};
		cartera = rec.cartera ?? { cantidad: 0, total: 0 };
		depositado = rec.depositado ?? { cantidad: 0, total: 0 };
		endosado = rec.endosado ?? { cantidad: 0, total: 0 };
		emitidoPend = emi.pendiente ?? { cantidad: 0, total: 0 };
		vencidos = d.vencidos?.cantidad > 0 ? d.vencidos : null;
	}

	async function cargar() {
		cargando = true;
		const qs = new URLSearchParams({ tipo: tipoActual });
		if (filEstado) qs.set('estado', filEstado);
		if (busqueda.trim()) qs.set('q', busqueda.trim());
		if (filDesde) qs.set('desde', filDesde);
		if (filHasta) qs.set('hasta', filHasta);
		const r = await api(`/cheques?${qs}`);
		filas = r.ok ? await r.json() : [];
		cargando = false;
	}

	async function cargarProveedores() {
		const r = await api('/proveedores');
		if (!r.ok) return;
		const d = await r.json();
		proveedores = Array.isArray(d) ? d : (d.data ?? d.proveedores ?? []);
	}

	function onBuscarInput() {
		clearTimeout(busqTimer);
		busqTimer = setTimeout(cargar, 280);
	}

	async function recargar() {
		await Promise.all([cargarResumen(), cargar()]);
	}

	// ── Acciones sobre un cheque existente ──────────────────────
	async function accionRechazar(f: Cheque) {
		const ok = await confirmar(`¿Marcar como rechazado el cheque N° ${f.numero} por ${fmt(f.monto)}?`, {
			confirmLabel: 'Rechazar',
			danger: true
		});
		if (!ok) return;
		const r = await api(`/cheques/${f.id}/rechazar`, { method: 'POST' });
		if (r.ok) {
			toast_('Cheque marcado como rechazado', 'ok');
			recargar();
		} else {
			toast_('Error al rechazar', 'err');
		}
	}
	async function accionDebitado(f: Cheque) {
		const ok = await confirmar(`¿Confirmar que el cheque N° ${f.numero} fue debitado de la cuenta?`, {
			confirmLabel: 'Confirmar débito'
		});
		if (!ok) return;
		const r = await api(`/cheques/${f.id}/debitado`, { method: 'POST' });
		if (r.ok) {
			toast_('Cheque marcado como debitado', 'ok');
			recargar();
		} else {
			toast_('Error', 'err');
		}
	}
	async function accionReactivar(f: Cheque) {
		const ok = await confirmar(
			`¿Reactivar el cheque N° ${f.numero}? Volverá al estado ${f.tipo === 'recibido' ? 'cartera' : 'pendiente'}.`,
			{ confirmLabel: 'Reactivar' }
		);
		if (!ok) return;
		const r = await api(`/cheques/${f.id}/reactivar`, { method: 'POST' });
		if (!r.ok) {
			const d = await r.json();
			toast_(d.error || 'Error', 'err');
			return;
		}
		toast_('Cheque reactivado', 'ok');
		recargar();
	}
	async function accionEliminar(f: Cheque) {
		const ok = await confirmar(`¿Eliminar el cheque N° ${f.numero}?`, { confirmLabel: 'Eliminar', danger: true });
		if (!ok) return;
		const r = await api(`/cheques/${f.id}`, { method: 'DELETE' });
		if (!r.ok) {
			const d = await r.json();
			toast_(d.error || 'Error', 'err');
			return;
		}
		toast_('Cheque eliminado', 'ok');
		recargar();
	}

	// ── Modal: Depositar ─────────────────────────────────────────
	let modalDepositar = $state(false);
	let accionId = $state<number | null>(null);
	let depInfo = $state('');
	let depBanco = $state('');
	let depFecha = $state(hoy);

	function abrirDepositar(f: Cheque) {
		accionId = f.id;
		depInfo = `Cheque N° ${f.numero} — ${f.banco} ${fmt(f.monto)}`;
		depBanco = '';
		depFecha = hoy;
		modalDepositar = true;
	}
	async function confirmarDepositar() {
		const banco = depBanco.trim();
		if (!banco) return;
		const r = await api(`/cheques/${accionId}/depositar`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ banco_destino: banco, fecha_deposito: depFecha })
		});
		if (!r.ok) {
			const d = await r.json();
			toast_(d.error || 'Error', 'err');
			return;
		}
		modalDepositar = false;
		toast_('Cheque depositado correctamente', 'ok');
		recargar();
	}

	// ── Modal: Endosar ────────────────────────────────────────────
	let modalEndosar = $state(false);
	let endInfo = $state('');
	let endProveedor = $state('');
	let endFecha = $state(hoy);
	let endObs = $state('');

	function abrirEndosar(f: Cheque) {
		accionId = f.id;
		endInfo = `Cheque N° ${f.numero} — ${f.banco} ${fmt(f.monto)}`;
		endProveedor = '';
		endFecha = hoy;
		endObs = '';
		modalEndosar = true;
	}
	async function confirmarEndosar() {
		if (!endProveedor) return;
		const r = await api(`/cheques/${accionId}/endosar`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ proveedor_id: parseInt(endProveedor), fecha: endFecha, observaciones: endObs.trim() })
		});
		const d = await r.json();
		if (!r.ok) {
			toast_(d.error || 'Error', 'err');
			return;
		}
		modalEndosar = false;
		toast_(`Cheque endosado a ${d.proveedor}`, 'ok');
		recargar();
	}

	// ── Modal: Nuevo cheque ──────────────────────────────────────
	let modalNuevo = $state(false);
	let nTipo = $state<'recibido' | 'emitido'>('recibido');
	let nNumero = $state('');
	let nBanco = $state('');
	let nMonto = $state('');
	let nLibrador = $state('');
	let nBeneficiario = $state('');
	let nCuit = $state('');
	let nFechaEmision = $state(hoy);
	let nFechaVencimiento = $state(hoy);
	let nProveedor = $state('');
	let nNotas = $state('');
	let guardandoNuevo = $state(false);

	function abrirNuevo() {
		nTipo = tipoActual;
		nNumero = '';
		nBanco = '';
		nMonto = '';
		nLibrador = '';
		nBeneficiario = '';
		nCuit = '';
		nFechaEmision = hoy;
		nFechaVencimiento = hoy;
		nProveedor = '';
		nNotas = '';
		modalNuevo = true;
	}

	async function guardarNuevo() {
		if (!nNumero.trim()) {
			toast_('Ingresá el número de cheque', 'err');
			return;
		}
		if (!nBanco.trim()) {
			toast_('Ingresá el banco', 'err');
			return;
		}
		const monto = parseFloat(nMonto);
		if (!monto || monto <= 0) {
			toast_('Ingresá un monto válido', 'err');
			return;
		}
		if (!nFechaVencimiento) {
			toast_('Ingresá la fecha de vencimiento', 'err');
			return;
		}
		const body = {
			tipo: nTipo,
			numero: nNumero.trim(),
			banco: nBanco.trim(),
			monto,
			fecha_emision: nFechaEmision || null,
			fecha_vencimiento: nFechaVencimiento,
			librador: nLibrador.trim() || null,
			beneficiario: nBeneficiario.trim() || null,
			cuit: nCuit.trim() || null,
			notas: nNotas.trim() || null,
			proveedor_id: nProveedor ? parseInt(nProveedor) : null
		};
		guardandoNuevo = true;
		const r = await api('/cheques', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		});
		guardandoNuevo = false;
		const d = await r.json();
		if (!r.ok) {
			toast_(d.error || 'Error al guardar', 'err');
			return;
		}
		modalNuevo = false;
		toast_('Cheque guardado correctamente', 'ok');
		recargar();
	}

	Promise.all([cargarResumen(), cargar(), cargarProveedores()]);
</script>

<svelte:head>
	<title>Logos — Cheques</title>
</svelte:head>

<div class="page-body">
	<div class="chq-resumen">
		<div class="chq-card">
			<div class="chq-card-lbl">En cartera</div>
			<div class="chq-card-row">
				<span class="chq-card-val">{fmt(cartera.total)}</span>
				<span class="chq-card-sub">{pl(cartera.cantidad, 'cheque')}</span>
			</div>
		</div>
		{#if vencidos}
			<div class="chq-card danger">
				<div class="chq-card-lbl">Vencidos en cartera</div>
				<div class="chq-card-row">
					<span class="chq-card-val">{fmt(vencidos.total)}</span>
					<span class="chq-card-sub">{pl(vencidos.cantidad, 'cheque')} VENCIDO</span>
				</div>
			</div>
		{/if}
		<div class="chq-card ok">
			<div class="chq-card-lbl">Depositados</div>
			<div class="chq-card-row">
				<span class="chq-card-val">{fmt(depositado.total)}</span>
				<span class="chq-card-sub">{pl(depositado.cantidad, 'cheque')}</span>
			</div>
		</div>
		<div class="chq-card">
			<div class="chq-card-lbl">Endosados</div>
			<div class="chq-card-row">
				<span class="chq-card-val">{fmt(endosado.total)}</span>
				<span class="chq-card-sub">{pl(endosado.cantidad, 'cheque')}</span>
			</div>
		</div>
		<div class="chq-card warn">
			<div class="chq-card-lbl">Emitidos pendientes</div>
			<div class="chq-card-row">
				<span class="chq-card-val">{fmt(emitidoPend.total)}</span>
				<span class="chq-card-sub">{pl(emitidoPend.cantidad, 'cheque')}</span>
			</div>
		</div>
	</div>

	<div class="chq-toolbar">
		<div class="chq-tabs">
			<button class="chq-tab" class:active={tipoActual === 'recibido'} onclick={() => cambiarTab('recibido')}>Recibidos</button>
			<button class="chq-tab" class:active={tipoActual === 'emitido'} onclick={() => cambiarTab('emitido')}>Emitidos</button>
		</div>
		<div class="chq-sep"></div>
		<select class="chq-estado-sel" bind:value={filEstado} onchange={cargar}>
			{#each opcionesEstado as o (o.value)}
				<option value={o.value}>{o.label}</option>
			{/each}
		</select>
		<input
			type="search"
			class="chq-busq"
			placeholder="Buscar N°, banco, librador…"
			bind:value={busqueda}
			oninput={onBuscarInput}
		/>
		<span class="chq-lbl-date">Venc. desde</span>
		<input type="date" class="chq-date" bind:value={filDesde} onchange={cargar} />
		<span class="chq-lbl-date">hasta</span>
		<input type="date" class="chq-date" bind:value={filHasta} onchange={cargar} />
		<div style="flex:1"></div>
		<button class="btn btn-ok" style="padding:6px 14px;font-size:12px" onclick={abrirNuevo}>+ Nuevo cheque</button>
	</div>

	<div class="chq-table-wrap">
		<table class="chq-tbl">
			<thead>
				{#if tipoActual === 'recibido'}
					<tr>
						<th>N° cheque</th><th>Banco</th><th>Librador</th><th class="r">Monto</th><th>Emisión</th
						><th>Vencimiento</th><th>Estado</th><th>Origen</th><th></th>
					</tr>
				{:else}
					<tr>
						<th>N° cheque</th><th>Banco</th><th>Beneficiario</th><th class="r">Monto</th><th>Emisión</th
						><th>Vencimiento</th><th>Estado</th><th>Proveedor</th><th></th>
					</tr>
				{/if}
			</thead>
			<tbody>
				{#if cargando}
					<tr><td colspan="9" class="chq-empty">Cargando…</td></tr>
				{:else if !filas.length}
					<tr><td colspan="9" class="chq-empty">No hay cheques para mostrar.</td></tr>
				{:else}
					{#each filas as f (f.id)}
						<tr>
							<td class="td-mono">{f.numero}</td>
							<td>{f.banco}</td>
							<td>{tipoActual === 'recibido' ? f.librador || '—' : f.beneficiario || f.proveedor_nombre || '—'}</td>
							<td class="r td-monto">{fmt(f.monto)}</td>
							<td>{fmtFecha(f.fecha_emision)}</td>
							<td class={vencClase(f)}>{fmtFecha(f.fecha_vencimiento)}{vencNota(f)}</td>
							<td><span class="estado-badge est-{f.estado}">{f.estado}</span></td>
							{#if tipoActual === 'recibido'}
								<td><span class="orig-badge">{origenRecibido(f)}</span></td>
							{:else}
								<td><span class="orig-badge">{f.proveedor_nombre || '—'}</span></td>
							{/if}
							<td class="td-act">
								{#if f.tipo === 'recibido'}
									{#if f.estado === 'cartera'}
										<button class="btn-accion btn-depositar" onclick={() => abrirDepositar(f)}>Depositar</button>
										<button class="btn-accion btn-endosar" onclick={() => abrirEndosar(f)}>Endosar</button>
										<button class="btn-accion btn-rechazar" onclick={() => accionRechazar(f)}>Rechazar</button>
										<button class="btn-accion btn-del" onclick={() => accionEliminar(f)}>✕</button>
									{:else if ['depositado', 'rechazado', 'endosado'].includes(f.estado)}
										<button class="btn-accion btn-reactivar" onclick={() => accionReactivar(f)}>Reactivar</button>
									{/if}
								{:else if f.estado === 'pendiente'}
									<button class="btn-accion btn-debitado" onclick={() => accionDebitado(f)}>Debitado</button>
									<button class="btn-accion btn-rechazar" onclick={() => accionRechazar(f)}>Rechazar</button>
									<button class="btn-accion btn-del" onclick={() => accionEliminar(f)}>✕</button>
								{:else if ['debitado', 'rechazado'].includes(f.estado)}
									<button class="btn-accion btn-reactivar" onclick={() => accionReactivar(f)}>Reactivar</button>
								{/if}
							</td>
						</tr>
					{/each}
				{/if}
			</tbody>
		</table>
	</div>
</div>

{#if modalNuevo}
	<div class="chq-overlay open" role="presentation" onclick={(e) => e.target === e.currentTarget && (modalNuevo = false)}>
		<div class="chq-modal chq-modal-lg">
			<div class="chq-mhead">
				<h3>{nTipo === 'recibido' ? 'Nuevo cheque recibido' : 'Nuevo cheque emitido'}</h3>
				<button class="chq-mclose" onclick={() => (modalNuevo = false)}>×</button>
			</div>
			<div class="chq-mbody">
				<div class="chq-fg-row">
					<div class="chq-fg">
						<label class="chq-lbl" for="n-tipo">Tipo *</label>
						<select class="chq-sel" id="n-tipo" bind:value={nTipo}>
							<option value="recibido">Recibido (de terceros)</option>
							<option value="emitido">Emitido (propio)</option>
						</select>
					</div>
					<div class="chq-fg">
						<label class="chq-lbl" for="n-monto">Monto *</label>
						<input id="n-monto" type="number" class="chq-inp" min="0.01" step="0.01" placeholder="0,00" bind:value={nMonto} />
					</div>
				</div>
				<div class="chq-fg-row">
					<div class="chq-fg">
						<label class="chq-lbl" for="n-numero">Número de cheque *</label>
						<input id="n-numero" type="text" class="chq-inp" placeholder="00012345" maxlength="30" bind:value={nNumero} />
					</div>
					<div class="chq-fg">
						<label class="chq-lbl" for="n-banco">Banco *</label>
						<input id="n-banco" type="text" class="chq-inp" placeholder="Banco Nación…" maxlength="100" bind:value={nBanco} />
					</div>
				</div>
				<div class="chq-fg-row">
					{#if nTipo === 'recibido'}
						<div class="chq-fg">
							<label class="chq-lbl" for="n-librador">Librador (titular)</label>
							<input id="n-librador" type="text" class="chq-inp" placeholder="Nombre del firmante" maxlength="150" bind:value={nLibrador} />
						</div>
					{:else}
						<div class="chq-fg">
							<label class="chq-lbl" for="n-beneficiario">Beneficiario</label>
							<input id="n-beneficiario" type="text" class="chq-inp" placeholder="A quién se paga" maxlength="150" bind:value={nBeneficiario} />
						</div>
					{/if}
					<div class="chq-fg">
						<label class="chq-lbl" for="n-cuit">CUIT</label>
						<input id="n-cuit" type="text" class="chq-inp" placeholder="20-12345678-9" maxlength="13" bind:value={nCuit} />
					</div>
				</div>
				<div class="chq-fg-row">
					<div class="chq-fg">
						<label class="chq-lbl" for="n-fecha-emision">Fecha de emisión</label>
						<input id="n-fecha-emision" type="date" class="chq-inp" bind:value={nFechaEmision} />
					</div>
					<div class="chq-fg">
						<label class="chq-lbl" for="n-fecha-vencimiento">Fecha de vencimiento *</label>
						<input id="n-fecha-vencimiento" type="date" class="chq-inp" bind:value={nFechaVencimiento} />
					</div>
				</div>
				{#if nTipo === 'emitido'}
					<div class="chq-fg">
						<label class="chq-lbl" for="n-proveedor">Proveedor (a quién se emite)</label>
						<select class="chq-sel" id="n-proveedor" bind:value={nProveedor}>
							<option value="">— Seleccioná un proveedor —</option>
							{#each proveedores.filter((p) => p.activo !== 0) as p (p.id)}
								<option value={p.id}>{p.nombre}</option>
							{/each}
						</select>
					</div>
				{/if}
				<div class="chq-fg">
					<label class="chq-lbl" for="n-notas">Notas</label>
					<textarea id="n-notas" class="chq-textarea" placeholder="Observaciones opcionales…" bind:value={nNotas}></textarea>
				</div>
			</div>
			<div class="chq-mfoot">
				<button class="chq-btn-sec" onclick={() => (modalNuevo = false)}>Cancelar</button>
				<button class="chq-btn-pri" disabled={guardandoNuevo} onclick={guardarNuevo}>Guardar</button>
			</div>
		</div>
	</div>
{/if}

{#if modalDepositar}
	<div class="chq-overlay open" role="presentation" onclick={(e) => e.target === e.currentTarget && (modalDepositar = false)}>
		<div class="chq-modal">
			<div class="chq-mhead">
				<h3>Depositar cheque</h3>
				<button class="chq-mclose" onclick={() => (modalDepositar = false)}>×</button>
			</div>
			<div class="chq-mbody">
				<div class="chq-modal-info">{depInfo}</div>
				<div class="chq-fg">
					<label class="chq-lbl" for="dep-banco">Banco destino (dónde se deposita) *</label>
					<input id="dep-banco" type="text" class="chq-inp" placeholder="Banco Galicia — Cta. 123-456…" bind:value={depBanco} />
				</div>
				<div class="chq-fg">
					<label class="chq-lbl" for="dep-fecha">Fecha de depósito</label>
					<input id="dep-fecha" type="date" class="chq-inp" bind:value={depFecha} />
				</div>
			</div>
			<div class="chq-mfoot">
				<button class="chq-btn-sec" onclick={() => (modalDepositar = false)}>Cancelar</button>
				<button class="chq-btn-pri" onclick={confirmarDepositar}>Confirmar depósito</button>
			</div>
		</div>
	</div>
{/if}

{#if modalEndosar}
	<div class="chq-overlay open" role="presentation" onclick={(e) => e.target === e.currentTarget && (modalEndosar = false)}>
		<div class="chq-modal">
			<div class="chq-mhead">
				<h3>Endosar cheque a proveedor</h3>
				<button class="chq-mclose" onclick={() => (modalEndosar = false)}>×</button>
			</div>
			<div class="chq-mbody">
				<div class="chq-modal-info">{endInfo}</div>
				<div class="chq-fg">
					<label class="chq-lbl" for="end-proveedor">Proveedor *</label>
					<select class="chq-sel" id="end-proveedor" bind:value={endProveedor}>
						<option value="">— Seleccioná un proveedor —</option>
						{#each proveedores.filter((p) => p.activo !== 0) as p (p.id)}
							<option value={p.id}>{p.nombre}</option>
						{/each}
					</select>
				</div>
				<div class="chq-fg">
					<label class="chq-lbl" for="end-fecha">Fecha</label>
					<input id="end-fecha" type="date" class="chq-inp" bind:value={endFecha} />
				</div>
				<div class="chq-fg">
					<label class="chq-lbl" for="end-obs">Observaciones</label>
					<input id="end-obs" type="text" class="chq-inp" placeholder="Opcional" bind:value={endObs} />
				</div>
				<p class="chq-hint">Se registrará un abono en la cuenta corriente del proveedor seleccionado.</p>
			</div>
			<div class="chq-mfoot">
				<button class="chq-btn-sec" onclick={() => (modalEndosar = false)}>Cancelar</button>
				<button class="chq-btn-pri" onclick={confirmarEndosar}>Endosar</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.page-body {
		flex: 1;
		display: flex;
		flex-direction: column;
		overflow: hidden;
		background: var(--neo-bg-deep);
	}
	.chq-resumen {
		display: flex;
		flex-shrink: 0;
		background: var(--neo-bg);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	.chq-card {
		flex: 1;
		width: 25%;
		padding: 8px 18px;
		display: flex;
		flex-direction: column;
		justify-content: center;
		gap: 1px;
		border-right: 1px solid var(--borde);
	}
	.chq-card:last-child {
		border-right: none;
	}
	.chq-card-lbl {
		font-size: 10px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.chq-card-row {
		display: flex;
		align-items: baseline;
		gap: 8px;
	}
	.chq-card-val {
		font-size: 14px;
		font-weight: 700;
		color: var(--neo-text);
		font-variant-numeric: tabular-nums;
	}
	.chq-card-sub {
		font-size: 11px;
		color: var(--neo-text-3);
	}
	.chq-card.warn .chq-card-val {
		color: var(--neo-warning);
	}
	.chq-card.danger .chq-card-val {
		color: var(--neo-danger);
	}
	.chq-card.ok .chq-card-val {
		color: var(--neo-success);
	}
	.chq-toolbar {
		display: flex;
		gap: 8px;
		padding: 8px 14px;
		flex-shrink: 0;
		flex-wrap: wrap;
		align-items: center;
		background: var(--neo-bg);
		border-bottom: 1px solid var(--borde-fuerte);
	}
	.chq-tabs {
		display: flex;
		border: 1px solid var(--borde-fuerte);
		overflow: hidden;
	}
	.chq-tab {
		padding: 5px 16px;
		border: none;
		cursor: pointer;
		font-size: 12px;
		font-weight: 600;
		background: transparent;
		color: var(--neo-text-2);
		font-family: inherit;
	}
	.chq-tab + .chq-tab {
		border-left: 1px solid var(--borde-fuerte);
	}
	.chq-tab.active {
		background: var(--neo-accent);
		color: #fff;
	}
	.chq-sep {
		width: 1px;
		height: 20px;
		background: var(--color-bg-alt);
	}
	.chq-estado-sel {
		padding: 5px 8px;
		border: 1px solid var(--borde-fuerte);
		font-size: 12px;
		background: var(--neo-bg);
		color: var(--neo-text);
		font-family: inherit;
		outline: none;
		cursor: pointer;
	}
	.chq-busq {
		flex: 1;
		min-width: 160px;
		max-width: 260px;
		padding: 5px 10px;
		border: 1px solid var(--borde-fuerte);
		font-size: 12px;
		background: var(--neo-bg);
		color: var(--neo-text);
		outline: none;
		font-family: inherit;
	}
	.chq-date {
		padding: 5px 8px;
		border: 1px solid var(--borde-fuerte);
		font-size: 12px;
		font-family: inherit;
		background: var(--neo-bg);
		color: var(--neo-text);
		outline: none;
	}
	.chq-lbl-date {
		font-size: 11px;
		color: var(--neo-text-3);
	}
	.chq-table-wrap {
		flex: 1;
		overflow-y: auto;
		padding: 10px 16px 16px;
		min-height: 0;
	}
	table.chq-tbl {
		width: 100%;
		border-collapse: collapse;
		background: var(--neo-bg);
		border-radius: var(--neo-r-lg);
		box-shadow: var(--neo-e1);
		overflow: hidden;
	}
	table.chq-tbl thead th {
		padding: 9px 12px;
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--color-ink);
		border-bottom: 1px solid var(--borde-fuerte);
		text-align: left;
		background: var(--neo-bg);
		white-space: nowrap;
	}
	table.chq-tbl thead th.r {
		text-align: right;
	}
	table.chq-tbl tbody tr {
		border-bottom: 1px solid var(--borde-fuerte);
	}
	table.chq-tbl tbody tr:hover {
		background: var(--color-bg-alt);
	}
	table.chq-tbl tbody tr:last-child {
		border-bottom: none;
	}
	table.chq-tbl td {
		padding: 9px 12px;
		font-size: 13px;
		vertical-align: middle;
		white-space: nowrap;
	}
	table.chq-tbl td.r {
		text-align: right;
		font-variant-numeric: tabular-nums;
	}
	.td-mono {
		font-family: monospace;
		font-size: 12px;
	}
	.td-monto {
		font-weight: 700;
	}
	.td-act {
		text-align: right;
	}
	.venc-ok {
		color: var(--neo-success);
	}
	.venc-prox {
		color: var(--neo-warning);
		font-weight: 700;
	}
	.venc-venc {
		color: var(--neo-danger);
		font-weight: 700;
	}
	.estado-badge {
		display: inline-block;
		padding: 2px 8px;
		border-radius: 20px;
		font-size: 11px;
		font-weight: 700;
		letter-spacing: 0.3px;
		text-transform: uppercase;
	}
	.est-cartera {
		background: var(--primary-soft-2);
		color: var(--color-primary);
	}
	.est-depositado {
		background: rgba(39, 174, 96, 0.1);
		color: var(--neo-success);
	}
	.est-endosado {
		background: rgba(124, 58, 237, 0.1);
		color: #7c3aed;
	}
	.est-rechazado {
		background: rgba(185, 28, 28, 0.1);
		color: var(--neo-danger);
	}
	.est-pendiente {
		background: rgba(243, 156, 18, 0.1);
		color: var(--neo-warning);
	}
	.est-debitado {
		background: rgba(100, 116, 139, 0.1);
		color: #64748b;
	}
	.chq-empty {
		text-align: center;
		padding: 48px 20px;
		color: var(--neo-text-3);
		font-size: 14px;
	}
	.btn-accion {
		padding: 4px 9px;
		border: none;
		border-radius: var(--neo-r-xs);
		font-size: 11px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
		white-space: nowrap;
	}
	.btn-accion:hover {
		opacity: 0.8;
	}
	.btn-depositar {
		background: rgba(39, 174, 96, 0.15);
		color: var(--neo-success);
	}
	.btn-endosar {
		background: rgba(124, 58, 237, 0.15);
		color: #7c3aed;
	}
	.btn-rechazar {
		background: rgba(185, 28, 28, 0.1);
		color: var(--neo-danger);
	}
	.btn-debitado {
		background: rgba(39, 174, 96, 0.15);
		color: var(--neo-success);
	}
	.btn-reactivar {
		background: var(--color-bg-alt);
		color: var(--neo-text-2);
	}
	.btn-del {
		background: rgba(185, 28, 28, 0.1);
		color: var(--neo-danger);
	}
	.orig-badge {
		font-size: 11px;
		color: var(--neo-text-3);
		font-style: italic;
	}
	.chq-overlay {
		position: fixed;
		inset: 0;
		z-index: 1000;
		display: flex;
		align-items: center;
		justify-content: center;
		background: rgba(49, 52, 75, 0.48);
	}
	.chq-modal {
		background: #fff;
		border: 1px solid var(--borde-fuerte);
		box-shadow: 0 4px 24px rgba(0, 0, 0, 0.13);
		width: 480px;
		max-width: 95vw;
		display: flex;
		flex-direction: column;
		max-height: 90vh;
	}
	.chq-modal-lg {
		width: 560px;
	}
	.chq-mhead {
		padding: 14px 20px;
		background: var(--color-bg-alt);
		border-bottom: 1px solid var(--borde-fuerte);
		display: flex;
		align-items: center;
		justify-content: space-between;
	}
	.chq-mhead h3 {
		font-size: 13px;
		font-weight: 700;
		margin: 0;
		color: var(--neo-text);
	}
	.chq-mclose {
		background: none;
		border: none;
		cursor: pointer;
		font-size: 18px;
		color: var(--neo-text-3);
		padding: 2px 6px;
	}
	.chq-mbody {
		padding: 20px;
		overflow-y: auto;
		flex: 1;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.chq-mfoot {
		padding: 12px 20px;
		border-top: 1px solid var(--borde-fuerte);
		display: flex;
		justify-content: flex-end;
		gap: 8px;
	}
	.chq-fg {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.chq-fg-row {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 10px;
	}
	.chq-lbl {
		font-size: 10px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		color: var(--neo-text-3);
	}
	.chq-inp {
		padding: 8px 10px;
		border: 1px solid var(--borde-fuerte);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: #fff;
		color: var(--neo-text);
		width: 100%;
		box-sizing: border-box;
	}
	.chq-inp:focus {
		border-color: var(--color-primary);
	}
	.chq-sel {
		padding: 8px 10px;
		border: 1px solid var(--borde-fuerte);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: #fff;
		color: var(--neo-text);
		width: 100%;
	}
	.chq-textarea {
		padding: 8px 10px;
		border: 1px solid var(--borde-fuerte);
		font-size: 13px;
		font-family: inherit;
		outline: none;
		background: #fff;
		color: var(--neo-text);
		width: 100%;
		box-sizing: border-box;
		resize: vertical;
		min-height: 60px;
	}
	.chq-btn-pri {
		padding: 8px 20px;
		background: var(--neo-accent);
		color: #fff;
		border: none;
		cursor: pointer;
		font-size: 13px;
		font-weight: 600;
		font-family: inherit;
	}
	.chq-btn-pri:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
	.chq-btn-sec {
		padding: 8px 20px;
		background: #fff;
		color: var(--color-primary);
		border: 1px solid var(--color-primary);
		cursor: pointer;
		font-size: 13px;
		font-weight: 500;
		font-family: inherit;
	}
	.chq-hint {
		font-size: 11px;
		color: var(--neo-text-3);
	}
	.chq-modal-info {
		background: var(--color-bg-alt);
		padding: 10px 14px;
		font-size: 13px;
		border: 1px solid var(--borde-fuerte);
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
	}
</style>
