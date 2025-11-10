<?php
/**
 * Plugin Name:       تغییر قیمت‌ها
 * Description:       صفحه مدیریت قیمت‌های محصولات ووکامرس با امکان تغییر موجودی
 * Version:           2.4.0
 * Author:            Arsalan Arghavan
 * Text Domain:       puzzling-price-changer
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      6.4
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>تغییر قیمت‌ها</strong> نیاز به ووکامرس دارد. لطفاً ابتدا ووکامرس را نصب و فعال کنید.</p></div>';
    });
    return;
}

// Include WordPress functions
if (!function_exists('add_action')) {
    require_once(ABSPATH . 'wp-includes/pluggable.php');
}

// Plugin initialization
add_action('init', function() {
    // Load plugin text domain for translations
    load_plugin_textdomain('puzzling-price-changer', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Plugin activation hook
register_activation_hook(__FILE__, function() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('این افزونه نیاز به ووکامرس دارد. لطفاً ابتدا ووکامرس را نصب و فعال کنید.', 'puzzling-price-changer'));
    }
});

// 1. Add Admin Menu
function psp_add_admin_menu() {
    add_menu_page('تغییر قیمت پازلینگ', 'تغییر قیمت پازلینگ', 'manage_woocommerce', 'puzzling_price_manager', 'psp_render_admin_page', 'dashicons-admin-generic', 55);
    add_submenu_page('puzzling_price_manager', 'تغییر قیمت', 'تغییر قیمت', 'manage_woocommerce', 'puzzling_price_manager');
    add_submenu_page('puzzling_price_manager', 'تغییر قیمت گروهی', 'تغییر قیمت گروهی', 'manage_woocommerce', 'puzzling_price_bulk', 'psp_render_bulk_page');
}
add_action('admin_menu', 'psp_add_admin_menu');

/**
 * Retrieve the primary brand slug for a product.
 *
 * @param int $product_id
 * @return string
 */
function psp_get_product_brand_slug($product_id) {
    $terms = wp_get_post_terms($product_id, 'product_brand');

    if (is_wp_error($terms) || empty($terms)) {
        return '';
    }

    // Use the first term as the primary brand.
    return $terms[0]->slug;
}

/**
 * Build the brand dropdown HTML for a product row.
 *
 * @param array  $brand_terms
 * @param string $selected_slug
 * @param int    $product_id
 * @return string
 */
function psp_build_brand_select($brand_terms, $selected_slug, $product_id) {
    $options_html = '<option value="">بدون برند</option>';

    if (!empty($brand_terms)) {
        foreach ($brand_terms as $brand_term) {
            $selected_attr = selected($selected_slug, $brand_term->slug, false);
            $options_html .= '<option value="' . esc_attr($brand_term->slug) . '"' . $selected_attr . '>' . esc_html($brand_term->name) . '</option>';
        }
    }

    $html  = '<div class="brand-wrapper">';
    $html .= '<select class="brand-select" data-product-id="' . esc_attr($product_id) . '" data-original-value="' . esc_attr($selected_slug) . '">';
    $html .= $options_html;
    $html .= '</select>';
    $html .= '<span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>';
    $html .= '</div>';

    return $html;
}

