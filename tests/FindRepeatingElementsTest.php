<?php
/**
 * Unit test for wpezimport_find_repeating_elements function
 */

class FindRepeatingElementsTest extends WP_Mock\Tools\TestCase {

    /**
     * Set up the test
     */
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    /**
     * Tear down after the test
     */
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    /**
     * Test finding repeating elements in a simple XML structure
     */
    public function testFindRepeatingElementsSimple() {
        // Create a simple XML structure with repeating elements
        $xml_string = <<<XML
<products>
    <product>
        <id>1</id>
        <name>Product 1</name>
        <price>10.99</price>
    </product>
    <product>
        <id>2</id>
        <name>Product 2</name>
        <price>20.99</price>
    </product>
    <product>
        <id>3</id>
        <name>Product 3</name>
        <price>30.99</price>
    </product>
</products>
XML;

        $xml = simplexml_load_string($xml_string);

        // // Mock error_log to avoid actual logging during tests
        // WP_Mock::userFunction('error_log', [
        //     'times' => '0+',
        // ]);

        // Call the function
        $counts = [];
        $repeating_elements = wpezimport_find_repeating_elements($xml, '', $counts);

        // Assert results
        $this->assertIsArray($repeating_elements);
        $this->assertNotEmpty($repeating_elements);

        // We expect to find /products/product as a repeating element with count 3
        $found_product_repeating = false;
        foreach ($repeating_elements as $element) {
            if ($element['xpath'] === '/products/product' && $element['count'] === 3) {
                $found_product_repeating = true;
                break;
            }
        }

        $this->assertTrue($found_product_repeating, 'Should find /products/product as a repeating element with count 3');
    }

    /**
     * Test behavior with a flat XML structure (no repeating elements)
     */
    public function testFindRepeatingElementsNone() {
        // Create an XML with no repeating elements
        $xml_string = <<<XML
<configuration>
    <server>localhost</server>
    <port>3306</port>
    <username>admin</username>
    <password>password123</password>
</configuration>
XML;

        $xml = simplexml_load_string($xml_string);

        // // Mock error_log to avoid actual logging during tests
        // WP_Mock::userFunction('error_log', [
        //     'times' => '0+',
        // ]);

        // Call the function
        $counts = [];
        $repeating_elements = wpezimport_find_repeating_elements($xml, '', $counts);

        // Assert results - should be empty as there are no repeating elements
        $this->assertIsArray($repeating_elements);
        $this->assertEmpty($repeating_elements);
    }
}
