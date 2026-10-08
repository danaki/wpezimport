<?php
/**
 * Unit tests for UrlEncoder class
 *
 * Tests URL encoding and validation functions.
 */

use Wpezimport\Wpezimport\UrlEncoder;

class UrlEncoderTest extends WP_Mock\Tools\TestCase {

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
    // Tests for isUrl()
    // =========================================================================

    /**
     * Test valid HTTP URLs are recognized
     */
    public function testIsUrlValidHttp() {
        $this->assertTrue(UrlEncoder::isUrl('http://example.com'));
        $this->assertTrue(UrlEncoder::isUrl('http://example.com/path'));
        $this->assertTrue(UrlEncoder::isUrl('http://example.com/path/to/file.jpg'));
        $this->assertTrue(UrlEncoder::isUrl('http://example.com:8080/path'));
    }

    /**
     * Test valid HTTPS URLs are recognized
     */
    public function testIsUrlValidHttps() {
        $this->assertTrue(UrlEncoder::isUrl('https://example.com'));
        $this->assertTrue(UrlEncoder::isUrl('https://secure.example.com/path'));
        $this->assertTrue(UrlEncoder::isUrl('https://example.com/path/to/file.jpg'));
    }

    /**
     * Test URLs with leading/trailing whitespace
     */
    public function testIsUrlWithWhitespace() {
        $this->assertTrue(UrlEncoder::isUrl('  http://example.com  '));
        $this->assertTrue(UrlEncoder::isUrl("\thttp://example.com\n"));
    }

    /**
     * Test case insensitivity of scheme
     */
    public function testIsUrlCaseInsensitive() {
        $this->assertTrue(UrlEncoder::isUrl('HTTP://example.com'));
        $this->assertTrue(UrlEncoder::isUrl('HTTPS://example.com'));
        $this->assertTrue(UrlEncoder::isUrl('Http://example.com'));
    }

    /**
     * Test non-URL strings return false
     */
    public function testIsUrlInvalidStrings() {
        $this->assertFalse(UrlEncoder::isUrl('not a url'));
        $this->assertFalse(UrlEncoder::isUrl('example.com'));
        $this->assertFalse(UrlEncoder::isUrl('/path/to/file.jpg'));
        $this->assertFalse(UrlEncoder::isUrl('ftp://example.com')); // Only http/https
        $this->assertFalse(UrlEncoder::isUrl('file:///path/to/file'));
    }

    /**
     * Test empty and null values return false
     */
    public function testIsUrlEmptyValues() {
        $this->assertFalse(UrlEncoder::isUrl(''));
        $this->assertFalse(UrlEncoder::isUrl(null));
        $this->assertFalse(UrlEncoder::isUrl(0));
    }

    // =========================================================================
    // Tests for encode()
    // =========================================================================

    /**
     * Test encoding URL with spaces in path
     */
    public function testEncodeUrlWithSpaces() {
        $url = 'http://example.com/path/file name.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringNotContainsString(' ', $encoded);
        $this->assertStringContainsString('%20', $encoded);
        $this->assertEquals('http://example.com/path/file%20name.jpg', $encoded);
    }

    /**
     * Test encoding URL with multiple spaces
     */
    public function testEncodeUrlWithMultipleSpaces() {
        $url = 'http://example.com/my path/my file name.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringNotContainsString(' ', $encoded);
        $this->assertEquals('http://example.com/my%20path/my%20file%20name.jpg', $encoded);
    }

    /**
     * Test that clean URLs remain unchanged
     */
    public function testEncodeCleanUrl() {
        $url = 'http://example.com/path/to/file.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertEquals($url, $encoded);
    }

    /**
     * Test encoding URL with special characters
     */
    public function testEncodeUrlWithSpecialCharacters() {
        $url = 'http://example.com/path/file[1].jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringContainsString('%5B', $encoded);
        $this->assertStringContainsString('%5D', $encoded);
    }

