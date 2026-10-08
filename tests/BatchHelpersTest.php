<?php
/**
 * Unit tests for the small batch helpers:
 * - wpezimport_batch_extract_value_csv()
 * - wpezimport_batch_extract_unique_value() / _csv()
 * - wpezimport_batch_process_taxonomies_csv()
 */

class BatchHelpersTest extends WP_Mock\Tools\TestCase {

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
    // wpezimport_batch_extract_value_csv()
    // =========================================================================

    public function testCsvValueByName() {
        $row = array('SKU' => 'A1', 'Name' => 'Bike');
        $this->assertEquals('A1', wpezimport_batch_extract_value_csv($row, 'SKU'));
    }

    public function testCsvValueBracesAndWhitespace() {
        $row = array('SKU' => 'A1');
        $this->assertEquals('A1', wpezimport_batch_extract_value_csv($row, ' {SKU} '));
        $this->assertEquals('A1', wpezimport_batch_extract_value_csv($row, '{ SKU }'));
    }

    public function testCsvMissingColumnReturnsEmpty() {
        $this->assertSame('', wpezimport_batch_extract_value_csv(array(), 'nope'));
    }

    // =========================================================================
    // wpezimport_batch_extract_unique_value() (XML)
    // =========================================================================

    private function item() {
        return simplexml_load_string('<product><id>123</id><code>C9</code></product>');
    }

    public function testUniqueValueFromXpathPattern() {
        $this->assertEquals('123', wpezimport_batch_extract_unique_value($this->item(), '{id}', array()));
    }

    public function testUniqueValueFromMappingField() {
        $mappings = array('_sku' => '{code}');
        $this->assertEquals('C9', wpezimport_batch_extract_unique_value($this->item(), '_sku', $mappings));
    }

    public function testUniqueValueReturnsNullWhenUnresolvable() {
        $this->assertNull(wpezimport_batch_extract_unique_value($this->item(), '{missing}', array()));
        $this->assertNull(wpezimport_batch_extract_unique_value($this->item(), 'unknown_field', array()));
    }

    // =========================================================================
    // wpezimport_batch_extract_unique_value_csv()
    // =========================================================================

    public function testCsvUniqueValueByColumnName() {
        $row = array('SKU' => 'A1');
        $this->assertEquals('A1', wpezimport_batch_extract_unique_value_csv($row, 'SKU', array()));
        $this->assertEquals('A1', wpezimport_batch_extract_unique_value_csv($row, '{SKU}', array()));
    }

    public function testCsvUniqueValueViaMapping() {
        $row = array('Col' => 'V9');
        $this->assertEquals('V9', wpezimport_batch_extract_unique_value_csv($row, '_sku', array('_sku' => 'Col')));
    }

    public function testCsvUniqueValueNull() {
        $this->assertNull(wpezimport_batch_extract_unique_value_csv(array(), 'SKU', array()));
        $this->assertNull(wpezimport_batch_extract_unique_value_csv(array('SKU' => ''), 'SKU', array()));
    }

    // =========================================================================
    // wpezimport_batch_process_taxonomies_csv()
    // =========================================================================

    private function captureTaxCalls() {
        $calls = array();
        WP_Mock::userFunction('wpezimport_process_taxonomy_terms', array(
            'return' => function ($post_id, $raw, $tax, $match) use (&$calls) {
                $calls[] = array('raw' => $raw, 'tax' => $tax, 'match' => $match);
                return array('added' => array(), 'assigned' => array());
            },
        ));
        return function () use (&$calls) { return $calls; };
    }

    public function testCsvTaxonomiesForProduct() {
        $getCalls = $this->captureTaxCalls();
        $row = array('Cats' => 'A,B', 'Tags' => 't1');
        wpezimport_batch_process_taxonomies_csv(5, $row, array(
            'post_categories' => 'Cats',
            'post_tags'       => 'Tags',
        ), 'product', true, false);

        $calls = $getCalls();
        $this->assertCount(2, $calls);
        $this->assertEquals('product_cat', $calls[0]['tax']);
        $this->assertEquals('A,B', $calls[0]['raw']);
        $this->assertTrue($calls[0]['match']);
        $this->assertEquals('product_tag', $calls[1]['tax']);
        $this->assertFalse($calls[1]['match']);
    }

    public function testCsvTaxonomiesForRegularPost() {
        $getCalls = $this->captureTaxCalls();
        wpezimport_batch_process_taxonomies_csv(5, array('C' => 'a'), array('post_categories' => 'C'), 'post', false, false);

        $calls = $getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('category', $calls[0]['tax']);
    }

    public function testCsvEmptyCategorySkipsCall() {
        $getCalls = $this->captureTaxCalls();
        wpezimport_batch_process_taxonomies_csv(5, array('C' => ''), array('post_categories' => 'C'), 'product', false, false);
        $this->assertCount(0, $getCalls());
    }

    public function testCsvBrandMappingAlwaysCreates() {
        $getCalls = $this->captureTaxCalls();
        wpezimport_batch_process_taxonomies_csv(5, array('B' => 'Acme'), array('post_brand' => 'B'), 'product', false, false);

        $calls = $getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('product_brand', $calls[0]['tax']);
        $this->assertFalse($calls[0]['match']);
    }
}
