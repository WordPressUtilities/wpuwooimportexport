<?php

/*
Name: WPU Woo Import/Export
Version: 0.8.0
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

    public $post_keys = array('post_title', 'post_content', 'post_type', 'post_status');
    public $user_keys = array('user_pass', 'user_login', 'user_nicename', 'user_url', 'user_email', 'display_name', 'nickname', 'first_name', 'last_name');

    public function __construct() {
    }

    /* ----------------------------------------------------------
      CREATE
    ---------------------------------------------------------- */

    /* Create a post from datas
    -------------------------- */

    public function create_post_from_data($data = array()) {
        $post_id = false;
        $post_obj = array();
        foreach ($data as $key => $var) {
            if ($key == 'metas') {
                continue;
            }
            $post_obj[$key] = $var;
        }

        if (empty($post_obj)) {
            return false;
        }

        $post_id = wp_insert_post($data);
        if (!is_numeric($post_id)) {
            return false;
        }

        if (isset($data['metas']) && is_array($data['metas'])) {
            foreach ($data['metas'] as $key => $var) {
                update_post_meta($post_id, $key, $var);
            }
        }

        return $post_id;

    }

    /* ----------------------------------------------------------
      SET
    ---------------------------------------------------------- */

    public function set_post_data_from_model($data = array(), $model = array()) {
        if (!is_array($data)) {
            $data = array();
        }

        if (!isset($data['metas'])) {
            $data['metas'] = array();
        }

        /* Add missing post model items */
        foreach ($model as $key => $var) {
            if (isset($data[$key])) {
                continue;
            }
            /* Save post key */
            $data[$key] = $var;
        }

        $data_new = $data;
        /* Move useless items */
        foreach ($data_new as $key => $var) {
            /* Not a native post key : use as meta */
            if ($key != 'metas' && !in_array($key, $this->post_keys)) {
                $data['metas'][$key] = $var;
                unset($data[$key]);
            }
        }

        foreach ($model['metas'] as $key => $var) {
            $data['metas'][$key] = isset($data['metas'][$key]) ? $data['metas'][$key] : $var;
        }

        return $data;
    }

    /* ----------------------------------------------------------
      UPDATE
    ---------------------------------------------------------- */

    /* Update post from datas
    -------------------------- */

    public function update_post_from_data($post_id, $data = array()) {

        if (!isset($data['metas'])) {
            $data['metas'] = array();
        }

        $post_keys = array();
        foreach ($data as $key => $var) {

            if ($key == 'metas') {
                continue;
            }

            /* Avoid post keys */
            if (in_array($key, $this->post_keys)) {
                $post_keys[$key] = $var;
                continue;
            }

            /* Column is a postmeta */
            $data['metas'][$key] = $var;

        }

        /* Update metas */
        foreach ($data['metas'] as $key => $var) {
            update_post_meta($post_id, $key, $var);
        }

        /* If post keys are available : use them to reload content */
        if (!empty($post_keys)) {
            $post_keys['ID'] = $post_id;
            wp_update_post($post_keys);
        }
    }

    /* Update user from datas
    -------------------------- */

    public function update_user_from_data($user_id, $data) {
        $user_keys = array();
        foreach ($data as $key => $var) {

            /* Avoid user keys */
            if (in_array($key, $this->user_keys)) {
                $user_keys[$key] = $var;
                continue;
            }

            /* Column is a usermeta */
            update_user_meta($user_id, $key, $var);

        }

        /* If user keys are available : use them to reload content */
        if (!empty($user_keys)) {
            $user_keys['ID'] = $user_id;
            wp_update_user($user_keys);
        }
    }

    /* ----------------------------------------------------------
      GET SETTINGS FROM CLI
    ---------------------------------------------------------- */

    public function get_settings_from_cli() {
        $settings = array();
        $opts = getopt('n::', array(
            "number::",
            "fromdate::",
            "todate::"
        ));

        if (isset($opts['number']) && is_numeric($opts['number'])) {
            $settings['number'] = $opts['number'];
        }
        if (isset($opts['fromdate']) && preg_match('/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}$/isU', $opts['fromdate'])) {
            $settings['fromdate'] = $opts['fromdate'];
        }
        if (isset($opts['todate']) && preg_match('/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}$/isU', $opts['todate'])) {
            $settings['todate'] = $opts['todate'];
        }

        return $settings;

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

    public function get_datas_from_csv($csv_file, $use_first_line = true, $delimiter = ",", $enclosure = '"') {
        if (!file_exists($csv_file)) {
            error_log('CSV File do not exists');
            return false;
        }

        /* Clean CSV data */
        $data_lines = array();
        $model_line = array();

        if (($handle = fopen($csv_file, "r")) !== FALSE) {
            $i = 0;
            while (($data_line_raw = fgetcsv($handle, 5000, $delimiter, $enclosure)) !== FALSE) {
                $data_line = array();
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
                $i++;
            }
            fclose($handle);
        }

        return $data_lines;
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

    /* Test user
    -------------------------- */

    public function test_user($id = false) {
        $user = get_user_by('ID', $id);

        if (!is_object($user)) {
            return '/!\ Inexistant user';
        }

        return true;

    }

}
