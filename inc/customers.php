<?php

/*
* CUSTOMERS V 0.2.0
*/

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Customers extends WPUWooImportExport {
    public function __construct() {
        parent::__construct();
    }

    /* Get customers
    -------------------------- */

    public function get_customers($datas = array()) {

        if (!is_array($datas)) {
            $datas = array();
        }
        if (!isset($datas['role__in'])) {
            $datas['role__in'] = array('customer');
        }
        if (!isset($datas['orderby'])) {
            $datas['orderby'] = 'ID';
        }
        $customers = array();
        $users = get_users($datas);

        foreach ($users as $user) {

            $user_info = get_user_meta($user->ID);

            $customer = array(
                'id' => $user->ID,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->user_email,
                'company' => '',
                'address_1' => '',
                'address_2' => '',
                'postcode' => '',
                'city' => ''
            );

            if (isset($user_info['billing_company'])) {
                $customer['company'] = implode('', $user_info['billing_company']);
            }
            if (isset($user_info['billing_address_1'])) {
                $customer['address_1'] = implode('', $user_info['billing_address_1']);
            }
            if (isset($user_info['billing_address_2'])) {
                $customer['address_2'] = implode('', $user_info['billing_address_2']);
            }
            if (isset($user_info['billing_postcode'])) {
                $customer['postcode'] = implode('', $user_info['billing_postcode']);
            }
            if (isset($user_info['billing_city'])) {
                $customer['city'] = implode('', $user_info['billing_city']);
            }

            if (apply_filters('wpuwooimportexport_customers_use_customer', true, $customer)) {
                $customers[] = $customer;
            }
        }

        return $customers;
    }

    /* Update customers
        -------------------------- */

    public function update_customers_from_datas($datas = array()) {
        $this->display_table_datas($datas, array(), array(&$this, 'update_customer_from_datas'));
    }

    public function update_customer_from_datas($data = array(), $line = array()) {
        $line['post_id'] = $data['customer_id'];
        unset($data['customer_id']);

        $line_test = $this->test_user($line['post_id']);
        if ($line_test !== true) {
            $line['msg'] = $line_test;
            return $line;
        }

        $this->update_user_from_data($line['post_id'], $data);
        $line['msg'] = 'Successful update';

        return $line;
    }

}
