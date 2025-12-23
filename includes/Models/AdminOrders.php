<?php
if (!defined('ABSPATH')) { exit; }

// Підключаємо стандартний клас таблиць WP
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class SVB_Orders_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'svb_order',
            'plural'   => 'svb_orders',
            'ajax'     => false
        ]);
    }

    /**
     * 1. Реєструємо масові дії (Bulk Actions)
     */
    public function get_bulk_actions() {
        return [
            'delete' => 'Видалити'
        ];
    }

    /**
     * 2. Обробка дій (Видалення окремих, масове видалення та очищення сміття)
     */
    public function process_actions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'svb_orders';

        // --- А. Обробка видалення (поштучно або масово) ---
        if ('delete' === $this->current_action()) {
            $ids = isset($_REQUEST['svb_order_ids']) ? $_REQUEST['svb_order_ids'] : [];
            
            if (is_array($ids) && !empty($ids)) {
                $ids = array_map('intval', $ids);
                $ids_str = implode(',', $ids);
                
                // Видаляємо за первинним ключем `id`
                $wpdb->query("DELETE FROM $table_name WHERE id IN ($ids_str)");
                
                echo '<div class="notice notice-success is-dismissible"><p>Видалено замовлень: <strong>' . count($ids) . '</strong>.</p></div>';
            }
        }

        // --- Б. Обробка кнопки "Очистити пусті" (Global Cleanup) ---
        if (!empty($_REQUEST['svb_cleanup_action']) && current_user_can('manage_options')) {
            $candidates = $wpdb->get_results("
                SELECT order_id, payment, payment_status 
                FROM $table_name 
                WHERE payment_status NOT IN ('paid', 'success') 
                   OR payment_status IS NULL
            ", ARRAY_A);

            $ids_to_delete = [];

            foreach ($candidates as $row) {
                $payment = json_decode($row['payment'] ?? '{}', true);
                
                $jsonStatus = isset($payment['status']) ? strtolower($payment['status']) : '';
                if (in_array($jsonStatus, ['paid', 'success'])) continue;

                $amount = isset($payment['amount']) ? intval($payment['amount']) : 0;
                if ($amount > 0) continue;

                $ids_to_delete[] = intval($row['order_id']);
            }

            $deleted_count = 0;
            if (!empty($ids_to_delete)) {
                $chunks = array_chunk($ids_to_delete, 100);
                foreach ($chunks as $chunk) {
                    $ids_str = implode(',', $chunk);
                    $deleted_count += $wpdb->query("DELETE FROM $table_name WHERE order_id IN ($ids_str)");
                }
            }

            if ($deleted_count > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>Автоматичне очищення: видалено <strong>' . $deleted_count . '</strong> пустих.</p></div>';
            } else {
                echo '<div class="notice notice-info is-dismissible"><p>Сміття не знайдено.</p></div>';
            }
        }
    }

    /**
     * Панель фільтрів + Кнопка очищення
     */
    protected function extra_tablenav($which) {
        if ($which !== 'top') return;

        $selected = isset($_REQUEST['filter_status']) ? sanitize_text_field($_REQUEST['filter_status']) : '';
        ?>
        <div class="alignleft actions">
            <select name="filter_status">
                <option value=""><?php _e('Всі замовлення', 'santa'); ?></option>
                <option value="paid" <?php selected($selected, 'paid'); ?>>✅ Оплачені</option>
                <option value="pending" <?php selected($selected, 'pending'); ?>>⏳ Очікують</option>
                <option value="failed" <?php selected($selected, 'failed'); ?>>❌ Помилки</option>
            </select>
            <?php submit_button(__('Фільтрувати', 'santa'), 'button', 'filter_action', false); ?>

            <span style="margin-left: 10px; border-left: 1px solid #ccc; padding-left: 10px;"></span>
            <button type="submit" name="svb_cleanup_action" value="1" class="button button-link-delete" style="color: #a00; border: 1px solid #a00; background:#fff;" onclick="return confirm('Видалити ВСІ пусті неоплачені замовлення (0 грн)?')">
                🗑 Очистити пусті (0 грн)
            </button>
        </div>
        <?php
    }

    public function get_columns() {
        return [
            'cb'             => '<input type="checkbox" />',
            'order_id_col'   => 'ID', 
            'created_at'     => 'Дата',
            'payment_status' => 'Статус',
            'price'          => 'Сума',
            'child_count'    => 'Дітей',
            'info_combined'  => 'Дані (Ім\'я / Email)',
            'vid_url'        => 'Відео',
            'fb_event'       => 'FB Pixel',
            'site_url'       => 'Посилання'
        ];
    }

    public function get_sortable_columns() {
        return [
            'order_id_col'   => ['order_id', false],
            'created_at'     => ['created_at', true], 
            'child_count'    => ['child_count', false],
            'price'          => ['payment', false] 
        ];
    }

    protected function column_order_id_col($item) {
        $delete_url = add_query_arg([
    'page'   => $_REQUEST['page'],
    'action' => 'delete',
    'svb_order_ids' => [$item['id']]
]);

        $actions = [
            'delete' => sprintf(
                '<a href="%s" style="color:#a00" onclick="return confirm(\'Видалити замовлення #%s?\')">Видалити</a>', 
                esc_url($delete_url), 
                $item['order_id']
            )
        ];

        return sprintf('<strong>#%s</strong> %s', $item['order_id'], $this->row_actions($actions));
    }

    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'created_at':
                $date = strtotime($item['created_at']);
                return date('d.m.Y', $date) . '<br><small style="color:#888">' . date('H:i', $date) . '</small>';

            case 'payment_status':
                $status = $this->get_deep_status($item);
                $color = '#777'; $bg = '#eee'; $label = ucfirst($status);

                if (in_array($status, ['paid', 'success'])) {
                    $color = '#006909'; $bg = '#dff0d8'; $label = 'Оплачено';
                } elseif (in_array($status, ['failed', 'failure', 'canceled'])) {
                    $color = '#a00'; $bg = '#f2dede'; $label = 'Скасовано';
                } elseif (in_array($status, ['pending', 'processing'])) {
                    $color = '#d68100'; $bg = '#fcf8e3'; $label = 'Очікує';
                }
                
                return sprintf('<span style="background:%s; color:%s; padding: 4px 8px; border-radius: 4px; font-weight:bold; font-size:11px; display:inline-block;">%s</span>', $bg, $color, esc_html($label));

            case 'price':
                return $this->get_smart_price($item);

            case 'child_count':
                return '<div style="text-align:center; font-size:14px; font-weight:bold;">' . esc_html($item['child_count']) . '</div>';

            case 'info_combined':
                $childName = $this->get_child_names($item);
                $parentName = !empty($item['customer_name']) ? $item['customer_name'] : '';
                $email = !empty($item['customer_email']) ? $item['customer_email'] : '';
                
                if (empty($email)) {
                    $payment = json_decode($item['payment'] ?? '{}', true);
                    if (!empty($payment['sender_email'])) $email = $payment['sender_email']; 
                }

                $html = '';
                if ($childName && $childName !== '—') {
                    $html .= '<div>👶 <strong>' . esc_html($childName) . '</strong></div>';
                } 
                if ($parentName) {
                    $html .= '<div style="color:#555; font-size:12px;">👤 ' . esc_html($parentName) . '</div>';
                } elseif (!$childName || $childName === '—') {
                    $html .= '<span style="color:#ccc;">(Ім\'я не вказано)</span>';
                }
                if ($email) {
                    $html .= '<div style="margin-top:4px;"><a href="mailto:'.esc_attr($email).'" style="text-decoration:none;">✉️ ' . esc_html($email) . '</a></div>';
                } else {
                    $html .= '<div style="margin-top:4px; color:#ccc; font-size:11px;">✉️ (Email не збережено)</div>';
                }
                return $html;

            case 'vid_url':
                $url = $this->get_video_url($item);
                if ($url) {
                    return '<a href="' . esc_url($url) . '" class="button button-primary" target="_blank" style="font-size:11px;">🎥 Скачати</a>';
                }
                $st = $this->get_deep_status($item);
                if (in_array($st, ['paid', 'success'])) {
                    return '<span style="color:orange; font-size:11px;">Оплачено (генерується)</span>';
                }
                return '<span style="color:#eee">—</span>';

            case 'fb_event':
                return '<span style="color:#999; font-family:monospace; font-size:11px;">ADKate_Post</span>'; 

            case 'site_url':
                return '<a href="'.home_url().'" target="_blank" style="font-size:11px;">'.str_replace(['https://','http://'], '', home_url()).'</a>';

            default:
                return print_r($item, true);
        }
    }

