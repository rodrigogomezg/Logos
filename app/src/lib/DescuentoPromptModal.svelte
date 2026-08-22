<script lang="ts">
	import { descuentoPrompt, resolverDescuentoPrompt } from './descuento-prompt';

	function onKeydown(e: KeyboardEvent) {
		if (!$descuentoPrompt) return;
		if (e.key === 'Escape' || e.key === 'Enter') resolverDescuentoPrompt(false);
	}
</script>

<svelte:window onkeydown={onKeydown} />

{#if $descuentoPrompt}
	<div class="dp-overlay" role="presentation" onclick={(e) => e.target === e.currentTarget && resolverDescuentoPrompt(false)}>
		<div class="dp-modal">
			<div class="dp-titulo">Este comprobante tiene descuentos aplicados</div>
			<div class="dp-mensaje">¿Querés mostrarlos en el documento? Si elegís ocultarlos, los ítems y el total se imprimen sin ningún rastro del descuento.</div>
			<div class="dp-botones">
				<button class="dp-btn dp-btn-sec" onclick={() => resolverDescuentoPrompt(true)}>Ocultar descuentos</button>
				<button class="dp-btn dp-btn-ok" onclick={() => resolverDescuentoPrompt(false)}>Mostrar descuentos</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.dp-overlay {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 99998;
	}
	.dp-modal {
		background: #fff;
		border-radius: 12px;
		padding: 24px;
		width: min(90vw, 440px);
		display: flex;
		flex-direction: column;
		gap: 14px;
		box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
	}
	.dp-titulo {
		font-size: 16px;
		font-weight: 700;
		color: var(--neo-text, #1a1a1a);
	}
	.dp-mensaje {
		font-size: 13px;
		color: var(--neo-text-2, #555);
		line-height: 1.5;
	}
	.dp-botones {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
		margin-top: 6px;
	}
	.dp-btn {
		padding: 9px 18px;
		border: 1.5px solid transparent;
		border-radius: 8px;
		font-size: 12px;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		cursor: pointer;
		font-family: inherit;
	}
	.dp-btn-ok {
		background: var(--color-primary, #2563eb);
		color: #fff;
	}
	.dp-btn-sec {
		background: #fff;
		color: var(--neo-text, #1a1a1a);
		border-color: #999;
	}
</style>
