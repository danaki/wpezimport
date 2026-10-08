<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include the import processor functions
require_once plugin_dir_path(__FILE__) . 'import-processor.php';

// ─── Resolve draft_id ───────────────────────────────────────────────────────
// Accept draft_id from GET (normal flow) or edit_import/import_id (editing a saved import).
$draft_id = isset($_GET['draft_id']) ? intval($_GET['draft_id']) : 0;
$edit_import_id = isset($_GET['edit_import']) ? intval($_GET['edit_import']) : 0;
if ($edit_import_id === 0 && isset($_GET['import_id'])) {
    $edit_import_id = intval($_GET['import_id']);
}
$editing_import = false;

$url_fetch_error = ''; // Track URL fetch errors for display

// If editing a saved import, create a draft from its config so AJAX handlers have a draft_id
if ($edit_import_id > 0 && $draft_id === 0) {
    $import = wpezimport_get_import($edit_import_id);

    if ($import) {
        $editing_import = true;
        $options = maybe_unserialize($import->options);

        // Build initial draft state from saved import
        $state = array(
            'editing_import'  => true,
            'edit_import_id'  => $edit_import_id,
            'import_name'     => $import->name,
            'import_type'     => $import->type,
            'import_options'  => $options,
            'selected_post_type' => 'product',
        );

        if (isset($options['mappings'])) {
            $state['field_mapping'] = $options['mappings'];
        }
        if (isset($options['update_flags']) && !empty($options['update_flags'])) {
            $state['update_flags'] = $options['update_flags'];
        }
        if (isset($options['selected_xpath']) && !empty($options['selected_xpath'])) {
            $state['xml_item_path'] = $options['selected_xpath'];
        }
        if (isset($options['is_xml'])) {
            $state['is_xml'] = (bool)$options['is_xml'];
        }

        // For file-type imports, show re-upload notice
        if ($import->type === 'file' && !empty($import->file_path)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning">';
                echo '<p>' . __('You are editing a saved file import. Please note that you must upload a file again when you run this import.', 'wpezimport') . '</p>';
                echo '</div>';
            });
        }

        // For URL-type imports, fetch XML now
        if ($import->type === 'url' && !empty($import->url)) {
            $state['import_url'] = $import->url;
            $selected_xpath = isset($options['selected_xpath']) ? $options['selected_xpath'] : '';

            if (!empty($selected_xpath)) {
                $fetch_result = wpezimport_fetch_xml_from_url($import->url, $selected_xpath);

                if (is_wp_error($fetch_result)) {
                    $url_fetch_error = $fetch_result->get_error_message();
                } else {
                    $state['uploaded_file']        = $fetch_result['file_path'];
                    $state['is_xml']               = true;
                    $state['file_headers']         = $fetch_result['headers'];
                    $state['xml_samples']          = $fetch_result['xml_samples'];
                    $state['current_sample_index'] = 0;
                }
            }
        }

        // Create the draft
        $draft_id = wpezimport_create_draft(array(
            'type'  => $import->type,
            'file'  => isset($state['uploaded_file']) ? $state['uploaded_file'] : '',
            'url'   => isset($state['import_url']) ? $state['import_url'] : '',
            'state' => $state,
        ));

        // Redirect so the URL contains draft_id — prevents duplicate drafts on refresh
        if ($draft_id > 0) {
            wp_redirect(admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id));
            exit;
        }
    }
}

// ─── Load state from draft ──────────────────────────────────────────────────
$draft = ($draft_id > 0) ? wpezimport_get_draft($draft_id) : false;
$ds = $draft ? $draft->state : array(); // shorthand for draft state

$uploaded_file = isset($ds['uploaded_file']) ? $ds['uploaded_file'] : null;
$file_headers  = isset($ds['file_headers'])  ? $ds['file_headers']  : array();
$is_xml        = isset($ds['is_xml'])        ? $ds['is_xml']        : false;
$item_xpath    = isset($ds['xml_item_path']) ? $ds['xml_item_path'] : '';

if (!$editing_import && $draft && isset($ds['editing_import']) && $ds['editing_import']) {
    $editing_import = true;
    $edit_import_id = isset($ds['edit_import_id']) ? intval($ds['edit_import_id']) : 0;
}

