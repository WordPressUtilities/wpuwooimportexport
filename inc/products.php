<?php

/*
* PRODUCTS V 0.6.4
*/

/*
 * TODO
 * - Delete products not in file
 */

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Products extends WPUWooImportExport {

    public $product_model = array(
        'post_title' => 'Product',
        'post_type' => 'product',
        'metas' => array(
            '_backorders' => 'no',
            '_crosssell_ids' => array(),
            '_downloadable' => 'no',
            '_featured' => 'no',
            '_manage_stock' => 'no',
            '_price' => 10,
            '_product_attributes' => array(),
            '_regular_price' => 10,
            '_sale_price' => 10,
            '_sku' => '',
            '_stock' => 99999,
            '_stock_status' => 'instock',
            '_tax_status' => 'taxable',
            '_virtual' => 'no',
            '_visibility' => 'visible',
            '_weight' => 1
        )
    );

    public function __construct() {
        parent::__construct();
    }

    /* Get products
    -------------------------- */

    public function get_products($datas = array()) {

        if (!is_array($datas)) {
            $datas = array();
        }
        if (!isset($datas['post_type'])) {
            $datas['post_type'] = 'product';
        }
        if (!isset($datas['posts_per_page'])) {
            $datas['posts_per_page'] = '-1';
        }
        if (!isset($datas['orderby'])) {
            $datas['orderby'] = 'ID';
        }
        if (!isset($datas['order'])) {
            $datas['order'] = 'ASC';
        }
        $load_variations = false;
        if (isset($datas['load_variations']) && $datas['load_variations']) {
            unset($datas['load_variations']);
            $load_variations = true;
        }

        $products = array();
        $wc_products = get_posts($datas);

        foreach ($wc_products as $product_post) {
            if (!apply_filters('wpuwooimportexport_products_use_product', true, $product_post)) {
                continue;
            }

            $product = wc_get_product($product_post);
            $product_item = array(
                'id' => $product_post->ID,
                'title' => $product_post->post_title,
                'attributes' => '',
                'parent' => 0,
                'date' => $product_post->post_date,
                'price' => $product->get_price(),
                'sku' => $product->get_sku(),
                'stock_status' => $product->get_stock_status(),
                'tax' => $product->get_tax_class()
            );

            if ($load_variations && $product->is_type('variable')) {
                $variable_product = new WC_Product_Variable($product_post->ID);
                $variations = $variable_product->get_available_variations();
                foreach ($variations as $variation_post) {
                    $product_var = wc_get_product($variation_post['id']);
                    $product_item = array(
                        'id' => $variation_post['id'],
                        'title' => $product_post->post_title . ' - ' . implode(' - ', $product_var->get_attributes()),
                        'attributes' => implode('/', $product_var->get_attributes()),
                        'parent' => $product_post->ID,
                        'date' => $product_var->get_date_created()->date('Y-m-d H:i:s'),
                        'price' => $product_var->get_price(),
                        'sku' => $product_var->get_sku(),
                        'stock_status' => $product_var->get_stock_status(),
                        'tax' => $product_var->get_tax_class()
                    );
                    $products[] = $product_item;
                }
                continue;
            }

            $products[] = $product_item;
        }

        return $products;
    }

    /* Create products
    -------------------------- */

    public function create_products_from_datas($datas) {
        $this->display_table_datas($datas, array(), array(&$this, 'create_product_from_datas'));
    }

    public function create_product_from_datas($data, $line) {
        if (isset($data['product_id'])) {
            unset($data['product_id']);
        }
        $data = $this->set_post_data_from_model($data, $this->product_model);

        $line_id = $this->create_post_from_data($data);

        $line['post_id'] = is_numeric($line_id) ? $line_id : 0;
        $line['msg'] = is_numeric($line_id) ? 'Successful creation' : 'Creation failed';

        return $line;
    }

    /* Update products
    -------------------------- */

    public function update_products_from_datas($datas) {
        $this->display_table_datas($datas, array(), array(&$this, 'update_product_from_datas'));
    }

    public function update_product_from_datas($data, $line, $variation = false) {
        $line['post_id'] = $data['product_id'];

        $line_test = $this->test_post($line['post_id'], $variation ? 'product_variation' : 'product');
        if ($line_test !== true) {
            $line['msg'] = $line_test;
            return $line;
        }

        if (isset($data['product_id'])) {
            unset($data['product_id']);
        }

        $this->update_post_from_data($line['post_id'], $data);
        $line['msg'] = 'Successful update';

        return $line;
    }

    /* Create or update
    -------------------------- */

    public function create_update_products_from_datas($datas, $callback_args = false) {
        if (!$callback_args) {
            $callback_args = array('associative_key' => '_sku');
        }
        $this->display_table_datas($datas, array(), array(&$this, 'create_update_product_from_datas'), $callback_args);
    }

    public function create_update_product_from_datas($data, $line, $args = array()) {
        $associative_key = '_sku';
        if (!is_array($args)) {
            $args = array();
        }
        if (isset($args['associative_key'])) {
            $associative_key = $args['associative_key'];
        }

        $product_id = wc_get_product_id_by_sku($data['_sku']);

        if (isset($args['callback_before'])) {
            call_user_func($args['callback_before'], $product_id, $data, $line);
        }

        if (is_numeric($product_id) && $product_id > 0) {
            $data['product_id'] = $product_id;
            $line = $this->update_product_from_datas($data, $line);
        } else {
            $line = $this->create_product_from_datas($data, $line);
        }

        if (isset($args['callback_after'])) {
            call_user_func($args['callback_after'], $product_id, $data, $line);
        }

        unset($line['product_id']);

        return $line;
    }

    /* Variations
    -------------------------- */

    public function create_update_variations_from_datas($datas, $callback_args = false) {
        if (!$callback_args) {
            $callback_args = array('associative_key' => '_sku');
        }
        $this->display_table_datas($datas, array('parent_id' => 'Parent'), array(&$this, 'create_update_variation_from_datas'), $callback_args);
    }

    public function create_update_variation_from_datas($data, $line, $args = array()) {
        $associative_key = '_sku';
        if (array($args) && isset($args['associative_key'])) {
            $associative_key = $args['associative_key'];
        }
        if (!isset($data['_parent'], $args['attribute_id'], $args['attribute_value'], $data[$args['attribute_value']])) {
            $line['msg'] = 'Invalid variation';
            return $line;
        }

        $parent_id = wc_get_product_id_by_sku($data['_parent']);
        $line['parent_id'] = $parent_id;
        if (!is_numeric($parent_id)) {
            $line['msg'] = 'Parent does not exists';
            return $line;
        }

        /* Parent should be variable */
        wp_set_object_terms($parent_id, 'variable', 'product_type', false);

        /* Set parent attribute */
        $term_taxo = 'pa_' . $args['attribute_id'];
        $this->add_product_attribute($parent_id, $args['attribute_id'], array(
            'name' => $term_taxo,
            'value' => '',
            'is_visible' => '0',
            'is_variation' => '1',
            'is_taxonomy' => '1'
        ));

        /* Set variation */
        $term_value = $data[$args['attribute_value']];
        $term = get_term_by('name', $term_value, $term_taxo);
        if (!$term) {
            $term = wp_insert_term($term_value, $term_taxo);
            $term = get_term_by('term_taxonomy_id', $term['term_taxonomy_id'], $term_taxo);
        }
        $term_id = $term->term_id;
        $term_slug = $term->slug;
        if (isset($args['callback_after_term'])) {
            call_user_func($args['callback_after_term'], $term_id, $data, $line);
        }

        wp_set_object_terms($parent_id, $term_slug, $term_taxo, 1);

        /* Get variations */
        $product = new WC_Product_Variable($parent_id);
        $variables = $product->get_available_variations();
        $existing_attributes = array();
        if (!empty($variables)) {
            foreach ($variables as $key => $var) {
                if (isset($var['attributes'], $var['attributes']['attribute_' . $term_taxo])) {
                    $existing_attributes[$var['attributes']['attribute_' . $term_taxo]] = $var['variation_id'];
                }
            }
        }

        if (!isset($data['_price']) || empty($data['_price'])) {
            $data['_price'] = get_post_meta($parent_id, '_price', 1);
        }
        if (!isset($data['_regular_price']) || empty($data['_regular_price'])) {
            $data['_regular_price'] = get_post_meta($parent_id, '_regular_price', 1);
        }
        if (!isset($data['_sale_price']) || empty($data['_sale_price'])) {
            $data['_sale_price'] = get_post_meta($parent_id, '_sale_price', 1);
        }

        /* Create attribute product if absent */
        $is_creation = false;
        if (empty($existing_attributes) || !array_key_exists($term_slug, $existing_attributes)) {
            $variation_id = $this->create_product_variation($parent_id);
            $is_creation = true;
            update_post_meta($variation_id, 'attribute_' . $term_taxo, $term_slug);
        } else {
            $variation_id = $existing_attributes[$term_slug];
        }

        if (isset($args['callback_after_variation'])) {
            call_user_func($args['callback_after_variation'], $variation_id, $data, $line);
        }

        /* Set variation */
        $data['product_id'] = $variation_id;
        if (is_numeric($variation_id)) {
            $line = $this->update_product_from_datas($data, $line, 1);
            $line['msg'] = $is_creation ? 'Successful variation creation' : 'Successful variation update';
        } else {
            $line['msg'] = 'Variation creation has failed';
        }

        /* Sync parent product */
        WC_Product_Variable::sync($parent_id);

        return $line;
    }

    public function create_product_variation($parent_id) {
        return wp_insert_post(array(
            'post_title' => 'Product #' . $parent_id . ' Variation',
            'post_content' => '',
            'post_status' => 'publish',
            'post_parent' => $parent_id,
            'post_type' => 'product_variation'
        ));
    }

    /* Attributes
    -------------------------- */

    public function register_attribute($attribute_id, $attribute_name) {
        global $wpdb;
        $attribute_ids = $wpdb->get_col('SELECT attribute_name FROM ' . $wpdb->prefix . 'woocommerce_attribute_taxonomies');
        if (!in_array($attribute_id, $attribute_ids)) {
            $insert = $this->process_add_taxo_attribute(array(
                'attribute_name' => $attribute_id,
                'attribute_label' => $attribute_name
            ));
        }
    }

    /* Custom attribute : http://wordpress.stackexchange.com/a/246687*/
    public function process_add_taxo_attribute($attribute) {
        global $wpdb;

        /* Default values */
        if (!isset($attribute['attribute_type']) || empty($attribute['attribute_type'])) {
            $attribute['attribute_type'] = 'text';
        }
        if (!isset($attribute['attribute_orderby']) || empty($attribute['attribute_orderby'])) {
            $attribute['attribute_orderby'] = 'menu_order';
        }
        if (!isset($attribute['attribute_public']) || empty($attribute['attribute_public'])) {
            $attribute['attribute_public'] = false;
        }

        /* Validate content */
        if (empty($attribute['attribute_name']) || empty($attribute['attribute_label'])) {
            return new WP_Error('error', __('Please, provide an attribute name and slug.', 'woocommerce'));
        } elseif (($valid_attribute_name = $this->valid_attribute_name($attribute['attribute_name'])) && is_wp_error($valid_attribute_name)) {
            return $valid_attribute_name;
        } elseif (taxonomy_exists(wc_attribute_taxonomy_name($attribute['attribute_name']))) {
            return new WP_Error('error', sprintf(__('Slug "%s" is already in use. Change it, please.', 'woocommerce'), sanitize_title($attribute['attribute_name'])));
        }

        /* Insert */
        $wpdb->insert($wpdb->prefix . 'woocommerce_attribute_taxonomies', $attribute);
        do_action('woocommerce_attribute_added', $wpdb->insert_id, $attribute);
        flush_rewrite_rules();
        delete_transient('wc_attribute_taxonomies');

        /* Temporary hardcoded register to update $wp_taxonomies */
        register_taxonomy(
            'pa_' . $attribute["attribute_name"],
            'product',
            array(
                'label' => $attribute["attribute_label"],
                'public' => $attribute["attribute_public"]
            )
        );

        return true;
    }

    public function valid_attribute_name($attribute_name) {
        if (strlen($attribute_name) >= 28) {
            return new WP_Error('error', sprintf(__('Slug "%s" is too long (28 characters max). Shorten it, please.', 'woocommerce'), sanitize_title($attribute_name)));
        } elseif (wc_check_if_attribute_name_is_reserved($attribute_name)) {
            return new WP_Error('error', sprintf(__('Slug "%s" is not allowed because it is a reserved term. Change it, please.', 'woocommerce'), sanitize_title($attribute_name)));
        }
        return true;
    }

    public function has_product_attribute($post_id, $attr_id, $attribute = array()) {
        $product_attributes = get_post_meta($post_id, '_product_attributes', 1);
        return (is_array($product_attributes) && isset($product_attributes['pa_' . $attr_id]));
    }

    public function add_product_attribute($post_id, $attr_id, $attribute = array()) {
        $attribute_name = 'pa_' . $attr_id;
        $product_attributes = get_post_meta($post_id, '_product_attributes', 1);
        if (!is_array($product_attributes)) {
            $product_attributes = array();
        }
        if (!is_array($attribute)) {
            $attribute = array();
        }
        if (!isset($attribute['is_visible'])) {
            $attribute['is_visible'] = 1;
        }
        if (!isset($attribute['is_variation'])) {
            $attribute['is_variation'] = 0;
        }
        if (!isset($attribute['is_taxonomy'])) {
            $attribute['is_taxonomy'] = 0;
        }
        if (!isset($attribute['name'])) {
            $attribute['name'] = $attribute_name;
        }
        if (!isset($attribute['value'])) {
            $attribute['value'] = '';
        }
        $product_attributes[$attribute_name] = $attribute;
        update_post_meta($post_id, '_product_attributes', $product_attributes);
    }

}
