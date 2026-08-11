<script lang="ts">
	import { confirmState, _resolverConfirm } from './confirm';

	function onKeydown(e: KeyboardEvent) {
		if (!$confirmState) return;
		if (e.key === 'Escape') _resolverConfirm(false);
		if (e.key === 'Enter') _resolverConfirm(true);
	}
</script>

<svelte:window onkeydown={onKeydown} />

{#if $confirmState}
	<div
		class="cf-overlay"
		role="presentation"
		onclick={(e) => e.target === e.currentTarget && _resolverConfirm(false)}
	>
		<div class="cf-modal">
			<div class="cf-titulo">{$confirmState.titulo}</div>
			<div class="cf-mensaje">{$confirmState.mensaje}</div>
			<div class="cf-botones">
				<button class="cf-btn cf-btn-sec" onclick={() => _resolverConfirm(false)}>Cancelar</button>
				<button
					class="cf-btn"
					class:cf-btn-danger={$confirmState.danger}
					class:cf-btn-ok={!$confirmState.danger}
					onclick={() => _resolverConfirm(true)}
				>
					{$confirmState.confirmLabel}
				</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.cf-overlay {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 99998;
	}
	.cf-modal {
		background: #fff;
		border-radius: 12px;
		padding: 24px;
		width: min(90vw, 420px);
		display: flex;
		flex-direction: column;
		gap: 14px;
		box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
	}
	.cf-titulo {
		font-size: 16px;
		font-weight: 700;
		color: var(--neo-text, #1a1a1a);
	}
	.cf-mensaje {
		font-size: 13px;
		color: var(--neo-text-2, #555);
		line-height: 1.5;
		white-space: pre-line;
	}
	.cf-botones {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
		margin-top: 6px;
	}
	.cf-btn {
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
	.cf-btn-ok {
		background: var(--color-primary, #2563eb);
		color: #fff;
	}
	.cf-btn-danger {
		background: #b91c1c;
		color: #fff;
	}
	.cf-btn-sec {
		background: #fff;
		color: var(--neo-text, #1a1a1a);
		border-color: #999;
	}
</style>
