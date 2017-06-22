<?php

/*
* PRODUCTS V 0.1.0
*/

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Products extends WPUWooImportExport {
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

    /* Update products
    -------------------------- */

    public function update_products_from_datas($datas) {
        $this->display_table_datas($datas, array(), array(&$this, 'update_product_from_datas'));
    }

    public function update_product_from_datas($data, $line) {
        $line['post_id'] = $data['product_id'];
        unset($data['product_id']);

        $line_test = $this->test_post($line['post_id'], 'product');
        if ($line_test !== true) {
            $line['msg'] = $line_test;
            return $line;
        }

        $this->update_post_from_data($line['post_id'], $data);
        $line['msg'] = 'Successful update';

        return $line;
    }
}