    /**
     * Test encoding preserves query string
     */
    public function testEncodePreservesQueryString() {
        $url = 'http://example.com/image.php?id=123&size=large';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringContainsString('?id=123&size=large', $encoded);
    }

    /**
     * Test query string values with spaces are percent-encoded
     */
    public function testEncodeQueryStringWithSpaces() {
        $encoded = UrlEncoder::encode('http://example.com/img.php?name=my file.jpg&size=large');

        $this->assertEquals('http://example.com/img.php?name=my%20file.jpg&size=large', $encoded);
    }

    /**
     * Test query string with non-ASCII characters is percent-encoded
     */
    public function testEncodeQueryStringWithUnicode() {
        $encoded = UrlEncoder::encode('http://example.com/search?q=велосипед');

        $this->assertEquals('http://example.com/search?q=' . rawurlencode('велосипед'), $encoded);
        $this->assertStringNotContainsString('велосипед', $encoded);
    }

    /**
     * Test already-encoded query values are not double-encoded
     */
    public function testEncodeQueryStringDoesNotDoubleEncode() {
        $encoded = UrlEncoder::encode('http://example.com/img.php?name=my%20file.jpg');

        $this->assertEquals('http://example.com/img.php?name=my%20file.jpg', $encoded);
    }

    /**
     * Test a value containing '=' keeps only the first '=' as separator
     */
    public function testEncodeQueryStringValueContainingEquals() {
        $encoded = UrlEncoder::encode('http://example.com/?expr=a=b');

        $this->assertEquals('http://example.com/?expr=a%3Db', $encoded);
    }

    /**
     * Test a key without a value and empty pairs
     */
    public function testEncodeQueryStringKeyOnlyAndEmptyPairs() {
        $encoded = UrlEncoder::encode('http://example.com/?flag&&id=1');

        $this->assertEquals('http://example.com/?flag&id=1', $encoded);
    }

    /**
     * Test real-world image URL from the fixture (query with '&' and '=')
     */
    public function testEncodeFixtureImageUrl() {
        $url = 'http://312.veikaliem.lv/img/s_product_image.php?public=241849-1-312-0&c=94a25';
        $this->assertEquals($url, UrlEncoder::encode($url));
    }

    /**
     * Test encoding preserves port number
     */
    public function testEncodePreservesPort() {
        $url = 'http://example.com:8080/path/file.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringContainsString(':8080', $encoded);
    }

    /**
     * Test encoding URL with fragment
     */
    public function testEncodePreservesFragment() {
        $url = 'http://example.com/page#section';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringContainsString('#section', $encoded);
    }

    /**
     * Test encoding HTTPS URL
     */
    public function testEncodeHttpsUrl() {
        $url = 'https://secure.example.com/path/file name.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringStartsWith('https://', $encoded);
        $this->assertStringContainsString('%20', $encoded);
    }

    /**
     * Test encoding empty URL returns empty string
     */
    public function testEncodeEmptyUrl() {
        $this->assertEquals('', UrlEncoder::encode(''));
        $this->assertEquals('', UrlEncoder::encode(null));
    }

    /**
     * Test encoding URL with unicode characters
     */
    public function testEncodeUrlWithUnicode() {
        $url = 'http://example.com/путь/файл.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringStartsWith('http://example.com/', $encoded);
        // Cyrillic should be encoded
        $this->assertStringNotContainsString('путь', $encoded);
    }

    /**
     * Test encoding already partially encoded URL doesn't double-encode
     */
    public function testEncodePartiallyEncodedUrl() {
        $url = 'http://example.com/path/file%20name with spaces.jpg';
        $encoded = UrlEncoder::encode($url);

        // Should not double-encode %20
        $this->assertStringNotContainsString('%2520', $encoded);
        // All spaces should be encoded
        $this->assertStringNotContainsString(' ', $encoded);
    }