// 2. Render the Main Admin Page HTML Structure
function psp_render_admin_page() {
    ?>
    <div class="wrap psp-wrap">
        <h1><span class="dashicons dashicons-cart"></span>مدیریت قیمت محصولات</h1>
        <p>در این صفحه می‌توانید قیمت تمام محصولات و متغیرهای آن‌ها را به سرعت تغییر دهید. پس از تغییر قیمت، به صورت خودکار ذخیره می‌شود.</p>

        <div class="psp-filters-container">
            <?php
            // Dropdown for categories
            wp_dropdown_categories([
                'show_option_all' => 'همه دسته‌بندی‌ها',
                'taxonomy'        => 'product_cat',
                'name'            => 'psp_category_filter',
                'id'              => 'psp_category_filter',
                'class'           => 'psp-filter-select',
                'hierarchical'    => true,
                'value_field'     => 'slug'
            ]);

            // Dropdown for brands
            $brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]);
            echo '<select id="psp_brand_filter" name="psp_brand_filter" class="psp-filter-select"><option value="">همه برندها</option>';
            if (!is_wp_error($brands) && !empty($brands)) {
                foreach ($brands as $brand) {
                    echo '<option value="' . esc_attr($brand->slug) . '">' . esc_html($brand->name) . '</option>';
                }
            }
            echo '</select>';
            
            // Stock status filter
            echo '<select id="psp_stock_filter" name="psp_stock_filter" class="psp-filter-select">
                <option value="">همه وضعیت‌های موجودی</option>
                <option value="instock">فقط موجود</option>
                <option value="outofstock">فقط ناموجود</option>
            </select>';
            
            // Sort order filter
            echo '<select id="psp_sort_filter" name="psp_sort_filter" class="psp-filter-select">
                <option value="date_desc">جدیدترین</option>
                <option value="date_asc">قدیمی‌ترین</option>
                <option value="name_asc">الفبا (صعودی)</option>
                <option value="name_desc">الفبا (نزولی)</option>
                <option value="price_asc">قیمت (کم به زیاد)</option>
                <option value="price_desc">قیمت (زیاد به کم)</option>
            </select>';
            ?>
            <div class="psp-search-wrapper">
                <input type="text" id="psp_search_filter" name="psp_search_filter" class="psp-search-input" placeholder="جستجوی نام محصول...">
                <button id="psp_search_button" class="button button-primary">جستجو</button>
            </div>
        </div>

        <!-- Desktop Table View -->
        <div class="psp-table-container">
            <table class="wp-list-table widefat fixed striped psp-table">
                <thead>
                    <tr>
                        <th class="product-image-col">تصویر</th>
                        <th class="product-name-col">نام محصول</th>
                        <th class="attributes-col">ویژگی‌ها</th>
                        <th class="brand-col">برند</th>
                        <th class="price-col">قیمت (تومان)</th>
                        <th class="price-col">قیمت با تخفیف (تومان)</th>
                        <th class="stock-col">موجودی</th>
                    </tr>
                </thead>
                <tbody id="psp-price-list-body">
                    </tbody>
            </table>
        </div>
        
        <!-- Mobile Card View -->
        <div class="psp-mobile-view" id="psp-mobile-list-body" style="display:none;">
        </div>
        
        <div id="psp-pagination-container" class="psp-pagination-container"></div>
    </div>
    <?php
}

