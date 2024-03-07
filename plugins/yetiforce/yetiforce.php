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
	/**
	 * [
	 *   'output'  => $output_headers,
	 *   'headers' => $headers_obj,
	 *   'exclude' => $exclude_headers,       // readonly
	 *   'folder'  => self::$MESSAGE->folder, // readonly
	 *   'uid'     => self::$MESSAGE->uid,    // readonly
	 *  ].
	 *
	 * @var array
	 */
	protected $messageHeaders;

	/**
	 * [
	 *   'message'    => $message,
	 *   'identities' => $identities,
	 *   'selected'   => $from_idx
	 *  ].
	 *
	 * @var array
	 */
	protected $identitySelect;

	/** @var array|bool */
	private $autologin;

	/** @var array */
	private $viewData = [];

	/** @var \App\User */
	private $currentUser;

	/** @var rcmail */
	private $rc;

	/** @var string|null */
	protected static $SESSION_KEY;

	/** @var array|null */
	protected static $COMPOSE;

	/** @var string|null */
	protected static $COMPOSE_ID;

	/** @var array */
	private $icsParts = [];

	/**
	 * Plugin initialization.
	 */
	public function init()
	{
		$this->rc = rcmail::get_instance();
		$skin = $this->rc->config->get('yeti_skin');
		if (empty($skin) || 'elastic' === $skin) {
			$this->include_stylesheet('skin_elastic.css');
		} else {
			$this->include_stylesheet("skin_{$skin}.css");
		}

		$this->add_hook('login_after', [$this, 'login_after']);
		$this->add_hook('startup', [$this, 'startup']);
		$this->add_hook('authenticate', [$this, 'authenticate']);
		$this->add_hook('storage_init', [$this, 'storage_init']);
		$this->add_hook('message_headers_output', [$this, 'message_headers_output']);
		$this->add_hook('message_before_send', [$this, 'message_before_send']);
		$this->add_hook('message_sent', [$this, 'message_sent']);

		$this->register_action('plugin.yetiforce-importIcs', [$this, 'importIcs']);
		$this->register_action('plugin.yetiforce-addFilesToMail', [$this, 'addFilesToMail']);
		$this->register_action('plugin.yetiforce-getContentEmailTemplate', [$this, 'getContentEmailTemplate']);
		$this->register_action('plugin.yetiforce-loadMailAnalysis', [$this, 'loadMailAnalysis']);

		if ('mail' == $this->rc->task) {
			$this->include_stylesheet('../../../../../layouts/resources/icons/yfm.css');
			$this->include_stylesheet('../../../../../layouts/resources/icons/additionalIcons.css');
			$this->include_stylesheet('../../../../../libraries/@fortawesome/fontawesome-free/css/all.css');
			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			if ($this->loadCurrentUser()) {
				$this->rc->load_language(null, [
					'LBL_FILE_FROM_CRM' => \App\Language::translate('LBL_FILE_FROM_CRM', 'OSSMail', false, false),
					'LBL_MAIL_TEMPLATES' => \App\Language::translate('LBL_MAIL_TEMPLATES', 'OSSMail', false, false),
					'LBL_TEMPLATES' => \App\Language::translate('LBL_TEMPLATES', 'OSSMail', false, false),
					'BTN_ANALYSIS_DETAILS' => \App\Language::translate('BTN_ANALYSIS_DETAILS', 'OSSMail', false, false),
					'LBL_SIGNATURES' => \App\Language::translate('LBL_SIGNATURES', 'OSSMail'),
				]);

				if ('preview' === $this->rc->action || 'show' === $this->rc->action || '' == $this->rc->action) {
					$this->include_script('preview.js');
					$this->include_stylesheet('preview.css');

					$this->add_hook('template_object_messageattachments', [$this, 'appendIcsPreview']);
					$this->add_hook('message_load', [$this, 'message_load']);
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
					$this->add_hook('identity_select', [$this, 'identity_select']);
					$this->add_hook('render_page', [$this, 'loadSignature']);

					$this->add_hook('message_compose_body', [$this, 'message_compose_body']);
					$this->add_hook('message_compose', [$this, 'message_compose']);

					if ($id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC)) {
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
			}
			chdir($currentPath);
		} elseif ('settings' == $this->rc->task) {
			$this->add_hook('preferences_list', [$this, 'settingsDisplayPrefs']);
			$this->add_hook('preferences_save', [$this, 'settingsSavePrefs']);
		}
	}

	/**
	 * 'startup' hook handler.
	 *
	 * @param array $args Hook arguments
	 *
	 * @return array Hook arguments
	 */
	public function startup($args): array
	{
		if (empty($_GET['_autologin']) || !($row = $this->getAutoLogin())) {
			return $args;
		}
		if (!empty($_SESSION['user_id']) && !empty($row['user_id']) && $_SESSION['user_id'] != $row['user_id']) {
			$this->rc->logout_actions();
			$this->rc->kill_session();
			$this->rc->plugins->exec_hook('logout_after', [
				'user' => $_SESSION['username'],
				'host' => $_SESSION['storage_host'],
				'lang' => $this->rc->user->language,
			]);
		}
		if (empty($_SESSION['user_id']) && !empty($_GET['_autologin'])) {
			$args['action'] = 'login';
		}

		return $args;
	}

	/**
	 * 'authenticate' hook handler.
	 *
	 * @param array $args Hook arguments
	 *
	 * @return array Hook arguments
	 */
	public function authenticate($args): array
	{
		if (!empty($_GET['_autologin']) && ($row = $this->getAutoLogin())) {
			$db = $this->rc->get_dbh();
			$db->query('DELETE FROM `u_yf_mail_autologin` WHERE `cuid` = ?;', $row['cuid']);

			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			require_once 'include/main/WebUI.php';
			if (\App\Record::isExists($row['ruid'], 'MailAccount')) {
				$mailAccount = \App\Mail\Account::getInstanceById($row['ruid']);
				if ($mailAccount->isActive()) {
					$imap = $mailAccount->getServer()->getImapHost();
					$smtp = $mailAccount->getServer()->getSmtpHost();
					$this->rc->config->set('imap_host', [$imap => $imap]);
					$this->rc->config->set('smtp_host', [$smtp => $smtp]);
					$args['user'] = $mailAccount->getLogin();
					$args['pass'] = $mailAccount->getPassword();
					$args['host'] = $imap;
					$args['cookiecheck'] = false;
					$args['valid'] = true;

					$_SESSION['smtp_host'] = $smtp;
					$_SESSION['crm']['id'] = $args['cuid'];
					$this->add_hook('smtp_connect', [$this, 'smtp_connect']);
					if ($mailAccount->getServer()->isOAuth()) {
						$token = $mailAccount->getAccessToken();
						$args['pass'] = $oauthToken = "Bearer {$token}";
						$oauthData = [
							'token_type' => 'Bearer',
							'expires_in' => \App\Fields\DateTime::getDiff(date('Y-m-d H:i:s'), $mailAccount->getExpireTime(), 'seconds'),
							'scope' => implode(' ', $mailAccount->getOAuthProvider()->getScopesByAction('MailAccount')),
							'expires' => strtotime($mailAccount->getExpireTime()) - 10,
							'refresh_token' => $this->rc->encrypt($mailAccount->getRefreshToken()),
							'mailAccountId' => $mailAccount->getSource()->getId()
						];
						$this->rc->config->set('imap_auth_type', 'XOAUTH2');
						$this->rc->config->set('login_password_maxlen', \strlen($oauthToken));

						$_SESSION['oauth_token'] = $oauthData;
						$this->add_hook('managesieve_connect', [$this, 'managesieve_connect']);
						$this->add_hook('refresh', [$this, 'refresh']);
					}
				}
			}
			chdir($currentPath);
		}
		return $args;
	}

	// START OAuth

	/**
	 * Callback for 'storage_init' hook.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function storage_init($options)
	{
		if (isset($_SESSION['oauth_token']) && 'imap' === $options['driver']) {
			if ($this->check_token_validity($_SESSION['oauth_token'])) {
				$options['password'] = $this->rcmail->decrypt($_SESSION['password']);
			}
			$options['auth_type'] = 'XOAUTH2';
		}
		if (isset($options['fetch_headers'])) {
			$options['fetch_headers'] = trim($options['fetch_headers'] . ' RECEIVED'); // ??
		}

		return $options;
	}

	/**
	 * Check the given access token data if still valid.
	 *
	 * ... and attempt to refresh if possible.
	 *
	 * @param array $token
	 *
	 * @return bool
	 */
	protected function check_token_validity($token)
	{
		if ($token['expires'] < time() && !empty($token['refresh_token']) && !empty($token['mailAccountId'])) {
			return false !== $this->refresh_access_token($token);
		}
		return false;
	}

	/**
	 * Obtain a new access token using the refresh_token grant type.
	 *
	 * If successful, this will update the `oauth_token` entry in
	 * session data.
	 *
	 * @param array $token
	 *
	 * @return array Updated authorization data
	 */
	public function refresh_access_token(array $token)
	{
		$response = false;
		$mailAccoountId = (int) $token['mailAccountId'];
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		require_once 'include/main/WebUI.php';
		if (\App\Record::isExists($mailAccoountId, 'MailAccount')) {
			try {
				$mailAccount = \App\Mail\Account::getInstanceById($mailAccoountId);
				$token = $mailAccount->getAccessToken();
				$oauthToken = "Bearer {$token}";
				$_SESSION['password'] = $this->rc->encrypt($oauthToken);
				$token['expires'] = strtotime($mailAccount->getExpireTime()) - 10;
				$_SESSION['oauth_token'] = $token;
				$response = [
					'token' => $token,
					'authorization' => $oauthToken,
				];
			} catch (\Throwable $e) {
				$response = false;
			}
		}
		chdir($currentPath);

		return $response;
	}

	/**
	 * Callback for 'smtp_connect' hook.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function smtp_connect($options)
	{
		if (isset($_SESSION['smtp_host'])) {
			$options['smtp_host'] = $_SESSION['smtp_host'];
		}
		if (isset($_SESSION['oauth_token'])) {
			$this->check_token_validity($_SESSION['oauth_token']);
			// enforce XOAUTH2 authorization type
			$options['smtp_user'] = $_SESSION['username'] ?? '%u';
			$options['smtp_pass'] = isset($_SESSION['password']) ? $this->rc->decrypt($_SESSION['password']) : '%p';
			$options['smtp_auth_type'] = 'XOAUTH2';
		}

		return $options;
	}

	/**
	 * Callback for 'managesieve_connect' hook.
	 *
	 * @param array $options
	 *
	 * @return array
	 */
	public function managesieve_connect($options)
	{
		if (isset($_SESSION['oauth_token'])) {
			$this->check_token_validity($_SESSION['oauth_token']);
			// enforce XOAUTH2 authorization type
			$options['auth_type'] = 'XOAUTH2';
		}

		return $options;
	}

	/**
	 * Callback for 'refresh' hook.
	 *
	 * @param array $options
	 *
	 * @return void
	 */
	public function refresh($options)
	{
		if (isset($_SESSION['oauth_token'])) {
			$this->check_token_validity($_SESSION['oauth_token']);
		}
	}

	// END OAUTH

	/**
	 * login_after hook handler.
	 * Password savin.
	 *
	 * @param array $args
	 */
	public function login_after($args): array
	{
		$pass = rcube_utils::get_input_string('_pass', rcube_utils::INPUT_POST, true, $this->rc->config->get('password_charset', 'UTF-8'));
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
			$args['_composeKey'] = App\Purifier::purifyByType(rcube_utils::get_input_string('_composeKey', rcube_utils::INPUT_GET), 'Alnum');
		}
		if ($row = $this->getAutoLogin()) {
			$sql = 'UPDATE ' . $this->rc->db->table_name('users') . ' SET crm_ma_id = ? WHERE user_id = ?';
			\call_user_func_array([$this->rc->db, 'query'], array_merge([$sql], [$row['ruid'], $this->rc->get_user_id()]));
			$this->rc->db->affected_rows();

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
		$key = App\Purifier::purifyByType(rcube_utils::get_input_string('_autologinKey', rcube_utils::INPUT_GPC), 'Alnum');
		$db = $this->rc->get_dbh();
		$sqlResult = $db->query('SELECT * FROM u_yf_mail_autologin WHERE u_yf_mail_autologin.`key` = ?;', $key);
		$autologin = false;
		if ($row = $db->fetch_assoc($sqlResult)) {
			$autologin = $row;
			$autologin['params'] = json_decode($autologin['params'], true);
		}
		$this->autologin = $autologin;
		return $autologin;
	}

	/**
	 * Handler for message_load hook.
	 * Set environment variables in JS, needed for QuickCreateForm.
	 *
	 * @param array $args
	 */
	public function message_load(array $args): void
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

	/**
	 * Handle message_compose hook.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function message_compose(array $args): array
	{
		$db = $this->rc->get_dbh();
		if (empty($_GET['_composeKey'])) {
			return $args;
		}
		$composeKey = App\Purifier::purifyByType(rcube_utils::get_input_string('_composeKey', rcube_utils::INPUT_GET), 'Alnum');
		$result = $db->query('SELECT * FROM `u_yf_mail_compose_data` WHERE `key` = ?', $composeKey);
		$params = $db->fetch_assoc($result);
		$db->query('DELETE FROM `u_yf_mail_compose_data` WHERE `key` = ?;', $composeKey);
		if (!empty($params)) {
			$params = json_decode($params['data'], true);
			foreach ($params as $key => &$value) {
				$args['param'][$key] = $value;
			}
			if ((isset($params['crmmodule']) && 'Documents' == $params['crmmodule']) || (isset($params['filePath']) && $params['filePath'])) {
				foreach ($this->getAttachment($params['crmrecord'], $params['filePath']) as $attachment) {
					$args['attachments'][] = $attachment;
				}
			}
			if (!isset($params['mailId'])) {
				return $args;
			}
			$currentPath = getcwd();
			chdir($this->rc->config->get('root_directory'));
			$loadCurrentUser = $this->loadCurrentUser();

			$result = $db->query('SELECT content,reply_to_email,date,from_email,to_email,cc_email,subject FROM vtiger_ossmailview WHERE ossmailviewid = ?;', $params['mailId']);
			$row = $db->fetch_assoc($result);

			if ($loadCurrentUser) {
				self::$COMPOSE = &$_SESSION['compose_data_' . $args['id']];
				$content = $row['content'];
				foreach ($this->decodeCustomTag($content, $args) as $attachment) {
					$_SESSION['plugins']['filesystem_attachments'][$args['id']][$attachment['id']] = realpath($attachment['path']);
					self::$COMPOSE['attachments'][$attachment['id']] = $attachment;
				}
				$row['content'] = $content;
			}
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
			if (!empty($params['recordNumber']) && !empty($params['crmmodule']) && $loadCurrentUser) {
				$subjectNumber = \App\Mail\RecordFinder::getRecordNumberFromString($subject, $params['crmmodule']);
				$recordNumber = \App\Mail\RecordFinder::getRecordNumberFromString("[{$params['recordNumber']}]", $params['crmmodule']);
				if (false === $subject || (false !== $subject && $subjectNumber !== $recordNumber)) {
					$subject = "[{$params['recordNumber']}] $subject";
				}
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
			chdir($currentPath);
		}
		return $args;
	}

	/**
	 * Handle message_compose_body hook.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function message_compose_body(array $args): array
	{
		$bodyIsHtml = $args['html'];
		$id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
		$row = $_SESSION['compose_data_' . $id]['param']['mailData'] ?? [];
		if (!$row) {
			return $args;
		}
		$type = $_SESSION['compose_data_' . $id]['param']['type'] ?? '';
		$body = $row['content'];
		$date = $this->rc->format_date($row['date'], $this->rc->config->get('date_long'));
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
				if ($row['cc_email']) {
					$prefix .= $this->rc->gettext('cc') . ': ' . $row['cc_email'] . "\n";
				}
				if ($replyto != $from) {
					$prefix .= $this->rc->gettext('replyto') . ': ' . $replyto . "\n";
				}
				$prefix .= "\n";
				$line_length = $this->rc->config->get('line_length', 72);
				$txt = new rcube_html2text($body, false, true, $line_length);
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
					'<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>',
					$this->rc->gettext('subject'), rcube::Q($subject),
					$this->rc->gettext('date'), rcube::Q($date),
					$this->rc->gettext('from'), rcube::Q($from, 'replace'),
					$this->rc->gettext('to'), rcube::Q($to, 'replace'));

				if ($row['cc_email']) {
					$prefix .= sprintf('<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>', $this->rc->gettext('cc'), rcube::Q($row['cc_email'], 'replace'));
				}
				if ($replyto !== $from) {
					$prefix .= sprintf('<tr><th align="right" nowrap="nowrap" valign="baseline">%s: </th><td>%s</td></tr>', $this->rc->gettext('replyto'), rcube::Q($replyto, 'replace'));
				}
				$prefix .= '</tbody></table>';
			}
			$body = $prefix . $body;
		} else {
			$prefix = $this->rc->gettext([
				'name' => 'mailreplyintro',
				'vars' => [
					'date' => $date,
					'sender' => $from,
				],
			]);
			if (!$bodyIsHtml) {
				$line_length = $this->rc->config->get('line_length', 72);
				$txt = new rcube_html2text($body, false, true, $line_length);
				$body = $txt->get_text();
				$body = preg_replace('/\r?\n/', "\n", $body);
				$body = trim($body, "\n");
				$body = rcmail_action_mail_compose::wrap_and_quote($body, $line_length);
				$prefix .= "\n";
				$body = $prefix . $body . $suffix;
			} else {
				$prefix = '<p id="reply-intro">' . rcube::Q($prefix) . '</p>';
				$body = $prefix . '<blockquote>' . $body . '</blockquote>' . $suffix;
			}
		}
		$this->rc->output->set_env('compose_mode', $type);
		$args['body'] = $body;
		return $args;
	}

	/**
	 * Identity selection.
	 *
	 * @param array $args
	 */
	public function identity_select(array $args): array
	{
		$this->identitySelect = $args;
		return $args;
	}

	/**
	 * Loading signature.
	 *
	 * @param array $args
	 *
	 * @return void
	 */
	public function loadSignature(array $args): void
	{
		if ($this->rc->config->get('enable_variables_in_signature') && !empty($this->rc->output->get_env('signatures'))) {
			$signatures = [];
			foreach ($this->rc->output->get_env('signatures') as $identityId => $signature) {
				$signatures[$identityId]['text'] = $this->parseVariables($signature['text']);
				$signatures[$identityId]['html'] = $this->parseVariables($signature['html']);
			}
			$this->rc->output->set_env('signatures', $signatures);
		}
		$globalSignatures = $this->getGlobalSignature();
		$this->rc->output->set_env('yetiForceSignatures', $globalSignatures['all']);
		if (empty($globalSignatures['global'])) {
			return;
		}
		$signatures = [];
		if($this->rc->output->get_env('signatures')){
			foreach (($this->rc->output->get_env('signatures') ?? []) as $identityId => $signature) {
				$signatures[$identityId]['text'] = $globalSignatures['text'];
				$signatures[$identityId]['html'] = '--<br><div class="pre global">' . $globalSignatures['global'] . '</div>';
			}
		}else{
			foreach (($this->rc->output->get_env('identities') ?? []) as $identityId => $identity) {
				$signatures[$identityId]['text'] = $globalSignatures['text'];
				$signatures[$identityId]['html'] = '--<br><div class="pre global">' . $globalSignatures['global'] . '</div>';
			}
		}
		if (isset($this->identitySelect['message']) && !empty($this->identitySelect['message']->identities)) {
			foreach ($this->identitySelect['message']->identities as $identity) {
				$identityId = $identity['identity_id'];
				if (!isset($signatures[$identityId])) {
					$signatures[$identityId]['text'] = "--\n" . $globalSignatures['global'];
					$signatures[$identityId]['html'] = '--<br><div class="pre global">' . $globalSignatures['global'] . '</div>';
				}
			}
		}
		$this->rc->output->set_env('signatures', $signatures);
	}

	/**
	 * Get global signature.
	 *
	 * @return array
	 */
	public function getGlobalSignature(): array
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));

		$parser = App\TextParser::getInstanceById($this->currentUser->getId(), 'Users');
		$signatures = ['global' => '', 'all' => []];
		foreach (\App\Mail::getSignatures() as $value) {
			$result = $parser->setContent($value['body'])->parse()->getContent();
			if ($value['default']) {
				$signatures['global'] = $result;
			} else {
				$signatures['all'][$value['id']] = [
					'name' => $value['name'],
					'body' => '--<br><div class="pre global">' . $result . '</div>',
				];
			}
		}
		chdir($currentPath);
		return $signatures;
	}

	/**
	 * Add files to mail.
	 * Action: plugin.yetiforce-addFilesToMail.
	 *
	 * @return void
	 */
	public function addFilesToMail(): void
	{
		self::$COMPOSE_ID = App\Purifier::purifyByType(rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC), 'Alnum');
		self::$COMPOSE = null;
		self::$SESSION_KEY = 'compose_data_' . self::$COMPOSE_ID;
		if (self::$COMPOSE_ID && !empty($_SESSION[self::$SESSION_KEY])) {
			self::$COMPOSE = &$_SESSION[self::$SESSION_KEY];
		}
		if (!self::$COMPOSE) {
			exit('Invalid session var!');
		}
		$uploadid = App\Purifier::purifyByType(rcube_utils::get_input_string('_uploadid', rcube_utils::INPUT_GPC), 'Integer');
		$ids = App\Purifier::purifyByType(rcube_utils::get_input_value('ids', rcube_utils::INPUT_GPC), 'Integer');
		$index = 0;
		foreach ($this->getAttachment($ids, false) as $attachment) {
			++$index;
			[$usec, $sec] = explode(' ', microtime());
			$id = preg_replace('/[^0-9]/', '', $this->rc->user->ID . $sec . $usec) . $index;
			$attachment['id'] = $id;
			$attachment['group'] = self::$COMPOSE_ID;
			@chmod($attachment['path'], 0600);  // set correct permissions (#1488996)
			$_SESSION['plugins']['filesystem_attachments'][self::$COMPOSE_ID][$id] = realpath($attachment['path']);
			$this->rc->session->append(self::$SESSION_KEY . '.attachments', $id, $attachment);
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
	 * @return array
	 */
	public function getAttachment($ids, $files): array
	{
		$attachments = [];
		if (empty($ids) && empty($files)) {
			return $attachments;
		}
		if (\is_array($ids)) {
			$ids = implode(',', $ids);
		}
		$userid = $this->rc->user->ID;
		$index = 0;
		if ($ids) {
			foreach (App\Mail::getAttachmentsFromDocument($ids, false) as $filePath => $row) {
				[, $sec] = explode(' ', microtime());
				$path = $this->rc->config->get('temp_dir') . DIRECTORY_SEPARATOR . "yfcrm_{$sec}_{$userid}_{$row['attachmentsid']}_{$index}.tmp";
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
				$filePath = $this->rc->config->get('temp_dir') . DIRECTORY_SEPARATOR . "yfcrm_{$sec}_{$userid}_{$index}.tmp";
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
	 * Copy attachment_success functions from a file public_html/modules/OSSMail/roundcube/program/actions/mail/attachment_upload.php.
	 *
	 * @param array  $attachment
	 * @param string $uploadid
	 *
	 * @return void
	 */
	public function rcmail_attachment_success(array $attachment, $uploadid): void
	{
		$id = $attachment['id'];

		if (!empty(self::$COMPOSE['deleteicon']) && is_file(self::$COMPOSE['deleteicon'])) {
			$button = html::img([
				'src' => self::$COMPOSE['deleteicon'],
				'alt' => $this->rc->gettext('delete'),
			]);
		} elseif (!empty(self::$COMPOSE['textbuttons'])) {
			$button = rcube::Q($this->rc->gettext('delete'));
		} else {
			$button = '';
		}

		$link_content = sprintf(
			'<span class="attachment-name">%s</span><span class="attachment-size">(%s)</span>',
			rcube::Q($attachment['name']), rcmail_action_mail_attachment_upload::show_bytes($attachment['size'])
		);

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

		$content = !empty(self::$COMPOSE['icon_pos']) && 'left' == self::$COMPOSE['icon_pos'] ? ($delete_link . $content_link) : ($content_link . $delete_link);

		$this->rc->output->command('add2attachment_list', "rcmfile$id", [
			'html' => $content,
			'name' => $attachment['name'],
			'mimetype' => $attachment['mimetype'],
			'classname' => rcube_utils::file2class($attachment['mimetype'], $attachment['name']),
			'complete' => true,
		], $uploadid);
	}

	/**
	 * Parse variables.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	protected function parseVariables(string $text): string
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		if ($this->loadCurrentUser()) {
			$text = \App\TextParser::getInstance()->setContent($text)->parse()->getContent();
		}
		chdir($currentPath);
		return $text;
	}

	/**
	 * Load current user.
	 *
	 * @return bool
	 */
	protected function loadCurrentUser(): bool
	{
		if (isset($this->currentUser)) {
			return true;
		}
		if (empty($_SESSION['crm']['id'])) {
			return false;
		}
		require 'include/main/WebUI.php';
		$this->currentUser = \App\User::getUserModel($_SESSION['crm']['id']);
		App\User::setCurrentUserId($_SESSION['crm']['id']);
		\App\Language::setTemporaryLanguage($this->currentUser->getDetail('language'));
		return true;
	}

	/**
	 * `yetiforce.adressbutton`  handler.
	 *
	 * @param array $args
	 *
	 * @return string
	 */
	public function adressButton(array $args): string
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
	 *
	 * @return void
	 */
	public function getContentEmailTemplate(): void
	{
		$templateId = App\Purifier::purifyByType(rcube_utils::get_input_string('id', rcube_utils::INPUT_GPC), 'Integer');
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$mail = [];
		if ($this->loadCurrentUser() && \App\Privilege::isPermitted('EmailTemplates', 'DetailView', $templateId)) {
			$mail = \App\Mail::getTemplate($templateId);
			if ($recordId = rcube_utils::get_input_string('record_id', rcube_utils::INPUT_GPC)) {
				$textParser = \App\TextParser::getInstanceById(
					App\Purifier::purifyByType($recordId, 'Integer'),
					App\Purifier::purifyByType(rcube_utils::get_input_string('select_module', rcube_utils::INPUT_GPC), 'Alnum')
					);
				$mail['subject'] = $textParser->setContent($mail['subject'])->parse()->getContent();
				$mail['content'] = $textParser->setContent($mail['content'])->parse()->getContent();
			} else {
				$textParser = \App\TextParser::getInstance();
				$mail['subject'] = $textParser->setContent($mail['subject'])->parse()->getContent();
				$mail['content'] = $textParser->setContent($mail['content'])->parse()->getContent();
			}
		}
		echo App\Json::encode([
			'subject' => $mail['subject'] ?? null,
			'content' => $mail['content'] ?? null,
			'attachments' => $mail['attachments'] ?? null,
		]);
		chdir($currentPath);
		exit;
	}

	/**
	 * Append ical preview in attachments' area.
	 * `template_object_messageattachments` hook handler.
	 *
	 * @param array $args
	 *
	 * @return mixed
	 */
	public function appendIcsPreview(array $args): array
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		if ($this->loadCurrentUser()) {
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
		if ($this->loadCurrentUser() && \App\Privilege::isPermitted('Calendar', 'CreateView')) {
			$mailId = (int) rcube_utils::get_input_string('_mailId', rcube_utils::INPUT_GPC);
			$uid = App\Purifier::purifyByType(rcube_utils::get_input_string('_uid', rcube_utils::INPUT_GPC), 'Alnum');
			$mbox = App\Purifier::purifyByType(rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GPC), 'Alnum');
			$mime_id = App\Purifier::purifyByType(rcube_utils::get_input_string('_part', rcube_utils::INPUT_GPC), 'Text');
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
	 * Parse message.
	 *
	 * @param rcube_message_header $message
	 *
	 * @return array
	 */
	public function parseMessage(rcube_message_header $message): array
	{
		$header = '';
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
	 * Handler for 'message_headers_output' hook, where we add the additional
	 * headers to the output.
	 *
	 * @params array @p Hook parameters
	 *
	 * @param mixed $p
	 *
	 * @return array Modified hook parameters
	 */
	public function message_headers_output($p)
	{
		$this->messageHeaders = $p;
		return $p;
	}

	public function loadMailAnalysis(): void
	{
		$uid = (int) rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST);
		$this->rc->output->command('plugin.yetiforce.showMailAnalysis', $this->rc->storage->get_raw_body($uid));
	}


	/**
	 * Hook message_before_send.
	 *
	 * @param mixed $args
	 */
	public function message_before_send(array $args): array
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		if ($this->loadCurrentUser()) {
			$eventHandler = new \App\EventHandler();
			$eventHandler->setModuleName('OSSMail');
			$eventHandler->setParams([
				'composeData' => $_SESSION['compose_data_' . rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC)] ?? [],
				'mailData' => $args,
			]);
			$eventHandler->trigger('OSSMailBeforeSend');
		}
		chdir($currentPath);
		return $eventHandler->getParams()['mailData'];
	}

	/**
	 * Hook message_sent.
	 *
	 * @param mixed $args
	 */
	public function message_sent(array $args): array
	{
		$currentPath = getcwd();
		chdir($this->rc->config->get('root_directory'));
		$this->loadCurrentUser();

		$eventHandler = new \App\EventHandler();
		$eventHandler->setModuleName('OSSMail');
		$eventHandler->setParams([
			'composeData' => $_SESSION['compose_data_' . rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC)] ?? [],
			'mailData' => $args,
		]);
		$eventHandler->trigger('OSSMailAfterSend');

		chdir($currentPath);
		return $eventHandler->getParams()['mailData'];
	}

	/**
	 * Hook to inject plugin-specific user settings.
	 *
	 * @param array $args
	 */
	public function settingsDisplayPrefs(array $args): array
	{
		if ('general' !== $args['section']) {
			return $args;
		}
		$skin = $this->rc->config->get('yeti_skin');

		$showTo = new html_select(['name' => '_yeti_skin', 'id' => 'ff_yeti_skin']);
		$showTo->add('-', '');
		$showTo->add('elastic', 'elastic');
		$showTo->add('blue', 'blue');

		$args['blocks']['YetiForce'] = [
			'name' => 'YetiForce',
			'options' => ['yeti_skin' => [
				'title' => html::label('ff_yeti_skin', rcube::Q($this->gettext('skin'))),
				'content' => $showTo->show($skin),
			],
			],
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
		$args['prefs']['yeti_skin'] = rcube_utils::get_input_string('_yeti_skin', rcube_utils::INPUT_POST);
		return $args;
	}

	/**
	 * Decode custom yetiforce tag.
	 *
	 * @see \App\Utils\Completions::decodeCustomTag
	 *
	 * @param string $content
	 * @param array  $args
	 *
	 * @return array
	 */
	public function decodeCustomTag(string &$content, array $args): array
	{
		$attachments = [];
		if (false !== strpos($content, '<yetiforce')) {
			$userid = $this->rc->user->ID;
			$index = 0;
			[$usec, $sec] = explode(' ', microtime());
			$dId = preg_replace('/[^0-9]/', '', $userid . $sec . $usec);
			$content = preg_replace_callback('/<yetiforce\s(.*)><\/yetiforce>/', function (array $matches) use (&$attachments, &$index, $sec, $userid, $dId, $args) {
				$attributes = \App\TextUtils::getTagAttributes($matches[0]);
				$return = '';
				if (!empty($attributes['type'])) {
					switch ($attributes['type']) {
						case 'Documents':
							$recordModel = \Vtiger_Record_Model::getInstanceById($attributes['crm-id'], 'Documents');
							if (($filePath = $recordModel->getFilePath()) && file_exists($filePath)) {
								$tmpPath = $this->rc->config->get('temp_dir') . DIRECTORY_SEPARATOR . "yfcrm_{$sec}_{$userid}_{$attributes['attachment-id']}_{$index}.tmp";
								copy($filePath, $tmpPath);
								$attachment = [
									'group' => $args['id'],
									'id' => $dId . $index,
									'path' => $tmpPath,
									'name' => $recordModel->get('filename'),
									'size' => filesize($tmpPath),
									'mimetype' => rcube_mime::file_content_type($tmpPath, $recordModel->get('filename'), $recordModel->getFileDetails()['type']),
								];
								$url = sprintf('%s&_id=%s&_action=display-attachment&_file=rcmfile%s', $this->rc->comm_path, $args['id'], $attachment['id']);
								$return = '<img src="' . $url . '" />';
								$attachments[$index] = $attachment;
								++$index;
							}
							break;
						default:
							break;
					}
				}
				return $return;
			}, $content);
		}
		return $attachments;
	}
}