    /**
     * Test encoding URL with user credentials
     */
    public function testEncodeUrlWithCredentials() {
        $url = 'http://user:pass@example.com/path/file.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringContainsString('@example.com', $encoded);
    }

    /**
     * Test encoding malformed URL does basic encoding
     */
    public function testEncodeMalformedUrl() {
        $url = 'not really a url with spaces';
        $encoded = UrlEncoder::encode($url);

        // Should still encode spaces
        $this->assertStringNotContainsString(' ', $encoded);
        $this->assertStringContainsString('%20', $encoded);
    }

    /**
     * Test real-world URL with WhatsApp image filename
     */
    public function testEncodeWhatsAppImageUrl() {
        $url = 'http://img.veikaliem.lv/312/WhatsApp Image 2022-06-14 at 9.spm.243595-o1997684.jpeg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringNotContainsString(' ', $encoded);
        $this->assertStringContainsString('WhatsApp%20Image', $encoded);
        $this->assertStringStartsWith('http://img.veikaliem.lv', $encoded);
    }

    // =========================================================================
    // Tests for decode()
    // =========================================================================

    /**
     * Test decoding URL-encoded string
     */
    public function testDecodeEncodedUrl() {
        $encoded = 'http://example.com/path/file%20name.jpg';
        $decoded = UrlEncoder::decode($encoded);

        $this->assertEquals('http://example.com/path/file name.jpg', $decoded);
    }

    /**
     * Test decoding preserves clean URLs
     */
    public function testDecodeCleanUrl() {
        $url = 'http://example.com/path/file.jpg';
        $decoded = UrlEncoder::decode($url);

        $this->assertEquals($url, $decoded);
    }

    /**
     * Test decoding multiple encoded characters
     */
    public function testDecodeMultipleEncodings() {
        $encoded = 'http://example.com/path%2Fto%2Ffile%20name.jpg';
        $decoded = UrlEncoder::decode($encoded);

        $this->assertEquals('http://example.com/path/to/file name.jpg', $decoded);
    }

    // =========================================================================
    // Round-trip tests
    // =========================================================================

    /**
     * Test encode then decode returns original (for simple cases)
     */
    public function testEncodeDecodeRoundTrip() {
        $original = 'http://example.com/path/file name.jpg';
        $encoded = UrlEncoder::encode($original);
        $decoded = UrlEncoder::decode($encoded);

        $this->assertEquals($original, $decoded);
    }

    /**
     * Test round-trip with complex URL
     */
    public function testEncodeDecodeRoundTripComplex() {
        $original = 'http://example.com/my path/file [1].jpg';
        $encoded = UrlEncoder::encode($original);
        $decoded = UrlEncoder::decode($encoded);

        $this->assertEquals($original, $decoded);
    }

    // =========================================================================
    // Edge case tests
    // =========================================================================

    /**
     * Test encoding URL with only domain
     */
    public function testEncodeUrlDomainOnly() {
        $url = 'http://example.com';
        $encoded = UrlEncoder::encode($url);

        $this->assertEquals('http://example.com', $encoded);
    }

    /**
     * Test encoding URL with trailing slash
     */
    public function testEncodeUrlTrailingSlash() {
        $url = 'http://example.com/path/';
        $encoded = UrlEncoder::encode($url);

        $this->assertEquals('http://example.com/path/', $encoded);
    }

    /**
     * Test encoding URL with multiple slashes
     */
    public function testEncodeUrlMultipleSlashes() {
        $url = 'http://example.com//path///file.jpg';
        $encoded = UrlEncoder::encode($url);

        // Should preserve slash structure
        $this->assertStringContainsString('//path///', $encoded);
    }

    /**
     * Test encoding URL with subdomain
     */
    public function testEncodeUrlWithSubdomain() {
        $url = 'http://img.cdn.example.com/path/file name.jpg';
        $encoded = UrlEncoder::encode($url);

        $this->assertStringStartsWith('http://img.cdn.example.com', $encoded);
        $this->assertStringContainsString('%20', $encoded);
    }
}
