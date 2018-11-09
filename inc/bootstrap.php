<?php

/*
Name: WPU Woo Import/Export
Version: 0.24.0
Description: A CLI utility to import/export orders & products in WooCommerce
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

$wpuwooimportexport_is_bootstraped = !defined('ABSPATH');

/* ----------------------------------------------------------
  Load WordPress
---------------------------------------------------------- */

if($wpuwooimportexport_is_bootstraped){
    /* It could take a while */
    set_time_limit(0);
    ini_set('memory_limit', '1G');

    // ignore_user_abort(true);

    /* Disable default environment */
    define('WP_USE_THEMES', false);

    /* Fix for qtranslate and other plugins */
    define('WP_ADMIN', true);
    $_SERVER['PHP_SELF'] = '/wp-admin/index.php';

    /* Load WordPress */
    /* Thanks to http://boiteaweb.fr/wordpress-bootstraps-ou-comment-bien-charger-wordpress-6717.html */
    chdir(dirname(__FILE__));
    $bootstrap = 'wp-load.php';
    while (!is_file($bootstrap)) {
        if (is_dir('..') && getcwd() != '/') {
            chdir('..');
        } else {
            die('EN: Could not find WordPress! FR : Impossible de trouver WordPress !');
        }
    }
    require_once $bootstrap;

    /* Start WP */
    wp();
}

/* ----------------------------------------------------------
  Disable email
---------------------------------------------------------- */

