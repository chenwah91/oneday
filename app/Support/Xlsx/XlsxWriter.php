<?php

namespace App\Support\Xlsx;

use RuntimeException;
use ZipArchive;

// 极简 xlsx 写入器(W13-2):单 sheet、首行表头、零依赖(PHP 内建 ZipArchive)。
//
// xlsx 本质是一个 zip 包,最小可用集只有五个成员:
//   [Content_Types].xml / _rels/.rels / xl/workbook.xml / xl/_rels/workbook.xml.rels / xl/worksheets/sheet1.xml
// 刻意不做样式、不做多 sheet、不做 sharedStrings(写入侧字符串一律 inline string):
// 这是后台「导出定义 → 改 → 导回」的工作文件,不是报表产品,别贪功能。
//
// 单元格类型只有两种:
//   int / float      → 数字单元格(<v>)
//   其余非空标量     → inline string(<is><t>),JSON 文本原样进单元格
//   null / ''        → 不写该单元格(Excel 视为空)
class XlsxWriter
{
    // $rows:行的列表,每行是按列顺序排的标量数组;第一行放表头
    public static function write(string $path, array $rows): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建 xlsx 文件:' . $path);
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheetXml($rows));

        if (! $zip->close()) {
            throw new RuntimeException('xlsx 写入失败:' . $path);
        }
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    private static function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $r = 0;
        foreach ($rows as $row) {
            $r++;
            $xml .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($row as $value) {
                $ref = self::columnRef($c) . $r;
                $c++;

                if ($value === null || $value === '') {
                    continue; // 空单元格不写,读回时补 null
                }

                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '"><v>' . self::numberText($value) . '</v></c>';
                    continue;
                }

                // xml:space="preserve":JSON 文本里可能有前后空格 / 连续空格,Excel 不许吃掉
                $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    // 数字转文本:不能走 (string) 强转 —— 大数会变科学计数法(1.0E+9),Excel 读得懂但人读不懂。
    // 定义表的小数最多 4 位(DECIMAL(14,4)),6 位精度绰绰有余;去掉尾零让 12 显示成 12 而不是 12.000000
    private static function numberText(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }

    // 0 基列号 → 列字母(0=A,25=Z,26=AA)
    public static function columnRef(int $index): string
    {
        $ref = '';
        $n = $index;
        while (true) {
            $ref = chr(ord('A') + ($n % 26)) . $ref;
            $n = intdiv($n, 26) - 1;
            if ($n < 0) {
                break;
            }
        }

        return $ref;
    }
}
