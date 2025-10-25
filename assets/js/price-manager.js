jQuery(document).ready(function($) {

    // --- DOM Elements ---
    const tableBody = $('#psp-price-list-body');
    const paginationContainer = $('#psp-pagination-container');
    const categoryFilter = $('#psp_category_filter');
    const brandFilter = $('#psp_brand_filter');
    const stockFilter = $('#psp_stock_filter');
    const sortFilter = $('#psp_sort_filter');
    const searchInput = $('#psp_search_filter');
    const searchButton = $('#psp_search_button');
    
    // Debug: Check if filters exist
    console.log('Filters found:', {
        category: categoryFilter.length,
        brand: brandFilter.length,
        stock: stockFilter.length,
        sort: sortFilter.length
    });

    // --- Utility Functions ---
    const formatNumber = (num) => num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    const unformatNumber = (str) => str.toString().replace(/[^0-9]/g, '');
    
    let debounceTimer;
    const debounce = (callback, time) => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(callback, time);
    };

    // --- Core Functions ---
    function fetchProducts(page = 1) {
        console.log('=== fetchProducts called with page:', page, '===');
        
        tableBody.html('<tr><td colspan="5" style="text-align:center; padding: 40px 0;"><span class="spinner is-active"></span></td></tr>');
        paginationContainer.empty();

        const filterData = {
            action: 'psp_get_products',
            _ajax_nonce: psp_ajax_object.filter_nonce,
            page: page,
            category: categoryFilter.val(),
            brand: brandFilter.val(),
            stock_status: stockFilter.val(),
            sort: sortFilter.val(),
            search: searchInput.val().trim()
        };
        
        // Debug: log filter data
        console.log('Filter Data:', filterData);
        console.log('AJAX URL:', psp_ajax_object.ajax_url);

        $.post(psp_ajax_object.ajax_url, filterData)
            .done(function(response) {
                console.log('=== AJAX Response ===');
                console.log('Response:', response);
                console.log('Success:', response.success);
                console.log('Data:', response.data);
                
                if (response.success) {
                    tableBody.html(response.data.products_html);
                    paginationContainer.html(response.data.pagination_html);
                    console.log('Products loaded, pagination updated');
                    console.log('Current page:', response.data.current_page);
                    console.log('Total pages:', response.data.total_pages);
                } else {
                    console.error('AJAX Error:', response);
                    tableBody.html('<tr><td colspan="5">خطا در بارگذاری محصولات: ' + (response.data || 'نامشخص') + '</td></tr>');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('=== AJAX Failed ===');
                console.error('XHR:', xhr);
                console.error('Status:', status);
                console.error('Error:', error);
                console.error('Response Text:', xhr.responseText);
                tableBody.html('<tr><td colspan="5">خطای ارتباط با سرور. لطفاً صفحه را رفرش کنید.</td></tr>');
            });
    }

    // --- Event Handlers ---
    fetchProducts(1); // Initial Load

    categoryFilter.on('change', () => fetchProducts(1));
    brandFilter.on('change', () => fetchProducts(1));
    stockFilter.on('change', () => fetchProducts(1));
    sortFilter.on('change', () => fetchProducts(1));
    searchButton.on('click', () => fetchProducts(1));
    
    searchInput.on('keyup', () => {
        debounce(() => fetchProducts(1), 500); // جستجوی با تأخیر
    });
    searchInput.on('keypress', (e) => {
        if (e.which === 13) {
            e.preventDefault(); // جلوگیری از ارسال فرم
            fetchProducts(1);
        }
    });

    // Simple pagination click handler
    $(document).on('click', '.page-numbers', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $this = $(this);
        const pageNum = $this.data('page');
        
        console.log('Pagination clicked, page:', pageNum);
        
        if (pageNum && pageNum > 0) {
            fetchProducts(pageNum);
        } else {
            console.error('Invalid page number:', pageNum);
        }
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
            _ajax_nonce: psp_ajax_object.update_nonce,
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
                    // Optionally show a more subtle error message instead of an alert
                }
            })
            .fail(function() {
                saveStatus.removeClass('saving').addClass('error');
            })
            .always(function() {
                setTimeout(() => saveStatus.removeClass('success error'), 2500);
            });
    });

    // Stock status update handler
    tableBody.on('change', '.stock-status-select', function() {
        const selectField = $(this);
        const saveStatus = selectField.closest('.stock-wrapper').find('.save-status');
        
        saveStatus.removeClass('success error').addClass('saving');

        const stockData = {
            action: 'psp_update_stock_status',
            _ajax_nonce: psp_ajax_object.update_nonce,
            id: selectField.data('id'),
            stock_status: selectField.val()
        };

        $.post(psp_ajax_object.ajax_url, stockData)
            .done(function(response) {
                saveStatus.removeClass('saving');
                if (response.success) {
                    saveStatus.addClass('success');
                } else {
                    saveStatus.addClass('error');
                }
            })
            .fail(function() {
                saveStatus.removeClass('saving').addClass('error');
            })
            .always(function() {
                setTimeout(() => saveStatus.removeClass('success error'), 2500);
            });
    });
});