<?php

/**
 * Integration Plugin yetiforce and roundcube.
 *
 * @license MIT
 * @author  Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 */
class yetiforce extends rcube_plugin
{
	private $rc;
	private $autologin;
	private $currentUser;
	private $viewData = [];
	private $message;
	private $icsParts = [];

	public function init()
	{
		$this->rc = rcmail::get_instance();
		$this->add_hook('login_after', [$this, 'loginAfter']);
		$this->add_hook('startup', [$this, 'startup']);
		$this->add_hook('authenticate', [$this, 'authenticate']);
		if ($this->rc->task == 'mail') {
			$this->register_action('plugin.yetiforce.addFilesToMail', [$this, 'addFilesToMail']);
			$this->register_action('plugin.yetiforce.getEmailTemplates', [$this, 'getEmailTemplates']);
			$this->register_action('plugin.yetiforce.getConntentEmailTemplate', [$this, 'getConntentEmailTemplate']);
			$this->rc->output->set_env('site_URL', $this->rc->config->get('site_URL'));
			$this->include_stylesheet($this->rc->config->get('public_URL') . 'layouts/resources/icons/userIcons.css');

			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			$this->loadCurrentUser();

			if ($this->rc->action === 'compose') {
				$composeAddressModules = [];
				foreach (AppConfig::module('Mail', 'RC_COMPOSE_ADDRESS_MODULES') as $moduleName) {
					if (\App\Privilege::isPermitted($moduleName)) {
						$composeAddressModules[$moduleName] = \App\Language::translate($moduleName, $moduleName);
					}
				}
				$this->viewData['compose']['composeAddressModules'] = $composeAddressModules;
				$this->rc->output->set_env('isPermittedMailTemplates', \App\Privilege::isPermitted('EmailTemplates'));

				$this->add_texts('localization/', false);
				$this->include_script('compose.js');

				$this->add_hook('message_compose_body', [$this, 'messageComposeBody']);
				$this->add_hook('message_compose', [$this, 'messageComposeHead']);
				$this->add_hook('render_page', [$this, 'loadSignature']);
				$this->add_hook('template_object_yt_adress_button', [$this, 'ytAdressButton']);

				$id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
				if ($id && isset($_SESSION['compose_data_' . $id]['param']['crmmodule'])) {
					$this->rc->output->set_env('crmModule', $_SESSION['compose_data_' . $id]['param']['crmmodule']);
				}
				if ($id && isset($_SESSION['compose_data_' . $id]['param']['crmrecord'])) {
					$this->rc->output->set_env('crmRecord', $_SESSION['compose_data_' . $id]['param']['crmrecord']);
				}
				if ($id && isset($_SESSION['compose_data_' . $id]['param']['crmview'])) {
					$this->rc->output->set_env('crmView', $_SESSION['compose_data_' . $id]['param']['crmview']);
				}
			}
			if ($this->rc->action === 'preview' || $this->rc->action === 'show') {
				$this->include_script('preview.js');
				$this->include_stylesheet($this->rc->config->get('public_URL') . 'libraries/@fortawesome/fontawesome-free/css/all.css');
				$this->include_stylesheet('preview.css');
				$this->add_hook('message_load', [$this, 'messageLoad']);
				$this->add_hook('template_object_messageattachments', [$this, 'appendIcsPreview']);
			}
			if (empty($this->rc->action)) {
				//$this->add_hook('preferences_save', array($this, 'prefsSave'));

				$this->include_script('colResizable.js');
				$this->include_script('list.js');
			}
			chdir($currentPath);
		}
	}

	public function startup($args)
	{
		$row = $this->getAutoLogin();
		if (!$row || empty($_GET['_autologin'])) {
			return $args;
		}
		if (!empty($_SESSION['user_id']) && $_SESSION['user_id'] != $row['user_id']) {
			$this->rc->logout_actions();
			$this->rc->kill_session();
			$this->rc->plugins->exec_hook('logout_after', [
				'user' => $_SESSION['username'],
				'host' => $_SESSION['storage_host'],
				'lang' => $this->rc->user->language
			]);
		}
		if (empty($_SESSION['user_id']) && !empty($_GET['_autologin'])) {
			$args['action'] = 'login';
		}
		return $args;
	}

