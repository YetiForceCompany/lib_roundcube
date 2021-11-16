'use strict';
/* {[The file is published on the basis of MIT License]} */
if (window.rcmail) {
	rcmail.addEventListener('init', function () {
		rcmail.crm = rcmail.getCrmWindow();
		if (rcmail.crm != false) {
			rcmail.env.compose_commands.push('yetiforce.addFilesFromCRM');
			rcmail.env.compose_commands.push('yetiforce.selectTemplate');
			rcmail.env.compose_commands.push('yetiforce.selectAdress');
			rcmail.register_command(
				'yetiforce.addFilesFromCRM',
				function () {
					rcmail.addFilesFromCRM();
				},
				true
			);
			rcmail.register_command(
				'yetiforce.selectTemplate',
				function () {
					rcmail.selectTemplate();
				},
				true
			);
			rcmail.register_command(
				'yetiforce.selectAdress',
				function (module, part) {
					rcmail.selectAdress(module, part);
				},
				true
			);
		}
	});
}
//Document selection
rcube_webmail.prototype.addFilesFromCRM = function () {
	rcmail.crm.app.showRecordsList(
		{
			module: 'Documents',
			src_module: 'Documents',
			multi_select: true,
			additionalInformations: true,
			search_params: [[['filelocationtype', 'e', 'I']]]
		},
		(modal, instance) => {
			instance.setSelectEvent((responseData) => {
				rcmail.addFilesToMail({
					ids: Object.keys(responseData)
				});
			});
		}
	);
};
//Add files to mail
rcube_webmail.prototype.addFilesToMail = function (data) {
	data._id = rcmail.env.compose_id;
	data._uploadid = new Date().getTime();
	this.http_post('plugin.yetiforce-addFilesToMail', data, this.set_busy(true, 'loading'));
};
// Select template
rcube_webmail.prototype.selectTemplate = function () {
	rcmail.crm.app.showRecordsList(
		{
			module: 'EmailTemplates',
			src_module: 'EmailTemplates',
			search_params: '[[["email_template_type","e","PLL_MAIL"]]]'
		},
		(modal, instance) => {
			instance.setSelectEvent((responseData) => {
				var recordId = rcmail.env.yf_crmRecord,
					module = rcmail.env.yf_crmModule,
					view = rcmail.env.yf_crmView;
				if (view == 'List') {
					var chElement = jQuery(crm.document).find('.listViewEntriesCheckBox')[0];
					recordId = jQuery(chElement).val();
				}
				jQuery.ajax({
					type: 'Get',
					url: '?_task=mail&_action=plugin.yetiforce-getContentEmailTemplate&_id=' + rcmail.env.compose_id,
					data: {
						id: responseData.id,
						record_id: recordId,
						select_module: module
					},
					dataType: 'json',
					success: function (data) {
						let oldSubject = jQuery('[name="_subject"]').val(),
							html = jQuery('<div/>').html(data.content).html(),
							ed = '';
						jQuery('[name="_subject"]').val(oldSubject + ' ' + data.subject);
						if (window.tinyMCE && (ed = tinyMCE.get(rcmail.env.composebody))) {
							let oldBody = tinyMCE.activeEditor.getContent();
							tinymce.activeEditor.setContent(html + oldBody);
						} else {
							let oldBody = jQuery('#composebody').val();
							jQuery('#composebody').val(html + oldBody);
						}
						if (typeof data.attachments !== 'undefined' && data.attachments !== null) {
							rcmail.addFilesToMail(data.attachments);
						}
					}
				});
			});
		}
	);
};
rcube_webmail.prototype.selectAdress = function (module, part) {
	rcmail.crm.app.showRecordsList(
		{
			module: module,
			src_module: 'OSSMail',
			multi_select: true,
			additionalInformations: false
		},
		(modal, instance) => {
			instance.setSelectEvent((responseData, e) => {
				rcmail.getEmailAddresses(responseData, e, module).done((emails) => {
					if (emails.length) {
						let paetElement = $('#' + part);
						let value = paetElement.val();
						if (value != '' && value.charAt(value.length - 1) != ',') {
							value = value + ',';
						}
						paetElement.val(value + emails.join(','));
						paetElement.change();
					} else {
						rcmail.crm.app.showNotify({
							text: rcmail.crm.app.vtranslate('NoFindEmailInRecord'),
							animation: 'show'
						});
					}
				});
			});
		}
	);
};
rcube_webmail.prototype.getEmailAddresses = function (responseData, e, module) {
	let aDeferred = $.Deferred(),
		emails = [],
		label = '',
		email = '';
	if (
		typeof e.target !== 'undefined' &&
		($(e.target).data('type') === 'email' || $(e.target).data('type') === 'multiEmail')
	) {
		emails.push($(e.target).text());
		aDeferred.resolve(emails);
	} else {
		let i = 0;
		for (let id in responseData) {
			rcmail.crm.app
				.getRecordDetails({
					record: id,
					module: module,
					fieldType: ['email', 'multiEmail']
				})
				.done((data) => {
					i++;
					label = email = rcmail.getFirstEmailAddress(data.result.data);
					if (responseData[id]) {
						label = responseData[id];
					}
					emails.push(label + '<' + email + '>');
					if (i === Object.keys(responseData).length) {
						//last iteration
						aDeferred.resolve(emails);
					}
				});
		}
	}
	return aDeferred.promise();
};
rcube_webmail.prototype.getFirstEmailAddress = function (data) {
	let emails = [];
	for (let key in data) {
		if (data[key]) {
			if (rcmail.crm.app.isJsonString(data[key])) {
				let multiEmail = JSON.parse(data[key]);
				for (let i in multiEmail) {
					emails.push(multiEmail[i].e);
				}
				break;
			} else {
				emails.push(data[key]);
				break;
			}
		}
	}
	return emails;
};
rcube_webmail.prototype.getCrmWindow = function () {
	if (opener !== null && typeof opener.parent.CONFIG == 'object') {
		return opener.parent;
	} else if (typeof parent.CONFIG == 'object') {
		return parent;
	} else if (typeof parent.opener.CONFIG == 'object') {
		return parent.opener;
	} else if (typeof parent.parent.CONFIG == 'object') {
		return parent.parent;
	} else if (typeof opener.crm.CONFIG == 'object') {
		return opener.crm;
	}
	return false;
};
