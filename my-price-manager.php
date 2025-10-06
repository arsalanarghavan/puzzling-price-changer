<?php
/**
 * Plugin Name:       تغییر قیمت‌ها
 * Description:       A page to manage all WooCommerce variable product prices using AJAX.
 * Version:           0.5.1
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
            <tbody id="psp-price-list-body">
                <?php psp_get_filtered_products_callback(false); // Initial load ?>
            </tbody>
        </table>
        <div id="psp-pagination-container" class="psp-pagination">
             <?php psp_get_pagination_callback(false); // Initial pagination load ?>
        </div>
    </div>
    <?php
}

// Function to get query arguments based on POST data
function psp_get_query_args() {
    $paged = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $category_slug = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $brand_slug = isset($_POST['brand']) ? sanitize_text_field($_POST['brand']) : '';

    $args = [
        'type' => 'variable',
        'limit' => 50,
        'page' => $paged,
        's' => $search_term,
    ];
    
    $tax_query = ['relation' => 'AND'];
    if (!empty($category_slug)) {
        $tax_query[] = ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $category_slug];
    }
    if (!empty($brand_slug)) {
        $tax_query[] = ['taxonomy' => 'pwb-brand', 'field' => 'slug', 'terms' => $brand_slug];
    }
    if (count($tax_query) > 1) {
        $args['tax_query'] = $tax_query;
    }

    return $args;
}


// Function to fetch products and generate table rows
function psp_get_filtered_products_callback($ajax = true) {
    if ($ajax) check_ajax_referer('psp_filter_nonce', 'nonce');

    $args = psp_get_query_args();
    $args['return'] = 'ids';
    
    $query = new WC_Product_Query($args);
    $product_ids = $query->get_products();
    
    ob_start();

    if (empty($product_ids)) {
        echo '<tr><td colspan="4">هیچ محصولی یافت نشد.</td></tr>';
    } else {
        $last_product_id = 0;
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!is_a($product, 'WC_Product_Variable')) continue;

            $variations_data = $product->get_available_variations();
            $is_first_variation = true;
            foreach ($variations_data as $variation_data) {
                $variation = wc_get_product($variation_data['variation_id']);
                if (!is_a($variation, 'WC_Product_Variation')) continue;

                $row_class = '';
                if ($is_first_variation && $last_product_id !== 0) {
                    $row_class = 'class="product-start"';
                }
                ?>
                <tr data-variation-id="<?php echo esc_attr($variation->get_id()); ?>" <?php echo $row_class; ?>>
                    <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                    <td class="attributes-cell">
                        <?php
                        foreach ($variation_data['attributes'] as $attr_key => $term_slug) {
                            $taxonomy = str_replace('attribute_', '', urldecode($attr_key));
                            $term = get_term_by('slug', urldecode($term_slug), $taxonomy);
                            echo '<span class="attribute-tag">' . wc_attribute_label($taxonomy) . ': <strong>' . ($term ? $term->name : $term_slug) . '</strong></span>';
                        }
                        ?>
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
    }
    
    $output = ob_get_clean();

    if ($ajax) {
        wp_send_json_success(['html' => $output]);
    } else {
        echo $output;
    }
}
add_action('wp_ajax_psp_get_filtered_products', 'psp_get_filtered_products_callback');

// Function to fetch pagination
function psp_get_pagination_callback($ajax = true) {
     if ($ajax) check_ajax_referer('psp_filter_nonce', 'nonce');

    $args = psp_get_query_args();
    $query = new WC_Product_Query($args);
    $total_products = $query->get_total();
    $num_pages = ceil($total_products / 50);

    $pagination_html = paginate_links([
        'base' => admin_url('admin.php?page=steel_price_manager') . '%_%',
        'format' => '&paged=%#%',
        'prev_text' => '«',
        'next_text' => '»',
        'total' => $num_pages,
        'current' => $args['page'],
    ]);

    if ($ajax) {
        wp_send_json_success(['html' => $pagination_html]);
    } else {
        echo $pagination_html;
    }
}
add_action('wp_ajax_psp_get_pagination', 'psp_get_pagination_callback');

// Enqueue scripts and styles
function psp_enqueue_admin_scripts($hook) {
    if ('puzzling_page_steel_price_manager' != $hook) return;
    wp_enqueue_style('psp-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/price-manager.css', [], '1.0.1');
    wp_enqueue_script('psp-admin-script', plugin_dir_url(__FILE__) . 'assets/js/price-manager.js', ['jquery'], '1.0.1', true);
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
    if ($variation_id <= 0) wp_send_json_error(['message' => 'شناسه نامعتبر.']);

    $variation = wc_get_product($variation_id);
    if (!is_a($variation, 'WC_Product_Variation')) wp_send_json_error(['message' => 'محصول یافت نشد.']);

    $price = isset($_POST['price']) ? wc_clean(wp_unslash($_POST['price'])) : '';
    $price_type = isset($_POST['price_type']) && $_POST['price_type'] === 'sale' ? 'sale' : 'regular';
    $price = str_replace(',', '', $price);

    if ($price_type === 'sale') {
        $variation->set_sale_price($price);
    } else {
        $variation->set_regular_price($price);
    }
    
    if ($variation->save()) {
        wp_send_json_success(['message' => 'قیمت ذخیره شد.']);
    } else {
        wp_send_json_error(['message' => 'خطا در ذخیره سازی.']);
    }
}
add_action('wp_ajax_update_variation_price', 'psp_update_variation_price_callback');