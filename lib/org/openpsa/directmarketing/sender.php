<?php
/**
 * @package org.openpsa.directmarketing
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

/**
 * Campaign message sender
 *
 * @package org.openpsa.directmarketing
 */
class org_openpsa_directmarketing_sender
{
    use midcom_baseclasses_components_base;

    /**
     * @var boolean
     */
    private $test_mode = false;

    /**
     * How many messages to send in one go
     *
     * @var integer
     */
    public $chunk_size = 50;

    /**
     * Length of the message token
     *
     * @var integer
     */
    public $token_size = 15;

    /**
     * @var org_openpsa_directmarketing_campaign_message_dba
     */
    private $_message;

    /**
     * @var org_openpsa_directmarketing_sender_backend
     */
    private $_backend;

    /**
     * @var integer
     */
    private static $_messages_sent = 0;

    private $_chunk_num = 0;

    /**
     * How many times to recurse if all results are filtered (speed vs memory [and risk on crashing], higher is faster)
     *
     * @var integer
     */
    private $_chunk_max_recurse = 15;

    private $from;

    private $subject;

    public function __construct(org_openpsa_directmarketing_campaign_message_dba $message, array $config = [], $from = '', string $subject = '')
    {
        $this->_component = 'org.openpsa.directmarketing';
        $this->_message = $message;
        $this->from = $from;
        $this->subject = $subject;

        if (   $message->orgOpenpsaObtype != org_openpsa_directmarketing_campaign_message_dba::EMAIL_TEXT
            && $message->orgOpenpsaObtype != org_openpsa_directmarketing_campaign_message_dba::EMAIL_HTML) {
            throw new midcom_error('unsupported message type');
        }
        $this->_backend = new org_openpsa_directmarketing_sender_backend_email($config, $message);
        $this->chunk_size = $this->_config->get('chunk_size');
    }

