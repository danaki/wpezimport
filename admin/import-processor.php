<?php
/**
 * Import processor functions
 *
 * This file contains functions for processing imports and saving import configurations
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// ─── Draft Import State (replaces $_SESSION) ───────────────────────────────

/**
 * Create a new draft import row in wpezimport_imports.
 *
 * @param array $data Associative array of draft state values.
 * @return int|false The draft ID on success, false on failure.
 */
function wpezimport_create_draft( array $data = array() ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_imports';

    $row = array(
        'name'     => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : __( 'Draft Import', 'ezimport' ),
        'type'     => isset( $data['type'] ) ? $data['type'] : 'file',
        'file'     => isset( $data['file'] ) ? $data['file'] : '',
        'url'      => isset( $data['url'] )  ? $data['url']  : '',
        'options'  => serialize( isset( $data['state'] ) ? $data['state'] : array() ),
        'created'  => current_time( 'mysql' ),
        'modified' => current_time( 'mysql' ),
    );

    $result = $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
    return $result ? $wpdb->insert_id : false;
}

/**
 * Get a draft import by ID.
 *
 * @param int $draft_id
 * @return object|false  Object with ->id, ->type, ->file, ->url, ->state (array).
 */
function wpezimport_get_draft( $draft_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_imports';

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $draft_id ) );
    if ( ! $row ) {
        return false;
    }
    $row->state = is_string( $row->options ) ? maybe_unserialize( $row->options ) : array();
    if ( ! is_array( $row->state ) ) {
        $row->state = array();
    }
    return $row;
}

/**
 * Update a draft import's state (merges with existing state).
 *
 * @param int   $draft_id
 * @param array $data  Keys: 'type','file','url' go to columns; everything else merges into state.
 * @return bool
 */
function wpezimport_update_draft( $draft_id, array $data ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_imports';

    $draft = wpezimport_get_draft( $draft_id );
    if ( ! $draft ) {
        return false;
    }

    $columns = array();
    $formats = array();

    // Top-level column updates
    foreach ( array( 'name', 'type', 'file', 'url' ) as $col ) {
        if ( array_key_exists( $col, $data ) ) {
            $columns[ $col ] = $data[ $col ];
            $formats[] = '%s';
            unset( $data[ $col ] );
        }
    }

    // Merge remaining keys into state
    if ( ! empty( $data ) ) {
        $state = array_merge( $draft->state, $data );
        $columns['options'] = serialize( $state );
        $formats[] = '%s';
    }

    if ( empty( $columns ) ) {
        return true; // nothing to update
    }

    $columns['modified'] = current_time( 'mysql' );
    $formats[] = '%s';

    return $wpdb->update( $table, $columns, array( 'id' => $draft_id ), $formats, array( '%d' ) ) !== false;
}

/**
 * Replace the entire state array for a draft (does not merge, overwrites).
 *
 * @param int   $draft_id
 * @param array $state
 * @return bool
 */
function wpezimport_set_draft_state( $draft_id, array $state ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_imports';

    return $wpdb->update(
        $table,
        array( 'options' => serialize( $state ), 'modified' => current_time( 'mysql' ) ),
        array( 'id' => $draft_id ),
        array( '%s', '%s' ),
        array( '%d' )
    ) !== false;
}

/**
 * Delete a draft import.
 *
 * @param int $draft_id
 * @return bool
 */
function wpezimport_delete_draft( $draft_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_imports';
    return $wpdb->delete( $table, array( 'id' => $draft_id ), array( '%d' ) ) !== false;
}

// ─── Saved Import CRUD ──────────────────────────────────────────────────────

/**
 * Save import configuration to database
 *
 * @param string $name Name of the import configuration
 * @param string $type Type of import (file or url)
 * @param string $post_type Target post type
 * @param array $options Import options including mappings
 * @param string $file_path Path to file (for file imports)
 * @param string $url URL of file (for url imports)
 * @return int|false The ID of the saved import on success, false on failure
 */
function wpezimport_save_import($name, $type, $post_type, $options, $file_path = '', $url = '') {
    global $wpdb;

    // Sanitize inputs
    $name = sanitize_text_field($name);
    $type = in_array($type, array('file', 'url')) ? $type : 'file';
    $post_type = sanitize_key($post_type);

    // Use a generic name if none provided
    if (empty($name)) {
        $name = __('Import created on ', 'ezimport') . date_i18n(get_option('date_format'));
    }

    // Serialize options array for storage
    $serialized_options = serialize($options);

    // Insert into database
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';
    $result = $wpdb->insert(
        $table_name,
        array(
            'name' => $name,
            'type' => $type,
            'post_type' => $post_type,
            'file_path' => $file_path,
            'url' => $url,
            'options' => $serialized_options,
            'created_at' => current_time('mysql')
        ),
        array(
            '%s', '%s', '%s', '%s', '%s', '%s', '%s'
        )
    );

    if ($result) {
        return $wpdb->insert_id;
    }

    return false;
}

