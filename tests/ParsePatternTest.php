<?php
/**
 * Unit tests for wpezimport_parse_pattern function
 *
 * Tests the token replacement function in process-mapping.php that replaces
 * {field_name} tokens in pattern strings with actual row data values.
 */

class ParsePatternTest extends WP_Mock\Tools\TestCase {

    /**
     * @var array Sample row data
     */
    private $rowData;

    /**
     * Set up the test
     */
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->rowData = array(
            'ID'          => '12345',
            'SKU'         => 'SKU-ABC-001',
            'EAN'         => '8001446121702',
            'Title'       => 'Mountain Bike',
            'Description' => 'A great mountain bike',
            'Price'       => '399.00',
            'Brand'       => 'Acme',
            'Color'       => 'Red',
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
    // Basic token replacement
    // =========================================================================

    /**
     * Test replacing a single token
     */
    public function testSingleToken() {
        $result = wpezimport_parse_pattern('{Title}', $this->rowData);
        $this->assertEquals('Mountain Bike', $result);
    }

    /**
     * Test replacing multiple tokens
     */
    public function testMultipleTokens() {
        $result = wpezimport_parse_pattern('{SKU} - {EAN}', $this->rowData);
        $this->assertEquals('SKU-ABC-001 - 8001446121702', $result);
    }

    /**
     * Test pattern with surrounding literal text
     */
    public function testTokenWithLiteralText() {
        $result = wpezimport_parse_pattern('Product: {Title} (ID: {ID})', $this->rowData);
        $this->assertEquals('Product: Mountain Bike (ID: 12345)', $result);
    }

    /**
     * Test three tokens in a row
     */
    public function testThreeTokens() {
        $result = wpezimport_parse_pattern('{Brand} {Title} - {Color}', $this->rowData);
        $this->assertEquals('Acme Mountain Bike - Red', $result);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    /**
     * Test pattern with no tokens returns the pattern unchanged
     */
    public function testNoTokens() {
        $result = wpezimport_parse_pattern('Static text', $this->rowData);
        $this->assertEquals('Static text', $result);
    }

    /**
     * Test empty pattern returns empty string
     */
    public function testEmptyPattern() {
        $result = wpezimport_parse_pattern('', $this->rowData);
        $this->assertEquals('', $result);
    }

    /**
     * Test token for non-existent field is replaced with empty string
     */
    public function testNonExistentField() {
        $result = wpezimport_parse_pattern('{NonExistent}', $this->rowData);
        $this->assertEquals('', $result);
    }

    /**
     * Test mixed existing and non-existing fields
     */
    public function testMixedExistingAndNonExisting() {
        $result = wpezimport_parse_pattern('{Title} by {Author}', $this->rowData);
        $this->assertEquals('Mountain Bike by ', $result);
    }

    /**
     * Test with empty row data — all tokens become empty
     */
    public function testEmptyRowData() {
        $result = wpezimport_parse_pattern('{Title} - {SKU}', array());
        $this->assertEquals(' - ', $result);
    }

    /**
     * Test pattern with only literal braces that don't form tokens
     */
    public function testUnclosedBrace() {
        $result = wpezimport_parse_pattern('Hello {world', $this->rowData);
        $this->assertEquals('Hello {world', $result);
    }

    /**
     * Test pattern with empty token name — {} is not a valid token
     */
    public function testEmptyTokenName() {
        // {} is NOT matched by regex (requires 1+ chars), so left as-is
        $result = wpezimport_parse_pattern('Before{}After', $this->rowData);
        $this->assertEquals('Before{}After', $result);
    }

    /**
     * Test same token used multiple times
     */
    public function testDuplicateTokens() {
        $result = wpezimport_parse_pattern('{ID}-{ID}-{ID}', $this->rowData);
        $this->assertEquals('12345-12345-12345', $result);
    }

    /**
     * Test value containing special characters
     */
    public function testValueWithSpecialCharacters() {
        $row = array('Name' => 'O\'Reilly & Sons <Ltd>');
        $result = wpezimport_parse_pattern('Company: {Name}', $row);
        $this->assertEquals('Company: O\'Reilly & Sons <Ltd>', $result);
    }

    /**
     * Test numeric value
     */
    public function testNumericValue() {
        $result = wpezimport_parse_pattern('Price: ${Price}', $this->rowData);
        $this->assertEquals('Price: $399.00', $result);
    }
}
