<?php

/*
* PRODUCTS V 0.3.0
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

            $product_item = array(
                'id' => $product_post->ID,
                'title' => $product_post->post_title,
                'parent' => 0
            );

            $product = wc_get_product($product_post);
            $product_item['sku'] = $product->get_sku();

            if ($load_variations && $product->is_type('variable')) {
                $variable_product = new WC_Product_Variable($product_post->ID);
                $variations = $variable_product->get_available_variations();
                foreach ($variations as $variation_post) {
                    $product_var = wc_get_product($variation_post['id']);
                    $product_item = array(
                        'id' => $variation_post['id'],
                        'title' => $variation_post['name'],
                        'parent' => $product_post->ID
                    );
                    $product_item['sku'] = $product_var->get_sku();
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

    public function update_product_from_datas($data, $line) {
        $line['post_id'] = $data['product_id'];

        $line_test = $this->test_post($line['post_id'], 'product');
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
}