	public function authenticate($args)
	{
		if (empty($_GET['_autologin'])) {
			return $args;
		}
		if ($row = $this->getAutoLogin()) {
			$host = false;
			foreach ($this->rc->config->get('default_host') as $key => $value) {
				if (strpos($key, $row['mail_host']) !== false) {
					$host = $key;
				}
			}
			if ($host) {
				$currentPath = getcwd();
				chdir($this->rc->config->get('root_directory'));
				require_once 'include/main/WebUI.php';
				$args['user'] = $row['username'];
				$args['pass'] = \App\Encryption::getInstance()->decrypt($row['password']);
				$args['host'] = $host;
				$args['cookiecheck'] = false;
				$args['valid'] = true;
				chdir($currentPath);
			}
			$db = $this->rc->get_dbh();
			$db->query('DELETE FROM `u_yf_mail_autologin` WHERE `cuid` = ?;', $row['cuid']);
		}
		return $args;
	}

	public function loginAfter($args)
	{
		//	Password saving
		$this->rc = rcmail::get_instance();
		$pass = rcube_utils::get_input_value('_pass', rcube_utils::INPUT_POST);
		if (!empty($pass)) {
			$sql = 'UPDATE ' . $this->rc->db->table_name('users') . ' SET password = ? WHERE user_id = ?';
			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			require_once 'include/main/WebUI.php';
			$pass = \App\Encryption::getInstance()->encrypt($pass);
			chdir($currentPath);
			call_user_func_array([$this->rc->db, 'query'], array_merge([$sql], [$pass, $this->rc->get_user_id()]));
			$this->rc->db->affected_rows();
		}
		if ($_GET['_autologin'] && !empty($_REQUEST['_composeKey'])) {
			$args['_action'] = 'compose';
			$args['_task'] = 'mail';
			$args['_composeKey'] = rcube_utils::get_input_value('_composeKey', rcube_utils::INPUT_GET);
		}
		if ($row = $this->getAutoLogin()) {
			$_SESSION['crm']['id'] = $row['cuid'];
			if (isset($row['params']['language'])) {
				$languages = $this->rc->list_languages();
				$lang = explode('_', $row['params']['language']);
				$lang[1] = strtoupper($lang[1]);
				$lang = implode('_', $lang);
				if (!isset($languages[$lang])) {
					$lang = substr($lang, 0, 2);
				}
				if (isset($languages[$lang])) {
					$this->rc->config->set('language', $lang);
					$this->rc->load_language($lang);
					$this->rc->user->save_prefs(['language' => $lang]);
				}
			}
		}
		return $args;
	}

	public function messageLoad($args)
	{
		if (!isset($args['object'])) {
			return;
		}
		$this->rc->output->set_env('subject', $args['object']->headers->subject);
		$from = $args['object']->headers->from;
		$from = explode('<', rtrim($from, '>'), 2);
		$fromName = '';
		if (count($from) > 1) {
			$fromName = $from[0];
			$fromMail = $from[1];
		} else {
			$fromMail = $from[0];
		}
		$this->rc->output->set_env('fromName', $fromName);
		$this->rc->output->set_env('fromMail', $fromMail);
		$this->setIcsData($args);
	}

