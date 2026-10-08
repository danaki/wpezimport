<?php
/**
 * Unit tests for UnitConverter class
 * 
 * Tests weight and dimension conversion functions.
 */

use Wpezimport\Wpezimport\UnitConverter;

class UnitConverterTest extends WP_Mock\Tools\TestCase {

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

    // =========================================================================
    // Tests for convertWeight()
    // =========================================================================

    /**
     * Test converting grams to kilograms
     */
    public function testConvertWeightGramsToKg() {
        $this->assertEquals(1.5, UnitConverter::convertWeight(1500, 'g'));
        $this->assertEquals(0.5, UnitConverter::convertWeight(500, 'g'));
        $this->assertEquals(2.0, UnitConverter::convertWeight(2000, 'g'));
        $this->assertEquals(0.001, UnitConverter::convertWeight(1, 'g'));
    }

    /**
     * Test that kg values remain unchanged
     */
    public function testConvertWeightKgUnchanged() {
        $this->assertEquals(1.5, UnitConverter::convertWeight(1.5, 'kg'));
        $this->assertEquals(10.0, UnitConverter::convertWeight(10, 'kg'));
        $this->assertEquals(0.5, UnitConverter::convertWeight(0.5, 'kg'));
    }

    /**
     * Test weight conversion with default unit (kg)
     */
    public function testConvertWeightDefaultUnit() {
        $this->assertEquals(5.0, UnitConverter::convertWeight(5));
    }

    /**
     * Test weight conversion with empty value
     */
    public function testConvertWeightEmptyValue() {
        $this->assertEquals('', UnitConverter::convertWeight('', 'g'));
        $this->assertEquals(0, UnitConverter::convertWeight(0, 'g'));
        $this->assertNull(UnitConverter::convertWeight(null, 'g'));
    }

    /**
     * Test weight conversion with string numeric value
     */
    public function testConvertWeightStringValue() {
        $this->assertEquals(1.5, UnitConverter::convertWeight('1500', 'g'));
        $this->assertEquals(2.5, UnitConverter::convertWeight('2.5', 'kg'));
    }

    /**
     * Test weight conversion case insensitivity
     */
    public function testConvertWeightCaseInsensitive() {
        $this->assertEquals(1.5, UnitConverter::convertWeight(1500, 'G'));
        $this->assertEquals(1.5, UnitConverter::convertWeight(1.5, 'KG'));
        $this->assertEquals(1.5, UnitConverter::convertWeight(1.5, 'Kg'));
    }

    // =========================================================================
    // Tests for convertDimension()
    // =========================================================================

    /**
     * Test converting centimeters to meters
     */
    public function testConvertDimensionCmToM() {
        $this->assertEquals(1.5, UnitConverter::convertDimension(150, 'cm'));
        $this->assertEquals(0.5, UnitConverter::convertDimension(50, 'cm'));
        $this->assertEquals(2.0, UnitConverter::convertDimension(200, 'cm'));
        $this->assertEquals(0.01, UnitConverter::convertDimension(1, 'cm'));
    }

    /**
     * Test converting millimeters to meters
     */
    public function testConvertDimensionMmToM() {
        $this->assertEquals(1.5, UnitConverter::convertDimension(1500, 'mm'));
        $this->assertEquals(0.5, UnitConverter::convertDimension(500, 'mm'));
        $this->assertEquals(2.0, UnitConverter::convertDimension(2000, 'mm'));
        $this->assertEquals(0.001, UnitConverter::convertDimension(1, 'mm'));
    }

    /**
     * Test that meter values remain unchanged
     */
    public function testConvertDimensionMUnchanged() {
        $this->assertEquals(1.5, UnitConverter::convertDimension(1.5, 'm'));
        $this->assertEquals(10.0, UnitConverter::convertDimension(10, 'm'));
        $this->assertEquals(0.5, UnitConverter::convertDimension(0.5, 'm'));
    }

    /**
     * Test dimension conversion with default unit (m)
     */
    public function testConvertDimensionDefaultUnit() {
        $this->assertEquals(5.0, UnitConverter::convertDimension(5));
    }

    /**
     * Test dimension conversion with empty value
     */
    public function testConvertDimensionEmptyValue() {
        $this->assertEquals('', UnitConverter::convertDimension('', 'cm'));
        $this->assertEquals(0, UnitConverter::convertDimension(0, 'cm'));
        $this->assertNull(UnitConverter::convertDimension(null, 'mm'));
    }

    /**
     * Test dimension conversion with string numeric value
     */
    public function testConvertDimensionStringValue() {
        $this->assertEquals(1.5, UnitConverter::convertDimension('150', 'cm'));
        $this->assertEquals(2.5, UnitConverter::convertDimension('2.5', 'm'));
    }

    /**
     * Test dimension conversion case insensitivity
     */
    public function testConvertDimensionCaseInsensitive() {
        $this->assertEquals(1.5, UnitConverter::convertDimension(150, 'CM'));
        $this->assertEquals(1.5, UnitConverter::convertDimension(1500, 'MM'));
        $this->assertEquals(1.5, UnitConverter::convertDimension(1.5, 'M'));
    }