// Force product post type
$selected_post_type = 'product';

// If no file uploaded and not editing a URL import, redirect to step 1
$has_url_import = $draft && isset($ds['import_url']) && !empty($ds['import_url']);
if (!$uploaded_file && !$editing_import && !$has_url_import) {
    wp_redirect(admin_url('admin.php?page=wpezimport-new'));
    exit;
}

// WordPress default post fields
$wp_fields = array(
    'post_title' => __('Post Title', 'wpezimport'),
    'post_content' => __('Post Content', 'wpezimport'),
);

// Get available post types
$post_types = get_post_types(array('public' => true), 'objects');

// Get available post statuses
$post_statuses = get_post_statuses();

// Get available authors
$authors = get_users(array('role__in' => array('administrator', 'editor', 'author')));

// Get available custom fields
$custom_fields = array();
$custom_field_keys = wpezimport_get_meta_keys();
if ($custom_field_keys) {
    foreach ($custom_field_keys as $key) {
        // Filter out protected fields (starting with underscore)
        if (substr($key, 0, 1) !== '_') {
            $custom_fields[$key] = $key;
        }
    }
}

// Load sample XML data for the structure viewer if it's an XML file
$xml_sample = '';
$xml_samples = array(); // Array to store multiple product samples
$current_sample_index = 0;
$total_samples = 0;

