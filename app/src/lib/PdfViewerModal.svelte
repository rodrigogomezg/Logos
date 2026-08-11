<script lang="ts">
	import { pdfModal, cerrarPdf } from './pdf';

	function onKeydown(e: KeyboardEvent) {
		if ($pdfModal && e.key === 'Escape') cerrarPdf();
	}

	function imprimir() {
		const iframe = document.getElementById('pdf-viewer-iframe') as HTMLIFrameElement | null;
		iframe?.contentWindow?.print();
	}
</script>

<svelte:window onkeydown={onKeydown} />

{#if $pdfModal}
	<div class="pdf-overlay" role="presentation" onclick={(e) => e.target === e.currentTarget && cerrarPdf()}>
		<div class="pdf-modal">
			<div class="pdf-toolbar">
				{#if $pdfModal.printBtn}
					<button class="pdf-btn" onclick={imprimir}>Imprimir</button>
				{/if}
				{#if $pdfModal.downloadFilename}
					<a class="pdf-btn" href={$pdfModal.url} download={$pdfModal.downloadFilename}>Descargar</a>
				{/if}
				<button class="pdf-btn pdf-btn-cerrar" onclick={cerrarPdf}>✕ Cerrar</button>
			</div>
			<iframe id="pdf-viewer-iframe" src={$pdfModal.url} title="Vista previa PDF"></iframe>
		</div>
	</div>
{/if}

<style>
	.pdf-overlay {
		position: fixed;
		inset: 0;
		background: rgba(0, 0, 0, 0.6);
		z-index: 99996;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.pdf-modal {
		background: #fff;
		width: min(94vw, 900px);
		height: 92vh;
		display: flex;
		flex-direction: column;
		border-radius: 8px;
		overflow: hidden;
	}
	.pdf-toolbar {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
		padding: 10px;
		border-bottom: 1px solid var(--borde, #ddd);
		flex-shrink: 0;
	}
	.pdf-btn {
		padding: 7px 14px;
		border: 1px solid var(--borde-fuerte, #ccc);
		border-radius: 6px;
		background: #fff;
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
		font-family: inherit;
		text-decoration: none;
		color: var(--neo-text, #1a1a1a);
	}
	.pdf-btn-cerrar {
		color: #b91c1c;
		border-color: #b91c1c;
	}
	iframe {
		flex: 1;
		border: none;
	}
</style>
