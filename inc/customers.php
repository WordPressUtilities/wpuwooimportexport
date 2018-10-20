<?php

/*
* CUSTOMERS V 0.3.0
*/

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Customers extends WPUWooImportExport {

    private $default_customer_fields = array(
        'company' => 'billing_company',
        'address_1' => 'billing_address_1',
        'address_2' => 'billing_address_2',
        'postcode' => 'billing_postcode',
        'city' => 'billing_city'
    );

    public function __construct() {
        parent::__construct();
    }

    /* Get customers
    -------------------------- */

    public function get_customers($datas = array(), $extra_fields = array()) {

        /* Set datas */
        if (!is_array($datas)) {
            $datas = array();
        }
        if (!isset($datas['role__in'])) {
            $datas['role__in'] = array('customer');
        }
        if (!isset($datas['orderby'])) {
            $datas['orderby'] = 'ID';
        }

        /* Set fields */
        if (!is_array($extra_fields)) {
            $extra_fields = array();
        }
        foreach ($this->default_customer_fields as $field_id => $field_meta_id) {
            if (!isset($extra_fields)) {
                $extra_fields[$field_id] = $field_meta_id;
            }
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

            foreach ($extra_fields as $field_id => $field_meta_id) {
                if (isset($user_info[$field_meta_id])) {
                    $customer[$field_id] = implode('', $user_info[$field_meta_id]);
                }
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
