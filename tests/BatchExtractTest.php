<?php
/**
 * Unit tests for batch extract helper functions
 *
 * Tests the pure helper functions in import-batch.php:
 * - wpezimport_batch_extract_value() (XML)
 * - wpezimport_batch_extract_value_csv() (CSV/XLS)
 * - wpezimport_batch_extract_unique_value() (XML)
 * - wpezimport_batch_extract_unique_value_csv() (CSV/XLS)
 */

class BatchExtractTest extends WP_Mock\Tools\TestCase {

    /**
     * @var SimpleXMLElement Test XML item
     */
    private $xmlItem;

    /**
     * @var array Test CSV row (associative array)
     */
    private $csvRow;

    /**
     * Set up the test
     */
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        // Create a sample XML item for testing
        $xml_string = <<<XML
<product>
    <id>12345</id>
    <code>SKU-ABC-001</code>
    <ean_code>8001446121702</ean_code>
    <info>
        <title>
            <en>Mountain Bike</en>
            <lv>Kalnu velosipēds</lv>
        </title>
        <description>
            <en>A great mountain bike</en>
        </description>
    </info>
    <prices>
        <price>
            <dealer>399.00</dealer>
            <retail>499.00</retail>
        </price>
    </prices>
    <delivery>
        <size_weight>20</size_weight>
    </delivery>
</product>
XML;
        $this->xmlItem = simplexml_load_string($xml_string);

