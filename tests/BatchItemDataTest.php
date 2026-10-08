<?php
/**
 * Unit tests for the pure functions extracted from wpezimport_process_import_batch():
 * - wpezimport_batch_load_items()
 * - wpezimport_batch_build_item_data()
 * - wpezimport_batch_diff_existing()
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class BatchItemDataTest extends WP_Mock\Tools\TestCase {

    /** @var SimpleXMLElement */
    private $xmlItem;

    /** @var string[] */
    private $tmpFiles = array();

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->xmlItem = simplexml_load_string(<<<XML
<product>
    <id>SKU-001</id>
    <title>Mountain Bike</title>
    <desc>  A <b>great</b> bike  </desc>
    <price>399.00</price>
    <sale_price>349.00</sale_price>
    <stock>5</stock>
    <stock_status>auto</stock_status>
    <weight>17500</weight>
    <length>175</length>
    <width>22</width>
    <height>105</height>
    <img1>http://example.com/a.jpg</img1>
    <img2>http://example.com/b.jpg</img2>
    <img_empty/>
    <color>red</color>
    <brand>FREJUS</brand>
    <slug>Mountain Bike Pro!</slug>
</product>
XML
        );

        WP_Mock::userFunction('sanitize_title', array(
            'return' => function ($t) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-'); },
        ));
        WP_Mock::passthruFunction('__');
    }

    public function tearDown(): void {
        foreach ($this->tmpFiles as $f) {
            if (file_exists($f)) @unlink($f);
        }
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function tmpFile($content, $ext) {
        $path = tempnam(sys_get_temp_dir(), 'wpez') . '.' . $ext;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function tmpXlsx(array $rows) {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'wpez') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function mappings() {
        return array(
            'post_title'       => '{title}',
            'post_content'     => '{desc}',
            'post_name'        => '{slug}',
            '_regular_price'   => '{price}',
            '_sale_price'      => '{sale_price}',
            '_stock'           => '{stock}',
            '_stock_status'    => '{stock_status}',
            '_weight'          => '{weight}',
            '_length'          => '{length}',
            '_width'           => '{width}',
            '_height'          => '{height}',
            'image_url_1'      => '{img1}',
            'image_url_2'      => '{img2}',
            'image_url_3'      => '{img_empty}',
            'image_gallery'    => '{images/image}',
            'custom_field_color' => '{color}',
            'post_categories'  => '{category}',
            'post_brand'       => '{brand}',
            'skipped_empty'    => '',
        );
    }

    private function defaults() {
        return array('post_type' => 'product', 'post_status' => 'draft', 'post_author' => 1);
    }

    // =========================================================================
    // wpezimport_batch_load_items()
    // =========================================================================

    public function testLoadXmlItemsWithFixture() {
        $path = __DIR__ . '/fixtures/test.xml';
        $items = wpezimport_batch_load_items($path, true, array('selected_xpath' => '//product_output'));

        $this->assertIsArray($items);
        $this->assertCount(4, $items);
        $this->assertInstanceOf(SimpleXMLElement::class, $items[0]);
    }

    public function testLoadXmlDefaultXpathFindsItemElements() {
        $xml = '<feed><item><id>1</id></item><item><id>2</id></item></feed>';
        $items = wpezimport_batch_load_items($this->tmpFile($xml, 'xml'), true, array());
        $this->assertCount(2, $items);
    }

    public function testLoadXmlBrokenFileReturnsError() {
        $result = wpezimport_batch_load_items($this->tmpFile('<broken><x></broken>', 'xml'), true, array());
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('xml_parse', $result->get_error_code());
    }

    public function testLoadXmlNoMatchesReturnsEmptyArray() {
        $items = wpezimport_batch_load_items($this->tmpFile('<a><b/></a>', 'xml'), true, array('selected_xpath' => '//missing'));
        $this->assertSame(array(), $items);
    }

    public function testLoadCsvMapsHeadersToKeysAndSkipsHeader() {
        $items = wpezimport_batch_load_items(__DIR__ . '/fixtures/test.csv', false, array());

        $this->assertCount(4, $items);
        $this->assertEquals(array(
            'SKU'      => 'SKU-001',
            'Name'     => 'Mountain Bike, 29"',
            'Price'    => '399', // PhpSpreadsheet normalizes numeric strings
            'Category' => 'Bikes, Mountain',
            'Weight'   => '17',
        ), $items[0]);
    }

    public function testLoadCsvWithoutHeaderSkip() {
        $items = wpezimport_batch_load_items(
            $this->tmpFile("1,2\n3,4\n", 'csv'),
            false,
            array('skip_first_row' => false)
        );
        $this->assertCount(2, $items);
        // Row 0 is still used to build the header map, so '1'/'2' become keys
        $this->assertEquals(array('1' => '1', '2' => '2'), $items[0]);
        $this->assertEquals(array('1' => '3', '2' => '4'), $items[1]);
    }

    public function testLoadCsvEmptyFileReturnsEmpty() {
        $items = wpezimport_batch_load_items($this->tmpFile('', 'csv'), false, array());
        $this->assertSame(array(), $items);
    }

    public function testLoadXlsxItems() {
        $path = $this->tmpXlsx(array(
            array('SKU', 'Name'),
            array('A1', 'First'),
            array('A2', 'Second'),
        ));
        $items = wpezimport_batch_load_items($path, false, array());

        $this->assertCount(2, $items);
        $this->assertEquals(array('SKU' => 'A1', 'Name' => 'First'), $items[0]);
    }

    public function testLoadXlsxSkipsEmptyHeaderColumns() {
        $path = $this->tmpXlsx(array(
            array('SKU', null, 'Name'),
            array('A1', 'ignored', 'First'),
        ));
        $items = wpezimport_batch_load_items($path, false, array());

        $this->assertEquals(array('SKU' => 'A1', 'Name' => 'First'), $items[0]);
    }

    public function testLoadGarbageXlsxReturnsError() {
        $result = wpezimport_batch_load_items($this->tmpFile('not a spreadsheet', 'xlsx'), false, array());
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('file_parse', $result->get_error_code());
    }

    // =========================================================================
    // wpezimport_batch_build_item_data() — XML
    // =========================================================================

    public function testBuildXmlItemData() {
        $built = wpezimport_batch_build_item_data($this->xmlItem, $this->mappings(), true, $this->defaults());

        $this->assertEquals('Mountain Bike', $built['post_data']['post_title']);
        // (string) on a node with child elements returns concatenated text
        $this->assertEquals('A  bike', trim($built['post_data']['post_content']));
        $this->assertEquals('product', $built['post_data']['post_type']);
        $this->assertEquals('mountain-bike-pro', $built['post_data']['post_name'], 'post_name goes through sanitize_title');

        // Meta: prices + derived _price
        $this->assertEquals('399.00', $built['meta_data']['_regular_price']);
        $this->assertEquals('349.00', $built['meta_data']['_sale_price']);
        $this->assertEquals('349.00', $built['meta_data']['_price'], '_price = sale price when present');

        // custom_field_ prefix stripped
        $this->assertEquals('red', $built['meta_data']['color']);
        $this->assertArrayNotHasKey('custom_field_color', $built['meta_data']);

        // Image urls: empty node excluded, image_gallery not in urls list
        $this->assertEquals(array('http://example.com/a.jpg', 'http://example.com/b.jpg'), $built['image_urls']);

        // Taxonomy fields kept out of meta
        $this->assertArrayNotHasKey('post_brand', $built['meta_data']);

        // Fields resolution recorded
        $this->assertEquals('{title}', $built['fields']['post_title']['xpath']);
        $this->assertEquals('Mountain Bike', $built['fields']['post_title']['value']);
        $this->assertArrayNotHasKey('skipped_empty', $built['fields']);
    }

    public function testAutoStockStatusFromQuantity() {
        $built = wpezimport_batch_build_item_data($this->xmlItem, $this->mappings(), true, $this->defaults());
        $this->assertEquals('instock', $built['meta_data']['_stock_status']);

        $out = simplexml_load_string('<product><stock>0</stock><ss>auto</ss></product>');
        $built = wpezimport_batch_build_item_data($out, array('_stock' => '{stock}', '_stock_status' => '{ss}'), true, array());
        $this->assertEquals('outofstock', $built['meta_data']['_stock_status']);
    }

    public function testAutoPriceWithoutSaleUsesRegular() {
        $item = simplexml_load_string('<product><p>100</p></product>');
        $built = wpezimport_batch_build_item_data($item, array('_regular_price' => '{p}'), true, array());
        $this->assertEquals('100', $built['meta_data']['_price']);
    }

    public function testUnitConversionWeightAndDimensions() {
        $built = wpezimport_batch_build_item_data(
            $this->xmlItem,
            $this->mappings(),
            true,
            $this->defaults(),
            array('_weight' => 'g', '_dimensions' => 'cm')
        );

        $this->assertEquals(17.5, $built['meta_data']['_weight'], '17500 g -> 17.5 kg');
        $this->assertEquals(1.75, $built['meta_data']['_length'], '175 cm -> 1.75 m');
        $this->assertEquals(0.22, $built['meta_data']['_width']);
        $this->assertEquals(1.05, $built['meta_data']['_height']);
        // Fields resolution records the converted value
        $this->assertEquals(17.5, $built['fields']['_weight']['value']);
    }

    public function testPerFieldDimensionUnitTakesPrecedence() {
        $item = simplexml_load_string('<product><l>175</l></product>');
        $built = wpezimport_batch_build_item_data(
            $item,
            array('_length' => '{l}'),
            true,
            array(),
            array('_length' => 'mm', '_dimensions' => 'cm')
        );
        $this->assertEquals(0.175, $built['meta_data']['_length'], 'Per-field unit beats _dimensions fallback');
    }

    public function testEmptyValuesNotConverted() {
        $item = simplexml_load_string('<product><w/></product>');
        $built = wpezimport_batch_build_item_data($item, array('_weight' => '{w}'), true, array(), array('_weight' => 'g'));
        $this->assertSame('', $built['meta_data']['_weight']);
    }

    // =========================================================================
    // wpezimport_batch_build_item_data() — CSV
    // =========================================================================

    public function testBuildCsvItemData() {
        $row = array('SKU' => 'A1', 'Title' => 'Bike', 'Img' => 'http://x/y.jpg');
        $built = wpezimport_batch_build_item_data($row, array(
            'post_title'  => 'Title',
            '_sku'        => '{SKU}',
            'image_url_1' => 'Img',
        ), false, $this->defaults());

        $this->assertEquals('Bike', $built['post_data']['post_title']);
        $this->assertEquals('A1', $built['meta_data']['_sku']);
        $this->assertEquals(array('http://x/y.jpg'), $built['image_urls']);
    }

    // =========================================================================
    // wpezimport_batch_diff_existing()
    // =========================================================================

    private function existingPost() {
        return (object) array(
            'post_title'   => 'Old Title',
            'post_content' => 'Old content',
            'post_excerpt' => 'Old excerpt',
            'post_name'    => 'old-title',
            'post_status'  => 'publish',
            'post_date'    => '2020-01-01 00:00:00',
        );
    }

    private function mockMeta(array $meta) {
        WP_Mock::userFunction('get_post_meta', array(
            'return' => function ($id, $key, $single) use ($meta) {
                return isset($meta[$key]) ? $meta[$key] : '';
            },
        ));
    }

    public function testNoChangesWhenValuesIdentical() {
        $this->mockMeta(array('_regular_price' => '399.00'));

        $diff = wpezimport_batch_diff_existing(
            $this->existingPost(),
            55,
            array(
                'post_title'     => array('xpath' => '{title}', 'value' => 'Old Title'),
                '_regular_price' => array('xpath' => '{price}', 'value' => '399.00'),
            ),
            array('post_title' => 'Old Title'),
            array('_regular_price' => '399.00')
        );

        $this->assertFalse($diff['has_changes']);
        $this->assertEquals(array('ID' => 55), $diff['post']);
        $this->assertEmpty($diff['meta']);
        $this->assertFalse($diff['fields']['post_title']['changed']);
        $this->assertEquals('Old Title', $diff['fields']['post_title']['old_value']);
    }

    public function testChangedPostFieldIsIncluded() {
        $this->mockMeta(array());

        $diff = wpezimport_batch_diff_existing(
            $this->existingPost(),
            55,
            array('post_title' => array('xpath' => '{title}', 'value' => 'New Title')),
            array('post_title' => 'New Title'),
            array()
        );

        $this->assertTrue($diff['has_changes']);
        $this->assertEquals(array('ID' => 55, 'post_title' => 'New Title'), $diff['post']);
    }

    public function testUpdateFlagsDisallowChangedField() {
        $this->mockMeta(array('_regular_price' => '100'));

        $diff = wpezimport_batch_diff_existing(
            $this->existingPost(),
            55,
            array(
                'post_title'     => array('xpath' => '{title}', 'value' => 'New Title'),
                '_regular_price' => array('xpath' => '{price}', 'value' => '399'),
            ),
            array('post_title' => 'New Title'),
            array('_regular_price' => '399'),
            array('post_title' => '1', '_regular_price' => '0')
        );

        $this->assertTrue($diff['has_changes'], 'Title still changes');
        $this->assertArrayNotHasKey('_regular_price', $diff['meta'], 'Price flag off — must not update');
        $this->assertTrue($diff['fields']['_regular_price']['changed']);
        $this->assertFalse($diff['fields']['_regular_price']['update_allowed']);
    }

    public function testFieldMissingFlagNotUpdated() {
        $this->mockMeta(array('_stock' => '1'));

        // update_flags defined but does not contain _stock -> not allowed
        $diff = wpezimport_batch_diff_existing(
            $this->existingPost(),
            55,
            array('_stock' => array('xpath' => '{stock}', 'value' => '5')),
            array(),
            array('_stock' => '5'),
            array('post_title' => '1')
        );

        $this->assertFalse($diff['has_changes']);
        $this->assertFalse($diff['fields']['_stock']['update_allowed']);
    }

    public function testCustomFieldKeyStrippedForMetaLookup() {
        $this->mockMeta(array('color' => 'blue'));

        $diff = wpezimport_batch_diff_existing(
            $this->existingPost(),
            55,
            array('custom_field_color' => array('xpath' => '{color}', 'value' => 'red')),
            array(),
            array('color' => 'red')
        );

        $this->assertTrue($diff['has_changes']);
        $this->assertEquals(array('color' => 'red'), $diff['meta']);
        $this->assertEquals('blue', $diff['fields']['custom_field_color']['old_value']);
    }

    public function testTaxonomyAndImageFieldsSkipped() {
        $this->mockMeta(array());

        $diff = wpezimport_batch_diff_existing(
            $this->existingPost(),
            55,
            array(
                'post_categories' => array('xpath' => '{cat}', 'value' => 'A,B'),
                'post_tags'       => array('xpath' => '{tag}', 'value' => 'x'),
                'post_brand'      => array('xpath' => '{b}', 'value' => 'b'),
                'image_url_1'     => array('xpath' => '{i}', 'value' => 'http://x/y.jpg'),
                'image_gallery'   => array('xpath' => '{g}', 'value' => 'x'),
            ),
            array(),
            array()
        );

        $this->assertFalse($diff['has_changes']);
        $this->assertEmpty($diff['meta']);
    }

    public function testMetaOnlyChangeHasChanges() {
        $this->mockMeta(array('_stock' => '1'));

        $diff = wpezimport_batch_diff_existing(
            $this->existingPost(),
            55,
            array('_stock' => array('xpath' => '{stock}', 'value' => '5')),
            array(),
            array('_stock' => '5')
        );

        $this->assertTrue($diff['has_changes']);
        $this->assertEquals(array('ID' => 55), $diff['post']);
        $this->assertEquals(array('_stock' => '5'), $diff['meta']);
    }
}
