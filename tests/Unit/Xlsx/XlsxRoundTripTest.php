<?php

namespace Tests\Unit\Xlsx;

use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

// XlsxWriter / XlsxReader 的往返守门测试(W13-2):
// 写出去的文件必须原样读得回来(中文 / 整数 / 小数 / JSON 文本 / 空格),
// 且读取器要吃得下「Excel 保存过」的文件形态(sharedStrings / 富文本 run / 公式缓存值 / 非默认 sheet 文件名)。
class XlsxRoundTripTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->tempFiles[] = $file;

        return $file;
    }

    public function test_round_trip_preserves_chinese_numbers_and_json_text(): void
    {
        $rows = [
            ['building_id', '名称', 'level', 'capacity', 'output_json'],
            ['F01', '采集营地', 1, 24.3, '[{"resource":"berries","rate_per_min":8}]'],
            ['H01', '茅屋(测试)', 2, 0.5, '{"wood":12,"money":8}'],
        ];

        $path = $this->tempFile();
        XlsxWriter::write($path, $rows);
        $read = XlsxReader::read($path);

        // 表头原样
        $this->assertSame(['building_id', '名称', 'level', 'capacity', 'output_json'], $read[1]);

        // 中文往返不失真;整数回来还是 int(不是 1.0);小数按值相等;JSON 文本一个字符不差
        $this->assertSame('F01', $read[2][0]);
        $this->assertSame('采集营地', $read[2][1]);
        $this->assertSame(1, $read[2][2]);
        $this->assertEqualsWithDelta(24.3, $read[2][3], 1e-9);
        $this->assertSame('[{"resource":"berries","rate_per_min":8}]', $read[2][4]);
        $this->assertSame('{"wood":12,"money":8}', $read[3][4]);
        $this->assertSame('茅屋(测试)', $read[3][1]);
    }

    // 空单元格:写入跳过、读回补 null;'' 与 null 同样按空处理
    public function test_empty_cells_round_trip_as_null(): void
    {
        $path = $this->tempFile();
        XlsxWriter::write($path, [
            ['a', null, 'c'],
            [null, 2, null],
            ['x', '', 'z'],
        ]);

        $read = XlsxReader::read($path);

        $this->assertSame(['a', null, 'c'], $read[1]);
        // 行尾的空洞没有锚点,读回自然截断到最后一个有值的列
        $this->assertSame([null, 2], $read[2]);
        $this->assertSame(['x', null, 'z'], $read[3]);
    }

    // 前后空格与连续空格必须保真(JSON 文本里空格是内容的一部分,xml:space="preserve" 守着这一条)
    public function test_whitespace_is_preserved(): void
    {
        $path = $this->tempFile();
        XlsxWriter::write($path, [['  前后有空格  ', 'a  b']]);

        $read = XlsxReader::read($path);

        $this->assertSame('  前后有空格  ', $read[1][0]);
        $this->assertSame('a  b', $read[1][1]);
    }

    // Excel 保存过的文件长相与写入器不同:字符串收编进 sharedStrings(含富文本 run)、
    // sheet 文件名可能不是 sheet1.xml、公式单元格只剩缓存值。手工构造一个最小样本逐项验证
    public function test_reads_excel_style_file_with_shared_strings_and_formula_cache(): void
    {
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $path = $this->tempFile();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . $main . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="数据" sheetId="1" r:id="rId9"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/data.xml"/>'
            . '</Relationships>');
        // 富文本 run:一个字符串被拆成多段 <r><t>,读取器要拼回整串
        $zip->addFromString('xl/sharedStrings.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="' . $main . '" count="2" uniqueCount="2">'
            . '<si><t>你好</t></si>'
            . '<si><r><t>wor</t></r><r><t>ld</t></r></si>'
            . '</sst>');
        $zip->addFromString('xl/worksheets/data.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . $main . '"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            // A2 数字;B2 公式的字符串缓存(t="str");C2 公式的数字缓存(取 <v> 不重算 <f>)
            . '<row r="2"><c r="A2"><v>42</v></c>'
            . '<c r="B2" t="str"><f>CONCATENATE("a","b")</f><v>ab</v></c>'
            . '<c r="C2"><f>A2+1.5</f><v>43.5</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();

        $read = XlsxReader::read($path);

        $this->assertSame(['你好', 'world'], $read[1]);
        $this->assertSame(42, $read[2][0]);
        $this->assertSame('ab', $read[2][1]);
        $this->assertEqualsWithDelta(43.5, $read[2][2], 1e-9);
    }

    // 跳列(r="C3" 直接出现)要按引用定位,中间空洞补 null;跳行保留 Excel 行号
    public function test_sparse_cells_are_positioned_by_reference(): void
    {
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $path = $this->tempFile();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . $main . '"><sheetData>'
            . '<row r="3"><c r="C3"><v>7</v></c></row>'
            . '</sheetData></worksheet>');
        $zip->close();

        $read = XlsxReader::read($path);

        $this->assertArrayNotHasKey(1, $read);
        $this->assertSame([null, null, 7], $read[3]);
    }

    // 非 zip 文件必须报「不是有效的 xlsx」而不是奇怪的 warning
    public function test_rejects_non_zip_file(): void
    {
        $path = $this->tempFile();
        file_put_contents($path, '这不是一个 zip');

        $this->expectException(\RuntimeException::class);
        XlsxReader::read($path);
    }
}
