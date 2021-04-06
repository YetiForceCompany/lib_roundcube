<?php

/**
 * Integration Plugin yetiforce and roundcube.
 *
 * @license MIT
 * @author  Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 * @author  Radosław Skrzypczak <r.skrzypczak@yetiforce.com>
 */
class yetiforce extends rcube_plugin
{
	private $rc;
	private $autologin;
	private $currentUser;
	private $rbl;
	private $viewData = [];
	/**
	 * @var array
	 */
	private $icsParts = [];

	public function init()
	{
		$this->rc = rcmail::get_instance();
		$this->include_stylesheet('elastic.css');

		$this->add_hook('login_after', [$this, 'loginAfter']);
		$this->add_hook('startup', [$this, 'startup']);
		$this->add_hook('authenticate', [$this, 'authenticate']);

		$this->add_hook('storage_init', [$this, 'storageInit']);
		$this->add_hook('messages_list', [$this, 'messagesList']);
		$this->add_hook('message_objects', [$this, 'messageObjects']);

		$this->register_action('plugin.yetiforce-importIcs', [$this, 'importIcs']);
		$this->register_action('plugin.yetiforce-addFilesToMail', [$this, 'addFilesToMail']);
		$this->register_action('plugin.yetiforce-getContentEmailTemplate', [$this, 'getContentEmailTemplate']);
		$this->register_action('plugin.yetiforce-addSenderToList', [$this, 'addSenderToList']);
		$this->register_action('plugin.yetiforce-loadMailAnalysis', [$this, 'loadMailAnalysis']);

		if ('mail' == $this->rc->task) {
			$this->include_stylesheet('../../../../../layouts/resources/icons/yfm.css');
			$this->include_stylesheet('../../../../../layouts/resources/icons/additionalIcons.css');
			$this->include_stylesheet('../../../../../libraries/@fortawesome/fontawesome-free/css/all.css');
			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			$this->loadCurrentUser();

			$this->rc->load_language(null, [
				'LBL_FILE_FROM_CRM' => \App\Language::translate('LBL_FILE_FROM_CRM', 'OSSMail', false, false),
				'LBL_MAIL_TEMPLATES' => \App\Language::translate('LBL_MAIL_TEMPLATES', 'OSSMail', false, false),
				'LBL_TEMPLATES' => \App\Language::translate('LBL_TEMPLATES', 'OSSMail', false, false),
				'BTN_BLACK_LIST' => \App\Language::translate('LBL_BLACK_LIST', 'OSSMail', false, false),
				'LBL_BLACK_LIST_DESC' => \App\Language::translate('LBL_BLACK_LIST_DESC', 'OSSMail', false, false),
				'BTN_WHITE_LIST' => \App\Language::translate('LBL_WHITE_LIST', 'OSSMail', false, false),
				'LBL_WHITE_LIST_DESC' => \App\Language::translate('LBL_WHITE_LIST_DESC', 'OSSMail', false, false),
				'LBL_ALERT_NEUTRAL_LIST' => \App\Language::translate('LBL_ALERT_NEUTRAL_LIST', 'OSSMail', false, false),
				'LBL_ALERT_BLACK_LIST' => \App\Language::translate('LBL_BLACK_LIST_ALERT', 'OSSMail', false, false),
				'LBL_ALERT_WHITE_LIST' => \App\Language::translate('LBL_WHITE_LIST_ALERT', 'OSSMail', false, false),
				'LBL_ALERT_FAKE_MAIL' => \App\Language::translate('LBL_ALERT_FAKE_MAIL', 'OSSMail'),
				'BTN_ANALYSIS_DETAILS' => \App\Language::translate('BTN_ANALYSIS_DETAILS', 'OSSMail', false, false),
				'LBL_ALERT_FAKE_SENDER' => \App\Language::translate('LBL_ALERT_FAKE_SENDER', 'OSSMail'),
			]);

			if ('preview' === $this->rc->action || 'show' === $this->rc->action || '' == $this->rc->action) {
				$this->include_script('preview.js');
				$this->include_stylesheet('preview.css');

				$this->add_hook('template_object_messageattachments', [$this, 'appendIcsPreview']);
				$this->add_hook('template_object_messagesummary', [$this, 'messageSummary']);
				$this->add_hook('message_load', [$this, 'messageLoad']);

				$this->add_button([
					'command' => 'plugin.yetiforce.addSenderToList',
					'type' => 'link',
					'prop' => 1,
					'class' => 'button yfi-fa-check-circle disabled js-white-list-btn text-success',
					'classact' => 'button yfi-fa-check-circle text-success',
					'classsel' => 'button yfi-fa-check-circle pressed text-success',
					'title' => 'LBL_WHITE_LIST_DESC',
					'label' => 'BTN_WHITE_LIST',
					'innerclass' => 'inner',
				], 'toolbar');
				$this->add_button([
					'command' => 'plugin.yetiforce.addSenderToList',
					'type' => 'link',
					'prop' => 0,
					'class' => 'button yfi-fa-ban disabled text-danger',
					'classact' => 'button yfi-fa-ban text-danger',
					'classsel' => 'button yfi-fa-ban pressed text-danger',
					'title' => 'LBL_BLACK_LIST_DESC',
					'label' => 'BTN_BLACK_LIST',
					'innerclass' => 'inner',
				], 'toolbar');
				$this->add_button([
					'command' => 'plugin.yetiforce.loadMailAnalysis',
					'type' => 'link',
					'class' => 'button yfi-fa-book-reader disabled text-info',
					'classact' => 'button yfi-fa-book-reader text-info',
					'classsel' => 'button yfi-fa-book-reader pressed text-info',
					'title' => 'BTN_ANALYSIS_DETAILS',
					'label' => 'BTN_ANALYSIS_DETAILS',
					'innerclass' => 'inner',
				], 'toolbar');
			} elseif ('compose' === $this->rc->action) {
				$this->include_script('compose.js');
				$composeAddressModules = [];
				foreach (\App\Config::component('Mail', 'RC_COMPOSE_ADDRESS_MODULES') as $moduleName) {
					if (\App\Privilege::isPermitted($moduleName)) {
						$composeAddressModules[$moduleName] = \App\Language::translate($moduleName, $moduleName);
					}
				}
				$this->viewData['compose']['composeAddressModules'] = $composeAddressModules;
				$this->rc->output->set_env('yf_isPermittedMailTemplates', \App\Privilege::isPermitted('EmailTemplates'));

				$this->rc->output->add_handler('yetiforce.adressbutton', [$this, 'adressButton']);
				$this->add_hook('render_page', [$this, 'loadSignature']);

				$this->add_hook('message_compose_body', [$this, 'messageComposeBody']);
				$this->add_hook('message_compose', [$this, 'messageComposeHead']);

				if ($id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC)) {
					$id = App\Purifier::purifyByType($id, 'Alnum');
					if (isset($_SESSION['compose_data_' . $id]['param']['crmmodule'])) {
						$this->rc->output->set_env('yf_crmModule', $_SESSION['compose_data_' . $id]['param']['crmmodule']);
					}
					if (isset($_SESSION['compose_data_' . $id]['param']['crmrecord'])) {
						$this->rc->output->set_env('yf_crmRecord', $_SESSION['compose_data_' . $id]['param']['crmrecord']);
					}
					if (isset($_SESSION['compose_data_' . $id]['param']['crmview'])) {
						$this->rc->output->set_env('yf_crmView', $_SESSION['compose_data_' . $id]['param']['crmview']);
					}
				}
			}
			chdir($currentPath);
		} elseif ('settings' == $this->rc->task) {
			$this->add_hook('preferences_list', [$this, 'settingsDisplayPrefs']);
			$this->add_hook('preferences_save', [$this, 'settingsSavePrefs']);
		}
	}

	/**
	 * startup hook handler.
	 *
	 * @param array $args
	 */
	public function startup($args)
	{
		if (empty($_GET['_autologin']) || !($row = $this->getAutoLogin())) {
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

	/**
	 * authenticate hook handler.
	 *
	 * @param array $args
	 */
	public function authenticate($args)
	{
		if (!empty($_GET['_autologin']) && ($row = $this->getAutoLogin())) {
			$host = false;
			foreach ($this->rc->config->get('default_host') as $key => $value) {
				if (false !== strpos($key, $row['mail_host'])) {
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

	/**
	 * login_after hook handler.
	 * Password savin.
	 *
	 * @param array $args
	 */
	public function loginAfter($args)
	{
		$pass = rcube_utils::get_input_value('_pass', rcube_utils::INPUT_POST, true, $this->rc->config->get('password_charset', 'UTF-8'));
		if (!empty($pass)) {
			$sql = 'UPDATE ' . $this->rc->db->table_name('users') . ' SET password = ? WHERE user_id = ?';
			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			require_once 'include/main/WebUI.php';
			$pass = \App\Encryption::getInstance()->encrypt($pass);
			chdir($currentPath);
			\call_user_func_array([$this->rc->db, 'query'], array_merge([$sql], [$pass, $this->rc->get_user_id()]));
			$this->rc->db->affected_rows();
		}
		if ($_GET['_autologin'] && !empty($_GET['_composeKey'])) {
			$args['_action'] = 'compose';
			$args['_task'] = 'mail';
			$args['_composeKey'] = App\Purifier::purifyByType(rcube_utils::get_input_value('_composeKey', rcube_utils::INPUT_GET), 'Alnum');
		}
		if ($row = $this->getAutoLogin()) {
			$_SESSION['crm']['id'] = $row['cuid'];
			if (!empty($row['params']['language'])) {
				$language = $row['params']['language'];
			}
		} elseif (!empty($args['cuid'])) {
			$_SESSION['crm']['id'] = $args['cuid'];
			$language = \App\Language::getLanguageTag();
		} else {
			if (!empty($_COOKIE['YTSID']) && \App\Session::load()) {
				$sessionData = \App\Session::getById($_COOKIE['YTSID']);
				$_SESSION['crm']['id'] = $sessionData['authenticated_user_id'] ?? '';
				$language = $sessionData['language'] ?? '';
			}
		}
		if (!empty($language)) {
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
		return $args;
	}

	protected function getAutoLogin()
	{
		if (empty($_GET['_autologinKey'])) {
			return false;
		}
		if (isset($this->autologin)) {
			return $this->autologin;
		}
		$key = App\Purifier::purifyByType(rcube_utils::get_input_value('_autologinKey', rcube_utils::INPUT_GPC), 'Alnum');
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
	 * Set environment variables in JS, needed for QuickCreateForm
	 * message_load hook handler.
	 *
	 * @param array $args
	 */
	public function messageLoad(array $args)
	{
		if (!isset($args['object'])) {
			return;
		}
		$this->message = $args['object'];
		$from = explode('<', rtrim($this->message->headers->from, '>'), 2);
		$fromName = '';
		if (\count($from) > 1) {
			$fromName = $from[0];
			$fromMail = $from[1];
		} else {
			$fromMail = $from[0];
		}
		$this->rc->output->set_env('yf_fromName', $fromName);
		$this->rc->output->set_env('yf_fromMail', $fromMail);
		$this->rc->output->set_env('yf_subject', rcube_mime::decode_header($this->message->headers->subject, $this->message->headers->charset));
		foreach ((array) $this->message->attachments as $attachment) {
			if ('application/ics' === $attachment->mimetype || 'text/calendar' === $attachment->mimetype) {
				$this->icsParts[] = ['part' => $attachment->mime_id, 'uid' => $this->message->uid, 'type' => 'attachments'];
			}
		}
		foreach ((array) $this->message->parts as $part) {
			if ('application/ics' === $part->mimetype || 'text/calendar' === $part->mimetype) {
				$this->icsParts[] = ['part' => $part->mime_id, 'uid' => $this->message->uid, 'type' => 'parts'];
			}
		}
	}

	public function messageComposeHead(array $args)
	{
		$this->rc = rcmail::get_instance();
		$db = $this->rc->get_dbh();
		global $COMPOSE_ID;
		if (empty($_GET['_composeKey'])) {
			return $args;
		}
		$composeKey = App\Purifier::purifyByType(rcube_utils::get_input_value('_composeKey', rcube_utils::INPUT_GET), 'Alnum');
		$result = $db->query('SELECT * FROM `u_yf_mail_compose_data` WHERE `key` = ?', $composeKey);
		$params = $db->fetch_assoc($result);
		$db->query('DELETE FROM `u_yf_mail_compose_data` WHERE `key` = ?;', $composeKey);
		if (!empty($params)) {
			$params = json_decode($params['data'], true);
			foreach ($params as $key => &$value) {
				$args['param'][$key] = $value;
			}
			if ((isset($params['crmmodule']) && 'Documents' == $params['crmmodule']) || (isset($params['filePath']) && $params['filePath'])) {
				$userid = $this->rc->user->ID;
				[$usec, $sec] = explode(' ', microtime());
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
			$result = $db->query('SELECT content,reply_to_email,date,from_email,to_email,cc_email,subject FROM vtiger_ossmailview WHERE ossmailviewid = ?;', $params['mailId']);
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
					if (empty($to)) {
						$to = $row['from_email'];
					}
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

				$subjectNumber = \App\Mail\RecordFinder::getRecordNumberFromString($subject, $params['crmmodule']);
				$recordNumber = \App\Mail\RecordFinder::getRecordNumberFromString("[{$params['recordNumber']}]", $params['crmmodule']);
				if (false === $subject || (false !== $subject && $subjectNumber !== $recordNumber)) {
					$subject = "[{$params['recordNumber']}] $subject";
				}
				chdir($currentPath);
			}
			if (!empty($to)) {
				$args['param']['to'] = $to;
			}
			if (!empty($cc)) {
				$args['param']['cc'] = $cc;
			}
			if (!empty($subject)) {
				$args['param']['subject'] = $subject;
			}
		}
		return $args;
	}

	public function messageComposeBody(array $args)
	{
		$this->rc = rcmail::get_instance();
		$id = App\Purifier::purifyByType(rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC), 'Alnum');
		$row = $_SESSION['compose_data_' . $id]['param']['mailData'];
		$type = $_SESSION['compose_data_' . $id]['param']['type'];
		$params = $_SESSION['compose_data_' . $id]['param'];
		$recordNumber = '';
		// if ($number = \App\Mail\RecordFinder::getRecordNumberFromString("[{$params['recordNumber']}]", $params['crmmodule'])) {
		// 	$recordNumber = "[{$number}]";
		// }
		$bodyIsHtml = $args['html'];
		if (!$row) {
			if ($recordNumber) {
				if (!$bodyIsHtml) {
					$body = "\n ------------------------- \n" . $recordNumber;
				} else {
					$body = '<br><br><hr/>' . $recordNumber;
				}
				$args['body'] = $body;
			}
			return $args;
		}
		$body = $row['content'];
		$date = $row['date'];
		$from = $row['from_email'];
		$to = $row['to_email'];
		$subject = $row['subject'];
		$replyto = $row['reply_to_email'];
		$prefix = $suffix = '';
		if ('forward' === $type) {
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
				$prefix .= '</tbody></table>';
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
				if ($recordNumber) {
					$body .= "\n ------------------------- \n" . $recordNumber;
				}
			} else {
				$prefix = '<p>' . rcube::Q($prefix) . "</p>\n";
				$body = $prefix . '<blockquote>' . $body . '</blockquote>' . $suffix;
				if ($recordNumber) {
					$body .= '<hr/>' . $recordNumber;
				}
			}
		}
		$this->rc->output->set_env('compose_mode', $type);
		$args['body'] = $body;
		return $args;
	}

	//	Loading signature
	public function loadSignature(array $args)
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
		if ($MESSAGE->identities) {
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
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$config = Settings_Mail_Config_Model::getConfig('signature');
		$parser = App\TextParser::getInstanceById($this->currentUser->getId(), 'Users');
		$result['text'] = $result['html'] = $parser->setContent($config['signature'])->parse()->getContent();
		chdir($currentPath);
		return $result;
	}

	public function checkAddSignature()
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$config = Settings_Mail_Config_Model::getConfig('signature');
		chdir($currentPath);
		return empty($config['addSignature']) || 'false' === $config['addSignature'] ? true : false;
	}

	/**
	 * Add files to mail.
	 *
	 * @return void
	 */
	public function addFilesToMail()
	{
		$COMPOSE_ID = App\Purifier::purifyByType(rcube_utils::get_input_value('_id', rcube_utils::INPUT_GPC), 'Alnum');
		$uploadid = App\Purifier::purifyByType(rcube_utils::get_input_value('_uploadid', rcube_utils::INPUT_GPC), 'Integer');
		$ids = App\Purifier::purifyByType(rcube_utils::get_input_value('ids', rcube_utils::INPUT_GPC), 'Integer');
		$COMPOSE = null;
		if ($COMPOSE_ID && $_SESSION['compose_data_' . $COMPOSE_ID]) {
			$SESSION_KEY = 'compose_data_' . $COMPOSE_ID;
			$COMPOSE = &$_SESSION[$SESSION_KEY];
		}
		if (!$COMPOSE) {
			exit('Invalid session var!');
		}
		$index = 0;
		foreach ($this->getAttachment($ids, false) as $attachment) {
			++$index;
			$userid = rcmail::get_instance()->user->ID;
			[$usec, $sec] = explode(' ', microtime());
			$id = preg_replace('/[^0-9]/', '', $userid . $sec . $usec) . $index;
			$attachment['id'] = $id;
			$attachment['group'] = $COMPOSE_ID;
			@chmod($attachment['path'], 0600);  // set correct permissions (#1488996)
			$_SESSION['plugins']['filesystem_attachments'][$COMPOSE_ID][$id] = realpath($attachment['path']);
			$this->rc->session->append($SESSION_KEY . '.attachments', $id, $attachment);
			$this->rcmail_attachment_success($attachment, $uploadid);
		}
		$this->rc->output->command('auto_save_start');
		$this->rc->output->send('iframe');
	}

	/**
	 * Adding attachments.
	 *
	 * @param mixed $ids
	 * @param mixed $files
	 *
	 * @return void
	 */
	public function getAttachment($ids, $files)
	{
		$attachments = [];
		if (empty($ids) && empty($files)) {
			return $attachments;
		}
		if (!\is_array($ids)) {
			$ids = implode(',', $ids);
		}
		$userid = $this->rc->user->ID;
		$index = 0;
		if ($ids) {
			foreach (App\Mail::getAttachmentsFromDocument($ids, false) as $filePath => $row) {
				[, $sec] = explode(' ', microtime());
				$path = $this->rc->config->get('temp_dir') . DIRECTORY_SEPARATOR . "{$sec}_{$userid}_{$row['attachmentsid']}_$index.tmp";
				if (file_exists($filePath)) {
					copy($filePath, $path);
					$attachments[] = [
						'path' => $path,
						'name' => $row['name'],
						'size' => filesize($path),
						'mimetype' => rcube_mime::file_content_type($path, $row['name'], $row['type']),
					];
				}
				++$index;
			}
		}
		if ($files) {
			if (!\is_array($files)) {
				$files = [$files];
			}
			foreach ($files as $file) {
				$orgFileName = $file['name'] ?? basename($file);
				$orgFilePath = $file['path'] ?? $file;
				[, $sec] = explode(' ', microtime());
				$filePath = $this->rc->config->get('temp_dir') . DIRECTORY_SEPARATOR . "{$sec}_{$userid}_{$index}.tmp";
				$orgFile = $orgFilePath;
				if (!file_exists($orgFile)) {
					$orgFile = $this->rc->config->get('root_directory') . $orgFilePath;
				}
				if (file_exists($orgFile)) {
					copy($orgFile, $filePath);
					$attachment = [
						'path' => $filePath,
						'size' => filesize($filePath),
						'name' => $orgFileName,
						'mimetype' => rcube_mime::file_content_type($filePath, $orgFileName),
					];
					if (0 === strpos($orgFilePath, 'cache')) {
						unlink($orgFile);
					}
					$attachments[] = $attachment;
				}
				++$index;
			}
		}
		return $attachments;
	}

	/**
	 * Copy functions from a file public_html/modules/OSSMail/roundcube/program/steps/mail/attachments.inc.
	 *
	 * @param array  $attachment
	 * @param string $uploadid
	 *
	 * @return void
	 */
	public function rcmail_attachment_success($attachment, $uploadid)
	{
		global $RCMAIL, $COMPOSE;

		$id = $attachment['id'];

		if (($icon = $COMPOSE['deleteicon']) && is_file($icon)) {
			$button = html::img([
				'src' => $icon,
				'alt' => $RCMAIL->gettext('delete')
			]);
		} elseif ($COMPOSE['textbuttons']) {
			$button = rcube::Q($RCMAIL->gettext('delete'));
		} else {
			$button = '';
		}

		$link_content = sprintf('<span class="attachment-name">%s</span><span class="attachment-size">(%s)</span>',
		rcube::Q($attachment['name']), $RCMAIL->show_bytes($attachment['size']));

		$content_link = html::a([
			'href' => '#load',
			'class' => 'filename',
			'onclick' => sprintf("return %s.command('load-attachment','rcmfile%s', this, event)", rcmail_output::JS_OBJECT_NAME, $id),
		], $link_content);

		$delete_link = html::a([
			'href' => '#delete',
			'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this, event)", rcmail_output::JS_OBJECT_NAME, $id),
			'title' => $RCMAIL->gettext('delete'),
			'class' => 'delete',
			'aria-label' => $RCMAIL->gettext('delete') . ' ' . $attachment['name'],
		], $button);

		$content = 'left' == $COMPOSE['icon_pos'] ? $delete_link . $content_link : $content_link . $delete_link;

		$RCMAIL->output->command('add2attachment_list', "rcmfile$id", [
			'html' => $content,
			'name' => $attachment['name'],
			'mimetype' => $attachment['mimetype'],
			'classname' => rcube_utils::file2class($attachment['mimetype'], $attachment['name']),
			'complete' => true], $uploadid);
	}

	public function rcmailWrapAndQuote($text, $length = 72)
	{
		// Rebuild the message body with a maximum of $max chars, while keeping quoted message.
		$max = max(75, $length + 8);
		$lines = preg_split('/\r?\n/', trim($text));
		$out = '';
		foreach ($lines as $line) {
			// don't wrap already quoted lines
			if ('>' == $line[0]) {
				$line = '>' . rtrim($line);
			} elseif (mb_strlen($line) > $max) {
				$newline = '';

				foreach (explode("\n", rcube_mime::wordwrap($line, $length - 2)) as $l) {
					if (\strlen($l)) {
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
		\App\Language::setTemporaryLanguage($this->currentUser->getDetail('language'));
		return true;
	}

	public function adressButton(array $args)
	{
		if (empty($this->viewData['compose']['composeAddressModules'])) {
			return '';
		}
		$content = '';
		foreach ($this->viewData['compose']['composeAddressModules'] as $moduleName => $value) {
			$text = html::span(['class' => "yfm-$moduleName"], '') . " <span class=\"inner\">$value</span>";
			$content .= html::a([
				'class' => 'btn btn-sm btn-outline-dark mr-1 mt-1',
				'href' => '#',
				'data-input' => $args['part'],
				'data-module' => $moduleName,
				'title' => $value,
				'onclick' => "return rcmail.command('yetiforce.selectAdress','$moduleName','{$args['part']}')",
			], $text);
		}
		return $content;
	}

	/**
	 * Function to get info about email template.
	 */
	public function getContentEmailTemplate()
	{
		$templateId = App\Purifier::purifyByType(rcube_utils::get_input_value('id', rcube_utils::INPUT_GPC), 'Integer');
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$this->loadCurrentUser();
		$mail = [];
		if (\App\Privilege::isPermitted('EmailTemplates', 'DetailView', $templateId)) {
			$mail = \App\Mail::getTemplate($templateId);
			if ($recordId = rcube_utils::get_input_value('record_id', rcube_utils::INPUT_GPC)) {
				$textParser = \App\TextParser::getInstanceById(
					App\Purifier::purifyByType($recordId, 'Integer'),
					App\Purifier::purifyByType(rcube_utils::get_input_value('select_module', rcube_utils::INPUT_GPC), 'Alnum')
					);
				$mail['subject'] = $textParser->setContent($mail['subject'])->parse()->getContent();
				$mail['content'] = $textParser->setContent($mail['content'])->parse()->getContent();
			}
		}
		echo App\Json::encode([
			'subject' => $mail['subject'] ?? null,
			'content' => $mail['content'] ?? null,
			'attachments' => $mail['attachments'] ?? null
		]);
		chdir($currentPath);
		exit;
	}

	/**
	 * Append ical preview in attachments' area.
	 * template_object_messageattachments hook handler.
	 *
	 * @param array $args
	 *
	 * @return mixed
	 */
	public function appendIcsPreview(array $args): array
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$this->loadCurrentUser();
		$showPart = $ics = $counterBtn = $counterList = [];
		foreach ($this->icsParts as $icsPart) {
			$icsContent = $this->message->get_part_content($icsPart['part'], null, true);
			$calendar = \App\Integrations\Dav\Calendar::loadFromContent($icsContent);
			foreach ($calendar->getRecordInstance() as $key => $recordModel) {
				if (!isset($ics[$key])) {
					$ics[$key] = [$recordModel, $icsPart];
					if (isset($counterBtn[$icsPart['part']])) {
						++$counterBtn[$icsPart['part']];
					} else {
						$counterBtn[$icsPart['part']] = 1;
					}
				}
			}
		}
		$translationMod = 'Calendar';
		$showMore = false;
		foreach ($ics as $data) {
			$evTemplate = '<div class="c-ical mb-1">';
			[$record, $icsPart] = $data;
			$dateStart = $fields = $fieldsDescription = '';
			if (!$record->isEmpty('date_start')) {
				$dateStart = $record->getDisplayValue('date_start');
				$dateStartLabel = \App\Language::translate('LBL_START');
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-clock mr-1\"></span><strong>$dateStartLabel</strong>: $dateStart</div>";
			}
			if (!$record->isEmpty('due_date')) {
				$dueDate = $record->getDisplayValue('due_date');
				$dueDateLabel = \App\Language::translate('LBL_END');
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-clock mr-1\"></span><strong>$dueDateLabel</strong>: $dueDate</div>";
			}
			if ($location = $record->getDisplayValue('location', false, false, 100)) {
				$locationLabel = \App\Language::translate('Location', $translationMod);
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-map mr-1\"></span><strong>$locationLabel</strong>: $location</div>";
			}
			if ($status = $record->getDisplayValue('activitystatus')) {
				$statusLabel = \App\Language::translate('LBL_STATUS', $translationMod);
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-question-circle mr-1\"></span><strong>$statusLabel</strong>: $status</div>";
			}
			if ($type = $record->getDisplayValue('activitytype')) {
				$typeLabel = \App\Language::translate('Activity Type', $translationMod);
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-calendar mr-1\"></span><strong>$typeLabel</strong>: $type</div>";
			}
			if ($location = $record->getDisplayValue('meeting_url', false, false, 40)) {
				$locationLabel = \App\Language::translate('FL_MEETING_URL', $translationMod);
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"AdditionalIcon-VideoConference mr-1\"></span><strong>$locationLabel</strong>: $location</div>";
			}
			if ($allday = $record->getDisplayValue('allday')) {
				$alldayLabel = \App\Language::translate('All day', $translationMod);
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-edit mr-1\"></span><strong>$alldayLabel</strong>: $allday</div>";
			}
			if ($visibility = $record->getDisplayValue('visibility')) {
				$visibilityLabel = \App\Language::translate('Visibility', $translationMod);
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-eye mr-1\"></span><strong>$visibilityLabel</strong>: $visibility</div>";
			}
			if ($priority = $record->getDisplayValue('taskpriority')) {
				$label = \App\Language::translate('Priority', $translationMod);
				$fields .= "<div class=\"col-lg-4 col-sm-6 col-12\"><span class=\"fas fa-exclamation-circle mr-1\"></span><strong>$label</strong>: $priority</div>";
			}
			if ($description = $record->getDisplayValue('description', false, false, 50)) {
				$descriptionLabel = \App\Language::translate('Description', $translationMod);
				$fieldsDescription .= "<div class=\"col-12 mt-2\"><span class=\"fas fa-edit mr-1\"></span><strong>$descriptionLabel</strong>: $description</div>";
			}
			$evTemplate .= "<div class=\"w-100 c-ical__event card border-primary\">
								<div class=\"card-header c-ical__header py-1 d-sm-flex align-items-center text-center\">
									  <h3 class='c-ical__subject card-title mb-0 mr-auto text-center'>{$record->getDisplayValue('subject')} | $dateStart </h3>
									  <span class=\"button_to_replace\"></span>
								</div>
								<div class=\"c-ical__wrapper card-body py-2\">
									<div class=\"row\">
										$fields
										$fieldsDescription
									</div>
								</div>
							  </div>";
			$evTemplate .= '</div>';
			if (!isset($showPart[$icsPart['part']]) && \App\Privilege::isPermitted('Calendar', 'CreateView')) {
				$showPart[$icsPart['part']] = $icsPart['part'];
				$title = \App\Language::translate('LBL_ADD_TO_MY_CALENDAR', 'OSSMail');
				$counterText = empty($counterBtn[$icsPart['part']]) ? '' : ($counterBtn[$icsPart['part']] > 1 ? " ({$counterBtn[$icsPart['part']]})" : '');
				$btn = html::a([
					'href' => '#',
					'class' => 'button btn btn-sm btn-light',
					'onclick' => "return rcmail.command('yetiforce.importICS',{$icsPart['part']},'{$icsPart['type']}')",
					'title' => $title,
				], html::span(null, "<span class=\"far fa-calendar-plus mr-1\"></span>{$title}{$counterText}"));
				$evTemplate = str_replace('<span class="button_to_replace"></span>', "<span class=\"importBtn\">{$btn}</span>", $evTemplate);
				$args['content'] .= $evTemplate;
			} elseif ($counterList[$icsPart['part']] < 4) {
				$args['content'] .= $evTemplate;
			} else {
				$showMore = true;
			}
			if (isset($counterList[$icsPart['part']])) {
				++$counterList[$icsPart['part']];
			} else {
				$counterList[$icsPart['part']] = 1;
			}
		}
		if ($showMore) {
			$args['content'] .= html::div(null, '...');
		}
		chdir($currentPath);
		return $args;
	}

	/**
	 * Handler for plugin actions (AJAX), import ICS file.
	 *
	 * @return void
	 */
	public function importIcs(): void
	{
		chdir($this->rc->config->get('root_directory'));
		$this->loadCurrentUser();
		if (\App\Privilege::isPermitted('Calendar', 'CreateView')) {
			$mailId = (int) rcube_utils::get_input_value('_mailId', rcube_utils::INPUT_GPC);
			$uid = App\Purifier::purifyByType(rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GPC), 'Alnum');
			$mbox = App\Purifier::purifyByType(rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GPC), 'Alnum');
			$mime_id = App\Purifier::purifyByType(rcube_utils::get_input_value('_part', rcube_utils::INPUT_GPC), 'Text');
			$status = 0;
			if ($uid && $mbox && $mime_id) {
				$message = new rcube_message($uid, $mbox);
				$calendar = \App\Integrations\Dav\Calendar::loadFromContent($message->get_part_body($mime_id));
				foreach ($calendar->getRecordInstance() as $key => $recordModel) {
					$recordModel->set('assigned_user_id', $this->currentUser->getId());
					$recordModel->save();
					if ($recordModel->getId()) {
						$calendar->recordSaveAttendee($recordModel);
						if ($mailId) {
							$relationModel = new OSSMailView_Relation_Model();
							$relationModel->addRelation($mailId, $recordModel->getId());
						}
						++$status;
					}
				}
			}
			$this->rc->output->command('display_message', $status ? \App\Language::translateArgs('LBL_FILE_HAS_BEEN_IMPORTED', 'OSSMail', $status) : \App\Language::translate('LBL_ERROR_OCCURRED_DURING_IMPORT', 'OSSMail'), 'notice');
		} else {
			$this->rc->output->command('display_message', \App\Language::translate('LBL_PERMISSION_DENIED'), 'error');
		}
	}

	/**
	 * Handler for plugin actions (AJAX), mark mail file.
	 *
	 * @return void
	 */
	public function addSenderToList(): void
	{
		$props = (int) rcube_utils::get_input_value('_props', rcube_utils::INPUT_POST);
		$mbox = (string) rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
		$messageset = rcmail::get_uids(null, $mbox, $multi, rcube_utils::INPUT_POST);
		if ($messageset) {
			chdir($this->rc->config->get('root_directory'));
			$imap = $this->rc->get_storage();
			foreach ($messageset as $mbox => $uids) {
				$imap->set_folder($mbox);
				if ('*' === $uids) {
					$index = $imap->index($mbox, null, null, true);
					$uids = $index->get();
				}
				foreach ($uids as $uid) {
					$headers = $imap->get_raw_headers($uid);
					$body = null;
					if (0 === $props) {
						$message = new rcube_message($uid, $mbox);
						$body = $message->first_html_part();
					}
					\App\Mail\Rbl::addReport([
						'type' => $props,
						'header' => $headers,
						'body' => $body,
					]);
				}
			}
			if (0 === $props && ($junkMbox = $this->rc->config->get('junk_mbox')) && $mbox !== $junkMbox) {
				$this->rc->output->command('addSenderToListMove', $junkMbox);
			}
			$this->rc->output->command('display_message', \App\Language::translate('LBL_MESSAGE_HAS_BEEN_ADDED', 'OSSMail'), 'notice');
		}
	}

	/**
	 * storage_init hook handler.
	 * Adds additional headers to supported headers list.
	 *
	 * @param array $p
	 */
	public function storageInit(array $p): array
	{
		$p['fetch_headers'] = trim($p['fetch_headers'] . ' RECEIVED');
		return $p;
	}

	/**
	 * messages_list hook handler.
	 * Plugins may set header's list_cols/list_flags and other rcube_message_header variables and list columns.
	 *
	 * @param array $p
	 */
	public function messagesList(array $p): array
	{
		if (!\App\Config::component('Mail', 'rcListCheckRbl', false)) {
			return $p;
		}
		if (!empty($p['messages'])) {
			$ipList = $senderList = [];
			foreach ($p['messages'] as $message) {
				$parseMessage = $this->parseMessage($message);
				if ('' !== $parseMessage['header']) {
					$rblInstance = \App\Mail\Rbl::getInstance($parseMessage);
					if ($ip = $rblInstance->getSender()['ip'] ?? '') {
						$ipList[$message->uid] = $ip;
					}
					$verify = $rblInstance->verifySender();
					if (false === $verify['status']) {
						$senderList[$message->uid] = "<span class=\"fas fa-exclamation-triangle text-danger\" title=\"{$verify['info']}\"></span>";
					}
				}
			}
			$this->rc->output->set_env('yf_rblList', \App\Mail\Rbl::getColorByIps($ipList));
			$this->rc->output->set_env('yf_senderList', $senderList);
		}
		return $p;
	}

	/**
	 * Parse message.
	 *
	 * @param rcube_message_header $message
	 *
	 * @return array
	 */
	public function parseMessage(rcube_message_header $message): array
	{
		$header = '';
		$message->others['received'];
		if (isset($message->from)) {
			$header .= 'From: ' . $message->from . PHP_EOL;
		}
		if (isset($message->others['received'])) {
			$received = \is_array($message->others['received']) ? implode(PHP_EOL . 'Received: ', $message->others['received']) : $message->others['received'];
			$header .= 'Received: ' . $received . PHP_EOL;
		}
		if (isset($message->others['return-path'])) {
			$returnPath = \is_array($message->others['return-path']) ? implode(PHP_EOL . 'Return-Path: ', $message->others['return-path']) : $message->others['return-path'];
			$header .= 'Return-Path: ' . $returnPath . PHP_EOL;
		}
		if (isset($message->others['sender'])) {
			$header .= 'Sender: ' . $message->others['sender'] . PHP_EOL;
		}
		return ['header' => $header];
	}

	/**
	 * message_objects hook handler.
	 * Show alert in message with sender's server verification.
	 *
	 * @param array $p
	 */
	public function messageObjects(array $p): array
	{
		if (empty($this->rbl)) {
			$this->rbl = \App\Mail\Rbl::getInstance([]);
			$this->rbl->set('rawBody', $this->rc->imap->get_raw_body($p['message']->uid));
			$this->rbl->parse();
		}
		$sender = $rows = [];
		if ($ip = $this->rbl->getSender()['ip'] ?? '') {
			$rows = \App\Mail\Rbl::findIp($ip);
		}
		if ($rows) {
			foreach ($rows as $row) {
				if (1 !== (int) $row['status']) {
					$row['type'] = (int) $row['type'];
					$row['isBlack'] = \App\Mail\Rbl::LIST_TYPE_BLACK_LIST === $row['type'] || \App\Mail\Rbl::LIST_TYPE_PUBLIC_BLACK_LIST === $row['type'];
					$sender = $row;
					break;
				}
			}
		}
		if (($verifySender = $this->rbl->verifySender()) && !$verifySender['status']) {
			$btnMoreIcon = 'fas fa-exclamation-circle text-danger';
			$desc = \App\Language::translate('LBL_MAIL_SENDER', 'Settings:MailRbl') . ': ' . html::span(['class' => 'badge badge-danger'], html::span(['class' => 'mr-2 alert-icon fas fa-times'], '') . \App\Language::translate('LBL_INCORRECT', 'Settings:MailRbl')) . '<br>' . str_replace('<>', '<br>', $verifySender['info']);
			$alert = '';
			$alert .= html::span(null, $this->rc->gettext('LBL_ALERT_FAKE_SENDER'));
			$alert .= html::span(['class' => 'd-block'], html::span([], html::tag('button', [
				'onclick' => "return rcmail.command('plugin.yetiforce.loadMailAnalysis')",
				'title' => $this->gettext('addvcardmsg'),
				'class' => 'fakeMail float-right',
			], rcube::Q($this->rc->gettext('BTN_ANALYSIS_DETAILS'))) . $desc));
			$alertBlock = html::div(['id' => 'moreAlert', 'class' => 'd-none aligned-buttons boxerror '],
				html::span(null, $alert)
			);
		} else {
			$verifySpf = $this->rbl->verifySpf();
			$verifyDmarc = $this->rbl->verifyDmarc();
			$verifyDkim = $this->rbl->verifyDkim();
			$dangerType = \App\Mail\Rbl::SPF_FAIL === $verifySpf['status'] || \App\Mail\Rbl::DMARC_FAIL === $verifyDmarc['status'] || \App\Mail\Rbl::DKIM_FAIL === $verifyDkim['status'];
			$desc = '';
			$btnMoreIcon = $dangerType ? 'fas fa-exclamation-circle text-danger' : 'fas fa-exclamation-triangle text-warning';
			if (\App\Mail\Rbl::SPF_PASS !== $verifySpf['status']) {
				$desc .= '- ' . \App\Language::translate('LBL_SPF', 'Settings:MailRbl') . ': ' . html::span(['class' => 'badge ' . $verifySpf['class']], html::span(['class' => 'mr-2 alert-icon ' . $verifySpf['icon']], '') . \App\Language::translate($verifySpf['label'], 'Settings:MailRbl')) . ' ' . \call_user_func_array('vsprintf', [\App\Language::translate($verifySpf['desc'], 'Settings:MailRbl', false, false), [$verifySpf['domain']]]) . '<br />';
			}
			if (\App\Mail\Rbl::DKIM_PASS !== $verifyDkim['status']) {
				$desc .= '- ' . \App\Language::translate('LBL_DKIM', 'Settings:MailRbl') . ': ' . html::span(['class' => 'badge ' . $verifyDkim['class'], 'title' => $verifyDkim['log']], html::span(['class' => 'mr-2 alert-icon ' . $verifyDkim['icon']], '') . \App\Language::translate($verifyDkim['label'], 'Settings:MailRbl')) . ' ' . \App\Language::translate($verifyDkim['desc'], 'Settings:MailRbl') . '<br />';
			}
			if (\App\Mail\Rbl::DMARC_PASS !== $verifyDmarc['status']) {
				$desc .= '- ' . \App\Language::translate('LBL_DMARC', 'Settings:MailRbl') . ': ' . html::span(['class' => 'badge ' . $verifyDmarc['class'], 'title' => $verifyDmarc['log']], html::span(['class' => 'mr-2 alert-icon ' . $verifyDmarc['icon']], '') . \App\Language::translate($verifyDmarc['label'], 'Settings:MailRbl')) . ' ' . \App\Language::translate($verifyDmarc['desc'], 'Settings:MailRbl') . '<br />';
			}
			if ($desc) {
				$alert = '';

				$alert .= html::span(null, $this->rc->gettext('LBL_ALERT_FAKE_MAIL'));
				$alert .= html::span(['class' => 'd-block'], html::span([], html::tag('button', [
					'onclick' => "return rcmail.command('plugin.yetiforce.loadMailAnalysis')",
					'title' => $this->gettext('addvcardmsg'),
					'class' => 'fakeMail float-right',
				], rcube::Q($this->rc->gettext('BTN_ANALYSIS_DETAILS'))) . $desc));
				$alertBlock = html::div(['id' => 'moreAlert', 'class' => 'd-none aligned-buttons ' . ($dangerType ? 'boxerror' : 'boxwarning')],
					html::span(null, $alert)
			);
			}
		}
		$btnMore = html::span(['class' => 'float-right', 'style' => 'margin-left: auto;'], '<button class="btn btn-sm m-0 p-0" type="button" id="moreAlertBtn"><span class="' . $btnMoreIcon . ' h3"></span></button>');
		if ($sender) {
			$type = \App\Mail\Rbl::LIST_TYPES[$sender['type']];
			$p['content'][] = html::div(['class' => 'mail-type-alert', 'style' => 'background:' . $type['alertColor']],
				html::span(['class' => 'alert-icon ' . $type['icon']], '') .
				html::span(null, rcube::Q($this->rc->gettext($sender['isBlack'] ? 'LBL_ALERT_BLACK_LIST' : 'LBL_ALERT_WHITE_LIST'))) . $btnMore
			) . $alertBlock;
			return $p;
		}
		$p['content'][] = html::div(['class' => 'mail-type-alert', 'style' => 'background: #eaeaea'],
			html::span(['class' => 'alert-icon far fa-question-circle mr-2'], '') .
			html::span(null, rcube::Q($this->rc->gettext('LBL_ALERT_NEUTRAL_LIST'))) . $btnMore
		) . $alertBlock;
		return $p;
	}

	public function loadMailAnalysis(): void
	{
		$uid = (int) rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
		$this->rc->output->command('plugin.yetiforce.showMailAnalysis', $this->rc->imap->get_raw_body($uid));
	}

	/**
	 * Message summary area.
	 * template_object_messagesummary hook handler.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function messageSummary(array $args): array
	{
		$yetiShowTo = $this->rc->config->get('yeti_show_to');
		global $MESSAGE;
		if (!isset($MESSAGE) || empty($MESSAGE->headers)) {
			return $args;
		}
		$contnet = $end = '';
		if (!empty($yetiShowTo)) {
			$header = $MESSAGE->context ? 'from' : rcmail_message_list_smart_column_name();
			if ('from' === $header) {
				$mail = $MESSAGE->headers->to;
				$label = $this->rc->gettext('to');
			} else {
				$mail = $MESSAGE->headers->from;
				$label = $this->rc->gettext('from');
			}
			if ($mail) {
				if (false !== strpos($mail, '<')) {
					preg_match_all('/<(.*?)>/', $mail, $matches);
					if (isset($matches[1])) {
						$mail = implode(', ', $matches[1]);
					}
				}
				$separator = '<br>';
				if ('same' === $yetiShowTo) {
					$separator = '   |  ';
				}
				$contnet = " {$separator}{$label} {$mail}";
			}
		}
		$this->rbl = \App\Mail\Rbl::getInstance([]);
		$this->rbl->set('rawBody', $this->rc->imap->get_raw_body($MESSAGE->uid));
		$this->rbl->parse();
		if ($ip = $this->rbl->getSender()['ip'] ?? '') {
			$end = html::span(['class' => 'float-right'], '<span class="btn-group" role="group" aria-label="SOC">
			<button type="button" class="btn btn-sm btn-info" title="YetiForce Security Operations Center">YF-SOC</button>
			<a href="https://soc.yetiforce.com/search?ip=' . $ip . '" title="soc.yetiforce.com" target="_blank" class="btn btn-sm btn-outline-info">' . $ip . '</a>
		  </span>');
		}
		$args['content'] = str_replace('</span></span></div>', '', rtrim($args['content'])) . "{$contnet}</span></span>{$end}</div>";
		return $args;
	}

	/**
	 * Hook to inject plugin-specific user settings.
	 *
	 * @param array $args
	 */
	public function settingsDisplayPrefs(array $args): array
	{
		if ('general' != $args['section']) {
			return $args;
		}
		$type = $this->rc->config->get('yeti_show_to');

		$showTo = new html_select(['name' => '_yeti_show_to', 'id' => 'ff_yeti_show_to']);
		$showTo->add($this->gettext('none'), '');
		$showTo->add($this->gettext('show_to_same_line'), 'same');
		$showTo->add($this->gettext('show_to_new_line'), 'new');

		$args['blocks']['YetiForce'] = [
			'name' => 'YetiForce',
			'options' => ['yeti_show_to' => [
				'title' => html::label('ff_yeti_show_to', rcube::Q($this->gettext('show_to'))),
				'content' => $showTo->show($type)
			]
			]
		];
		return $args;
	}

	/**
	 * Hook to save plugin-specific user settings.
	 *
	 * @param mixed $args
	 */
	public function settingsSavePrefs(array $args): array
	{
		$args['prefs']['yeti_show_to'] = rcube_utils::get_input_value('_yeti_show_to', rcube_utils::INPUT_POST);
		return $args;
	}
}