// 3. AJAX Handler for Getting Products using WP_Query (Safer Method)
function psp_ajax_get_products() {
    // Check nonce
    if (!check_ajax_referer('psp_filter_nonce', false, false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
        return;
    }

    $paged = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $posts_per_page = 50;
    
    // Debug log
    error_log('PSP AJAX: Page = ' . $paged . ', Search = ' . $search_term);

    $brand_terms = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]);
    if (is_wp_error($brand_terms)) {
        $brand_terms = [];
    }

    // Handle sort order
    $sort_option = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'date_desc';
    $orderby = 'date';
    $order = 'DESC';
    
    switch ($sort_option) {
        case 'date_asc':
            $orderby = 'date';
            $order = 'ASC';
            break;
        case 'name_asc':
            $orderby = 'title';
            $order = 'ASC';
            break;
        case 'name_desc':
            $orderby = 'title';
            $order = 'DESC';
            break;
        case 'price_asc':
            $orderby = 'meta_value_num';
            $order = 'ASC';
            break;
        case 'price_desc':
            $orderby = 'meta_value_num';
            $order = 'DESC';
            break;
        default: // date_desc
            $orderby = 'date';
            $order = 'DESC';
            break;
    }

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        's'              => $search_term,
        'orderby'        => $orderby,
        'order'          => $order,
    ];
    
    // Add meta_key for price sorting
    if (strpos($sort_option, 'price') !== false) {
        $args['meta_key'] = '_regular_price';
    }

    // Build tax_query only if we have taxonomy filters
    if (!empty($_POST['category']) || !empty($_POST['brand'])) {
        $args['tax_query'] = ['relation' => 'AND'];
        
        if (!empty($_POST['category'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($_POST['category']),
            ];
        }

        if (!empty($_POST['brand'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_brand',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($_POST['brand']),
            ];
        }
    }
    
    // Stock status filter - will be applied after query
    $stock_status_filter = !empty($_POST['stock_status']) ? sanitize_text_field($_POST['stock_status']) : '';

    $products_query = new WP_Query($args);

    ob_start();
    if ($products_query->have_posts()) {
        while ($products_query->have_posts()) {
            $products_query->the_post();
            $product = wc_get_product(get_the_ID());
            if (!$product) continue;

            if ($product->is_type('variable')) {
                $variations_data = $product->get_available_variations();
                $parent_brand_slug = psp_get_product_brand_slug($product->get_id());
                $brand_select_html = psp_build_brand_select($brand_terms, $parent_brand_slug, $product->get_id());
                foreach ($variations_data as $i => $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if (!$variation) continue;
                    $stock_status = $variation->get_stock_status();
                    
                    // Apply stock filter if set
                    if (!empty($stock_status_filter) && $stock_status !== $stock_status_filter) {
                        continue;
                    }
                    ?>
                    <tr class="<?php echo ($i === 0) ? 'product-start' : ''; ?>">
                        <td class="product-image-cell">
                            <?php echo $variation->get_image('thumbnail', ['class' => 'psp-product-thumbnail']); ?>
                        </td>
                        <td data-label="نام محصول"><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                        <td class="attributes-cell" data-label="ویژگی‌ها">
                            <?php foreach ($variation_data['attributes'] as $attr_key => $term_slug) {
                                $taxonomy = str_replace('attribute_', '', urldecode($attr_key));
                                $term = get_term_by('slug', $term_slug, $taxonomy);
                                echo '<span class="attribute-tag">' . wc_attribute_label($taxonomy) . ': <strong>' . ($term ? esc_html($term->name) : esc_html($term_slug)) . '</strong></span>';
                            } ?>
                        </td>
                        <td class="brand-col" data-label="برند">
                            <?php echo $brand_select_html; ?>
                        </td>
                        <td data-label="قیمت (تومان)">
                            <div class="price-wrapper">
                                <input type="text" class="variation-price-input" value="<?php echo esc_attr($variation->get_regular_price() ? number_format($variation->get_regular_price()) : ''); ?>" data-price-type="regular" data-id="<?php echo esc_attr($variation->get_id()); ?>">
                                <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                            </div>
                        </td>
                        <td data-label="قیمت با تخفیف (تومان)">
                            <div class="price-wrapper">
                                <input type="text" class="variation-price-input" value="<?php echo esc_attr($variation->get_sale_price() ? number_format($variation->get_sale_price()) : ''); ?>" data-price-type="sale" data-id="<?php echo esc_attr($variation->get_id()); ?>">
                                <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                            </div>
                        </td>
                        <td data-label="موجودی">
                            <div class="stock-wrapper">
                                <select class="stock-status-select" data-id="<?php echo esc_attr($variation->get_id()); ?>">
                                    <option value="instock" <?php selected($stock_status, 'instock'); ?>>موجود</option>
                                    <option value="outofstock" <?php selected($stock_status, 'outofstock'); ?>>ناموجود</option>
                                </select>
                                <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            } else { // For simple products and other types
                $stock_status = $product->get_stock_status();
                $product_brand_slug = psp_get_product_brand_slug($product->get_id());
                $brand_select_html = psp_build_brand_select($brand_terms, $product_brand_slug, $product->get_id());
                
                // Apply stock filter if set
                if (!empty($stock_status_filter) && $stock_status !== $stock_status_filter) {
                    continue;
                }
                ?>
                 <tr class="product-start">
                    <td class="product-image-cell">
                        <?php echo $product->get_image('thumbnail', ['class' => 'psp-product-thumbnail']); ?>
                    </td>
                    <td data-label="نام محصول"><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                    <td class="attributes-cell" data-label="ویژگی‌ها"><span class="attribute-tag">محصول ساده</span></td>
                    <td class="brand-col" data-label="برند">
                        <?php echo $brand_select_html; ?>
                    </td>
                    <td data-label="قیمت (تومان)">
                        <div class="price-wrapper">
                            <input type="text" class="variation-price-input" value="<?php echo esc_attr($product->get_regular_price() ? number_format($product->get_regular_price()) : ''); ?>" data-price-type="regular" data-id="<?php echo esc_attr($product->get_id()); ?>">
                            <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                        </div>
                    </td>
                    <td data-label="قیمت با تخفیف (تومان)">
                        <div class="price-wrapper">
                            <input type="text" class="variation-price-input" value="<?php echo esc_attr($product->get_sale_price() ? number_format($product->get_sale_price()) : ''); ?>" data-price-type="sale" data-id="<?php echo esc_attr($product->get_id()); ?>">
                            <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                        </div>
                    </td>
                    <td data-label="موجودی">
                        <div class="stock-wrapper">
                            <select class="stock-status-select" data-id="<?php echo esc_attr($product->get_id()); ?>">
                                <option value="instock" <?php selected($stock_status, 'instock'); ?>>موجود</option>
                                <option value="outofstock" <?php selected($stock_status, 'outofstock'); ?>>ناموجود</option>
                            </select>
                            <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                        </div>
                    </td>
                </tr>
                <?php
            }
        }
        wp_reset_postdata();
    } else {
        echo '<tr><td colspan="7">هیچ محصولی با این مشخصات یافت نشد.</td></tr>';
    }
    $products_html = ob_get_clean();

    $pagination_html = '';
    if ($products_query->max_num_pages > 1) {
        // Create custom pagination HTML
        $pagination_html = '<div class="psp-pagination">';
        
        // Previous button
        if ($paged > 1) {
            $pagination_html .= '<a href="#" data-page="' . ($paged - 1) . '" class="page-numbers prev">« قبلی</a>';
        }
        
        // Page numbers
        $start_page = max(1, $paged - 2);
        $end_page = min($products_query->max_num_pages, $paged + 2);
        
        if ($start_page > 1) {
            $pagination_html .= '<a href="#" data-page="1" class="page-numbers">1</a>';
            if ($start_page > 2) {
                $pagination_html .= '<span class="page-numbers dots">...</span>';
            }
        }
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            $current_class = ($i == $paged) ? ' current' : '';
            $pagination_html .= '<a href="#" data-page="' . $i . '" class="page-numbers' . $current_class . '">' . $i . '</a>';
        }
        
        if ($end_page < $products_query->max_num_pages) {
            if ($end_page < $products_query->max_num_pages - 1) {
                $pagination_html .= '<span class="page-numbers dots">...</span>';
            }
            $pagination_html .= '<a href="#" data-page="' . $products_query->max_num_pages . '" class="page-numbers">' . $products_query->max_num_pages . '</a>';
        }
        
        // Next button
        if ($paged < $products_query->max_num_pages) {
            $pagination_html .= '<a href="#" data-page="' . ($paged + 1) . '" class="page-numbers next">بعدی »</a>';
        }
        
        $pagination_html .= '</div>';
    }

    // Debug: Log pagination info
    error_log('PSP Pagination: Current page = ' . $paged . ', Total pages = ' . $products_query->max_num_pages);
    
    wp_send_json_success([
        'products_html' => $products_html, 
        'pagination_html' => $pagination_html,
        'current_page' => $paged,
        'total_pages' => $products_query->max_num_pages,
        'total_products' => $products_query->found_posts
    ]);
}
add_action('wp_ajax_psp_get_products', 'psp_ajax_get_products');
add_action('wp_ajax_nopriv_psp_get_products', 'psp_ajax_get_products');

// 5. AJAX Handler for Updating Prices
function psp_ajax_update_price() {
    check_ajax_referer('psp_update_price_nonce');

    $product_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($product_id <= 0) wp_send_json_error(['message' => 'شناسه نامعتبر است.']);

    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error(['message' => 'محصول یافت نشد.']);

    $price = isset($_POST['price']) ? wc_clean(wp_unslash($_POST['price'])) : '';
    $price_type = isset($_POST['price_type']) && 'sale' === $_POST['price_type'] ? 'sale' : 'regular';
    $price = str_replace(',', '', $price);

    if ('' !== $price && !is_numeric($price)) wp_send_json_error(['message' => 'قیمت نامعتبر است.']);

    if ($price_type === 'sale') {
        $product->set_sale_price($price);
    } else {
        $product->set_regular_price($price);
    }
    
    $product->save();
    wp_send_json_success(['message' => 'قیمت ذخیره شد.']);
}
add_action('wp_ajax_psp_update_price', 'psp_ajax_update_price');

// 6. AJAX Handler for Updating Stock Status
function psp_ajax_update_stock_status() {
    check_ajax_referer('psp_update_price_nonce');

    $product_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($product_id <= 0) wp_send_json_error(['message' => 'شناسه نامعتبر است.']);

    $product = wc_get_product($product_id);
    if (!$product) wp_send_json_error(['message' => 'محصول یافت نشد.']);

    $stock_status = isset($_POST['stock_status']) && in_array($_POST['stock_status'], ['instock', 'outofstock']) ? $_POST['stock_status'] : 'instock';
    
    $product->set_stock_status($stock_status);
    $product->save();
    
    wp_send_json_success(['message' => 'وضعیت موجودی ذخیره شد.']);
}
add_action('wp_ajax_psp_update_stock_status', 'psp_ajax_update_stock_status');

// 7a. AJAX Handler for Updating Brand
function psp_ajax_update_brand() {
    check_ajax_referer('psp_update_price_nonce');

    $product_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($product_id <= 0) {
        wp_send_json_error(['message' => 'شناسه نامعتبر است.']);
    }

    $brand_slug = isset($_POST['brand_slug']) ? sanitize_text_field(wp_unslash($_POST['brand_slug'])) : '';

    if ($brand_slug === '') {
        $result = wp_set_object_terms($product_id, [], 'product_brand', false);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => 'امکان حذف برند وجود ندارد.']);
        }

        wp_send_json_success(['message' => 'برند حذف شد.']);
    }

    $brand_term = get_term_by('slug', $brand_slug, 'product_brand');
    if (!$brand_term || is_wp_error($brand_term)) {
        wp_send_json_error(['message' => 'برند انتخابی نامعتبر است.']);
    }

    $result = wp_set_object_terms($product_id, (int) $brand_term->term_id, 'product_brand', false);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => 'امکان ذخیره برند وجود ندارد.']);
    }

    wp_send_json_success(['message' => 'برند ذخیره شد.']);
}
add_action('wp_ajax_psp_update_brand', 'psp_ajax_update_brand');

