<?php

/*
* Posts V 0.3.5
*/

include dirname(__FILE__) . '/bootstrap.php';

class WPUWooImportExport_Posts extends WPUWooImportExport {
    public function __construct() {
        parent::__construct();
    }

    public function export_post($post_id, $options = array()) {
        # TEST POST ID
        if (!is_numeric($post_id)) {
            return false;
        }

        # SET OPTIONS
        if (!is_array($options)) {
            $options = array();
        }
        if (!isset($options['folder'])) {
            $options['folder'] = false;
        }
        if (!isset($options['skip_empty_metas'])) {
            $options['skip_empty_metas'] = false;
        }
        if (!isset($options['export_author_slug'])) {
            $options['export_author_slug'] = false;
        }
        if (!isset($options['create_nonexistant_author'])) {
            $options['create_nonexistant_author'] = false;
        }

        # SAVE POST
        $post = get_post($post_id);

        # GET DIR
        $dir_id = $post_id;
        if ($options['folder'] !== false) {
            $dir_id = $options['folder'];
        }
        if ($dir_id == 'slug') {
            $dir_id = $post->post_name;
        }
        $dir = $this->get_upload_dir($dir_id);

        # REMOVE UNUSED
        unset($post->guid);
        unset($post->to_ping);
        unset($post->pinged);
        unset($post->ID);
        unset($post->post_parent);
        if ($options['export_author_slug']) {
            $author = get_userdata($post->post_author);
            $post->post_author = $author->data->user_login;
        }
        file_put_contents($dir['full'] . 'post.json', json_encode($post));

        # SAVE METAS
        $post_metas = get_post_meta($post_id);
        if ($options['skip_empty_metas']) {
            $_post_metas = array();
            foreach ($post_metas as $key => $var) {
                if (empty($var)) {
                    continue;
                }
                if (is_array($var) && isset($var[0]) && empty($var[0])) {
                    continue;
                }
                $_post_metas[$key] = $var;
            }
            $post_metas = $_post_metas;
        }
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
                'post_title' => $attachment_raw->post_title,
                'post_content' => $attachment_raw->post_content,
                'post_excerpt' => $attachment_raw->post_excerpt,
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
            $this->print_message('# Invalid post');
            return false;
        }

        # Author
        if (!is_numeric($post->post_author)) {
            $author = get_user_by('slug', $post->post_author);
            if (is_object($author) && isset($author->data->ID)) {
                $post->post_author = $author->data->ID;
            } else if (is_string($post->post_author) && $options['create_nonexistant_author']) {
                $user_id = wp_create_user($post->post_author);
                if (is_numeric($user_id)) {
                    $post->post_author = $user_id;
                }
            }
        }

        # IMPORT OBJECT WITHOUT SOME LINES
        $new_post_id = wp_insert_post($post);

        if (!is_numeric($new_post_id)) {
            $this->print_message('# Post was not created');
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
                    $this->print_message('- File does not exists : skipping this.');
                    continue;
                }

                # LOAD FILE
                $file_up = $this->upload_file($filepath, $new_post_id);
                if (!is_numeric($file_up)) {
                    $this->print_message('- File could not be uploaded : skipping this.');
                    continue;
                }

                $update_args = array(
                    'ID' => $file_up
                );
                if (isset($att['post_title'])) {
                    $update_args['post_title'] = $att['post_title'];
                }
                if (isset($att['post_content'])) {
                    $update_args['post_content'] = $att['post_content'];
                }
                if (isset($att['post_excerpt'])) {
                    $update_args['post_excerpt'] = $att['post_excerpt'];
                }
                wp_update_post($update_args);

                $att['new_id'] = $file_up;
                $_attachments_table[] = $att;
                $_attachments_link[$att['ID']] = $file_up;
            }

        } else {
            $this->print_message('- Attachments are invalid or do not exists : skipping this.');
        }

        $find_attachments_metas = (is_array($options) && isset($options['find_attachments_metas']) && $options['find_attachments_metas']);

        # GET METAS
        $metas = $this->get_array_from_file($dir['full'] . 'metas.json');
        foreach ($metas as $key => $value) {
            if (is_array($value)) {
                $value = $value[0];
            }

            /* Quick check if value is serialized */
            if (substr($value, 0, 2) == 'a:') {
                $test_unserialize_value = unserialize($value);
                if (is_array($test_unserialize_value)) {
                    $value = $test_unserialize_value;
                }
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