    // =========================================================================
    // Tests for convertWeightFrom()
    // =========================================================================

    /**
     * Test converting kilograms to grams
     */
    public function testConvertWeightFromKgToG() {
        $this->assertEquals(1500, UnitConverter::convertWeightFrom(1.5, 'g'));
        $this->assertEquals(500, UnitConverter::convertWeightFrom(0.5, 'g'));
        $this->assertEquals(2000, UnitConverter::convertWeightFrom(2, 'g'));
    }

    /**
     * Test that kg output remains unchanged
     */
    public function testConvertWeightFromKgUnchanged() {
        $this->assertEquals(1.5, UnitConverter::convertWeightFrom(1.5, 'kg'));
        $this->assertEquals(10.0, UnitConverter::convertWeightFrom(10, 'kg'));
    }

    /**
     * Test weight from conversion with empty value
     */
    public function testConvertWeightFromEmptyValue() {
        $this->assertEquals('', UnitConverter::convertWeightFrom('', 'g'));
        $this->assertNull(UnitConverter::convertWeightFrom(null, 'g'));
    }

    // =========================================================================
    // Tests for convertDimensionFrom()
    // =========================================================================

    /**
     * Test converting meters to centimeters
     */
    public function testConvertDimensionFromMToCm() {
        $this->assertEquals(150, UnitConverter::convertDimensionFrom(1.5, 'cm'));
        $this->assertEquals(50, UnitConverter::convertDimensionFrom(0.5, 'cm'));
        $this->assertEquals(200, UnitConverter::convertDimensionFrom(2, 'cm'));
    }

    /**
     * Test converting meters to millimeters
     */
    public function testConvertDimensionFromMToMm() {
        $this->assertEquals(1500, UnitConverter::convertDimensionFrom(1.5, 'mm'));
        $this->assertEquals(500, UnitConverter::convertDimensionFrom(0.5, 'mm'));
        $this->assertEquals(2000, UnitConverter::convertDimensionFrom(2, 'mm'));
    }

    /**
     * Test that meter output remains unchanged
     */
    public function testConvertDimensionFromMUnchanged() {
        $this->assertEquals(1.5, UnitConverter::convertDimensionFrom(1.5, 'm'));
        $this->assertEquals(10.0, UnitConverter::convertDimensionFrom(10, 'm'));
    }

    /**
     * Test dimension from conversion with empty value
     */
    public function testConvertDimensionFromEmptyValue() {
        $this->assertEquals('', UnitConverter::convertDimensionFrom('', 'cm'));
        $this->assertNull(UnitConverter::convertDimensionFrom(null, 'mm'));
    }

    // =========================================================================
    // Round-trip conversion tests
    // =========================================================================

    /**
     * Test round-trip weight conversion (g -> kg -> g)
     */
    public function testWeightConversionRoundTrip() {
        $original = 1500;
        $converted = UnitConverter::convertWeight($original, 'g');
        $backConverted = UnitConverter::convertWeightFrom($converted, 'g');
        
        $this->assertEquals($original, $backConverted);
    }

    /**
     * Test round-trip dimension conversion (cm -> m -> cm)
     */
    public function testDimensionConversionRoundTripCm() {
        $original = 150;
        $converted = UnitConverter::convertDimension($original, 'cm');
        $backConverted = UnitConverter::convertDimensionFrom($converted, 'cm');
        
        $this->assertEquals($original, $backConverted);
    }

    /**
     * Test round-trip dimension conversion (mm -> m -> mm)
     */
    public function testDimensionConversionRoundTripMm() {
        $original = 1500;
        $converted = UnitConverter::convertDimension($original, 'mm');
        $backConverted = UnitConverter::convertDimensionFrom($converted, 'mm');
        
        $this->assertEquals($original, $backConverted);
    }

    // =========================================================================
    // Edge case tests
    // =========================================================================

    /**
     * Test conversion with very small values
     */
    public function testConversionSmallValues() {
        $this->assertEquals(0.0001, UnitConverter::convertDimension(0.01, 'cm'));
        $this->assertEquals(0.0001, UnitConverter::convertWeight(0.1, 'g'));
    }

    /**
     * Test conversion with very large values
     */
    public function testConversionLargeValues() {
        $this->assertEquals(1000000.0, UnitConverter::convertWeight(1000000000, 'g'));
        $this->assertEquals(10000.0, UnitConverter::convertDimension(1000000, 'cm'));
    }

    /**
     * Test conversion with negative values
     */
    public function testConversionNegativeValues() {
        $this->assertEquals(-1.5, UnitConverter::convertWeight(-1500, 'g'));
        $this->assertEquals(-1.5, UnitConverter::convertDimension(-150, 'cm'));
    }

    /**
     * Test conversion with decimal precision
     */
    public function testConversionDecimalPrecision() {
        $this->assertEquals(0.123, UnitConverter::convertWeight(123, 'g'));
        $this->assertEquals(1.234, UnitConverter::convertDimension(123.4, 'cm'));
    }
}
