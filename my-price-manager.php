<?php
/**
 * Plugin Name:       تغییر قیمت‌ها
 * Description:       A page to manage all WooCommerce variable product prices using AJAX.
 * Version:           0.1.1
 * Author:            Arsalan Arghavan (Rewritten by Assistant)
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Add Admin Menu
function psp_add_admin_menu() {
    add_menu_page('پازلینگ', 'پازلینگ', 'manage_woocommerce', 'puzzling_price_manager', 'psp_render_admin_page', 'dashicons-admin-generic', 55);
}
add_action('admin_menu', 'psp_add_admin_menu');

// 2. Render the Main Admin Page HTML Structure
function psp_render_admin_page() {
    ?>
    <div class="wrap psp-wrap">
        <h1><span class="dashicons-before dashicons-editor-table"></span>لیست قیمت محصولات</h1>
        <p>در این صفحه می‌توانید قیمت تمام متغیرهای محصولات را به سرعت تغییر دهید. پس از تغییر قیمت، به صورت خودکار ذخیره می‌شود.</p>

        <div class="psp-filters">
            <?php
            wp_dropdown_categories([
                'show_option_all' => 'همه دسته‌بندی‌ها', 'taxonomy' => 'product_cat', 'name' => 'psp_category_filter',
                'id' => 'psp_category_filter', 'class' => 'psp-filter-select', 'hierarchical' => true, 'value_field' => 'slug'
            ]);
            $brands = get_terms(['taxonomy' => 'pwb-brand', 'hide_empty' => false]);
            if (!is_wp_error($brands) && !empty($brands)) {
                echo '<select id="psp_brand_filter" name="psp_brand_filter" class="psp-filter-select"><option value="">همه برندها</option>';
                foreach ($brands as $brand) {
                    echo '<option value="' . esc_attr($brand->slug) . '">' . esc_html($brand->name) . '</option>';
                }
                echo '</select>';
            }
            ?>
            <div class="psp-search-wrapper">
                <input type="text" id="psp_search_filter" name="psp_search_filter" class="psp-search-input" placeholder="جستجوی نام محصول...">
                <button id="psp_search_button" class="button">جستجو</button>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped psp-table">
            <thead>
                <tr>
                    <th class="product-name-col">نام محصول</th>
                    <th class="attributes-col">ویژگی‌ها</th>
                    <th class="price-col">قیمت (تومان)</th>
                    <th class="price-col">قیمت با تخفیف (تومان)</th>
                </tr>
            </thead>
            <tbody id="psp-price-list-body"></tbody>
        </table>
        <div class="psp-pagination-container" id="psp-pagination-container"></div>
    </div>
    <?php
}

// 3. AJAX Handler for Getting Products using Direct DB Query
function psp_ajax_get_products() {
    global $wpdb;
    check_ajax_referer('psp_filter_nonce', 'nonce');

    $paged = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $category_slug = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $brand_slug = isset($_POST['brand']) ? sanitize_text_field($_POST['brand']) : '';
    $posts_per_page = 50;
    $offset = ($paged - 1) * $posts_per_page;

    // Base SQL Query
    $sql_select = "SELECT DISTINCT p.ID";
    $sql_from = "FROM {$wpdb->posts} p";
    $sql_joins = "
        INNER JOIN {$wpdb->term_relationships} tr_type ON p.ID = tr_type.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt_type ON tr_type.term_taxonomy_id = tt_type.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t_type ON tt_type.term_id = t_type.term_id
    ";
    $sql_where = "WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt_type.taxonomy = 'product_type' AND t_type.slug = 'variable'";
    $params = [];

    // Add search to query
    if (!empty($search_term)) {
        $sql_where .= " AND p.post_title LIKE %s";
        $params[] = '%' . $wpdb->esc_like($search_term) . '%';
    }

    // Add category filter to query
    if (!empty($category_slug)) {
        $sql_joins .= "
            INNER JOIN {$wpdb->term_relationships} tr_cat ON p.ID = tr_cat.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tr_cat.term_taxonomy_id = tt_cat.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t_cat ON tt_cat.term_id = t_cat.term_id
        ";
        $sql_where .= " AND tt_cat.taxonomy = 'product_cat' AND t_cat.slug = %s";
        $params[] = $category_slug;
    }

    // Add brand filter to query
    if (!empty($brand_slug)) {
        $sql_joins .= "
            INNER JOIN {$wpdb->term_relationships} tr_brand ON p.ID = tr_brand.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt_brand ON tr_brand.term_taxonomy_id = tt_brand.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t_brand ON tt_brand.term_id = t_brand.term_id
        ";
        $sql_where .= " AND tt_brand.taxonomy = 'pwb-brand' AND t_brand.slug = %s";
        $params[] = $brand_slug;
    }

    // --- Get TOTAL count for pagination ---
    $count_query = "SELECT COUNT(DISTINCT p.ID) " . $sql_from . $sql_joins . $sql_where;
    if (!empty($params)) {
        $total_products = $wpdb->get_var($wpdb->prepare($count_query, $params));
    } else {
        $total_products = $wpdb->get_var($count_query);
    }
    $total_pages = ceil($total_products / $posts_per_page);

    // --- Get paginated product IDs ---
    $sql_limit = " ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
    $params[] = $posts_per_page;
    $params[] = $offset;
    $product_ids_query = $sql_select . $sql_from . $sql_joins . $sql_where . $sql_limit;
    $product_ids = $wpdb->get_col($wpdb->prepare($product_ids_query, $params));

    // Generate HTML from product IDs
    ob_start();
    if (!empty($product_ids)) {
        $last_product_id = 0;
        foreach ($product_ids as $product_id) {
             $product = wc_get_product($product_id);
            if (!$product || !$product->is_type('variable')) continue;

            $variations_data = $product->get_available_variations();
            $is_first_variation = true;
            foreach ($variations_data as $variation_data) {
                $variation = wc_get_product($variation_data['variation_id']);
                if (!$variation) continue;
                ?>
                <tr class="<?php echo ($is_first_variation && $last_product_id !== 0) ? 'product-start' : ''; ?>">
                    <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                    <td class="attributes-cell">
                        <?php foreach ($variation_data['attributes'] as $attr_key => $term_slug) {
                            $taxonomy = str_replace('attribute_', '', urldecode($attr_key));
                            $term = get_term_by('slug', $term_slug, $taxonomy);
                            echo '<span class="attribute-tag">' . wc_attribute_label($taxonomy) . ': <strong>' . ($term ? esc_html($term->name) : esc_html($term_slug)) . '</strong></span>';
                        } ?>
                    </td>
                    <td>
                        <div class="price-wrapper">
                            <input type="text" class="variation-price-input" value="<?php echo esc_attr($variation->get_regular_price() ? number_format($variation->get_regular_price()) : ''); ?>" data-price-type="regular" data-variation-id="<?php echo esc_attr($variation->get_id()); ?>">
                            <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                        </div>
                    </td>
                    <td>
                        <div class="price-wrapper">
                            <input type="text" class="variation-price-input" value="<?php echo esc_attr($variation->get_sale_price() ? number_format($variation->get_sale_price()) : ''); ?>" data-price-type="sale" data-variation-id="<?php echo esc_attr($variation->get_id()); ?>">
                            <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                        </div>
                    </td>
                </tr>
                <?php
                $is_first_variation = false;
            }
            $last_product_id = $product_id;
        }
    } else {
        echo '<tr><td colspan="4">هیچ محصولی با این مشخصات یافت نشد.</td></tr>';
    }
    $products_html = ob_get_clean();

    // Generate pagination HTML
    $pagination_html = paginate_links([
        'base' => '#%#%', 'format' => '?paged=%#%', 'current' => $paged,
        'total' => $total_pages, 'prev_text' => '«', 'next_text' => '»',
    ]);

    wp_send_json_success(['products_html' => $products_html, 'pagination_html' => $pagination_html]);
}
add_action('wp_ajax_psp_get_products', 'psp_ajax_get_products');

// 5. AJAX Handler for Updating Prices
function psp_ajax_update_price() {
    check_ajax_referer('psp_update_price_nonce', 'nonce');

    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    if ($variation_id <= 0) wp_send_json_error(['message' => 'شناسه نامعتبر است.']);

    $variation = wc_get_product($variation_id);
    if (!$variation) wp_send_json_error(['message' => 'محصول یافت نشد.']);

    $price = isset($_POST['price']) ? wc_clean(wp_unslash($_POST['price'])) : '';
    $price_type = isset($_POST['price_type']) && 'sale' === $_POST['price_type'] ? 'sale' : 'regular';
    $price = str_replace(',', '', $price);

    if ('' !== $price && !is_numeric($price)) wp_send_json_error(['message' => 'قیمت نامعتبر است.']);

    if ($price_type === 'sale') {
        $variation->set_sale_price($price);
    } else {
        $variation->set_regular_price($price);
    }
    
    $variation->save();
    wp_send_json_success(['message' => 'قیمت ذخیره شد.']);
}
add_action('wp_ajax_psp_update_price', 'psp_ajax_update_price');

// 6. Enqueue Scripts and Styles
function psp_enqueue_admin_scripts($hook) {
    if ('toplevel_page_puzzling_price_manager' != $hook) return;
    
    wp_enqueue_style('psp-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/price-manager.css', [], '6.0.0');
    wp_enqueue_script('psp-admin-script', plugin_dir_url(__FILE__) . 'assets/js/price-manager.js', ['jquery'], '6.0.0', true);
    
    wp_localize_script('psp-admin-script', 'psp_ajax_object', [
        'ajax_url'     => admin_url('admin-ajax.php'),
        'filter_nonce' => wp_create_nonce('psp_filter_nonce'),
        'update_nonce' => wp_create_nonce('psp_update_price_nonce'),
    ]);
}
add_action('admin_enqueue_scripts', 'psp_enqueue_admin_scripts');