        // Create a sample CSV row for testing
        $this->csvRow = array(
            'ID'          => '12345',
            'SKU'         => 'SKU-ABC-001',
            'EAN'         => '8001446121702',
            'Title'       => 'Mountain Bike',
            'Description' => 'A great mountain bike',
            'Price'       => '399.00',
            'Weight'      => '20',
            'Category'    => 'Bikes > Mountain',
            'Tags'        => 'outdoor, sport, bike',
            'Image URL'   => 'http://example.com/bike.jpg',
        );
    }

    /**
     * Tear down after the test
     */
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // =========================================================================
    // Tests for wpezimport_batch_extract_value() — XML
    // =========================================================================

    /**
     * Test extracting value with curly-brace XPath pattern {expr}
     */
    public function testExtractValueXmlCurlyBracePattern() {
        $value = wpezimport_batch_extract_value($this->xmlItem, '{id}');
        $this->assertEquals('12345', $value);
    }

    /**
     * Test extracting nested value with curly-brace XPath pattern
     */
    public function testExtractValueXmlCurlyBraceNested() {
        $value = wpezimport_batch_extract_value($this->xmlItem, '{info/title/en}');
        $this->assertEquals('Mountain Bike', $value);
    }

    /**
     * Test extracting value with bare XPath (no braces, contains slash)
     */
    public function testExtractValueXmlBareXpath() {
        $value = wpezimport_batch_extract_value($this->xmlItem, 'info/title/lv');
        $this->assertEquals('Kalnu velosipēds', $value);
    }

    /**
     * Test extracting value with direct property name (no slash, no braces)
     */
    public function testExtractValueXmlDirectProperty() {
        $value = wpezimport_batch_extract_value($this->xmlItem, 'code');
        $this->assertEquals('SKU-ABC-001', $value);
    }

    /**
     * Test extracting value for non-existent element returns empty string
     */
    public function testExtractValueXmlNonExistent() {
        $value = wpezimport_batch_extract_value($this->xmlItem, '{nonexistent}');
        $this->assertEquals('', $value);
    }

    /**
     * Test extracting deeply nested value
     */
    public function testExtractValueXmlDeeplyNested() {
        $value = wpezimport_batch_extract_value($this->xmlItem, '{prices/price/dealer}');
        $this->assertEquals('399.00', $value);
    }

    /**
     * Test extracting value with empty xpath returns empty string
     */
    public function testExtractValueXmlEmptyXpath() {
        $value = wpezimport_batch_extract_value($this->xmlItem, '');
        $this->assertEquals('', $value);
    }

    /**
     * Test extracting with indexed XPath
     */
    public function testExtractValueXmlIndexedXpath() {
        $value = wpezimport_batch_extract_value($this->xmlItem, '{id[1]}');
        $this->assertEquals('12345', $value);
    }

    // =========================================================================
    // Tests for wpezimport_batch_extract_value_csv() — CSV/XLS
    // =========================================================================

    /**
     * Test extracting CSV value by column name
     */
    public function testExtractValueCsvSimple() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, 'ID');
        $this->assertEquals('12345', $value);
    }

    /**
     * Test extracting CSV value with curly braces stripped
     */
    public function testExtractValueCsvCurlyBraces() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, '{Title}');
        $this->assertEquals('Mountain Bike', $value);
    }

    /**
     * Test extracting CSV value with whitespace trimming
     */
    public function testExtractValueCsvWhitespaceTrimmed() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, '  SKU  ');
        $this->assertEquals('SKU-ABC-001', $value);
    }

    /**
     * Test extracting CSV value with braces and whitespace
     */
    public function testExtractValueCsvCurlyBracesAndWhitespace() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, '{ Price }');
        $this->assertEquals('399.00', $value);
    }

    /**
     * Test extracting CSV value for non-existent column returns empty string
     */
    public function testExtractValueCsvNonExistent() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, 'NonExistent');
        $this->assertEquals('', $value);
    }

    /**
     * Test extracting CSV value with column name containing spaces
     */
    public function testExtractValueCsvColumnWithSpaces() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, 'Image URL');
        $this->assertEquals('http://example.com/bike.jpg', $value);
    }

    /**
     * Test extracting CSV value with braces around spaced column name
     */
    public function testExtractValueCsvBracesAroundSpacedColumn() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, '{Image URL}');
        $this->assertEquals('http://example.com/bike.jpg', $value);
    }

    /**
     * Test extracting CSV value with empty column name returns empty string
     */
    public function testExtractValueCsvEmptyColumnName() {
        $value = wpezimport_batch_extract_value_csv($this->csvRow, '');
        $this->assertEquals('', $value);
    }

    /**
     * Test all columns are accessible
     */
    public function testExtractValueCsvAllColumns() {
        foreach ($this->csvRow as $col => $expected) {
            $value = wpezimport_batch_extract_value_csv($this->csvRow, $col);
            $this->assertEquals($expected, $value, "Column '$col' should return '$expected'");
        }
    }

    // =========================================================================
    // Tests for wpezimport_batch_extract_unique_value() — XML
    // =========================================================================

    /**
     * Test extracting unique value with XPath pattern
     */
    public function testExtractUniqueValueXmlXpath() {
        $value = wpezimport_batch_extract_unique_value($this->xmlItem, '{id}', array());
        $this->assertEquals('12345', $value);
    }

    /**
     * Test extracting unique value via mapping field key fallback
     */
    public function testExtractUniqueValueXmlMappingFallback() {
        $mappings = array(
            'my_unique_field' => '{ean_code}',
        );
        $value = wpezimport_batch_extract_unique_value($this->xmlItem, 'my_unique_field', $mappings);
        $this->assertEquals('8001446121702', $value);
    }

    /**
     * Test that direct XPath match takes priority over mapping fallback
     */
    public function testExtractUniqueValueXmlDirectPriority() {
        $mappings = array(
            'id' => '{code}', // mapping for 'id' points to code
        );
        // Since {id} is a valid XPath on the item, it should return '12345', not 'SKU-ABC-001'
        $value = wpezimport_batch_extract_unique_value($this->xmlItem, '{id}', $mappings);
        $this->assertEquals('12345', $value);
    }

    /**
     * Test non-matching identifier returns null
     */
    public function testExtractUniqueValueXmlNoMatch() {
        $value = wpezimport_batch_extract_unique_value($this->xmlItem, '{nonexistent}', array());
        $this->assertNull($value);
    }

    // =========================================================================
    // Tests for wpezimport_batch_extract_unique_value_csv() — CSV/XLS
    // =========================================================================

    /**
     * Test extracting unique value by direct column name
     */
    public function testExtractUniqueValueCsvDirect() {
        $value = wpezimport_batch_extract_unique_value_csv($this->csvRow, 'ID', array());
        $this->assertEquals('12345', $value);
    }

    /**
     * Test extracting unique value with curly braces
     */
    public function testExtractUniqueValueCsvCurlyBraces() {
        $value = wpezimport_batch_extract_unique_value_csv($this->csvRow, '{EAN}', array());
        $this->assertEquals('8001446121702', $value);
    }

    /**
     * Test extracting unique value via mapping field key fallback
     */
    public function testExtractUniqueValueCsvMappingFallback() {
        $mappings = array(
            'product_sku' => 'SKU',
        );
        $value = wpezimport_batch_extract_unique_value_csv($this->csvRow, 'product_sku', $mappings);
        $this->assertEquals('SKU-ABC-001', $value);
    }

    /**
     * Test that direct column match takes priority over mapping fallback
     */
    public function testExtractUniqueValueCsvDirectPriority() {
        $mappings = array(
            'ID' => 'SKU', // mapping for 'ID' points to SKU column
        );
        // 'ID' is a real column in the row, so it returns '12345' directly
        $value = wpezimport_batch_extract_unique_value_csv($this->csvRow, 'ID', $mappings);
        $this->assertEquals('12345', $value);
    }

    /**
     * Test non-matching identifier returns null
     */
    public function testExtractUniqueValueCsvNoMatch() {
        $value = wpezimport_batch_extract_unique_value_csv($this->csvRow, 'nonexistent', array());
        $this->assertNull($value);
    }

    /**
     * Test non-matching identifier with non-matching mapping returns null
     */
    public function testExtractUniqueValueCsvNoMatchWithMapping() {
        $mappings = array(
            'nonexistent' => 'also_nonexistent',
        );
        $value = wpezimport_batch_extract_unique_value_csv($this->csvRow, 'nonexistent', $mappings);
        $this->assertNull($value);
    }

    // =========================================================================
    // Tests for wpezimport_batch_extract_value with real test.xml fixture
    // =========================================================================

    /**
     * Test batch extract value against the real test.xml fixture
     */
    public function testExtractValueXmlWithTestFixture() {
        $xml = simplexml_load_file(dirname(__DIR__) . '/tests/fixtures/test.xml');
        $items = $xml->xpath('//product_output');

        $this->assertNotEmpty($items);

        $item = $items[0];

        // Test various extraction patterns against real data
        $this->assertEquals('189389', wpezimport_batch_extract_value($item, '{id}'));
        $this->assertEquals('189389', wpezimport_batch_extract_value($item, '{id[1]}'));
        $this->assertEquals('SZ1U29424DA BLACK/BLUE', wpezimport_batch_extract_value($item, 'code'));
        $this->assertEquals('399.0000', wpezimport_batch_extract_value($item, '{prices/price/dealer}'));
        $this->assertEquals('20', wpezimport_batch_extract_value($item, 'delivery/size_weight'));
    }
}
