<?php
/**
 * Unit tests for image helpers extracted from wpezimport_process_images():
 * - wpezimport_resolve_image_patterns()
 * - wpezimport_normalize_image_url()
 * - wpezimport_download_image()
 * - wpezimport_detect_image_extension()
 * - wpezimport_generate_image_filename()
 * - wpezimport_find_cached_image()
 * - wpezimport_extract_item_fields()
 */

class ImageHelpersTest extends WP_Mock\Tools\TestCase {

    private $allowedMimes = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp');
    private $maxSize = 20 * 1024 * 1024;

    /** @var string fake PNG (1x1) */
    private $pngData;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->pngData = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        WP_Mock::passthruFunction('__');
        WP_Mock::passthruFunction('apply_filters');
    }

    public function tearDown(): void {
        unset($GLOBALS['wpdb']);
        WP_Mock::tearDown();
        parent::tearDown();
    }

    // =========================================================================
    // wpezimport_normalize_image_url()
    // =========================================================================

    public function testNormalizeDecodesEntitiesThenEncodes() {
        list($orig, $enc) = wpezimport_normalize_image_url('http://x.lv/a.php?p=1&amp;c=2');

        $this->assertEquals('http://x.lv/a.php?p=1&c=2', $orig);
        $this->assertEquals('http://x.lv/a.php?p=1&c=2', $enc);
    }

    public function testNormalizeEncodesSpacesInPath() {
        list(, $enc) = wpezimport_normalize_image_url('http://img.lv/photos/my photo.jpg');
        $this->assertEquals('http://img.lv/photos/my%20photo.jpg', $enc);
    }

    public function testNormalizeFallbackForUnparseableUrl() {
        list($orig, $enc) = wpezimport_normalize_image_url('not a url {x}');
        $this->assertEquals('not a url {x}', $orig);
        $this->assertEquals('not%20a%20url%20%7Bx%7D', $enc);
    }

    // =========================================================================
    // wpezimport_resolve_image_patterns()
    // =========================================================================

    public function testPatternWithoutBracesPassedThrough() {
        $this->assertEquals(
            'http://a.jpg',
            wpezimport_resolve_image_patterns('http://a.jpg', null, 0)
        );
    }

    public function testPatternEvaluatedAgainstXmlItem() {
        $item = simplexml_load_string('<p><images><image>http://x/1.jpg</image><image>http://x/2.jpg</image></images></p>');
        $urls = wpezimport_resolve_image_patterns('{images/image}', $item, 0);

        $this->assertEquals('http://x/1.jpg http://x/2.jpg', $urls);
    }

    public function testPatternWithXmlString() {
        $urls = wpezimport_resolve_image_patterns('{img}', '<p><img>http://x/1.jpg</img></p>', 0);
        $this->assertEquals('http://x/1.jpg', $urls);
    }

    public function testPatternWithoutXmlItemReturnsEmpty() {
        $this->assertSame('', wpezimport_resolve_image_patterns('{img}', null, 0));
    }

    public function testBrokenXmlStringReturnsEmpty() {
        $this->assertSame('', wpezimport_resolve_image_patterns('{img}', '<not<closed>', 0));
    }

    public function testNonMatchingPatternReturnsEmpty() {
        $item = simplexml_load_string('<p><img>x</img></p>');
        $this->assertSame('', wpezimport_resolve_image_patterns('{missing}', $item, 0));
    }

    // =========================================================================
    // wpezimport_download_image()
    // =========================================================================

    private function mockHttp(array $headHeaders = array(), $body = null, $getError = null) {
        WP_Mock::userFunction('wp_remote_head', array(
            'return' => array('headers' => $headHeaders),
        ));
        WP_Mock::userFunction('wp_remote_get', array(
            'return' => $getError !== null ? $getError : array('headers' => array(), 'body' => $body, 'code' => 200),
        ));
        WP_Mock::userFunction('wp_remote_retrieve_header', array(
            'return' => function ($resp, $name) { return isset($resp['headers'][$name]) ? $resp['headers'][$name] : ''; },
        ));
        WP_Mock::userFunction('wp_remote_retrieve_body', array(
            'return' => function ($resp) { return $resp['body']; },
        ));
        WP_Mock::userFunction('wp_remote_retrieve_response_code', array(
            'return' => function ($resp) { return $resp['code']; },
        ));
    }

    public function testDownloadSuccess() {
        $this->mockHttp(array('content-type' => 'image/jpeg'), $this->pngData);
        $r = wpezimport_download_image('http://x/y.jpg', $this->allowedMimes, $this->maxSize);

        $this->assertIsArray($r);
        $this->assertSame($this->pngData, $r['data']);
        $this->assertEquals(200, $r['code']);
    }

    public function testDownloadRejectsNonImageContentType() {
        $this->mockHttp(array('content-type' => 'text/html; charset=utf-8'), $this->pngData);
        $r = wpezimport_download_image('http://x/y.jpg', $this->allowedMimes, $this->maxSize);

        $this->assertInstanceOf(WP_Error::class, $r);
        $this->assertEquals('bad_mime', $r->get_error_code());
    }

    public function testDownloadRejectsOversizedContentLength() {
        $this->mockHttp(array('content-type' => 'image/jpeg', 'content-length' => $this->maxSize + 1));
        $r = wpezimport_download_image('http://x/y.jpg', $this->allowedMimes, $this->maxSize);

        $this->assertInstanceOf(WP_Error::class, $r);
        $this->assertEquals('too_large', $r->get_error_code());
    }

    public function testDownloadRejectsOversizedBodyWhenHeadLies() {
        $this->mockHttp(array('content-type' => 'image/jpeg'), str_repeat('x', 100), null);
        $r = wpezimport_download_image('http://x/y.jpg', $this->allowedMimes, 50);

        $this->assertInstanceOf(WP_Error::class, $r);
        $this->assertEquals('too_large', $r->get_error_code());
    }

    public function testDownloadErrorPropagates() {
        $this->mockHttp(array(), null, new WP_Error('http_failed', 'boom'));
        $r = wpezimport_download_image('http://x/y.jpg', $this->allowedMimes, $this->maxSize);

        $this->assertInstanceOf(WP_Error::class, $r);
        $this->assertEquals('http_failed', $r->get_error_code());
    }

    public function testDownloadEmptyBodyError() {
        $this->mockHttp(array('content-type' => 'image/png'), '');
        $r = wpezimport_download_image('http://x/y.png', $this->allowedMimes, $this->maxSize);

        $this->assertInstanceOf(WP_Error::class, $r);
        $this->assertEquals('empty_body', $r->get_error_code());
    }

    // =========================================================================
    // wpezimport_detect_image_extension()
    // =========================================================================

    private function respWithType($type) {
        return array('headers' => array('content-type' => $type));
    }

    public function testExtensionFromContentType() {
        WP_Mock::userFunction('wp_remote_retrieve_header', array(
            'return' => function ($resp, $name) { return isset($resp['headers'][$name]) ? $resp['headers'][$name] : ''; },
        ));

        $r = wpezimport_detect_image_extension($this->respWithType('image/jpeg; charset=binary'), 'http://x/file', 'x');
        $this->assertEquals('jpg', $r['extension']);
    }

    public function testExtensionFallsBackToUrlPath() {
        WP_Mock::userFunction('wp_remote_retrieve_header', array('return' => ''));
        $r = wpezimport_detect_image_extension(null, 'http://x/photo.PNG', 'x');
        $this->assertEquals('png', $r['extension']);
    }

    public function testExtensionFromMagicBytes() {
        WP_Mock::userFunction('wp_remote_retrieve_header', array('return' => ''));
        $r = wpezimport_detect_image_extension(null, 'http://x/noext', $this->pngData);

        $this->assertEquals('png', $r['extension']);
        $this->assertEquals('image/png', $r['detected_mime']);
    }

    public function testExtensionUndetectable() {
        WP_Mock::userFunction('wp_remote_retrieve_header', array('return' => ''));
        $r = wpezimport_detect_image_extension(null, 'http://x/file.txt', 'plain text');

        $this->assertEquals('', $r['extension']);
        $this->assertEquals('text/plain', $r['detected_mime']);
    }

    public function testContentTypeTakesPrecedenceOverUrl() {
        WP_Mock::userFunction('wp_remote_retrieve_header', array(
            'return' => function ($resp, $name) { return $resp['headers'][$name]; },
        ));
        $r = wpezimport_detect_image_extension($this->respWithType('image/webp'), 'http://x/f.jpg', $this->pngData);
        $this->assertEquals('webp', $r['extension']);
    }

    // =========================================================================
    // wpezimport_generate_image_filename()
    // =========================================================================

    public function testFilenameFromBase() {
        $this->assertEquals('mountain-bike-1.jpg', wpezimport_generate_image_filename('http://x/y.png', 'jpg', 'mountain-bike', 0));
        $this->assertEquals('mountain-bike-3.png', wpezimport_generate_image_filename('http://x/y.png', 'png', 'mountain-bike', 2));
    }

    public function testFilenameFromUrl() {
        $this->assertEquals('photo.webp', wpezimport_generate_image_filename('http://x/photos/photo.jpg', 'webp', '', 0));
    }

    public function testFilenameFallbackForIndex() {
        $f = wpezimport_generate_image_filename('http://x/index.php?i=5', 'jpg', '', 0);
        $this->assertMatchesRegularExpression('/^image-\d+-\d{4}\.jpg$/', $f);
    }

    public function testFilenameFallbackForExtensionless() {
        $f = wpezimport_generate_image_filename('http://x/', 'png', '', 0);
        $this->assertMatchesRegularExpression('/^image-\d+-\d{4}\.png$/', $f);
    }

    // =========================================================================
    // wpezimport_find_cached_image()
    // =========================================================================

    private function mockDb($cacheRow = null, $attachPost = null, $attachFile = null) {
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($q, $url) { return $url; });
        $wpdb->shouldReceive('get_row')->andReturn($cacheRow);
        $wpdb->shouldReceive('delete')->andReturn(1)->byDefault();

        WP_Mock::userFunction('get_post', array('return' => $attachPost));
        WP_Mock::userFunction('get_attached_file', array('return' => $attachFile));
    }

    public function testCacheMissReturnsDownload() {
        $this->mockDb(null);
        $ids = array(9 => 9);
        $this->assertEquals(array('mode' => 'download'), wpezimport_find_cached_image('http://x/a.jpg', 1, $ids));
    }

    public function testCacheHitSamePostReturnsAttached() {
        $row = (object) array('id' => 5, 'attachment_id' => 77);
        $this->mockDb($row, (object) array('post_parent' => 1));

        $ids = array(77 => 77, 9 => 9);
        $r = wpezimport_find_cached_image('http://x/a.jpg', 1, $ids);

        $this->assertEquals('attached', $r['mode']);
        $this->assertEquals(77, $r['attachment_id']);
        $this->assertArrayNotHasKey(77, $ids, 'Attachment must be excluded from cleanup');
        $this->assertArrayHasKey(9, $ids);
    }

    public function testCacheHitOtherPostWithFileReturnsCopy() {
        $tmp = tempnam(sys_get_temp_dir(), 'wpez') . '.jpg';
        file_put_contents($tmp, 'img-bytes');

        $row = (object) array('id' => 5, 'attachment_id' => 77);
        $this->mockDb($row, (object) array('post_parent' => 99), $tmp);

        $ids = array();
        $r = wpezimport_find_cached_image('http://x/a.jpg', 1, $ids);
        @unlink($tmp);

        $this->assertEquals('copy', $r['mode']);
        $this->assertEquals('img-bytes', $r['data']);
    }

    public function testCacheHitOtherPostMissingFileReturnsDownload() {
        $row = (object) array('id' => 5, 'attachment_id' => 77);
        $this->mockDb($row, (object) array('post_parent' => 99), '/nonexistent/file.jpg');

        $ids = array();
        $this->assertEquals(array('mode' => 'download'), wpezimport_find_cached_image('http://x/a.jpg', 1, $ids));
    }

    public function testCacheHitAttachmentGoneDeletesRowAndDownloads() {
        global $wpdb;
        $row = (object) array('id' => 5, 'attachment_id' => 77);
        $this->mockDb($row, null);
        $wpdb->shouldReceive('delete')->with('wp_wpezimport_images', array('id' => 5), array('%d'))->once();

        $ids = array();
        $this->assertEquals(array('mode' => 'download'), wpezimport_find_cached_image('http://x/a.jpg', 1, $ids));
    }

    // =========================================================================
    // wpezimport_batch_extract_value() multi-token parity
    // =========================================================================

    private function item() {
        return simplexml_load_string(<<<XML
<product>
    <id>12345</id>
    <info><title><en>Bike</en></title></info>
    <price>99.90</price>
</product>
XML
        );
    }

    public function testMultiTokenPatternSubstitutesAllTokens() {
        // Regression: legacy replaced every {token}; batch previously took only the first
        $this->assertEquals('12345-99.90', wpezimport_batch_extract_value($this->item(), '{id}-{price}'));
        $this->assertEquals('Bike', wpezimport_batch_extract_value($this->item(), '{info/title/en}'));
        $this->assertEquals('12345', wpezimport_batch_extract_value($this->item(), '{id}'));
    }

    public function testNonBracePatternsStillWork() {
        $this->assertEquals('12345', wpezimport_batch_extract_value($this->item(), 'id'));
        $this->assertEquals('Bike', wpezimport_batch_extract_value($this->item(), 'info/title/en'));
    }

    public function testMissingTokenReplacedWithEmpty() {
        $this->assertEquals('12345--99.90', wpezimport_batch_extract_value($this->item(), '{id}-{missing}-{price}'));
        $this->assertEquals('', wpezimport_batch_extract_value($this->item(), '{missing}'));
    }
}
