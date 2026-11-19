<?php

namespace App\Modules\Gudang;

use Illuminate\Validation\ValidationException;

/** Small vector-only PDF writer for Code 128 labels, one label per page. */
class BarcodeLabelPdf
{
    public function render(array $labels, int $widthMm, int $heightMm): string
    {
        $width = $widthMm * 72 / 25.4;
        $height = $heightMm * 72 / 25.4;
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pages = [];
        foreach ($labels as $index => $label) {
            if (strlen($label['code']) > 100 || ! preg_match('/^[\x20-\x7E]+$/D', $label['code'])) {
                throw ValidationException::withMessages(['labels' => 'Kode label harus 1 sampai 100 karakter ASCII cetak.']);
            }
            $pageId = 4 + $index * 2;
            $streamId = $pageId + 1;
            $pages[] = $pageId.' 0 R';
            preg_match('/viewBox="0 0 (\d+) 50"/', $label['svg'], $viewBox);
            preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)" height="50"\/>/', $label['svg'], $bars, PREG_SET_ORDER);
            $scale = ($width - 16) / (int) $viewBox[1];
            if ($scale < 0.4) {
                throw ValidationException::withMessages(['width' => 'Label terlalu sempit untuk barcode ini. Pilih lebar label yang lebih besar atau gunakan kode yang lebih pendek.']);
            }
            $barHeight = max(10, $height - 36);
            $commands = "0 0 0 rg\n";
            foreach ($bars as $bar) {
                $commands .= sprintf("%.3F %.3F %.3F %.3F re f\n", 8 + (int) $bar[1] * $scale, 17, (int) $bar[2] * $scale, $barHeight);
            }
            $name = $this->text($label['name'], 35);
            $code = $this->text($label['code'], 100);
            // Helvetica is a proportional font; 0.7 em per character is a conservative fit.
            $codeFont = min(8, ($width - 16) / max(1, strlen($label['code']) * 0.7));
            $nameFont = min(8, ($width - 16) / max(1, strlen($name) * 0.7));
            $commands .= sprintf("BT /F1 %.3F Tf 8 %.3F Td (%s) Tj ET\n", $nameFont, $height - 10, $name);
            $commands .= sprintf("BT /F1 %.3F Tf 8 5 Td (%s) Tj ET\n", $codeFont, $code);
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3F %.3F] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>', $width, $height, $streamId);
            $objects[$streamId] = '<< /Length '.strlen($commands).">>\nstream\n".$commands.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ', $pages).'] >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function text(string $value, int $limit): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '';
        $value = substr(preg_replace('/[^\x20-\x7E]/', '', $value), 0, $limit);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
