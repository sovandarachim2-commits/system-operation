<?php
/**
 * Finance XLSX export helper – styles match print view (borders, green/red totals)
 * Uses ZipArchive (no PhpSpreadsheet). Fallback: CSV.
 */
function finance_xlsx_col($n) {
    $s = '';
    while ($n > 0) { $mod = ($n - 1) % 26; $s = chr(65 + $mod) . $s; $n = (int)(($n - $mod) / 26); }
    return $s;
}

function finance_create_xlsx($filePath, array $rows, array $rowStyles, $sheetName = 'Report', array $colWidths = []) {
    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create XLSX file');
    }
    // Styles: print colors – green #d1fae5, red #fee2e2, borders
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    // default, title, header, total-green, total-red, section, meta
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="6">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD1FAE5"/><bgColor rgb="FFD1FAE5"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor rgb="FFFEE2E2"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor rgb="FFF3F4F6"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor rgb="FFE5E7EB"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="4" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $styleMap = ['default' => 0, 'title' => 1, 'header' => 2, 'section' => 3, 'total-green' => 4, 'total-red' => 5, 'meta' => 6, 'cell' => 7, 'cell-right' => 8];

    $colsXml = '';
    if (!empty($colWidths)) {
        foreach (array_values($colWidths) as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(50, max(6, $w)) . '" customWidth="1"/>';
        }
        $colsXml = '<cols>' . $colsXml . '</cols>';
    }

    $sheetData = '';
    foreach ($rows as $ri => $row) {
        $vals = is_array($row) ? array_values($row) : [$row];
        $sheetData .= '<row r="' . ($ri + 1) . '">';
        foreach ($vals as $ci => $v) {
            $cellRef = finance_xlsx_col($ci + 1) . ($ri + 1);
            $si = $styleMap[$rowStyles[$ri] ?? 'default'] ?? 0;
            $str = (string)$v;
            if (is_numeric($v) && $v !== '' && $v !== null) {
                $sheetData .= '<c r="' . $cellRef . '" s="' . $si . '"><v>' . (float)$v . '</v></c>';
            } else {
                $sheetData .= '<c r="' . $cellRef . '" t="inlineStr" s="' . $si . '"><is><t>' . htmlspecialchars($str, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
            }
        }
        $sheetData .= '</row>';
    }

    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $colsXml . '<sheetData>' . $sheetData . '</sheetData></worksheet>';
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Finance Report</dc:title><dc:creator>Finance</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created></cp:coreProperties>';
    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Finance</Application></Properties>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('docProps/core.xml', $core);
    $zip->addFromString('docProps/app.xml', $app);
    $zip->close();
}
