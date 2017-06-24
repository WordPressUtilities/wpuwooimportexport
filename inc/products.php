<?php

/*
* PRODUCTS V 0.2.0
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

        $products = array();
        $wc_products = get_posts($datas);

        foreach ($wc_products as $product) {
            $product = array(
                'id' => $product->ID,
                'title' => $product->post_title
            );

            if (apply_filters('wpuwooimportexport_products_use_product', true, $product)) {
                $products[] = $product;
            }
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

        $data = $this->set_post_data_from_model($data, $this->product_model);

        $this->update_post_from_data($line['post_id'], $data);
        $line['msg'] = 'Successful update';

        return $line;
    }
}
