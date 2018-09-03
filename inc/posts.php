<?php

/*
* Posts V 0.2.1
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
        # REMOVE UNUSED
        unset($post->guid);
        unset($post->to_ping);
        unset($post->pinged);
        unset($post->ID);
        unset($post->post_parent);
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
            # Copy attachment object
            $att_dirname = get_attached_file($attachment_raw->ID, 1);
            $att = array(
                'ID' => $attachment_raw->ID,
                'filename' => basename(get_attached_file($attachment_raw->ID, 1))
            );
            $attachments[] = $att;
            # Copy file
            copy($att_dirname, $dir['files'] . $att['filename']);
        }
        file_put_contents($dir['full'] . 'attachments.json', json_encode($attachments));

    }

    public function import_post($post_id, $options = array()) {
        # GET DIR
        $dir = $this->get_upload_dir($post_id);

        # GET POST OBJECT
        $post = $this->get_array_from_file($dir['full'] . 'post.json');
        if (!is_array($post)) {
            echo '# Invalid post';
            return false;
        }

        # IMPORT OBJECT WITHOUT SOME LINES
        $new_post_id = wp_insert_post($post);

        if (!is_numeric($new_post_id)) {
            echo '# Post was not created';
            return false;
        }

        # GET ATTACHMENTS
        $_attachments_table = array();
        $_attachments_link = array();
        $attachments = $this->get_array_from_file($dir['full'] . 'attachments.json');
        if (is_array($attachments)) {
            # EXTRACT IDS AND FILES IN A LIST
            foreach ($attachments as $att) {
                $att = (array) $att;
                $filepath = $dir['files'] . $att['filename'];
                if (!file_exists($filepath)) {
                    echo '- File does not exists : skipping this.';
                    continue;
                }

                # LOAD FILE
                $file_up = $this->upload_file($filepath, $new_post_id);
                if (!is_numeric($file_up)) {
                    echo '- File could not be uploaded : skipping this.';
                    continue;
                }
                $att['new_id'] = $file_up;
                $_attachments_table[] = $att;
                $_attachments_link[$att['ID']] = $file_up;
            }

        } else {
            echo '- Attachments are invalid or do not exists : skipping this.';
        }

        $find_attachments_metas = (is_array($options) && isset($options['find_attachments_metas']) && $options['find_attachments_metas']);

        # GET METAS
        $metas = $this->get_array_from_file($dir['full'] . 'metas.json');
        foreach ($metas as $key => $value) {
            if (is_array($value)) {
                $value = $value[0];
            }

            # CONVERT OLD ATT IDS NEW ATT IDS
            do {
                if (empty($_attachments_link)) {
                    return;
                }

                /* IF NUMERIC */
                if (!is_numeric($value)) {
                    break;
                }

                if ($key != '_thumbnail_id' && !$find_attachments_metas) {
                    break;
                }

                /* Convert if possible */
                $value = intval($value, 10);
                if (array_key_exists($value, $_attachments_link)) {
                    $value = $_attachments_link[$value];
                }

            } while (0);

            # IMPORT METAS
            update_post_meta($new_post_id, $key, $value);
        }

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

    public function get_array_from_file($_file) {
        if (!file_exists($_file)) {
            return '# File does not exists';
        }
        $_file = file_get_contents($_file);
        $_object = json_decode($_file);
        if (is_array($_object)) {
            return $_object;
        }
        if (!is_object($_object)) {
            return '# Object is not valid';
        }
        return (array) $_object;
    }

}
