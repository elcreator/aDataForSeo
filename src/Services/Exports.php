<?php

namespace Elcreator\aDataForSeo\Services;

use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Support\Lang;
use RuntimeException;
use ZipArchive;

/**
 * CSV and XLSX of the matrix, the keywords (with their cluster and whether
 * they count), the issues and the rankings — plus, in the XLSX, the
 * parameters and the method, so a spreadsheet explains its own numbers.
 * XLSX is written by hand (SpreadsheetML in a zip): no dependency for it.
 */
final class Exports
{
    public const KINDS = ['matrix', 'keywords', 'issues', 'rankings'];

    /** @return array{0: string[], 1: array<int, array<int, scalar|null>>} */
    public static function table(Project $project, string $kind): array
    {
        switch ($kind) {
            case 'matrix':
                $rows = Reports::matrix($project)['targets'];
                $head = ['tier', 'score', 'status', 'country', 'lang', 'geo', 'deal', 'type', 'primary_keyword', 'demand_real', 'demand_ambiguous', 'demand_origin', 'demand_raw', 'ratio', 'kd_serp', 'native_share', 'document_id', 'url', 'proposed_url'];

                return [$head, array_map(static fn ($r) => array_map(static fn ($h) => $r[$h] ?? null, $head), $rows)];
            case 'keywords':
                $head = ['keyword', 'market', 'lang', 'volume', 'counted', 'shared', 'cluster_id', 'target_id', 'core_keyword', 'kd_labs', 'cpc', 'intent', 'source', 'geo', 'deal', 'type', 'brand', 'origin'];
                $out = [];
                $page = 1;

                do {
                    $chunk = Reports::keywords($project, ['page' => $page++]);

                    foreach ($chunk['keywords'] as $k) {
                        $out[] = array_map(static fn ($h) => is_bool($k[$h] ?? null) ? (int) $k[$h] : ($k[$h] ?? null), $head);
                    }
                } while (count($chunk['keywords']) === Reports::PAGE && $page < 500);

                return [$head, $out];
            case 'issues':
                $head = ['severity', 'code', 'status', 'url', 'document_id', 'fix', 'source'];

                return [$head, array_map(static fn ($r) => array_map(static fn ($h) => $r[$h] ?? null, $head), Reports::issues($project, ['status' => 'all'])['issues'])];
            case 'rankings':
                $data = Reports::rankings($project);
                $head = array_merge(['keyword', 'url'], $data['dates']);

                return [$head, array_map(static fn ($r) => array_merge([$r['keyword'], $r['url']], array_map(static fn ($d) => $r['positions'][$d] ?? null, $data['dates'])), $data['rows'])];
        }

        throw WorkbenchException::invalid('error_export');
    }

    public static function csv(Project $project, string $kind): string
    {
        [$head, $rows] = self::table($project, $kind);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $head, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn ($v) => self::safeCell($v), $row), ',', '"', '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** A spreadsheet cannot start a formula from our data (CSV injection). */
    private static function safeCell(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }

    public static function xlsx(Project $project, string $kind): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(Lang::get('aDataForSeo::global.error_zip'));
        }

        [$head, $rows] = self::table($project, $kind);
        $sheets = [
            $kind => array_merge([$head], $rows),
            'parameters' => self::parameters($project),
            'method' => array_map(static fn ($line) => [$line], explode("\n", Lang::get('aDataForSeo::global.export_method'))),
        ];

        $file = tempnam(sys_get_temp_dir(), 'adfs');
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::OVERWRITE);
        $names = array_keys($sheets);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . implode('', array_map(static fn ($i) => '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names))) . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(static fn ($i, $n) => '<sheet name="' . htmlspecialchars(mb_substr($n, 0, 31), ENT_XML1) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', array_keys($names), $names)) . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(static fn ($i) => '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>', array_keys($names))) . '</Relationships>');

        foreach (array_values($sheets) as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', self::sheet($sheet));
        }

        $zip->close();
        $data = (string) file_get_contents($file);
        @unlink($file);

        return $data;
    }

    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';

            foreach (array_values($row) as $c => $value) {
                $ref = self::column($c) . ($r + 1);

                if ($value === null || $value === '') {
                    continue;
                }

                $xml .= is_int($value) || is_float($value)
                    ? '<c r="' . $ref . '"><v>' . $value . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars((string) $value, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function column(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26) . $name;
        }

        return $name;
    }

    private static function parameters(Project $project): array
    {
        $rows = [['parameter', 'value'], ['domain', $project->domain], ['k', \Elcreator\aDataForSeo\Analysis\Research\Priority::k($project)]];

        foreach (\Elcreator\aDataForSeo\Analysis\Research\Priority::weights($project) as $name => $value) {
            $rows[] = [$name, (float) $value];
        }

        foreach ($project->marketList() as $market) {
            $rows[] = ['market ' . $market['country'], implode(', ', (array) $market['languages'])];
        }

        return $rows;
    }
}
