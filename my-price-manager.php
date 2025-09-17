<?php
/**
 * Plugin Name:       Puzzling Steel Price List
 * Description:       A page to manage all WooCommerce variable product prices using AJAX.
 * Version:           0.0.4
 * Author:            Arsalan Arghavan
 */

if (!defined('ABSPATH')) {
    exit;
}

function psp_add_admin_menu() {
    add_menu_page(
        'لیست قیمت',
        'لیست قیمت',
        'manage_woocommerce',
        'steel_price_manager',
        'psp_render_admin_page',
        'dashicons-editor-table',
        56
    );
}
add_action('admin_menu', 'psp_add_admin_menu');

function psp_render_admin_page() {
    ?>
    <div class="wrap psp-wrap">
        <h1><span class="dashicons-before dashicons-editor-table"></span>لیست قیمت</h1>
        <p>در این صفحه می‌توانید قیمت تمام متغیرهای محصولات را به سرعت تغییر دهید. پس از تغییر قیمت، به صورت خودکار ذخیره می‌شود.</p>
        
        <table class="wp-list-table widefat fixed striped psp-table">
            <thead>
                <tr>
                    <th class="product-name-col">نام محصول</th>
                    <th class="attributes-col">ویژگی‌ها</th>
                    <th class="price-col">قیمت (تومان)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $args = array('type' => 'variable', 'limit' => -1, 'return' => 'ids');
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
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

function psp_enqueue_admin_scripts($hook) {
    if ('toplevel_page_steel_price_manager' != $hook) return;
    wp_enqueue_style('psp-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/price-manager.css', [], '1.2');
    wp_enqueue_script('psp-admin-script', plugin_dir_url(__FILE__) . 'assets/js/price-manager.js', array('jquery'), '1.2', true);
    wp_localize_script('psp-admin-script', 'psp_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('psp_update_price_nonce')
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