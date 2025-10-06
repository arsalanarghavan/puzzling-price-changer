<?php
/**
 * Plugin Name:       تغییر قیمت‌ها
 * Description:       A page to manage all WooCommerce variable product prices using AJAX.
 * Version:           0.1.0
 * Author:            Arsalan Arghavan
 */

if (!defined('ABSPATH')) {
    exit;
}

function psp_add_admin_menu() {
    add_menu_page(
        'پازلینگ',
        'پازلینگ',
        'manage_woocommerce',
        'puzzling_main_menu',
        null,
        'dashicons-admin-generic',
        55
    );

    add_submenu_page(
        'puzzling_main_menu',
        'تغییر قیمت‌ها',
        'تغییر قیمت‌ها',
        'manage_woocommerce',
        'steel_price_manager',
        'psp_render_admin_page'
    );
}
add_action('admin_menu', 'psp_add_admin_menu');

function psp_render_admin_page() {
    ?>
    <div class="wrap psp-wrap">
        <h1><span class="dashicons-before dashicons-editor-table"></span>لیست قیمت</h1>
        <p>در این صفحه می‌توانید قیمت تمام متغیرهای محصولات را به سرعت تغییر دهید. پس از تغییر قیمت، به صورت خودکار ذخیره می‌شود.</p>
        
        <div class="psp-filters">
            <?php
            // Category Filter
            wp_dropdown_categories(array(
                'show_option_all' => 'همه دسته‌بندی‌ها',
                'taxonomy'        => 'product_cat',
                'name'            => 'psp_category_filter',
                'id'              => 'psp_category_filter',
                'class'           => 'psp-filter-select',
                'hierarchical'    => true,
                'value_field'     => 'slug',
            ));

            // Brand Filter (Perfect Brands for WooCommerce)
            $brands = get_terms(array('taxonomy' => 'pwb-brand', 'hide_empty' => false));
            if (!is_wp_error($brands) && !empty($brands)) {
                echo '<select id="psp_brand_filter" name="psp_brand_filter" class="psp-filter-select">';
                echo '<option value="">همه برندها</option>';
                foreach ($brands as $brand) {
                    echo '<option value="' . esc_attr($brand->slug) . '">' . esc_html($brand->name) . '</option>';
                }
                echo '</select>';
            }
            ?>
        </div>

        <table class="wp-list-table widefat fixed striped psp-table">
            <thead>
                <tr>
                    <th class="product-name-col">نام محصول</th>
                    <th class="attributes-col">ویژگی‌ها</th>
                    <th class="price-col">قیمت (تومان)</th>
                </tr>
            </thead>
            <tbody id="psp-price-list-body">
                <?php psp_get_filtered_products_callback(false); // Initial load ?>
            </tbody>
        </table>
    </div>
    <?php
}

function psp_get_filtered_products_callback($ajax = true) {
    if ($ajax) {
        check_ajax_referer('psp_filter_nonce', 'nonce');
    }

    $category_slug = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $brand_slug = isset($_POST['brand']) ? sanitize_text_field($_POST['brand']) : '';

    $tax_query = array('relation' => 'AND');

    if (!empty($category_slug)) {
        $tax_query[] = array(
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $category_slug,
        );
    }
    if (!empty($brand_slug)) {
        $tax_query[] = array(
            'taxonomy' => 'pwb-brand',
            'field'    => 'slug',
            'terms'    => $brand_slug,
        );
    }

    $args = array(
        'type' => 'variable',
        'limit' => -1,
        'return' => 'ids',
        'tax_query' => $tax_query,
    );
    $variable_products_ids = wc_get_products($args);

    if (empty($variable_products_ids)) {
        echo '<tr><td colspan="3">هیچ محصول متغیری یافت نشد.</td></tr>';
    } else {
        foreach ($variable_products_ids as $product_id) {
            $product = wc_get_product($product_id);
            $variations = $product->get_available_variations();

            foreach ($variations as $variation) {
                $variation_obj = wc_get_product($variation['variation_id']);
                $price = $variation_obj->get_price();
                ?>
                <tr data-variation-id="<?php echo esc_attr($variation['variation_id']); ?>">
                    <td><strong><?php echo esc_html($product->get_name()); ?></strong></td>
                    <td class="attributes-cell">
                        <?php
                        foreach ($variation['attributes'] as $attribute_slug => $term_slug) {
                            if (empty($term_slug)) continue;
                            $decoded_term_slug = urldecode($term_slug);
                            $taxonomy = str_replace('attribute_', '', urldecode($attribute_slug));
                            $attribute_label = wc_attribute_label($taxonomy);
                            $term = get_term_by('slug', $decoded_term_slug, $taxonomy);
                            $term_name = $term ? $term->name : $decoded_term_slug;

                            echo '<span class="attribute-tag">' . esc_html($attribute_label) . ': <strong>' . esc_html($term_name) . '</strong></span>';
                        }
                        ?>
                    </td>
                    <td>
                        <div class="price-wrapper">
                            <input type="text" 
                                   class="variation-price-input" 
                                   value="<?php echo esc_attr(number_format($price)); ?>" 
                                   data-variation-id="<?php echo esc_attr($variation['variation_id']); ?>"
                                   placeholder="قیمت جدید...">
                            <span class="save-status">
                                <span class="spinner"></span>
                                <span class="status-icon"></span>
                            </span>
                        </div>
                    </td>
                </tr>
                <?php
            }
        }
    }

    if ($ajax) {
        wp_die();
    }
}
add_action('wp_ajax_psp_get_filtered_products', 'psp_get_filtered_products_callback');


function psp_enqueue_admin_scripts($hook) {
    if ('puzzling_page_steel_price_manager' != $hook) return;
    wp_enqueue_style('psp-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/price-manager.css', [], '1.3');
    wp_enqueue_script('psp-admin-script', plugin_dir_url(__FILE__) . 'assets/js/price-manager.js', array('jquery'), '1.3', true);
    wp_localize_script('psp-admin-script', 'psp_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'update_nonce' => wp_create_nonce('psp_update_price_nonce'),
        'filter_nonce' => wp_create_nonce('psp_filter_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'psp_enqueue_admin_scripts');

function psp_update_variation_price_callback() {
    check_ajax_referer('psp_update_price_nonce', 'nonce');
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    $new_price = isset($_POST['new_price']) ? str_replace(',', '', wc_clean($_POST['new_price'])) : '';
    if ($variation_id > 0) {
        $variation = wc_get_product($variation_id);
        $variation->set_price($new_price);
        $variation->set_regular_price($new_price);
        if ($variation->save()) {
            wp_send_json_success(array('message' => 'قیمت ذخیره شد.'));
        } else {
            wp_send_json_error(array('message' => 'خطا در ذخیره سازی.'));
        }
    } else {
        wp_send_json_error(array('message' => 'شناسه متغیر نامعتبر است.'));
    }
    wp_die();
}
add_action('wp_ajax_update_variation_price', 'psp_update_variation_price_callback');

function psp_display_call_for_price($price_html, $product) {
    if ($product->get_price() === '' || $product->get_price() == 0) {
        if (is_shop() || is_product_category() || is_product_tag() || is_product()) {
             return '<span class="call-for-price">تماس بگیرید</span>';
        }
    }
    return $price_html;
}
add_filter('woocommerce_get_price_html', 'psp_display_call_for_price', 100, 2);
add_filter('woocommerce_variable_price_html', 'psp_display_call_for_price', 100, 2);