protected function column_cb($item) {
    return sprintf('<input type="checkbox" name="svb_order_ids[]" value="%s" />', $item['id']);
}

    // --- Helpers ---

    private function get_deep_status($item) {
        if (!empty($item['payment_status'])) return strtolower($item['payment_status']);
        $payment = json_decode($item['payment'] ?? '{}', true);
        return isset($payment['status']) ? strtolower($payment['status']) : 'unpaid';
    }

    private function get_smart_price($item) {
        $payment = json_decode($item['payment'] ?? '{}', true);
        if (isset($payment['amount']) && $payment['amount'] > 0) {
            return '<strong>' . ($payment['amount'] / 100) . ' грн</strong>';
        } 
        $count = intval($item['child_count']);
        $estimated = ($count > 0) ? 249 : 0; 
        return '<span style="color:#999; border-bottom:1px dotted #ccc;" title="Не оплачено">' . $estimated . ' грн (план)</span>';
    }

    private function get_child_names($item) {
        $voiceRaw = $item['voice'] ?? '{}';
        $voice = json_decode($voiceRaw, true);
        $names = [];
        if (is_array($voice)) {
            foreach (['name', 'name2', 'name3'] as $k) {
                if (!empty($voice[$k])) {
                    $path = is_array($voice[$k]) ? ($voice[$k]['file'] ?? '') : $voice[$k];
                    if (is_string($path)) {
                        $cleanName = ucfirst(preg_replace('/\d/', '', pathinfo(basename($path), PATHINFO_FILENAME)));
                        if ($cleanName) $names[] = $cleanName;
                    }
                }
            }
        }
        return !empty($names) ? implode(', ', $names) : '—';
    }

    private function get_video_url($item) {
        $resRaw = $item['result'] ?? '{}';
        $result = json_decode($resRaw, true);
        if (is_array($result)) {
            if (!empty($result['download_url'])) return $result['download_url'];
            if (!empty($result['video_url'])) return $result['video_url'];
        }
        $st = $this->get_deep_status($item);
        if (in_array($st, ['paid', 'success']) && !empty($item['public_token']) && function_exists('svb_build_download_url')) {
            return svb_build_download_url($item['order_id'], $item['public_token']);
        }
        return '';
    }

    public function prepare_items() {
        // 1. Обробка дій (видалення/очищення) перед запитом
        $this->process_actions();

        global $wpdb;
        $table_name = $wpdb->prefix . 'svb_orders';
        $per_page = 20;
        
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $where_clauses = [];

        // --- ВАЖЛИВО: Глобальний фільтр (Приховуємо замовлення з сумою 0) ---
        // Умова: в JSON payment має бути ключ "amount", і він не повинен бути "amount":0
        $where_clauses[] = "(payment LIKE '%\"amount\":%' AND payment NOT LIKE '%\"amount\":0%')";
        // ------------------------------------------------------------------

        // 2. Пошук
        if (!empty($_REQUEST['s'])) {
            $search = esc_sql($_REQUEST['s']);
            $where_clauses[] = "(order_id LIKE '%{$search}%' OR customer_email LIKE '%{$search}%' OR customer_name LIKE '%{$search}%' OR payment LIKE '%{$search}%')";
        }

        // 3. Фільтр статусу
        if (!empty($_REQUEST['filter_status'])) {
            $f_status = sanitize_text_field($_REQUEST['filter_status']);
            if ($f_status === 'paid') {
                $where_clauses[] = "(payment_status IN ('paid', 'success') OR payment LIKE '%\"status\":\"paid\"%' OR payment LIKE '%\"status\":\"success\"%')";
            } elseif ($f_status === 'pending') {
                $where_clauses[] = "(payment_status IN ('pending', 'processing', 'unpaid') OR payment LIKE '%\"status\":\"pending\"%')";
            } elseif ($f_status === 'failed') {
                $where_clauses[] = "(payment_status IN ('failed', 'failure', 'canceled') OR payment LIKE '%\"status\":\"failed\"%')";
            }
        }

        $sql = "SELECT * FROM $table_name";
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        $orderby = (!empty($_REQUEST['orderby'])) ? esc_sql($_REQUEST['orderby']) : 'created_at';
        $order   = (!empty($_REQUEST['order']))   ? esc_sql($_REQUEST['order'])   : 'DESC';
        
        // Сортування по ID (якщо сортують по кастомній колонці ID)
        if ($orderby === 'order_id_col') $orderby = 'order_id';
        if ($orderby === 'payment') $sql .= " ORDER BY order_id $order"; 
        else $sql .= " ORDER BY $orderby $order";

        $total_items = $wpdb->query($sql);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        $sql .= " LIMIT $offset, $per_page";

        $this->items = $wpdb->get_results($sql, ARRAY_A);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }
}

