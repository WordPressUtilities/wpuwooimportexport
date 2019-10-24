<?php

/*
* Import Export Post v 0.1.1
*/

include dirname(__FILE__) . '/../inc/posts.php';

class WPUWooImportExportTasks_ImportExportPost extends WPUWooImportExport_Posts {
    private $methods = array(
        'import',
        'export'
    );
    public function __construct() {
        parent::__construct();
        global $argv;

        if (!is_array($argv) || !isset($argv[1], $argv[2]) || !in_array($argv[1], $this->methods) || !is_numeric($argv[2])) {
            $this->print_message('The syntax is not right :');
            $this->print_message('$ php import-export-post.php export 123');
            $this->print_message('$ php import-export-post.php import 123');
            die;
        }

        if ($argv[1] == 'import') {
            $this->import_post($argv[2], array('find_attachments_metas' => 1));
            $this->print_message('Import ok');
        } else {
            $this->export_post($argv[2]);
            $this->print_message('Export ok');
        }
    }

}

new WPUWooImportExportTasks_ImportExportPost();
