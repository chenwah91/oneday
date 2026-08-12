<?php

namespace App\Support\Xlsx;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

// 极简 xlsx 读取器(W13-2):读第一个 sheet,零依赖(ZipArchive + SimpleXML)。
//
// 必须能读回「Excel 保存过」的文件,所以比写入器多支持几样:
//   - sharedStrings(Excel 保存时会把 inline string 收编进共享字符串表)
//   - inlineStr(本项目写入器的写法)
//   - 富文本 run(<is>/<si> 里拆成多段 <r><t>,取拼接结果)
//   - 公式单元格取缓存值 <v>(t="str" 是字符串缓存,其余按数字)
//   - 空单元格 / 跳列(按 r="C5" 引用定位,空洞补 null)
// 样式一律不管:导入只认值。
class XlsxReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    // 返回 [Excel 行号(1 基) => 单元格值数组(0 基列,空洞为 null)],按行号升序。
    // 值类型:数字单元格 → int|float;字符串 → string;布尔 → 1|0;空 → null
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('不是有效的 xlsx 文件(zip 打不开)');
        }

        try {
            $sheetXml = $zip->getFromName(self::firstSheetPath($zip));
            if ($sheetXml === false) {
                throw new RuntimeException('xlsx 里找不到工作表');
            }
            $shared = self::sharedStrings($zip);
        } finally {
            $zip->close();
        }

        $doc = self::parseXml($sheetXml, '工作表 XML 无法解析');
        $sheetData = $doc->children(self::NS_MAIN)->sheetData;

        $rows = [];
        $autoRow = 0;
        foreach ($sheetData->row as $row) {
            // 注意:经 children($ns) 取出的元素,数组式取属性会在**该命名空间**里找,
            // 而 r / t 这类属性是无命名空间的 —— 必须走 attributes()(默认 = 无命名空间)
            // r 属性是行号;个别生成器不写 r,按出现顺序顺延
            $rowNum = (int) ($row->attributes()['r'] ?? 0);
            $rowNum = $rowNum > 0 ? $rowNum : $autoRow + 1;
            $autoRow = $rowNum;

            $cells = [];
            $autoCol = -1;
            foreach ($row->children(self::NS_MAIN)->c as $cell) {
                $ref = (string) ($cell->attributes()['r'] ?? '');
                $col = $ref !== '' ? self::columnIndex($ref) : $autoCol + 1;
                $autoCol = $col;
                $cells[$col] = self::cellValue($cell, $shared);
            }

            if ($cells === []) {
                $rows[$rowNum] = [];
                continue;
            }

            // 空洞补 null,给出稠密的 0..max 列数组(导入按表头位置取值)
            $dense = [];
            $max = max(array_keys($cells));
            for ($i = 0; $i <= $max; $i++) {
                $dense[$i] = $cells[$i] ?? null;
            }
            $rows[$rowNum] = $dense;
        }

        ksort($rows);

        return $rows;
    }

    // 第一个 sheet 的包内路径:workbook.xml 找到首个 sheet 的 r:id,再去 rels 解析 Target。
    // Excel 重存后 sheet 文件名可能不是 sheet1.xml,所以不能写死;两步都失败才回落默认路径
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            return $fallback;
        }

        try {
            $workbook = self::parseXml($workbookXml, 'workbook.xml 无法解析');
            $rels = self::parseXml($relsXml, 'workbook.xml.rels 无法解析');
        } catch (RuntimeException) {
            return $fallback;
        }

        $sheet = $workbook->children(self::NS_MAIN)->sheets->sheet[0] ?? null;
        if ($sheet === null) {
            return $fallback;
        }
        $rid = (string) ($sheet->attributes(self::NS_REL)['id'] ?? '');

        foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
            // 属性同样要走 attributes()(见 read() 里的注释)
            if ((string) ($rel->attributes()['Id'] ?? '') !== $rid) {
                continue;
            }
            $target = (string) ($rel->attributes()['Target'] ?? '');

            // Target 可能是绝对(/xl/worksheets/sheet1.xml)或相对 xl/ 的路径
            return $target !== '' && $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
        }

        return $fallback;
    }

    // 共享字符串表:索引 => 文本。没有该成员(纯 inline / 纯数字的文件)时返回空表
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = self::parseXml($xml, 'sharedStrings.xml 无法解析');

        $strings = [];
        foreach ($doc->children(self::NS_MAIN)->si as $si) {
            $strings[] = self::textOf($si);
        }

        return $strings;
    }

    // <si> / <is> 的文本:直挂的 <t> 与富文本 run(<r><t>)拼接
    private static function textOf(SimpleXMLElement $node): string
    {
        $children = $node->children(self::NS_MAIN);

        $text = (string) $children->t;
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::NS_MAIN)->t;
        }

        return $text;
    }

    // 单元格取值:t 属性分流(s=共享字符串 / inlineStr / str=公式字符串缓存 / b=布尔 / 默认数字)。
    // 公式单元格(<f> + <v>)不重算公式,一律取缓存值 <v>
    private static function cellValue(SimpleXMLElement $cell, array $shared): int|float|string|null
    {
        $type = (string) ($cell->attributes()['t'] ?? '');
        $children = $cell->children(self::NS_MAIN);

        if ($type === 's') {
            return $shared[(int) $children->v] ?? '';
        }
        if ($type === 'inlineStr') {
            return self::textOf($children->is);
        }
        if ($type === 'str') {
            return (string) $children->v;
        }
        if ($type === 'b') {
            return (string) $children->v === '1' ? 1 : 0;
        }

        $raw = (string) $children->v;
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw)) {
            // 数字单元格里出现非数字缓存值:原样给回字符串,让导入侧按列规则拒绝
            return $raw;
        }

        // 整数还原成 int(等级 / 秒数这类列不希望拿到 3.0),其余 float
        return preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : (float) $raw;
    }

    // 列引用 → 0 基列号("C5" → 2;"AA1" → 26)
    public static function columnIndex(string $ref): int
    {
        $letters = rtrim(strtoupper($ref), '0123456789');
        $n = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $n = $n * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return $n - 1;
    }

    // libxml 静默解析:坏 XML 抛 RuntimeException,不让 warning 泄进响应
    private static function parseXml(string $xml, string $message): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            throw new RuntimeException($message);
        }

        return $doc;
    }
}
