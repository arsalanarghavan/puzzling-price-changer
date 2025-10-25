<?php
/**
 * Bulk Price Bump Module
 * Part of Puzzling Price Changer Plugin
 * Provides bulk price modification functionality
 */

if (!defined('ABSPATH')) exit;

final class XX_Woo_Bulk_Price_Bump {
    const SLUG        = 'xx-bulk-price-bump';
    const GROUP       = 'xx-bpb';
    const PER_PAGE    = 100;
    const LOCK_KEY    = 'xx_bpb_lock';
    const PARAMS_KEY  = 'xx_bpb_params';
    const STATE_KEY   = 'xx_bpb_state';
    const LASTLOG_KEY = 'xx_bpb_last_log';
    const LOG_DIR     = 'xx-bpb-logs';

    public function __construct() {
        // Don't add menu automatically - it will be added by the main plugin
        // add_action('admin_menu',        [$this, 'menu']);
        add_action('admin_notices',     [$this, 'notices']);
        add_action('admin_bar_menu',    [$this, 'admin_bar_status'], 100);
        add_action('admin_post_xx_bpb_start',   [$this, 'handle_start']);
        add_action('admin_post_xx_bpb_cancel',  [$this, 'handle_cancel']);
        add_action('admin_post_xx_bpb_run_now', [$this, 'handle_run_now']);
        add_action('admin_post_xx_bpb_import',  [$this, 'handle_import']);
        add_action('admin_post_xx_bpb_clear_queue', [$this, 'handle_clear_queue']);
        add_action('xx_bpb_process',        [$this, 'job_bump'], 10, 2);
        add_action('xx_bpb_import_process', [$this, 'job_import'], 10, 2);
    }

    private function sanitize_price($price_string) {
        $price_string = trim($price_string);
        if ($price_string === '') return '';
        return preg_replace('/[^0-9.]/', '', $price_string);
    }

