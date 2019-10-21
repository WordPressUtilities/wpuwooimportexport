<?php

/*
* Import CSV V 0.1.0
*/

include dirname(__FILE__) . '/../inc/bootstrap.php';

class WPUWooImportExportTasks_ImportCSV extends WPUWooImportExport {
    public function __construct() {
        global $argv;

        /* Check file */
        if (!file_exists($argv[1])) {
            $this->print_message('The syntax is not right :');
            $this->print_message('$ php import-csv.php file.csv');
            return;
        }
        if (!isset($argv[1])) {
            $this->print_message("No valid file provided");
            return;
        }
        $this->sync_posts_from_csv($argv[1], array(
            'debug_type' => 'print'
        ));

    }

}

new WPUWooImportExportTasks_ImportCSV();
