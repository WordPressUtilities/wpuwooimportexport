<?php

/*
Name: WPU Woo Import/Export
Version: 0.2.0
Description: A CLI utility to import/export orders & products in WooCommerce
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

/* ----------------------------------------------------------
  Load WordPress
---------------------------------------------------------- */

/* It could take a while */
set_time_limit(0);
ini_set('memory_limit', '1G');
ignore_user_abort(true);

/* Disable default environment */
define('WP_USE_THEMES', false);

/* Load WordPress */
/* Thanks to http://boiteaweb.fr/wordpress-bootstraps-ou-comment-bien-charger-wordpress-6717.html */
$bootstrap = 'wp-load.php';
while (!is_file($bootstrap)) {
    if (is_dir('..')) {
        chdir('..');
    } else {
        die('EN: Could not find WordPress! FR : Impossible de trouver WordPress !');
    }

}
require_once $bootstrap;

/* Start WP */
wp();

/* ----------------------------------------------------------
  Bootstrap class
---------------------------------------------------------- */

class WPUWooImportExport {

    private $post_keys = array('post_title', 'post_content');

    public function __construct() {
    }

    /* ----------------------------------------------------------
      GET CSV FROM DATAS
    ---------------------------------------------------------- */

    public function get_csv_from_datas($datas = array()) {
        /* Get columns */

        $csv = implode(',', array_map(array(&$this, 'get_csv_value'), array_keys(current($datas)))) . "\n";
        foreach ($datas as $data) {
            $csv .= implode(',', array_map(array(&$this, 'get_csv_value'), $data)) . "\n";
        }

        return $csv;
    }

    public function get_csv_value($value) {

        return '"' . esc_html($value) . '"';
    }

    /* ----------------------------------------------------------
      DISPLAY DATAS
    ---------------------------------------------------------- */

    public function display_table_datas($datas = array(), $columns = array(), $callback) {
        $count_datas = count($datas);
        if (!$count_datas) {
            return false;
        }

        $first_line = array('wpuwooimportexport_index' => 'n/n', 'id' => 'ID', 'msg' => 'msg') + $columns;
        echo implode("\t", $first_line) . "\n" . str_repeat("---\t", count($first_line)) . "\n";
        foreach ($datas as $i => $data) {
            $line = array(
                'i' => sprintf('%s/%s', $i + 1, $count_datas)
            );

            $line = call_user_func_array($callback, array('data' => $data, 'line' => $line));

            echo implode("\t", $line) . "\n";

            @flush();
            @ob_flush();

        }
    }

    /* ----------------------------------------------------------
      GET DATAS
    ---------------------------------------------------------- */

    public function get_datas_from_csv($csv_file, $use_first_line = true) {
        if (!file_exists($csv_file)) {
            error_log('CSV File do not exists');
            return false;
        }

        /* Clean CSV data */
        $raw_csv = file_get_contents($csv_file);
        $raw_csv = trim($raw_csv);
        $raw_csv = str_replace("\r", "\n", $raw_csv);
        $raw_csv = str_replace("\n\n", "\n", $raw_csv);
        $raw_csv_l = explode("\n", $raw_csv);

        /* Extract line */
        $data_lines = array();
        $model_line = array();
        foreach ($raw_csv_l as $i => $csv_line) {
            $data_line = array();
            $data_line_raw = explode(';', $csv_line);
            /* First line is used as a model */
            if ($use_first_line && $i == 0) {
                foreach ($data_line_raw as $ii => $model_key) {
                    $line_text = strtolower(str_replace(' ', '_', $model_key));
                    $line_text = preg_replace('/([^a-z_]+)/', '', $line_text);
                    $model_line[$ii] = $line_text;
                }
            } else {
                foreach ($data_line_raw as $ii => $value) {
                    $data_line[$model_line[$ii]] = $value;
                }
                $data_lines[] = $data_line;
            }
        }

        return $data_lines;
    }

    /* ----------------------------------------------------------
      UPDATE
    ---------------------------------------------------------- */

    /* Update post from datas
    -------------------------- */

    public function update_post_from_data($post_id, $data) {
        $post_keys = array();
        foreach ($data as $key => $var) {

            /* Avoid post keys */
            if (in_array($key, $this->post_keys)) {
                $post_keys[$key] = $var;
                continue;
            }

            /* Column is a postmeta */
            update_post_meta($post_id, $key, $var);

        }

        /* If post keys are available : use them to reload content */
        if (!empty($post_keys)) {
            $post_keys['ID'] = $post_id;
            wp_update_post($post_keys);
        }
    }

    /* ----------------------------------------------------------
      TEST
    ---------------------------------------------------------- */

    /* Test post
    -------------------------- */

    public function test_post($id = false, $post_type = 'post') {
        $post = get_post($id);
        if (!is_object($post)) {
            return '/!\ Inexistant post';
        }

        if ($post->post_type != $post_type) {
            return '/!\ Not a ' . $post_type;
        }

        return true;

    }

}
