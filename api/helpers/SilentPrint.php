<?php

// Imprime un PDF en silencio (sin diálogos) usando PDFtoPrinter.exe.
// Adobe Acrobat se descartó: se cuelga indefinidamente tanto desde Apache
// (corre como LocalSystem) como desde una sesión interactiva. PDFtoPrinter.exe
// fue validado en ambos contextos.
class SilentPrint {

    private const EXE = __DIR__ . '/../../tools/PDFtoPrinter.exe';

    public static function imprimir(string $pdfPath, string $impresora): bool {
        $cmd = '"' . self::EXE . '" "' . $pdfPath . '" "' . $impresora . '"';
        exec($cmd . ' 2>&1', $salida, $codigo);
        return $codigo === 0;
    }
}
