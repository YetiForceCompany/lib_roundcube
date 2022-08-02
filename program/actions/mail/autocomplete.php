<?php

// {[The file is published on the basis of YetiForce Public License that can be found in the following directory: licenses/License.html]}
// <--------   YetiForce Sp. z o.o.   -------->

class rcmail_action_mail_autocomplete extends rcmail_action
{
	protected static $mode = self::MODE_AJAX;

	/**
	 * Request handler.
	 *
	 * @param array $args Arguments from the previous step(s)
	 */
	public function run($args = [])
	{
		$rcmail = rcmail::get_instance();
		$search = rcube_utils::get_input_string('_search', rcube_utils::INPUT_GPC, true);
		$reqid = rcube_utils::get_input_string('_reqid', rcube_utils::INPUT_GPC);
		$contacts = [];

		if (\strlen($search)) {
			$contacts = [];
			$crmUserId = false;
			if (isset($_SESSION['crm']['id'])) {
				$crmUserId = $_SESSION['crm']['id'];
			} elseif ($rcmail->user->data['crm_user_id']) {
				$crmUserId = $rcmail->user->data['crm_user_id'];
			}
			if ($crmUserId) {
				$addressBookFile = $rcmail->config->get('root_directory') . 'cache/addressBook/mails_' . $crmUserId . '.php';
				if (is_file($addressBookFile)) {
					include $addressBookFile;
					$contacts = preg_grep("/{$search}/i", $bookMails);
				}
			}
			$contacts = array_values($contacts);
		}

		$rcmail->output->command('ksearch_query_results', $contacts, $search, $reqid);
		$rcmail->output->send();
	}
}
// <--------   YetiForce Sp. z o.o.   -------->
