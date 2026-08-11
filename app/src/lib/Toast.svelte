<script lang="ts">
	import { toasts } from './toast';

	const colores: Record<string, string> = {
		ok: '#15803d',
		err: '#B91C1C',
		info: '#2563eb',
		warn: '#b45309'
	};
</script>

<div class="toast-host">
	{#each $toasts as t (t.id)}
		<div class="toast-item" style="border-left-color:{colores[t.tipo]}">
			{t.mensaje}
		</div>
	{/each}
</div>

<style>
	.toast-host {
		position: fixed;
		bottom: 24px;
		right: 24px;
		z-index: 99997;
		display: flex;
		flex-direction: column;
		gap: 8px;
		pointer-events: none;
	}
	.toast-item {
		background: #1f2937;
		color: #fff;
		padding: 12px 18px;
		border-radius: 8px;
		border-left: 4px solid;
		font-size: 13px;
		box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
		max-width: 360px;
		animation: toast-in 150ms ease-out;
	}
	@keyframes toast-in {
		from {
			opacity: 0;
			transform: translateY(8px);
		}
		to {
			opacity: 1;
			transform: translateY(0);
		}
	}
</style>
