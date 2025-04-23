<?php

/*
Name: WPU Woo Import/Export
Version: 0.45.3
Description: A CLI utility to import/export orders & products in WooCommerce
Author: Darklg
Author URI: https://darklg.me/
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

$wpuwooimportexport_is_bootstraped = !defined('ABSPATH');
if (!isset($wpuwooimportexport_emulate_wp_admin)) {
    $wpuwooimportexport_emulate_wp_admin = true;
}

/* ----------------------------------------------------------
  Check PHP version
---------------------------------------------------------- */

$min_php_version = '7.4';
if (version_compare(phpversion(), $min_php_version, '<')) {
    echo 'PHP ' . $min_php_version . ' is required.';
    die;
}

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
    if ($wpuwooimportexport_emulate_wp_admin) {
        define('WP_ADMIN', true);
        $_SERVER['PHP_SELF'] = '/wp-admin/index.php';
    }

    if (defined('WPUWOO__WPLOAD_FILE') && file_exists(WPUWOO__WPLOAD_FILE)) {
        /*
         * Use a specific bootstrap :
         * define('WPUWOO__WPLOAD_FILE','/home/mywebsite/wordpress/wp-load.php');
         */
        $bootstrap = WPUWOO__WPLOAD_FILE;
        chdir(dirname($bootstrap));
    } else {
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
    }
    require_once $bootstrap;

    /* Require some functions if W3TC is installed */
    $admin_path = str_replace(get_bloginfo('url') . '/', ABSPATH, get_admin_url());
    require_once $admin_path . '/includes/screen.php';

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
    public $wpuwooimportexport_create_or_update_post_status = array(
        'publish',
        'private',
        'future'
    );

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
        if (!isset($data['args']) || !is_array($data['args'])) {
            $data['args'] = array();
        }
        if (!isset($data['args']['name'])) {
            $data['args']['name'] = $data['term_name'];
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
            $term = wp_insert_term($data['term_name'], $data['taxonomy'], $data['args']);
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

            $data = apply_filters('wpuwooimportexport__create_or_update_term_from_datas__data_before_update', $data);

            /* Update term basic informations */
            $term = wp_update_term($_term_id, $data['taxonomy'], $data['args']);

            /* Ignore some errors which can be benign for our use */
            $ignored_errors_slugs = apply_filters('wpuwooimportexport__create_or_update_term_from_datas__ignored_errors_slugs', array());
            if (is_wp_error($term)) {
                foreach ($term->errors as $error_key => $error_content) {
                    if (!in_array($error_key, $ignored_errors_slugs)) {
                        return false;
                    }
                }
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
    public function create_or_update_post_from_datas($data = array(), $search = array(), $check_uniqid = false, $get_status = false, $args = array()) {
        global $wpdb;
        if (empty($search) || !is_array($search)) {
            return false;
        }

        $data = $this->prepare_post_data($data);

        if (!isset($data['metas']['uniqid']) && isset($search['uniqid'])) {
            $data['metas']['uniqid'] = $search['uniqid'];
        }

        if (!isset($args['post_id'])) {

            $args = array(
                'posts_per_page' => 1,
                'post_type' => $data['post_type'],
                'post_status' => apply_filters('wpuwooimportexport_create_or_update_post_status', $this->wpuwooimportexport_create_or_update_post_status),
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
                if (isset($args['uniqid_type'])) {
                    $uniqid_type = $args['uniqid_type'];
                }
                $v = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = $uniqid_type LIMIT 0,1", 'uniqid', $search['uniqid']));
                if (!is_null($v) && $v == $search['uniqid']) {
                    $uniqid_found = true;
                }
            }

            $results = (($check_uniqid && $uniqid_found) || !$check_uniqid) ? get_posts($args) : array();

            if (!$results || !isset($results[0])) {
                $return = $this->create_post_from_data($data);
                /* Cache uniqid "forever" */
                if (isset($search['uniqid']) && $check_uniqid) {
                    wp_cache_set('wpuwoo_uniqid_' . $search['uniqid'], $return, '');
                }
                if ($get_status && is_numeric($return)) {
                    $return = array('created', $return);
                }
                return $return;
            }

            $post_id = $results[0]->ID;
            $data = apply_filters('wpuwooimportexport_create_or_update_post_data_before_update', $data, $post_id);

        } else {
            $post_id = $args['post_id'];
        }

        $return = $this->update_post_from_data($post_id, $data);
        if (is_numeric($return) && isset($search['uniqid']) && $check_uniqid) {
            wp_cache_set('wpuwoo_uniqid_' . $search['uniqid'], $return, '');
        }
        if ($get_status && is_numeric($return)) {
            $return = array('updated', $return);
        }
        return $return;
    }

    /* Search uniqid
    -------------------------- */

    public function get_post_by_uniqid($uniqid = false) {
        global $wpdb;
        if (!$uniqid) {
            return false;
        }
        $uniqid_type = is_numeric($uniqid) ? '%d' : '%s';

        $cache_id = 'wpuwoo_uniqid_' . $uniqid;
        $post_id = wp_cache_get($cache_id);
        if (!is_numeric($post_id)) {
            $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = $uniqid_type", 'uniqid', $uniqid));
            wp_cache_set($cache_id, $post_id, '', 3600);
        }

        return $post_id;
    }

    /* Index Uniqid
    -------------------------- */

    public function index_uniqid($uniqid, $post_type = false) {
        $index = array();
        global $wpdb;

        $sql = $wpdb->prepare("SELECT post_id,meta_value FROM $wpdb->postmeta WHERE $wpdb->postmeta.meta_key = %s", $uniqid);
        if ($post_type) {
            $statuses = apply_filters('wpuwooimportexport_create_or_update_post_status', $this->wpuwooimportexport_create_or_update_post_status);
            $statuses_sql = array();
            foreach ($statuses as $status) {
                $statuses_sql[] = "'" . esc_sql($status) . "'";
            }
            $sql .= " AND post_id IN (";
            $sql .= $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type=%s", $post_type);
            $sql .= " AND post_status IN(" . implode(',', $statuses_sql) . ")";
            $sql .= " )";
        }

        $results = $wpdb->get_results($sql);

        foreach ($results as $item) {
            $index[$item->meta_value] = $item->post_id;
        }
        unset($results);
        return $index;
    }

    /* Prepare post datas
    -------------------------- */

    public function prepare_post_data($data = array()) {
        if (!is_array($data)) {
            echo 'Invalid data';
            die;
        }

        $data = apply_filters('wpuwooimportexport__prepare_post_data__before', $data);

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
                $k = substr($key, 6);
                if (!isset($data['terms'][$k])) {
                    $data['terms'][$k] = array();
                }
                $value = explode(',', $value);
                $value = array_map('trim', $value);
                foreach ($value as $v) {
                    $data['terms'][$k][] = $v;
                }
            }
        }

        /* Metas */
        if (!isset($data['metas'])) {
            $data['metas'] = array();
        }

        foreach ($data as $key => $value) {
            if (substr($key, 0, 6) == 'meta__') {
                $data['metas'][substr($key, 6)] = $value;
                continue;
            }
            /* Not a native post key : use as meta */
            if ($key != 'metas' && !in_array($key, $this->post_keys) && !is_array($value) && substr($key, 0, 6) != 'term__') {
                $data['metas'][$key] = $value;
            }
        }

        foreach ($data['metas'] as $key => $value) {
            if (substr($key, 0, 7) == 'array__') {
                $data['metas'][substr($key, 7)] = explode(",", $value);
                unset($data['metas'][$key]);
                continue;
            }
        }

        $data = apply_filters('wpuwooimportexport__prepare_post_data__after', $data);

        return $data;

    }

    /* ----------------------------------------------------------
      Sync posts and link them via Polylang
    ---------------------------------------------------------- */

    public function sync_post_pll($uniqid, $languages = array()) {
        if (!is_array($languages) || empty($languages)) {
            $languages = array(
                'fr' => array(),
                'en' => array()
            );
        }

        $posts = array();
        foreach ($languages as $lang_id => $values) {
            $lang_uniqid = $lang_id . '-' . $uniqid;
            $post_id = $this->create_or_update_post_from_datas($values, array(
                'uniqid' => $lang_uniqid
            ));
            update_post_meta($post_id, 'uniqid', $lang_uniqid);
            pll_set_post_language($post_id, $lang_id);
            $posts[$lang_id] = $post_id;
        }

        if (function_exists('pll_save_post_translations')) {
            pll_save_post_translations($posts);
        }

        return $posts;
    }

    /* ----------------------------------------------------------
      Sync terms and link them via Polylang
    ---------------------------------------------------------- */

    public function sync_term_pll($uniqid, $languages = array()) {
        if (!is_array($languages) || empty($languages)) {
            $languages = array(
                'fr' => array(),
                'en' => array()
            );
        }

        $terms = array();
        foreach ($languages as $lang_id => $values) {
            $values['term_name'] = $lang_id . '-' . $values['term_name'];
            $term_item = $this->create_or_update_term_from_datas($values, array(
                'uniqid' => $lang_id . '-' . $uniqid
            ));
            update_term_meta($term_item, 'uniqid', $lang_id . '-' . $uniqid);
            pll_set_term_language($term_item, $lang_id);
            $terms[$lang_id] = $term_item;
        }

        if (function_exists('pll_save_post_translations')) {
            pll_save_term_translations($terms);
        }

        return $terms;
    }

    /* ----------------------------------------------------------
      SYNC
    ---------------------------------------------------------- */

    public function sync_posts_from_csv($csv_file, $settings = array()) {

        /* Settings */
        $_default_settings = array(
            'debug_type' => '',
            'use_first_line' => null,
            'delimiter' => null,
            'enclosure' => null,
            'allow_numbers' => null
        );

        if (!is_array($settings)) {
            $settings = array();
        }
        foreach ($_default_settings as $key => $setting) {
            if (!isset($settings[$key])) {
                $settings[$key] = $setting;
            }
        }

        /* Try to extract datas */
        $datas = $this->get_datas_from_csv($csv_file, $settings['use_first_line'], $settings['delimiter'], $settings['enclosure'], $settings['allow_numbers']);

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

    public function create_csv_from_datas($datas = array(), $export_file = 'test.csv', $delimiter = ",", $enclosure = '"', $columns = array(), $args = array()) {
        if(!is_array($args)){
            $args = array();
        }
        $args = array_merge(array(
            'force_utf8' => true
        ), $args);

        $csv = $this->get_csv_from_datas($datas, $delimiter, $enclosure, $columns);
        if ($args['force_utf8'] && !$this->is_utf8($csv)) {
            $csv = utf8_encode($csv);
        }
        $fpc = file_put_contents($export_file, $csv);
        if ($fpc) {
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

        $return_type = 'create';

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
            $return_type = 'update';
        }

        return $return_type;
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

    /**
     * Check if an URL is already a local attachment
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    public function url_is_local_attachment($url) {

        $upload_dir = wp_get_upload_dir();
        $baseurl = $upload_dir['baseurl'];
        $baseurl_len = strlen($baseurl);
        if (substr($url, 0, $baseurl_len) != $baseurl) {
            return false;
        }

        /* Extract only the file path relative to the uploads dir */
        $url = str_replace($baseurl . '/', '', $url);

        /* Thanks to https://core.trac.wordpress.org/ticket/41816#comment:7 */
        /* Remove dimensions from URL */
        $url_nonumber = preg_replace('/-\d+[Xx]\d+\./', '.', $url);

        /* Look for the attachment */
        $att_id = attachment_url_to_postid($url_nonumber);
        if ($att_id) {
            return $att_id;
        }

        /* No attachment can be found, search scaled version */
        $url_nonumber_noscaled = preg_replace('/-\d+[Xx]\d+\./', '-scaled.', $url);
        return attachment_url_to_postid($url_nonumber_noscaled);
    }

    /**
     * Set an URL as post thumbnail
     * @param [type]  $url     [description]
     * @param integer $post_id [description]
     */
    public function set_post_thumbnail_from_url($url, $post_id = 0) {

        $image_id = $this->upload_image_from_url($url, $post_id);

        // set image as the post thumbnail
        if ($post_id) {
            set_post_thumbnail($post_id, $image_id);
        }

        return $image_id;
    }

    /**
     * Try to get the real image URL if it's hosted by a service
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    public function extract_real_image_url($url) {
        if (!class_exists('DOMDocument')) {
            return $url;
        }
        $url_parts = parse_url($url);
        if (!isset($url_parts['host'])) {
            return $url;
        }
        if ($url_parts['host'] == 'drive.google.com') {

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $page_content = file_get_contents(download_url($url));

            $dom_obj = new DOMDocument();
            $dom_obj->loadHTML($page_content);
            $meta_val = null;

            foreach ($dom_obj->getElementsByTagName('meta') as $meta) {
                if ($meta->getAttribute('property') == 'og:image') {
                    $meta_val = $meta->getAttribute('content');
                }

                if ($meta->getAttribute('itemprop') == 'name') {
                    $meta_name = $meta->getAttribute('content');
                }
            }

            // Avoid Google Drive's resize
            $meta_val_parts = explode('=', $meta_val);
            if (!isset($meta_val_parts[0])) {
                return false;
            }
            $url = $meta_val_parts[0];
            if ($meta_name) {
                $url .= '?' . $meta_name;
            }
        }
        return $url;
    }

    /**
     * Upload an image from an URL
     * @param  [type]  $url     [description]
     * @param  integer $post_id [description]
     * @return [type]           [description]
     */
    public function upload_image_from_url($url, $post_id = 0) {
        if (!$url) {
            return 0;
        }

        // Clean URL
        $url = $this->extract_real_image_url($url);

        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Import image as an attachment
        return media_sideload_image($url, $post_id, '', 'id');
    }

    /**
     * Upload an image if it is not present in the media library
     * @param  [type]  $file           [description]
     * @param  boolean $reference_name [description]
     * @param  boolean $meta_key       [description]
     * @param  boolean $force          [description]
     * @return [type]                  [description]
     */
    public function upload_if_not_exists($file, $reference_name = false, $meta_key = false, $force = false) {
        global $wpdb;
        if (!$meta_key) {
            $meta_key = 'wpuwoo_imgbasename';
        }

        if (!$reference_name) {
            $reference_name = basename($file);
        }

        $reference_name = strtolower($reference_name);

        /* Search image in database */
        $cache_id = 'wpuwoo_imgbasename_' . md5($reference_name);
        $image_id = wp_cache_get($cache_id);
        if ($image_id === false || !is_numeric($image_id)) {
            $image_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value=%s ORDER BY meta_id DESC", $meta_key, $reference_name));
            wp_cache_set($cache_id, $image_id, '', DAY_IN_SECONDS);
        }

        /* If not found */
        if (!is_numeric($image_id) || $force) {

            /* If the file happens to be an URL */
            if (filter_var($file, FILTER_VALIDATE_URL) !== FALSE) {
                $image_id = $this->upload_image_from_url($file);
            } else {
                $image_id = $this->upload_file($file);
            }

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

        /* Select all */
        $posts = get_posts(array(
            'fields' => 'ids',
            'post_type' => $post_type,
            'post_status' => $args['post_status'],
            'numberposts' => -1
        ));
        $total = count($posts);

        if ($total < 1) {
            $this->debug_message('No ' . $post_type . ' found', $args['debug_type']);
            return true;
        }

        foreach ($posts as $i => $post_item) {
            $message = ($i + 1) . '/' . $total . ' : Deleting post type ' . $post_type . ' #' . $post_item;
            wp_delete_post($post_item, true);
            $this->debug_message($message, $args['debug_type']);
        }
        return true;
    }
    /* ----------------------------------------------------------
      DELETE ALL TAX TERMS
    ---------------------------------------------------------- */

    public function delete_tax($tax_slug = '', $args = array()) {
        if (!$tax_slug) {
            return false;
        }

        if (!is_array($args)) {
            $args = array();
        }
        if (!isset($args['debug_type'])) {
            $args['debug_type'] = 'log';
        }

        /* Select all */
        $terms = get_terms(array(
            'taxonomy' => $tax_slug,
            'hide_empty' => false
        ));

        if (is_wp_error($terms)) {
            $this->debug_message($tax_slug . ' is not a valid tax', $args['debug_type']);
            return true;
        }

        $total = count($terms);

        if ($total < 1) {
            $this->debug_message('No ' . $tax_slug . ' found', $args['debug_type']);
            return true;
        }

        foreach ($terms as $i => $term_item) {
            $message = ($i + 1) . '/' . $total . ' : Deleting term ' . $tax_slug . ' #' . $term_item->term_id;
            wp_delete_term($term_item->term_id, $tax_slug);
            $this->debug_message($message, $args['debug_type']);
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

    public function get_datas_from_xml($xml_file) {
        if (!file_exists($xml_file)) {
            error_log('XML File do not exists');
            return false;
        }
        $xml = simplexml_load_file($xml_file);
        if (!$xml) {
            error_log('Invalid XML file :' . basename($xml_file));
            return false;
        }
        $data = array();
        foreach ($xml->children() as $item) {
            $data[] = array_change_key_case((array) $item, CASE_LOWER);
        }
        if (!is_array($data)) {
            error_log('Invalid XML file :' . basename($xml_file));
            return false;
        }
        return $data;
    }

    public function get_datas_from_csv($csv_file, $use_first_line = true, $delimiter = ",", $enclosure = '"', $allow_numbers = false) {
        if (!file_exists($csv_file)) {
            error_log('CSV File do not exists');
            return false;
        }

        if (is_null($use_first_line)) {
            $use_first_line = true;
        }
        if (!is_string($delimiter) || is_null($delimiter)) {
            $delimiter = ",";
        }
        if (!is_string($delimiter) || is_null($enclosure)) {
            $enclosure = '"';
        }
        if (is_null($allow_numbers)) {
            $allow_numbers = false;
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

    /* Try to get a tmp CSV file from an upload */
    public function get_csv_from_file_upload($file = array()) {

        /* Check upload */
        if ($file['error'] != '0') {
            return false;
        }

        /* Check extension */
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if ($ext != 'csv') {
            return false;
        }

        /* Check mime type */
        if (!in_array($file['type'], array(
            'text/plain',
            'application/vnd.ms-excel',
            'text/csv',
            'text/x-csv'
        ))) {
            return false;
        }

        /* Return tmp file */
        return $file['tmp_name'];
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
      Set post term
    ---------------------------------------------------------- */

    /**
     * Set post terms from a string
     * @param int    $post_id
     * @param string $term_names
     * @param string $taxonomy
     */
    public function wp_set_post_terms($post_id, $term_names, $taxonomy = 'category') {
        if (!$term_names) {
            return false;
        }
        if (!is_array($term_names)) {
            $term_names = array_filter(array_map('trim', explode(';', $term_names)));
        }
        $term_ids = array();
        foreach ($term_names as $term_name) {
            $term = term_exists($term_name, $taxonomy);
            if (!$term) {
                $term = wp_insert_term($term_name, $taxonomy);
            }
            $term_ids[] = intval($term['term_id']);
        }
        if (!$term_ids) {
            return false;
        }
        wp_set_post_terms($post_id, $term_ids, $taxonomy, false);
        return $term_ids;
    }

    /* ----------------------------------------------------------
      Get from remote URL
    ---------------------------------------------------------- */

    public function get_from_remote_url($remote_url, $args = array(), $cache_settings = array()) {

        /* Default args */
        if (!is_array($args)) {
            $args = array();
        }

        /* Cache settings */
        if (!is_array($cache_settings)) {
            $cache_settings = array();
        }
        if (!isset($cache_settings['age'])) {
            $cache_settings['age'] = 0;
        }
        $has_cache = $cache_settings['age'] > 0;
        if (!isset($cache_settings['dir'])) {
            $cache_settings['dir'] = ABSPATH . '../cache/';
        }
        if (!isset($cache_settings['name'])) {
            $cache_settings['name'] = md5($remote_url . json_encode($args));
        }
        if ($has_cache && !is_dir($cache_settings['dir'])) {
            mkdir($cache_settings['dir']);
        }
        $cache_file = $cache_settings['dir'] . $cache_settings['name'];
        if ($has_cache && file_exists($cache_file) && time() - filemtime($cache_file) < $cache_settings['age']) {
            return file_get_contents($cache_file);
        }

        /* Making the call */
        $response = wp_remote_get($remote_url, $args);
        $result_body = wp_remote_retrieve_body($response);
        if (!$result_body) {
            $this->print_message('Error : Could not retrieve URL');
            $this->print_message('- URL : ' . $remote_url);
            $this->print_message('- Response : ' . json_encode($response));
            return false;
        }

        /* Building cache */
        if ($has_cache) {
            file_put_contents($cache_file, $result_body);
        }

        /* Sending result */
        return $result_body;
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

    /* Thanks to https://stackoverflow.com/a/12109100 */
    public function glob_recursive($pattern, $flags = 0) {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, $this->glob_recursive($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }

    public function get_cli_args() {
        global $argv;
        $args = array();
        foreach ($argv as $arg) {
            if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
                $args[$matches[1]] = $matches[2];
            }
        }
        return $args;
    }

    public function switch_multisite($args = array()) {
        if (empty($args)) {
            $this->print_message('WPUWOOImportExport - Error : No arguments found.');
            die;
        }

        if (is_multisite()) {
            if (!isset($args['blog_id']) || !is_numeric($args['blog_id'])) {
                $this->print_message('WPUWOOImportExport - Error : Missing blog_id.');
                die;
            }
            switch_to_blog($args['blog_id']);
        }

    }

    /* ----------------------------------------------------------
      SFTP
    ---------------------------------------------------------- */

    /**
     * Copy a file to a SFTP server.
     * @param string $filename The path to the file to be copied.
     * @param array $sftp_infos An array containing the SFTP connection details.
     * @return void
     */
    public function copy_file_to_sftp($filename, $sftp_infos = array()) {

        /* Check for ssh2_sftp availability */
        if (!function_exists('ssh2_sftp')) {
            $this->print_message('The ssh2.sftp wrapper is not available.');
            return false;
        }
        /* Check values */
        if (!file_exists($filename)) {
            $this->print_message('The file does not exist: ' . $filename);
            return false;
        }
        if (!is_array($sftp_infos) || !isset($sftp_infos['host'], $sftp_infos['user'], $sftp_infos['pass'], $sftp_infos['dir'])) {
            $this->print_message('SFTP infos are not valid');
            return false;
        }
        if (!isset($sftp_infos['port']) || !$sftp_infos['port']) {
            $sftp_infos['port'] = 22;
        }

        /* Init SFTP connection */
        $sftp = ssh2_connect($sftp_infos['host'], $sftp_infos['port']);
        ssh2_auth_password($sftp, $sftp_infos['user'], $sftp_infos['pass']);
        $sftp_stream = ssh2_sftp($sftp);
        $dir = "ssh2.sftp://{$sftp_stream}/{$sftp_infos['dir']}/";

        $remote_stream = fopen($dir . basename($filename), 'w');
        $local_stream = fopen($filename, 'r');

        if (!$remote_stream) {
            $this->print_message('Could not open remote file: ' . $filename);
            return false;
        }
        if (!$local_stream) {
            $this->print_message('Could not open local file: ' . $filename);
            return false;
        }

        stream_copy_to_stream($local_stream, $remote_stream);

        fclose($local_stream);
        fclose($remote_stream);

        return true;

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
