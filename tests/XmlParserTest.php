<?php
/**
 * Unit tests for XML parser functions
 *
 * These tests verify the pure XML parsing logic without any database dependencies.
 */

class XmlParserTest extends WP_Mock\Tools\TestCase {

    /**
     * @var string Path to the test.xml file
     */
    private $testXmlPath;

    /**
     * @var SimpleXMLElement The loaded test XML
     */
    private $testXml;

    /**
     * @var array Sample product items from test.xml
     */
    private $productItems;

    /**
     * Set up the test
     */
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        // Load the real test.xml file
        $this->testXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $this->testXml = simplexml_load_file($this->testXmlPath);

        // Get product items
        $this->productItems = $this->testXml->xpath('//product_output');
    }

    /**
     * Tear down after the test
     */
    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // =========================================================================
    // Tests for wpezimport_evaluate_xpath()
    // =========================================================================

    /**
     * Test evaluating a simple XPath expression
     */
    public function testEvaluateXpathSimple() {
        $item = $this->productItems[0];

        $result = wpezimport_evaluate_xpath($item, 'id');
        $this->assertEquals('189389', $result);
    }

    /**
     * Test evaluating a nested XPath expression
     */
    public function testEvaluateXpathNested() {
        $item = $this->productItems[0];

        $result = wpezimport_evaluate_xpath($item, 'info/title/lv');
        $this->assertStringContainsString('Itāļu Kalnu velosipēds', $result);
    }

    /**
     * Test evaluating XPath with leading slash
     */
    public function testEvaluateXpathWithLeadingSlash() {
        $item = $this->productItems[0];

        $result = wpezimport_evaluate_xpath($item, '/id');
        $this->assertEquals('189389', $result);
    }

    /**
     * Test evaluating XPath for non-existent element
     */
    public function testEvaluateXpathNonExistent() {
        $item = $this->productItems[0];

        $result = wpezimport_evaluate_xpath($item, 'non_existent_element');
        $this->assertEquals('', $result);
    }

    /**
     * Test evaluating XPath with empty input
     */
    public function testEvaluateXpathEmptyInputs() {
        $item = $this->productItems[0];

        $this->assertEquals('', wpezimport_evaluate_xpath($item, ''));
        $this->assertEquals('', wpezimport_evaluate_xpath(null, 'id'));
    }

    /**
     * Test evaluating XPath for price data
     */
    public function testEvaluateXpathPrice() {
        $item = $this->productItems[0];

        $result = wpezimport_evaluate_xpath($item, 'prices/price/dealer');
        $this->assertEquals('399.0000', $result);
    }

    /**
     * Test evaluating XPath for delivery dimensions
     */
    public function testEvaluateXpathDeliveryData() {
        $item = $this->productItems[0];

        $weight = wpezimport_evaluate_xpath($item, 'delivery/size_weight');
        $height = wpezimport_evaluate_xpath($item, 'delivery/size_height');
        $width = wpezimport_evaluate_xpath($item, 'delivery/size_width');
        $length = wpezimport_evaluate_xpath($item, 'delivery/size_length');

        $this->assertEquals('20', $weight);
        $this->assertEquals('105', $height);
        $this->assertEquals('22', $width);
        $this->assertEquals('175', $length);
    }

    // =========================================================================
    // Tests for wpezimport_evaluate_xpath_all()
    // =========================================================================

    /**
     * Test evaluating XPath for multiple image elements
     */
    public function testEvaluateXpathAllImages() {
        // Second product has images
        $item = $this->productItems[1];

        $results = wpezimport_evaluate_xpath_all($item, 'info/images/image');

        $this->assertIsArray($results);
        $this->assertCount(5, $results);
        $this->assertStringContainsString('http://img.veikaliem.lv', $results[0]);
    }

    /**
     * Test evaluating XPath all for single element
     */
    public function testEvaluateXpathAllSingleElement() {
        $item = $this->productItems[0];

        $results = wpezimport_evaluate_xpath_all($item, 'id');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('189389', $results[0]);
    }

    /**
     * Test evaluating XPath all for non-existent element
     */
    public function testEvaluateXpathAllNonExistent() {
        $item = $this->productItems[0];

        $results = wpezimport_evaluate_xpath_all($item, 'non_existent');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // =========================================================================
    // Tests for wpezimport_parse_field_pattern()
    // =========================================================================

    /**
     * Test parsing a pattern with single XPath expression
     */
    public function testParseFieldPatternSingle() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, '{id}');
        $this->assertEquals('189389', $result);
    }

    public function testParseFieldPatternSingleWithIndex() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, '{id[1]}');
        $this->assertEquals('189389', $result);
    }

    /**
     * Test parsing a pattern with multiple XPath expressions
     */
    public function testParseFieldPatternMultiple() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, '{code} - {ean_code}');
        $this->assertEquals('SZ1U29424DA BLACK/BLUE - 8001446121702', $result);
    }

    /**
     * Test parsing a pattern with nested XPath
     */
    public function testParseFieldPatternNested() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, '{info/title/lv}');
        $this->assertStringContainsString('Itāļu Kalnu velosipēds', $result);
    }

    public function testParseFieldPatternNestedWithIndex() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, '{info[1]/title[1]/lv[1]}');
        $this->assertStringContainsString('Itāļu Kalnu velosipēds', $result);
    }

    /**
     * Test parsing a literal pattern (no curly braces)
     */
    public function testParseFieldPatternLiteral() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, 'Static Value');
        $this->assertEquals('Static Value', $result);
    }

    /**
     * Test parsing empty pattern
     */
    public function testParseFieldPatternEmpty() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, '');
        $this->assertEquals('', $result);
    }

    /**
     * Test parsing pattern with mixed content
     */
    public function testParseFieldPatternMixed() {
        $item = $this->productItems[0];

        $result = wpezimport_parse_field_pattern($item, 'Product ID: {id}, EAN: {ean_code}');
        $this->assertEquals('Product ID: 189389, EAN: 8001446121702', $result);
    }

    // =========================================================================
    // Tests for wpezimport_parse_field_pattern_all()
    // =========================================================================

    /**
     * Test parsing pattern for multiple values (images)
     */
    public function testParseFieldPatternAllImages() {
        $item = $this->productItems[1]; // Has images

        $results = wpezimport_parse_field_pattern_all($item, '{info/images/image}');

        $this->assertIsArray($results);
        $this->assertCount(5, $results);
    }

    /**
     * Test parsing literal URLs
     */
    public function testParseFieldPatternAllLiteralUrls() {
        $item = $this->productItems[0];

        $results = wpezimport_parse_field_pattern_all($item, 'http://example.com/1.jpg http://example.com/2.jpg');

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertEquals('http://example.com/1.jpg', $results[0]);
        $this->assertEquals('http://example.com/2.jpg', $results[1]);
    }

    // =========================================================================
    // Tests for wpezimport_extract_mapped_data()
    // =========================================================================

    /**
     * Test extracting mapped data with typical field mappings
     */
    public function testExtractMappedDataTypical() {
        $item = $this->productItems[0];

        $field_mapping = array(
            'post_title' => '{info/title/lv}',
            'post_content' => '{info/description/lv}',
            '_sku' => '{code}',
            '_ean' => '{ean_code}',
            '_price' => '{prices/price/dealer}',
            '_weight' => '{delivery/size_weight}',
            'unique_identifier_source' => '{id}'
        );

        $result = wpezimport_extract_mapped_data($item, $field_mapping);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('post_title', $result);
        $this->assertArrayHasKey('_sku', $result);
        $this->assertArrayHasKey('_ean', $result);
        $this->assertArrayHasKey('_price', $result);
        $this->assertArrayHasKey('_weight', $result);

        $this->assertStringContainsString('Itāļu Kalnu velosipēds', $result['post_title']);
        $this->assertEquals('SZ1U29424DA BLACK/BLUE', $result['_sku']);
        $this->assertEquals('8001446121702', $result['_ean']);
        $this->assertEquals('399.0000', $result['_price']);
        $this->assertEquals('20', $result['_weight']);
    }

    /**
     * Test extracting mapped data with empty mappings
     */
    public function testExtractMappedDataEmpty() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_mapped_data($item, array());

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test extracting mapped data with some empty patterns
     */
    public function testExtractMappedDataWithEmptyPatterns() {
        $item = $this->productItems[0];

        $field_mapping = array(
            'post_title' => '{info/title/lv}',
            'empty_field' => '',
            '_sku' => '{code}'
        );

        $result = wpezimport_extract_mapped_data($item, $field_mapping);

        $this->assertArrayHasKey('post_title', $result);
        $this->assertArrayHasKey('_sku', $result);
        $this->assertArrayNotHasKey('empty_field', $result);
    }

    // =========================================================================
    // Tests for wpezimport_prepare_post_data()
    // =========================================================================

    /**
     * Test preparing post data from extracted data
     */
    public function testPreparePostDataTypical() {
        $extracted_data = array(
            'post_title' => 'Test Product Title',
            'post_content' => 'Test product description',
            'post_excerpt' => 'Short description',
            'post_category' => 'Category1',
            'post_tags' => 'tag1, tag2',
            'image_url_1' => 'http://example.com/image1.jpg',
            'image_url_2' => 'http://example.com/image2.jpg',
            '_sku' => 'TEST-SKU-123',
            '_price' => '99.99',
            '_weight' => '500',
            'custom_field' => 'custom value'
        );

        $result = wpezimport_prepare_post_data($extracted_data);

        // Check post_data
        $this->assertArrayHasKey('post_data', $result);
        $this->assertEquals('Test Product Title', $result['post_data']['post_title']);
        $this->assertEquals('Test product description', $result['post_data']['post_content']);
        $this->assertEquals('Short description', $result['post_data']['post_excerpt']);

        // Check taxonomy_data
        $this->assertArrayHasKey('taxonomy_data', $result);
        $this->assertEquals('Category1', $result['taxonomy_data']['category']);
        $this->assertEquals('tag1, tag2', $result['taxonomy_data']['post_tag']);

        // Check image_data
        $this->assertArrayHasKey('image_data', $result);
        $this->assertEquals('http://example.com/image1.jpg', $result['image_data']['image_url_1']);
        $this->assertEquals('http://example.com/image2.jpg', $result['image_data']['image_url_2']);

        // Check meta_fields
        $this->assertArrayHasKey('meta_fields', $result);
        $this->assertEquals('TEST-SKU-123', $result['meta_fields']['_sku']);
        $this->assertEquals('99.99', $result['meta_fields']['_price']);
        $this->assertEquals('custom value', $result['meta_fields']['custom_field']);
    }

    /**
     * Test weight conversion from grams to kg
     */
    public function testPreparePostDataWeightConversion() {
        $extracted_data = array(
            '_weight' => '1500'
        );

        $mapping_units = array(
            '_weight' => 'g'
        );

        $result = wpezimport_prepare_post_data($extracted_data, $mapping_units);

        $this->assertEquals(1.5, $result['meta_fields']['_weight']);
    }

    /**
     * Test dimension conversion from cm to m
     */
    public function testPreparePostDataDimensionConversionCm() {
        $extracted_data = array(
            '_length' => '150',
            '_width' => '50',
            '_height' => '100'
        );

        $mapping_units = array(
            '_dimensions' => 'cm'
        );

        $result = wpezimport_prepare_post_data($extracted_data, $mapping_units);

        $this->assertEquals(1.5, $result['meta_fields']['_length']);
        $this->assertEquals(0.5, $result['meta_fields']['_width']);
        $this->assertEquals(1.0, $result['meta_fields']['_height']);
    }

    /**
     * Test dimension conversion from mm to m
     */
    public function testPreparePostDataDimensionConversionMm() {
        $extracted_data = array(
            '_length' => '1500',
            '_width' => '500',
            '_height' => '1000'
        );

        $mapping_units = array(
            '_dimensions' => 'mm'
        );

        $result = wpezimport_prepare_post_data($extracted_data, $mapping_units);

        $this->assertEquals(1.5, $result['meta_fields']['_length']);
        $this->assertEquals(0.5, $result['meta_fields']['_width']);
        $this->assertEquals(1.0, $result['meta_fields']['_height']);
    }

    /**
     * Test preparing empty data
     */
    public function testPreparePostDataEmpty() {
        $result = wpezimport_prepare_post_data(array());

        $this->assertArrayHasKey('post_data', $result);
        $this->assertArrayHasKey('meta_fields', $result);
        $this->assertArrayHasKey('taxonomy_data', $result);
        $this->assertArrayHasKey('image_data', $result);
        $this->assertEmpty($result['post_data']);
        $this->assertEmpty($result['meta_fields']);
    }

    // =========================================================================
    // Tests for wpezimport_extract_image_urls()
    // =========================================================================

    /**
     * Test extracting image URLs with XPath pattern
     */
    public function testExtractImageUrlsWithXpath() {
        $item = $this->productItems[1]; // Has images

        $result = wpezimport_extract_image_urls($item, '{info/images/image}');

        $this->assertArrayHasKey('urls', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertCount(5, $result['urls']);
        $this->assertStringContainsString('http://img.veikaliem.lv', $result['urls'][0]);
    }

    /**
     * Test extracting image URLs with direct URLs
     *
     * Note: Direct URLs are split by commas and newlines only (not spaces),
     * because URLs may contain spaces that need to be preserved and encoded.
     */
    public function testExtractImageUrlsDirectUrls() {
        $item = $this->productItems[0];

        // URLs separated by commas (recommended format)
        $urls = 'http://example.com/1.jpg, http://example.com/2.jpg, http://example.com/3.jpg';
        $result = wpezimport_extract_image_urls($item, $urls);

        $this->assertCount(3, $result['urls']);
        $this->assertEquals('http://example.com/1.jpg', $result['urls'][0]);
        $this->assertEquals('http://example.com/2.jpg', $result['urls'][1]);
        $this->assertEquals('http://example.com/3.jpg', $result['urls'][2]);
    }

    /**
     * Test extracting image URLs with empty input
     */
    public function testExtractImageUrlsEmpty() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_image_urls($item, '');

        $this->assertEmpty($result['urls']);
    }

    // =========================================================================
    // Integration test: Full workflow simulation
    // =========================================================================

    /**
     * Test the complete extraction workflow from XML to prepared post data
     */
    public function testFullExtractionWorkflow() {
        $item = $this->productItems[1]; // Second product with images

        // Define field mappings similar to real import configuration
        $field_mapping = array(
            'post_title' => '{info/title/lv}',
            'post_content' => '{info/description/lv}',
            '_sku' => '{code}',
            '_ean' => '{ean_code}',
            '_regular_price' => '{prices/price/dealer}',
            '_weight' => '{delivery/size_weight}',
            '_length' => '{delivery/size_length}',
            '_width' => '{delivery/size_width}',
            '_height' => '{delivery/size_height}',
            'image_url_1' => '{info/images/image[1]}',
            'image_url_2' => '{info/images/image[2]}',
            'post_category' => '{links/group}',
            'unique_identifier_source' => '{id}',
            'wpezimport_unique_id' => '{id}'
        );

        $mapping_units = array(
            '_weight' => 'kg',
            '_dimensions' => 'cm'
        );

        // Step 1: Extract raw data
        $extracted = wpezimport_extract_mapped_data($item, $field_mapping);

        $this->assertNotEmpty($extracted);
        $this->assertArrayHasKey('post_title', $extracted);
        $this->assertArrayHasKey('_sku', $extracted);

        // Step 2: Prepare post data
        $prepared = wpezimport_prepare_post_data($extracted, $mapping_units);

        // Verify structure
        $this->assertArrayHasKey('post_data', $prepared);
        $this->assertArrayHasKey('meta_fields', $prepared);
        $this->assertArrayHasKey('taxonomy_data', $prepared);
        $this->assertArrayHasKey('image_data', $prepared);

        // Verify post data
        $this->assertStringContainsString('Kalnu velosipēds', $prepared['post_data']['post_title']);

        // Verify meta fields
        $this->assertEquals('SMU29221DA BLACK', $prepared['meta_fields']['_sku']);
        $this->assertEquals('8001446118139', $prepared['meta_fields']['_ean']);

        // Verify dimensions were converted (cm to m)
        $this->assertEquals(1.7, $prepared['meta_fields']['_length']); // 170cm -> 1.7m
        $this->assertEquals(0.28, $prepared['meta_fields']['_width']); // 28cm -> 0.28m
        $this->assertEquals(0.9, $prepared['meta_fields']['_height']); // 90cm -> 0.9m

        // Verify taxonomy
        $this->assertEquals('Kalnu velosipēdi', $prepared['taxonomy_data']['category']);

        // Verify image data is stored for later processing
        $this->assertNotEmpty($prepared['image_data']['image_url_1']);
    }

    /**
     * Test image gallery import with indexed XPath expressions using test.xml
     */
    public function testImageGalleryWithIndexedXpath() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // Second product (index 1) has 5 images
        $item = $realProductItems[1];

        // This is the exact pattern from the import config
        $imageGalleryPattern = '{info[1]/images[1]/image[1]}{info[1]/images[1]/image[2]}{info[1]/images[1]/image[3]}{info[1]/images[1]/image[4]}{info[1]/images[1]/image[5]}';

        // Test wpezimport_parse_field_pattern_all for multi-value extraction
        $results = wpezimport_parse_field_pattern_all($item, $imageGalleryPattern);

        $this->assertIsArray($results);
        $this->assertCount(5, $results, 'Should extract 5 image URLs from indexed XPath pattern');

        // Verify the URLs are correct
        $this->assertStringContainsString('http://img.veikaliem.lv', $results[0]);
        $this->assertStringContainsString('WhatsApp', $results[0]);

        // Test with wpezimport_extract_image_urls
        $imageResults = wpezimport_extract_image_urls($item, $imageGalleryPattern);

        $this->assertArrayHasKey('urls', $imageResults);
        $this->assertCount(5, $imageResults['urls'], 'wpezimport_extract_image_urls should also extract 5 URLs');
    }

    /**
     * Test image gallery with indexed XPath on product with fewer images
     */
    public function testImageGalleryIndexedXpathPartialMatch() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // Fourth product (index 3) has only 1 image
        $item = $realProductItems[3];

        // Same pattern but product only has 1 image
        $imageGalleryPattern = '{info[1]/images[1]/image[1]}{info[1]/images[1]/image[2]}{info[1]/images[1]/image[3]}{info[1]/images[1]/image[4]}{info[1]/images[1]/image[5]}';

        $results = wpezimport_parse_field_pattern_all($item, $imageGalleryPattern);

        $this->assertIsArray($results);
        // Should only get 1 URL since product only has 1 image
        $this->assertCount(1, $results, 'Should extract only 1 image URL when product has fewer images');
        $this->assertStringContainsString('http://img.veikaliem.lv', $results[0]);
    }

    // =========================================================================
    // Tests for wpezimport_encode_url()
    // =========================================================================

    /**
     * Test URL encoding with spaces in filename
     */
    public function testEncodeUrlWithSpaces() {
        $url = 'http://img.veikaliem.lv/312/WhatsApp Image 2022-06-14 at 9.spm.243595-o1997684.jpeg';
        $encoded = wpezimport_encode_url($url);

        $this->assertStringNotContainsString(' ', $encoded, 'Encoded URL should not contain spaces');
        $this->assertStringContainsString('%20', $encoded, 'Spaces should be encoded as %20');
        $this->assertStringStartsWith('http://img.veikaliem.lv', $encoded);
    }

    /**
     * Test URL encoding preserves already valid URLs
     */
    public function testEncodeUrlPreservesValidUrl() {
        $url = 'http://example.com/path/to/image.jpg';
        $encoded = wpezimport_encode_url($url);

        $this->assertEquals('http://example.com/path/to/image.jpg', $encoded);
    }

    /**
     * Test URL encoding with special characters
     */
    public function testEncodeUrlWithSpecialCharacters() {
        $url = 'http://example.com/path/file[1].jpg';
        $encoded = wpezimport_encode_url($url);

        $this->assertStringNotContainsString('[', $encoded);
        $this->assertStringNotContainsString(']', $encoded);
        $this->assertStringContainsString('%5B', $encoded);
        $this->assertStringContainsString('%5D', $encoded);
    }

    /**
     * Test URL encoding with query string
     */
    public function testEncodeUrlWithQueryString() {
        $url = 'http://example.com/image.php?id=123&size=large';
        $encoded = wpezimport_encode_url($url);

        $this->assertStringContainsString('?id=123&size=large', $encoded);
    }

    /**
     * Test URL encoding with port number
     */
    public function testEncodeUrlWithPort() {
        $url = 'http://example.com:8080/path/image file.jpg';
        $encoded = wpezimport_encode_url($url);

        $this->assertStringContainsString(':8080', $encoded);
        $this->assertStringContainsString('%20', $encoded);
    }

    /**
     * Test URL encoding handles empty input
     */
    public function testEncodeUrlEmpty() {
        $this->assertEquals('', wpezimport_encode_url(''));
        $this->assertEquals('', wpezimport_encode_url(null));
    }

    /**
     * Test URL encoding with Cyrillic or unicode characters
     */
    public function testEncodeUrlWithUnicodeCharacters() {
        $url = 'http://example.com/путь/файл.jpg';
        $encoded = wpezimport_encode_url($url);

        // Should be URL encoded
        $this->assertStringStartsWith('http://example.com/', $encoded);
        // Original Cyrillic should not be present (should be encoded)
        $this->assertStringNotContainsString('путь', $encoded);
    }

    /**
     * Test URL encoding with already partially encoded URL
     */
    public function testEncodeUrlAlreadyPartiallyEncoded() {
        $url = 'http://example.com/path/file%20name with spaces.jpg';
        $encoded = wpezimport_encode_url($url);

        // Should not double-encode %20
        $this->assertStringNotContainsString('%2520', $encoded, 'Should not double-encode');
        $this->assertStringNotContainsString(' ', $encoded, 'All spaces should be encoded');
    }

    // =========================================================================
    // Tests for image URL extraction with encoding from test.xml
    // =========================================================================

    /**
     * Test that extracted image URLs from test.xml are properly encoded
     */
    public function testExtractImageUrlsEncodesSpaces() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // Second product has URLs with spaces
        $item = $realProductItems[1];

        $imageGalleryPattern = '{info[1]/images[1]/image[1]}';
        $result = wpezimport_extract_image_urls($item, $imageGalleryPattern);

        // Check raw URL contains space
        $this->assertStringContainsString(' ', $result['raw_urls'][0], 'Raw URL should contain space');

        // Check encoded URL does not contain space
        $this->assertStringNotContainsString(' ', $result['urls'][0], 'Encoded URL should not contain space');
        $this->assertStringContainsString('%20', $result['urls'][0], 'Encoded URL should contain %20');
    }

    /**
     * Test that all 5 image URLs from test.xml are properly encoded
     */
    public function testExtractAllImageUrlsEncodesSpaces() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // Second product has 5 images with spaces in URLs
        $item = $realProductItems[1];

        $imageGalleryPattern = '{info[1]/images[1]/image[1]}{info[1]/images[1]/image[2]}{info[1]/images[1]/image[3]}{info[1]/images[1]/image[4]}{info[1]/images[1]/image[5]}';
        $result = wpezimport_extract_image_urls($item, $imageGalleryPattern);

        $this->assertCount(5, $result['urls']);
        $this->assertCount(5, $result['raw_urls']);

        // All encoded URLs should not contain spaces
        foreach ($result['urls'] as $index => $url) {
            $this->assertStringNotContainsString(' ', $url, "URL at index $index should not contain space");
        }
    }

    /**
     * Test extracting URLs without encoding (for comparison/debugging)
     */
    public function testExtractImageUrlsWithoutEncoding() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        $item = $realProductItems[1];

        $imageGalleryPattern = '{info[1]/images[1]/image[1]}';
        $result = wpezimport_extract_image_urls($item, $imageGalleryPattern, false);

        // Without encoding, URLs should still contain spaces
        $this->assertStringContainsString(' ', $result['urls'][0], 'URL without encoding should contain space');
        $this->assertEquals($result['raw_urls'][0], $result['urls'][0], 'Without encoding, urls should equal raw_urls');
    }

    /**
     * Test image gallery with indexed XPath on product with no images
     */
    public function testImageGalleryIndexedXpathNoImages() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // First product (index 0) has empty images element
        $item = $realProductItems[0];

        $imageGalleryPattern = '{info[1]/images[1]/image[1]}{info[1]/images[1]/image[2]}{info[1]/images[1]/image[3]}{info[1]/images[1]/image[4]}{info[1]/images[1]/image[5]}';

        $results = wpezimport_parse_field_pattern_all($item, $imageGalleryPattern);

        $this->assertIsArray($results);
        $this->assertEmpty($results, 'Should return empty array when product has no images');
    }

    /**
     * Test that wpezimport_extract_image_urls returns empty arrays (not the pattern) when XPath doesn't match
     */
    public function testExtractImageUrlsReturnsEmptyNotPatternWhenNoMatch() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // First product (id=189389) has empty images element
        $item = $realProductItems[0];

        $imageGalleryPattern = '{info[1]/images[1]/image[1]}{info[1]/images[1]/image[2]}{info[1]/images[1]/image[3]}{info[1]/images[1]/image[4]}{info[1]/images[1]/image[5]}';

        $result = wpezimport_extract_image_urls($item, $imageGalleryPattern);

        // Should return empty arrays, NOT the pattern string
        $this->assertIsArray($result['urls']);
        $this->assertEmpty($result['urls'], 'urls should be empty when XPath does not match');
        $this->assertIsArray($result['raw_urls']);
        $this->assertEmpty($result['raw_urls'], 'raw_urls should be empty when XPath does not match');

        // Make sure the pattern string itself is not in the results
        foreach ($result['urls'] as $url) {
            $this->assertStringNotContainsString('{info', $url, 'Pattern should not appear in URLs');
            $this->assertStringNotContainsString('%7Binfo', $url, 'Encoded pattern should not appear in URLs');
        }
    }

    /**
     * Test that empty XPath results don't produce encoded curly braces
     */
    public function testEmptyXpathResultsNotUrlEncoded() {
        // Load test.xml fixture
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // First product has no images
        $item = $realProductItems[0];

        $pattern = '{info[1]/images[1]/image[1]}';

        // With encoding enabled
        $result = wpezimport_extract_image_urls($item, $pattern, true);

        // Should be empty, not contain encoded pattern like %7Binfo%5B1%5D
        $this->assertEmpty($result['urls'], 'Should be empty, not contain encoded pattern');

        // Also test parse_field_pattern_all directly
        $parsed = wpezimport_parse_field_pattern_all($item, $pattern, true);
        $this->assertEmpty($parsed, 'parse_field_pattern_all should return empty array');
    }

    // =========================================================================
    // Tests for wpezimport_is_url()
    // =========================================================================

    /**
     * Test URL detection
     */
    public function testIsUrl() {
        $this->assertTrue(wpezimport_is_url('http://example.com/image.jpg'));
        $this->assertTrue(wpezimport_is_url('https://example.com/image.jpg'));
        $this->assertTrue(wpezimport_is_url('HTTP://EXAMPLE.COM/IMAGE.JPG'));
        $this->assertTrue(wpezimport_is_url('http://img.veikaliem.lv/312/WhatsApp Image.jpeg'));

        $this->assertFalse(wpezimport_is_url(''));
        $this->assertFalse(wpezimport_is_url(null));
        $this->assertFalse(wpezimport_is_url('not a url'));
        $this->assertFalse(wpezimport_is_url('/path/to/file.jpg'));
        $this->assertFalse(wpezimport_is_url('ftp://example.com/file.jpg'));
    }

    // =========================================================================
    // Tests for early URL encoding in wpezimport_parse_field_pattern_all()
    // =========================================================================

    /**
     * Test that wpezimport_parse_field_pattern_all encodes URLs when requested
     */
    public function testParseFieldPatternAllEncodesUrls() {
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // Second product has URLs with spaces
        $item = $realProductItems[1];

        $pattern = '{info[1]/images[1]/image[1]}';

        // Without encoding
        $rawResults = wpezimport_parse_field_pattern_all($item, $pattern, false);
        $this->assertStringContainsString(' ', $rawResults[0], 'Raw result should contain space');

        // With encoding
        $encodedResults = wpezimport_parse_field_pattern_all($item, $pattern, true);
        $this->assertStringNotContainsString(' ', $encodedResults[0], 'Encoded result should not contain space');
        $this->assertStringContainsString('%20', $encodedResults[0], 'Encoded result should contain %20');
    }

    /**
     * Test that multiple XPath patterns with URLs are all encoded
     */
    public function testParseFieldPatternAllEncodesMultipleUrls() {
        $realXmlPath = dirname(__DIR__) . '/tests/fixtures/test.xml';
        $realXml = simplexml_load_file($realXmlPath);
        $realProductItems = $realXml->xpath('//product_output');

        // Second product has 5 images with spaces in URLs
        $item = $realProductItems[1];

        $pattern = '{info[1]/images[1]/image[1]}{info[1]/images[1]/image[2]}{info[1]/images[1]/image[3]}';

        $encodedResults = wpezimport_parse_field_pattern_all($item, $pattern, true);

        $this->assertCount(3, $encodedResults);

        // All URLs should be encoded (no spaces)
        foreach ($encodedResults as $index => $url) {
            $this->assertStringNotContainsString(' ', $url, "URL at index $index should not contain space");
        }
    }

    /**
     * Test that non-URL values are not encoded
     */
    public function testParseFieldPatternAllDoesNotEncodeNonUrls() {
        $item = $this->productItems[0];

        // Get a non-URL value like product code
        $pattern = '{code}';

        $rawResults = wpezimport_parse_field_pattern_all($item, $pattern, false);
        $encodedResults = wpezimport_parse_field_pattern_all($item, $pattern, true);

        // Non-URL values should be the same
        $this->assertEquals($rawResults[0], $encodedResults[0], 'Non-URL values should not be modified');
    }

    /**
     * Test processing all products from test.xml
     */
    public function testProcessAllTestProducts() {
        $field_mapping = array(
            'post_title' => '{info/title/lv}',
            '_sku' => '{code}',
            '_ean' => '{ean_code}',
            'unique_identifier_source' => '{id}'
        );

        $processed_count = 0;
        foreach ($this->productItems as $item) {
            $extracted = wpezimport_extract_mapped_data($item, $field_mapping);

            $this->assertArrayHasKey('post_title', $extracted);
            $this->assertArrayHasKey('_sku', $extracted);
            $this->assertArrayHasKey('unique_identifier_source', $extracted);

            $processed_count++;
        }

        // test.xml has 4 products
        $this->assertEquals(4, $processed_count);
    }

    // =========================================================================
    // Tests for wpezimport_find_repeating_elements()
    // =========================================================================

    /**
     * Test finding repeating elements in the test.xml structure
     */
    public function testFindRepeatingElementsInTestXml() {
        $repeating = wpezimport_find_repeating_elements($this->testXml);

        $this->assertIsArray($repeating);
        $this->assertNotEmpty($repeating, 'Should find repeating elements in test.xml');

        // Extract just the xpaths for easier assertion
        $xpaths = array_column($repeating, 'xpath');

        // product_output repeats 4 times under products_output
        $this->assertContains('/result/products_output/product_output', $xpaths,
            'Should detect product_output as repeating');
    }

    /**
     * Test finding repeating image elements
     */
    public function testFindRepeatingImagesInProduct() {
        // Get second product which has 5 images
        $product = $this->productItems[1];

        $repeating = wpezimport_find_repeating_elements($product);

        $xpaths = array_column($repeating, 'xpath');

        // image elements repeat under info/images
        $this->assertContains('/product_output/info/images/image', $xpaths,
            'Should detect image elements as repeating');

        // Find the image entry and check count
        foreach ($repeating as $element) {
            if ($element['xpath'] === '/product_output/info/images/image') {
                $this->assertEquals(5, $element['count'], 'Should find 5 image elements');
                break;
            }
        }
    }

    /**
     * Test finding repeating elements with empty XML
     */
    public function testFindRepeatingElementsEmpty() {
        $result = wpezimport_find_repeating_elements(null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test finding repeating elements in product with no repeating children
     */
    public function testFindRepeatingElementsNoRepeats() {
        // First product has empty images, so no repeating image elements
        $product = $this->productItems[0];

        $repeating = wpezimport_find_repeating_elements($product);
        $xpaths = array_column($repeating, 'xpath');

        // Should NOT find image as repeating since there are none
        $this->assertNotContains('/product_output/info/images/image', $xpaths,
            'Should not detect image as repeating when there are none');
    }

    /**
     * Test that repeating elements returns correct count
     */
    public function testFindRepeatingElementsCount() {
        $repeating = wpezimport_find_repeating_elements($this->testXml);

        // Find product_output entry
        foreach ($repeating as $element) {
            if ($element['xpath'] === '/result/products_output/product_output') {
                $this->assertEquals(4, $element['count'], 'Should find 4 product_output elements');
                return;
            }
        }

        $this->fail('product_output not found in repeating elements');
    }

    // =========================================================================
    // Tests for wpezimport_extract_unique_identifier()
    // =========================================================================

    /**
     * Test extracting unique identifier with simple XPath pattern
     */
    public function testExtractUniqueIdentifierSimple() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_unique_identifier($item, '{id}');

        $this->assertEquals('189389', $result);
    }

    /**
     * Test extracting unique identifier with indexed XPath
     */
    public function testExtractUniqueIdentifierIndexed() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_unique_identifier($item, '{id[1]}');

        $this->assertEquals('189389', $result);
    }

    /**
     * Test extracting unique identifier with nested XPath
     */
    public function testExtractUniqueIdentifierNested() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_unique_identifier($item, '{code}');

        $this->assertEquals('SZ1U29424DA BLACK/BLUE', $result);
    }

    /**
     * Test extracting unique identifier with composite pattern
     */
    public function testExtractUniqueIdentifierComposite() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_unique_identifier($item, '{id}-{ean_code}');

        $this->assertEquals('189389-8001446121702', $result);
    }

    /**
     * Test extracting unique identifier with empty pattern
     */
    public function testExtractUniqueIdentifierEmptyPattern() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_unique_identifier($item, '');

        $this->assertEquals('', $result);
    }

    /**
     * Test extracting unique identifier with null item
     */
    public function testExtractUniqueIdentifierNullItem() {
        $result = wpezimport_extract_unique_identifier(null, '{id}');

        $this->assertEquals('', $result);
    }

    /**
     * Test extracting unique identifier with non-matching XPath
     */
    public function testExtractUniqueIdentifierNoMatch() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_unique_identifier($item, '{non_existent_field}');

        $this->assertEquals('', $result);
    }

    /**
     * Test extracting unique identifier from all test products
     */
    public function testExtractUniqueIdentifierAllProducts() {
        $expected_ids = array('189389', '189393', '189395', '189408');

        foreach ($this->productItems as $index => $item) {
            $result = wpezimport_extract_unique_identifier($item, '{id}');
            $this->assertEquals($expected_ids[$index], $result,
                "Product $index should have ID {$expected_ids[$index]}");
        }
    }

    /**
     * Test extracting unique identifier with EAN code
     */
    public function testExtractUniqueIdentifierEan() {
        $item = $this->productItems[0];

        $result = wpezimport_extract_unique_identifier($item, '{ean_code}');

        $this->assertEquals('8001446121702', $result);
    }
}