// 7. Enqueue Scripts and Styles
function psp_enqueue_admin_scripts($hook) {
    // Debug: Log hook name
    error_log('PSP Hook: ' . $hook);
    
    // Check if we're on our plugin pages (main page or submenu page)
    // Hook format: toplevel_page_{slug} for main menu, {parent-slug}_{submenu-slug} for submenu
    if ($hook === 'toplevel_page_puzzling_price_manager' || 
        $hook === 'تغییر-قیمت-پازلینگ_page_puzzling_price_manager' ||
        $hook === 'toplevel_page_puzzling_price_bulk' ||
        strpos($hook, 'puzzling_price') !== false) {
        
        error_log('PSP: Enqueuing scripts for hook: ' . $hook);
        
        wp_enqueue_style('dashicons');
        wp_enqueue_style('psp-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/price-manager.css', ['dashicons'], '2.4.1');
        wp_enqueue_script('psp-admin-script', plugin_dir_url(__FILE__) . 'assets/js/price-manager.js', ['jquery'], '2.4.1', true);
        
        wp_localize_script('psp-admin-script', 'psp_ajax_object', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'filter_nonce' => wp_create_nonce('psp_filter_nonce'),
            'update_nonce' => wp_create_nonce('psp_update_price_nonce'),
        ]);
        
        error_log('PSP: Scripts enqueued successfully');
    }
}
add_action('admin_enqueue_scripts', 'psp_enqueue_admin_scripts');

// Initialize bulk price bump
function psp_init_bulk_price_bump() {
    if (file_exists(__DIR__ . '/woo-bulk-price-bump.php') && !class_exists('XX_Woo_Bulk_Price_Bump')) {
        include_once __DIR__ . '/woo-bulk-price-bump.php';
    }
    
    if (class_exists('XX_Woo_Bulk_Price_Bump')) {
        $GLOBALS['xx_woo_bulk_price_bump'] = new XX_Woo_Bulk_Price_Bump();
    }
}
add_action('plugins_loaded', 'psp_init_bulk_price_bump');

// Render function for bulk price page
function psp_render_bulk_page() {
    if (isset($GLOBALS['xx_woo_bulk_price_bump']) && is_object($GLOBALS['xx_woo_bulk_price_bump'])) {
        $GLOBALS['xx_woo_bulk_price_bump']->page();
    } else {
        echo '<div class="wrap"><h1>تغییر قیمت گروهی</h1><p>خطا در بارگذاری ماژول تغییر قیمت گروهی</p></div>';
    }
}