<?php

/*
* ORDERS V 0.1.1
*/

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Orders extends WPUWooImportExport {
    public function __construct() {
        parent::__construct();
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
