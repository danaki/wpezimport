<?php
/**
 * Unit tests for XmlParser::getElementsByXpath() and unique identifier extraction
 * with bare (brace-less) patterns.
 */

use Wpezimport\Wpezimport\XmlParser;

class XmlParserElementsTest extends WP_Mock\Tools\TestCase {

    /** @var SimpleXMLElement */
    private $xml;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
        $this->xml = simplexml_load_file(__DIR__ . '/fixtures/test.xml');
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // =========================================================================
    // getElementsByXpath()
    // =========================================================================

    public function testAbsolutePathReturnsAllItems() {
        $items = XmlParser::getElementsByXpath($this->xml, '/result/products_output/product_output');
        $this->assertCount(4, $items);
        $this->assertEquals('189389', (string) $items[0]->id);
        $this->assertEquals('189408', (string) $items[3]->id);
    }

    public function testDescendantPathReturnsAllItems() {
        $items = XmlParser::getElementsByXpath($this->xml, '//product_output');
        $this->assertCount(4, $items);
    }

    public function testFallsBackToLastSegmentWhenAbsolutePathIsWrong() {
        // "/wrong/root/product_output" does not exist; fallback to //product_output
        $items = XmlParser::getElementsByXpath($this->xml, '/wrong/root/product_output');
        $this->assertCount(4, $items);
    }

    public function testRelativeSingleSegmentPath() {
        // Relative path from the document root element (<result>)
        $items = XmlParser::getElementsByXpath($this->xml, 'products_output/product_output');
        $this->assertCount(4, $items);
    }

    public function testReturnsEmptyArrayWhenNothingMatches() {
        $this->assertSame([], XmlParser::getElementsByXpath($this->xml, '//does_not_exist'));
    }

    public function testReturnsEmptyArrayForEmptyPath() {
        $this->assertSame([], XmlParser::getElementsByXpath($this->xml, ''));
        $this->assertSame([], XmlParser::getElementsByXpath($this->xml, '   '));
    }

    public function testReturnsEmptyArrayForNullXml() {
        $this->assertSame([], XmlParser::getElementsByXpath(null, '//product_output'));
    }

    public function testInvalidXpathReturnsEmptyArrayWithoutWarnings() {
        $this->assertSame([], XmlParser::getElementsByXpath($this->xml, '//[invalid'));
    }

    public function testResultIsZeroIndexedList() {
        $items = XmlParser::getElementsByXpath($this->xml, '//product_output');
        $this->assertSame(range(0, 3), array_keys($items));
    }

    public function testProceduralWrapperDelegates() {
        $items = wpezimport_get_xml_elements_by_xpath($this->xml, '//product_output');
        $this->assertCount(4, $items);
        $this->assertInstanceOf(SimpleXMLElement::class, $items[0]);
    }

    // =========================================================================
    // extractUniqueIdentifier() with bare patterns
    // =========================================================================

    public function testBareChildNameIsEvaluatedNotReturnedLiterally() {
        $item = $this->xml->xpath('//product_output')[0];
        $this->assertEquals('189389', XmlParser::extractUniqueIdentifier($item, 'id'));
    }

    public function testBareNestedPathIsEvaluated() {
        $item = $this->xml->xpath('//product_output')[0];
        $this->assertEquals('8001446121702', XmlParser::extractUniqueIdentifier($item, 'ean_code'));
        $this->assertEquals('PO/8210/189389', XmlParser::extractUniqueIdentifier($item, 'ecom_guid'));
    }

    public function testBracedPatternStillWorks() {
        $item = $this->xml->xpath('//product_output')[0];
        $this->assertEquals('SZ1U29424DA BLACK/BLUE-8001446121702', XmlParser::extractUniqueIdentifier($item, '{code}-{ean_code}'));
    }

    public function testBareNonExistentChildReturnsEmpty() {
        $item = $this->xml->xpath('//product_output')[0];
        $this->assertSame('', XmlParser::extractUniqueIdentifier($item, 'no_such_field'));
    }
}
