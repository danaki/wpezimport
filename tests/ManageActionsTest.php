<?php
/**
 * Unit tests for wpezimport_handle_manage_actions() (wpezimport.php)
 *
 * Covers the security fixes: capability check, nonce verification and
 * correct redirect targets for delete/run actions.
 */

class ManageActionsTest extends WP_Mock\Tools\TestCase {

    /** @var array Simulated wpdb state */
    private $rows;

    /** @var array */
    private $redirects;

    /** @var array */
    private $deleted;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->rows = array(
            1 => (object) array('id' => 1, 'type' => 'file'),
            2 => (object) array('id' => 2, 'type' => 'url'),
        );
        $this->redirects = array();
        $this->deleted   = array();

        global $wpdb;
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($q, $id) { return array('id' => $id); });
        $wpdb->shouldReceive('get_row')->andReturnUsing(function ($prepared) {
            $id = is_array($prepared) ? $prepared['id'] : 0;
            return isset($this->rows[$id]) ? $this->rows[$id] : null;
        });
        $wpdb->shouldReceive('delete')->andReturnUsing(function ($table, $where) {
            if (isset($this->rows[$where['id']])) {
                $this->deleted[] = $where['id'];
                return 1;
            }
            return false;
        });

        // wp_die must abort execution like the real one — simulate via exception
        WP_Mock::userFunction('wp_die', array(
            'return' => function ($msg = '') { throw new ManageActionsWpDie($msg); },
        ));
        WP_Mock::userFunction('wp_safe_redirect', array(
            'return' => function ($url) { $this->redirects[] = $url; throw new ManageActionsRedirect($url); },
        ));
        WP_Mock::userFunction('sanitize_key', array(
            'return' => function ($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $k)); },
        ));
        WP_Mock::userFunction('admin_url', array(
            'return' => function ($path = '') { return 'http://example.test/wp-admin/' . $path; },
        ));
        WP_Mock::userFunction('add_query_arg', array(
            'return' => function ($args, $url) { return $url . '?' . http_build_query($args); },
        ));
    }

    public function tearDown(): void {
        unset($GLOBALS['wpdb']);
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function getParams($page = 'wpezimport-manage', $action = 'delete', $id = 1) {
        $_GET = array('page' => $page, 'action' => $action, 'import_id' => $id);
    }

    // =========================================================================
    // Guard conditions
    // =========================================================================

    public function testIgnoresOtherPages() {
        $this->getParams('tools', 'delete', 1);
        wpezimport_handle_manage_actions();
        $this->assertEmpty($this->redirects);
        $this->assertEmpty($this->deleted);
    }

    public function testIgnoresMissingAction() {
        $_GET = array('page' => 'wpezimport-manage');
        wpezimport_handle_manage_actions();
        $this->assertEmpty($this->redirects);
    }

    public function testIgnoresUnknownAction() {
        $this->getParams('wpezimport-manage', 'explode', 1);
        wpezimport_handle_manage_actions();
        $this->assertEmpty($this->redirects);
        $this->assertEmpty($this->deleted);
    }

    // =========================================================================
    // Capability check
    // =========================================================================

    public function testDeleteDeniedWithoutCapability() {
        $this->getParams();
        WP_Mock::userFunction('current_user_can', array('return' => false));

        $this->expectException(ManageActionsWpDie::class);
        wpezimport_handle_manage_actions();
        // wp_die threw; nothing else may have run
    }

    // =========================================================================
    // Delete action
    // =========================================================================

    public function testDeleteRequiresValidNonce() {
        $this->getParams();
        WP_Mock::userFunction('current_user_can', array('return' => true));
        WP_Mock::userFunction('check_admin_referer', array(
            'return' => function () { throw new ManageActionsNonceFailed(); },
        ));

        $this->expectException(ManageActionsNonceFailed::class);
        wpezimport_handle_manage_actions();
        $this->assertEmpty($this->deleted);
    }

    public function testDeleteUsesPerImportNonceAction() {
        $this->getParams('wpezimport-manage', 'delete', 2);
        WP_Mock::userFunction('current_user_can', array('return' => true));

        $checked = array();
        WP_Mock::userFunction('check_admin_referer', array(
            'return' => function ($action) use (&$checked) { $checked[] = $action; return true; },
        ));

        try { wpezimport_handle_manage_actions(); } catch (ManageActionsRedirect $e) {}

        $this->assertEquals(array('wpezimport_delete_import_2'), $checked);
        $this->assertEquals(array(2), $this->deleted);
        $this->assertEquals('http://example.test/wp-admin/admin.php?page=wpezimport-manage&deleted=1', $this->redirects[0]);
    }

    public function testDeleteFailureRedirectsWithZero() {
        $this->getParams('wpezimport-manage', 'delete', 999);
        WP_Mock::userFunction('current_user_can', array('return' => true));
        WP_Mock::userFunction('check_admin_referer', array('return' => true));

        try { wpezimport_handle_manage_actions(); } catch (ManageActionsRedirect $e) {}

        $this->assertStringContainsString('deleted=0', $this->redirects[0]);
    }

    // =========================================================================
    // Run action
    // =========================================================================

    public function testRunFileImportRedirectsToUploadStep() {
        $this->getParams('wpezimport-manage', 'run', 1);
        WP_Mock::userFunction('current_user_can', array('return' => true));

        try { wpezimport_handle_manage_actions(); } catch (ManageActionsRedirect $e) {}

        $this->assertEquals('http://example.test/wp-admin/admin.php?page=wpezimport-new&run_saved_import=1', $this->redirects[0]);
    }

    public function testRunUrlImportRedirectsToMappingStep() {
        $this->getParams('wpezimport-manage', 'run', 2);
        WP_Mock::userFunction('current_user_can', array('return' => true));

        try { wpezimport_handle_manage_actions(); } catch (ManageActionsRedirect $e) {}

        $this->assertEquals('http://example.test/wp-admin/admin.php?page=wpezimport-new&step=2&edit_import=2', $this->redirects[0]);
    }

    public function testRunMissingImportRedirectsBack() {
        $this->getParams('wpezimport-manage', 'run', 999);
        WP_Mock::userFunction('current_user_can', array('return' => true));

        try { wpezimport_handle_manage_actions(); } catch (ManageActionsRedirect $e) {}

        $this->assertEquals('http://example.test/wp-admin/admin.php?page=wpezimport-manage', $this->redirects[0]);
    }
}

class ManageActionsWpDie extends Exception {}
class ManageActionsRedirect extends Exception {}
class ManageActionsNonceFailed extends Exception {}