// Рендер
function svb_render_orders_page() {
    $table = new SVB_Orders_Table();
    $table->prepare_items();
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Відстеження замовлень (Santa)</h1>
        <form method="get">
            <input type="hidden" name="page" value="svb-orders" />
            <?php $table->search_box('Пошук замовлення', 'search_id'); ?>
            <?php $table->display(); ?>
        </form>
    </div>
    <style>
        .wp-list-table th#order_id_col { width: 90px; }
        .wp-list-table th#created_at { width: 100px; }
        .wp-list-table th#child_count { width: 60px; text-align:center; }
        .wp-list-table td.child_count { text-align:center; }
        .wp-list-table th#price { width: 100px; }
        .wp-list-table .column-info_combined { width: 25%; }
        .wp-list-table .column-payment_status { width: 100px; text-align:center; }
        .alignleft.actions select {
            float: left;
            margin-right: 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            padding: 0 24px 0 8px;
            line-height: 2;
            min-width: 150px;
        }
        /* Стиль для кнопки очищення */
        .button.button-link-delete:hover {
            background-color: #a00 !important;
            color: #fff !important;
            border-color: #a00 !important;
        }
    </style>
    <?php
}

add_action('admin_menu', 'svb_register_orders_menu');
function svb_register_orders_menu() {
    add_menu_page(
        'Santa Orders', 'Tracking', 'manage_options', 'svb-orders', 'svb_render_orders_page', 'dashicons-chart-line', 6
    );
}