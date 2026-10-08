<?php
/**
 * Integration tests for wpezimport_handle_import() — the draft-based AJAX
 * entry point now delegates to wpezimport_process_import_batch() via a
 * temporary or existing wpezimport_saved_imports row.
 */

class HandleImportAdapterTest extends WP_Mock\Tools\TestCase {

    private $jsonResponse;
    private $fixture;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        // Work on a copy: the batch processor deletes the run file on completion
        $this->fixture = tempnam(sys_get_temp_dir(), 'wpez') . '.xml';
        copy(realpath(__DIR__ . '/fixtures/test.xml'), $this->fixture);
        $this->jsonResponse = null;

        $_POST = array(
            'wpezimport_import_nonce' => 'ok',
            'draft_id' => 1,
        );

        WP_Mock::userFunction('wp_verify_nonce', array('return' => true));
        WP_Mock::userFunction('current_user_can', array('return' => true));
        WP_Mock::userFunction('wp_send_json_error', array(
            'return' => function ($data) { throw new HandleImportJson('error', $data); },
        ));
        WP_Mock::userFunction('wp_send_json_success', array(
            'return' => function ($data) { throw new HandleImportJson('success', $data); },
        ));
        WP_Mock::passthruFunction('__');
        WP_Mock::passthruFunction('apply_filters');
        WP_Mock::userFunction('current_time', array('return' => '2024-01-01 00:00:00'));
        WP_Mock::userFunction('set_transient', array('return' => true));
        WP_Mock::userFunction('delete_transient', array('return' => true));
        WP_Mock::userFunction('maybe_unserialize', array(
            'return' => function ($v) {
                if (!is_string($v)) return $v;
                $r = @unserialize($v);
                return $r === false ? $v : $r;
            },
        ));
    }

    public function tearDown(): void {
        if (file_exists($this->fixture)) @unlink($this->fixture);
        unset($GLOBALS['wpdb']);
        $_POST = array();
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function draft(array $state) {
        $d = new stdClass();
        $d->id = 1;
        $d->state = $state;
        return $d;
    }

    private function baseState(array $overrides = array()) {
        return array_merge(array(
            'field_mapping' => array(
                'post_title' => '{info/title/en}',
                '_sku'       => '{code}',
            ),
            'import_options' => array(),
            'uploaded_file'  => $this->fixture,
            'is_xml'         => true,
            'xml_item_path'  => '//product_output',
        ), $overrides);
    }

    private function runHandler() {
        try {
            wpezimport_handle_import();
        } catch (HandleImportJson $e) {
            return $e->payload;
        }
        $this->fail('Expected wp_send_json_* to be called');
    }

    // =========================================================================
    // Guards
    // =========================================================================

    public function testMissingNonceFails() {
        unset($_POST['wpezimport_import_nonce']);
        WP_Mock::userFunction('wpezimport_get_draft', array('return' => $this->draft($this->baseState())));

        $r = $this->runHandler();
        $this->assertStringContainsString('Missing nonce', $r['message']);
    }

    public function testMissingDraftFails() {
        $_POST['draft_id'] = 0;
        $r = $this->runHandler();
        $this->assertStringContainsString('Missing draft ID', $r['message']);
    }

    public function testDraftNotFoundFails() {
        WP_Mock::userFunction('wpezimport_get_draft', array('return' => false));
        $r = $this->runHandler();
        $this->assertStringContainsString('Draft not found', $r['message']);
    }

    public function testDraftWithoutMappingFails() {
        WP_Mock::userFunction('wpezimport_get_draft', array('return' => $this->draft(array())));
        $r = $this->runHandler();
        $this->assertStringContainsString('Import data missing', $r['message']);
    }

    public function testMissingFileFails() {
        WP_Mock::userFunction('wpezimport_get_draft', array(
            'return' => $this->draft($this->baseState(array('uploaded_file' => '/nonexistent.xml'))),
        ));
        $r = $this->runHandler();
        $this->assertStringContainsString('no longer exists', $r['message']);
    }

    // =========================================================================
    // No unique identifier → all items skipped (legacy safety rule preserved)
    // =========================================================================

    public function testNoUniqueIdentifierSkipsAllItems() {
        WP_Mock::userFunction('wpezimport_get_draft', array(
            'return' => $this->draft($this->baseState()),
        ));

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 42;
        $wpdb->shouldReceive('insert')->once()->andReturn(1);
        $wpdb->shouldReceive('delete')->twice()->andReturn(1);

        $r = $this->runHandler();

        $this->assertEquals(4, $r['total_rows']);
        $this->assertEquals(0, $r['imported_count']);
        $this->assertEquals(4, $r['skipped_count']);
        $this->assertCount(4, $r['resolved_items']);
        $this->assertEquals('skipped', $r['resolved_items'][0]['resolution']['status']);
        $this->assertNotEmpty($r['warnings']);
    }

    // =========================================================================
    // Saved import path → full batch run
    // =========================================================================

    public function testSavedImportRunsBatchProcessor() {
        $savedRow = null;

        WP_Mock::userFunction('wpezimport_get_draft', array(
            'return' => $this->draft($this->baseState(array(
                'field_mapping' => array(
                    'post_title' => '{info/title/en}',
                    '_sku'       => '{code}',
                    'unique_identifier_source' => '{id}',
                ),
                'saved_import_id' => 7,
            ))),
        ));

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 0;
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql) {
            $args = func_get_args();
            array_shift($args);
            return vsprintf(str_replace('%d', '%s', $sql), $args);
        });
        $wpdb->shouldReceive('get_var')->andReturn(7);
        $wpdb->shouldReceive('update')->andReturnUsing(function ($table, $data) use (&$savedRow) {
            if (isset($data['options'])) {
                $savedRow = $data;
            }
            return 1;
        });
        $wpdb->shouldReceive('get_row')->andReturnUsing(function ($sql) use (&$savedRow) {
            if (strpos($sql, 'cron_log') !== false) {
                return (object) array('cron_log' => serialize(array()));
            }
            return (object) array(
                'id'               => 7,
                'type'             => 'file',
                'file_path'        => $savedRow ? $savedRow['file_path'] : '',
                'url'              => '',
                'is_xml'           => 1,
                'options'          => $savedRow ? $savedRow['options'] : serialize(array()),
                'cron_status'      => 'triggered',
                'cron_phase'       => 'items',
                'cron_started_at'  => '2024-01-01 00:00:00',
                'cron_last_item'   => 0,
                'cron_total_items' => 0,
                'cron_created'     => 0,
                'cron_updated'     => 0,
                'cron_errors'      => 0,
                'cron_log'         => serialize(array()),
            );
        });
        $wpdb->shouldReceive('insert')->andReturn(1);
        $wpdb->shouldReceive('delete')->andReturn(1);

        WP_Mock::userFunction('get_transient', array(
            'return' => function ($key) {
                return strpos($key, 'wpezimport_run_file_') === 0 ? $this->fixture : false;
            },
        ));
        WP_Mock::userFunction('get_posts', array('return' => array()));

        $nextId = 500;
        WP_Mock::userFunction('wp_insert_post', array(
            'return' => function ($data) use (&$nextId) { return $nextId++; },
        ));
        WP_Mock::userFunction('add_post_meta', array('return' => true));
        WP_Mock::userFunction('taxonomy_exists', array('return' => false));
        WP_Mock::userFunction('get_current_user_id', array('return' => 1));
        WP_Mock::userFunction('admin_url', array(
            'return' => function ($p = '') { return 'http://test/' . $p; },
        ));

        $r = $this->runHandler();

        $this->assertEquals(4, $r['total_rows']);
        $this->assertEquals(4, $r['created_count']);
        $this->assertEquals(4, $r['imported_count']);
        $this->assertEquals(0, $r['skipped_count']);
        $this->assertCount(4, $r['imported_ids']);
        $this->assertEquals('created', $r['resolved_items'][0]['resolution']['status']);
        $this->assertNotEmpty($savedRow, 'Adapter must write draft options into the saved import row');
        $this->assertArrayHasKey('statistics_url', $r);
        $this->assertStringContainsString('import_id=7', $r['statistics_url']);
    }
}

class HandleImportJson extends Exception {
    public $payload;
    public function __construct($status, $payload) {
        $this->payload = is_array($payload) ? $payload + array('_status' => $status) : $payload;
        $this->payload['_status'] = $status;
        parent::__construct($status);
    }
}