    /**
     * Sends a message to all testers
     */
    public function send_test(string $content) : bool
    {
        $this->test_mode = true;
        midcom::get()->disable_limits();

        while ($results = $this->_qb_send_loop()) {
            if (!$this->process_results($results, $content)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sends $content to all members of the campaign
     */
    public function send_bg(string $url_base, int $batch, string $content) : bool
    {
        //TODO: Figure out how to recognize errors and pass the info on

        $this->_chunk_num = $batch - 1;

        midcom::get()->disable_limits();
        //For first batch (they start from 1 instead of 0) make sure we have smart campaign members up to date
        if ($batch == 1) {
            $this->_check_campaign_up_to_date();
        }
        // Register sendStarted if not already set
        if (!$this->_message->sendStarted) {
            $this->_message->sendStarted = time();
            $this->_message->update();
        }
        $results = $this->_qb_single_chunk();
        //The method above might have incremented the counter for internal reasons
        $batch = $this->_chunk_num + 1;
        if ($results === null) {
            $status = true; //All should be ok
        } elseif ($status = $this->process_results($results, $content)) {
            //register next batch
            return $this->register_send_job($batch + 1, $url_base);
        }

        // Last batch done, register sendCompleted if we're not in test mode
        $this->_message->sendCompleted = time();
        $this->_message->update();

        return $status;
    }

    private function process_results(array $results, string $content) : bool
    {
        if (!$this->_backend->check_results($results)) {
            return false; //Backend refuses delivery
        }
        foreach ($results as $member) {
            $this->_send_member($member, $content);
        }
        return true;
    }

    public function register_send_job(int $batch, string $url_base, int $time = null) : bool
    {
        $time = $time ?: time() + 30;
        $args = [
            'batch' => $batch,
            'url_base' => $url_base,
        ];
        debug_add("Registering batch #{$args['batch']} for {$args['url_base']} to start on: " . date('Y-m-d H:i:s', $time));
        midcom::get()->auth->request_sudo('org.openpsa.directmarketing');
        $atstat = midcom_services_at_interface::register($time, 'org.openpsa.directmarketing', 'background_send_message', $args);
        midcom::get()->auth->drop_sudo();
        if (!$atstat) {
            debug_add("FAILED to register batch #{$args['batch']} for {$args['url_base']}, errstr: " . midcom_connection::get_error_string(), MIDCOM_LOG_ERROR);
        }
        return $atstat;
    }

    private function _send_member(org_openpsa_directmarketing_campaign_member_dba $member, string $content)
    {
        if (!$person = $this->_get_person($member)) {
            return;
        }

        $from = $this->from ?: 'noreplyaddress@openpsa2.org';
        $subject = $this->subject ?: '[no subject]';
        if ($this->test_mode) {
            $subject = "[TEST] {$subject}";
        }
        $content = $member->personalize_message($content, $this->_message->orgOpenpsaObtype, $person);
        $token = $this->_create_token();
        $subject = $member->personalize_message($subject, org_openpsa_directmarketing_campaign_message_dba::EMAIL_TEXT, $person);
        $params = [];

        try {
            $this->_backend->send($person, $member, $token, $subject, $content, $from);
            self::$_messages_sent++;
            $status = org_openpsa_directmarketing_campaign_messagereceipt_dba::SENT;
        } catch (midcom_error $e) {
            $status = org_openpsa_directmarketing_campaign_messagereceipt_dba::FAILURE;
            if (!$this->test_mode) {
                $params[] = [
                    'domain' => 'org.openpsa.directmarketing',
                    'name' => 'send_error_message',
                    'value' => $e->getMessage(),
                ];
            } else {
                midcom::get()->uimessages->add($this->_l10n->get($this->_component), $e->getMessage(), 'error');
            }
        }
        if (!$this->test_mode) {
            $member->create_receipt($this->_message->id, $status, $token, $params);
        }
    }

    /**
     * Creates a random token string that can be used to track a single
     * delivery. The returned token string will only contain
     * lowercase alphanumeric characters and will start with a lowercase
     * letter to avoid problems with special processing being triggered
     * by special characters in the token string.
     */
    private function _create_token() : string
    {
        //Testers need dummy token
        if ($this->test_mode) {
            return 'dummy';
        }

        $token = midcom_helper_misc::random_string(1, 'abcdefghijklmnopqrstuvwxyz');
        $token .= midcom_helper_misc::random_string($this->token_size - 1, 'abcdefghijklmnopqrstuvwxyz0123456789');

        //If token is not free or (very, very unlikely) matches our dummy token, recurse.
        if (   $token === 'dummy'
            || !org_openpsa_directmarketing_campaign_messagereceipt_dba::token_is_free($token)) {
            return $this->_create_token();
        }
        return $token;
    }

    /**
     * Check is given member has denied contacts of given type
     */
    private function _get_person(org_openpsa_directmarketing_campaign_member_dba $member) : ?org_openpsa_contacts_person_dba
    {
        try {
            $person = org_openpsa_contacts_person_dba::get_cached($member->person);
        } catch (midcom_error $e) {
            debug_add("Person #{$member->person} deleted or missing, removing member (member #{$member->id})");
            $member->orgOpenpsaObtype = org_openpsa_directmarketing_campaign_member_dba::UNSUBSCRIBED;
            $member->delete();
            return null;
        }
        $type = $this->_backend->get_type();
        if (   $person->get_parameter('org.openpsa.directmarketing', "send_all_denied")
            || $person->get_parameter('org.openpsa.directmarketing', "send_{$type}_denied")) {
            debug_add("Sending {$type} messages to person {$person->rname} is denied, unsubscribing member (member #{$member->id})");
            $member->orgOpenpsaObtype = org_openpsa_directmarketing_campaign_member_dba::UNSUBSCRIBED;
            $member->update();
            return null;
        }
        return $person;
    }

    /**
     * Loops trough send filter in chunks, adds some common constraints and checks for send-receipts.
     */
    private function _qb_send_loop() : ?array
    {
        $ret = $this->_qb_single_chunk();
        $this->_chunk_num++;
        //Trivial rate limiting
        sleep(1);
        return $ret;
    }

    private function _qb_single_chunk(int $level = 0) : ?array
    {
        $qb = org_openpsa_directmarketing_campaign_member_dba::new_query_builder();
        $this->_backend->add_member_constraints($qb);
        $this->_qb_common_constraints($qb);
        $this->_qb_chunk_limits($qb);

        $results = $qb->execute_unchecked();
        if (empty($results)) {
            debug_add('Got failure or empty resultset, aborting');
            return null;
        }

        if ($this->test_mode) {
            debug_add('TEST mode, no receipt filtering will be done');
            return $results;
        }
        debug_add('Got ' . count($results) . ' initial results');

        $results = $this->_qb_filter_results($results);

        debug_add('Have ' . count($results) . ' results left after filtering');
        debug_add("Recursion level is {$level}, limit is {$this->_chunk_max_recurse}");
        /* Make sure we still have results left, if not just recurse...
         (basically this is to avoid returning an empty array when everything is otherwise ok) */
        if (empty($results) && ($level < $this->_chunk_max_recurse)) {
            debug_add('All our results got filtered, recursing for another round');
            $this->_chunk_num++;
            return $this->_qb_single_chunk($level + 1);
        }

        reset($results);
        return $results;
    }

    private function _qb_chunk_limits(midcom_core_querybuilder $qb)
    {
        debug_add("Processing chunk {$this->_chunk_num}");
        $offset = $this->_chunk_num * $this->chunk_size;
        if ($offset > 0) {
            debug_add("Setting offset to {$offset}");
            $qb->set_offset($offset);
        }
        debug_add("Setting limit to {$this->chunk_size}");
        $qb->set_limit($this->chunk_size);
    }

    private function _qb_filter_results(array $results)
    {
        if (empty($results)) {
            return $results;
        }
        //Make a map for receipt filtering
        $results_person_map = [];
        foreach ($results as $k => $member) {
            $results_person_map[$member->person] = $k;
        }
        $mc = org_openpsa_directmarketing_campaign_messagereceipt_dba::new_collector('message', $this->_message->id);
        $mc->add_constraint('message', '=', $this->_message->id);
        $mc->add_constraint('orgOpenpsaObtype', '=', org_openpsa_directmarketing_campaign_messagereceipt_dba::SENT);
        $mc->add_constraint('person', 'IN', array_keys($results_person_map));

        $receipts = $mc->get_values('person');

        if (empty($receipts)) {
            return $results;
        }
        debug_add('Found ' . count($receipts) . ' send receipts for this chunk');

        //Filter results array by receipt
        $receipts = array_flip($receipts);
        $persons_to_remove = array_intersect_key($results_person_map, $receipts);
        return array_diff_key($results, array_flip($persons_to_remove));
    }

    /**
     * Get send status
     *
     * @return array Number of valid members at index 0 and number of send receipts at 1
     */
    public function get_status() : array
    {
        $qb_mem = org_openpsa_directmarketing_campaign_member_dba::new_query_builder();
        $this->_backend->add_member_constraints($qb_mem);

        $this->_qb_common_constraints($qb_mem);
        $valid_members = $qb_mem->count_unchecked();

        $qb_receipts = org_openpsa_directmarketing_campaign_messagereceipt_dba::new_query_builder();
        $qb_receipts->add_constraint('message', '=', $this->_message->id);
        $qb_receipts->add_constraint('orgOpenpsaObtype', '=', org_openpsa_directmarketing_campaign_messagereceipt_dba::SENT);
        $send_receipts = $qb_receipts->count_unchecked();

        return [$valid_members, $send_receipts];
    }

    /**
     * Check if this message is attached to a smart campaign, if so update the campaign members
     */
    private function _check_campaign_up_to_date()
    {
        midcom::get()->auth->request_sudo('org.openpsa.directmarketing');
        $campaign = new org_openpsa_directmarketing_campaign_dba($this->_message->campaign);
        midcom::get()->auth->drop_sudo();
        if ($campaign->orgOpenpsaObtype == org_openpsa_directmarketing_campaign_dba::TYPE_SMART) {
            $campaign->update_smart_campaign_members();
        }
    }

    /**
     * Sets the common constrains for campaign members queries
     */
    protected function _qb_common_constraints($qb)
    {
        debug_add("Setting constraint campaign = {$this->_message->campaign}");
        $qb->add_constraint('campaign', '=', $this->_message->campaign);
        $qb->add_constraint('suspended', '<', time());
        if ($this->test_mode) {
            debug_add('TEST mode, adding constraints');
            $qb->add_constraint('orgOpenpsaObtype', '=', org_openpsa_directmarketing_campaign_member_dba::TESTER);
        } else {
            debug_add('REAL mode, adding constraints');
            //Fail safe way, exclude those we know we do not want, in case some wanted members have incorrect type...
            $qb->add_constraint('orgOpenpsaObtype', '<>', org_openpsa_directmarketing_campaign_member_dba::TESTER);
            $qb->add_constraint('orgOpenpsaObtype', '<>', org_openpsa_directmarketing_campaign_member_dba::UNSUBSCRIBED);
            $qb->add_constraint('orgOpenpsaObtype', '<>', org_openpsa_directmarketing_campaign_member_dba::BOUNCED);
        }
        $qb->add_order('person.lastname', 'ASC');
        $qb->add_order('person.firstname', 'ASC');
        $qb->add_order('person.id', 'ASC');
    }
}
