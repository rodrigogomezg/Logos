// Port de abrirPdfModal() de pos/helpers.js.
import { writable } from 'svelte/store';

export interface PdfModalState {
	url: string;
	printBtn: boolean;
	downloadFilename: string | null;
}

export const pdfModal = writable<PdfModalState | null>(null);

export function abrirPdf(
	blob: Blob,
	opts: { printBtn?: boolean; downloadFilename?: string | null } = {}
): void {
	const url = URL.createObjectURL(blob);
	pdfModal.set({
		url,
		printBtn: opts.printBtn ?? false,
		downloadFilename: opts.downloadFilename ?? null
	});
}

export function cerrarPdf(): void {
	pdfModal.update((s) => {
		if (s) URL.revokeObjectURL(s.url);
		return null;
	});
}