    public function handle_import() {
        if (!current_user_can('manage_woocommerce')) wp_die('No access');
        if (!$this->nonce_ok(self::SLUG.'_import_nonce', self::SLUG.'_import_nonce_field')) wp_die('Bad nonce');
        if (get_transient(self::LOCK_KEY)) $this->redirect_err('یک فرآیند دیگر در حال اجراست. لطفا صبر کرده یا آن را لغو کنید.');
        if (empty($_FILES['import_csv']) || $_FILES['import_csv']['error'] !== UPLOAD_ERR_OK) $this->redirect_err('خطا در آپلود فایل یا فایلی انتخاب نشده است.');

        $file_info = wp_check_filetype($_FILES['import_csv']['name']);
        if (!in_array(strtolower($file_info['ext']), ['csv'])) {
            $this->redirect_err('نوع فایل نامعتبر است. لطفاً فقط فایل با فرمت CSV آپلود کنید.');
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $movefile = wp_handle_upload($_FILES['import_csv'], ['test_form' => false]);

        if ($movefile && !isset($movefile['error'])) {
            $file_path = $movefile['file'];
            $handle = fopen($file_path, 'r');
            if (!$handle) {
                $this->redirect_err('امکان خواندن فایل آپلود شده وجود ندارد.');
            }

            $header = fgetcsv($handle);
            if (empty($header) || !in_array('id', $header) || !in_array('regular_new', $header)) {
                fclose($handle);
                wp_delete_file($file_path);
                $this->redirect_err('فایل CSV نامعتبر است. سطر اول (هدر) باید حتما شامل ستون‌های `id` و `regular_new` باشد.');
            }
            
            $total_rows = 0;
            while(fgetcsv($handle) !== false) $total_rows++;
            fclose($handle);

            if ($total_rows === 0) {
                wp_delete_file($file_path);
                $this->redirect_err('فایل CSV هیچ ردیف داده‌ای ندارد.');
            }

            $run_id = wp_generate_uuid4();
            $this->init_import_log($run_id);
            $params = ['job_type' => 'import', 'run_id' => $run_id, 'started_at' => time(), 'file_path' => $file_path];
            update_option(self::PARAMS_KEY, $params, false);

            $pages = (int) ceil($total_rows / self::PER_PAGE);
            $state = ['total' => $total_rows, 'pages' => $pages, 'current_page' => 0, 'processed' => 0, 'processed_ids' => []];
            update_option(self::STATE_KEY, $state, false);

            set_transient(self::LOCK_KEY, 1, 30 * MINUTE_IN_SECONDS);
            $this->enqueue_job('xx_bpb_import_process', 1, $run_id);

            wp_redirect(admin_url('admin.php?page=' . self::SLUG . '&queued=1'));
            exit;
        } else {
            $this->redirect_err('خطا در آپلود فایل: ' . ($movefile['error'] ?? 'Unknown error'));
        }
    }

    public function job_import($page = 1, $run = '') {
        set_transient(self::LOCK_KEY, 1, 30 * MINUTE_IN_SECONDS);
        $params = get_option(self::PARAMS_KEY);
        $state  = get_option(self::STATE_KEY);
        if (!$params || !$state || $run !== $params['run_id'] || empty($params['file_path']) || !file_exists($params['file_path'])) {
            delete_transient(self::LOCK_KEY); return;
        }

        $processed_ids_map = isset($state['processed_ids']) ? $state['processed_ids'] : [];

        $handle = fopen($params['file_path'], 'r');
        if (!$handle) { return; }

        $header = fgetcsv($handle);
        $id_idx = array_search('id', $header);
        $reg_new_idx = array_search('regular_new', $header);
        $sale_new_idx = array_search('sale_new', $header);

        if ($id_idx === false || $reg_new_idx === false) {
            fclose($handle);
            delete_transient(self::LOCK_KEY);
            return;
        }
        
        rewind($handle);
        fgetcsv($handle); 

        $start_row = (($page - 1) * self::PER_PAGE);
        if ($start_row > 0) {
            for ($i = 0; $i < $start_row; $i++) {
                if (fgetcsv($handle) === false) break;
            }
        }
        
        $processed_in_batch = 0;
        $total_processed_so_far = ($page - 1) * self::PER_PAGE;
        $log_rows = [];
        
        while ($processed_in_batch < self::PER_PAGE && ($row = fgetcsv($handle)) !== false) {
             $processed_in_batch++;
             $product_id = !empty($row[$id_idx]) ? absint($row[$id_idx]) : 0;
             if (!$product_id) continue;
             
             if (isset($processed_ids_map[$product_id])) {
                 $log_rows[] = $this->row_import($product_id, 'SKIPPED', '', '', 'Duplicate Product ID in CSV. Already processed.');
                 continue;
             }
             
             $product = wc_get_product($product_id);
             if (!$product) {
                 $log_rows[] = $this->row_import($product_id, 'SKIPPED', '', '', 'Product ID not found.');
                 continue;
             }

             $old_reg_price = $product->get_regular_price('edit');
             $reg_price_csv = $row[$reg_new_idx];
             $sanitized_reg_price = $this->sanitize_price($reg_price_csv);

             if ($sanitized_reg_price === '' || !is_numeric($sanitized_reg_price) || $sanitized_reg_price < 0) {
                 $log_rows[] = $this->row_import($product_id, 'SKIPPED', $old_reg_price, $reg_price_csv, "Invalid price format: '{$reg_price_csv}'");
                 continue;
             }
             
             $sale_price_csv = ($sale_new_idx !== false) ? $row[$sale_new_idx] : '';
             $sanitized_sale_price = $this->sanitize_price($sale_price_csv);

             try {
                if ($product->is_type('variable')) {
                    $children = $product->get_children();
                    if (empty($children)) {
                        $log_rows[] = $this->row_import($product_id, 'SKIPPED', $old_reg_price, $reg_price_csv, 'Variable product has no variations.');
                        continue;
                    }
                    $all_variations_saved = true;
                    foreach ($children as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if (!$variation) continue;

                        $variation->set_regular_price($sanitized_reg_price);
                        if ($sanitized_sale_price !== '' && is_numeric($sanitized_sale_price) && $sanitized_sale_price >= 0) {
                            $variation->set_sale_price($sanitized_sale_price);
                        } else {
                            $variation->set_sale_price('');
                        }
                        
                        $update_result = $variation->save();
                        if ($update_result === 0 || $update_result === false) {
                            $all_variations_saved = false;
                        }
                    }

                    if ($all_variations_saved) {
                        WC_Product_Variable::sync($product);
                        $log_rows[] = $this->row_import($product_id, 'SUCCESS', $old_reg_price, $sanitized_reg_price, 'All variations updated.');
                    } else {
                        $log_rows[] = $this->row_import($product_id, 'FAILED', $old_reg_price, $sanitized_reg_price, 'One or more variations failed to save silently. This is likely a plugin/theme conflict.');
                    }
                } else {
                    $product->set_regular_price($sanitized_reg_price);
                    if ($sanitized_sale_price !== '' && is_numeric($sanitized_sale_price) && $sanitized_sale_price >= 0) {
                        $product->set_sale_price($sanitized_sale_price);
                    } else {
                        $product->set_sale_price('');
                    }

                    $update_result = $product->save();

                    if ($update_result > 0) {
                        $log_rows[] = $this->row_import($product_id, 'SUCCESS', $old_reg_price, $sanitized_reg_price, 'Product updated.');
                    } else {
                        $log_rows[] = $this->row_import($product_id, 'FAILED', $old_reg_price, $sanitized_reg_price, 'Save command failed silently. This is likely a plugin/theme conflict.');
                    }
                }
                wc_delete_product_transients($product_id);
                $processed_ids_map[$product_id] = true;
             } catch (\Throwable $e) {
                $log_rows[] = $this->row_import($product_id, 'ERROR', $old_reg_price, $reg_price_csv, 'Exception during save: ' . $e->getMessage());
             }
        }
        
        $is_last_batch = feof($handle);
        fclose($handle);

        if (!empty($log_rows)) $this->log_rows($run, $log_rows, 'import-log-');
        
        $state['current_page'] = (int)$page;
        $state['processed'] = $total_processed_so_far + $processed_in_batch;
        $state['processed_ids'] = $processed_ids_map;
        update_option(self::STATE_KEY, $state, false);

        if ($is_last_batch || $state['processed'] >= $state['total']) {
            if (function_exists('wp_cache_flush')) wp_cache_flush();
            if (function_exists('litespeed_purge_all')) litespeed_purge_all();
            
            $last = ['run' => $run, 'url' => $this->log_url($run, 'import-log-')];
            update_option(self::LASTLOG_KEY, $last, false);

            if (file_exists($params['file_path'])) wp_delete_file($params['file_path']);
            delete_option(self::PARAMS_KEY);
            delete_option(self::STATE_KEY);
            delete_transient(self::LOCK_KEY);
        } else {
            $this->enqueue_job('xx_bpb_import_process', (int)$page + 1, $run);
        }
    }
    
    public function job_bump($page = 1, $run = '', $from_ui = false) { set_transient(self::LOCK_KEY, 1, 30 * MINUTE_IN_SECONDS); $params = get_option(self::PARAMS_KEY); $state  = get_option(self::STATE_KEY); if (!$params || !$state || $run !== $params['run_id']) { delete_transient(self::LOCK_KEY); return; } $done = $this->process_batch($params, (int)$page); $state['current_page'] = (int)$page; $state['processed']   += (int)$done['updated']; update_option(self::STATE_KEY, $state, false); if ($done['finished']) { delete_option(self::PARAMS_KEY); delete_option(self::STATE_KEY); delete_transient(self::LOCK_KEY); $last = ['run' => $run, 'url' => $this->log_url($run)]; update_option(self::LASTLOG_KEY, $last, false); if ($from_ui) { wp_redirect(admin_url('admin.php?page=' . self::SLUG . '&xx_bpb_done=' . urlencode('پایان فرآیند افزایش قیمت. به‌روزرسانی انجام شد.'))); exit; } } else { $this->enqueue_job('xx_bpb_process', (int)$page + 1, $run); } }
    public function menu() { 
        // This is handled by the main plugin now
        // add_submenu_page('woocommerce','Woo Bulk Price Bump','Woo Bulk Price Bump','manage_woocommerce',self::SLUG,[$this,'page']); 
    }
    public function page() {
        if (!current_user_can('manage_woocommerce')) return;
        
        $params = get_option(self::PARAMS_KEY);
        $state  = get_option(self::STATE_KEY);
        $locked = (bool) get_transient(self::LOCK_KEY);
        ?>
        <div class="wrap psp-bulk-wrap">
            <div class="psp-bulk-header">
                <h1><span class="dashicons dashicons-update"></span>تغییر قیمت گروهی</h1>
                <p>ابزار قدرتمند برای تغییر قیمت‌های گروهی محصولات ووکامرس با قابلیت‌های پیشرفته</p>
            </div>
            
            <?php if ($params): ?>
                <?php $this->render_status($params, $state, true, $locked); ?>
                <div class="psp-bulk-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psp-inline-form">
                        <?php wp_nonce_field(self::SLUG.'_nonce', self::SLUG.'_nonce_field'); ?>
                        <input type="hidden" name="action" value="xx_bpb_cancel">
                        <button class="button button-secondary psp-cancel-btn">
                            <span class="dashicons dashicons-no"></span> لغو فرآیند
                        </button>
                    </form>
                    
                    <?php if (isset($params['job_type']) && $params['job_type'] !== 'import'): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psp-inline-form">
                            <?php wp_nonce_field(self::SLUG.'_nonce', self::SLUG.'_nonce_field'); ?>
                            <input type="hidden" name="action" value="xx_bpb_run_now">
                            <button class="button button-primary psp-run-now-btn">
                                <span class="dashicons dashicons-controls-play"></span> اجرای فوری
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="psp-bulk-content">
                    <?php $this->render_form(); ?>
                    <?php $this->render_import_form(); ?>
                    <?php $this->render_tools_section(); ?>
                    
                    <?php 
                    $last = get_option(self::LASTLOG_KEY);
                    if (!empty($last['url'])): 
                    ?>
                        <div class="psp-log-section">
                            <h3><span class="dashicons dashicons-download"></span>آخرین فایل لاگ</h3>
                            <p>لینک آخرین فایل لاگ ایجاد شده: 
                                <a target="_blank" href="<?php echo esc_url($last['url']); ?>" class="psp-log-link">
                                    <?php echo esc_html(basename($last['url'])); ?>
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    private function render_form() {
        ?>
        <div class="psp-bulk-card">
            <div class="psp-card-header">
                <h2><span class="dashicons dashicons-chart-line"></span>افزایش گروهی قیمت‌ها</h2>
                <p>قیمت محصولات را به صورت گروهی و خودکار افزایش دهید</p>
            </div>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psp-bulk-form">
                <?php wp_nonce_field(self::SLUG.'_nonce', self::SLUG.'_nonce_field'); ?>
                <input type="hidden" name="action" value="xx_bpb_start">
                
                <div class="psp-form-section">
                    <h3>تنظیمات پایه</h3>
                    <div class="psp-form-row">
                        <label class="psp-form-label">نوع افزایش</label>
                        <div class="psp-radio-group">
                            <label class="psp-radio-item">
                                <input type="radio" name="type" value="fixed" checked>
                                <span class="psp-radio-text">مبلغ ثابت (تومان)</span>
                            </label>
                            <label class="psp-radio-item">
                                <input type="radio" name="type" value="percent">
                                <span class="psp-radio-text">درصد (%)</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="psp-form-row">
                        <label class="psp-form-label" for="value">مقدار افزایش</label>
                        <input type="number" id="value" name="value" step="0.0001" required 
                               class="psp-form-input" placeholder="مثلاً 50000 یا 10">
                        <p class="psp-form-description">مقدار افزایش را وارد کنید</p>
                    </div>
                    
                    <div class="psp-form-row">
                        <label class="psp-checkbox-item">
                            <input type="checkbox" name="apply_sale" value="1" checked>
                            <span class="psp-checkbox-text">اعمال روی قیمت‌های حراج</span>
                        </label>
                    </div>
                </div>
                
                <div class="psp-form-section">
                    <h3>فیلترها (اختیاری)</h3>
                    <div class="psp-form-row">
                        <label class="psp-form-label" for="category_slugs">محدود به دسته‌ها</label>
                        <input type="text" id="category_slugs" name="category_slugs" 
                               class="psp-form-input" placeholder="slug دسته‌ها با کاما: makeup,skincare">
                        <p class="psp-form-description">فقط محصولات این دسته‌ها را تغییر دهید</p>
                    </div>
                </div>
                
                <div class="psp-form-section">
                    <h3>قوانین پیشرفته (اختیاری)</h3>
                    <div class="psp-form-row">
                        <label class="psp-form-label" for="range_rules">قوانین بازه‌ای</label>
                        <textarea id="range_rules" name="range_rules" rows="6" 
                                  class="psp-form-textarea" placeholder="هر خط: min-max: delta"></textarea>
                        <p class="psp-form-description">مثال: 10000-50000: 5000 (برای قیمت‌های 10-50 هزار تومان، 5 هزار تومان اضافه کن)</p>
                        
                        <label class="psp-checkbox-item">
                            <input type="checkbox" name="rules_combine" value="1">
                            <span class="psp-checkbox-text">ترکیب با افزایش پایه (پیش‌فرض: جایگزین)</span>
                        </label>
                    </div>
                </div>
                
                <div class="psp-form-section">
                    <h3>رند کردن قیمت (اختیاری)</h3>
                    <div class="psp-form-row">
                        <label class="psp-checkbox-item">
                            <input type="checkbox" name="enable_rounding" value="1">
                            <span class="psp-checkbox-text">فعال‌سازی رند کردن قیمت</span>
                        </label>
                        <p class="psp-form-description">قیمت‌های نهایی که کمتر از آستانه باشند، رند می‌شوند</p>
                        
                        <div class="psp-form-inline">
                            <div class="psp-form-group">
                                <label class="psp-form-label" for="rounding_threshold">آستانه قیمت</label>
                                <input type="number" id="rounding_threshold" name="rounding_threshold" 
                                       value="50000" class="psp-form-input-small">
                            </div>
                            <div class="psp-form-group">
                                <label class="psp-form-label" for="rounding_value">رند به نزدیک‌ترین</label>
                                <input type="number" id="rounding_value" name="rounding_value" 
                                       value="1000" step="10" class="psp-form-input-small">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="psp-form-actions">
                    <button type="submit" class="button button-primary button-large psp-submit-btn">
                        <span class="dashicons dashicons-yes"></span> شروع فرآیند
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
    private function render_import_form() {
        ?>
        <div class="psp-bulk-card">
            <div class="psp-card-header">
                <h2><span class="dashicons dashicons-upload"></span>ورود قیمت از CSV</h2>
                <p>فایل CSV خود را برای به‌روزرسانی قیمت‌ها آپلود کنید</p>
            </div>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" 
                  enctype="multipart/form-data" class="psp-bulk-form">
                <?php wp_nonce_field(self::SLUG.'_import_nonce', self::SLUG.'_import_nonce_field'); ?>
                <input type="hidden" name="action" value="xx_bpb_import">
                
                <div class="psp-form-section">
                    <div class="psp-form-row">
                        <label class="psp-form-label" for="import_csv">فایل CSV</label>
                        <div class="psp-file-upload">
                            <input type="file" id="import_csv" name="import_csv" 
                                   accept=".csv, text/csv" required class="psp-file-input">
                            <label for="import_csv" class="psp-file-label">
                                <span class="dashicons dashicons-cloud-upload"></span>
                                <span class="psp-file-text">انتخاب فایل CSV</span>
                            </label>
                        </div>
                        <p class="psp-form-description">
                            فایل CSV باید شامل ستون‌های <code>id</code> و <code>regular_new</code> باشد
                        </p>
                    </div>
                </div>
                
                <div class="psp-form-actions">
                    <button type="submit" class="button button-secondary button-large psp-submit-btn">
                        <span class="dashicons dashicons-upload"></span> شروع آپلود و پردازش
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
    private function render_tools_section() {
        ?>
        <div class="psp-bulk-card psp-tools-card">
            <div class="psp-card-header">
                <h2><span class="dashicons dashicons-admin-tools"></span>ابزارهای کمکی</h2>
                <p>ابزارهای پیشرفته برای مدیریت و بهینه‌سازی سیستم</p>
            </div>
            
            <div class="psp-tool-item">
                <div class="psp-tool-header">
                    <h3><span class="dashicons dashicons-trash"></span>پاک کردن صف وظایف</h3>
                    <span class="psp-tool-badge psp-badge-warning">پیشرفته</span>
                </div>
                <p>اگر به دلیل اجرای نسخه‌های قدیمی این افزونه، تعداد بسیار زیادی وظیفه با نام 
                   <code>wc_update_product_lookup_tables</code> در صف دارید، می‌توانید با این دکمه همه آنها را یکجا حذف کنید.</p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" 
                      onsubmit="return confirm('آیا مطمئن هستید؟ این عمل تمام وظایف در انتظار ووکامرس برای آپدیت جداول را حذف می‌کند. این کار غیرقابل بازگشت است.');"
                      class="psp-tool-form">
                    <?php wp_nonce_field(self::SLUG.'_clear_queue_nonce', self::SLUG.'_clear_queue_nonce_field'); ?>
                    <input type="hidden" name="action" value="xx_bpb_clear_queue">
                    <button type="submit" class="button button-danger psp-tool-btn">
                        <span class="dashicons dashicons-trash"></span> حذف وظایف در انتظار
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
    private function render_status($params, $state, $show_log_link = false, $locked = false) {
        $job_type_label = (isset($params['job_type']) && $params['job_type'] === 'import') ? 'ورود از CSV' : 'افزایش قیمت';
        $total = isset($state['total']) ? max(0, intval($state['total'])) : 0;
        $pages = isset($state['pages']) ? max(1, intval($state['pages'])) : 1;
        $current = isset($state['current_page']) ? intval($state['current_page']) : 0;
        $processed = isset($state['processed']) ? intval($state['processed']) : 0;
        $run_id = isset($params['run_id']) ? sanitize_text_field($params['run_id']) : '-';
        $started = !empty($params['started_at']) ? date_i18n('Y-m-d H:i:s', intval($params['started_at'])) : '-';
        $percent = min(100, max(0, ($total > 0 ? floor(($processed / $total) * 100) : 0)));
        ?>
        <div class="psp-status-card">
            <div class="psp-status-header">
                <h2>
                    <span class="dashicons dashicons-<?php echo $locked ? 'update' : 'clock'; ?>"></span>
                    فرآیند <?php echo esc_html($job_type_label); ?>
                    <span class="psp-status-badge <?php echo $locked ? 'psp-badge-running' : 'psp-badge-queued'; ?>">
                        <?php echo $locked ? 'در حال اجرا…' : 'در صف/قابل اجرا'; ?>
                    </span>
                </h2>
            </div>
            
            <div class="psp-status-content">
                <div class="psp-progress-section">
                    <div class="psp-progress-bar">
                        <div class="psp-progress-fill" style="width: <?php echo $percent; ?>%"></div>
                    </div>
                    <div class="psp-progress-text">
                        <?php echo esc_html($processed); ?> از <?php echo esc_html($total); ?> 
                        (<?php echo $percent; ?>%)
                    </div>
                </div>
                
                <div class="psp-status-details">
                    <div class="psp-status-item">
                        <span class="psp-status-label">شناسه فرآیند:</span>
                        <code class="psp-status-value"><?php echo esc_html($run_id); ?></code>
                    </div>
                    <div class="psp-status-item">
                        <span class="psp-status-label">زمان شروع:</span>
                        <span class="psp-status-value"><?php echo esc_html($started); ?></span>
                    </div>
                    <div class="psp-status-item">
                        <span class="psp-status-label">بچ فعلی:</span>
                        <span class="psp-status-value"><?php echo esc_html($current . ' / ' . $pages); ?></span>
                    </div>
                </div>
                
                <?php if ($show_log_link): 
                    $log_url = ($params['job_type'] === 'import') ? $this->log_url($run_id, 'import-log-') : $this->log_url($run_id);
                    if ($log_url && file_exists($this->log_path($run_id, ($params['job_type'] === 'import' ? 'import-log-' : 'bpb-')))):
                ?>
                    <div class="psp-log-section">
                        <a target="_blank" href="<?php echo esc_url($log_url); ?>" class="psp-log-link">
                            <span class="dashicons dashicons-download"></span>
                            دانلود فایل لاگ: <?php echo esc_html(basename($log_url)); ?>
                        </a>
                    </div>
                <?php endif; endif; ?>
                
                <div class="psp-status-footer">
                    <p><span class="dashicons dashicons-info"></span> وظیفه در پس‌زمینه با Action Scheduler/WP-Cron اجرا می‌شود.</p>
                </div>
            </div>
        </div>
        <?php
    }
    public function notices() { if (!current_user_can('manage_woocommerce')) return; if (isset($_GET['xx_bpb_done'])) { $msg = sanitize_text_field(urldecode($_GET['xx_bpb_done'])); echo '<div class="notice notice-success"><p>'.esc_html($msg).'</p></div>'; } if (isset($_GET['xx_bpb_err'])) { $msg = sanitize_text_field(urldecode($_GET['xx_bpb_err'])); echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>'; } if (isset($_GET['queued'])) { echo '<div class="notice notice-info"><p>وظیفه با موفقیت صف شد و به زودی در پس‌زمینه اجرا می‌شود.</p></div>'; } if (isset($_GET['canceled'])) { echo '<div class="notice notice-warning"><p>فرآیند لغو شد.</p></div>'; } if (isset($_GET['cleared'])) { $count = (int)$_GET['cleared']; echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('%d pending tasks were successfully deleted.', 'xx-bpb'), $count) . '</p></div>'; } }
    private function nonce_ok($action, $field) { return isset($_POST[$field]) && wp_verify_nonce($_POST[$field], $action); }
    public function handle_clear_queue() { if (!current_user_can('manage_woocommerce')) wp_die('No access'); if (!$this->nonce_ok(self::SLUG.'_clear_queue_nonce', self::SLUG.'_clear_queue_nonce_field')) wp_die('Bad nonce'); global $wpdb; $table = $wpdb->prefix . 'actionscheduler_actions'; $count1 = $wpdb->query("DELETE FROM {$table} WHERE hook = 'wc_update_product_lookup_tables' AND status = 'pending'"); $count2 = $wpdb->query("DELETE FROM {$table} WHERE hook = 'wc_update_product_lookup_tables_column' AND status = 'pending'"); $total_deleted = $count1 + $count2; wp_redirect(admin_url('admin.php?page=' . self::SLUG . '&cleared=' . $total_deleted)); exit; }
    public function handle_start() { if (!current_user_can('manage_woocommerce')) wp_die('No access'); if (!$this->nonce_ok(self::SLUG.'_nonce', self::SLUG.'_nonce_field')) wp_die('Bad nonce'); if (get_transient(self::LOCK_KEY)) $this->redirect_err('یک فرآیند در حال اجراست. اول صبر/لغو کنید.'); $type=sanitize_text_field($_POST['type']??'fixed'); $value=floatval($_POST['value']??0); $apply_sale=!empty($_POST['apply_sale']); $cat_slugs=sanitize_text_field($_POST['category_slugs']??''); $range_rules=isset($_POST['range_rules'])?sanitize_textarea_field(wp_unslash($_POST['range_rules'])):''; $rules_combine=!empty($_POST['rules_combine'])?1:0; $enable_rounding=!empty($_POST['enable_rounding']); $rounding_threshold=(float)($_POST['rounding_threshold']??50000); $rounding_value=(int)($_POST['rounding_value']??1000); if ($value==0 && trim($range_rules)==='') $this->redirect_err('حداقل یکی از «افزایش پایه» یا «قوانین بازه‌ای» باید تنظیم شود.'); $run_id=wp_generate_uuid4(); $params=['job_type'=>'bump','type'=>($type==='percent')?'percent':'fixed','value'=>$value,'apply_sale'=>$apply_sale?1:0,'cats'=>$cat_slugs,'run_id'=>$run_id,'started_at'=>time(),'rules'=>$range_rules,'rules_mode'=>$rules_combine?'combine':'override','enable_rounding'=>$enable_rounding,'rounding_threshold'=>$rounding_threshold,'rounding_value'=>$rounding_value]; update_option(self::PARAMS_KEY,$params,false); $total=$this->count_products($cat_slugs); $pages=(int)ceil(max(1,$total)/self::PER_PAGE); $state=['total'=>$total,'pages'=>$pages,'current_page'=>0,'processed'=>0]; update_option(self::STATE_KEY,$state,false); $this->init_log($run_id); set_transient(self::LOCK_KEY,1,30*MINUTE_IN_SECONDS); $this->enqueue_job('xx_bpb_process',1,$run_id); wp_redirect(admin_url('admin.php?page='.self::SLUG.'&queued=1')); exit; }
    public function handle_cancel() { if (!current_user_can('manage_woocommerce')) wp_die('No access'); if (!$this->nonce_ok(self::SLUG.'_nonce', self::SLUG.'_nonce_field')) wp_die('Bad nonce'); $params=get_option(self::PARAMS_KEY); if ($params&&isset($params['job_type'])&&$params['job_type']==='import'&&!empty($params['file_path'])) { if (file_exists($params['file_path'])) wp_delete_file($params['file_path']); } delete_transient(self::LOCK_KEY); delete_option(self::PARAMS_KEY); delete_option(self::STATE_KEY); wp_redirect(admin_url('admin.php?page='.self::SLUG.'&canceled=1')); exit; }
    public function handle_run_now() { if (!current_user_can('manage_woocommerce')) wp_die('No access'); if (!$this->nonce_ok(self::SLUG.'_nonce', self::SLUG.'_nonce_field')) wp_die('Bad nonce'); $params=get_option(self::PARAMS_KEY); $state=get_option(self::STATE_KEY); if (!$params||!$state||(isset($params['job_type'])&&$params['job_type']==='import')) { $this->redirect_err('هیچ Job افزایش قیمتی در صف نیست.'); } $next_page=max(1,(int)$state['current_page']+1); $this->job_bump($next_page,$params['run_id'],true); wp_redirect(admin_url('admin.php?page='.self::SLUG.'&queued=1')); exit; }
    private function redirect_err($msg) { wp_redirect(admin_url('admin.php?page='.self::SLUG.'&xx_bpb_err='.urlencode($msg))); exit; }
    private function enqueue_job($hook, $page = 1, $run_id = '') { if (function_exists('as_enqueue_async_action')) { as_enqueue_async_action($hook,['page'=>(int)$page,'run'=>(string)$run_id],self::GROUP); } else { wp_schedule_single_event(time()+1,$hook,[(int)$page,(string)$run_id]); } }
    private function count_products($cat_slugs) { $taxq=[]; if(!empty($cat_slugs)) { $taxq[]=['taxonomy'=>'product_cat','field'=>'slug','terms'=>array_filter(array_map('trim',explode(',',$cat_slugs)))]; } $q=new WP_Query(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'no_found_rows'=>true]); return intval($q->post_count); }
    private function process_batch($params,$paged) { $taxq=[]; if(!empty($params['cats'])) { $taxq[]=['taxonomy'=>'product_cat','field'=>'slug','terms'=>array_filter(array_map('trim',explode(',',$params['cats'])))]; } $q=new WP_Query(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>self::PER_PAGE,'paged'=>$paged,'tax_query'=>$taxq,'orderby'=>'ID','order'=>'ASC']); $updated=0; $skipped=0; $d=function_exists('wc_get_price_decimals')?wc_get_price_decimals():2; $rows=[]; foreach($q->posts as $pid) { $product=wc_get_product($pid); if(!$product){$skipped++;continue;} try{ if($product->is_type('variable')) { $children=$product->get_children(); foreach($children as $vid) { $v=wc_get_product($vid); if(!$v){$skipped++;continue;} $reg_old=$v->get_regular_price('edit'); $sale_old=$v->get_sale_price('edit'); list($reg_new,$msg_r)=$this->apply_adjustment($reg_old,$params,$d); if($reg_old!==''){$v->set_regular_price($reg_new);} $sale_new=$sale_old; $msg_s=''; if(!empty($params['apply_sale'])) { list($sale_new,$msg_s)=$this->apply_adjustment($sale_old,$params,$d); if($sale_old!=='')$v->set_sale_price($sale_new); } if ($sale_new !== '' && is_numeric($sale_new) && (float)$sale_new >= 0 && (float)$reg_new > 0 && (float)$sale_new < (float)$reg_new) { $v->set_price($sale_new); } else { $v->set_price($reg_new); } $v->save(); wc_delete_product_transients($v->get_id()); $updated++; $rows[]=$this->row(time(),$paged,'variation',$vid,$pid,$reg_old,$reg_new,$sale_old,$sale_new,'ok',trim($msg_r.($msg_s?' | '.$msg_s:''))); } if(class_exists('WC_Product_Variable'))WC_Product_Variable::sync($product,false); $product->save(); wc_delete_product_transients($product->get_id()); }elseif($product->is_type('simple')) { $reg_old=$product->get_regular_price('edit'); $sale_old=$product->get_sale_price('edit'); list($reg_new,$msg_r)=$this->apply_adjustment($reg_old,$params,$d); if($reg_old!==''){$product->set_regular_price($reg_new);} $sale_new=$sale_old; $msg_s=''; if(!empty($params['apply_sale'])) { list($sale_new,$msg_s)=$this->apply_adjustment($sale_old,$params,$d); if($sale_old!=='')$product->set_sale_price($sale_new); } if ($sale_new !== '' && is_numeric($sale_new) && (float)$sale_new >= 0 && (float)$reg_new > 0 && (float)$sale_new < (float)$reg_new) { $product->set_price($sale_new); } else { $product->set_price($reg_new); } $product->save(); wc_delete_product_transients($product->get_id()); $updated++; $rows[]=$this->row(time(),$paged,'product',$pid,'',$reg_old,$reg_new,$sale_old,$sale_new,'ok',trim($msg_r.($msg_s?' | '.$msg_s:''))); }else{ $skipped++; $rows[]=$this->row(time(),$paged,'product',$pid,'','','','','','skipped','unsupported_type: '.$product->get_type()); } }catch(\Throwable $e) { $skipped++; $rows[]=$this->row(time(),$paged,'product',$pid,'','','','','','error',$e->getMessage()); } } if(!empty($rows))$this->log_rows(get_option(self::PARAMS_KEY)['run_id']??'',$rows); $finished=($q->post_count<self::PER_PAGE); return compact('finished','updated','skipped'); }
    private function apply_adjustment($old,$params,$decimals) { if($old===''||$old===null)return['','empty-price']; $oldf=(float)$old; $final_new=0; $msg=''; $base_new=$oldf; if(!($params['type']==='fixed'&&(float)$params['value']===0)) { if($params['type']==='percent') { $base_new=$oldf*(1+((float)$params['value']/100)); }else{ $base_new=$oldf+(float)$params['value']; } } $base_msg='base:'.$params['type'].'='.$params['value']; $rules=$this->parse_rules($params['rules']??''); $rule_applied=null; foreach($rules as $r) { if($oldf>=$r['min']&&$oldf<=$r['max']){$rule_applied=$r;break;} } if($rule_applied) { $ref=($params['rules_mode']??'override')==='combine'?$base_new:$oldf; $final_new=$rule_applied['percent']?$ref*(1+($rule_applied['delta']/100.0)):$ref+$rule_applied['delta']; $msg='rule:'.$rule_applied['raw'].' ('.($params['rules_mode']??'override').')'; }else{ $final_new=$base_new; $msg=$base_msg; } if(!empty($params['enable_rounding'])) { $threshold=(float)($params['rounding_threshold']??50000); $value=(int)($_POST['rounding_value']??1000); if($value>0&&$final_new>0&&$final_new<$threshold) { $rounded_price=ceil($final_new/$value)*$value; if($rounded_price!=$final_new) { $final_new=$rounded_price; $msg.=' | rounded'; } } } if($final_new<0)$final_new=0; $final_new=number_format((float)$final_new,$decimals,'.',''); return[$final_new,$msg]; }
    private function fa_to_en($s) { $find=['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','،',',',' ']; $repl=['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','','','','']; return str_replace($find,$repl,(string)$s); }
    private function to_float($s) { $s=$this->fa_to_en($s); $s=str_replace('%','',$s); return(float)$s; }
    private function parse_rules($rules_str) { $rules=[]; if(!$rules_str)return $rules; $lines=preg_split('/\r\n|\r|\n/',$rules_str); foreach($lines as $line) { $line=trim($line); if($line===''||(isset($line[0])&&$line[0]==='#'))continue; if(strpos($line,':')===false)continue; list($range,$deltaRaw)=array_map('trim',explode(':',$line,2)); $tag=''; if(strpos($deltaRaw,'#')!==false){list($deltaRaw,$tag)=array_map('trim',explode('#',$deltaRaw,2));} $parts=preg_split('/\s*-\s*/',$range); if(count($parts)!==2)continue; $min=$this->to_float($parts[0]); $max=$this->to_float($parts[1]); if($max<$min){$tmp=$min;$min=$max;$max=$tmp;} $dtrim=trim($deltaRaw); $is_percent=substr($dtrim,-1)==='%'; $delta=$this->to_float($dtrim); $rules[]=['min'=>$min,'max'=>$max,'percent'=>$is_percent,'delta'=>$delta,'raw'=>$line,'tag'=>$tag]; } return $rules; }
    private function uploads_base() { $u=wp_upload_dir(); return['dir'=>trailingslashit($u['basedir']).self::LOG_DIR.'/','url'=>trailingslashit($u['baseurl']).self::LOG_DIR.'/',]; }
    private function init_log($run_id) { $b=$this->uploads_base(); if(!wp_mkdir_p($b['dir']))return; $path=$this->log_path($run_id); if(!file_exists($path)) { $header="timestamp,page,entity,id,parent_id,regular_old,regular_new,sale_old,sale_new,status,message\n"; file_put_contents($path,$header,LOCK_EX); update_option(self::LASTLOG_KEY,['run'=>$run_id,'url'=>$this->log_url($run_id)],false); } }
    private function row_import($id, $status, $reg_old, $reg_new, $message) { $ts = time(); $csv = [(string)$ts, (string)$id, (string)$status, (string)$reg_old, (string)$reg_new, str_replace(["\n","\r",'"'],[' ',' ', "'"], (string)$message)]; $fh = fopen('php://temp','r+'); fputcsv($fh,$csv); rewind($fh); $line = stream_get_contents($fh); fclose($fh); return $line; }
    private function init_import_log($run_id) { $b = $this->uploads_base(); if (!wp_mkdir_p($b['dir'])) return; $path = $this->log_path($run_id, 'import-log-'); if (!file_exists($path)) { $header = "timestamp,product_id,status,regular_price_before,regular_price_from_csv,message\n"; file_put_contents($path, $header, LOCK_EX); } }
    private function log_rows($run_id, $rows, $prefix = 'bpb-') { if (empty($rows)) return; $path = $this->log_path($run_id, $prefix); if (file_exists($path) && is_writable($path)) { file_put_contents($path, implode("", $rows), FILE_APPEND | LOCK_EX); } }
    private function log_path($run_id, $prefix = 'bpb-') { $base = $this->uploads_base(); return $base['dir'] . $prefix . sanitize_file_name($run_id) . '.csv'; }
    private function log_url($run_id, $prefix = 'bpb-') { $base = $this->uploads_base(); return $base['url'] . $prefix . sanitize_file_name($run_id) . '.csv'; }
    private function row($ts,$page,$entity,$id,$parent_id,$reg_old,$reg_new,$sale_old,$sale_new,$status,$message) { $csv=[(string)$ts,(string)$page,(string)$entity,(string)$id,(string)$parent_id,($reg_old===null?'':(string)$reg_old),($reg_new===null?'':(string)$reg_new),($sale_old===null?'':(string)$sale_old),($sale_new===null?'':(string)$sale_new),(string)$status,str_replace(["\n","\r",'"'],[' ',' ',"'"],(string)$message)]; $fh=fopen('php://temp','r+');fputcsv($fh,$csv);rewind($fh);$line=stream_get_contents($fh);fclose($fh);return $line; }
    public function admin_bar_status($wp_admin_bar) { if(!current_user_can('manage_woocommerce'))return; $params=get_option(self::PARAMS_KEY); $state=get_option(self::STATE_KEY); if(!$params||!$state)return; $pages=max(1,intval($state['pages']??1)); $current=intval($state['current_page']??0); $percent=min(100,max(0,floor(($current/$pages)*100))); $job_type_label=(isset($params['job_type'])&&$params['job_type']==='import')?'Import':'Price Bump'; $title=$job_type_label.': '.$percent.'%'; $wp_admin_bar->add_node(['id'=>'xx-bpb-status','title'=>esc_html($title),'href'=>admin_url('admin.php?page='.self::SLUG),'meta'=>['class'=>'xx-bpb-status']]); }
}

// Don't auto-instantiate - let the main plugin handle it
// new XX_Woo_Bulk_Price_Bump();
