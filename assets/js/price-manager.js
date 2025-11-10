jQuery(document).ready(function($) {

    // --- DOM Elements ---
    const tableBody = $('#psp-price-list-body');
    const mobileBody = $('#psp-mobile-list-body');
    const paginationContainer = $('#psp-pagination-container');
    const categoryFilter = $('#psp_category_filter');
    const brandFilter = $('#psp_brand_filter');
    const stockFilter = $('#psp_stock_filter');
    const sortFilter = $('#psp_sort_filter');
    const searchInput = $('#psp_search_filter');
    const searchButton = $('#psp_search_button');

    // --- Utility Functions ---
    const formatNumber = (num) => num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    const unformatNumber = (str) => str.toString().replace(/[^0-9]/g, '');
    
    let debounceTimer;
    const debounce = (callback, time) => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(callback, time);
    };
    
    const setBrandOriginalValue = ($select, value) => {
        const safeValue = value || '';
        $select.attr('data-original-value', safeValue);
        $select.data('original-value', safeValue);
    };

    const getBrandOriginalValue = ($select) => {
        const attrValue = $select.attr('data-original-value');
        if (typeof attrValue !== 'undefined') {
            return attrValue;
        }
        const dataValue = $select.data('original-value');
        return typeof dataValue === 'undefined' ? '' : dataValue;
    };

    function initializeBrandSelects(root) {
        const $root = root && root.jquery ? root : $(root || document);
        $root.find('.brand-select').each(function() {
            const $select = $(this);
            setBrandOriginalValue($select, $select.val() || '');
        });
    }
    
    // Reattach event handlers after mobile conversion
    function attachMobileEventHandlers() {
        // Event handlers are already delegated via $(document).on()
        // Just ensure original values are stored
        $('.psp-mobile-field-content .variation-price-input').each(function() {
            const $input = $(this);
            if (!$input.data('original-value')) {
                $input.data('original-value', unformatNumber($input.val()));
            }
        });
        
        $('.psp-mobile-field-content .stock-status-select').each(function() {
            const $select = $(this);
            if (!$select.data('original-value')) {
                $select.data('original-value', $select.val());
            }
        });

        $('.psp-mobile-field-content .brand-select').each(function() {
            const $select = $(this);
            if (typeof $select.attr('data-original-value') === 'undefined') {
                setBrandOriginalValue($select, $select.val() || '');
            }
        });
    }
    
    // Convert table to mobile cards
    function convertToMobile(force = false) {
        const width = $(window).width();
        const isDesktop = width > 768;
        
        // Store current view state
        if (typeof convertToMobile.lastView === 'undefined') {
            convertToMobile.lastView = isDesktop ? 'desktop' : 'mobile';
        }
        
        if (isDesktop) {
            // Desktop view
            mobileBody.hide();
            tableBody.show();
            convertToMobile.lastView = 'desktop';
            return;
        }
        
        // Mobile view - check if we need to rebuild
        if (!force && convertToMobile.lastView === 'mobile' && mobileBody.find('.psp-mobile-card').length > 0) {
            // Already in mobile view, just show it
            mobileBody.show();
            tableBody.hide();
            return;
        }
        
        mobileBody.show();
        tableBody.hide();
        mobileBody.empty(); // Clear first
        convertToMobile.lastView = 'mobile';
        
        tableBody.find('tr').each(function() {
            const $row = $(this);
            const $cells = $row.find('td');
            
            if ($cells.length < 7) return; // Skip empty/error rows
            
            // Clone wrapper divs from table (price-wrapper and stock-wrapper) to keep original in DOM
            const $brandWrapper = $cells.eq(3).find('.brand-wrapper').clone(true, true);
            const $regularPriceWrapper = $cells.eq(4).find('.price-wrapper').clone(true, true);
            const $salePriceWrapper = $cells.eq(5).find('.price-wrapper').clone(true, true);
            const $stockWrapper = $cells.eq(6).find('.stock-wrapper').clone(true, true);

            if ($brandWrapper.length === 0) {
                return;
            }
            
            // Get image
            const $image = $cells.eq(0).find('img');
            
            // Get attributes
            const $attrs = $cells.eq(2).clone(true);
            $attrs.find('.attribute-tag').removeClass('attribute-tag').addClass('psp-mobile-tag');
            
            // Add mobile classes to cloned wrappers and elements
            $brandWrapper.addClass('psp-mobile-wrapper');
            $regularPriceWrapper.addClass('psp-mobile-wrapper');
            $salePriceWrapper.addClass('psp-mobile-wrapper');
            $stockWrapper.addClass('psp-mobile-wrapper');
            
            $brandWrapper.find('.brand-select').addClass('psp-mobile-select');
            $regularPriceWrapper.find('.variation-price-input').addClass('psp-mobile-input');
            $salePriceWrapper.find('.variation-price-input').addClass('psp-mobile-input');
            $stockWrapper.find('.stock-status-select').addClass('psp-mobile-select');
            $brandWrapper.find('.save-status').addClass('psp-mobile-status');
            $regularPriceWrapper.find('.save-status').addClass('psp-mobile-status');
            $salePriceWrapper.find('.save-status').addClass('psp-mobile-status');
            $stockWrapper.find('.save-status').addClass('psp-mobile-status');
            
            // Build mobile card
            const $card = $('<div class="psp-mobile-card"></div>');
            const $header = $('<div class="psp-mobile-card-header"></div>').append($image.clone());
            const $body = $('<div class="psp-mobile-card-body"></div>');
            
            // Product name
            const $nameField = $('<div class="psp-mobile-field"></div>')
                .append('<div class="psp-mobile-label">نام محصول</div>')
                .append('<div>' + $cells.eq(1).text().trim() + '</div>');
            
            // Attributes
            const $attrsField = $('<div class="psp-mobile-field"></div>')
                .append('<div class="psp-mobile-label">ویژگی‌ها</div>')
                .append('<div class="psp-mobile-tags">' + $attrs.html() + '</div>');

            // Brand - use cloned wrapper
            const $brandField = $('<div class="psp-mobile-field"></div>')
                .append('<div class="psp-mobile-label">برند</div>')
                .append($('<div class="psp-mobile-field-content"></div>').append($brandWrapper));
            
            // Regular price - use cloned wrapper
            const $regularField = $('<div class="psp-mobile-field"></div>')
                .append('<div class="psp-mobile-label">قیمت (تومان)</div>')
                .append($('<div class="psp-mobile-field-content"></div>').append($regularPriceWrapper));
            
            // Sale price - use cloned wrapper
            const $saleField = $('<div class="psp-mobile-field"></div>')
                .append('<div class="psp-mobile-label">قیمت با تخفیف (تومان)</div>')
                .append($('<div class="psp-mobile-field-content"></div>').append($salePriceWrapper));
            
            // Stock - use cloned wrapper
            const $stockField = $('<div class="psp-mobile-field"></div>')
                .append('<div class="psp-mobile-label">موجودی</div>')
                .append($('<div class="psp-mobile-field-content"></div>').append($stockWrapper));
            
            $body.append($nameField)
                .append($attrsField)
                .append($brandField)
                .append($regularField)
                .append($saleField)
                .append($stockField);
            $card.append($header).append($body);
            mobileBody.append($card);
        });
        
        attachMobileEventHandlers();
        initializeBrandSelects(mobileBody);
    }
    
    // --- Core Functions ---
    function fetchProducts(page = 1) {
        tableBody.html('<tr><td colspan="7" style="text-align:center; padding: 40px 0;"><span class="spinner is-active"></span></td></tr>');
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

        $.post(psp_ajax_object.ajax_url, filterData)
            .done(function(response) {
                if (response.success) {
                    tableBody.html(response.data.products_html);
                    paginationContainer.html(response.data.pagination_html);
                    initializeBrandSelects(tableBody);
                    convertToMobile(true); // Force convert to mobile view when loading new data
                    attachMobileEventHandlers(); // Reattach handlers after loading
                    initializeBrandSelects(mobileBody);
                } else {
                    tableBody.html('<tr><td colspan="7">خطا در بارگذاری محصولات: ' + (response.data || 'نامشخص') + '</td></tr>');
                }
            })
            .fail(function(xhr, status, error) {
                tableBody.html('<tr><td colspan="7">خطای ارتباط با سرور. لطفاً صفحه را رفرش کنید.</td></tr>');
            });
    }

    // --- Event Handlers ---
    fetchProducts(1); // Initial Load

    categoryFilter.on('change', () => fetchProducts(1));
    brandFilter.on('change', () => fetchProducts(1));
    stockFilter.on('change', () => fetchProducts(1));
    sortFilter.on('change', () => fetchProducts(1));
    searchButton.on('click', () => fetchProducts(1));
    
    // Convert on resize with debounce
    let resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(convertToMobile, 250);
    });
    
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
        
        if (pageNum && pageNum > 0) {
            fetchProducts(pageNum);
        }
    });

    // Price input formatting - delegate to both table and mobile
    $(document).on('input', '.variation-price-input', function() {
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

    // Price change handler - delegate to both table and mobile
    $(document).on('blur', '.variation-price-input', function(e) {
        const inputField = $(this);
        const saveStatus = inputField.closest('.price-wrapper').find('.save-status');
        
        // Don't save if value hasn't changed
        const currentValue = unformatNumber(inputField.val());
        const originalValue = inputField.data('original-value') || '';
        
        if (currentValue === originalValue) {
            return;
        }
        
        saveStatus.removeClass('success error').addClass('saving');

        const priceData = {
            action: 'psp_update_price',
            _ajax_nonce: psp_ajax_object.update_nonce,
            id: inputField.data('id'),
            price: currentValue,
            price_type: inputField.data('price-type')
        };

        $.post(psp_ajax_object.ajax_url, priceData)
            .done(function(response) {
                saveStatus.removeClass('saving');
                if (response.success) {
                    saveStatus.addClass('success');
                    inputField.data('original-value', currentValue);
                } else {
                    saveStatus.addClass('error');
                }
            })
            .fail(function(xhr, status, error) {
                saveStatus.removeClass('saving').addClass('error');
            })
            .always(function() {
                setTimeout(() => saveStatus.removeClass('success error'), 2500);
            });
    });
    
    // Store original value on focus for mobile
    $(document).on('focus', '.variation-price-input', function() {
        const inputField = $(this);
        if (!inputField.data('original-value')) {
            inputField.data('original-value', unformatNumber(inputField.val()));
        }
    });

    // Brand update handler - delegate to both table and mobile
    $(document).on('change', '.brand-select', function() {
        const selectField = $(this);
        const productId = parseInt(selectField.data('product-id'), 10);

        if (!productId) {
            return;
        }

        const saveStatus = selectField.closest('.brand-wrapper').find('.save-status');
        const currentValue = selectField.val() || '';
        const originalValue = getBrandOriginalValue(selectField);

        if (currentValue === originalValue) {
            return;
        }

        saveStatus.removeClass('success error').addClass('saving');

        const brandData = {
            action: 'psp_update_brand',
            _ajax_nonce: psp_ajax_object.update_nonce,
            id: productId,
            brand_slug: currentValue
        };

        $.post(psp_ajax_object.ajax_url, brandData)
            .done(function(response) {
                saveStatus.removeClass('saving');
                if (response.success) {
                    saveStatus.addClass('success');
                    const $relatedSelects = $('.brand-select[data-product-id="' + productId + '"]');
                    $relatedSelects.each(function() {
                        const $relatedSelect = $(this);
                        $relatedSelect.val(currentValue);
                        setBrandOriginalValue($relatedSelect, currentValue);
                    });
                } else {
                    saveStatus.addClass('error');
                    selectField.val(originalValue);
                }
            })
            .fail(function() {
                saveStatus.removeClass('saving').addClass('error');
                selectField.val(originalValue);
            })
            .always(function() {
                setTimeout(function() {
                    saveStatus.removeClass('success error');
                }, 2500);
            });
    });

    // Store original value on focus for brand select
    $(document).on('focus', '.brand-select', function() {
        const $select = $(this);
        if (typeof $select.attr('data-original-value') === 'undefined') {
            setBrandOriginalValue($select, $select.val() || '');
        }
    });

    // Stock status update handler - delegate to both table and mobile
    $(document).on('change', '.stock-status-select', function() {
        const selectField = $(this);
        const saveStatus = selectField.closest('.stock-wrapper').find('.save-status');
        
        // Don't save if value hasn't changed
        const currentValue = selectField.val();
        const originalValue = selectField.data('original-value') || '';
        
        if (currentValue === originalValue) {
            return;
        }
        
        saveStatus.removeClass('success error').addClass('saving');

        const stockData = {
            action: 'psp_update_stock_status',
            _ajax_nonce: psp_ajax_object.update_nonce,
            id: selectField.data('id'),
            stock_status: currentValue
        };

        $.post(psp_ajax_object.ajax_url, stockData)
            .done(function(response) {
                saveStatus.removeClass('saving');
                if (response.success) {
                    saveStatus.addClass('success');
                    selectField.data('original-value', currentValue);
                } else {
                    saveStatus.addClass('error');
                }
            })
            .fail(function(xhr, status, error) {
                saveStatus.removeClass('saving').addClass('error');
            })
            .always(function() {
                setTimeout(() => saveStatus.removeClass('success error'), 2500);
            });
    });
    
    // Store original value on focus for stock select
    $(document).on('focus', '.stock-status-select', function() {
        const selectField = $(this);
        if (!selectField.data('original-value')) {
            selectField.data('original-value', selectField.val());
        }
    });
});