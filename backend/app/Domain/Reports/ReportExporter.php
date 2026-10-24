<?php

namespace App\Domain\Reports;

use App\Models\ReportShare;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * A report as a file — the same numbers the page shows, built from the same payload, so a file can never disagree with
 * the screen and can never carry a section the page hides. CSV for a spreadsheet import, XLSX for a workbook with one
 * sheet per section, PDF for a document with the workspace's name on it.
 */
class ReportExporter
{
    /** @param array<string,mixed> $data the payload from LiveReport::build */
    public function download(ReportShare $share, array $data, string $format, Request $request): Response
    {
        $base = $this->filename($share);
        $tables = $this->tables($data);

        return match ($format) {
            'csv' => response($this->csv($tables), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$base.'.csv"']),
            'xlsx' => response($this->xlsx($tables), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$base.'.xlsx"']),
            'pdf' => response($this->pdf($share, $data, $tables), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$base.'.pdf"']),
            default => abort(422, 'Unknown format.'),
        };
    }

    /**
     * Every section of the payload as a table: a list of rows becomes columns, a bag of figures becomes name/value.
     *
     * @param  array<string,mixed>  $data
     * @return array<string, array{headers: list<string>, rows: list<list<string>>}>
     */
    public function tables(array $data): array
    {
        $tables = [];
        $meta = $data['report'] ?? [];
        $tables['report'] = ['headers' => ['name', 'value'], 'rows' => [
            ['name', (string) ($meta['name'] ?? '')], ['from', (string) ($meta['from'] ?? '')], ['to', (string) ($meta['to'] ?? '')],
            ['currency', (string) ($meta['currency'] ?? '')], ['generated_at', (string) ($meta['generated_at'] ?? '')],
        ]];

        foreach ($data as $key => $value) {
            if (in_array($key, ['report', 'sections', 'workspace', 'link'], true) || ! is_array($value)) {
                continue;
            }

            $tables[$key] = $this->table($value);
        }

        return $tables;
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    private function table(array $value): array
    {
        if ($value !== [] && array_is_list($value) && is_array($value[0] ?? null)) {
            $headers = [];
            foreach ($value as $row) {
                foreach (array_keys($this->flatten((array) $row)) as $h) {
                    if (! in_array($h, $headers, true)) {
                        $headers[] = $h;
                    }
                }
            }

            return ['headers' => $headers, 'rows' => array_map(function ($row) use ($headers) {
                $flat = $this->flatten((array) $row);

                return array_map(fn ($h) => $this->cell($flat[$h] ?? ''), $headers);
            }, $value)];
        }

        $rows = [];
        foreach ($this->flatten($value) as $name => $cell) {
            $rows[] = [(string) $name, $this->cell($cell)];
        }

        return ['headers' => ['name', 'value'], 'rows' => $rows];
    }

    /** @return array<string,mixed> */
    private function flatten(array $value, string $prefix = ''): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $name = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v)) {
                // A list of rows inside a row is summarised by its size; the page has the detail.
                $out += array_is_list($v) && is_array($v[0] ?? null) ? [$name.'.count' => count($v)] : $this->flatten($v, $name);
            } else {
                $out[$name] = $v;
            }
        }

        return $out;
    }

    private function cell(mixed $v): string
    {
        $s = match (true) {
            $v === null => '',
            is_bool($v) => $v ? '1' : '0',
            is_float($v) => rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'),
            default => (string) $v,
        };

        // A cell that starts like a formula is text, not a formula, when a spreadsheet opens it.
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@'], true) && ! is_numeric($s) ? "'".$s : $s;
    }

    private function csv(array $tables): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($tables as $name => $t) {
            fputcsv($h, ['# '.$name], ',', '"', '');
            fputcsv($h, $t['headers'], ',', '"', '');
            foreach ($t['rows'] as $row) {
                fputcsv($h, $row, ',', '"', '');
            }
            fputcsv($h, [], ',', '"', '');
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }

    private function xlsx(array $tables): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $names = [];
        $sheets = '';
        $rels = '';
        $types = '';
        $i = 0;

        foreach ($tables as $name => $t) {
            $i++;
            $safe = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name) ?: 'sheet'.$i, 0, 31);
            $names[] = $safe;
            $rowsXml = '';
            foreach (array_merge([$t['headers']], $t['rows']) as $r => $row) {
                $cells = '';
                foreach ($row as $c => $v) {
                    $ref = $this->column($c).($r + 1);
                    $cells .= is_numeric($v) && ! str_starts_with((string) $v, '0') || $v === '0'
                        ? '<c r="'.$ref.'"><v>'.$v.'</v></c>'
                        : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                }
                $rowsXml .= '<row r="'.($r + 1).'">'.$cells.'</row>';
            }
            $zip->addFromString("xl/worksheets/sheet$i.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetData>'.$rowsXml.'</sheetData></worksheet>');
            $sheets .= '<sheet name="'.htmlspecialchars($safe, ENT_XML1, 'UTF-8').'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$types.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function column(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    private function pdf(ReportShare $share, array $data, array $tables): string
    {
        $brand = ReportBranding::workspace($share, $share->tenant);
        $html = '<html dir="rtl"><body style="font-family:dejavusans;font-size:10pt">'
            .'<h1 style="color:'.$brand['color'].'">'.e($share->name).'</h1>'
            .'<p>'.e((string) $brand['name']).' — '.e((string) ($data['report']['from'] ?? '')).' → '.e((string) ($data['report']['to'] ?? '')).'</p>';

        foreach ($tables as $name => $t) {
            if ($name === 'report') {
                continue;
            }
            $html .= '<h3>'.e($name).'</h3><table border="1" cellpadding="4" style="border-collapse:collapse;width:100%"><tr>';
            foreach ($t['headers'] as $h) {
                $html .= '<th style="background:#eee">'.e($h).'</th>';
            }
            $html .= '</tr>';
            foreach (array_slice($t['rows'], 0, 200) as $row) {
                $html .= '<tr>'.implode('', array_map(fn ($c) => '<td>'.e($c).'</td>', $row)).'</tr>';
            }
            $html .= '</table>';
        }

        if ($brand['footer']) {
            $html .= '<p style="color:#666;font-size:8pt">'.e($brand['footer']).'</p>';
        }

        $pdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $pdf->WriteHTML($html.'</body></html>');

        return $pdf->Output('', 'S');
    }

    private function filename(ReportShare $share): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $share->name), '-');

        return ($slug !== '' ? substr($slug, 0, 60) : 'report').'-'.now()->format('Ymd');
    }
}
