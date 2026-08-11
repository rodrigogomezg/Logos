<script lang="ts">
	import { contactModal, cerrarContacto, guardarContacto, eliminarContacto } from './contact-modal';

	const CONDICIONES_IVA = [
		'Responsable Inscripto',
		'Monotributista',
		'Consumidor Final',
		'Exento',
		'No Responsable'
	];

	function onKeydown(e: KeyboardEvent) {
		if (!$contactModal) return;
		if (e.key === 'Escape') {
			if ($contactModal.confirmarEliminar) contactModal.update((s) => (s ? { ...s, confirmarEliminar: false } : s));
			else cerrarContacto();
		}
	}
</script>

<svelte:window onkeydown={onKeydown} />

{#if $contactModal}
	{@const s = $contactModal}
	<div class="cm-overlay" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarContacto()}>
		<div class="cm-modal">
			<div class="cm-header">
				<h3>
					{s.form.id === null
						? s.tipo === 'clientes'
							? 'Nuevo cliente'
							: 'Nuevo proveedor'
						: s.tipo === 'clientes'
							? 'Editar cliente'
							: 'Editar proveedor'}
				</h3>
				<button class="cm-header-cerrar" onclick={cerrarContacto}>✕</button>
			</div>

			{#if s.cargando}
				<div class="cm-body" style="display:flex;align-items:center;justify-content:center;color:#9B9590">
					Cargando…
				</div>
			{:else}
				<div class="cm-body">
					<div class="cm-col">
						<span class="cm-section">Datos de contacto</span>
						<div class="cm-group">
							<label class="cm-label" for="cm-f-nombre">Nombre / Razón social *</label>
							<input id="cm-f-nombre" class="cm-input" bind:value={$contactModal.form.nombre} />
						</div>
						<div class="cm-row cm-row-2">
							<div class="cm-group">
								<label class="cm-label" for="cm-f-cuit">CUIT</label>
								<input
									id="cm-f-cuit"
									class="cm-input"
									placeholder="20-12345678-9"
									bind:value={$contactModal.form.cuit}
								/>
							</div>
							<div class="cm-group">
								<label class="cm-label" for="cm-f-iva">Condición IVA</label>
								<select id="cm-f-iva" class="cm-select" bind:value={$contactModal.form.condicion_iva}>
									<option value="">—</option>
									{#each CONDICIONES_IVA as c (c)}<option value={c}>{c}</option>{/each}
								</select>
							</div>
						</div>
						<div class="cm-row cm-row-2">
							<div class="cm-group">
								<label class="cm-label" for="cm-f-email">Email</label>
								<input id="cm-f-email" class="cm-input" type="email" bind:value={$contactModal.form.email} />
							</div>
							<div class="cm-group">
								<label class="cm-label" for="cm-f-tel">Teléfono</label>
								<input id="cm-f-tel" class="cm-input" bind:value={$contactModal.form.telefono} />
							</div>
						</div>
						<div class="cm-group">
							<label class="cm-label" for="cm-f-dom">Domicilio</label>
							<input id="cm-f-dom" class="cm-input" bind:value={$contactModal.form.domicilio} />
						</div>
						<div class="cm-row cm-row-2">
							<div class="cm-group">
								<label class="cm-label" for="cm-f-loc">Localidad</label>
								<input id="cm-f-loc" class="cm-input" bind:value={$contactModal.form.localidad} />
							</div>
							<div class="cm-group">
								<label class="cm-label" for="cm-f-prov">Provincia</label>
								<input id="cm-f-prov" class="cm-input" bind:value={$contactModal.form.provincia} />
							</div>
						</div>
						<div class="cm-group" style="flex:1;display:flex;flex-direction:column">
							<label class="cm-label" for="cm-f-obs">Observaciones</label>
							<textarea id="cm-f-obs" class="cm-textarea" bind:value={$contactModal.form.observaciones}
							></textarea>
						</div>
					</div>

					<div class="cm-col cm-col-right">
						<div style="display:flex;flex-direction:column;gap:10px">
							<span class="cm-section">Cuenta corriente</span>
							<label class="cm-toggle">
								<input type="checkbox" bind:checked={$contactModal.form.cc_habilitada} />
								<span class="cm-track"></span>
								<span class="cm-toggle-lbl">Habilitar cuenta corriente</span>
							</label>
							<div class="cm-group">
								<label class="cm-label" for="cm-f-limite">Límite de crédito ($)</label>
								<input
									id="cm-f-limite"
									class="cm-input"
									type="number"
									min="0"
									step="0.01"
									bind:value={$contactModal.form.limite_credito}
								/>
							</div>
							<div class="cm-group">
								<label class="cm-label" for="cm-f-plazo">Plazo de pago (días)</label>
								<input
									id="cm-f-plazo"
									class="cm-input"
									type="number"
									min="1"
									step="1"
									placeholder="Sin plazo"
									bind:value={$contactModal.form.plazo_pago_dias}
								/>
							</div>
						</div>

						{#if s.tipo === 'clientes'}
							<div style="display:flex;flex-direction:column;gap:10px">
								<span class="cm-section">Configuración comercial</span>
								<div class="cm-group">
									<label class="cm-label" for="cm-f-lista">Lista de precios</label>
									<select id="cm-f-lista" class="cm-select" bind:value={$contactModal.form.lista_precio_id}>
										<option value={null}>Sin lista asignada</option>
										{#each s.listas as l (l.id)}
											<option value={l.id}>{l.nombre} ({l.porcentaje >= 0 ? '+' : ''}{l.porcentaje}%)</option>
										{/each}
									</select>
								</div>
								<div class="cm-group">
									<label class="cm-label" for="cm-f-dto">Dto. extra (%)</label>
									<input
										id="cm-f-dto"
										class="cm-input"
										type="number"
										min="-100"
										max="100"
										step="0.01"
										bind:value={$contactModal.form.descuento_extra}
									/>
								</div>
							</div>
						{:else}
							<div style="display:flex;flex-direction:column;gap:10px">
								<span class="cm-section">Configuración comercial</span>
								<div class="cm-group">
									<label class="cm-label" for="cm-f-regla">Regla de precio</label>
									<select id="cm-f-regla" class="cm-select" bind:value={$contactModal.form.regla_precio_id}>
										<option value={null}>Sin regla asignada</option>
										{#each s.reglas as r (r.id)}
											<option value={r.id}>{r.nombre} (+{r.porcentaje_recargo}%)</option>
										{/each}
									</select>
								</div>
							</div>
						{/if}

						<div
							style="display:flex;flex-direction:column;gap:10px;margin-top:auto;padding-top:12px;border-top:1px solid #D5D0CA"
						>
							{#if s.error}<div style="color:#B91C1C;font-size:12px">{s.error}</div>{/if}
							<label class="cm-toggle">
								<input type="checkbox" bind:checked={$contactModal.form.activo} />
								<span class="cm-track"></span>
								<span class="cm-toggle-lbl">Contacto activo</span>
							</label>
						</div>
					</div>
				</div>
			{/if}

			<div class="cm-footer">
				{#if s.form.id !== null}
					<button
						class="cm-btn cm-btn-peligro"
						style="margin-right:auto"
						onclick={() => contactModal.update((st) => (st ? { ...st, confirmarEliminar: true } : st))}
						>Eliminar</button
					>
				{/if}
				<button class="cm-btn cm-btn-sec" onclick={cerrarContacto}>Cancelar</button>
				<button class="cm-btn cm-btn-ok" disabled={s.guardando || s.cargando} onclick={guardarContacto}>
					{s.guardando ? 'Guardando…' : 'Guardar'}
				</button>
			</div>
		</div>
	</div>

	{#if s.confirmarEliminar}
		<div class="cm-overlay">
			<div class="cm-modal cm-confirm">
				<div class="cm-header">
					<h3>Confirmar eliminación</h3>
					<button
						class="cm-header-cerrar"
						onclick={() => contactModal.update((st) => (st ? { ...st, confirmarEliminar: false } : st))}>✕</button
					>
				</div>
				<div class="cm-body">
					<p>¿Eliminar "{s.form.nombre}"? Esta acción no se puede deshacer.</p>
				</div>
				<div class="cm-footer">
					<button
						class="cm-btn cm-btn-sec"
						onclick={() => contactModal.update((st) => (st ? { ...st, confirmarEliminar: false } : st))}
						>Cancelar</button
					>
					<button class="cm-btn" style="background:#B91C1C;color:#fff;border-color:#B91C1C" onclick={eliminarContacto}
						>Eliminar</button
					>
				</div>
			</div>
		</div>
	{/if}
{/if}

<style>
	.cm-overlay {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 400;
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
</style>