if ($wpuwooimportexport_is_bootstraped && !isset($keepmails)) {
    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
            return true;
        }
    }

    add_action('phpmailer_init', 'disable_phpmailer');
    function disable_phpmailer($phpmailer) {
        $phpmailer->ClearAllRecipients();
    }

    add_filter('wp_mail', 'disable_wpmail');
    function disable_wpmail($args) {
        return array(
            'to' => 'no-reply@example.com',
            'subject' => $args['subject'],
            'message' => $args['message'],
            'headers' => $args['headers'],
            'attachments' => $args['attachments']
        );
    }
}

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

        /* Filter values */
        if (isset($data['post_title'])) {
            $data['post_title'] = wp_strip_all_tags($data['post_title']);
        }

        if (!isset($data['post_status'])) {
            $data['post_status'] = 'publish';
        }

        /* Insert post */
        $post_id = wp_insert_post($data);
        if (!is_numeric($post_id)) {
            return false;
        }

        /* Metas */
        $this->set_post_metas($post_id, $data);

        return $post_id;

    }

    /* Create a CSV from datas
    -------------------------- */

    public function create_csv_from_datas($datas = array(), $export_file = 'test.csv', $delimiter = ",", $enclosure = '"') {

        $csv = $this->get_csv_from_datas($datas, $delimiter, $enclosure);

        $fpc = file_put_contents($export_file, $csv);

        if (count($fpc)) {
            echo "- Export : ok in " . $export_file . "\n";
        } else {
            echo "- Export : failed.\n";
        }
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

        $this->set_post_metas($post_id, $data);

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
      METAS
    ---------------------------------------------------------- */

    public function set_post_metas($post_id, $data) {
        if (!isset($data['metas']) || !is_array($data['metas'])) {
            return;
        }
        if (array_key_exists('post_thumbnail', $data['metas'])) {
            $this->set_post_thumbnail($post_id, $data['metas']['post_thumbnail']);
        }
        foreach ($data['metas'] as $key => $var) {
            update_post_meta($post_id, $key, $var);
        }
    }

    /* ----------------------------------------------------------
      MEDIAS
    ---------------------------------------------------------- */

    public function set_post_thumbnail($post_id, $file_thumbnail) {

        /* Check if thumbnail exists */
        $file_thumbnail = trim($file_thumbnail);
        if (empty($file_thumbnail)) {
            return false;
        }
        if (!file_exists($file_thumbnail)) {
            $file_thumbnail = remove_accents($file_thumbnail);
            if (!file_exists($file_thumbnail)) {
                return false;
            }
        }
        if (!is_file($file_thumbnail)) {
            return false;
        }

        /* Compare hashes */
        $post_thumbnail_hash = get_post_meta($post_id, 'post_thumbnail_hash', 1);
        $file_thumbnail_hash = md5_file($file_thumbnail);
        if ($file_thumbnail_hash == $post_thumbnail_hash) {
            return false;
        }

        /* Upload file */
        $thumbnail_id = $this->upload_file($file_thumbnail, $post_id);
        if (!is_numeric($thumbnail_id)) {
            return false;
        }

        /* Save new thumbnail */
        set_post_thumbnail($post_id, $thumbnail_id);
        update_post_meta($post_id, 'post_thumbnail_hash', $file_thumbnail_hash);
    }

    public function upload_file($filepath, $parent_post_id = 0) {

        if (!file_exists($filepath)) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload_dir = wp_upload_dir();

        /* Get file info */
        $basename = basename($filepath);
        $filetype = wp_check_filetype($filepath);

        /* Copy file to avoid deleting it */
        $tmpfile = $upload_dir['basedir'] . $basename;
        copy($filepath, $tmpfile);

        /* Upload copied file */
        $attachment_id = media_handle_sideload(array(
            'name' => strtolower(remove_accents($basename)),
            'tmp_name' => $tmpfile,
            'type' => $filetype['type'],
            'size' => filesize($filepath),
            'error' => UPLOAD_ERR_OK
        ), $parent_post_id);

        if (is_wp_error($attachment_id)) {
            return false;
        } else {
            return $attachment_id;
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
      DELETE POST TYPE
    ---------------------------------------------------------- */

    public function delete_post_type($post_type = '') {
        if (!$post_type) {
            return false;
        }
        /* Delete all */
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => 'any',
            'numberposts' => -1
        ));
        foreach ($posts as $post_item) {
            wp_delete_post($post_item->ID, true);
        }
        return true;
    }

    /* ----------------------------------------------------------
      CONVERT CSV LINES
    ---------------------------------------------------------- */

    public function convert_csv_lines($datas = array(), $lines_association = array()) {

        $_datas = array();
        foreach ($datas as $data_item) {
            $_data_item = array();

            /* Search if line has an association */
            foreach ($lines_association as $line_destination => $line_searched) {
                if (array_key_exists($line_searched, $data_item)) {
                    $_data_item[$line_destination] = $data_item[$line_searched];
                }
            }
            if (!empty($_data_item)) {
                $_datas[] = $_data_item;
            }
        }

        return $_datas;
    }

    /* ----------------------------------------------------------
      GET CSV FROM DATAS
    ---------------------------------------------------------- */

    public function get_csv_from_datas($datas = array(), $delimiter = ",", $enclosure = '"') {
        if (!is_array($datas) || empty($datas)) {
            return '';
        }
        ob_start();
        $out = fopen('php://output', 'w');
        $columns = array_keys(current($datas));

        fputcsv($out, $columns, $delimiter, $enclosure);
        foreach ($datas as $data) {
            fputcsv($out, $data, $delimiter, $enclosure);
        }
        fclose($out);
        $csv = ob_get_clean();
        return $csv;
    }

    /* ----------------------------------------------------------
      DISPLAY DATAS
    ---------------------------------------------------------- */

    public function display_table_datas($datas = array(), $columns = array(), $callback = false, $callback_arg = array()) {
        $count_datas = count($datas);
        if (!$count_datas) {
            return false;
        }

        $first_line = array('wpuwooimportexport_index' => 'n/n', 'id' => 'ID', 'msg' => 'msg') + $columns;
        echo implode("\t", $first_line) . "\n" . str_repeat("---\t", count($first_line)) . "\n";
        $ii = 0;
        foreach ($datas as $i => $data) {
            $line = array(
                'i' => sprintf('%s/%s', ++$ii, $count_datas)
            );

            if (is_callable($callback)) {
                $line = call_user_func_array($callback, array('data' => $data, 'line' => $line, 'args' => $callback_arg));
            }

            echo implode("\t", $line);

            $this->line_break();
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
                if ($use_first_line) {
                    if ($i == 0) {
                        foreach ($data_line_raw as $ii => $model_key) {
                            $line_text = strtolower(str_replace(' ', '_', $model_key));
                            $line_text = preg_replace('/([^a-z_]+)/', '', $line_text);
                            $model_line[$ii] = !empty($line_text) ? $line_text : 'line' . $ii;
                        }
                    } else {
                        foreach ($data_line_raw as $ii => $value) {
                            $data_line[$model_line[$ii]] = $value;
                        }
                    }
                } else {
                    foreach ($data_line_raw as $ii => $value) {
                        $data_line[$ii] = $value;
                    }
                }
                if (!empty($data_line)) {
                    $data_lines[] = $data_line;
                }
                $i++;
            }
            fclose($handle);
        }

        return $data_lines;
    }

    public function clean_csv_linebreaks($csv_file) {
        $file_contents = file_get_contents($csv_file);
        $file_contents = str_replace("\r", "\r\n", $file_contents);
        $file_contents = str_replace("\r\n\n", "\r\n", $file_contents);
        file_put_contents($csv_file, $file_contents);
    }

    /* ----------------------------------------------------------
      FTP
    ---------------------------------------------------------- */

    /**
     * Send a file to an FTP folder
     * @param  string  $file       Local full file path.
     * @param  string  $remotefile Remote full file path.
     * @param  string  $host       FTP Host
     * @param  string  $user       FTP User
     * @param  string  $password   FTP Password
     * @param  integer $port       FTP Port
     * @return boolean             Success of the transfer
     */
    public function send_file_to_ftp($file, $remotefile, $host, $user, $password, $port = 21) {
        $conn_id = ftp_connect($host, $port);
        if (!$conn_id) {
            return false;
        }
        if (!ftp_login($conn_id, $user, $password)) {
            ftp_close($conn_id);
            return false;
        }
        ftp_pasv($conn_id, true);
        $upload = ftp_put($conn_id, $remotefile, $file, FTP_ASCII);
        ftp_close($conn_id);
        return $upload;
    }

    /**
     * Get a file from an FTP folder
     * @param  string  $file       Local full file path.
     * @param  string  $remotefile Remote full file path.
     * @param  string  $host       FTP Host
     * @param  string  $user       FTP User
     * @param  string  $password   FTP Password
     * @param  integer $port       FTP Port
     * @return boolean             Success of the transfer
     */
    public function get_file_from_ftp($file, $remotefile, $host, $user, $password, $port = 21) {
        $conn_id = ftp_connect($host, $port);
        if (!$conn_id) {
            return false;
        }
        if (!ftp_login($conn_id, $user, $password)) {
            ftp_close($conn_id);
            return false;
        }
        $return = ftp_get($conn_id, $file, $remotefile, FTP_BINARY);
        ftp_close($conn_id);
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

    /* ----------------------------------------------------------
      Utilities
    ---------------------------------------------------------- */

    public function line_break() {
        echo "\n";
        @flush();
        @ob_flush();
    }

    /* ----------------------------------------------------------
      MAIL
    ---------------------------------------------------------- */

    public function wp_mail($to, $subject = '', $body = '', $headers = array(), $attachments = array()) {

        if (!$headers) {
            $mail_from_name = get_option('mail_from_name');
            if (!$mail_from_name) {
                $mail_from_name = get_bloginfo('name');
            }

            $mail_from = get_option('mail_from');
            if (!$mail_from) {
                $mail_from = get_option('admin_email');
            }

            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $mail_from_name . ' <' . $mail_from . '>'
            );
        }

        $result = wp_mail($to, $subject, $body, $headers, $attachments);

        // wp mail debugging
        if (!$result) {
            global $ts_mail_errors;
            global $phpmailer;

            if (!isset($ts_mail_errors)) {
                $ts_mail_errors = array();
            }
            if (isset($phpmailer)) {
                $ts_mail_errors[] = $phpmailer->ErrorInfo;
            }

            error_log(print_r($ts_mail_errors, true));
        }
    }

}
