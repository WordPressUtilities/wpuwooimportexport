# WPU Woo Import/Export

* A CLI utility to import/export orders & products in WooCommerce


## Example 1 : Import orders modifications from a CSV file :

```php
<?php

include dirname(__FILE__) . '/../wpuwooimportexport/inc/orders.php';

class WPUWOOTEST_UpdateOrders extends WPUWooImportExport_Orders {
    public function __construct() {
        $csv_file = dirname( __FILE__ ) . '/test.csv';
        $datas = $this->get_datas_from_csv($csv_file);
        $this->update_orders_from_datas($datas);
    }
}

new WPUWOOTEST_UpdateOrders();
```
