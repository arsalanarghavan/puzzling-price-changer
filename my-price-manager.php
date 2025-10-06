<?php
/**
 * Plugin Name:       تغییر قیمت‌ها
 * Description:       A page to manage all WooCommerce variable product prices using AJAX.
 * Version:           0.3.5
 * Author:            Arsalan Arghavan
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
function psp_add_admin_menu() {
    add_menu_page('پازلینگ', 'پازلینگ', 'manage_woocommerce', 'puzzling_main_menu', null, 'dashicons-admin-generic', 55);
    add_submenu_page('puzzling_main_menu', 'تغییر قیمت‌ها', 'تغییر قیمت‌ها', 'manage_woocommerce', 'steel_price_manager', 'psp_render_admin_page');
}
add_action('admin_menu', 'psp_add_admin_menu');

// Render the main admin page
function psp_render_admin_page() {
    ?>
    <div class="wrap psp-wrap">
        <h1><span class="dashicons-before dashicons-editor-table"></span>لیست قیمت محصولات</h1>
        <p>در این صفحه می‌توانید قیمت تمام متغیرهای محصولات را به سرعت تغییر دهید. پس از تغییر قیمت، به صورت خودکار ذخیره می‌شود.</p>

        <div class="psp-filters">
            <?php
            wp_dropdown_categories(['show_option_all' => 'همه دسته‌بندی‌ها', 'taxonomy' => 'product_cat', 'name' => 'psp_category_filter', 'id' => 'psp_category_filter', 'class' => 'psp-filter-select', 'hierarchical' => true, 'value_field' => 'slug']);
            $brands = get_terms(['taxonomy' => 'pwb-brand', 'hide_empty' => false]);
            if (!is_wp_error($brands) && !empty($brands)) {
                echo '<select id="psp_brand_filter" name="psp_brand_filter" class="psp-filter-select"><option value="">همه برندها</option>';
                foreach ($brands as $brand) echo '<option value="' . esc_attr($brand->slug) . '">' . esc_html($brand->name) . '</option>';
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
            <tbody id="psp-price-list-body">
                <?php psp_get_filtered_products_callback(false); ?>
            </tbody>
        </table>
        <div id="psp-pagination-container" class="psp-pagination"></div>
    </div>
    <?php
}

// AJAX handler for filtering products
function psp_get_filtered_products_callback($ajax = true) {
    if ($ajax) check_ajax_referer('psp_filter_nonce', 'nonce');

    $paged = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $category_slug = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $brand_slug = isset($_POST['brand']) ? sanitize_text_field($_POST['brand']) : '';

    $tax_query = ['relation' => 'AND'];
    if (!empty($category_slug)) $tax_query[] = ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $category_slug];
    if (!empty($brand_slug)) $tax_query[] = ['taxonomy' => 'pwb-brand', 'field' => 'slug', 'terms' => $brand_slug];

    $args = ['type' => 'variable', 'limit' => 50, 'page' => $paged, 'return' => 'ids', 's' => $search_term];
    if (count($tax_query) > 1) $args['tax_query'] = $tax_query;

    $query = new WC_Product_Query($args);
    $product_ids = $query->get_products();
    
    ob_start();

    if (empty($product_ids)) {
        echo '<tr><td colspan="4">هیچ محصول متغیری با این مشخصات یافت نشد.</td></tr>';
    } else {
        $last_product_id = 0;
        foreach ($product_ids as $product_id) {
            try {
                $product = wc_get_product($product_id);
                if (!is_a($product, 'WC_Product_Variable')) continue;

                $variations_data = $product->get_available_variations();
                if (empty($variations_data)) continue;

                $is_first_variation_of_product = true;
                foreach ($variations_data as $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if (!is_a($variation, 'WC_Product_Variation')) continue;

                    $row_class = '';
                    if ($is_first_variation_of_product && $last_product_id !== 0) {
                        $row_class = 'class="product-start"';
                    }
                    ?>
                    <tr data-variation-id="<?php echo esc_attr($variation->get_id()); ?>" <?php echo $row_class; ?>>
                        <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                        <td class="attributes-cell">
                            <?php
                            // ** FIX: Re-added urldecode to correctly display attribute names **
                            if (!empty($variation_data['attributes'])) {
                                foreach ($variation_data['attributes'] as $attr_key => $term_slug) {
                                    if (empty($term_slug)) continue;
                                    
                                    $taxonomy = str_replace('attribute_', '', urldecode($attr_key));
                                    $attr_label = wc_attribute_label($taxonomy);
                                    
                                    $decoded_term_slug = urldecode($term_slug);
                                    $term = get_term_by('slug', $decoded_term_slug, $taxonomy);
                                    $term_name = $term ? $term->name : $decoded_term_slug;

                                    echo '<span class="attribute-tag">' . esc_html($attr_label) . ': <strong>' . esc_html($term_name) . '</strong></span>';
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <div class="price-wrapper">
                                <input type="text" class="variation-price-input" value="<?php echo esc_attr($variation->get_regular_price() ? number_format($variation->get_regular_price()) : ''); ?>" data-variation-id="<?php echo esc_attr($variation->get_id()); ?>" placeholder="قیمت عادی...">
                                <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                            </div>
                        </td>
                        <td>
                            <div class="price-wrapper">
                                <input type="text" class="variation-sale-price-input" value="<?php echo esc_attr($variation->get_sale_price() ? number_format($variation->get_sale_price()) : ''); ?>" data-variation-id="<?php echo esc_attr($variation->get_id()); ?>" placeholder="قیمت تخفیف...">
                                <span class="save-status"><span class="spinner"></span><span class="status-icon"></span></span>
                            </div>
                        </td>
                    </tr>
                    <?php
                    $is_first_variation_of_product = false;
                }
                $last_product_id = $product_id;
            } catch (Exception $e) {
                error_log("Puzzling Price Changer Error: Failed to process product ID {$product_id}. Error: " . $e->getMessage());
                continue;
            }
        }
    }
    
    $output = ob_get_clean();

    if ($ajax) {
        $total_products = $query->get_total();
        $num_pages = ceil($total_products / 50);
        $pagination = paginate_links(['base' => '#', 'format' => '', 'prev_text' => '«', 'next_text' => '»', 'total' => $num_pages, 'current' => $paged]);
        echo $output . '' . $pagination;
        wp_die();
    } else {
        echo $output;
    }
}
add_action('wp_ajax_psp_get_filtered_products', 'psp_get_filtered_products_callback');


// Enqueue scripts and styles
function psp_enqueue_admin_scripts($hook) {
    if ('puzzling_page_steel_price_manager' != $hook) return;
    wp_enqueue_style('psp-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/price-manager.css', [], '1.5.4');
    wp_enqueue_script('psp-admin-script', plugin_dir_url(__FILE__) . 'assets/js/price-manager.js', ['jquery'], '1.5.4', true);
    wp_localize_script('psp-admin-script', 'psp_ajax_object', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'update_nonce' => wp_create_nonce('psp_update_price_nonce'),
        'filter_nonce' => wp_create_nonce('psp_filter_nonce'),
    ]);
}
add_action('admin_enqueue_scripts', 'psp_enqueue_admin_scripts');

// AJAX handler for updating prices
function psp_update_variation_price_callback() {
    check_ajax_referer('psp_update_price_nonce', 'nonce');

    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    if ($variation_id <= 0) wp_send_json_error(['message' => 'شناسه متغیر نامعتبر است.']);

    $variation = wc_get_product($variation_id);
    if (!is_a($variation, 'WC_Product_Variation')) wp_send_json_error(['message' => 'متغیر محصول یافت نشد.']);

    $price = isset($_POST['price']) ? wc_clean(wp_unslash($_POST['price'])) : '';
    $price_type = isset($_POST['price_type']) && $_POST['price_type'] === 'sale' ? 'sale' : 'regular';
    $price = str_replace(',', '', $price);

    if ($price_type === 'sale') $variation->set_sale_price($price);
    else $variation->set_regular_price($price);
    
    if ($variation->save() > 0) wp_send_json_success(['message' => 'قیمت ذخیره شد.']);
    else wp_send_json_error(['message' => 'خطا در ذخیره سازی قیمت.']);
}
add_action('wp_ajax_update_variation_price', 'psp_update_variation_price_callback');

// Display 'Call for Price' text
function psp_display_call_for_price($price_html, $product) {
    if (!is_admin() && ($product->get_price() === '' || $product->get_price() == 0) && (is_shop() || is_product_category() || is_product_tag() || is_product())) {
        return '<span class="call-for-price">تماس بگیرید</span>';
    }
    return $price_html;
}
add_filter('woocommerce_get_price_html', 'psp_display_call_for_price', 100, 2);
add_filter('woocommerce_variable_price_html', 'psp_display_call_for_price', 100, 2);