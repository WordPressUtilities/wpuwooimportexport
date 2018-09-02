<?php

/*
* Posts V 0.1.0
*/

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Posts extends WPUWooImportExport {
    public function __construct() {
        parent::__construct();
    }

    public function export_post($post_id) {
        # TEST POST ID
        if (!is_numeric($post_id)) {
            return false;
        }

        # GET DIR
        $dir = $this->get_upload_dir($post_id);

        # SAVE POST
        $post = get_post($post_id);
        file_put_contents($dir['full'] . 'post.json', json_encode($post));

        # SAVE METAS
        $post_metas = get_post_meta($post_id);
        file_put_contents($dir['full'] . 'metas.json', json_encode($post_metas));

        # SAVE ATTACHMENTS
        $attachments_raw = get_posts(array(
            'post_type' => 'attachment',
            'numberposts' => -1,
            'post_status' => 'any',
            'post_mime_type' => 'image',
            'post_parent' => $post_id
        ));
        $attachments = array();
        foreach ($attachments_raw as $attachment_raw) {
            #
            $attachment = $attachment_raw;
            $attachment->dirname = get_attached_file($attachment_raw->ID, 1);
            $attachment->filename = basename(get_attached_file($attachment_raw->ID, 1));
            $attachments[] = $attachment;
            # Copy file
            copy($attachment->dirname, $dir['files'] . $attachment->filename);
        }
        file_put_contents($dir['full'] . 'attachments.json', json_encode($attachments));

    }

    public function import_post($id) {
        # TEST POST ID
        if (!is_numeric($post_id)) {
            return false;
        }

        # GET DIR
        $dir = $this->get_upload_dir($post_id);
    }

    /* DIRS */

    public function get_upload_dir($post_id) {
        $upload_dir = wp_upload_dir();
        $dir = array();
        $dir['full'] = $upload_dir['basedir'] . '/export/post/' . $post_id . '/';
        $dir['files'] = $dir['full'] . 'files/';
        if (!is_dir($dir['full'])) {
            mkdir($dir['full'], 0755, 1);
        }
        if (!is_dir($dir['files'])) {
            mkdir($dir['files'], 0755, 1);
        }
        return $dir;
    }

}
