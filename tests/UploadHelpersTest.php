<?php
/**
 * Unit tests for helper functions in process-upload.php:
 * - wpezimport_truncate_text()
 * - wpezimport_build_xml_tree_recursive()
 * - wpezimport_extract_xml_node_structure_recursive()
 * - wpezimport_get_xml_tree_for_selection()
 */

class UploadHelpersTest extends WP_Mock\Tools\TestCase {

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        WP_Mock::passthruFunction('__');
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // =========================================================================
    // wpezimport_truncate_text()
    // =========================================================================

    public function testShortTextReturnedUnchanged() {
        $this->assertEquals('hello', wpezimport_truncate_text('hello', 50));
        $this->assertEquals('hello', wpezimport_truncate_text('hello', 5));
    }

    public function testLongTextIsTruncatedWithEllipsis() {
        $text = str_repeat('ab', 50); // 100 chars
        $result = wpezimport_truncate_text($text, 10);

        $this->assertEquals('ababababab...', $result);
        $this->assertEquals(13, strlen($result));
    }

    public function testMultibyteTextTruncatesOnCharacters() {
        $text = 'Velosipēds ir labs transporta līdzeklis';
        $result = wpezimport_truncate_text($text, 10);

        $this->assertTrue(mb_strlen($text) > 10);
        $this->assertEquals('Velosipēds...', $result);
    }

    // =========================================================================
    // wpezimport_build_xml_tree_recursive()
    // =========================================================================

    public function testBuildsNestedTreeWithXpaths() {
        $xml = simplexml_load_string('<product><id>1</id><info><title>Bike</title></info></product>');
        $tree = wpezimport_build_xml_tree_recursive($xml);

        $this->assertEquals('product', $tree['name']);
        $this->assertEquals('/product', $tree['xpath']);
        $this->assertEquals(2, $tree['child_count']);
        $this->assertCount(2, $tree['children']);

        $this->assertEquals('id', $tree['children'][0]['name']);
        $this->assertEquals('/product/id', $tree['children'][0]['xpath']);
        $this->assertEquals('1', $tree['children'][0]['text_preview']);

        $this->assertEquals('/product/info', $tree['children'][1]['xpath']);
        $this->assertEquals('/product/info/title', $tree['children'][1]['children'][0]['xpath']);
    }

    public function testRootXpathHasNoDoubleSlash() {
        $xml = simplexml_load_string('<root><a>x</a></root>');
        $tree = wpezimport_build_xml_tree_recursive($xml);
        $this->assertEquals('/root', $tree['xpath']);
    }

    public function testAttributesAndCountsAreCollected() {
        $xml = simplexml_load_string('<product id="7" version="2"><a/><b/><c/></product>');
        $tree = wpezimport_build_xml_tree_recursive($xml);

        $this->assertEquals(array('id' => '7', 'version' => '2'), $tree['attributes']);
        $this->assertEquals(2, $tree['attribute_count']);
        $this->assertEquals(3, $tree['child_count']);
    }

    public function testTextPreviewIsTruncated() {
        $xml = simplexml_load_string('<item><desc>' . str_repeat('x', 100) . '</desc></item>');
        $desc = wpezimport_build_xml_tree_recursive($xml)['children'][0];

        $this->assertEquals(str_repeat('x', 50) . '...', $desc['text_preview']);
    }

    public function testEmptyElementHasEmptyPreview() {
        $xml = simplexml_load_string('<item><empty/></item>');
        $empty = wpezimport_build_xml_tree_recursive($xml)['children'][0];
        $this->assertEquals('', $empty['text_preview']);
    }

    // =========================================================================
    // wpezimport_extract_xml_node_structure_recursive()
    // =========================================================================

    public function testExtractsRelativePathsOfLeafElements() {
        $xml = simplexml_load_string('<product><id>1</id><info><title>T</title></info><empty/></product>');
        $structure = array();
        wpezimport_extract_xml_node_structure_recursive($xml, '', $structure);

        $this->assertContains('id', $structure);
        $this->assertContains('info/title', $structure);
        $this->assertNotContains('info', $structure, 'Non-leaf parents are not fields');
        $this->assertNotContains('empty', $structure, 'Empty leafs are not fields');
    }

    public function testExtractsAttributes() {
        $xml = simplexml_load_string('<group id="5898">Bikes</group>');
        $structure = array();
        wpezimport_extract_xml_node_structure_recursive($xml, '', $structure);

        $this->assertContains('@id', $structure);
        // Root element with text but no children and empty relative path is not added
        $this->assertNotContains('group', $structure);
    }

    public function testSameNamedSiblingsProduceSamePathOnce() {
        $xml = simplexml_load_string('<product><images><image>a.jpg</image><image>b.jpg</image></images></product>');
        $structure = array();
        wpezimport_extract_xml_node_structure_recursive($xml, '', $structure);

        $count = array_count_values($structure);
        $this->assertEquals(1, $count['images/image']);
    }

    public function testFixtureItemStructure() {
        $xml = simplexml_load_file(__DIR__ . '/fixtures/test.xml');
        $item = $xml->xpath('//product_output')[0];

        $structure = array();
        wpezimport_extract_xml_node_structure_recursive($item, '', $structure);

        $this->assertContains('id', $structure);
        $this->assertContains('info/title/lv', $structure);
        $this->assertContains('links/group', $structure);
        $this->assertContains('links/group/@id', $structure);
        $this->assertContains('prices/price/dealer', $structure);
    }

    // =========================================================================
    // wpezimport_get_xml_tree_for_selection()
    // =========================================================================

    public function testTreeForSelectionBuildsTreeFromFixture() {
        $tree = wpezimport_get_xml_tree_for_selection(__DIR__ . '/fixtures/test.xml');

        $this->assertIsArray($tree);
        $this->assertEquals('result', $tree['name']);
        $this->assertEquals('/result', $tree['xpath']);
        $this->assertEquals('products_output', $tree['children'][0]['name']);
    }

    public function testTreeForSelectionMissingFileReturnsError() {
        $result = wpezimport_get_xml_tree_for_selection('/nonexistent/file.xml');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('file_not_found', $result->get_error_code());
    }

    public function testTreeForSelectionBrokenXmlReturnsError() {
        $path = tempnam(sys_get_temp_dir(), 'wpez') . '.xml';
        file_put_contents($path, '<broken><unclosed></broken>');

        $result = wpezimport_get_xml_tree_for_selection($path);
        @unlink($path);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('xml_parse_error', $result->get_error_code());
    }

    public function testTreeForSelectionStripsBom() {
        $path = tempnam(sys_get_temp_dir(), 'wpez') . '.xml';
        file_put_contents($path, "\xEF\xBB\xBF" . '<root><a>1</a></root>');

        $result = wpezimport_get_xml_tree_for_selection($path);
        @unlink($path);

        $this->assertIsArray($result);
        $this->assertEquals('root', $result['name']);
    }
}
