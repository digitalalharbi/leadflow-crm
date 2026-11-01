<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A minimal, streaming XLSX writer: one sheet, rows of text and numbers, nothing else.
 *
 * Rows go to a temporary sheet file as they come, so a hundred thousand leads never sit in memory, and the package
 * is zipped once at the end. Text is written as inline strings — a cell that starts with "=" is shown, never
 * evaluated, so the CSV-injection concern CsvWriter handles cannot arise here. Right-to-left is set on the sheet
 * when asked, so an Arabic export opens the way it reads.
 */
class XlsxWriter
{
    /** @var resource */
    private $sheet;

    private string $sheetPath;

    private int $row = 0;

    public function __construct(private string $path, private string $title = 'Sheet1', private bool $rtl = false)
    {
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'xlsx');
        $handle = fopen($this->sheetPath, 'w');

        if ($handle === false) {
            throw new RuntimeException('Cannot open a temporary sheet.');
        }

        $this->sheet = $handle;
        fwrite($this->sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"'.($this->rtl ? ' rightToLeft="1"' : '').'>'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetData>');
    }

    /** @param array<int,mixed> $cells */
    public function writeRow(array $cells): void
    {
        $this->row++;
        $xml = '<row r="'.$this->row.'">';

        foreach (array_values($cells) as $i => $value) {
            $ref = self::column($i).$this->row;
            $style = $this->row === 1 ? ' s="1"' : '';

            if ($value === null || $value === '') {
                continue;
            }

            if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $xml .= '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';

                continue;
            }

            $text = self::clean((string) (is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : $value));
            $xml .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$text.'</t></is></c>';
        }

        fwrite($this->sheet, $xml.'</row>');
    }

    public function rows(): int
    {
        return max(0, $this->row - 1);
    }

    public function close(): void
    {
        fwrite($this->sheet, '</sheetData></worksheet>');
        fclose($this->sheet);

        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($this->sheetPath);

            throw new RuntimeException('Cannot write the workbook.');
        }

        $title = htmlspecialchars(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $this->title), 0, 31), ENT_XML1);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$title.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>');
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($this->sheetPath);
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    /** XML-escaped, with the control characters XML 1.0 forbids removed. */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
