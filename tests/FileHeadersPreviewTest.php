<?php
/**
 * Unit tests for spreadsheet/CSV reading helpers in process-upload.php:
 * - wpezimport_extract_file_headers()
 * - wpezimport_generate_file_preview()
 * - wpezimport_generate_preview_html()
 * and wpezimport_get_spreadsheet_reader() in process-import.php.
 *
 * XLSX fixtures are generated on the fly with PhpSpreadsheet so no binaries
 * need to live in the repository.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class FileHeadersPreviewTest extends WP_Mock\Tools\TestCase {

    /** @var string */
    private $csvPath;

    /** @var string */
    private $xlsxPath;

    /** @var string[] temp files to remove */
    private $tmpFiles = array();

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->csvPath  = __DIR__ . '/fixtures/test.csv';
        $this->xlsxPath = $this->makeXlsx(array(
            array('SKU', 'Name', 'Price', 'Category', 'Weight'),
            array('SKU-001', 'Mountain Bike', 399, 'Bikes', 17),
            array('SKU-002', 'City Bike', 299.5, 'Bikes', 15.5),
            array('SKU-003', 'Kids Bike', 149.99, 'Kids', 9),
            array('SKU-004', 'Helmet', 49, 'Accessories', 0.4),
            array('SKU-005', 'Lock', 19, 'Accessories', 0.3),
        ));

        WP_Mock::passthruFunction('esc_html');
        WP_Mock::passthruFunction('esc_attr');
        WP_Mock::passthruFunction('__');
    }

    public function tearDown(): void {
        foreach ($this->tmpFiles as $f) {
            if (file_exists($f)) @unlink($f);
        }
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function makeXlsx(array $rows) {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'wpez') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function makeTempFile($content, $ext) {
        $path = tempnam(sys_get_temp_dir(), 'wpez') . '.' . $ext;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    // =========================================================================
    // wpezimport_extract_file_headers()
    // =========================================================================

    public function testCsvHeaders() {
        $headers = wpezimport_extract_file_headers($this->csvPath, 'csv');
        $this->assertEquals(array('SKU', 'Name', 'Price', 'Category', 'Weight'), $headers);
    }

    public function testXlsxHeadersStartAtColumnA() {
        // Regression for the stringFromColumnIndex($col - 1) off-by-one:
        // the first header must be "SKU" (column A), not shifted.
        $headers = wpezimport_extract_file_headers($this->xlsxPath, 'xlsx');
        $this->assertEquals(array('SKU', 'Name', 'Price', 'Category', 'Weight'), $headers);
    }

    public function testCsvWithSemicolonDelimiter() {
        $path = $this->makeTempFile("a;b;c\n1;2;3\n", 'csv');
        $this->assertEquals(array('a', 'b', 'c'), wpezimport_extract_file_headers($path, 'csv', ';'));
    }

    public function testCsvWithTabDelimiterKeyword() {
        $path = $this->makeTempFile("a\tb\tc\n1\t2\t3\n", 'csv');
        $this->assertEquals(array('a', 'b', 'c'), wpezimport_extract_file_headers($path, 'csv', 'tab'));
    }

    public function testCsvWithCustomEnclosure() {
        $path = $this->makeTempFile("'first name','last name'\n'a','b'\n", 'csv');
        $this->assertEquals(array('first name', 'last name'), wpezimport_extract_file_headers($path, 'csv', ',', "'"));
    }

    public function testCsvHeadersAreTrimmedAndEmptyOnesDropped() {
        $path = $this->makeTempFile("  a  ,,b\n1,2,3\n", 'csv');
        $headers = wpezimport_extract_file_headers($path, 'csv');
        $this->assertEquals(array('a', 'b'), $headers);
        $this->assertSame(array(0, 1), array_keys($headers), 'Result must be re-indexed');
    }

    public function testCsvAllEmptyHeadersFallBackToGenericNames() {
        $path = $this->makeTempFile(",,\n1,2,3\n", 'csv');
        $this->assertEquals(array('Column 1', 'Column 2', 'Column 3'), wpezimport_extract_file_headers($path, 'csv'));
    }

    public function testUnreadableXlsxReturnsEmptyArray() {
        $path = $this->makeTempFile('this is not a spreadsheet', 'xlsx');
        $this->assertSame(array(), wpezimport_extract_file_headers($path, 'xlsx'));
    }

    // =========================================================================
    // wpezimport_generate_file_preview()
    // =========================================================================

    public function testCsvPreviewSkipsHeaderAndLimitsToThreeRows() {
        $rows = wpezimport_generate_file_preview($this->csvPath, 'csv');

        $this->assertCount(3, $rows);
        $this->assertEquals(array('SKU-001', 'Mountain Bike, 29"', '399.00', 'Bikes, Mountain', '17'), $rows[0]);
        $this->assertEquals('SKU-003', $rows[2][0]);
    }

    public function testXlsxPreviewSkipsHeaderAndLimitsToThreeRows() {
        $rows = wpezimport_generate_file_preview($this->xlsxPath, 'xlsx');

        $this->assertCount(3, $rows);
        // Column A of the first data row must be the SKU (off-by-one regression)
        $this->assertEquals('SKU-001', $rows[0][0]);
        $this->assertEquals('Mountain Bike', $rows[0][1]);
        $this->assertEquals(399, $rows[0][2]);
        $this->assertEquals('SKU-003', $rows[2][0]);
    }

    public function testXlsxPreviewWithFewerRowsThanLimit() {
        $path = $this->makeXlsx(array(array('h1', 'h2'), array('v1', 'v2')));
        $rows = wpezimport_generate_file_preview($path, 'xlsx');
        $this->assertEquals(array(array('v1', 'v2')), $rows);
    }

    public function testCsvPreviewWithTabDelimiter() {
        $path = $this->makeTempFile("a\tb\n1\t2\n3\t4\n", 'csv');
        $this->assertEquals(array(array('1', '2'), array('3', '4')), wpezimport_generate_file_preview($path, 'csv', 'tab'));
    }

    public function testPreviewOfMissingCsvReturnsEmpty() {
        $this->assertSame(array(), @wpezimport_generate_file_preview('/nonexistent/file.csv', 'csv'));
    }

    public function testPreviewOfBrokenXlsxReturnsErrorRows() {
        $path = $this->makeTempFile('garbage', 'xlsx');
        $rows = wpezimport_generate_file_preview($path, 'xlsx');
        $this->assertEquals(array('Error'), $rows[0]);
        $this->assertStringStartsWith('Failed to read Excel file', $rows[1][0]);
    }

    // =========================================================================
    // wpezimport_generate_preview_html()
    // =========================================================================

    public function testPreviewHtmlRendersHeadersAndRows() {
        $html = wpezimport_generate_preview_html(array('A', 'B'), array(array('1', '2'), array('3', '4')));

        $this->assertStringContainsString('<th>A</th><th>B</th>', $html);
        $this->assertEquals(2, substr_count($html, '<tr>') - 1, 'Two data rows expected');
        $this->assertStringContainsString('<td title="3">3</td>', $html);
        $this->assertStringNotContainsString('wpezimport-xml-info', $html);
    }

    public function testPreviewHtmlXmlModeShowsShortHeaderWithFullXpathTooltip() {
        $html = wpezimport_generate_preview_html(array('info/title/en', 'links/group/@id'), array(), true);

        $this->assertStringContainsString('<th title="info/title/en" data-xpath="info/title/en">en</th>', $html);
        $this->assertStringContainsString('data-xpath="links/group/@id">@id</th>', $html);
        $this->assertStringContainsString('wpezimport-xml-info', $html);
    }

    public function testPreviewHtmlEmptyDataShowsMessageWithColspan() {
        $html = wpezimport_generate_preview_html(array('A', 'B', 'C'), array());
        $this->assertStringContainsString('<td colspan="3">No data found in the file.</td>', $html);
    }

    public function testPreviewHtmlTruncatesLongCells() {
        $long = str_repeat('x', 300);
        $html = wpezimport_generate_preview_html(array('A'), array(array($long)));

        $this->assertStringContainsString(str_repeat('x', 250) . '...</td>', $html);
        $this->assertStringContainsString('title="' . $long . '"', $html);
    }

    public function testPreviewHtmlSkipsNonArrayRows() {
        $html = wpezimport_generate_preview_html(array('A'), array('not-a-row', array('ok')));
        $this->assertEquals(1, substr_count($html, '<td title='));
    }

    // =========================================================================
    // wpezimport_get_spreadsheet_reader()
    // =========================================================================

    public function testReaderForXlsx() {
        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class, wpezimport_get_spreadsheet_reader('xlsx'));
        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class, wpezimport_get_spreadsheet_reader('XLSX'));
    }

    public function testReaderForXls() {
        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Reader\Xls::class, wpezimport_get_spreadsheet_reader('xls'));
    }

    public function testReaderForCsvAppliesDelimiterAndEnclosure() {
        $reader = wpezimport_get_spreadsheet_reader('csv', ';', "'");
        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Reader\Csv::class, $reader);
        $this->assertEquals(';', $reader->getDelimiter());
        $this->assertEquals("'", $reader->getEnclosure());
    }

    public function testReaderForCsvTabKeyword() {
        $this->assertEquals("\t", wpezimport_get_spreadsheet_reader('csv', 'tab')->getDelimiter());
    }

    public function testReaderThrowsForUnsupportedExtension() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported file format: pdf');
        wpezimport_get_spreadsheet_reader('pdf');
    }

    public function testCsvReaderReadsFixtureCorrectly() {
        $reader = wpezimport_get_spreadsheet_reader('csv');
        $rows = $reader->load($this->csvPath)->getActiveSheet()->toArray();

        $this->assertEquals(array('SKU', 'Name', 'Price', 'Category', 'Weight'), $rows[0]);
        $this->assertEquals('Mountain Bike, 29"', $rows[1][1]);
        $this->assertCount(5, $rows);
    }
}
