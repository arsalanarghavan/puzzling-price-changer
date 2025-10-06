jQuery(document).ready(function($) {

    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function unformatNumber(str) {
        return str.toString().replace(/[^0-9]/g, '');
    }

    function updatePrice(inputField) {
        var variationId = inputField.data('variation-id');
        var newPrice = unformatNumber(inputField.val());
        var priceType = inputField.hasClass('variation-sale-price-input') ? 'sale' : 'regular';
        var saveStatus = inputField.closest('.price-wrapper').find('.save-status');

        saveStatus.removeClass('success error').addClass('saving');

        $.post(psp_ajax_object.ajax_url, {
            action: 'update_variation_price',
            nonce: psp_ajax_object.update_nonce,
            variation_id: variationId,
            price: newPrice,
            price_type: priceType
        }, function(response) {
            saveStatus.removeClass('saving');
            if (response.success) {
                saveStatus.addClass('success');
            } else {
                saveStatus.addClass('error');
                alert('خطا: ' + (response.data.message || 'ناشناخته'));
            }
            setTimeout(() => saveStatus.removeClass('success error'), 2000);
        }).fail(function() {
            saveStatus.removeClass('saving').addClass('error');
            alert('خطای ارتباط با سرور.');
            setTimeout(() => saveStatus.removeClass('error'), 2000);
        });
    }

    function rebindPriceInputEvents() {
        $('#psp-price-list-body').off('input', '.variation-price-input, .variation-sale-price-input');
        $('#psp-price-list-body').off('change', '.variation-price-input, .variation-sale-price-input');

        $('#psp-price-list-body').on('input', '.variation-price-input, .variation-sale-price-input', function() {
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

        $('#psp-price-list-body').on('change', '.variation-price-input, .variation-sale-price-input', function() {
            updatePrice($(this));
        });
    }

    function applyFilters(page = 1) {
        var category = $('#psp_category_filter').val();
        var brand = $('#psp_brand_filter').val();
        var search = $('#psp_search_filter').val();
        var tableBody = $('#psp-price-list-body');
        var paginationContainer = $('#psp-pagination-container');

        tableBody.html('<tr><td colspan="4"><span class="spinner is-active" style="float:right; margin: 10px;"></span></td></tr>');
        paginationContainer.empty();

        $.post(psp_ajax_object.ajax_url, {
            action: 'psp_get_filtered_products',
            nonce: psp_ajax_object.filter_nonce,
            category: category,
            brand: brand,
            search: search,
            page: page
        }, function(response) {
            var parts = response.split('');
            tableBody.html(parts[0]);
            if (parts.length > 1) {
                paginationContainer.html(parts[1]);
            }
        });
    }

    $('#psp_category_filter, #psp_brand_filter').on('change', () => applyFilters(1));
    $('#psp_search_button').on('click', () => applyFilters(1));
    $('#psp_search_filter').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            applyFilters(1);
        }
    });

    $('#psp-pagination-container').on('click', 'a.page-numbers', function(e) {
        e.preventDefault();
        var pageUrl = $(this).attr('href');
        var pageNum = 1;
        if (pageUrl) {
            var match = pageUrl.match(/paged=(\d+)/);
            if (match) {
                 pageNum = parseInt(match[1]);
            } else if ($(this).hasClass('next')) {
                pageNum = parseInt($('.page-numbers.current').text()) + 1;
            } else if ($(this).hasClass('prev')) {
                 pageNum = parseInt($('.page-numbers.current').text()) - 1;
            } else {
                 pageNum = parseInt($(this).text());
            }
        }
        applyFilters(pageNum);
    });

    rebindPriceInputEvents();
});