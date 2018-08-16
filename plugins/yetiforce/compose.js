/* {[The file is published on the basis of MIT License]} */

window.rcmail && rcmail.addEventListener('init', function (evt) {

	var crm = window.crm = getCrmWindow();
	var crmPath = rcmail.env.site_URL + 'index.php?';

	rcmail.env.compose_commands.push('yetiforce.addFilesToMail');
	rcmail.env.compose_commands.push('yetiforce.addFilesFromCRM');

	// Document selection
	rcmail.register_command('yetiforce.addFilesToMail', function (data) {
		var ts = new Date().getTime(),
			frame_name = 'rcmupload' + ts,
			frame = rcmail.async_upload_form_frame(frame_name);
		data._uploadid = ts;
		jQuery.ajax({
			url: "?_task=mail&_action=plugin.yetiforce.addFilesToMail&_id=" + rcmail.env.compose_id,
			type: "POST",
			data: data,
			success: function (data) {
				var doc = frame[0];
				var body = $(doc);
				body.html(data);
			}
		});
	}, true);

	// Add a document to an email crm
	rcmail.register_command('yetiforce.addFilesFromCRM', function (data) {
		if (crm != false) {
			window.crm.app.showRecordsList({
				module: 'Documents',
				src_module: 'Documents',
				multi_select: true,
				additionalInformations: true
			}, (modal, instance) => {
				instance.setSelectEvent((responseData) => {
					rcmail.command('yetiforce.addFilesToMail', {
						ids: Object.keys(responseData),
						_uploadid: new Date().getTime()
					});
				});
			});
		}
	}, true);
	// Selection of email with popup
	$('#composeheaders #yt_adress_buttons .button').click(function () {
		var mailField = $(this).attr('data-input');
		var module = $(this).attr('data-module');
		window.crm.app.showRecordsList({
			module: module,
			src_module: 'OSSMail',
			multi_select: true,
			additionalInformations: true
		}, (modal, instance) => {
			instance.setSelectEvent((responseData, e) => {
				let emails = [];
				if (typeof e.target !== 'undefined' && $(e.target).data('type') === 'email') {
					emails.push($(e.target).text());
				} else {
					$.each(responseData, function (id, fields) {
						$.each(fields, function (key, row) {
							if (row.type === 'email') {
								emails.push(row.value);
								return false;
							}
						});
					});
				}
				if (emails.length === 0) {
					window.crm.Vtiger_Helper_Js.showPnotify({
						text: window.crm.app.vtranslate('NoFindEmailInRecord'),
						animation: 'show'
					});
				} else {
					let value = $('#' + mailField).val();
					if (value != '' && value.charAt(value.length - 1) != ',') {
						value = value + ',';
					}
					$('#' + mailField).val(value + emails.join(','));
				}
			});
		});
	});
	//Loading list of modules with templates mail
	if (rcmail.env.isPermittedMailTemplates) {
		jQuery.ajax({
			type: 'Get',
			url: "?_task=mail&_action=plugin.yetiforce.getEmailTemplates&_id=" + rcmail.env.compose_id,
			async: false,
			success: function (data) {
				var modules = [];
				var tmp = [];
				data = JSON.parse(data);
				$.each(data, function (index, value) {
					jQuery('#vtmodulemenulink').removeClass('disabled');
					jQuery('#tplmenulink').removeClass('disabled');
					tmp.push({name: value.moduleName, label: value.moduleName});
					jQuery('#tplmenu #texttplsmenu').append('<li class="' + value.moduleName + '"><a href="#" data-module="' + value.module + '" data-tplid="' + value.id + '" class="active">' + value.name + '</a></li>');
				});

				$.each(tmp, function (index, value) {
					if (jQuery.inArray(value.name, modules) == -1) {
						jQuery('#vtmodulemenu .toolbarmenu').append('<li class="' + value.name + '"><a href="#" data-module="' + value.name + '" class="active">' + value.label + '</a></li>');
						modules.push(value.name);
					}
				});

			}
		});
	}
	// Limit the list of templates
	jQuery('#vtmodulemenu li a').on('click', function () {
		var selectModule = jQuery(this).data('module');
		if (selectModule == undefined) {
			jQuery('#tplmenu li').show();
		} else {
			jQuery('#tplmenu li.' + selectModule).show();
			jQuery('#tplmenu li').not("." + selectModule).hide();
		}
	});

	if (rcmail.env.crmModule != undefined) {
		jQuery('#vtmodulemenu li.' + rcmail.env.crmModule + ' a').trigger("click");
	}

	// Loading a template mail
	jQuery('#tplmenu  li a').on('click', function () {
		var id = jQuery(this).data('tplid');
		var recordId = rcmail.env.crmRecord,
			module = rcmail.env.crmModule,
			view = rcmail.env.crmView;
		if (view == 'List') {
			var chElement = jQuery(crm.document).find('.listViewEntriesCheckBox')[0];
			recordId = jQuery(chElement).val();
		}
		jQuery.ajax({
			type: 'Get',
			url: "?_task=mail&_action=plugin.yetiforce.getConntentEmailTemplate&_id=" + rcmail.env.compose_id,
			data: {
				id: id,
				record_id: recordId,
				select_module: module
			},
			success: function (data) {
				data = JSON.parse(data);
				var oldSubject = jQuery('[name="_subject"]').val();
				var html = jQuery("<div/>").html(data.content).html();
				jQuery('[name="_subject"]').val(oldSubject + ' ' + data.subject);
				if (window.tinyMCE && (ed = tinyMCE.get(rcmail.env.composebody))) {
					var oldBody = tinyMCE.activeEditor.getContent();
					tinymce.activeEditor.setContent(html + oldBody);
				} else {
					var oldBody = jQuery('#composebody').val();
					jQuery('#composebody').val(html + oldBody);
				}
				if (typeof data.attachments !== 'undefined' && data.attachments !== null) {
					rcmail.command('yetiforce.addFilesToMail', data.attachments);
				}
			}
		});
	});
});

function getCrmWindow() {
	if (opener !== null) {
		return opener.parent;
	} else if (typeof parent.app == "object") {
		return parent;
	}
	return false;
}
