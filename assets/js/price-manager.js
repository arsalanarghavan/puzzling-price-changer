jQuery(document).ready(function($) {

    const tableBody = $('#psp-price-list-body');
    const paginationContainer = $('#psp-pagination-container');
    const categoryFilter = $('#psp_category_filter');
    const brandFilter = $('#psp_brand_filter');
    const searchInput = $('#psp_search_filter');
    const searchButton = $('#psp_search_button');

    const formatNumber = (num) => num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    const unformatNumber = (str) => str.toString().replace(/[^0-9]/g, '');

    function fetchProducts(page = 1) {
        tableBody.html('<tr><td colspan="4"><span class="spinner is-active" style="float:right; margin: 10px;"></span></td></tr>');
        paginationContainer.html('');

        const filterData = {
            action: 'psp_get_products',
            _ajax_nonce: psp_ajax_object.filter_nonce, // Corrected Nonce field name
            page: page,
            category: categoryFilter.val(),
            brand: brandFilter.val(),
            search: searchInput.val()
        };

        $.post(psp_ajax_object.ajax_url, filterData)
            .done(function(response) {
                if (response.success) {
                    tableBody.html(response.data.products_html);
                    paginationContainer.html(response.data.pagination_html);
                } else {
                    tableBody.html('<tr><td colspan="4">خطا در بارگذاری محصولات.</td></tr>');
                }
            })
            .fail(function() {
                tableBody.html('<tr><td colspan="4">خطای ارتباط با سرور. لطفاً صفحه را رفرش کنید.</td></tr>');
            });
    }

    // --- Event Handlers ---
    fetchProducts(1); // Initial Load

    categoryFilter.on('change', () => fetchProducts(1));
    brandFilter.on('change', () => fetchProducts(1));
    searchButton.on('click', () => fetchProducts(1));
    searchInput.on('keypress', (e) => {
        if (e.which === 13) fetchProducts(1);
    });

    paginationContainer.on('click', 'a.page-numbers', function(e) {
        e.preventDefault();
        const href = $(this).attr('href');
        const pageNum = new URLSearchParams(href.slice(href.indexOf('?'))).get('paged') || 1;
        fetchProducts(pageNum);
    });

    tableBody.on('input', '.variation-price-input', function() {
        const cursorPosition = this.selectionStart;
        const originalLength = this.value.length;
        const unformattedValue = unformatNumber(this.value);

        if (unformattedValue) {
            this.value = formatNumber(unformattedValue);
            const newLength = this.value.length;
            this.setSelectionRange(cursorPosition + (newLength - originalLength), cursorPosition + (newLength - originalLength));
        } else {
            this.value = '';
        }
    });

    tableBody.on('change', '.variation-price-input', function() {
        const inputField = $(this);
        const saveStatus = inputField.closest('.price-wrapper').find('.save-status');
        
        saveStatus.removeClass('success error').addClass('saving');

        const priceData = {
            action: 'psp_update_price',
            _ajax_nonce: psp_ajax_object.update_nonce, // Corrected Nonce field name
            id: inputField.data('id'),
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
                setTimeout(() => saveStatus.removeClass('success error'), 3000);
            });
    });
});