<?php
/**
 * @package org.openpsa.directmarketing
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use midcom\datamanager\datamanager;

/**
 * @package org.openpsa.directmarketing
 */
class org_openpsa_directmarketing_handler_message_send extends midcom_baseclasses_components_handler
{
    use org_openpsa_directmarketing_handler;

    private datamanager $_datamanager;

    private $batch_url_base_full;

    private $batch_number;

    private $send_start;

    private function load_datamanager(org_openpsa_directmarketing_campaign_message_dba $message)
    {
        $this->_datamanager = datamanager::from_schemadb($this->_config->get('schemadb_message'))
            ->set_storage($message);
    }

    public function _handler_send_bg(string $guid, int $batch_number, string $job, array &$data)
    {
        midcom::get()->auth->request_sudo($this->_component);

        //Load message
        $data['message'] = new org_openpsa_directmarketing_campaign_message_dba($guid);
        // TODO: Check that campaign is in this topic

        $this->load_datamanager($data['message']);

        $this->batch_number = $batch_number;
        midcom_services_at_entry_dba::get_cached($job);

        midcom::get()->skip_page_style = true;
        midcom::get()->auth->drop_sudo();
    }

    public function _show_send_bg(string $handler_id, array &$data)
    {
        midcom::get()->auth->request_sudo($this->_component);
        $sender = $this->_get_sender($data);
        $composed = $this->compose($data);
        debug_add('Forcing content type: text/plain');
        midcom::get()->header('Content-Type: text/plain');
        $bgstat = $sender->send_bg($this->batch_url_base_full, $this->batch_number, $composed);
        if (!$bgstat) {
            echo "ERROR\n";
        } else {
            echo "Batch #{$this->batch_number} DONE\n";
        }
        midcom::get()->auth->drop_sudo();
    }

    private function _get_sender(array $data) : org_openpsa_directmarketing_sender
    {
        $message_array = $this->_datamanager->get_content_raw();
        $message_array['dm_storage'] = $this->_datamanager->get_storage();
        if (!array_key_exists('content', $message_array)) {
            throw new midcom_error('"content" not defined in schema');
        }

        $settings = [
            'mail_send_backend' => 'mail_send_backend',
            'bouncer_address' => 'bounce_detector_address',
            'linkdetector_address' => 'link_detector_address',
        ];

        foreach ($settings as $config_name => $target_name) {
            if ($value = $this->_config->get($config_name)) {
                $message_array[$target_name] = $value;
            }
        }

        //PONDER: Should we leave these entirely for the methods to parse from the array ?
        $subject = $message_array['subject'] ?? '';
        $from = $message_array['from'] ?? '';

        $sender = new org_openpsa_directmarketing_sender($data['message'], $message_array, $from, $subject);
        if ($token_size = $this->_config->get('token_size')) {
            $sender->token_size = $token_size;
        }
        return $sender;
    }

    private function compose(array $data) : string
    {
        $nap = new midcom_helper_nav();
        $node = $nap->get_node($nap->get_current_node());
        $compose_url = $node[MIDCOM_NAV_RELATIVEURL] . 'message/compose/' . $data['message']->guid .'/';
        $this->batch_url_base_full = $node[MIDCOM_NAV_RELATIVEURL] . 'message/send_bg/' . $data['message']->guid . '/';
        debug_add("compose_url: {$compose_url}");
        debug_add("batch_url base: {$this->batch_url_base_full}");
        $le_backup = ini_set('log_errors', true);
        $de_backup = ini_set('display_errors', false);
        ob_start();
        midcom::get()->dynamic_load($compose_url);
        $composed = ob_get_clean();
        ini_set('display_errors', $de_backup);
        ini_set('log_errors', $le_backup);

        return $composed;
    }

    public function _handler_send(string $guid, array &$data)
    {
        midcom::get()->auth->require_valid_user();
        //Load message
        $data['message'] = new org_openpsa_directmarketing_campaign_message_dba($guid);
        $this->load_campaign($data['message']->campaign);

        $this->add_breadcrumb($this->router->generate('message_view', ['guid' => $guid]), $data['message']->title);
        $this->add_breadcrumb("", $this->_l10n->get('send'));

        $this->load_datamanager($data['message']);

        $this->send_start = time();
    }

    public function _show_send(string $handler_id, array &$data)
    {
        $sender = $this->_get_sender($data);
        $composed = $this->compose($data);
        //We force the content-type since the compositor might have set it to something else in compositor for preview purposes
        debug_add('Forcing content type: text/html');
        midcom::get()->header('Content-Type: text/html');

        if ($handler_id == 'test_send_message') {
            // on-line send
            if ($sender->send_test($composed)) {
                midcom_show_style('send-finish');
            }
        } else {
            // Schedule background send
            if (!$sender->register_send_job(1, $this->batch_url_base_full, $this->send_start)) {
                throw new midcom_error("Job registration failed: " . midcom_connection::get_error_string());
            }
            midcom_show_style('send-status');
        }
    }
}
