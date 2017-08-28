<?php

/*
* ORDERS V 0.2.4
*/

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Orders extends WPUWooImportExport {
    public function __construct() {
        parent::__construct();
    }

    public function get_orders($datas = array()) {
        if (!is_array($datas)) {
            $datas = array();
        }
        if (!isset($datas['post_type'])) {
            $datas['post_type'] = 'shop_order';
        }
        if (!isset($datas['posts_per_page'])) {
            $datas['posts_per_page'] = '20';
        }
        if (!isset($datas['orderby'])) {
            $datas['orderby'] = 'ID';
        }
        if (!isset($datas['order'])) {
            $datas['order'] = 'DESC';
        }
        if (!isset($datas['post_status'])) {
            $datas['post_status'] = 'wc-processing';
        }

        $list_products = false;
        if (isset($datas['list_products'])) {
            $list_products = true;
            unset($datas['list_products']);
        }

        $load_billing_address = false;
        if (isset($datas['load_billing_address'])) {
            $load_billing_address = true;
            unset($datas['load_billing_address']);
        }

        $load_shipping_address = false;
        if (isset($datas['load_shipping_address'])) {
            $load_shipping_address = true;
            unset($datas['load_shipping_address']);
        }

        $order_posts = get_posts($datas);
        $orders = array();
        foreach ($order_posts as $order_post) {
            $wc_order = new WC_Order($order_post->ID);
            $order = array(
                'id' => $order_post->ID,
                'date' => $order_post->post_date,
                'customer_id' => $wc_order->get_customer_id(),
                'item_count' => $wc_order->get_item_count(),
                'total' => $wc_order->get_total(),
                'total_shipping' => $wc_order->get_total_shipping(),
                'billing_email' => $wc_order->get_billing_email(),
                'billing_phone' => $wc_order->get_billing_phone(),
                'payment_gateway' => ''
            );
            $payment_gateway = wc_get_payment_gateway_by_order($wc_order);
            if (is_object($payment_gateway)) {
                $order['payment_gateway'] = $payment_gateway->id;
            }

            if ($load_billing_address) {
                $order['billing_first_name'] = $wc_order->get_billing_first_name();
                $order['billing_last_name'] = $wc_order->get_billing_last_name();
                $order['billing_company'] = $wc_order->get_billing_company();
                $order['billing_address_1'] = $wc_order->get_billing_address_1();
                $order['billing_address_2'] = $wc_order->get_billing_address_2();
                $order['billing_city'] = $wc_order->get_billing_city();
                $order['billing_state'] = $wc_order->get_billing_state();
                $order['billing_postcode'] = $wc_order->get_billing_postcode();
                $order['billing_country'] = $wc_order->get_billing_country();
            }

            if ($load_shipping_address) {
                $order['shipping_first_name'] = $wc_order->get_shipping_first_name();
                $order['shipping_last_name'] = $wc_order->get_shipping_last_name();
                $order['shipping_company'] = $wc_order->get_shipping_company();
                $order['shipping_address_1'] = $wc_order->get_shipping_address_1();
                $order['shipping_address_2'] = $wc_order->get_shipping_address_2();
                $order['shipping_city'] = $wc_order->get_shipping_city();
                $order['shipping_state'] = $wc_order->get_shipping_state();
                $order['shipping_postcode'] = $wc_order->get_shipping_postcode();
                $order['shipping_country'] = $wc_order->get_shipping_country();
            }

            $tmp_orders = array();

            if (!$list_products) {
                $tmp_orders[] = $order;
            } else {
                $items = $wc_order->get_items();
                foreach ($items as $item) {
                    $product = wc_get_product($item->get_product_id());
                    $order['line_product_id'] = $item->get_product_id();
                    $order['line_variation_id'] = $item->get_variation_id();
                    $order['line_sku'] = '';
                    if ($product) {
                        $order['line_sku'] = $product->get_sku();
                    }
                    $order['line_name'] = $item->get_name();
                    $order['line_qty'] = $item->get_quantity();
                    $order['line_total'] = $item->get_total();
                    $order['line_total_tax'] = $item->get_total_tax();
                    $tmp_orders[] = $order;
                }
            }

            foreach ($tmp_orders as $tmp_order) {
                if (apply_filters('wpuwooimportexport_orders_use_order', true, $tmp_order)) {
                    $orders[] = $tmp_order;
                }
            }

        }

        return $orders;

    }

    /* Update orders
    -------------------------- */

    public function update_orders_from_datas($datas = array()) {
        $this->display_table_datas($datas, array(), array(&$this, 'update_order_from_datas'));
    }

    public function update_order_from_datas($data = array(), $line = array()) {
        $line['post_id'] = $data['order_id'];
        unset($data['order_id']);

        $line_test = $this->test_post($line['post_id'], 'shop_order');
        if ($line_test !== true) {
            $line['msg'] = $line_test;
            return $line;
        }

        $this->update_post_from_data($line['post_id'], $data);
        $line['msg'] = 'Successful update';

        return $line;
    }
}
