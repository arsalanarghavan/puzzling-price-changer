jQuery(document).ready(function($) {

    // --- UTILITY FUNCTIONS ---
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function unformatNumber(str) {
        return str.toString().replace(/[^0-9]/g, '');
    }

    // --- CORE FUNCTION to apply filters and fetch data ---
    function applyFilters(page = 1) {
        var tableBody = $('#psp-price-list-body');
        var paginationContainer = $('#psp-pagination-container');
        
        tableBody.html('<tr><td colspan="4"><span class="spinner is-active" style="float:right; margin: 10px;"></span></td></tr>');

        var filterData = {
            nonce: psp_ajax_object.filter_nonce,
            category: $('#psp_category_filter').val(),
            brand: $('#psp_brand_filter').val(),
            search: $('#psp_search_filter').val(),
            page: page
        };

        // Fetch Products
        $.post(psp_ajax_object.ajax_url, { action: 'psp_get_filtered_products', ...filterData })
            .done(function(response) {
                if (response.success) {
                    tableBody.html(response.data.html);
                } else {
                    tableBody.html('<tr><td colspan="4">خطا در بارگذاری محصولات.</td></tr>');
                }
            })
            .fail(function() {
                tableBody.html('<tr><td colspan="4">خطای ارتباط با سرور.</td></tr>');
            });

        // Fetch Pagination
        paginationContainer.html('<span class="spinner is-active"></span>');
        $.post(psp_ajax_object.ajax_url, { action: 'psp_get_pagination', ...filterData })
            .done(function(response) {
                if (response.success) {
                    paginationContainer.html(response.data.html);
                } else {
                    paginationContainer.html('');
                }
            });
    }

    // --- EVENT HANDLERS ---

    // 1. Filter and Search triggers
    $('#psp_category_filter, #psp_brand_filter').on('change', function() {
        applyFilters(1);
    });

    $('#psp_search_button').on('click', function() {
        applyFilters(1);
    });

    $('#psp_search_filter').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            applyFilters(1);
        }
    });

    // 2. Pagination click handler (delegated)
    $('#psp-pagination-container').on('click', 'a.page-numbers', function(e) {
        e.preventDefault();
        var pageUrl = $(this).attr('href');
        var pageNum = 1;
        
        var pagedMatch = pageUrl.match(/paged=(\d+)/);
        if (pagedMatch && pagedMatch[1]) {
            pageNum = parseInt(pagedMatch[1]);
        } else {
            // Fallback for prev/next if no number in URL
            var current = parseInt($('.page-numbers.current').text()) || 1;
            if ($(this).hasClass('next')) {
                pageNum = current + 1;
            } else if ($(this).hasClass('prev')) {
                pageNum = Math.max(1, current - 1);
            } else {
                pageNum = parseInt($(this).text()) || 1;
            }
        }
        applyFilters(pageNum);
    });

    // 3. Price input formatting (delegated)
    $('#psp-price-list-body').on('input', '.variation-price-input', function() {
        var cursorPosition = this.selectionStart;
        var originalLength = this.value.length;
        var unformattedValue = unformatNumber(this.value);

        if (unformattedValue.length > 0) {
            this.value = formatNumber(unformattedValue);
            var newLength = this.value.length;
            cursorPosition += (newLength - originalLength);
            this.setSelectionRange(cursorPosition, cursorPosition);
        } else {
            this.value = '';
        }
    });

    // 4. Price update on change/blur (delegated)
    $('#psp-price-list-body').on('change', '.variation-price-input', function() {
        var inputField = $(this);
        var saveStatus = inputField.closest('.price-wrapper').find('.save-status');
        
        saveStatus.removeClass('success error').addClass('saving');

        var priceData = {
            action: 'update_variation_price',
            nonce: psp_ajax_object.update_nonce,
            variation_id: inputField.data('variation-id'),
            price: unformatNumber(inputField.val()),
            price_type: inputField.data('price-type')
        };

        $.post(psp_ajax_object.ajax_url, priceData)
            .done(function(response) {
                saveStatus.removeClass('saving');
                if (response.success) {
                    saveStatus.addClass('success');
                } else {
                    saveStatus.addClass('error');
                    alert('خطا: ' + (response.data ? response.data.message : 'ناشناخته'));
                }
            })
            .fail(function() {
                saveStatus.removeClass('saving').addClass('error');
                alert('خطای ارتباط با سرور.');
            })
            .always(function() {
                setTimeout(() => saveStatus.removeClass('success error'), 2000);
            });
    });

});
// CORRECTED: The extra '}' at the end of the file was removed.