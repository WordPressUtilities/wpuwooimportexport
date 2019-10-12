<?php

/*
Name: WPU Woo Import/Export
Version: 0.33.1
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

if ($wpuwooimportexport_is_bootstraped) {
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

    public $post_keys = array('post_title', 'post_content', 'post_type', 'post_status', 'post_date', 'post_date_gmt', 'post_parent');
    public $user_keys = array('user_pass', 'user_login', 'user_nicename', 'user_url', 'user_email', 'display_name', 'nickname', 'first_name', 'last_name');

    public function __construct() {
    }

    /* ----------------------------------------------------------
      CREATE OR UPDATE
    ---------------------------------------------------------- */

    /* Term
    -------------------------- */

    /**
     $term_id = $this->create_or_update_term_from_datas(array(
         'term_name' => 'My Term',
         'taxonomy' => 'category',
         'metas' => array(
             'my_meta_key' => 'Value'
         )
     ), array(
         'uniqid' => '121234'
     ));
     */

    public function create_or_update_term_from_datas($data = array(), $search = array()) {
        if (empty($search) || !is_array($search)) {
            return false;
        }
        if (!isset($data['taxonomy'])) {
            $data['taxonomy'] = 'category';
        }
        if (!isset($data['term_name'])) {
            return false;
        }
        if (!isset($data['metas']) || !is_array($data['metas'])) {
            $data['metas'] = array();
        }

        /* Obtain post by uniqid */
        $args = array(
            'hide_empty' => false,
            'meta_query' => array()
        );
        foreach ($search as $key => $value) {
            $args['meta_query'][] = array(
                'key' => $key,
                'value' => $value,
                'compare' => '='
            );
        }
        $terms = get_terms($data['taxonomy'], $args);

        /* Create term */
        if (empty($terms) || is_wp_error($terms)) {
            $term = wp_insert_term($data['term_name'], $data['taxonomy']);
            if (is_wp_error($term)) {
                return false;
            }

            /* Add Uniqid */
            foreach ($search as $key => $value) {
                $this->add_term_meta($term['term_id'], $key, $value);
            }

            /* Add Metas */
            foreach ($data['metas'] as $key => $value) {
                $this->add_term_meta($term['term_id'], $key, $value);
            }

            return $term['term_id'];

        } else {
            $_term_id = $terms[0]->term_id;

            /* Update term basic informations */
            $term = wp_update_term($_term_id, $data['taxonomy'], array(
                'name' => $data['term_name']
            ));
            if (is_wp_error($term)) {
                return false;
            }

            /* Update Metas */
            foreach ($data['metas'] as $key => $value) {
                update_term_meta($_term_id, $key, $value);
            }

            return $_term_id;
        }

    }

    /* Post
    -------------------------- */

    /**
     * Create or update a post from datas
     * @param  array   $data          Array of datas for the post.
     * @param  array   $search        Search query to find an existing post
     * @param  boolean $check_uniqid  Check if uniqid exists ( Faster when inserting lot of new posts )
     * @param  boolean $get_status    Return an array composed of the status created / updated and the post id
     * @return mixed                  Post ID / array('new',int postid) / Error
     */
    public function create_or_update_post_from_datas($data = array(), $search = array(), $check_uniqid = false, $get_status = false) {
        global $wpdb;
        if (empty($search) || !is_array($search)) {
            return false;
        }

        $data = $this->prepare_post_data($data);

        $args = array(
            'posts_per_page' => 1,
            'post_type' => $data['post_type'],
            'post_status' => apply_filters('wpuwooimportexport_create_or_update_post_status', array(
                'publish',
                'private',
                'future'
            )),
            'meta_query' => array(
                'relation' => 'AND'
            )
        );

        foreach ($search as $key => $value) {
            $args['meta_query'][] = array(
                'key' => $key,
                'value' => $value,
                'compare' => '='
            );
        }

        $uniqid_found = false;
        if (isset($search['uniqid']) && $check_uniqid) {
            $uniqid_type = is_numeric($search['uniqid']) ? '%d' : '%s';
            $v = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = $uniqid_type", 'uniqid', $search['uniqid']));
            if (!is_null($v) && $v == $search['uniqid']) {
                $uniqid_found = true;
            }
        }

        $results = (($check_uniqid && $uniqid_found) || !$check_uniqid) ? get_posts($args) : array();

        if (!$results || !isset($results[0])) {
            $return = $this->create_post_from_data($data);
            if ($get_status && is_numeric($return)) {
                $return = array('created', $return);
            }
            return $return;
        }

        $data = apply_filters('wpuwooimportexport_create_or_update_post_data_before_update', $data);

        $return = $this->update_post_from_data($results[0]->ID, $data);
        if ($get_status && is_numeric($return)) {
            $return = array('updated', $return);
        }
        return $return;
    }

    /* Prepare post datas
    -------------------------- */

    public function prepare_post_data($data = array()) {
        if (!is_array($data)) {
            echo 'Invalid data';
            die;
        }

        /* Filter values */
        if (isset($data['post_title'])) {
            $data['post_title'] = wp_strip_all_tags($data['post_title']);
        }

        if (!isset($data['post_status'])) {
            $data['post_status'] = 'publish';
        }

        if (!isset($data['post_type'])) {
            $data['post_type'] = 'post';
        }

        /* Terms */
        if (!isset($data['terms'])) {
            $data['terms'] = array();
        }

        foreach ($data as $key => $value) {
            if (substr($key, 0, 6) == 'term__') {
                $data['terms'][substr($key, 6)] = $value;
            }
        }

        /* Metas */
        if (!isset($data['metas'])) {
            $data['metas'] = array();
        }

        foreach ($data as $key => $value) {
            if (substr($key, 0, 6) == 'meta__') {
                $data['metas'][substr($key, 6)] = $value;
            }
            /* Not a native post key : use as meta */
            if ($key != 'metas' && !in_array($key, $this->post_keys) && !is_array($value) && substr($key, 0, 6) != 'term__') {
                $data['metas'][$key] = $value;
            }
        }

        return $data;

    }

    /* ----------------------------------------------------------
      SYNC
    ---------------------------------------------------------- */

    public function sync_posts_from_csv($csv_file, $settings = array()) {

        /* Settings */
        if (!is_array($settings)) {
            $settings = array();
        }
        if (!isset($settings['debug_type'])) {
            $settings['debug_type'] = '';
        }

        /* Try to extract datas */
        $datas = $this->get_datas_from_csv($csv_file);
        if (!is_array($datas)) {
            $this->debug_message("Invalid file provided", $settings['debug_type']);
            return;
        }

        /* Parse datas */
        $nb_posts = count($datas);
        foreach ($datas as $i => $data) {

            $ii = $i + 1;
            $total = "${ii}/{$nb_posts}";

            $status = 'created';
            if (isset($data['uniqid'])) {
                $return = $this->create_or_update_post_from_datas($data, array(
                    'uniqid' => $data['uniqid']
                ), false, true);
                $post_id = false;
                if (is_array($return) && isset($return[1]) && is_numeric($return[1])) {
                    $status = $return[0];
                    $post_id = $return[1];
                }
            } else {
                $post_id = $this->create_post_from_data($data);
            }

            do_action('wpuwooimportexport__sync_posts_from_csv__post_action', $post_id, $data);

            /* Create post */
            if (is_numeric($post_id)) {
                $this->debug_message($total . "\t - Post #${post_id} " . $status . " !", $settings['debug_type']);
            } else {
                $this->debug_message($total . "\t - Post could not be created", $settings['debug_type']);
            }
        }
    }

    /* ----------------------------------------------------------
      CREATE
    ---------------------------------------------------------- */

    /* Create a post from datas
    -------------------------- */

    public function create_post_from_data($data = array()) {
        $post_id = false;
        $post_obj = array();

        $data = $this->prepare_post_data($data);

        foreach ($data as $key => $var) {
            if ($key == 'metas') {
                continue;
            }
            if ($key == 'terms') {
                continue;
            }
            $post_obj[$key] = $var;
        }

        if (empty($post_obj)) {
            return false;
        }

        /* Insert post */
        $post_id = wp_insert_post($data);
        if (!is_numeric($post_id)) {
            return false;
        }

        /* Metas */
        $this->set_post_metas($post_id, $data);

        /* Terms */
        $this->set_post_terms($post_id, $data);

        return $post_id;

    }

    /* Create a CSV from datas
    -------------------------- */

    public function create_csv_from_datas($datas = array(), $export_file = 'test.csv', $delimiter = ",", $enclosure = '"', $columns = array()) {

        $csv = $this->get_csv_from_datas($datas, $delimiter, $enclosure, $columns);
        if (!$this->is_utf8($csv)) {
            $csv = utf8_encode($csv);
        }
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

        /* Add missing post model items */
        foreach ($model as $key => $var) {
            $data[$key] = isset($data[$key]) ? $data[$key] : $var;
        }

        /* Prepare data */
        $data = $this->prepare_post_data($data);

        /* Add missing model metas */
        if (isset($model['metas']) && is_array($model['metas'])) {
            foreach ($model['metas'] as $key => $var) {
                $data['metas'][$key] = isset($data['metas'][$key]) ? $data['metas'][$key] : $var;
            }
        }

        return $data;
    }

    /* ----------------------------------------------------------
      UPDATE
    ---------------------------------------------------------- */

    /* Update post from datas
    -------------------------- */

    public function update_post_from_data($post_id, $data = array()) {

        $data = $this->prepare_post_data($data);

        $post_keys = array();
        foreach ($data as $key => $var) {
            if (in_array($key, $this->post_keys)) {
                $post_keys[$key] = $var;
            }
        }

        /* Metas */
        $this->set_post_metas($post_id, $data);

        /* Terms */
        $this->set_post_terms($post_id, $data);

        /* If post keys are available : use them to reload content */
        if (!empty($post_keys)) {
            $post_keys['ID'] = $post_id;
            wp_update_post($post_keys);
        }

        return $post_id;
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

    /**
     * Set a post meta with multiple values
     * @param int    $post_id
     * @param string $meta_key
     * @param array  $meta_values
     */
    public function set_post_meta_multiple_values($post_id, $meta_key, $meta_values = array()) {
        delete_post_meta($post_id, $meta_key);
        foreach ($meta_values as $value) {
            add_post_meta($post_id, $meta_key, $value);
        }
    }

    /* Term metas
    -------------------------- */

    public function set_post_terms($post_id, $data) {
        if (!isset($data['terms']) || !is_array($data['terms'])) {
            return;
        }
        foreach ($data['terms'] as $tax_slug => $terms) {
            wp_set_object_terms($post_id, $terms, $tax_slug, false);
        }
    }

    /* Quicker meta add
    -------------------------- */

    /**
     * Quicker add_user_meta function
     * @param int    $user_id
     * @param string $meta_key
     * @param string $meta_value
     */
    public function add_user_meta($user_id, $meta_key, $meta_value) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->usermeta,
            array(
                'user_id' => $user_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ),
            array(
                '%d',
                '%s',
                '%s'
            )
        );
        return $wpdb->insert_id;
    }

    /**
     * Quicker add_post_meta function
     * @param int    $post_id
     * @param string $meta_key
     * @param string $meta_value
     */
    public function add_post_meta($post_id, $meta_key, $meta_value) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->postmeta,
            array(
                'post_id' => $post_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ),
            array(
                '%d',
                '%s',
                '%s'
            )
        );
        return $wpdb->insert_id;
    }

    /**
     * Quicker add_term_meta function
     * @param int    $term_id
     * @param string $meta_key
     * @param string $meta_value
     */
    public function add_term_meta($term_id, $meta_key, $meta_value) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->termmeta,
            array(
                'term_id' => $term_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ),
            array(
                '%d',
                '%s',
                '%s'
            )
        );
        return $wpdb->insert_id;
    }

    /* ----------------------------------------------------------
      SYNC SQL LINES
    ---------------------------------------------------------- */

    public function update_or_create_sql($data, $table) {
        global $wpdb;
        $_table = $wpdb->prefix . $table;

        $cache_id = 'update_or_create_sql_' . $table . $data['uniqid'];

        // GET CACHED VALUE
        $var = wp_cache_get($cache_id);
        if ($var === false) {
            // COMPUTE RESULT
            $var = $wpdb->get_var($wpdb->prepare("SELECT id FROM $_table WHERE uniqid=%s", $data['uniqid']));
            // CACHE RESULT
            wp_cache_set($cache_id, $var, '', 60);
        }

        /* Create */
        if (!$var) {
            $wpdb->insert(
                $_table,
                $data
            );
        }
        /* Update */
        else {
            $wpdb->update(
                $_table,
                $data,
                array('ID' => $var)
            );
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

    public function set_post_thumbnail_from_url($url, $post_id) {
        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Import image as an attachment
        $image = media_sideload_image($url, $post_id, '', 'id');

        // set image as the post thumbnail
        set_post_thumbnail($post_id, $image);

    }

    public function upload_if_not_exists($file, $reference_name, $meta_key = false) {
        global $wpdb;
        if (!$meta_key) {
            $meta_key = 'wpuwoo_imgbasename';
        }

        $reference_name = strtolower($reference_name);

        /* Search image in database */
        $cache_id = 'wpuwoo_imgbasename_' . md5($reference_name);
        $image_id = wp_cache_get($cache_id);
        if ($image_id === false) {
            $image_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value=%s", $meta_key, $reference_name));
            wp_cache_set($cache_id, $image_id, '', DAY_IN_SECONDS);
        }

        /* If not found */
        if (!is_numeric($image_id)) {

            /* Upload image */
            $image_id = $this->upload_file($file);

            /* Save reference name */
            add_post_meta($image_id, $meta_key, $reference_name);

        }

        return $image_id;

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

    public function delete_post_type($post_type = '', $args = array()) {
        if (!$post_type) {
            return false;
        }

        if (!is_array($args)) {
            $args = array();
        }
        if (!isset($args['debug_type'])) {
            $args['debug_type'] = 'log';
        }
        if (!isset($args['post_status'])) {
            $args['post_status'] = 'any';
        }

        /* Delete all */
        $posts = get_posts(array(
            'fields' => 'ids',
            'post_type' => $post_type,
            'post_status' => $args['post_status'],
            'numberposts' => -1
        ));
        $total = count($posts);
        foreach ($posts as $i => $post_item) {
            $message = ($i + 1) . '/' . $total . ' : Deleting post type ' . $post_type . ' #' . $post_item;
            wp_delete_post($post_item, true);
            $this->debug_message($debug_message, $args['debug_type']);
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

    public function get_csv_from_datas($datas = array(), $delimiter = ",", $enclosure = '"', $columns = array()) {
        if (!is_array($datas) || empty($datas)) {
            return '';
        }
        ob_start();
        $out = fopen('php://output', 'w');
        if (!is_array($columns) || empty($columns)) {
            $columns = array_keys(current($datas));
        }

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

    public function get_datas_from_json($json_file) {
        if (!file_exists($json_file)) {
            error_log('JSON File do not exists');
            return false;
        }
        $raw_data = file_get_contents($json_file);
        $data = json_decode($raw_data, true);
        if (!is_array($data)) {
            error_log('Invalid JSON file :' . basename($json_file));
            return false;
        }
        return $data;
    }

    public function get_datas_from_csv($csv_file, $use_first_line = true, $delimiter = ",", $enclosure = '"', $allow_numbers = false) {
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
                            if ($allow_numbers) {
                                $line_text = preg_replace('/([^0-9a-z_]+)/', '', $line_text);

                            } else {
                                $line_text = preg_replace('/([^a-z_]+)/', '', $line_text);

                            }
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
    public function send_file_to_ftp($file, $remotefile, $host, $user, $password, $port = 21, $passive = true) {
        $conn_id = $this->get_connect_id($host, $user, $password, $port);
        if (!$conn_id) {
            return false;
        }
        ftp_pasv($conn_id, $passive);
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
        $conn_id = $this->get_connect_id($host, $user, $password, $port);
        if (!$conn_id) {
            return false;
        }
        $return = ftp_get($conn_id, $file, $remotefile, FTP_BINARY);
        ftp_close($conn_id);
    }

    /**
     * Get a file list from FTP
     * @param  [type]  $folder   [description]
     * @param  [type]  $host     [description]
     * @param  [type]  $user     [description]
     * @param  [type]  $password [description]
     * @param  integer $port     [description]
     * @return [type]            [description]
     */
    public function get_file_list_from_ftp($folder, $host, $user, $password, $port = 21, $passive = true) {
        $conn_id = $this->get_connect_id($host, $user, $password, $port);
        if (!$conn_id) {
            return false;
        }
        ftp_pasv($conn_id, $passive);
        $contents = ftp_nlist($conn_id, $folder);
        ftp_close($conn_id);
        return $contents;
    }

    /**
     * Get a FTP Connect ID
     * @param  [type]  $host     [description]
     * @param  [type]  $user     [description]
     * @param  [type]  $password [description]
     * @param  integer $port     [description]
     * @return [type]            [description]
     */
    public function get_connect_id($host, $user, $password, $port = 21) {
        $conn_id = ftp_connect($host, $port);
        if (!$conn_id) {
            error_log('FTP Connect did not work');
            return false;
        }
        if (!ftp_login($conn_id, $user, $password)) {
            error_log('FTP Login did not work');
            ftp_close($conn_id);
            return false;
        }
        return $conn_id;
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

    public function debug_message($message = '', $debug_type = '') {
        switch ($debug_type) {
        case 'all':
            $this->print_message($message);
            error_log($message);
        case 'print':
        case 'message':
            $this->print_message($message);
            break;
        case 'log':
        default:
            error_log($message);
        }

    }

    public function print_message($message = '') {
        $message = is_array($message) ? implode("\t", $message) : $message;
        echo $message;
        $this->line_break();
    }

    public function print_error($message = '') {
        $message = is_array($message) ? implode("\t", $message) : $message;
        $_error_flag = ' /!\\ ';
        echo "\e[0;31m" . $_error_flag . $message . $_error_flag . "\e[0m\n";
        $this->line_break();
        die;
    }

    public function line_break() {
        echo "\n";
        @flush();
        @ob_flush();
    }

    public function remove_accents($name) {
        if (!$this->is_utf8($name)) {
            $name = utf8_encode($name);
        }
        $name = strtr(utf8_decode($name), utf8_decode('àáâãäçèéêëìíîïñòóôõöōùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinoooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
        return $name;
    }

    /* Thx http://php.net/manual/fr/function.mb-detect-encoding.php#50087 */
    public function is_utf8($string) {
        return preg_match('%^(?:
              [\x09\x0A\x0D\x20-\x7E]            # ASCII
            | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
            |  \xE0[\xA0-\xBF][\x80-\xBF]        # excluding overlongs
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
            |  \xED[\x80-\x9F][\x80-\xBF]        # excluding surrogates
            |  \xF0[\x90-\xBF][\x80-\xBF]{2}     # planes 1-3
            | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
            |  \xF4[\x80-\x8F][\x80-\xBF]{2}     # plane 16
        )*$%xs', $string);

    }

    public function remove_images($content) {

        /* - Remove captions */
        $content = preg_replace('/\[caption(.*)\[\/caption\]/isU', '', $content);

        /* - Remove remaining images */
        $content = preg_replace("/<p([^>]*)>([\s]*)<a([^>]*)>([\s]*)<img([^>]*)>([\s]*)<\/a>([\s]*)<\/p>/", "\n\n", $content);
        $content = preg_replace("/<a([^>]*)>([\s]*)<img([^>]*)>([\s]*)<\/a>/", "\n\n", $content);
        $content = preg_replace("/<p([^>]*)>([\s]*)<img([^>]*)>([\s]*)<\/p>/", "\n\n", $content);
        $content = preg_replace("/<img([^>]*)>/", "\n\n", $content);

        /* - Remove multiple line breaks */
        $content = preg_replace("/([\r\n]{3,})/", "\n\n", $content);

        return $content;
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

/* ----------------------------------------------------------
  WPDB Override
---------------------------------------------------------- */

/**
 * Temporary switch to another database
 */
class WPUWooImportExport_WPDBOverride {
    private $table_prefix;
    private $wpdb_tmp;

    public function __construct($autoload = true) {
        if ($autoload) {
            $this->connect();
        }
    }

    public function __destruct() {
        $this->disconnect();
    }

    /* Login / Logout */

    public function connect($db_name = false, $db_prefix = false, $db_user = false, $db_password = false, $db_host = false) {
        global $wpdb, $table_prefix;

        /* Get values */
        $db_name = $this->get_db_name($db_name);
        $db_prefix = $this->get_db_prefix($db_prefix);
        $db_user = $this->get_db_user($db_user);
        $db_password = $this->get_db_password($db_password);
        $db_host = $this->get_db_host($db_host);

        /* Backup old database */
        $this->wpdb_tmp = $wpdb;

        /* Store old prefix */
        $this->table_prefix = $table_prefix;

        /* Switch values */
        $wpdb = new wpdb($db_user, $db_password, $db_name, $db_host);
        $wpdb->select($db_name);
        $wpdb->set_prefix($db_prefix);
    }

    public function disconnect() {
        global $wpdb, $table_prefix;
        /* Restore db */
        $wpdb = $this->wpdb_tmp;

        /* Switch back values */
        $wpdb->select(DB_NAME);
        $wpdb->set_prefix($this->table_prefix);
        $table_prefix = $this->table_prefix;
    }

    /* Getters */

    /**
     * Try to load automatically DB Name
     */
    public function get_db_name($db_name) {
        if (!$db_name) {
            try {
                $db_name = OLD_DB_NAME;
            } catch (Exception $e) {
                var_dump($e);
                die;
            }
        }
        return $db_name;
    }

    /**
     * Try to load automatically DB Prefix
     */
    public function get_db_prefix($db_prefix) {
        if (!$db_prefix) {
            try {
                $db_prefix = OLD_DB_PREFIX;
            } catch (Exception $e) {
                var_dump($e);
                die;
            }
        }
        return $db_prefix;
    }

    /**
     * Try to load automatically DB User
     */
    public function get_db_user($db_user) {
        if (!$db_user) {
            try {
                $db_user = OLD_DB_USER;
            } catch (Exception $e) {
                var_dump($e);
                die;
            }
        }
        return $db_user;
    }

    /**
     * Try to load automatically DB Password
     */
    public function get_db_password($db_password) {
        if (!$db_password) {
            try {
                $db_password = OLD_DB_PASSWORD;
            } catch (Exception $e) {
                var_dump($e);
                die;
            }
        }
        return $db_password;
    }

    /**
     * Try to load automatically DB Host
     */
    public function get_db_host($db_host) {
        if (!$db_host) {
            try {
                $db_host = OLD_DB_HOST;
            } catch (Exception $e) {
                var_dump($e);
                die;
            }
        }
        return $db_host;
    }

}