	public function messageComposeHead($args)
	{
		$this->rc = rcmail::get_instance();
		$db = $this->rc->get_dbh();
		global $COMPOSE_ID;

		$composeKey = rcube_utils::get_input_value('_composeKey', rcube_utils::INPUT_GET);
		$result = $db->query('SELECT * FROM `u_yf_mail_compose_data` WHERE `key` = ?', $composeKey);
		$params = $db->fetch_assoc($result);
		$db->query('DELETE FROM `u_yf_mail_compose_data` WHERE `key` = ?;', $composeKey);
		if (!empty($params)) {
			$params = json_decode($params['data'], true);

			foreach ($params as $key => &$value) {
				$args['param'][$key] = $value;
			}
			if ((isset($params['crmmodule']) && $params['crmmodule'] == 'Documents') || (isset($params['filePath']) && $params['filePath'])) {
				$userid = $this->rc->user->ID;
				list($usec, $sec) = explode(' ', microtime());
				$dId = preg_replace('/[^0-9]/', '', $userid . $sec . $usec);
				foreach (self::getAttachment($params['crmrecord'], $params['filePath']) as $index => $attachment) {
					$attachment['group'] = $COMPOSE_ID;
					$attachment['id'] = $dId . $index;
					$args['attachments'][$attachment['id']] = $attachment;
				}
			}
			if (!isset($params['mailId'])) {
				return $args;
			}
			$mailId = $params['mailId'];
			$result = $db->query('SELECT content,reply_to_email,date,from_email,to_email,cc_email,subject FROM vtiger_ossmailview WHERE ossmailviewid = ?;', $mailId);
			$row = $db->fetch_assoc($result);
			$args['param']['type'] = $params['type'];
			$args['param']['mailData'] = $row;
			switch ($params['type']) {
				case 'replyAll':
					$cc = $row['to_email'];
					$cc .= ',' . $row['cc_email'];
					$cc = str_replace($row['from_email'] . ',', '', $cc);
					$cc = trim($cc, ',');
				// no break
				case 'reply':
					$to = $row['reply_to_email'];
					if (preg_match('/^re:/i', $row['subject'])) {
						$subject = $row['subject'];
					} else {
						$subject = 'Re: ' . $row['subject'];
					}
					$subject = preg_replace('/\s*\([wW]as:[^\)]+\)\s*$/', '', $subject);
					break;
				case 'forward':
					if (preg_match('/^fwd:/i', $row['subject'])) {
						$subject = $row['subject'];
					} else {
						$subject = 'Fwd: ' . $row['subject'];
					}
					break;
			}
			if (!empty($params['recordNumber']) && !empty($params['crmmodule'])) {
				$currentPath = getcwd();
				chdir($this->rc->config->get('root_directory'));
				$this->loadCurrentUser();

				$subjectNumber = \App\Fields\Email::findRecordNumber($subject, $params['crmmodule']);
				$recordNumber = \App\Fields\Email::findRecordNumber("[{$params['recordNumber']}]", $params['crmmodule']);
				if ($subject === false || ($subject !== false && $subjectNumber !== $recordNumber)) {
					$subject = "[{$params['recordNumber']}] $subject";
				}
				chdir($currentPath);
			}
			$args['param']['to'] = $to;
			$args['param']['cc'] = $cc;
			$args['param']['subject'] = $subject;
		}
		return $args;
	}

	public function messageComposeBody($args)
	{
		$this->rc = rcmail::get_instance();

		$id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
		$row = $_SESSION['compose_data_' . $id]['param']['mailData'];
		$type = $_SESSION['compose_data_' . $id]['param']['type'];
		if (!$row) {
			return;
		}
		$bodyIsHtml = $args['html'];
		$date = $row['date'];
		$from = $row['from_email'];
		$to = $row['to_email'];
		$body = $row['content'];
		$subject = $row['subject'];
		$replyto = $row['reply_to_email'];

		$prefix = $suffix = '';
		if ($type === 'forward') {
			if (!$bodyIsHtml) {
				$prefix = "\n\n\n-------- " . $this->rc->gettext('originalmessage') . " --------\n";
				$prefix .= $this->rc->gettext('subject') . ': ' . $subject . "\n";
				$prefix .= $this->rc->gettext('date') . ': ' . $date . "\n";
				$prefix .= $this->rc->gettext('from') . ': ' . $from . "\n";
				$prefix .= $this->rc->gettext('to') . ': ' . $to . "\n";
				if ($cc = $row['cc_email']) {
					$prefix .= $this->rc->gettext('cc') . ': ' . $cc . "\n";
				}
				if ($replyto != $from) {
					$prefix .= $this->rc->gettext('replyto') . ': ' . $replyto . "\n";
				}
				$prefix .= "\n";
				global $LINE_LENGTH;
				$txt = new rcube_html2text($body, false, true, $LINE_LENGTH);
				$body = $txt->get_text();
				$body = preg_replace('/\r?\n/', "\n", $body);
				$body = trim($body, "\n");
			} else {
				$prefix = sprintf(
					'<p>-------- ' . $this->rc->gettext('originalmessage') . ' --------</p>' .
					'<table border="0" cellpadding="0" cellspacing="0"><tbody>' .
					'<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>' .
					'<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>' .
					'<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>' .
					'<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>', $this->rc->gettext('subject'), rcube::Q($subject), $this->rc->gettext('date'), rcube::Q($date), $this->rc->gettext('from'), rcube::Q($from, 'replace'), $this->rc->gettext('to'), rcube::Q($to, 'replace'));
				if ($cc = $row['cc_email']) {
					$prefix .= sprintf('<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>', $this->rc->gettext('cc'), rcube::Q($cc, 'replace'));
				}
				if ($replyto != $from) {
					$prefix .= sprintf('<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>', $this->rc->gettext('replyto'), rcube::Q($replyto, 'replace'));
				}
				$prefix .= '</tbody></table><br>';
			}
			$body = $prefix . $body;
		} else {
			$prefix = $this->rc->gettext([
				'name' => 'mailreplyintro',
				'vars' => [
					'date' => $this->rc->format_date($date, $this->rc->config->get('date_long')),
					'sender' => $from,
				]
			]);
			if (!$bodyIsHtml) {
				global $LINE_LENGTH;
				$txt = new rcube_html2text($body, false, true, $LINE_LENGTH);
				$body = $txt->get_text();
				$body = preg_replace('/\r?\n/', "\n", $body);
				$body = trim($body, "\n");
				$body = rcmailWrapAndQuote($body, $LINE_LENGTH);
				$prefix .= "\n";
				$body = $prefix . $body . $suffix;
			} else {
				$prefix = '<p>' . rcube::Q($prefix) . "</p>\n";
				$body = $prefix . '<blockquote>' . $body . '</blockquote>' . $suffix;
			}
		}
		$this->rc->output->set_env('compose_mode', $type);
		$args['body'] = $body;
		return $args;
	}