/**
 * Get saved import by ID
 *
 * @param int $import_id ID of the saved import to retrieve
 * @return object|false Import data object or false if not found
 */
function wpezimport_get_import($import_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';

    $import = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $import_id
    ));

    if ($import) {
        // Unserialize options
        $import->options_array = maybe_unserialize($import->options);
        return $import;
    }

    return false;
}

/**
 * Get all saved imports
 *
 * @return array Array of import objects
 */
function wpezimport_get_all_imports() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';

    $imports = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

    if ($imports) {
        foreach ($imports as &$import) {
            // Unserialize options
            $import->options_array = maybe_unserialize($import->options);
        }
    }

    return $imports ?: array();
}

/**
 * Delete saved import by ID
 *
 * @param int $import_id ID of the import to delete
 * @return bool True on success, false on failure
 */
function wpezimport_delete_import($import_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';

    $result = $wpdb->delete(
        $table_name,
        array('id' => $import_id),
        array('%d')
    );

    return $result !== false;
}

/**
 * Update the last run timestamp for an import
 *
 * @param int $import_id ID of the import to update
 * @return bool True on success, false on failure
 */
function wpezimport_update_last_run($import_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';

    $result = $wpdb->update(
        $table_name,
        array('last_run' => current_time('mysql')),
        array('id' => $import_id),
        array('%s'),
        array('%d')
    );

    return $result !== false;
}

/**
 * Save import statistics to the database
 *
 * @param int $import_id ID of the import
 * @param array $statistics Statistics data to save
 * @return int|false The ID of the saved statistics on success, false on failure
 */
function wpezimport_save_statistics($import_id, $statistics) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_statistics';

    // Extract counts from statistics or use defaults
    $created = isset($statistics['created']) ? intval($statistics['created']) : 0;
    $updated = isset($statistics['updated']) ? intval($statistics['updated']) : 0;
    $errors = isset($statistics['errors']) ? intval($statistics['errors']) : 0;

    // Additional data as JSON if provided
    $statistics_json = null;
    if (!empty($statistics['additional_data'])) {
        $statistics_json = json_encode($statistics['additional_data']);
    }

    // Insert into database
    $result = $wpdb->insert(
        $table_name,
        array(
            'import_id' => $import_id,
            'run_date' => current_time('mysql'),
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'statistics_data' => $statistics_json
        ),
        array('%d', '%s', '%d', '%d', '%d', '%s')
    );

    if ($result) {
        return $wpdb->insert_id;
    }

    return false;
}

/**
 * Get statistics for a specific import
 *
 * @param int $import_id ID of the import
 * @param bool $get_all Whether to get all statistics for this import or just the most recent
 * @return array|object|false Statistics data object/array or false if not found
 */
function wpezimport_get_statistics($import_id, $get_all = false) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_statistics';

    if ($get_all) {
        // Get all statistics records for this import
        $statistics = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE import_id = %d ORDER BY run_date DESC",
            $import_id
        ));

        if ($statistics) {
            foreach ($statistics as &$stat) {
                if (!empty($stat->statistics_data)) {
                    $stat->additional_data = json_decode($stat->statistics_data, true);
                }
            }
            return $statistics;
        }
    } else {
        // Get only the most recent statistics
        $statistics = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE import_id = %d ORDER BY run_date DESC LIMIT 1",
            $import_id
        ));

        if ($statistics) {
            if (!empty($statistics->statistics_data)) {
                $statistics->additional_data = json_decode($statistics->statistics_data, true);
            }
            return $statistics;
        }
    }

    return false;
}

/**
 * Create the import statistics table if it doesn't exist
 */
function wpezimport_create_statistics_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_statistics';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        import_id bigint(20) unsigned NOT NULL,
        run_date datetime NOT NULL,
        created int(11) NOT NULL DEFAULT 0,
        updated int(11) NOT NULL DEFAULT 0,
        errors int(11) NOT NULL DEFAULT 0,
        statistics_data longtext,
        PRIMARY KEY  (id),
        KEY import_id (import_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
