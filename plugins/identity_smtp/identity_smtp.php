<?php
/**
 * Per identity smtp settings.
 *
 * Description
 *
 * @version 0.1
 *
 * @author elm@skweez.net, ritze@skweez.net, mks@skweez.net
 *
 * @url skweez.net
 *
 * MIT License
 */
class identity_smtp extends rcube_plugin
{
	public $task = 'mail|settings';
	private $from_identity;

	public function init()
	{
		$this->include_script('identity_smtp.js');
		$this->add_texts('localization/', true);

		$this->add_hook('message_before_send', [
			$this,
			'messageBeforeSend'
		]);
		$this->add_hook('smtp_connect', [
			$this,
			'smtpWillConnect'
		]);
		$this->add_hook('identity_form', [
			$this,
			'identityFormWillBeDisplayed'
		]);
		$this->add_hook('identity_create', [
			$this,
			'identityWasCreated'
		]);
		$this->add_hook('identity_update', [
			$this,
			'identityWasUpdated'
		]);
		$this->add_hook('identity_delete', [
			$this,
			'identityWasDeleted'
		]);
	}

	public function smtpLog($message)
	{
		rcube::write_log('identity_smtp_plugin', $message);
	}

	public function saveSmtpSettings($args)
	{
		$identities = rcmail::get_instance()->config->get('identity_smtp');
		$id = (int) $args['id'];

		if (!isset($identities)) {
			$identities = [];
		}

		$smtp_standard = rcube_utils::get_input_value('_smtp_standard', rcube_utils::INPUT_POST);

		$password = rcube_utils::get_input_value('_smtp_pass', rcube_utils::INPUT_POST, true);
		$password2 = rcube_utils::get_input_value('_smtp_pass2', rcube_utils::INPUT_POST, true);

		if ($password != $password2) {
			$args['abort'] = true;
			$args['result'] = false;
			$args['message'] = $this->gettext('smtp_passwords_mismatch');
			return $args;
		}

		if ($password != $identities[$id]['smtp_pass']) {
			$password = rcmail::get_instance()->encrypt($password);
		}

		$smtpSettingsRecord = [
			'smtp_standard' => isset($smtp_standard),
			'smtp_host' => rcube_utils::get_input_value('_smtp_host', rcube_utils::INPUT_POST),
			'smtp_user' => rcube_utils::get_input_value('_smtp_user', rcube_utils::INPUT_POST),
			'smtp_pass' => $password
		];

		unset($identities[$id]);
		$identities += [
			$id => $smtpSettingsRecord
		];
		rcmail::get_instance()->user->save_prefs([
			'identity_smtp' => $identities
		]);

		return $args;
	}

	public function loadSmtpSettings($args)
	{
		$smtpSettings = rcmail::get_instance()->config->get('identity_smtp');
		$id = (int) $args['identity_id'];
		$smtpSettingsRecord = [
			'smtp_standard' => $smtpSettings[$id]['smtp_standard'] ?? '',
			'smtp_host' => $smtpSettings[$id]['smtp_host'] ?? '',
			'smtp_user' => $smtpSettings[$id]['smtp_user'] ?? '',
			'smtp_pass' => $smtpSettings[$id]['smtp_pass'] ?? '',
			'smtp_pass2' => $smtpSettings[$id]['smtp_pass'] ?? '',
		];

		if (null === $smtpSettingsRecord['smtp_standard']) {
			$smtpSettingsRecord['smtp_standard'] = true;
		}

		return $smtpSettingsRecord;
	}

	public function identityFormWillBeDisplayed($args)
	{
		$form = $args['form'];
		$record = $args['record'] ?? [];

		// Load the stored smtp settings
		$smtpSettingsRecord = $this->loadSmtpSettings($record);

		if (!isset($record['identity_id'])) {
			// FIX ME
			$smtpSettingsForm = [
				'smtpSettings' => [
					'name' => $this->gettext('smtp_settings_header'),
					'content' => [
						'text' => [
							'label' => $this->gettext('smtp_settings_not_available'),
							'value' => ' '
						]
					]
				]
			];
		} else {
			$smtpSettingsForm = [
				'smtpSettings' => [
					'name' => $this->gettext('smtp_settings_header'),
					'content' => [
						'smtp_standard' => [
							'type' => 'checkbox',
							'label' => $this->gettext('use_default_smtp_server'),
							'onclick' => 'identity_smtp_toggle_standard_server()'
						],
						'smtp_host' => [
							'type' => 'text',
							'label' => $this->gettext('smtp_host'),
							'class' => 'identity_smtp_form',
							'size' => 40
						],
						'smtp_user' => [
							'type' => 'text',
							'label' => $this->gettext('smtp_user'),
							'class' => 'identity_smtp_form',
							'size' => 40
						],
						'smtp_pass' => [
							'type' => 'password',
							'label' => $this->gettext('smtp_pass'),
							'class' => 'identity_smtp_form',
							'size' => 40
						],
						'smtp_pass2' => [
							'type' => 'password',
							'label' => $this->gettext('smtp_pass2'),
							'class' => 'identity_smtp_form',
							'placeholder' => 'test',
							'size' => 40
						]
					]
				]
			];
			if ($smtpSettingsRecord['smtp_standard'] || null === $smtpSettingsRecord['smtp_standard']) {
				foreach ($smtpSettingsForm['smtpSettings']['content'] as &$input) {
					if ('checkbox' != $input['type']) {
						$input['disabled'] = 'disabled';
					}
				}
			}
		}
		$form += $smtpSettingsForm;
		$record += $smtpSettingsRecord;

		$OUTPUT = [
			'form' => $form,
			'record' => $record
		];
		return $OUTPUT;
	}

	// This function is called when a new identity is created. We want to use the default smtp server here
	public function identityWasCreated($args)
	{
		return $this->saveSmtpSettings($args);
	}

	// This function is called when the users saves a changed identity. It is responsible for saving the smtp settings
	public function identityWasUpdated($args)
	{
		return $this->saveSmtpSettings($args);
	}

	public function identityWasDeleted($args)
	{
		$smtpSettings = rcmail::get_instance()->config->get('identity_smtp');
		$id = $args['id'];
		unset($smtpSettings[$id]);
		rcmail::get_instance()->user->save_prefs([
			'identity_smtp' => $smtpSettings
		]);

		// Return false to not abort the deletion of the identity
		return false;
	}

	public function messageBeforeSend($args)
	{
		$identities = rcmail::get_instance()->user->list_identities();
		foreach ($identities as $idx => $ident) {
			if ($identities[$idx]['email'] == $args['from']) {
				$this->from_identity = $identities[$idx]['identity_id'];
			}
		}
		return $args;
	}

	// This function is called when an email is sent and it should pull the correct smtp settings for the used identity and insert them
	public function smtpWillConnect($args)
	{
		$smtpSettings = $this->loadSmtpSettings([
			'identity_id' => $this->from_identity
		]);
		if (!$smtpSettings['smtp_standard'] && null !== $smtpSettings['smtp_standard']) {
			$args['smtp_host'] = $smtpSettings['smtp_host'];
			$args['smtp_user'] = $smtpSettings['smtp_user'];
			$args['smtp_pass'] = rcmail::get_instance()->decrypt($smtpSettings['smtp_pass']);
		}
		return $args;
	}
}