	//	Loading signature
	public function loadSignature($response)
	{
		global $OUTPUT, $MESSAGE;
		if ($this->rc->config->get('enable_variables_in_signature') && !empty($OUTPUT->get_env('signatures'))) {
			$signatures = [];
			foreach ($OUTPUT->get_env('signatures') as $identityId => $signature) {
				$signatures[$identityId]['text'] = $this->parseVariables($signature['text']);
				$signatures[$identityId]['html'] = $this->parseVariables($signature['html']);
			}
			$OUTPUT->set_env('signatures', $signatures);
		}
		if ($this->checkAddSignature()) {
			return;
		}
		$gS = $this->getGlobalSignature();
		if (empty($gS['html'])) {
			return;
		}
		$signatures = [];
		foreach ($OUTPUT->get_env('signatures') as $identityId => $signature) {
			$signatures[$identityId]['text'] = $signature['text'] . PHP_EOL . $gS['text'];
			$signatures[$identityId]['html'] = $signature['html'] . '<div class="pre global">' . $gS['html'] . '</div>';
		}
		if (count($MESSAGE->identities)) {
			foreach ($MESSAGE->identities as &$identity) {
				$identityId = $identity['identity_id'];
				if (!isset($signatures[$identityId])) {
					$signatures[$identityId]['text'] = "--\n" . $gS['text'];
					$signatures[$identityId]['html'] = '--<br><div class="pre global">' . $gS['html'] . '</div>';
				}
			}
		}
		$OUTPUT->set_env('signatures', $signatures);
	}

	public function getGlobalSignature()
	{
		global $RCMAIL;
		$db = $RCMAIL->get_dbh();
		$result = [];
		$sqlResult = $db->query("SELECT `value` FROM yetiforce_mail_config WHERE `type` = 'signature' AND `name` = 'signature';");
		while ($row = $db->fetch_assoc($sqlResult)) {
			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			$parser = App\TextParser::getInstanceById($this->currentUser->getId(), 'Users');
			$value = $parser->setContent($row['value'])->parse()->getContent();
			chdir($currentPath);
			$result['html'] = $value;
			$result['text'] = $value;
		}
		return $result;
	}

	public function checkAddSignature()
	{
		global $RCMAIL;
		$db = $RCMAIL->get_dbh();
		$sqlResult = $db->query("SELECT * FROM yetiforce_mail_config WHERE `type` = 'signature' AND `name` = 'addSignature';");
		while ($sql_arr = $db->fetch_assoc($sqlResult)) {
			return $sql_arr['value'] == 'false' ? true : false;
		}
		return true;
	}

