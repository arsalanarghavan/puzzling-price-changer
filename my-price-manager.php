<?php
/**
 * Plugin Name:       تغییر قیمت‌ها
 * Description:       A page to manage all WooCommerce product prices using AJAX.
 * Version:           0.3.0
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
                <button id="psp_search_button" class="button button-primary">جستجو</button>
            </div>
        </div>

        <div class="psp-table-container">
            <table class="wp-list-table widefat fixed striped psp-table">
                <thead>
                    <tr>
                        <th class="product-name-col">نام محصول</th>
                        <th class="attributes-col">ویژگی‌ها</th>
                        <th class="price-col">قیمت (تومان)</th>
                        <th class="price-col">قیمت با تخفیف (تومان)</th>
                    </tr>
                </thead>
                <tbody id="psp-price-list-body">
                    </tbody>
            </table>
        </div>
        <div id="psp-pagination-container" class="psp-pagination-container"></div>
    </div>
    <?php
}

// 3. AJAX Handler for Getting Products using WP_Query (Safer Method)
function psp_ajax_get_products() {
    check_ajax_referer('psp_filter_nonce');

    $paged = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $posts_per_page = 50;

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        's'              => $search_term,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => ['relation' => 'AND'],
    ];

    if (!empty($_POST['category'])) {
        $args['tax_query'][] = [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => sanitize_text_field($_POST['category']),
        ];
    }

    if (!empty($_POST['brand'])) {
        $args['tax_query'][] = [
            'taxonomy' => 'pwb-brand',
            'field'    => 'slug',
            'terms'    => sanitize_text_field($_POST['brand']),
        ];
    }

    $products_query = new WP_Query($args);

    ob_start();
    if ($products_query->have_posts()) {
        while ($products_query->have_posts()) {
            $products_query->the_post();
            $product = wc_get_product(get_the_ID());
            if (!$product) continue;

            if ($product->is_type('variable')) {
                $variations_data = $product->get_available_variations();
                foreach ($variations_data as $i => $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if (!$variation) continue;
                    ?>
                    <tr class="<?php echo ($i === 0) ? 'product-start' : ''; ?>">
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
                                <input type="text" class="variation-price-input" value="<?php echo esc_attr($variation->get_regular_price() ? number_format($variation->get_regular_price()) : ''); ?>" data-price-type="regular" data-id="<?php echo esc_attr($variation->get_id()); ?>">
                                <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                            </div>
                        </td>
                        <td>
                            <div class="price-wrapper">
                                <input type="text" class="variation-price-input" value="<?php echo esc_attr($variation->get_sale_price() ? number_format($variation->get_sale_price()) : ''); ?>" data-price-type="sale" data-id="<?php echo esc_attr($variation->get_id()); ?>">
                                <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            } else { // For simple products and other types
                ?>
                 <tr class="product-start">
                    <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                    <td class="attributes-cell"><span class="attribute-tag">محصول ساده</span></td>
                    <td>
                        <div class="price-wrapper">
                            <input type="text" class="variation-price-input" value="<?php echo esc_attr($product->get_regular_price() ? number_format($product->get_regular_price()) : ''); ?>" data-price-type="regular" data-id="<?php echo esc_attr($product->get_id()); ?>">
                            <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                        </div>
                    </td>
                    <td>
                        <div class="price-wrapper">
                            <input type="text" class="variation-price-input" value="<?php echo esc_attr($product->get_sale_price() ? number_format($product->get_sale_price()) : ''); ?>" data-price-type="sale" data-id="<?php echo esc_attr($product->get_id()); ?>">
                            <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                        </div>
                    </td>
                </tr>
                <?php
            }
        }
        wp_reset_postdata();
    } else {
        echo '<tr><td colspan="4">هیچ محصولی با این مشخصات یافت نشد.</td></tr>';
    }
    $products_html = ob_get_clean();

    $pagination_html = paginate_links([
        'base' => '#%#%', 'format' => '?paged=%#%', 'current' => $paged,
        'total' => $products_query->max_num_pages, 'prev_text' => '«', 'next_text' => '»',
    ]);

    wp_send_json_success(['products_html' => $products_html, 'pagination_html' => $pagination_html]);
}
add_action('wp_ajax_psp_get_products', 'psp_ajax_get_products');

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

// 6. Enqueue Scripts and Styles
function psp_enqueue_admin_scripts($hook) {
    if ('toplevel_page_puzzling_price_manager' != $hook) return;
    
    wp_enqueue_style('psp-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/price-manager.css', [], '6.0.3');
    wp_enqueue_script('psp-admin-script', plugin_dir_url(__FILE__) . 'assets/js/price-manager.js', ['jquery'], '6.0.3', true);
    
    wp_localize_script('psp-admin-script', 'psp_ajax_object', [
        'ajax_url'     => admin_url('admin-ajax.php'),
        'filter_nonce' => wp_create_nonce('psp_filter_nonce'),
        'update_nonce' => wp_create_nonce('psp_update_price_nonce'),
    ]);
}
add_action('admin_enqueue_scripts', 'psp_enqueue_admin_scripts');