if ($is_xml) {
    if (isset($ds['xml_samples']) && is_array($ds['xml_samples']) && !empty($ds['xml_samples'])) {
        $xml_samples_raw = $ds['xml_samples'];
        $total_samples = count($xml_samples_raw);

        // Ensure current_sample_index is valid, defaulting to 0
        $current_sample_index = isset($ds['current_sample_index']) ? (int)$ds['current_sample_index'] : 0;
        if ($current_sample_index < 0 || $current_sample_index >= $total_samples) {
            $current_sample_index = 0;
            if ($total_samples > 0 && $draft_id > 0) {
                 wpezimport_update_draft($draft_id, array('current_sample_index' => 0));
            } else {
                $current_sample_index = 0;
            }
        }

        if ($total_samples > 0) {
            $raw_sample_string = $xml_samples_raw[$current_sample_index];
            libxml_use_internal_errors(true);
            $simplexml_sample = simplexml_load_string($raw_sample_string, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
            libxml_clear_errors();

            if ($simplexml_sample !== false) {
                // $item_xpath is loaded from draft state at the top of the page.
                // Corrected call: $node, $base_xpath (empty for sample root), $is_root (true for sample root), $depth (0 for initial)
                $xml_sample = wpezimport_process_xml_node($simplexml_sample, '', true, 0);
            } else {
                $xml_sample = '<p class="wpezimport-error">' . __('Error loading XML sample data for display. The sample may be malformed.', 'wpezimport') . '</p>';
                error_log('WPezImport: Failed to load XML sample string for display. Index: ' . $current_sample_index . '. Raw string: ' . substr($raw_sample_string, 0, 200));
            }
        } else {
            // $is_xml is true, draft xml_samples is set and is an array, but it's empty.
            $xml_sample = '<p>' . __('No XML samples were extracted. Please check your XML file and selection.', 'wpezimport') . '</p>';
        }
    } elseif (isset($ds['uploaded_file'])) { // $is_xml true, but samples not in draft implies issue in Step 1
        $xml_sample = '<p class="wpezimport-error">' . __('XML import type detected, but no XML samples found. Please re-upload your file and complete Step 1 selection.', 'wpezimport') . '</p>';
    } else {
        // $is_xml true, but no uploaded file session variable; very unlikely state here, means direct navigation to step 2 without upload
        $xml_sample = '<p class="wpezimport-error">' . __('Cannot display XML samples. No file uploaded or session expired. Please start from Step 1.', 'wpezimport') . '</p>';
    }
}

// Mapping data
$mapping = isset($ds['mapping']) ? $ds['mapping'] : array();

?>

<?php
wp_enqueue_script('wpezimport-admin-woo-tabs', plugins_url('../assets/js/admin-woo-tabs.js', __FILE__), array('jquery'), null, true);
?>

<div class="wrap wpezimport-admin-container">
    <h1>
    <?php if ($editing_import): ?>
        <?php _e('Edit Import', 'wpezimport'); ?>
    <?php else: ?>
        <?php echo esc_html(get_admin_page_title()); ?>
    <?php endif; ?>
</h1>

    <?php if ($editing_import && isset($import)): ?>
    <div class="wpezimport-import-info-box">
        <h3><?php _e('Import Source', 'wpezimport'); ?></h3>

        <table class="wpezimport-info-table">
            <?php if ($import->type === 'url' && !empty($import->url)): ?>
            <tr>
                <th><?php _e('Data Source URL:', 'wpezimport'); ?></th>
                <td><code><?php echo esc_html($import->url); ?></code></td>
            </tr>
            <?php elseif ($import->type === 'file' && !empty($import->file_path)): ?>
            <tr>
                <th><?php _e('File:', 'wpezimport'); ?></th>
                <td><code><?php echo esc_html(basename($import->file_path)); ?></code></td>
            </tr>
            <?php endif; ?>
            <?php
            $saved_xpath = '';
            // Check options first, then session variable
            if (isset($options) && isset($options['selected_xpath']) && !empty($options['selected_xpath'])) {
                $saved_xpath = $options['selected_xpath'];
            } elseif (!empty($item_xpath)) {
                $saved_xpath = $item_xpath;
            }
            if (!empty($saved_xpath)):
            ?>
            <tr>
                <th><?php _e('XML Item Path:', 'wpezimport'); ?></th>
                <td><code><?php echo esc_html($saved_xpath); ?></code></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($url_fetch_error)): ?>
    <div class="notice notice-error">
        <p>
            <strong><?php _e('Could not fetch XML from URL:', 'wpezimport'); ?></strong>
            <?php echo esc_html($url_fetch_error); ?>
        </p>
        <p><?php _e('You can still edit the mapping below. Use the "Refetch XML" button in the XML Structure panel to retry.', 'wpezimport'); ?></p>
    </div>
    <?php endif; ?>

    <div class="wpezimport-card wpezimport-mapping-container">
        <form method="post" action="" id="wpezimport-mapping-form">
            <?php wp_nonce_field('wpezimport_save_mapping', 'wpezimport_mapping_nonce'); ?>
            <input type="hidden" name="draft_id" id="wpezimport_draft_id" value="<?php echo esc_attr($draft_id); ?>">

            <div class="wpezimport-mapping-layout">
                <div class="wpezimport-mapping-target">
                    <div class="wpezimport-field-group">
                        <h4><?php _e('Drag & drop any element on the right to set field content.', 'wpezimport'); ?></h4>


                        <?php
                        // Helper function to get update flag value
                        $get_update_flag = function($field_name, $default = false) use ($editing_import, $ds) {
                            // Default fields that should be checked for new imports
                            $default_checked_fields = array('_regular_price', '_sale_price', '_stock');

                            if (isset($ds['update_flags']) && isset($ds['update_flags'][$field_name])) {
                                return $ds['update_flags'][$field_name] === '1';
                            }
                            // For new imports, check defaults
                            if (!$editing_import && in_array($field_name, $default_checked_fields)) {
                                return true;
                            }
                            return $default;
                        };
                        ?>
                        <div class="wpezimport-update-column-header">
                            <span><?php _e('Update?', 'wpezimport'); ?></span>
                        </div>
                        <p class="description"><?php _e('This field will be used to identify if an item already exists in WordPress during import.', 'wpezimport'); ?></p>
                        <div class="wpezimport-field-mapping-row">
                            <label for="unique_identifier_source"><?php _e('Unique Identifier Source', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $unique_identifier_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['unique_identifier_source'])) {
                                    $unique_identifier_value = esc_attr($ds['field_mapping']['unique_identifier_source']);
                                }
                                elseif (isset($ds['import_options']) && isset($ds['import_options']['unique_identifier_source'])) {
                                    $unique_identifier_value = esc_attr($ds['import_options']['unique_identifier_source']);
                                }
                                ?>
                                <input type="text" name="field_mapping[unique_identifier_source]" id="unique_identifier_source" class="regular-text wpezimport-field-pattern" value="<?php echo $unique_identifier_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="post_title"><?php _e('Title', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $post_title_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['post_title'])) {
                                    $post_title_value = esc_attr($ds['field_mapping']['post_title']);
                                }
                                ?>
                                <input type="text" name="field_mapping[post_title]" id="post_title" class="regular-text wpezimport-field-pattern" value="<?php echo $post_title_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[post_title]" id="update_post_title" value="1" <?php checked($get_update_flag('post_title')); ?>>
                                <label for="update_post_title"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="post_content"><?php _e('Description', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $post_content_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['post_content'])) {
                                    $post_content_value = esc_attr($ds['field_mapping']['post_content']);
                                }
                                ?>
                                <input type="text" name="field_mapping[post_content]" id="post_content" class="regular-text wpezimport-field-pattern" value="<?php echo $post_content_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[post_content]" id="update_post_content" value="1" <?php checked($get_update_flag('post_content')); ?>>
                                <label for="update_post_content"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="post_brand"><?php _e('Brand', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $brand_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['post_brand'])) {
                                    $brand_value = esc_attr($ds['field_mapping']['post_brand']);
                                }
                                ?>
                                <input type="text" name="field_mapping[post_brand]" id="post_brand" class="regular-text wpezimport-field-pattern" value="<?php echo $brand_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[post_brand]" id="update_post_brand" value="1" <?php checked($get_update_flag('post_brand')); ?>>
                                <label for="update_post_brand"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="_sku"><?php _e('Code/Part number (SKU)', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $sku_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['_sku'])) {
                                    $sku_value = esc_attr($ds['field_mapping']['_sku']);
                                }
                                ?>
                                <input type="text" name="field_mapping[_sku]" id="_sku" class="regular-text wpezimport-field-pattern" value="<?php echo $sku_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[_sku]" id="update__sku" value="1" <?php checked($get_update_flag('_sku')); ?>>
                                <label for="update__sku"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="_global_unique_id"><?php _e('GTIN, UPC, EAN, or ISBN', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $gtin_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['_global_unique_id'])) {
                                    $gtin_value = esc_attr($ds['field_mapping']['_global_unique_id']);
                                }
                                ?>
                                <input type="text" name="field_mapping[_global_unique_id]" id="_global_unique_id" class="regular-text wpezimport-field-pattern" value="<?php echo $gtin_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[_global_unique_id]" id="update__global_unique_id" value="1" <?php checked($get_update_flag('_global_unique_id')); ?>>
                                <label for="update__global_unique_id"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="_regular_price"><?php _e('Regular price', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $regular_price_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['_regular_price'])) {
                                    $regular_price_value = esc_attr($ds['field_mapping']['_regular_price']);
                                }
                                ?>
                                <input type="text" name="field_mapping[_regular_price]" id="_regular_price" class="regular-text wpezimport-field-pattern" value="<?php echo $regular_price_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[_regular_price]" id="update__regular_price" value="1" <?php checked($get_update_flag('_regular_price')); ?>>
                                <label for="update__regular_price"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>
                        <div class="wpezimport-field-mapping-row">
                            <label for="_sale_price"><?php _e('Sale price', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $sale_price_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['_sale_price'])) {
                                    $sale_price_value = esc_attr($ds['field_mapping']['_sale_price']);
                                }
                                ?>
                                <input type="text" name="field_mapping[_sale_price]" id="_sale_price" class="regular-text wpezimport-field-pattern" value="<?php echo $sale_price_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[_sale_price]" id="update__sale_price" value="1" <?php checked($get_update_flag('_sale_price')); ?>>
                                <label for="update__sale_price"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="_stock"><?php _e('Stock quantity', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $stock_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['_stock'])) {
                                    $stock_value = esc_attr($ds['field_mapping']['_stock']);
                                }
                                ?>
                                <input type="text" name="field_mapping[_stock]" id="_stock" class="regular-text wpezimport-field-pattern" value="<?php echo $stock_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[_stock]" id="update__stock" value="1" <?php checked($get_update_flag('_stock')); ?>>
                                <label for="update__stock"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>

                        <div class="wpezimport-field-mapping-row">
                            <label for="_weight"><?php _e('Weight', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $weight_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['_weight'])) {
                                    $weight_value = esc_attr($ds['field_mapping']['_weight']);
                                }
                                $weight_unit = 'kg';
                                if (isset($ds['import_options']['mapping_units']['_weight'])) {
                                    $weight_unit = esc_attr($ds['import_options']['mapping_units']['_weight']);
                                }
                                ?>
                                <input type="text" name="field_mapping[_weight]" id="_weight" class="regular-text wpezimport-field-pattern" value="<?php echo $weight_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                                <select name="field_mapping_units[_weight]" id="_weight_unit">
                                    <option value="kg" <?php selected($weight_unit, 'kg'); ?>>kg</option>
                                    <option value="g" <?php selected($weight_unit, 'g'); ?>>g</option>
                                </select>
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[_weight]" id="update__weight" value="1" <?php checked($get_update_flag('_weight')); ?>>
                                <label for="update__weight"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>
                        <div class="wpezimport-field-mapping-row">
                            <label for="product_length"><?php _e('Dimensions', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container wpezimport-dimensions-wrap">
                                <?php
                                $length_value = isset($ds['field_mapping']['_length']) ? esc_attr($ds['field_mapping']['_length']) : '';
                                $width_value = isset($ds['field_mapping']['_width']) ? esc_attr($ds['field_mapping']['_width']) : '';
                                $height_value = isset($ds['field_mapping']['_height']) ? esc_attr($ds['field_mapping']['_height']) : '';
                                $dimension_unit = 'm';
                                if (isset($ds['import_options']['mapping_units']['_length'])) {
                                    $dimension_unit = esc_attr($ds['import_options']['mapping_units']['_length']);
                                }
                                ?>
                                <input id="product_length" placeholder="<?php _e('Length', 'wpezimport'); ?>" class="wpezimport-field-pattern" type="text" name="field_mapping[_length]" value="<?php echo $length_value; ?>" />
                                <input id="product_width" placeholder="<?php _e('Width', 'wpezimport'); ?>" class="wpezimport-field-pattern" type="text" name="field_mapping[_width]" value="<?php echo $width_value; ?>" />
                                <input id="product_height" placeholder="<?php _e('Height', 'wpezimport'); ?>" class="wpezimport-field-pattern" type="text" name="field_mapping[_height]" value="<?php echo $height_value; ?>" />
                                <select name="field_mapping_units[_dimensions]" id="_dimensions_unit">
                                    <option value="m" <?php selected($dimension_unit, 'm'); ?>>m</option>
                                    <option value="cm" <?php selected($dimension_unit, 'cm'); ?>>cm</option>
                                    <option value="mm" <?php selected($dimension_unit, 'mm'); ?>>mm</option>
                                </select>
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[_dimensions]" id="update__dimensions" value="1" <?php checked($get_update_flag('_dimensions')); ?>>
                                <label for="update__dimensions"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="wpezimport-field-group">
                        <h4><?php _e('Product Images', 'wpezimport'); ?></h4>
                        <p class="description"><?php _e('The first successfully downloaded image becomes the featured image.', 'wpezimport'); ?></p>
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="wpezimport-field-mapping-row">
                            <label for="image_url_<?php echo $i; ?>"><?php printf(__('Image %d', 'wpezimport'), $i); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $image_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['image_url_' . $i])) {
                                    $image_value = esc_attr($ds['field_mapping']['image_url_' . $i]);
                                }
                                ?>
                                <input type="text" name="field_mapping[image_url_<?php echo $i; ?>]" id="image_url_<?php echo $i; ?>" class="regular-text wpezimport-field-pattern" value="<?php echo $image_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[image_url_<?php echo $i; ?>]" id="update_image_url_<?php echo $i; ?>" value="1" <?php checked($get_update_flag('image_url_' . $i)); ?>>
                                <label for="update_image_url_<?php echo $i; ?>"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <div class="wpezimport-field-group">
                        <h4><?php _e('Category', 'wpezimport'); ?></h4>
                        <p class="description"><?php _e('Map XML path for product category.', 'wpezimport'); ?></p>
                        <div class="wpezimport-field-mapping-row">
                            <label for="post_categories"><?php _e('Category', 'wpezimport'); ?></label>
                            <div class="wpezimport-field-input-container">
                                <?php
                                $category_value = '';
                                if (isset($ds['field_mapping']) && isset($ds['field_mapping']['post_categories'])) {
                                    $category_value = esc_attr($ds['field_mapping']['post_categories']);
                                }
                                $categories_match_existing = isset($ds['import_options']['categories_match_existing']) ? $ds['import_options']['categories_match_existing'] : '';
                                ?>
                                <input type="text" name="field_mapping[post_categories]" id="post_categories" class="regular-text wpezimport-field-pattern" value="<?php echo $category_value; ?>" placeholder="<?php _e('Drag a column here', 'wpezimport'); ?>">
                            </div>
                            <div class="wpezimport-update-checkbox">
                                <input type="checkbox" name="update_flags[post_categories]" id="update_post_categories" value="1" <?php checked($get_update_flag('post_categories')); ?>>
                                <label for="update_post_categories"><?php _e('Update?', 'wpezimport'); ?></label>
                            </div>
                        </div>
                        <div class="form-field wpezimport-form-field-mt">
                            <label>
                                <input type="checkbox" name="import_options[categories_match_existing]" value="yes" <?php checked($categories_match_existing, 'yes'); ?> />
                                <?php _e('Only match existing categories (do not create new ones)', 'wpezimport'); ?>
                            </label>
                        </div>
                    </div>

                    <!-- Import Options Collapsible Section -->
                    <div class="wpezimport-collapsible-section">
                        <div class="wpezimport-collapsible-header" onclick="this.parentElement.classList.toggle('expanded')">
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                            <h4><?php _e('Import Options', 'wpezimport'); ?></h4>
                            <span class="wpezimport-collapsible-hint"><?php _e('Post status, author & more', 'wpezimport'); ?></span>
                        </div>
                        <div class="wpezimport-collapsible-content">
                            <input type="hidden" name="import_options[default_post_type]" value="product">

                            <div class="wpezimport-option-row">
                                <label for="default_post_status"><?php _e('Default Post Status', 'wpezimport'); ?></label>
                                <select name="import_options[default_post_status]" id="default_post_status">
                                    <option value="publish"><?php _e('Published', 'wpezimport'); ?></option>
                                    <option value="draft" selected><?php _e('Draft', 'wpezimport'); ?></option>
                                    <option value="pending"><?php _e('Pending', 'wpezimport'); ?></option>
                                </select>
                            </div>

                            <div class="wpezimport-option-row">
                                <label for="default_author"><?php _e('Default Author', 'wpezimport'); ?></label>
                                <select name="import_options[default_author]" id="default_author">
                                    <?php
                                    $users = get_users(array('role__in' => array('administrator', 'editor', 'author')));
                                    foreach ($users as $user) {
                                        echo '<option value="' . esc_attr($user->ID) . '"' . ($user->ID == get_current_user_id() ? ' selected' : '') . '>' . esc_html($user->display_name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <?php if (!$is_xml): ?>
                            <div class="wpezimport-option-row">
                                <label for="skip_first_row">
                                    <input type="checkbox" name="import_options[skip_first_row]" id="skip_first_row" value="1" checked>
                                    <?php _e('Skip First Row', 'wpezimport'); ?>
                                </label>
                                <p class="description"><?php _e('If enabled, the first row of data will be skipped (assumes it contains headers).', 'wpezimport'); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Data Source Columns -->
                <?php if ($is_xml || $has_url_import): ?>
                <div class="wpezimport-mapping-source">
                    <!-- XML Sample Display -->
                    <div class="wpezimport-xml-sample">
                        <h3><?php _e('XML Structure', 'wpezimport'); ?></h3>
                        <p class="description"><?php _e('Drag elements to map them to WordPress fields.', 'wpezimport'); ?></p>
                        <?php if ($has_url_import): ?>
                        <div class="wpezimport-refetch-bar" style="margin-bottom: 10px;">
                            <button type="button" class="button button-small" id="wpezimport-refetch-xml"
                                data-url="<?php echo esc_attr(isset($ds['import_url']) ? $ds['import_url'] : ''); ?>"
                                data-xpath="<?php echo esc_attr($item_xpath); ?>">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Refetch XML', 'wpezimport'); ?>
                            </button>
                            <span id="wpezimport-refetch-status" style="margin-left: 8px;"></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($is_xml && $total_samples > 0): ?>
                        <div class="wpezimport-xml-navigation">
                            <button type="button" class="button button-small" id="wpezimport-prev-xml-sample" <?php if ($current_sample_index <= 0) echo 'disabled'; ?>>
                                <span class="dashicons dashicons-arrow-left-alt"></span>
                            </button>
                            <span class="wpezimport-product-counter">
                                <?php echo sprintf(__('Sample %d of %d', 'wpezimport'), $current_sample_index + 1, $total_samples); ?>
                            </span>
                            <button type="button" class="button button-small" id="wpezimport-next-xml-sample" <?php if ($current_sample_index >= $total_samples - 1) echo 'disabled'; ?>>
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </button>
                        </div>
                        <?php endif; ?>
                        <div class="wpezimport-xml-structure" id="wpezimport-xml-structure">
                            <?php if ($is_xml): ?>
                                <?php echo $xml_sample; ?>
                            <?php elseif (!empty($url_fetch_error)): ?>
                                <p class="wpezimport-error"><?php echo esc_html($url_fetch_error); ?></p>
                                <p><?php _e('Click "Refetch XML" above to retry.', 'wpezimport'); ?></p>
                            <?php else: ?>
                                <p><?php _e('No XML data loaded. Click "Refetch XML" to load the structure from the URL.', 'wpezimport'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="wpezimport-mapping-source">
                    <!-- CSV/Excel Columns Display -->
                    <div class="wpezimport-csv-columns">
                        <h3><?php _e('File Columns', 'wpezimport'); ?></h3>
                        <p class="description"><?php _e('Drag columns to map them to WordPress fields.', 'wpezimport'); ?></p>
                        <?php if (!empty($file_headers)): ?>
                        <input type="search" id="wpezimport-column-search" class="regular-text" placeholder="<?php esc_attr_e('Search columns...', 'wpezimport'); ?>" style="margin-bottom:8px;width:100%;">
                        <?php endif; ?>
                        <div class="wpezimport-columns-list">
                            <?php if (!empty($file_headers)): ?>
                                <?php foreach ($file_headers as $index => $header): ?>
                                <div class="wpezimport-column-item" draggable="true" data-column="<?php echo esc_attr($header); ?>">
                                    <span class="dashicons dashicons-move"></span>
                                    <span class="wpezimport-column-name"><?php echo esc_html($header); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="wpezimport-reupload-section">
                                    <p class="wpezimport-notice"><?php _e('The original file is no longer available. Please re-upload your file to load the columns.', 'wpezimport'); ?></p>
                                    <div class="wpezimport-reupload-control">
                                        <input type="file" id="wpezimport-reupload-file" accept=".csv,.xls,.xlsx" />
                                        <button type="button" class="button" id="wpezimport-reupload-btn">
                                            <span class="dashicons dashicons-upload"></span>
                                            <?php _e('Upload File', 'wpezimport'); ?>
                                        </button>
                                        <div id="wpezimport-reupload-loader" class="wpezimport-loader" style="display:none;">
                                            <span class="spinner is-active"></span>
                                        </div>
                                    </div>
                                    <div id="wpezimport-reupload-status"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="wpezimport-form-footer">
                <div class="wpezimport-save-import-section">
                    <?php
                    // Pre-populate import name and check the box when editing
                    $import_name = '';

                    if (isset($ds['import_name']) && !empty($ds['import_name'])) {
                        $import_name = esc_attr($ds['import_name']);
                    }
                    ?>

                    <div id="save-import-options">
                        <div class="wpezimport-option-row">
                            <label for="import_name"><?php _e('Import Name', 'wpezimport'); ?></label>
                            <input type="text" name="import_name" id="import_name" class="regular-text" value="<?php echo $import_name; ?>" placeholder="<?php _e('Optional - Auto-generated if left empty', 'wpezimport'); ?>">
                            <p class="description"><?php _e('Leave empty to auto-generate name with current date and time', 'wpezimport'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="wpezimport-form-buttons">
                    <button type="submit" id="wpezimport-mapping-button" class="button button-primary"><?php _e('Save Import Configuration', 'wpezimport'); ?></button>
                    <div id="wpezimport-mapping-loader" class="wpezimport-loader">
                        <span class="spinner is-active"></span>
                        <span><?php _e('Saving...', 'wpezimport'); ?></span>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