	//	Adding attachments
	public function addFilesToMail()
	{
		$COMPOSE_ID = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC);
		$uploadid = rcube_utils::get_input_value('_uploadid', rcube_utils::INPUT_GPC);
		$ids = rcube_utils::get_input_value('ids', rcube_utils::INPUT_GPC);
		$COMPOSE = null;

		if ($COMPOSE_ID && $_SESSION['compose_data_' . $COMPOSE_ID]) {
			$SESSION_KEY = 'compose_data_' . $COMPOSE_ID;
			$COMPOSE = &$_SESSION[$SESSION_KEY];
		}
		if (!$COMPOSE) {
			die('Invalid session var!');
		}
		$this->rc = rcmail::get_instance();
		$index = 0;

		$attachments = $this->getAttachment($ids, false);
		foreach ($attachments as $attachment) {
			$index++;
			$attachment['group'] = $COMPOSE_ID;
			$userid = rcmail::get_instance()->user->ID;
			list($usec, $sec) = explode(' ', microtime());
			$id = preg_replace('/[^0-9]/', '', $userid . $sec . $usec) . $index;
			$attachment['id'] = $id;
			$_SESSION['plugins']['filesystem_attachments'][$COMPOSE_ID][$id] = realpath($attachment['path']);
			$this->rc->session->append($SESSION_KEY . '.attachments', $id, $attachment);

			if (($icon = $COMPOSE['deleteicon']) && is_file($icon)) {
				$button = html::img([
					'src' => $icon,
					'alt' => $this->rc->gettext('delete')
				]);
			} elseif ($COMPOSE['textbuttons']) {
				$button = rcube::Q($this->rc->gettext('delete'));
			} else {
				$button = '';
			}
			$link_content = sprintf('%s <span class="attachment-size"> (%s)</span>',
				rcube::Q($attachment['name']), $this->rc->show_bytes($attachment['size']));
			$content_link = html::a([
				'href' => '#load',
				'class' => 'filename',
				'onclick' => sprintf("return %s.command('load-attachment','rcmfile%s', this, event)", rcmail_output::JS_OBJECT_NAME, $id),
			], $link_content);
			$delete_link = html::a([
				'href' => '#delete',
				'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this, event)", rcmail_output::JS_OBJECT_NAME, $id),
				'title' => $this->rc->gettext('delete'),
				'class' => 'delete',
				'aria-label' => $this->rc->gettext('delete') . ' ' . $attachment['name'],
			], $button);
			$content = $COMPOSE['icon_pos'] == 'left' ? $delete_link . $content_link : $content_link . $delete_link;

			$htmlAttachments = 'window.rcmail.add2attachment_list("rcmfile' . $id . '",{html:"' . rcube::JQ($content) . '",name:"' . $attachment['name'] . '",mimetype:"' . $attachment['mimetype'] . '",classname:"' . rcube_utils::file2class($attachment['mimetype'], $attachment['name']) . '",complete:true},"' . $uploadid . '");' . PHP_EOL;
		}
		echo '<!DOCTYPE html>
<html lang="en">
<head><title></title><meta http-equiv="content-type" content="text/html; charset=UTF-8" />
<script type="text/javascript">
if (window && window.rcmail) {
	window.rcmail.iframe_loaded("");
	' . $htmlAttachments . '
	window.rcmail.auto_save_start(false);
}
</script>
</head>
<body>
</body>
</html>';
		exit;
	}

	public function getAttachment($ids, $files)
	{
		$attachments = [];
		if (empty($ids) && empty($files)) {
			return $attachments;
		}
		if (is_array($ids)) {
			$ids = implode(',', $ids);
		}
		$this->rc = rcmail::get_instance();
		$db = $this->rc->get_dbh();
		$userid = $this->rc->user->ID;
		$index = 0;
		if ($ids) {
			$sql_result = $db->query("SELECT vtiger_attachments.* FROM vtiger_attachments INNER JOIN vtiger_seattachmentsrel ON vtiger_seattachmentsrel.attachmentsid=vtiger_attachments.attachmentsid WHERE vtiger_seattachmentsrel.crmid IN ($ids);");
			while ($row = $db->fetch_assoc($sql_result)) {
				$orgFile = $this->rc->config->get('root_directory') . $row['path'] . $row['attachmentsid'];
				list($usec, $sec) = explode(' ', microtime());
				$filepath = $this->rc->config->get('temp_dir') . DIRECTORY_SEPARATOR . "{$sec}_{$userid}_{$row['attachmentsid']}_$index.tmp";
				if (file_exists($orgFile)) {
					copy($orgFile, $filepath);
					$attachment = [
						'path' => $filepath,
						'name' => $row['name'],
						'size' => filesize($filepath),
						'mimetype' => rcube_mime::file_content_type($filepath, $row['name'], $row['type']),
					];
					$attachments[] = $attachment;
				}
				$index++;
			}
		}
		if ($files) {
			$orgFile = $this->rc->config->get('root_directory') . $files;
			list($usec, $sec) = explode(' ', microtime());
			$filepath = $this->rc->config->get('root_directory') . "cache/mail/{$sec}_{$userid}_{$index}.tmp";
			if (file_exists($orgFile)) {
				copy($orgFile, $filepath);
				$attachment = [
					'path' => $filepath,
					'size' => filesize($filepath),
					'name' => basename($orgFile),
					'mimetype' => rcube_mime::file_content_type($filepath, basename($orgFile)),
				];
				$attachments[] = $attachment;
			}
			$index++;
		}
		return $attachments;
	}

	public function rcmailWrapAndQuote($text, $length = 72)
	{
		// Rebuild the message body with a maximum of $max chars, while keeping quoted message.
		$max = max(75, $length + 8);
		$lines = preg_split('/\r?\n/', trim($text));
		$out = '';
		foreach ($lines as $line) {
			// don't wrap already quoted lines
			if ($line[0] == '>') {
				$line = '>' . rtrim($line);
			} elseif (mb_strlen($line) > $max) {
				$newline = '';

				foreach (explode("\n", rcube_mime::wordwrap($line, $length - 2)) as $l) {
					if (strlen($l)) {
						$newline .= '> ' . $l . "\n";
					} else {
						$newline .= ">\n";
					}
				}

				$line = rtrim($newline);
			} else {
				$line = '> ' . $line;
			}
			// Append the line
			$out .= $line . "\n";
		}
		return rtrim($out, "\n");
	}

	protected function getAutoLogin()
	{
		if (empty($_GET['_autologinKey'])) {
			return false;
		}
		if (isset($this->autologin)) {
			return $this->autologin;
		}
		$key = rcube_utils::get_input_value('_autologinKey', rcube_utils::INPUT_GPC);
		$db = $this->rc->get_dbh();
		$sqlResult = $db->query('SELECT * FROM u_yf_mail_autologin INNER JOIN roundcube_users ON roundcube_users.user_id = u_yf_mail_autologin.ruid WHERE roundcube_users.password <> \'\' AND u_yf_mail_autologin.`key` = ?;', $key);
		$autologin = false;
		if ($row = $db->fetch_assoc($sqlResult)) {
			$autologin = $row;
			$autologin['params'] = json_decode($autologin['params'], true);
		}
		$this->autologin = $autologin;
		return $autologin;
	}

	/**
	 * Parse variables.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	protected function parseVariables($text)
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$this->loadCurrentUser();
		$text = \App\TextParser::getInstance()->setContent($text)->parse()->getContent();
		chdir($currentPath);
		return $text;
	}

	protected function loadCurrentUser()
	{
		if (isset($this->currentUser)) {
			return true;
		}
		require 'include/main/WebUI.php';
		$this->currentUser = \App\User::getUserModel($_SESSION['crm']['id']);
		App\User::setCurrentUserId($_SESSION['crm']['id']);
		return true;
	}

	public function ytAdressButton($p)
	{
		if (empty($this->viewData['compose']['composeAddressModules'])) {
			return $p;
		}
		$content = '';
		foreach ($this->viewData['compose']['composeAddressModules'] as $moduleName => $value) {
			$text = html::span(['class' => "userIcon-$moduleName"], '') . ' ' . $value;
			$content .= html::a(['class' => 'button', 'data-input' => $p['part'], 'data-module' => $moduleName], $text);
		}
		$p['content'] = $content;
		return $p;
	}

	/**
	 * Function to get templates.
	 */
	public function getEmailTemplates()
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$this->loadCurrentUser();
		$emailTemplates = App\Mail::getTempleteList(false, 'PLL_MAIL');
		echo App\Json::encode($emailTemplates);
		chdir($currentPath);
		exit;
	}

	/**
	 * Function to get info about email template.
	 */
	public function getConntentEmailTemplate()
	{
		$templeteId = rcube_utils::get_input_value('id', rcube_utils::INPUT_GPC);
		$recordId = rcube_utils::get_input_value('record_id', rcube_utils::INPUT_GPC);
		$moduleName = rcube_utils::get_input_value('select_module', rcube_utils::INPUT_GPC);
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$this->loadCurrentUser();
		$mail = App\Mail::getTemplete($templeteId);
		if ($recordId) {
			$textParser = \App\TextParser::getInstanceById($recordId, $moduleName);
			$mail['subject'] = $textParser->setContent($mail['subject'])->parse()->getContent();
			$mail['content'] = $textParser->setContent($mail['content'])->parse()->getContent();
		}
		echo App\Json::encode([
			'subject' => $mail['subject'],
			'content' => $mail['content'],
			'attachments' => $mail['attachments'],
		]);
		chdir($currentPath);
		exit;
	}

	public function setIcsData($args)
	{
		$this->message = $args['object'];
		foreach ((array)$this->message->attachments as $attachment) {
			if ($this->isIcs($attachment)) {
				$this->icsParts[] = ['part' => $attachment->mime_id, 'uid' => $this->message->uid];
			}
		}
		foreach ((array)$this->message->parts as $part) {
			if ($this->isIcs($part)) {
				$this->icsParts[] = ['part' => $attachment->mime_id, 'uid' => $this->message->uid];
			}
		}
		if ($this->icsParts) {
			$this->add_texts('localization');
		}
	}

	/**
	 * Append ical preview in attachments' area.
	 *
	 * @param $args
	 *
	 * @return mixed
	 */
	public function appendIcsPreview($args)
	{
		$icsPartsIds = [];
		foreach ($this->icsParts as $icsPart) {
			$icsContent = $this->message->get_part_content($icsPart['part'], null, true);
			$file_name = $icsPart['uid'];
			if (in_array($file_name, $icsPartsIds)) {
				continue;
			} else {
				array_push($icsPartsIds, $file_name);
			}
			$filePath = $this->rc->config->get('root_directory') . 'cache/import/' . $file_name . '.ics';
			file_put_contents($filePath, $icsContent);
			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			$this->loadCurrentUser();
			$icsRecords = \App\Utils\iCalendar::import($filePath);
			chdir($currentPath);
			$eventTable = '<div class="c-ical">';
			foreach ($icsRecords as $record) {
				if ($record->getValueByField('time_start') || $record->getValueByField('time_end')) {
					$dateStart = strtotime($record->getDisplayValue('date_start'));
					$monthName = date('M', $dateStart);
					$dayName = date('w', $dateStart);
					if (date('Y-m-d', $dateStart) !== ($dateEnd = date('Y-m-d', strtotime($record->getDisplayValue('due_date'))))) {
						if ($monthName !== date('M', $dateEnd)) {
							$monthName .= ' - ' . date('M', $dateEnd);
						}
						$dayName .= ' - ' . date('w', $dateEnd);
					}
					$eventTable .= "<div class='w-100 flex-wrap'>
										<div class='c-ical__card'>
											$monthName
											<div class='c-ical__card__nb'>$dayName</div>
										</div>
										{$record->getDisplayValue('subject')}
									</div>";
				}
			}
			$eventTable .= '</div>';
			$args['content'] .=
				html::p(['class' => ''],
					html::a([
						'href' => 'javascript:void',
						'onclick' => "return rcmail.command('yetiforce.importICS',$file_name,this,event)",
						'title' => $this->gettext('addicalinvitemsg'),
					],
						html::span(null, rcube::Q($this->gettext('addicalinvitemsg'))
						)
					)
				);
			$args['content'] .= html::div(null, $eventTable);
		}
		return $args;
	}

	/**
	 * Check if $part is ics.
	 *
	 * @param $part
	 *
	 * @return bool
	 */
	public function isIcs($part)
	{
		return $part->mimetype == 'application/ics' || $part->mimetype == 'text/calendar';
	}
}
