<?php
/**
 * Plugin Name: FluentCart Product Exporter (CSV)
 * Description: Exports all FluentCart products, variations and bundles to a CSV that matches FluentCart's Bulk Product Import format. Reads directly from the fct_* tables (read-only).
 * Version: 2.2.0
 * Author: Akhil
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

class FCT_Product_Exporter {

    const CAP = 'manage_options';

    /**
     * Exact importer header order (from the FluentCart sample import CSV).
     */
    private $headers = [
        'ID', 'Type', 'SKU', 'Name', 'Published', 'Short description', 'description',
        'Categories', 'Images', 'Parent', 'Regular price', 'Sale price',
        'Attribute 1 name', 'Attribute 1 value(s)',
        'Payment Type', 'Subscription Interval', 'Trial Days', 'Installment',
        'Installment Count', 'Setup Fee', 'Setup Fee Name', 'Setup Fee Amount',
        // Extra non-importer columns kept at the end for your reference only.
        'Bundle (FYI only)',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_fct_export_products', [$this, 'handle_export']);
    }

    public function register_menu() {
        add_management_page(
            'FluentCart Product Export',
            'FC Product Export',
            self::CAP,
            'fct-product-export',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can(self::CAP)) {
            wp_die('You do not have permission to access this page.');
        }
        global $wpdb;
        $variations_table = $wpdb->prefix . 'fct_product_variations';
        $product_count = 0;
        $variation_count = 0;
        if ($this->table_exists($variations_table)) {
            $variation_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$variations_table}`");
            $product_count   = (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM `{$variations_table}`");
        }
        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=fct_export_products'),
            'fct_export_products',
            'fct_nonce'
        );
        ?>
        <div class="wrap">
            <h1>FluentCart Product Export</h1>
            <p>Exports products in FluentCart's <strong>Bulk Product Import</strong> CSV format (WooCommerce-style parent/child rows).</p>
            <p><strong><?php echo esc_html($product_count); ?></strong> product(s), <strong><?php echo esc_html($variation_count); ?></strong> variation row(s) found.</p>
            <p>
                <a href="<?php echo esc_url($export_url); ?>" class="button button-primary">Download Products CSV</a>
            </p>
            <hr>
            <h2>How products are exported</h2>
            <ul style="list-style:disc;margin-left:20px;">
                <li><strong>Simple products</strong> &rarr; one row (type <code>simple</code>) with price + payment data.</li>
                <li><strong>Variable products</strong> &rarr; one parent row (type <code>variable</code>, no price) + one <code>variation</code> child row per variation, linked by the parent SKU in the <code>Parent</code> column.</li>
                <li><strong>Subscriptions</strong> &rarr; Payment Type / Interval / Trial / Installment / Setup Fee columns are filled from each variation's stored subscription data.</li>
                <li><strong>Bundles</strong> &rarr; exported as a normal product; the bundled child SKUs are listed in the last "Bundle (FYI only)" column so nothing is lost. The importer ignores that extra column &mdash; rebuild the bundle link manually after import.</li>
            </ul>
            <p style="color:#666;">Prices are converted from FluentCart's internal cents storage to decimal.</p>
        </div>
        <?php
    }

    public function handle_export() {
        if (!current_user_can(self::CAP)) {
            wp_die('Permission denied.');
        }
        if (!isset($_GET['fct_nonce']) || !wp_verify_nonce($_GET['fct_nonce'], 'fct_export_products')) {
            wp_die('Security check failed.');
        }

        global $wpdb;
        $variations_table = $wpdb->prefix . 'fct_product_variations';
        $details_table    = $wpdb->prefix . 'fct_product_details';
        $meta_table       = $wpdb->prefix . 'fct_product_meta';
        $posts_table      = $wpdb->posts;

        if (!$this->table_exists($variations_table)) {
            wp_die('FluentCart variations table not found (' . esc_html($variations_table) . ').');
        }
        $has_details = $this->table_exists($details_table);

        // Fetch all variations + parent post info, grouped by product.
        $select = "v.*, p.post_title AS product_title, p.post_status AS product_status,
                   p.post_excerpt AS product_excerpt, p.post_content AS product_content";
        $from   = "`{$variations_table}` v LEFT JOIN `{$posts_table}` p ON v.post_id = p.ID";
        if ($has_details) {
            $select .= ", d.variation_type AS variation_type";
            $from   .= " LEFT JOIN `{$details_table}` d ON v.post_id = d.post_id";
        }
        $sql  = "SELECT {$select} FROM {$from} ORDER BY v.post_id ASC, v.serial_index ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($rows === null) {
            wp_die('Query failed: ' . esc_html($wpdb->last_error));
        }

        // Group variations by product (post_id).
        $products = [];
        foreach ($rows as $r) {
            $pid = (int) $r['post_id'];
            if (!isset($products[$pid])) {
                $products[$pid] = [
                    'post_id'    => $pid,
                    'title'      => $r['product_title'],
                    'status'     => $r['product_status'],
                    'excerpt'    => $r['product_excerpt'],
                    'content'    => $r['product_content'],
                    'variations' => [],
                ];
            }
            $products[$pid]['variations'][] = $r;
        }

        $featured_cache = [];

        $filename = 'fluentcart-products-' . date('Y-m-d-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        fputcsv($out, $this->headers);

        foreach ($products as $pid => $product) {
            $variations = $product['variations'];
            $published  = ($product['status'] === 'publish') ? 1 : 0;
            $categories = $this->get_categories($pid);
            $images     = $this->get_product_images($wpdb, $meta_table, $pid, $featured_cache);

            $is_variable = count($variations) > 1;

            if (!$is_variable) {
                // SIMPLE product = single row.
                $v = $variations[0];
                $row = $this->base_row();
                $row['Type']             = 'simple';
                $row['SKU']              = $this->sku_for($v, $product, 0);
                $row['Name']             = $product['title'];
                $row['Published']        = $published;
                $row['Short description'] = $this->clean_text($product['excerpt']);
                $row['description']      = $this->clean_text($product['content']);
                $row['Categories']       = $categories;
                $row['Images']           = $images;
                $this->apply_pricing($row, $v);
                $this->apply_subscription($row, $v);
                $row['Bundle (FYI only)'] = $this->bundle_info($v);
                $this->write_row($out, $row);
            } else {
                // VARIABLE product = parent row + variation child rows.
                $parent_sku = $this->parent_sku_for($product, $pid);

                $parent = $this->base_row();
                $parent['Type']             = 'variable';
                $parent['SKU']              = $parent_sku;
                $parent['Name']             = $product['title'];
                $parent['Published']        = $published;
                $parent['Short description'] = $this->clean_text($product['excerpt']);
                $parent['description']      = $this->clean_text($product['content']);
                $parent['Categories']       = $categories;
                $parent['Images']           = $images;
                $this->write_row($out, $parent);

                foreach ($variations as $idx => $v) {
                    $child = $this->base_row();
                    $child['Type']      = 'variation';
                    $child['SKU']       = $this->sku_for($v, $product, $idx);
                    $child['Published'] = $published;
                    $child['Parent']    = $parent_sku;
                    // Variation image (own thumbnail if set).
                    $child['Images']    = $this->variation_image($wpdb, $meta_table, $v, $featured_cache, $pid);
                    $this->apply_pricing($child, $v);
                    // Attribute name/value for this variation.
                    list($attr_name, $attr_val) = $this->get_attribute($wpdb, (int) $v['id'], $v, $product['title']);
                    $child['Attribute 1 name']      = $attr_name;
                    $child['Attribute 1 value(s)']  = $attr_val;
                    $this->apply_subscription($child, $v);
                    $child['Bundle (FYI only)'] = $this->bundle_info($v);
                    $this->write_row($out, $child);
                }
            }
        }

        fclose($out);
        exit;
    }

    /* ---------- helpers ---------- */

    private function base_row() {
        $row = [];
        foreach ($this->headers as $h) {
            $row[$h] = '';
        }
        return $row;
    }

    private function write_row($out, $row) {
        $line = [];
        foreach ($this->headers as $h) {
            $line[] = isset($row[$h]) ? $row[$h] : '';
        }
        fputcsv($out, $line);
    }

    private function apply_pricing(&$row, $v) {
        $price   = isset($v['item_price']) ? $this->to_decimal($v['item_price']) : '';
        $compare = isset($v['compare_price']) ? $this->to_decimal($v['compare_price']) : '';
        // FluentCart: compare_price is the "was" price. If compare > item, item is the sale price.
        if ($compare !== '' && (float) $compare > 0 && (float) $compare > (float) $price) {
            $row['Regular price'] = $compare;
            $row['Sale price']    = $price;
        } else {
            $row['Regular price'] = $price;
            $row['Sale price']    = '';
        }
    }

    private function apply_subscription(&$row, $v) {
        $type = isset($v['payment_type']) ? $v['payment_type'] : 'onetime';
        if ($type !== 'subscription') {
            $row['Payment Type'] = 'onetime';
            return;
        }
        $row['Payment Type'] = 'subscription';
        $info = $this->decode_other_info($v);

        $row['Subscription Interval'] = $this->arr($info, 'repeat_interval', '');
        $row['Trial Days']            = $this->arr($info, 'trial_days', '');
        $installment = $this->arr($info, 'installment', '');
        if ($installment === 'yes') {
            $row['Installment']       = 'yes';
            $row['Installment Count'] = $this->arr($info, 'times', '');
        }
        // Setup / signup fee.
        $fee_name = $this->arr($info, 'signup_fee_name', '');
        $fee_amt  = $this->arr($info, 'signup_fee', '');
        $manage_fee = $this->arr($info, 'manage_setup_fee', 'no');
        if ($manage_fee === 'yes' || ($fee_amt !== '' && (float) $fee_amt > 0)) {
            $row['Setup Fee']        = 'yes';
            $row['Setup Fee Name']   = $fee_name;
            // signup_fee may be in cents; convert if it looks like an integer cents value.
            $row['Setup Fee Amount'] = is_numeric($fee_amt) ? $this->to_decimal($fee_amt) : $fee_amt;
        }
    }

    private function get_attribute($wpdb, $variation_id, $v, $product_title = '') {
        // Discover attribute table names (vary across versions).
        $rel_table   = $this->first_existing(['fct_atts_relations', 'fct_attribute_relations', 'fct_product_attribute_relations']);
        $group_table = $this->first_existing(['fct_atts_groups', 'fct_attribute_groups']);
        $term_table  = $this->first_existing(['fct_atts_terms', 'fct_attribute_terms']);

        if ($rel_table && $group_table && $term_table) {
            $sql = $wpdb->prepare(
                "SELECT g.title AS group_title, t.title AS term_title
                 FROM `{$rel_table}` r
                 LEFT JOIN `{$group_table}` g ON r.group_id = g.id
                 LEFT JOIN `{$term_table}` t ON r.term_id = t.id
                 WHERE r.object_id = %d
                 LIMIT 1",
                $variation_id
            );
            $res = $wpdb->get_row($sql, ARRAY_A);
            if ($res && !empty($res['group_title'])) {
                return [$res['group_title'], $res['term_title']];
            }
        }

        // Fallback: this store doesn't use the attribute system. The differentiator
        // lives in variation_title, usually as "{Product Name} - {Format}".
        // Strip the product-name prefix so the value is just "Paperback", "E-book (epub)", etc.
        $title = isset($v['variation_title']) ? trim((string) $v['variation_title']) : '';
        if ($title === '') {
            return ['', ''];
        }
        $value = $this->strip_product_prefix($title, $product_title);
        return ['Format', $value];
    }

    /**
     * Remove a leading "{Product Title} - " (or "{Product Title} – ") prefix
     * from a variation title, leaving just the distinguishing part.
     * Falls back to the original title if no clean prefix is found.
     */
    private function strip_product_prefix($variation_title, $product_title) {
        $product_title = trim((string) $product_title);
        if ($product_title === '') {
            return $variation_title;
        }
        // Match "{product} - rest", tolerating hyphen, en dash, or em dash and spacing.
        $pattern = '/^' . preg_quote($product_title, '/') . '\s*[-\x{2013}\x{2014}]\s*(.+)$/u';
        if (preg_match($pattern, $variation_title, $m)) {
            return trim($m[1]);
        }
        // If the title simply starts with the product name (no separator), trim it.
        if (stripos($variation_title, $product_title) === 0) {
            $rest = trim(substr($variation_title, strlen($product_title)));
            $rest = ltrim($rest, " -\x{2013}\x{2014}");
            if ($rest !== '') {
                return trim($rest);
            }
        }
        return $variation_title;
    }

    private function get_categories($post_id) {
        $names = [];
        foreach (['product-categories', 'product_cat', 'fc_product_category'] as $tax) {
            if (!taxonomy_exists($tax)) {
                continue;
            }
            $terms = get_the_terms($post_id, $tax);
            if (is_array($terms)) {
                foreach ($terms as $t) {
                    // Build hierarchy path like "Clothing > T-Shirts".
                    $path = [$t->name];
                    $parent_id = $t->parent;
                    while ($parent_id) {
                        $p = get_term($parent_id, $tax);
                        if (!$p || is_wp_error($p)) break;
                        array_unshift($path, $p->name);
                        $parent_id = $p->parent;
                    }
                    $names[] = implode(' > ', $path);
                }
            }
        }
        return implode(', ', array_unique($names));
    }

    private function get_product_images($wpdb, $meta_table, $post_id, &$cache) {
        $urls = [];
        // Featured image first.
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $u = wp_get_attachment_url($thumb_id);
            if ($u) $urls[] = $u;
        }
        // Gallery / product images stored in fct_product_meta.
        if ($this->table_exists($meta_table)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_value FROM `{$meta_table}` WHERE object_type = %s AND object_id = %d AND meta_key IN ('product_thumbnail','gallery_images','product_gallery','images')",
                'product', $post_id
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                foreach ($this->extract_urls($row['meta_value']) as $u) {
                    $urls[] = $u;
                }
            }
        }
        return implode(', ', array_values(array_unique(array_filter($urls))));
    }

    private function variation_image($wpdb, $meta_table, $v, &$cache, $post_id) {
        if ($this->table_exists($meta_table) && !empty($v['id'])) {
            $mv = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM `{$meta_table}` WHERE object_type = %s AND object_id = %d AND meta_key = %s LIMIT 1",
                'variation', (int) $v['id'], 'product_thumbnail'
            ));
            if ($mv) {
                $u = $this->extract_urls($mv);
                if (!empty($u)) return $u[0];
            }
        }
        return ''; // leave blank; parent images cover it
    }

    private function extract_urls($meta_value) {
        $out = [];
        $decoded = json_decode($meta_value, true);
        if (is_array($decoded)) {
            array_walk_recursive($decoded, function ($val, $key) use (&$out) {
                if ($key === 'url' && is_string($val)) $out[] = $val;
            });
            if (!empty($out)) return $out;
        }
        $unser = @maybe_unserialize($meta_value);
        if (is_array($unser)) {
            array_walk_recursive($unser, function ($val, $key) use (&$out) {
                if ($key === 'url' && is_string($val)) $out[] = $val;
            });
            if (!empty($out)) return $out;
        }
        if (is_string($meta_value) && filter_var($meta_value, FILTER_VALIDATE_URL)) {
            $out[] = $meta_value;
        }
        return $out;
    }

    private function bundle_info($v) {
        $info = $this->decode_other_info($v);
        if (isset($info['bundle_child_ids']) && !empty($info['bundle_child_ids'])) {
            $ids = $info['bundle_child_ids'];
            if (is_array($ids)) {
                return 'BUNDLE children variation IDs: ' . implode('|', $ids);
            }
            return 'BUNDLE: ' . $ids;
        }
        return '';
    }

    private function decode_other_info($v) {
        if (!isset($v['other_info']) || $v['other_info'] === '') {
            return [];
        }
        $d = json_decode($v['other_info'], true);
        if (is_array($d)) return $d;
        $u = @maybe_unserialize($v['other_info']);
        return is_array($u) ? $u : [];
    }

    /**
     * Real SKU if present, otherwise a clean synthetic SKU based on stable IDs.
     * Synthetic SKUs deliberately use product/variation IDs (never the title text)
     * so the SKU column can never be confused with the product name.
     * Format: FC-{post_id}-V{variation_id}  e.g. FC-1356-V232
     */
    private function sku_for($v, $product, $idx) {
        if (isset($v['sku']) && trim((string) $v['sku']) !== '') {
            return trim((string) $v['sku']);
        }
        $pid = (int) $product['post_id'];
        $vid = isset($v['id']) ? (int) $v['id'] : ($idx + 1);
        return 'FC-' . $pid . '-V' . $vid;
    }

    /**
     * Parent linker SKU for a variable product. Uses the product ID so it is
     * unique, stable, and clearly an identifier rather than a title.
     * Format: FC-{post_id}  e.g. FC-1356
     */
    private function parent_sku_for($product, $pid) {
        return 'FC-' . (int) $pid;
    }

    private function to_decimal($cents) {
        if ($cents === '' || !is_numeric($cents)) return '';
        return number_format(((float) $cents) / 100, 2, '.', '');
    }

    private function clean_text($html) {
        if ($html === null) return '';
        // Keep it CSV-friendly: strip tags, collapse whitespace.
        $text = wp_strip_all_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function arr($arr, $key, $default = '') {
        return (is_array($arr) && isset($arr[$key])) ? $arr[$key] : $default;
    }

    private function first_existing($names) {
        global $wpdb;
        foreach ($names as $n) {
            $t = $wpdb->prefix . $n;
            if ($this->table_exists($t)) return $t;
        }
        return '';
    }

    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}

new FCT_Product_Exporter();
