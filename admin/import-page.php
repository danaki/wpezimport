<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Load data from draft
$draft_id = isset($_GET['draft_id']) ? intval($_GET['draft_id']) : 0;
$draft = ($draft_id > 0) ? wpezimport_get_draft($draft_id) : false;
$ds = $draft ? $draft->state : array();

$uploaded_file = isset($ds['uploaded_file']) ? $ds['uploaded_file'] : null;
$field_mapping = isset($ds['field_mapping']) ? $ds['field_mapping'] : array();
$import_options = isset($ds['import_options']) ? $ds['import_options'] : array();
$is_xml = isset($ds['is_xml']) ? $ds['is_xml'] : false;

// If no mapping data, redirect to step 2
if (!$uploaded_file || empty($field_mapping)) {
    wp_redirect(admin_url('admin.php?page=wpezimport-new&step=2'));
    exit;
}
?>

<div class="wrap wpezimport-admin-container">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="wpezimport-intro">
        <p><?php esc_html_e('Step 3: Import Data', 'ezimport-for-woocommerce'); ?></p>
    </div>

    <div class="wpezimport-card">
        <h2><?php esc_html_e('Import Settings', 'ezimport-for-woocommerce'); ?></h2>
        <p><?php esc_html_e('You are about to import data from the file:', 'ezimport-for-woocommerce'); ?> <strong><?php echo esc_html(basename($uploaded_file)); ?></strong></p>

        <div class="wpezimport-mapping-summary">
            <h3><?php esc_html_e('Field Mapping Patterns', 'ezimport-for-woocommerce'); ?></h3>
            <div class="wpezimport-table-wrapper">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WordPress Field', 'ezimport-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Pattern', 'ezimport-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Define field display names
                        $field_names = array(
                            'post_title' => __('Post Title', 'ezimport-for-woocommerce'),
                            'post_content' => __('Post Content', 'ezimport-for-woocommerce'),
                            'post_excerpt' => __('Post Excerpt', 'ezimport-for-woocommerce'),
                            'post_status' => __('Post Status', 'ezimport-for-woocommerce'),
                            'post_name' => __('Post Slug', 'ezimport-for-woocommerce'),
                            'post_date' => __('Post Date', 'ezimport-for-woocommerce'),
                            'post_modified' => __('Post Modified Date', 'ezimport-for-woocommerce'),
                            'post_type' => __('Post Type', 'ezimport-for-woocommerce'),
                            'post_category' => __('Categories', 'ezimport-for-woocommerce'),
                            'tags_input' => __('Tags', 'ezimport-for-woocommerce'),
                            'menu_order' => __('Menu Order', 'ezimport-for-woocommerce'),
                            'comment_status' => __('Comment Status', 'ezimport-for-woocommerce'),
                            'ping_status' => __('Ping Status', 'ezimport-for-woocommerce'),
                            'post_parent' => __('Parent Post ID', 'ezimport-for-woocommerce'),
                            'post_password' => __('Post Password', 'ezimport-for-woocommerce'),
                        );

                        // Display standard WordPress fields
                        foreach ($field_mapping as $field_id => $pattern) :
                            // Check if not a meta field and pattern is not empty
                            if (!empty($pattern) && strpos((string)$field_id, 'meta_') !== 0) :
                        ?>
                            <tr>
                                <td><?php echo isset($field_names[$field_id]) ? esc_html($field_names[$field_id]) : esc_html($field_id); ?></td>
                                <td><?php echo esc_html($pattern); ?></td>
                            </tr>
                        <?php
                            endif;
                        endforeach;

                        // Display custom fields (meta)
                        $custom_fields = array_filter($field_mapping, function($key) {
                            // Cast to string inline to avoid null issues with strpos
                            return strpos((string)$key, 'meta_') === 0;
                        }, ARRAY_FILTER_USE_KEY);

                        if (!empty($custom_fields)) :
                        ?>
                            <tr>
                                <th colspan="2"><?php esc_html_e('Custom Fields', 'ezimport-for-woocommerce'); ?></th>
                            </tr>
                            <?php foreach ($custom_fields as $meta_key => $pattern) :
                                if (!empty($pattern)) :
                                    // Extract the actual meta key from the field ID
                                    $actual_meta_key = substr($meta_key, 5); // Remove 'meta_' prefix
                            ?>
                                <tr>
                                    <td><?php echo esc_html($actual_meta_key); ?></td>
                                    <td><code><?php echo esc_html($pattern); ?></code></td>
                                </tr>
                            <?php
                                endif;
                            endforeach;
                        endif;
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="wpezimport-import-options-summary">
            <h3><?php esc_html_e('Import Options', 'ezimport-for-woocommerce'); ?></h3>
            <ul>
                <li>
                    <strong><?php esc_html_e('Default Post Type:', 'ezimport-for-woocommerce'); ?></strong>
                    <?php
                    $default_post_type = isset($import_options['default_post_type']) ? $import_options['default_post_type'] : 'post';
                    $post_type_obj = get_post_type_object($default_post_type);
                    echo esc_html($post_type_obj ? $post_type_obj->labels->singular_name : $default_post_type);
                    ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Default Post Status:', 'ezimport-for-woocommerce'); ?></strong>
                    <?php
                    $default_post_status = isset($import_options['default_post_status']) ? $import_options['default_post_status'] : 'draft';
                    $post_statuses = get_post_statuses();
                    echo isset($post_statuses[$default_post_status]) ?
                        esc_html($post_statuses[$default_post_status]) :
                        esc_html($default_post_status);
                    ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Default Author:', 'ezimport-for-woocommerce'); ?></strong>
                    <?php
                    $default_author = isset($import_options['default_author']) ? $import_options['default_author'] : get_current_user_id();
                    $author = get_userdata($default_author);
                    echo $author ? esc_html($author->display_name) : esc_html__('Unknown Author', 'ezimport-for-woocommerce');
                    ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Skip First Row:', 'ezimport-for-woocommerce'); ?></strong>
                    <?php
                    $skip_first_row = isset($import_options['skip_first_row']) ? $import_options['skip_first_row'] : true;
                    echo $skip_first_row ? esc_html__('Yes', 'ezimport-for-woocommerce') : esc_html__('No', 'ezimport-for-woocommerce');
                    ?>
                </li>
                <li>
                    <strong><?php esc_html_e('XML Item Path:', 'ezimport-for-woocommerce'); ?></strong>
                    <?php
                    $selected_xpath = isset($import_options['selected_xpath']) ? $import_options['selected_xpath'] : '//item';
                    echo esc_html($selected_xpath);
                    ?>
                </li>
            </ul>
        </div>

        <form id="wpezimport-import-form" method="post">
            <?php wp_nonce_field('wpezimport_import', 'wpezimport_import_nonce'); ?>
            <input type="hidden" name="draft_id" value="<?php echo esc_attr($draft_id); ?>">

            <div class="wpezimport-form-section">
                <h3><?php esc_html_e('Import Options', 'ezimport-for-woocommerce'); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wpezimport-post-type"><?php esc_html_e('Post Type', 'ezimport-for-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="wpezimport-post-type" name="wpezimport_post_type">
                                <?php
                                $post_types = get_post_types(array('public' => true), 'objects');
                                foreach ($post_types as $post_type) {
                                    echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->label) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpezimport-post-status"><?php esc_html_e('Post Status', 'ezimport-for-woocommerce'); ?></label>
                        </th>
                        <td>
                            <select id="wpezimport-post-status" name="wpezimport_post_status">
                                <option value="publish"><?php esc_html_e('Published', 'ezimport-for-woocommerce'); ?></option>
                                <option value="draft" selected><?php esc_html_e('Draft', 'ezimport-for-woocommerce'); ?></option>
                                <option value="pending"><?php esc_html_e('Pending', 'ezimport-for-woocommerce'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wpezimport-form-actions">
                <button id="wpezimport-import-button" type="submit" class="button button-primary">
                    <?php esc_html_e('Start Import', 'ezimport-for-woocommerce'); ?>
                </button>
                <div id="wpezimport-import-loader" class="wpezimport-loader">
                    <div class="wpezimport-loader-spinner"></div>
                    <span><?php esc_html_e('Importing...', 'ezimport-for-woocommerce'); ?></span>
                </div>
            </div>
        </form>
    </div>

    <div id="wpezimport-import-progress" class="wpezimport-card" style="display: none;">
        <h2><?php esc_html_e('Import Progress', 'ezimport-for-woocommerce'); ?></h2>
        <div class="wpezimport-progress-bar-container">
            <div class="wpezimport-progress-bar" id="wpezimport-progress-bar" style="width: 0%;"></div>
        </div>
        <div class="wpezimport-progress-status" id="wpezimport-progress-status">
            <?php esc_html_e('Ready to import...', 'ezimport-for-woocommerce'); ?>
        </div>
        <div class="wpezimport-progress-statistics" id="wpezimport-progress-statistics">
            <span id="wpezimport-imported-count">0</span> <?php esc_html_e('out of', 'ezimport-for-woocommerce'); ?> <span id="wpezimport-total-rows">0</span> <?php esc_html_e('records processed', 'ezimport-for-woocommerce'); ?>
        </div>
        <div class="wpezimport-import-errors" id="wpezimport-import-errors" style="display: none;">
            <h3><?php esc_html_e('Errors', 'ezimport-for-woocommerce'); ?></h3>
            <div id="wpezimport-error-list"></div>
        </div>
        <div class="wpezimport-xpath-debug" id="wpezimport-xpath-debug" style="display: none; margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <h3><?php esc_html_e('XPath Evaluation Log', 'ezimport-for-woocommerce'); ?></h3>
            <pre id="wpezimport-xpath-log" style="background: #fff; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.5;"></pre>
        </div>
        <div class="wpezimport-resolved-items" id="wpezimport-resolved-items" style="display: none; margin-top: 20px;">
            <h3><?php esc_html_e('Product Resolution Details', 'ezimport-for-woocommerce'); ?></h3>
            <div id="wpezimport-resolved-items-list"></div>
        </div>
        <div class="wpezimport-import-actions" id="wpezimport-import-actions" style="display: none;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new')); ?>" class="button button-primary"><?php esc_html_e('Start New Import', 'ezimport-for-woocommerce'); ?></a>
        </div>
    </div>
</div>


<script>
    jQuery(document).ready(function($) {
        const importForm = $('#wpezimport-import-form');
        const importButton = $('#wpezimport-import-button');
        const importLoader = $('#wpezimport-import-loader');
        const progressSection = $('#wpezimport-import-progress');
        const progressBar = $('#wpezimport-progress-bar');
        const progressStatus = $('#wpezimport-progress-status');
        const importedCount = $('#wpezimport-imported-count');
        const totalRows = $('#wpezimport-total-rows');
        const errorSection = $('#wpezimport-import-errors');
        const errorList = $('#wpezimport-error-list');
        const xpathDebugSection = $('#wpezimport-xpath-debug');
        const xpathLog = $('#wpezimport-xpath-log');
        const importActions = $('#wpezimport-import-actions');

        // Handle form submission
        importForm.on('submit', function(e) {
            e.preventDefault();

            // Clear previous errors and debug logs
            errorList.empty();
            errorSection.hide();
            xpathLog.empty();
            xpathDebugSection.hide();

            // Show progress section and hide form button
            importButton.prop('disabled', true);
            importLoader.css('display', 'flex');
            progressSection.show();

            // Get form data including the nonce
            const formData = new FormData(this);
            formData.append('action', 'wpezimport_handle_import');

            // Log what we're about to send
            console.log('Sending AJAX request to:', ajaxurl);

            // Use fetch API for more reliable AJAX
            fetch(ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('AJAX Success Response:', data);

                if (data.success) {
                    // Update progress
                    progressBar.css('width', data.data.progress_percentage + '%');
                    progressStatus.text(data.data.progress_percentage + '%' + '<?php echo esc_js(__("complete", "ezimport-for-woocommerce")); ?>');
                    importedCount.text(data.data.imported_count);
                    totalRows.text(data.data.total_rows);

                    // Display detailed import results with new vs updated vs skipped counts
                    if (data.data.hasOwnProperty('created_count') && data.data.hasOwnProperty('updated_count')) {
                        let resultsSummary = '<?php echo esc_js(__("Items created: ", "ezimport-for-woocommerce")); ?>' + data.data.created_count +
                            ', <?php echo esc_js(__("Items updated: ", "ezimport-for-woocommerce")); ?>' + data.data.updated_count;

                        // Add skipped count if any
                        if (data.data.skipped_count && data.data.skipped_count > 0) {
                            resultsSummary += ', <span style="color: #d63638;"><?php echo esc_js(__("Items skipped: ", "ezimport-for-woocommerce")); ?>' + data.data.skipped_count + '</span>';
                        }

                        // Add the detailed counts below the progress bar
                        if ($('#wpezimport-import-results-detail').length === 0) {
                            progressSection.append('<div id="wpezimport-import-results-detail" class="wpezimport-results-summary" style="margin-top: 10px; font-weight: bold;"></div>');
                        }
                        $('#wpezimport-import-results-detail').html(resultsSummary);
                    }

                    // Display warnings if any (before errors)
                    if (data.data.warnings && data.data.warnings.length > 0) {
                        if ($('#wpezimport-import-warnings').length === 0) {
                            progressSection.after('<div id="wpezimport-import-warnings" class="wpezimport-warnings-section" style="margin-top: 15px;"><h3><?php echo esc_js(__("Warnings", "ezimport-for-woocommerce")); ?></h3><div id="wpezimport-warning-list"></div></div>');
                        }
                        $('#wpezimport-import-warnings').show();
                        data.data.warnings.forEach(function(warning) {
                            $('#wpezimport-warning-list').append('<div class="notice notice-warning"><p>' + escapeHtml(warning) + '</p></div>');
                        });
                    }

                    // Display errors if any
                    if (data.data.errors && data.data.errors.length > 0) {
                        errorSection.show();
                        data.data.errors.forEach(function(error) {
                            errorList.append('<p>' + escapeHtml(error) + '</p>');
                        });
                    }

                    // Display XPath debug logs if any
                    if (data.data.xpath_debug && data.data.xpath_debug.length > 0) {
                        $('#wpezimport-xpath-debug').show();
                        $('#wpezimport-xpath-log').text(data.data.xpath_debug.join('\n'));
                    }

                    // Display resolved items with hierarchical field details
                    if (data.data.resolved_items && data.data.resolved_items.length > 0) {
                        renderResolvedItems(data.data.resolved_items);
                    }

                    // Show completion
                    importLoader.hide();
                    progressStatus.text('<?php echo esc_js(__("Import completed!", "ezimport-for-woocommerce")); ?>');
                    importActions.show();

                    // If statistics are available, show link instead of auto-redirect
                    if (data.data.hasOwnProperty('statistics_url')) {
                        // Add link to statistics page
                        if ($('#wpezimport-statistics-link').length === 0) {
                            importActions.prepend('<a id="wpezimport-statistics-link" href="' + escapeHtml(data.data.statistics_url) + '" class="button button-secondary" style="margin-right: 10px;"><?php echo esc_js(__("View Statistics", "ezimport-for-woocommerce")); ?></a>');
                        }
                    }
                } else {
                    // Error in import
                    importLoader.hide();
                    progressStatus.text('<?php echo esc_js(__("Error: ", "ezimport-for-woocommerce")); ?>' + (data.data.message || '<?php echo esc_js(__("An unknown error occurred.", "ezimport-for-woocommerce")); ?>'));
                    errorSection.show();
                    errorList.append('<p>' + escapeHtml(data.data.message || '<?php echo esc_js(__("An unknown error occurred.", "ezimport-for-woocommerce")); ?>') + '</p>');
                    importActions.show();
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);

                importLoader.hide();
                progressStatus.text('<?php echo esc_js(__("Error: Could not connect to the server.", "ezimport-for-woocommerce")); ?>');
                errorSection.show();
                errorList.append('<p><?php echo esc_js(__("Could not connect to the server. Please check the PHP error logs.", "ezimport-for-woocommerce")); ?></p>');
                errorList.append('<p>Error details: ' + escapeHtml(error.message) + '</p>');
                importActions.show();
            });

            return false;
        });

        // Function to render resolved items hierarchically
        function renderResolvedItems(resolvedItems) {
            const container = $('#wpezimport-resolved-items-list');
            container.empty();

            resolvedItems.forEach(function(item, index) {
                const resolution = item.resolution || {};
                const fields = item.fields || {};
                const status = resolution.status || 'unknown';
                const title = resolution.title || '<?php echo esc_js(__("Item", "ezimport-for-woocommerce")); ?> #' + item.item_index;

                // Create item container
                const itemDiv = $('<div class="wpezimport-resolved-item"></div>');

                // Create header
                const statusLabel = status === 'created' ? '<?php echo esc_js(__("Created", "ezimport-for-woocommerce")); ?>' :
                                   status === 'updated' ? '<?php echo esc_js(__("Updated", "ezimport-for-woocommerce")); ?>' :
                                   status === 'skipped' ? '<?php echo esc_js(__("Skipped", "ezimport-for-woocommerce")); ?>' :
                                   '<?php echo esc_js(__("Error", "ezimport-for-woocommerce")); ?>';

                const header = $(`
                    <div class="wpezimport-resolved-item-header">
                        <div>
                            <span class="toggle-icon dashicons dashicons-arrow-right-alt2"></span>
                            <span class="wpezimport-resolved-item-title">${escapeHtml(title)}</span>
                        </div>
                        <span class="wpezimport-resolved-item-status ${status}">${statusLabel}</span>
                    </div>
                `);

                // Create body with resolution info and fields table
                const body = $('<div class="wpezimport-resolved-item-body"></div>');

                // Add ID Resolution section
                const idRes = item.id_resolution || {};
                let idResolutionHtml = '<div class="wpezimport-id-resolution">';
                idResolutionHtml += '<h4><?php echo esc_js(__("ID Resolution", "ezimport-for-woocommerce")); ?></h4>';
                idResolutionHtml += '<table class="wpezimport-id-table">';

                // XPath
                idResolutionHtml += '<tr><th><?php echo esc_js(__("XPath Pattern:", "ezimport-for-woocommerce")); ?></th><td><code>' + escapeHtml(idRes.xpath || '<?php echo esc_js(__("Not configured", "ezimport-for-woocommerce")); ?>') + '</code></td></tr>';

                // Extracted Value
                idResolutionHtml += '<tr><th><?php echo esc_js(__("Extracted Value:", "ezimport-for-woocommerce")); ?></th><td><strong>' + escapeHtml(idRes.extracted_value || '<?php echo esc_js(__("(empty)", "ezimport-for-woocommerce")); ?>') + '</strong></td></tr>';

                // Status with colored badge
                let idStatusLabel = '<?php echo esc_js(__("Unknown", "ezimport-for-woocommerce")); ?>';
                let idStatusClass = 'unknown';
                switch(idRes.status) {
                    case 'not_configured':
                        idStatusLabel = '<?php echo esc_js(__("Not Configured", "ezimport-for-woocommerce")); ?>';
                        idStatusClass = 'warning';
                        break;
                    case 'extraction_failed':
                        idStatusLabel = '<?php echo esc_js(__("Extraction Failed", "ezimport-for-woocommerce")); ?>';
                        idStatusClass = 'error';
                        break;
                    case 'value_extracted':
                        idStatusLabel = '<?php echo esc_js(__("Value Extracted", "ezimport-for-woocommerce")); ?>';
                        idStatusClass = 'info';
                        break;
                    case 'duplicate_found':
                        idStatusLabel = '<?php echo esc_js(__("Duplicate Found → Updated", "ezimport-for-woocommerce")); ?>';
                        idStatusClass = 'updated';
                        break;
                    case 'no_duplicate':
                        idStatusLabel = '<?php echo esc_js(__("No Duplicate → Created New", "ezimport-for-woocommerce")); ?>';
                        idStatusClass = 'created';
                        break;
                }
                idResolutionHtml += '<tr><th><?php echo esc_js(__("Duplicate Check:", "ezimport-for-woocommerce")); ?></th><td><span class="wpezimport-id-status ' + idStatusClass + '">' + idStatusLabel + '</span></td></tr>';

                // Existing Post ID if duplicate
                if (idRes.duplicate_found && idRes.existing_post_id) {
                    idResolutionHtml += '<tr><th><?php echo esc_js(__("Matched Post ID:", "ezimport-for-woocommerce")); ?></th><td>#' + idRes.existing_post_id + '</td></tr>';
                }

                idResolutionHtml += '</table></div>';
                body.append(idResolutionHtml);

                // Add general resolution info
                let resolutionInfo = '<div class="wpezimport-resolution-info">';
                if (resolution.post_id) {
                    resolutionInfo += '<strong><?php echo esc_js(__("Result Post ID:", "ezimport-for-woocommerce")); ?></strong> ' + resolution.post_id + ' | ';
                }
                resolutionInfo += '<strong><?php echo esc_js(__("Action:", "ezimport-for-woocommerce")); ?></strong> ' + statusLabel;
                if (resolution.message) {
                    resolutionInfo += '<br><strong><?php echo esc_js(__("Message:", "ezimport-for-woocommerce")); ?></strong> ' + escapeHtml(resolution.message);
                }
                resolutionInfo += '</div>';
                body.append(resolutionInfo);

                // Create fields table
                const fieldKeys = Object.keys(fields);
                if (fieldKeys.length > 0) {
                    let tableHtml = '<table class="wpezimport-field-table">';
                    tableHtml += '<thead><tr><th><?php echo esc_js(__("Field", "ezimport-for-woocommerce")); ?></th><th><?php echo esc_js(__("Value", "ezimport-for-woocommerce")); ?></th></tr></thead>';
                    tableHtml += '<tbody>';

                    fieldKeys.forEach(function(fieldKey) {
                        const fieldData = fields[fieldKey];
                        const xpath = fieldData.xpath || '';
                        const value = fieldData.value || '';

                        tableHtml += '<tr>';
                        tableHtml += '<th>' + escapeHtml(fieldKey) + '<span class="wpezimport-field-xpath">' + escapeHtml(xpath) + '</span></th>';
                        tableHtml += '<td class="wpezimport-field-value">' + escapeHtml(value) + '</td>';
                        tableHtml += '</tr>';
                    });

                    tableHtml += '</tbody></table>';
                    body.append(tableHtml);
                }

                // Assemble item
                itemDiv.append(header);
                itemDiv.append(body);
                container.append(itemDiv);

                // Add click handler for expand/collapse
                header.on('click', function() {
                    itemDiv.toggleClass('expanded');
                });
            });

            // Show the resolved items section
            $('#wpezimport-resolved-items').show();
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }
    });
</script>
