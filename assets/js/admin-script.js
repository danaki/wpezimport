/**
 * wpezimport Admin Scripts
 */

(function($) {
    'use strict';

    // Global draft_id - set from hidden field or AJAX responses
    var wpezimport_draft_id = 0;

    // Helper to get draft_id from hidden field or global
    function getDraftId() {
        var hidden = document.getElementById('wpezimport_draft_id');
        if (hidden && hidden.value) {
            return hidden.value;
        }
        return wpezimport_draft_id || 0;
    }

    // Helper to store draft_id from AJAX response
    function storeDraftId(responseData) {
        if (responseData && responseData.draft_id) {
            wpezimport_draft_id = responseData.draft_id;
            // Also update hidden field if present
            var hidden = document.getElementById('wpezimport_draft_id');
            if (hidden) {
                hidden.value = responseData.draft_id;
            }
        }
    }

    // Initialize the file upload functionality
    function initFileUpload() {
        const fileInput = $('#wpezimport-file-input');
        const fileButton = $('#wpezimport-file-select-button');
        const fileName = $('#wpezimport-file-select-name');
        const uploadForm = $('#wpezimport-upload-form');
        const uploadButton = $('#wpezimport-upload-button');
        const loader = $('#wpezimport-loader');
        const previewContainer = $('#wpezimport-preview-container');
        const previewContent = $('#wpezimport-preview-content');

        // Handle file selection
        fileInput.on('change', function() {
            const selectedFile = this.files[0];
            if (selectedFile) {
                fileName.text(selectedFile.name);
            } else {
                fileName.text('No file chosen');
            }
        });

        // Handle form submission
        uploadForm.on('submit', function(e) {
            e.preventDefault();

            const fileToUpload = fileInput[0].files[0];
            if (!fileToUpload) {
                alert('Please select a file to upload.');
                return false;
            }

            // Check file extension
            const fileExt = fileToUpload.name.split('.').pop().toLowerCase();
            if (!['csv', 'xls', 'xlsx', 'xml'].includes(fileExt)) {
                alert('Please select a valid file (CSV, XLS, XLSX, XML).');
                return false;
            }

            // Create form data
            const formData = new FormData();
            formData.append('action', 'wpezimport_upload_file');
            formData.append('wpezimport_file', fileToUpload);
            formData.append('wpezimport_nonce', wpezimport_localized_data.upload_nonce);
            // Include saved_import_id if present on the form
            var savedImportInput = document.querySelector('input[name="saved_import_id"]');
            if (savedImportInput) {
                formData.append('saved_import_id', savedImportInput.value);
            }

            // Show loader
            uploadButton.prop('disabled', true);
            loader.css('display', 'flex');

            // Send AJAX request
            $.ajax({
                url: wpezimport_localized_data.ajax_url, // Corrected AJAX URL
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    // Hide loader
                    uploadButton.prop('disabled', false);
                    loader.hide();

                    if (response.success) {
                        storeDraftId(response.data);
                        if (response.data && response.data.xml_tree_data) {
                            if (typeof displayXmlSelectionUI === 'function') {
                                displayXmlSelectionUI(response.data.xml_tree_data, wpezimport_localized_data.post_types);
                                previewContainer.show();
                                $('html, body').animate({
                                    scrollTop: previewContainer.offset().top - 50
                                }, 500);
                            } else {
                                console.error('displayXmlSelectionUI function is not defined.');
                                previewContent.html('<p style="color:red;">Error: XML selection UI could not be loaded. Ensure plugin files are intact.</p>');
                                previewContainer.show();
                            }
                        } else if (response.data && response.data.preview) {
                            previewContent.html(response.data.preview);
                            previewContainer.show();
                            $('html, body').animate({
                                scrollTop: previewContainer.offset().top - 50
                            }, 500);
                            // Initialize CSV refresh button if present
                            initCsvRefreshButton();
                        } else if (response.data && response.data.redirect) {
                            // Handle redirects for saved imports (non-XML)
                            window.location.href = response.data.redirect;
                        } else {
                            previewContent.html('<p style="color:green;">' + (response.data.message || 'File processed.') + '</p>');
                            previewContainer.show();
                        }
                    } else {
                        alert(response.data.message || 'An error occurred while uploading the file.');
                        previewContent.html('');
                        previewContainer.hide();
                    }
                },
                error: function() {
                    // Hide loader
                    uploadButton.prop('disabled', false);
                    loader.hide();
                    previewContent.html(''); // Clear content on error
                    previewContainer.hide(); // Hide container on error
                    alert('An error occurred while uploading the file. Please try again.');
                }
            });

            return false;
        });
    }

    // Initialize URL import functionality
    function initUrlImport() {
        const urlForm = $('#wpezimport-url-form');
        const urlInput = $('#wpezimport-file-url');
        const urlButton = $('#wpezimport-url-button');
        const urlLoader = $('#wpezimport-url-loader');
        const previewContainer = $('#wpezimport-preview-container');
        const previewContent = $('#wpezimport-preview-content');

        if (urlForm.length === 0) {
            console.log('URL form not found on this page');
            return; // Not on the import page
        }

        console.log('URL import form initialized');

        // Handle form submission
        urlForm.on('submit', function(e) {
            e.preventDefault();
            console.log('URL form submitted');

            const fileUrl = urlInput.val().trim();
            if (!fileUrl) {
                alert('Please enter a valid URL.');
                return false;
            }

            console.log('Processing URL import for:', fileUrl);

            // Create form data
            const formData = new FormData();
            formData.append('action', 'wpezimport_url_import');
            formData.append('wpezimport_file_url', fileUrl);
            formData.append('wpezimport_url_nonce', wpezimport_localized_data.url_nonce);

            // Log the form data
            console.log('Form data prepared:', {
                action: 'wpezimport_url_import',
                url: fileUrl,
                nonce: wpezimport_localized_data.url_nonce // Also update logged nonce for consistency
            });

            // Show loader
            urlButton.prop('disabled', true);
            urlLoader.css('display', 'flex');

            console.log('Sending AJAX request to:', wpezimport_localized_data.ajax_url);

            // Send AJAX request using fetch API for better reliability
            fetch(wpezimport_localized_data.ajax_url, { // Corrected AJAX URL
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                console.log('Response received:', response);
                return response.json();
            })
            .then(data => {
                // Hide loader
                urlButton.prop('disabled', false);
                urlLoader.hide();
                console.log('URL import response:', data);

                if (data.success) {
                    storeDraftId(data.data);
                    if (data.data && data.data.xml_tree_data) {
                        if (typeof displayXmlSelectionUI === 'function') {
                            displayXmlSelectionUI(data.data.xml_tree_data, wpezimport_localized_data.post_types);
                            previewContainer.show();
                            $('html, body').animate({
                                scrollTop: previewContainer.offset().top - 50
                            }, 500);
                        } else {
                            console.error('displayXmlSelectionUI function is not defined.');
                            previewContent.html('<p style="color:red;">Error: XML selection UI could not be loaded. Ensure plugin files are intact.</p>');
                            previewContainer.show();
                        }
                    } else if (data.data && data.data.preview) {
                        previewContent.html(data.data.preview);
                        previewContainer.show();
                        $('html, body').animate({
                            scrollTop: previewContainer.offset().top - 50
                        }, 500);
                    } else if (data.data && data.data.redirect) {
                        // Handle redirects for saved imports (non-XML)
                        window.location.href = data.data.redirect;
                    } else {
                        previewContent.html('<p style="color:green;">' + (data.data.message || 'URL imported successfully.') + '</p>');
                        previewContainer.show();
                    }
                } else {
                    alert(data.data?.message || 'An error occurred while importing the file.');
                    console.error('URL import error:', data);
                    previewContent.html('');
                    previewContainer.hide();
                }
            })
            .catch(error => {
                // Hide loader
                urlButton.prop('disabled', false);
                urlLoader.hide();
                previewContent.html(''); // Clear content on error
                previewContainer.hide(); // Hide container on error
                console.error('Fetch error:', error);
                alert('An error occurred while importing the file: ' + error.message);
            });

            return false;
        });
    }

    // Initialize tabs functionality - REMOVED: Tabs replaced with separate forms
    /*
    function initTabs() {
        const tabLinks = $('.wpezimport-tab-nav a');
        const tabPanes = $('.wpezimport-tab-pane');

        tabLinks.on('click', function(e) {
            e.preventDefault();

            const targetId = $(this).attr('href');

            // Update active tab
            tabLinks.parent().removeClass('active');
            $(this).parent().addClass('active');

            // Show target tab pane
            tabPanes.removeClass('active');
            $(targetId).addClass('active');
        });
    }
    */

    // Initialize the mapping form functionality with drag and drop
    function initMappingForm() {
        const mappingForm = $('#wpezimport-mapping-form');
        const mappingButton = $('#wpezimport-mapping-button');
        const mappingLoader = $('#wpezimport-mapping-loader');

        if (mappingForm.length === 0) {
            return; // Not on the mapping page
        }

        // Initialize drag and drop functionality
        initDragAndDrop();

        // Initialize search functionality
        initColumnSearch();

        // Initialize custom field adder
        initCustomFieldAdder();

        // Handle form submission
        mappingForm.on('submit', function(e) {
            e.preventDefault();

            // Validate form - ensure at least one field has a value
            let hasMapping = false;
            $('.wpezimport-field-pattern').each(function() {
                if ($(this).val().trim() !== '') {
                    hasMapping = true;
                    return false; // Break the loop
                }
            });

            if (!hasMapping) {
                alert('Please map at least one WordPress field by dragging imported columns into it.');
                return false;
            }

            // Prepare form data
            const formData = new FormData(this);
            formData.append('action', 'wpezimport_save_mapping');
            formData.append('wpezimport_mapping_nonce', $('#wpezimport_mapping_nonce').val());

            // Show loader
            mappingButton.prop('disabled', true);
            mappingLoader.css('display', 'flex');

            // Send AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    // Hide loader
                    mappingButton.prop('disabled', false);
                    mappingLoader.hide();

                    if (response.success) {
                        // Redirect to import page
                        window.location.href = response.data.redirect;
                    } else {
                        alert(response.data.message || 'An error occurred while saving mapping.');
                    }
                },
                error: function(xhr, status, error) {
                    // Hide loader
                    mappingButton.prop('disabled', false);
                    mappingLoader.hide();

                    console.error('AJAX Error:', xhr.responseText);
                    alert('An error occurred while saving mapping. Please try again.');
                }
            });

            return false;
        });
    }

    // Unified drop handler for both imported columns and XML elements
    function unifiedDropHandler(e) {
        e.preventDefault();
        this.classList.remove('highlight');
        this.classList.remove('wpezimport-drop-active');

        let data = e.dataTransfer.getData('text/plain');
        console.log('Dropped data:', data);
        let columnData;
        let xpath = null;

        // Try to parse as JSON (imported column drag), fallback to plain string (XML drag)
        try {
            columnData = JSON.parse(data);
            if (columnData && columnData.xpath) {
                xpath = columnData.xpath;
            } else if (columnData && columnData.name) {
                // If only column name, treat as column
                xpath = columnData.name;
            }
        } catch (err) {
            // Not JSON, treat as plain XPath string
            xpath = data;
        }

        if (!xpath) {
            console.warn('No XPath or column data found in drop event');
            return;
        }

        // Insert as {xpath} at cursor or append
        if (document.selection) {
            console.log('Inserting via selection');
            this.focus();
            const sel = document.selection.createRange();
            sel.text = '{' + xpath + '}';
        } else if (this.selectionStart || this.selectionStart === 0) {
            console.log('Inserting via selectionStart');

            const startPos = this.selectionStart;
            const endPos = this.selectionEnd;
            const scrollTop = this.scrollTop;
            this.value = this.value.substring(0, startPos) +
                                    '{' + xpath + '}' +
                                    this.value.substring(endPos, this.value.length);
            this.focus();
            this.selectionStart = startPos + xpath.length + 2;
            this.selectionEnd = startPos + xpath.length + 2;
            this.scrollTop = scrollTop;
        } else {
            console.log('Inserting via append');
            this.value += '{' + xpath + '}';
        }

        // Trigger change event
        const event = new Event('change', { bubbles: true });
        this.dispatchEvent(event);

        // Add visual feedback
        this.classList.add('highlight');
        setTimeout(() => {
            this.classList.remove('highlight');
        }, 800);
    }

    // Initialize drag and drop functionality
    function initDragAndDrop() {
        const importedColumns = document.querySelectorAll('.wpezimport-imported-column');
        const fieldInputs = document.querySelectorAll('.wpezimport-field-pattern');

        // Add drag events to imported columns
        importedColumns.forEach(column => {
            column.addEventListener('dragstart', handleDragStart);
            column.addEventListener('dragend', handleDragEnd);
        });

        // Add unified drop events to field inputs
        fieldInputs.forEach(input => {
            input.addEventListener('dragover', handleDragOver);
            input.removeEventListener('drop', unifiedDropHandler); // Prevent duplicate
            input.addEventListener('drop', unifiedDropHandler);
        });
    }

    // Initialize search functionality for imported columns
    function initColumnSearch() {
        const searchInput = document.getElementById('wpezimport-column-search');
        if (!searchInput) return;

        searchInput.addEventListener('input', function() {
            const searchText = this.value.toLowerCase().trim();
            const columns = document.querySelectorAll('.wpezimport-imported-column, .wpezimport-column-item');

            columns.forEach(column => {
                const columnName = column.dataset.column.toLowerCase();
                if (columnName.includes(searchText)) {
                    column.style.display = '';
                } else {
                    column.style.display = 'none';
                }
            });
        });
    }

    // Initialize custom field adder
    function initCustomFieldAdder() {
        const addButton = document.getElementById('wpezimport-add-custom-field');
        if (!addButton) return;

        addButton.addEventListener('click', function() {
            const container = document.getElementById('wpezimport-custom-fields-container');
            if (!container) return;

            // Generate unique ID for the new field
            const uniqueId = 'new_' + Date.now();

            // Create new field row
            const newRow = document.createElement('div');
            newRow.className = 'wpezimport-custom-field-row';

            newRow.innerHTML = `
                <div class="wpezimport-field-input-group">
                    <div class="wpezimport-field-name">
                        <input type="text" class="wpezimport-custom-field-name"
                               name="custom_field_names[]"
                               value=""
                               placeholder="Custom Field Name">
                    </div>
                    <div class="wpezimport-field-value">
                        <input type="text"
                               name="field_mapping[custom_field_${uniqueId}]"
                               class="regular-text wpezimport-field-pattern"
                               value=""
                               placeholder="Value or Column">
                    </div>
                    <button type="button" class="button wpezimport-remove-custom-field" title="Remove">×</button>
                </div>
            `;

            container.appendChild(newRow);

            // Add event listener to remove button
            const removeBtn = newRow.querySelector('.wpezimport-remove-custom-field');
            removeBtn.addEventListener('click', function() {
                newRow.remove();
            });

            // Add event listener to update field name in the value input's name attribute
            const nameInput = newRow.querySelector('.wpezimport-custom-field-name');
            const valueInput = newRow.querySelector('.wpezimport-field-value input');
            nameInput.addEventListener('change', function() {
                const fieldName = this.value.trim() || uniqueId;
                valueInput.name = 'field_mapping[custom_field_' + fieldName + ']';
            });

            // Focus the name input
            nameInput.focus();

            // Add drop functionality to the value input
            valueInput.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
            });
            valueInput.addEventListener('drop', unifiedDropHandler);
        });

        // Initialize remove buttons for existing custom fields
        document.querySelectorAll('.wpezimport-remove-custom-field').forEach(function(btn) {
            btn.addEventListener('click', function() {
                this.closest('.wpezimport-custom-field-row').remove();
            });
        });

        // Initialize name change listeners for existing custom fields
        document.querySelectorAll('.wpezimport-custom-field-row').forEach(function(row) {
            const nameInput = row.querySelector('.wpezimport-custom-field-name');
            const valueInput = row.querySelector('.wpezimport-field-value input');
            if (nameInput && valueInput) {
                nameInput.addEventListener('change', function() {
                    const fieldName = this.value.trim();
                    if (fieldName) {
                        valueInput.name = 'field_mapping[custom_field_' + fieldName + ']';
                    }
                });
            }
        });
    }

    // Handle drag over
    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    }

    // Initialize XML structure functionality
    function initXmlStructureDisplay() {
        // Copy XPath to clipboard
        $('.wpezimport-copy-xpath').on('click', function() {
            const xpath = $(this).data('xpath');
            const fieldInput = $('.wpezimport-field-pattern:focus').length ? $('.wpezimport-field-pattern:focus') : $('.wpezimport-field-pattern').first();

            // Always define xpathWithBraces in this scope
            const xpathWithBraces = '{' + xpath + '}';

            // If there's a focused field, insert the XPath there
            if (fieldInput.length) {
                // Get current cursor position
                const input = fieldInput.get(0);
                if (input && typeof input.selectionStart === 'number') {
                    const startPos = input.selectionStart;
                    const endPos = input.selectionEnd;
                    const scrollTop = input.scrollTop;
                    input.value = input.value.substring(0, startPos) + xpathWithBraces + input.value.substring(endPos);
                    input.selectionStart = startPos + xpathWithBraces.length;
                    input.selectionEnd = startPos + xpathWithBraces.length;
                    input.scrollTop = scrollTop;
                } else {
                    fieldInput.val(fieldInput.val() + xpathWithBraces);
                }
                fieldInput.trigger('change');
            }

            // Copy to clipboard
            const tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(xpathWithBraces).select();
            document.execCommand('copy');
            tempInput.remove();

            // Show success message
            showNotice('XPath copied to clipboard: ' + xpathWithBraces, 'success');
        });

        // Toggle XML node children
        $('.wpezimport-xml-node-header').on('click', function(e) {
            if (!$(e.target).hasClass('wpezimport-copy-xpath')) {
                $(this).siblings('.wpezimport-xml-children').toggle();
            }
        });
    }

    // Show notification
    function showNotice(message, type = 'info') {
        const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

        // Remove existing notices
        $('.wpezimport-notice-container').remove();

        // Create container and add notice
        const container = $('<div class="wpezimport-notice-container" style="position: fixed; top: 50px; right: 20px; z-index: 9999;"></div>');
        container.append(notice);
        $('body').append(container);

        // Auto-dismiss after 3 seconds
        setTimeout(function() {
            container.fadeOut(500, function() {
                $(this).remove();
            });
        }, 3000);
    }

    // Initialize XML sample navigation
    function initXmlSampleNavigation() {
        const prevButton = document.getElementById('wpezimport-prev-xml-sample');
        const nextButton = document.getElementById('wpezimport-next-xml-sample');

        if (!prevButton || !nextButton) return;

        console.log('Initializing XML sample navigation buttons');

        prevButton.addEventListener('click', function() {
            if (this.disabled) return;

            console.log('Previous button clicked');

            // Show loading state
            document.getElementById('wpezimport-xml-structure').innerHTML = '<div class="wpezimport-loading">Loading previous sample...</div>';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpezimport_navigate_xml_sample',
                    direction: 'prev',
                    wpezimport_mapping_nonce: $('#wpezimport_mapping_nonce').val(),
                    draft_id: getDraftId()
                },
                success: function(response) {
                    console.log('AJAX response:', response);
                    if (response.success) {
                        // Update the XML structure
                        document.getElementById('wpezimport-xml-structure').innerHTML = response.data.formatted_xml;
                        document.getElementById('wpezimport-xml-structure').style.display = 'block';

                        // Update counter (replace HTML, not just textContent)
                        const counter = document.querySelector('.wpezimport-product-counter');
                        if (counter) {
                            counter.outerHTML = response.data.sample_counter_html;
                        }

                        // Update navigation buttons
                        prevButton.disabled = !response.data.has_prev;
                        nextButton.disabled = !response.data.has_next;

                        // Reinitialize draggable elements
                        initXmlStructureDragDrop();
                    } else {
                        console.error('Error navigating XML sample:', response.data.message);
                        document.getElementById('wpezimport-xml-structure').innerHTML =
                            '<div class="wpezimport-error">Error: ' + response.data.message + '</div>';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    console.error('Response:', xhr.responseText);
                    document.getElementById('wpezimport-xml-structure').innerHTML =
                        '<div class="wpezimport-error">AJAX error: ' + status + ' - ' + error + '</div>';
                }
            });
        });

        nextButton.addEventListener('click', function() {
            if (this.disabled) return;

            console.log('Next button clicked');

            // Show loading state
            document.getElementById('wpezimport-xml-structure').innerHTML = '<div class="wpezimport-loading">Loading next sample...</div>';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpezimport_navigate_xml_sample',
                    direction: 'next',
                    wpezimport_mapping_nonce: $('#wpezimport_mapping_nonce').val(),
                    draft_id: getDraftId()
                },
                success: function(response) {
                    console.log('AJAX response:', response);
                    if (response.success) {
                        // Update the XML structure
                        document.getElementById('wpezimport-xml-structure').innerHTML = response.data.formatted_xml;
                        document.getElementById('wpezimport-xml-structure').style.display = 'block';

                        // Update counter (replace HTML, not just textContent)
                        const counter = document.querySelector('.wpezimport-product-counter');
                        if (counter) {
                            counter.outerHTML = response.data.sample_counter_html;
                        }

                        // Update navigation buttons
                        prevButton.disabled = !response.data.has_prev;
                        nextButton.disabled = !response.data.has_next;

                        // Reinitialize draggable elements
                        initXmlStructureDragDrop();
                    } else {
                        console.error('Error navigating XML sample:', response.data.message);
                        document.getElementById('wpezimport-xml-structure').innerHTML =
                            '<div class="wpezimport-error">Error: ' + response.data.message + '</div>';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    console.error('Response:', xhr.responseText);
                    document.getElementById('wpezimport-xml-structure').innerHTML =
                        '<div class="wpezimport-error">AJAX error: ' + status + ' - ' + error + '</div>';
                }
            });
        });
    }

    // Initialize Refetch XML button for URL imports
    function initRefetchXml() {
        const refetchBtn = document.getElementById('wpezimport-refetch-xml');
        if (!refetchBtn) return;

        refetchBtn.addEventListener('click', function() {
            const url = this.getAttribute('data-url');
            const xpath = this.getAttribute('data-xpath');
            const statusEl = document.getElementById('wpezimport-refetch-status');
            const structureEl = document.getElementById('wpezimport-xml-structure');

            if (!url || !xpath) {
                if (statusEl) statusEl.innerHTML = '<span style="color:#dc3232;">URL or XPath is missing.</span>';
                return;
            }

            // Show loading state
            refetchBtn.disabled = true;
            if (statusEl) statusEl.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 5px;"></span> Fetching...';
            if (structureEl) structureEl.innerHTML = '<div class="wpezimport-loading">Downloading and parsing XML...</div>';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpezimport_refetch_xml',
                    nonce: wpezimport_localized_data.nonce,
                    url: url,
                    xpath: xpath,
                    draft_id: getDraftId()
                },
                success: function(response) {
                    refetchBtn.disabled = false;
                    if (response.success) {
                        if (statusEl) statusEl.innerHTML = '<span style="color:#46b450;">' + response.data.message + '</span>';
                        if (structureEl) structureEl.innerHTML = response.data.xml_html;

                        // Show/update navigation if samples > 0
                        var navEl = document.querySelector('.wpezimport-xml-navigation');
                        if (response.data.total_samples > 0 && !navEl) {
                            // Build navigation HTML and insert before structure
                            var navHtml = '<div class="wpezimport-xml-navigation">' +
                                '<button type="button" class="button button-small" id="wpezimport-prev-xml-sample" disabled>' +
                                '<span class="dashicons dashicons-arrow-left-alt"></span></button>' +
                                '<span class="wpezimport-product-counter">Sample 1 of ' + response.data.total_samples + '</span>' +
                                '<button type="button" class="button button-small" id="wpezimport-next-xml-sample"' +
                                (response.data.total_samples <= 1 ? ' disabled' : '') + '>' +
                                '<span class="dashicons dashicons-arrow-right-alt"></span></button></div>';
                            structureEl.insertAdjacentHTML('beforebegin', navHtml);
                            initXmlSampleNavigation();
                        } else if (navEl && response.data.total_samples > 0) {
                            // Update existing counter
                            var counter = navEl.querySelector('.wpezimport-product-counter');
                            if (counter) counter.textContent = 'Sample 1 of ' + response.data.total_samples;
                            var prevBtn = document.getElementById('wpezimport-prev-xml-sample');
                            var nextBtn = document.getElementById('wpezimport-next-xml-sample');
                            if (prevBtn) prevBtn.disabled = true;
                            if (nextBtn) nextBtn.disabled = (response.data.total_samples <= 1);
                        }

                        // Reinitialize drag-drop on new elements
                        initXmlStructureDragDrop();
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                        if (statusEl) statusEl.innerHTML = '<span style="color:#dc3232;">' + msg + '</span>';
                        if (structureEl) structureEl.innerHTML = '<p class="wpezimport-error">' + msg + '</p><p>Click "Refetch XML" to retry.</p>';
                    }
                },
                error: function(xhr, status, error) {
                    refetchBtn.disabled = false;
                    var msg = 'AJAX error: ' + status + ' - ' + error;
                    if (statusEl) statusEl.innerHTML = '<span style="color:#dc3232;">' + msg + '</span>';
                    if (structureEl) structureEl.innerHTML = '<p class="wpezimport-error">' + msg + '</p>';
                }
            });
        });
    }

    // Initialize draggable XML elements in the structure viewer
    function initXmlStructureDragDrop() {
        console.log('Initializing XML structure drag and drop');

        // Wait for DOM to be fully loaded
        setTimeout(function() {
            // Select all draggable XML elements (both regular elements and tags and attributes)
            const xmlElements = document.querySelectorAll('.wpezimport-xml-tag-name[draggable="true"], .wpezimport-xml-attribute[draggable="true"]');
            console.log('Found ' + xmlElements.length + ' draggable XML elements');

            xmlElements.forEach(element => {
                // Make sure draggable attribute is set
                element.setAttribute('draggable', 'true');

                // Add dragstart event to set dataTransfer with XPath
                element.addEventListener('dragstart', function(e) {
                    const xpath = this.getAttribute('data-xpath');
                    if (xpath) {
                        e.dataTransfer.setData('text/plain', xpath);
                        e.dataTransfer.effectAllowed = 'copy';
                    } else {
                        console.warn('No data-xpath found for dragged XML element', this);
                    }
                });

                // Add tooltip events
                element.addEventListener('mouseenter', function() {
                    // Show full XPath in tooltip when hovering
                    const xpath = this.getAttribute('data-xpath');
                    if (xpath && !this.querySelector('.wpezimport-xml-element-path')) {
                        const tooltip = document.createElement('span');
                        tooltip.className = 'wpezimport-xml-element-path';
                        tooltip.textContent = xpath;
                        this.appendChild(tooltip);
                    }
                });
            });

            // Add drop targets for input fields
            const inputFields = document.querySelectorAll('.wpezimport-field-input-container input[type="text"]');
            console.log('Found ' + inputFields.length + ' input fields');

            inputFields.forEach(field => {
                // Remove any existing listeners to prevent duplicates
                field.removeEventListener('dragover', handleDragOver);
                field.removeEventListener('drop', unifiedDropHandler);

                // Add the event listeners
                field.addEventListener('dragover', handleDragOver);
                field.addEventListener('drop', unifiedDropHandler);
                field.addEventListener('dragenter', function() {
                    this.classList.add('highlight');
                });
                field.addEventListener('dragleave', function() {
                    this.classList.remove('highlight');
                });
            });

            // Make XML tag names and attributes draggable and visually distinct
            // Attach dragstart to attributes and stop propagation
            const xmlAttributes = document.querySelectorAll('.wpezimport-xml-attribute[draggable="true"]');
            xmlAttributes.forEach(attr => {
                attr.classList.add('wpezimport-draggable-attribute'); // green color
                attr.addEventListener('dragstart', function(e) {
                    const xpath = this.getAttribute('data-xpath');
                    if (xpath) {
                        e.dataTransfer.setData('text/plain', xpath);
                        e.dataTransfer.effectAllowed = 'copy';
                    }
                    e.stopPropagation(); // Prevent parent dragstart from firing
                    this.classList.add('wpezimport-dragging');
                });
                attr.addEventListener('dragend', function() {
                    this.classList.remove('wpezimport-dragging');
                });
            });

            // Attach dragstart to tag names (not attributes)
            const xmlTags = document.querySelectorAll('.wpezimport-xml-tag[draggable="true"]');
            xmlTags.forEach(tag => {
                tag.classList.add('wpezimport-draggable-tag'); // highlight style
                tag.addEventListener('dragstart', function(e) {
                    const xpath = this.getAttribute('data-xpath');
                    if (xpath) {
                        e.dataTransfer.setData('text/plain', xpath);
                        e.dataTransfer.effectAllowed = 'copy';
                    }
                    this.classList.add('wpezimport-dragging');
                });
                tag.addEventListener('dragend', function() {
                    this.classList.remove('wpezimport-dragging');
                });
            });
        }, 500); // Short delay to ensure DOM is ready
    }

    // Initialize search functionality for imported columns
    function initColumnSearch() {
        const searchInput = document.getElementById('wpezimport-column-search');
        if (!searchInput) return;

        searchInput.addEventListener('input', function() {
            const searchText = this.value.toLowerCase().trim();
            const columns = document.querySelectorAll('.wpezimport-imported-column, .wpezimport-column-item');

            columns.forEach(column => {
                const columnName = column.dataset.column.toLowerCase();
                if (columnName.includes(searchText)) {
                    column.style.display = '';
                } else {
                    column.style.display = 'none';
                }
            });
        });
    }

    // Handle drag over
    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
    }

    // Initialize XML structure functionality
    function initXmlStructureDisplay() {
        // Copy XPath to clipboard
        $('.wpezimport-copy-xpath').on('click', function() {
            const xpath = $(this).data('xpath');
            const fieldInput = $('.wpezimport-field-pattern:focus').length ? $('.wpezimport-field-pattern:focus') : $('.wpezimport-field-pattern').first();

            // Always define xpathWithBraces in this scope
            const xpathWithBraces = '{' + xpath + '}';

            // If there's a focused field, insert the XPath there
            if (fieldInput.length) {
                // Get current cursor position
                const input = fieldInput.get(0);
                if (input && typeof input.selectionStart === 'number') {
                    const startPos = input.selectionStart;
                    const endPos = input.selectionEnd;
                    const scrollTop = input.scrollTop;
                    input.value = input.value.substring(0, startPos) + xpathWithBraces + input.value.substring(endPos);
                    input.selectionStart = startPos + xpathWithBraces.length;
                    input.selectionEnd = startPos + xpathWithBraces.length;
                    input.scrollTop = scrollTop;
                } else {
                    fieldInput.val(fieldInput.val() + xpathWithBraces);
                }
                fieldInput.trigger('change');
            }

            // Copy to clipboard
            const tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(xpathWithBraces).select();
            document.execCommand('copy');
            tempInput.remove();

            // Show success message
            showNotice('XPath copied to clipboard: ' + xpathWithBraces, 'success');
        });

        // Toggle XML node children
        $('.wpezimport-xml-node-header').on('click', function(e) {
            if (!$(e.target).hasClass('wpezimport-copy-xpath')) {
                $(this).siblings('.wpezimport-xml-children').toggle();
            }
        });
    }

    // Show notification
    function showNotice(message, type = 'info') {
        const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

        // Remove existing notices
        $('.wpezimport-notice-container').remove();

        // Create container and add notice
        const container = $('<div class="wpezimport-notice-container" style="position: fixed; top: 50px; right: 20px; z-index: 9999;"></div>');
        container.append(notice);
        $('body').append(container);

        // Auto-dismiss after 3 seconds
        setTimeout(function() {
            container.fadeOut(500, function() {
                $(this).remove();
            });
        }, 3000);
    }

    // Initialize XML sample navigation
    function initXmlSampleNavigation() {
        const prevButton = document.getElementById('wpezimport-prev-xml-sample');
        const nextButton = document.getElementById('wpezimport-next-xml-sample');

        if (!prevButton || !nextButton) return;

        console.log('Initializing XML sample navigation buttons');

        prevButton.addEventListener('click', function() {
            if (this.disabled) return;

            console.log('Previous button clicked');

            // Show loading state
            document.getElementById('wpezimport-xml-structure').innerHTML = '<div class="wpezimport-loading">Loading previous sample...</div>';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpezimport_navigate_xml_sample',
                    direction: 'prev',
                    wpezimport_mapping_nonce: $('#wpezimport_mapping_nonce').val(),
                    draft_id: getDraftId()
                },
                success: function(response) {
                    console.log('AJAX response:', response);
                    if (response.success) {
                        // Update the XML structure
                        document.getElementById('wpezimport-xml-structure').innerHTML = response.data.formatted_xml;
                        document.getElementById('wpezimport-xml-structure').style.display = 'block';

                        // Update counter (replace HTML, not just textContent)
                        const counter = document.querySelector('.wpezimport-product-counter');
                        if (counter) {
                            counter.outerHTML = response.data.sample_counter_html;
                        }

                        // Update navigation buttons
                        prevButton.disabled = !response.data.has_prev;
                        nextButton.disabled = !response.data.has_next;

                        // Reinitialize draggable elements
                        initXmlStructureDragDrop();
                    } else {
                        console.error('Error navigating XML sample:', response.data.message);
                        document.getElementById('wpezimport-xml-structure').innerHTML =
                            '<div class="wpezimport-error">Error: ' + response.data.message + '</div>';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    console.error('Response:', xhr.responseText);
                    document.getElementById('wpezimport-xml-structure').innerHTML =
                        '<div class="wpezimport-error">AJAX error: ' + status + ' - ' + error + '</div>';
                }
            });
        });

        nextButton.addEventListener('click', function() {
            if (this.disabled) return;

            console.log('Next button clicked');

            // Show loading state
            document.getElementById('wpezimport-xml-structure').innerHTML = '<div class="wpezimport-loading">Loading next sample...</div>';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpezimport_navigate_xml_sample',
                    direction: 'next',
                    wpezimport_mapping_nonce: $('#wpezimport_mapping_nonce').val(),
                    draft_id: getDraftId()
                },
                success: function(response) {
                    console.log('AJAX response:', response);
                    if (response.success) {
                        // Update the XML structure
                        document.getElementById('wpezimport-xml-structure').innerHTML = response.data.formatted_xml;
                        document.getElementById('wpezimport-xml-structure').style.display = 'block';

                        // Update counter (replace HTML, not just textContent)
                        const counter = document.querySelector('.wpezimport-product-counter');
                        if (counter) {
                            counter.outerHTML = response.data.sample_counter_html;
                        }

                        // Update navigation buttons
                        prevButton.disabled = !response.data.has_prev;
                        nextButton.disabled = !response.data.has_next;

                        // Reinitialize draggable elements
                        initXmlStructureDragDrop();
                    } else {
                        console.error('Error navigating XML sample:', response.data.message);
                        document.getElementById('wpezimport-xml-structure').innerHTML =
                            '<div class="wpezimport-error">Error: ' + response.data.message + '</div>';
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error);
                    console.error('Response:', xhr.responseText);
                    document.getElementById('wpezimport-xml-structure').innerHTML =
                        '<div class="wpezimport-error">AJAX error: ' + status + ' - ' + error + '</div>';
                }
            });
        });
    }

    // === XML Element Selection UI Functions ===
    let selectedXmlPath = null; // Stores the XPath of the selected XML node

    /**
     * Renders a single XML node and its children for the tree view.
     * @param {object} nodeData - The data for the current XML node.
     * @param {jQuery} parentElement - The jQuery element to append this node to.
     * @param {number} depth - The current depth in the XML tree (for indentation).
     */
    function renderXmlNode(nodeData, parentElement, depth) {
        const PADDING_PER_LEVEL = 20; // pixels
        const nodeElement = $('<div>').addClass('xml-tree-node').css('padding-left', (depth * PADDING_PER_LEVEL) + 'px');
        const nodeHeader = $('<div>').addClass('xml-tree-node-header');

        const toggleIcon = $('<span>').addClass('xml-tree-toggle').text(nodeData.children && nodeData.children.length > 0 ? (depth === 0 ? '▼' : '►') : '    ').css('cursor', 'default');
        if (nodeData.children && nodeData.children.length > 0) {
            toggleIcon.css('cursor', 'pointer');
        }

        const nameElement = $('<span>').addClass('xml-tree-node-name').text(nodeData.name).css('cursor', 'pointer');
        nameElement.attr('title', 'Click to select this element: ' + nodeData.xpath + '\nChildren: ' + nodeData.child_count + ', Attributes: ' + nodeData.attribute_count);

        nodeHeader.append(toggleIcon).append(nameElement);

        if (nodeData.attributes && Object.keys(nodeData.attributes).length > 0) {
            const attrsString = Object.entries(nodeData.attributes).map(([key, val]) => `${key}="${val}"`).join(' ');
            const attrElement = $('<span>').addClass('xml-tree-node-attrs').text(' (' + attrsString + ')');
            nodeHeader.append(attrElement);
        }

        if (nodeData.text_preview) {
            const textPreviewElement = $('<span>').addClass('xml-tree-node-preview').text(' :: ' + nodeData.text_preview);
            nodeHeader.append(textPreviewElement);
        }

        nodeElement.append(nodeHeader);
        parentElement.append(nodeElement);

        const childrenContainer = $('<div>').addClass('xml-tree-node-children').css('padding-left', PADDING_PER_LEVEL + 'px');
        if (depth > 0) { // Collapse children by default for non-root nodes
            childrenContainer.hide();
        }

        if (nodeData.children && nodeData.children.length > 0) {
            nodeData.children.forEach(child => {
                renderXmlNode(child, childrenContainer, depth + 1);
            });
            nodeElement.append(childrenContainer);
        }

        // Click to toggle children visibility
        toggleIcon.on('click', function(e) {
            e.stopPropagation();
            if (nodeData.children && nodeData.children.length > 0) {
                childrenContainer.toggle();
                toggleIcon.text(childrenContainer.is(':visible') ? '▼' : '►');
            }
        });

        // Click to select node
        nameElement.on('click', function(e) {
            e.stopPropagation();
            $('.xml-tree-node-name.selected').removeClass('selected');
            nameElement.addClass('selected');
            selectedXmlPath = nodeData.xpath;
            $('#xml-selected-path-display').text('Selected Path: ' + selectedXmlPath).css('font-weight', 'bold');
            // Enable confirm button now that XML path is selected (post type is hardcoded to 'product')
            $('#wpezimport-confirm-xml-selection').prop('disabled', false);
        });
    }

    /**
     * Displays the XML selection UI: tree, post type dropdown, and confirm button.
     * @param {object} xmlTreeData - The hierarchical data of the XML file.
     * @param {object} postTypes - Object mapping post type slugs to labels.
     */
    function displayXmlSelectionUI(xmlTreeData, postTypes) {
        selectedXmlPath = null; // Reset selected path
        const previewContent = $('#wpezimport-preview-content');
        previewContent.empty(); // Clear previous content

        const description = $('<p>')
            .text('Select the XML element that contains the product data.').css({'margin-bottom': '10px'});
        previewContent.append(description);

        const treeContainer = $('<div>').attr('id', 'xml-tree-container').css({'border': '1px solid #ccc', 'padding': '10px', 'max-height': '400px', 'overflow-y': 'auto', 'margin-bottom': '15px'});
        if (xmlTreeData && xmlTreeData.name) { // Check if it's the root node object
            renderXmlNode(xmlTreeData, treeContainer, 0);
        } else {
            treeContainer.text('Error: Invalid XML data structure received.');
        }
        previewContent.append(treeContainer);

        const selectionInfo = $('<div>').attr('id', 'xml-selected-path-display').css({'margin-bottom': '10px', 'min-height': '20px'});
        previewContent.append(selectionInfo);

        // Post type is hardcoded to 'product' (WooCommerce) - no selector needed

        // Confirm Button
        const confirmButton = $('<button>')
            .attr('id', 'wpezimport-confirm-xml-selection')
            .addClass('button button-primary')
            .text(wpezimport_localized_data.strings.confirm_selection || 'Confirm Selection and Proceed to Mapping')
            .prop('disabled', true); // Disabled until XML path is selected
        previewContent.append(confirmButton);

        confirmButton.on('click', handleXmlSelectionConfirmation);
    }

    /**
     * Handles the confirmation of XML element and post type selection.
     * Sends the selected data to the server via AJAX.
     */
    function handleXmlSelectionConfirmation() {
        if (!selectedXmlPath) {
            alert(wpezimport_localized_data.strings.alert_select_xml_element || 'Please select an XML element from the tree.');
            return;
        }

        const $confirmButton = $('#wpezimport-confirm-xml-selection');
        $confirmButton.prop('disabled', true).text(wpezimport_localized_data.strings.processing || 'Processing...');
        // Consider adding a visual loader here as well

        $.ajax({
            url: wpezimport_localized_data.ajax_url,
            type: 'POST',
            data: {
                action: 'wpezimport_finalize_step1_selection',
                selected_xpath: selectedXmlPath,
                selected_post_type: 'product',
                finalize_nonce: wpezimport_localized_data.finalize_nonce,
                draft_id: getDraftId()
            },
            success: function(response) {
                if (response.success) {
                    if (response.data && response.data.redirect_url) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        // Should not happen if PHP sends redirect_url
                        alert(wpezimport_localized_data.strings.success_proceed_manually || 'Selection successful. Proceed to mapping (URL missing).');
                        $confirmButton.prop('disabled', false).text(wpezimport_localized_data.strings.confirm_selection || 'Confirm Selection and Proceed to Mapping');
                    }
                } else {
                    alert((wpezimport_localized_data.strings.error_finalizing || 'Error finalizing selection: ') + (response.data && response.data.message ? response.data.message : 'Unknown error.'));
                    $confirmButton.prop('disabled', false).text(wpezimport_localized_data.strings.confirm_selection || 'Confirm Selection and Proceed to Mapping');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error finalizing XML selection:', textStatus, errorThrown, jqXHR.responseText);
                alert((wpezimport_localized_data.strings.error_ajax || 'AJAX error: ') + textStatus);
                $confirmButton.prop('disabled', false).text(wpezimport_localized_data.strings.confirm_selection || 'Confirm Selection and Proceed to Mapping');
            }
        });
    }
    // === END XML Element Selection UI Functions ===

    // Initialize mapping form save handler
    function initMappingFormSave() {
        $('#wpezimport-mapping-form').on('submit', function(e) {
            e.preventDefault();

            const $form = $(this);
            const $submitButton = $form.find('button[type="submit"]');
            const originalButtonText = $submitButton.text();
            const $loader = $('#wpezimport-loader'); // Assuming a general loader exists, or create one

            // Show loader and disable button
            $submitButton.prop('disabled', true).text(wpezimport_localized_data.strings.saving || 'Saving...');
            if ($loader.length) {
                $loader.css('display', 'flex');
            } else {
                // Fallback simple loader indicator
                $form.find('.wpezimport-form-buttons').append('<span class="spinner is-active" style="float: none; margin-left: 5px;"></span>');
            }

            // Collect form data
            let formData = $form.serializeArray(); // Gets most fields
            let data = {};
            $.each(formData, function() {
                if (data[this.name] !== undefined) {
                    if (!data[this.name].push) {
                        data[this.name] = [data[this.name]];
                    }
                    data[this.name].push(this.value || '');
                } else {
                    data[this.name] = this.value || '';
                }
            });

            // Ensure TinyMCE content is captured (WP editor for post_content)
            if (typeof tinymce !== 'undefined' && tinymce.get('post_content')) {
                data['field_mapping[post_content]'] = tinymce.get('post_content').getContent();
            }

            // Add action and nonce explicitly (though serializeArray should get the nonce if it's an input)
            data.action = 'wpezimport_save_mapping';
            if (!$form.find('input[name="wpezimport_mapping_nonce"]').length) { // If nonce wasn't part of form inputs (it is)
                data.wpezimport_mapping_nonce = wpezimport_localized_data.mapping_nonce; // Assuming mapping_nonce is localized
            } else {
                data.wpezimport_mapping_nonce = $form.find('input[name="wpezimport_mapping_nonce"]').val();
            }

            // Log data for debugging
            // console.log('Submitting mapping data:', data);

            $.ajax({
                url: wpezimport_localized_data.ajax_url,
                type: 'POST',
                data: data,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Display success message
                        let successMessage = wpezimport_localized_data.strings.config_saved || 'Configuration saved successfully.';
                        if(response.data && response.data.message) {
                            successMessage = response.data.message;
                        }
                        // Add a visual success indication
                        $form.before('<div class="notice notice-success is-dismissible"><p>' + successMessage + '</p></div>');

                        // Optionally redirect or update UI
                        if (response.data && response.data.redirect_url) {
                            window.location.href = response.data.redirect_url;
                        } else {
                            // Re-enable button, reset text, remove spinner
                            $submitButton.prop('disabled', false).text(originalButtonText);
                            if ($loader.length) $loader.hide();
                            $form.find('.spinner').remove();
                            // Scroll to top to see message
                            $('html, body').animate({ scrollTop: 0 }, 'slow');
                        }
                    } else {
                        let errorMessage = wpezimport_localized_data.strings.error_saving_config || 'An error occurred while saving the configuration.';
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                        alert(errorMessage);
                        $submitButton.prop('disabled', false).text(originalButtonText);
                        if ($loader.length) $loader.hide();
                        $form.find('.spinner').remove();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    alert((wpezimport_localized_data.strings.ajax_error || 'AJAX error:') + ' ' + textStatus + ' - ' + errorThrown);
                    $submitButton.prop('disabled', false).text(originalButtonText);
                    if ($loader.length) $loader.hide();
                    $form.find('.spinner').remove();
                }
            });
        });
    }

    // Initialize CSV refresh button for delimiter/enclosure changes
    function initCsvRefreshButton() {
        $(document).off('click', '#wpezimport-refresh-preview').on('click', '#wpezimport-refresh-preview', function(e) {
            e.preventDefault();

            const delimiter = $('#csv_delimiter').val();
            const enclosure = $('#csv_enclosure').val();
            const previewContent = $('#wpezimport-preview-content');
            const refreshButton = $(this);

            // Disable button and show loading state
            refreshButton.prop('disabled', true).text(wpezimport_localized_data.strings.refreshing || 'Refreshing...');

            $.ajax({
                url: wpezimport_localized_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpezimport_refresh_csv_preview',
                    nonce: wpezimport_localized_data.upload_nonce,
                    delimiter: delimiter,
                    enclosure: enclosure,
                    draft_id: getDraftId()
                },
                success: function(response) {
                    if (response.success && response.data.preview) {
                        previewContent.html(response.data.preview);
                        // Re-initialize the refresh button for the new content
                        initCsvRefreshButton();
                    } else {
                        alert(response.data.message || 'Error refreshing preview.');
                        refreshButton.prop('disabled', false).text(wpezimport_localized_data.strings.refresh_preview || 'Refresh Preview');
                    }
                },
                error: function() {
                    alert('An error occurred while refreshing the preview.');
                    refreshButton.prop('disabled', false).text(wpezimport_localized_data.strings.refresh_preview || 'Refresh Preview');
                }
            });
        });
    }

    // Handle file re-upload on mapping page (editing saved file import)
    $(document).on('click', '#wpezimport-reupload-btn', function() {
        var fileInput = document.getElementById('wpezimport-reupload-file');
        if (!fileInput || !fileInput.files.length) {
            $('#wpezimport-reupload-status').html('<p class="wpezimport-error">Please select a file first.</p>');
            return;
        }

        var file = fileInput.files[0];
        var ext = file.name.split('.').pop().toLowerCase();
        if (['csv', 'xls', 'xlsx'].indexOf(ext) === -1) {
            $('#wpezimport-reupload-status').html('<p class="wpezimport-error">Invalid file type. Please upload a CSV, XLS, or XLSX file.</p>');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'wpezimport_reupload_file');
        formData.append('nonce', wpezimport_localized_data.upload_nonce);
        formData.append('draft_id', getDraftId());
        formData.append('wpezimport_reupload_file', file);

        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#wpezimport-reupload-loader').show();
        $('#wpezimport-reupload-status').html('');

        $.ajax({
            url: wpezimport_localized_data.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    // Replace the entire re-upload section with the draggable columns
                    var $list = $('.wpezimport-columns-list');
                    $list.html(response.data.columns_html);
                    // Reinitialize CSV drag-drop
                    initCsvColumnDragDrop();
                } else {
                    $('#wpezimport-reupload-status').html('<p class="wpezimport-error">' + (response.data && response.data.message ? response.data.message : 'Upload failed.') + '</p>');
                    $btn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('#wpezimport-reupload-status').html('<p class="wpezimport-error">AJAX error: ' + error + '</p>');
                $btn.prop('disabled', false);
            },
            complete: function() {
                $('#wpezimport-reupload-loader').hide();
            }
        });
    });

    // Initialize CSV column drag and drop
    function initCsvColumnDragDrop() {
        console.log('Initializing CSV column drag and drop');

        const columnItems = document.querySelectorAll('.wpezimport-column-item');
        console.log('Found ' + columnItems.length + ' CSV column items');

        columnItems.forEach(item => {
            item.addEventListener('dragstart', function(e) {
                const columnValue = this.getAttribute('data-column');
                if (columnValue) {
                    e.dataTransfer.setData('text/plain', columnValue);
                    e.dataTransfer.effectAllowed = 'copy';
                }
                this.classList.add('dragging');
            });

            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
            });
        });

        // Add drop targets for input fields
        const inputFields = document.querySelectorAll('.wpezimport-field-input-container input[type="text"]');
        inputFields.forEach(field => {
            field.addEventListener('dragover', handleDragOver);
            field.addEventListener('drop', unifiedDropHandler);
            field.addEventListener('dragenter', function() {
                this.classList.add('highlight');
            });
            field.addEventListener('dragleave', function() {
                this.classList.remove('highlight');
            });
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        // initTabs(); // Removed - tabs replaced with separate forms
        initFileUpload();
        initUrlImport();
        initMappingForm();
        initXmlStructureDisplay();

        // Initialize XML structure drag and drop if on mapping page
        if (document.getElementById('wpezimport-xml-structure')) {
            console.log('XML structure found, initializing drag and drop');
            // Add a slight delay to ensure the DOM is fully loaded
            setTimeout(function() {
                initXmlStructureDragDrop();
                initXmlSampleNavigation();
                initRefetchXml();
            }, 500);
        }

        // Initialize CSV column drag and drop if on mapping page (non-XML)
        if (document.querySelector('.wpezimport-columns-list')) {
            console.log('CSV columns found, initializing drag and drop');
            setTimeout(function() {
                initCsvColumnDragDrop();
            }, 300);
        }

        // On page load, if the XML structure container is present, trigger an AJAX call to load the first XML sample (direction: 'init')
        if (document.getElementById('wpezimport-xml-structure')) {
            // Call AJAX to load the first sample (index 0)
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpezimport_navigate_xml_sample',
                    direction: 'init', // special direction for initial load
                    wpezimport_mapping_nonce: $('#wpezimport_mapping_nonce').val(),
                    draft_id: getDraftId()
                },
                success: function(response) {
                    if (response.success) {
                        document.getElementById('wpezimport-xml-structure').innerHTML = response.data.formatted_xml;
                        document.getElementById('wpezimport-xml-structure').style.display = 'block';
                        // Re-initialize drag and drop
                        initXmlStructureDragDrop();
                        // Update counter (replace HTML, not just textContent)
                        const counter = document.querySelector('.wpezimport-product-counter');
                        if (counter) {
                            counter.outerHTML = response.data.sample_counter_html;
                        }

                        // Update navigation buttons
                        if (typeof updateXmlSampleNavButtons === 'function') {
                            updateXmlSampleNavButtons(response.data);
                        }
                    } else {
                        document.getElementById('wpezimport-xml-structure').innerHTML = '<div class="wpezimport-error">' + (response.data && response.data.message ? response.data.message : 'Error loading XML sample.') + '</div>';
                    }
                },
                error: function(xhr, status, error) {
                    document.getElementById('wpezimport-xml-structure').innerHTML = '<div class="wpezimport-error">AJAX error: ' + status + ' - ' + error + '</div>';
                }
            });
        }

        // NOTE: Disable duplicate form handler to prevent double form submission
        // We already handle form submission in initMappingForm()
        // if ($('#wpezimport-mapping-form').length) {
        //     initMappingFormSave();
        // }
    });

})(jQuery);
