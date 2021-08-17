'use strict';
/* {[The file is published on the basis of MIT License]} */
if (window.rcmail) {
	rcmail.addEventListener('init', function () {
		rcmail.crm = rcmail.getCrmWindow();
		if (rcmail.crm === false) {
			return;
		}
		if (rcmail.env.uid) {
			rcmail.loadActionBar();
		}
		rcmail.env.message_commands.push('yetiforce.importICS');
		rcmail.register_command(
			'yetiforce.importICS',
			function (props, type) {
				rcmail.importICS(props, type);
			},
			true
		);
		rcmail.register_command(
			'plugin.yetiforce.addSenderToList',
			function (props) {
				rcmail.addSenderToList(props);
			},
			rcmail.env.uid
		);
		rcmail.register_command(
			'plugin.yetiforce.loadMailAnalysis',
			function (props) {
				rcmail.loadMailAnalysis(props);
			},
			rcmail.env.uid
		);
		rcmail.addEventListener('plugin.yetiforce.showMailAnalysis', function (content) {
			rcmail.showMailAnalysis(content);
		});
		if (rcmail.message_list) {
			rcmail.message_list.addEventListener('select', function (list) {
				rcmail.enable_command('plugin.yetiforce.addSenderToList', list.get_selection(false).length > 0);
				rcmail.enable_command('plugin.yetiforce.loadMailAnalysis', list.get_selection(false).length > 0);
			});
			rcmail.addEventListener('listupdate', function () {
				let btns = $('#toolbar-menu .js-white-list-btn');
				if (rcmail.env.mailbox === rcmail.env.junk_mailbox) {
					btns.hide();
				} else {
					btns.show();
				}
			});
		}
		if (rcmail.env.layout == 'widescreen') {
			if (rcmail.gui_objects.messagelist) {
				rcmail.addEventListener('insertrow', function (evt) {
					if (typeof rcmail.env.yf_rblList !== 'undefined' && typeof rcmail.env.yf_senderList !== 'undefined') {
						if (typeof rcmail.env.yf_rblList[evt.uid] !== 'undefined') {
							evt.row.obj.style.backgroundColor = rcmail.env.yf_rblList[evt.uid];
						} else {
							evt.row.obj.style.backgroundColor = '#eaeaea';
						}
						if (typeof rcmail.env.yf_senderList[evt.uid] !== 'undefined') {
							$('.fromto', evt.row.obj).prepend(
								$('<span class="sender-alert-icon"/>').html(rcmail.env.yf_senderList[evt.uid])
							);
						}
					}
				});
			}
		}
		if (rcmail.env.action == 'preview') {
			$('#moreAlertBtn').on('click', function () {
				let alert = $('#moreAlert');
				if (alert.hasClass('d-none')) {
					alert.removeClass('d-none');
				} else {
					alert.addClass('d-none');
				}
			});
		}
	});
}
// Add sender to list action
rcube_webmail.prototype.addSenderToList = function (props) {
	this.http_post(
		'plugin.yetiforce-addSenderToList',
		this.selection_post_data({
			_props: props
		}),
		this.set_busy(true, 'loading')
	);
};
rcube_webmail.prototype.addSenderToListMove = function (mbox) {
	this.move_messages(mbox);
};
// import ICS file action
rcube_webmail.prototype.importICS = function (part, type) {
	this.http_post(
		'plugin.yetiforce-importIcs',
		{
			_mbox: rcmail.env.mailbox,
			_uid: rcmail.env.uid,
			_part: part,
			_type: type,
			_mailId: this.mailId
		},
		this.set_busy(true, 'loading')
	);
};
rcube_webmail.prototype.loadActionBar = function () {
	this.crmContent = $('#ytActionBarContent');
	rcmail.crm.AppConnector.request({
		module: 'OSSMail',
		view: 'MailActionBar',
		uid: rcmail.env.uid,
		folder: rcmail.env.mailbox,
		rcId: rcmail.env.user_id
	}).done(function (response) {
		rcmail.crmContent.find('.ytHeader').html(response);
		$('#messagecontent').css('top', rcmail.crmContent.outerHeight() + $('#messageheader').outerHeight() + 'px');
		rcmail.registerEvents();
	});
};
rcube_webmail.prototype.registerEvents = function () {
	this.mailId = this.crmContent.find('#mailActionBarID').val();
	this.registerAddRecord();
	this.registerAddReletedRecord();
	this.registerSelectRecord();
	this.registerRemoveRecord();
	this.registerImportMail();
	rcmail.crm.app.registerPopover(this.crmContent.closest('body'));
	rcmail.crm.app.registerIframeAndMoreContent(this.crmContent.closest('body'));
	var block = this.crmContent.find('.ytHeader .js-data');
	this.crmContent.find('.hideBtn').click(function () {
		var button = $(this);
		var icon = button.find('span');
		if (button.data('type') == '0') {
			button.data('type', '1');
			icon.removeClass('fa-chevron-circle-up').addClass('fa-chevron-circle-down');
		} else {
			button.data('type', '0');
			icon.removeClass('fa-chevron-circle-down').addClass('fa-chevron-circle-up');
		}
		block.toggle();
		$(window).trigger('resize');
	});
};
rcube_webmail.prototype.registerImportMail = function () {
	let clicked = false;
	let importButton = rcmail.crmContent.find('.importMail');
	importButton.click(function (e) {
		if (clicked) return false;
		clicked = true;
		importButton.addClass('d-none');
		rcmail.crm.app.showNotify({
			text: rcmail.crm.app.vtranslate('StartedDownloadingEmail'),
			type: 'info'
		});
		rcmail.crm.AppConnector.request({
			module: 'OSSMail',
			action: 'ImportMail',
			uid: rcmail.env.uid,
			folder: rcmail.env.mailbox,
			rcId: rcmail.env.user_id
		})
			.done(function () {
				rcmail.loadActionBar();
				rcmail.crm.app.showNotify({
					text: rcmail.crm.app.vtranslate('AddFindEmailInRecord'),
					type: 'success'
				});
			})
			.fail(function () {
				clicked = false;
			});
	});
};
rcube_webmail.prototype.registerRemoveRecord = function () {
	rcmail.crmContent.find('button.removeRecord').click(function (e) {
		rcmail.removeRecord($(e.currentTarget).closest('.rowRelatedRecord').data('id'));
	});
};
rcube_webmail.prototype.registerSelectRecord = function () {
	rcmail.crmContent.find('button.selectRecord').click(function (e) {
		let relationSelect = rcmail.crmContent.find('#addRelationSelect').val();
		let getCacheModule = rcmail.crm.app.moduleCacheGet('selectedModuleName');
		if (getCacheModule === 'undefined' || relationSelect !== getCacheModule) {
			rcmail.crm.app.moduleCacheSet('selectedModuleName', relationSelect);
		}
		let relParams = {
			mailId: rcmail.mailId
		};
		if ($(this).data('type') == 0) {
			var module = $(this).closest('.js-head-container').find('.module').val();
			if (module === null) {
				return;
			}
		} else {
			var module = $(this).data('module');
			relParams.crmid = $(this).closest('.rowRelatedRecord').data('id');
			relParams.mod = $(this).closest('.rowRelatedRecord').data('module');
			relParams.newModule = module;
		}
		rcmail.showPopup(
			{
				module: module,
				src_module: 'OSSMailView',
				src_record: rcmail.mailId
			},
			relParams
		);
	});
};
rcube_webmail.prototype.registerAddReletedRecord = function () {
	rcmail.crmContent.find('button.addRelatedRecord').click(function (e) {
		let targetElement = $(e.currentTarget);
		let row = targetElement.closest('.rowRelatedRecord');
		rcmail.showQuickCreateForm(targetElement.data('module'), row.data('id'), {
			sourceModule: row.data('module')
		});
	});
};
rcube_webmail.prototype.registerAddRecord = function () {
	let getCacheModule = rcmail.crm.app.moduleCacheGet('selectedModuleName');
	if (getCacheModule) {
		rcmail.crmContent.find('#addRelationSelect').val(getCacheModule);
	}
	rcmail.crmContent.find('button.addRecord').click(function (e) {
		let relationSelect = rcmail.crmContent.find('#addRelationSelect').val();
		if (getCacheModule === 'undefined' || relationSelect !== getCacheModule) {
			rcmail.crm.app.moduleCacheSet('selectedModuleName', relationSelect);
		}
		let col = $(e.currentTarget).closest('.js-head-container');
		let selectValue = col.find('.module').val();
		if (selectValue !== null) {
			let relatedRecords = [];
			rcmail.crmContent
				.find('.js-data')
				.find('.rowRelatedRecord')
				.each((i, record) => {
					let data = $(record).data();
					relatedRecords.push({
						module: data.module,
						id: data.id
					});
				});
			rcmail.showQuickCreateForm(selectValue, rcmail.mailId, { relatedRecords: relatedRecords });
		}
	});
};
rcube_webmail.prototype.removeRecord = function (crmid) {
	rcmail.crm.AppConnector.request({
		async: false,
		dataType: 'json',
		data: {
			module: 'OSSMail',
			action: 'ExecuteActions',
			mode: 'removeRelated',
			params: {
				mailId: rcmail.mailId,
				crmid: crmid
			}
		}
	}).done(function (data) {
		let response = data['result'];
		let notifyParams = {
			text: response['data'],
			animation: 'show'
		};
		if (response['success']) {
			notifyParams = {
				text: response['data'],
				type: 'info',
				animation: 'show'
			};
		}
		rcmail.crm.app.showNotify(notifyParams);
		rcmail.loadActionBar();
	});
};
rcube_webmail.prototype.showPopup = function (params, actionsParams) {
	actionsParams['newModule'] = params['module'];
	rcmail.crm.app.showRecordsList(params, (modal, instance) => {
		instance.setSelectEvent((responseData, e) => {
			actionsParams['newCrmId'] = responseData.id;
			rcmail.crm.AppConnector.request({
				async: false,
				dataType: 'json',
				data: {
					module: 'OSSMail',
					action: 'ExecuteActions',
					mode: 'addRelated',
					params: actionsParams
				}
			}).done(function (data) {
				let response = data['result'];
				if (response['success']) {
					var notifyParams = {
						text: response['data'],
						type: 'info',
						animation: 'show'
					};
				} else {
					var notifyParams = {
						text: response['data'],
						animation: 'show'
					};
				}
				rcmail.crm.app.showNotify(notifyParams);
				rcmail.loadActionBar();
			});
		});
	});
};
rcube_webmail.prototype.showQuickCreateForm = function (moduleName, record, params = {}) {
	let relatedParams = {},
		sourceModule = 'OSSMailView';
	if (params['sourceModule']) {
		sourceModule = params['sourceModule'];
	}
	const postShown = function (data) {
		$('<input type="hidden" name="sourceModule" value="' + sourceModule + '" />').appendTo(data);
		$('<input type="hidden" name="sourceRecord" value="' + record + '" />').appendTo(data);
		$('<input type="hidden" name="relationOperation" value="true" />').appendTo(data);
	};
	const ids = {
		link: 'modulesLevel0',
		process: 'modulesLevel1',
		subprocess: 'modulesLevel2',
		subprocess_sl: 'modulesLevel3',
		linkextend: 'modulesLevel4'
	};
	for (var i in ids) {
		var element = rcmail.crmContent.find('#' + ids[i]);
		var value = element.length ? JSON.parse(element.val()) : [];
		if ($.inArray(sourceModule, value) >= 0) {
			relatedParams[i] = record;
		}
	}
	const fillNameFields = (first) => {
		const nameData = rcmail.env.yf_fromName.split(' ');
		const firstName = nameData.shift();
		const lastName = nameData.join(' ');
		return first ? firstName : lastName;
	};
	let autoCompleteMapRaw = rcmail.crmContent.find('.js-mailAutoCompleteFields').val();
	let autoCompleteMap = autoCompleteMapRaw ? JSON.parse(autoCompleteMapRaw) : [];
	if (autoCompleteMap && autoCompleteMap[moduleName]) {
		let map = autoCompleteMap[moduleName];
		for (let name in map) {
			if (map.hasOwnProperty(name) && map[name]) {
				switch (map[name]) {
					case 'fromNameFirstPart':
						relatedParams[name] = fillNameFields(true);
						break;
					case 'fromNameSecondPart':
						relatedParams[name] = fillNameFields(false);
						break;
					case 'fromName':
						relatedParams[name] = rcmail.env.yf_fromName;
						break;
					case 'subject':
						relatedParams[name] = rcmail.env.yf_subject;
						break;
					case 'email':
						relatedParams[name] = rcmail.env.yf_fromMail;
						break;
				}
			}
		}
	}
	relatedParams['email'] = rcmail.env.yf_fromMail;
	relatedParams['email1'] = rcmail.env.yf_fromMail;
	let messageBody = $('#messagebody .rcmBody').clone();
	messageBody.find('.image-attachment').remove();
	relatedParams['description'] = messageBody.html();
	//relatedParams['related_to'] = record;
	if (params.relatedRecords !== undefined) {
		relatedParams['relatedRecords'] = params.relatedRecords;
	}
	relatedParams['sourceModule'] = sourceModule;
	relatedParams['sourceRecord'] = record;
	relatedParams['relationOperation'] = true;
	rcmail.crm.App.Components.QuickCreate.createRecord(moduleName, {
		callbackFunction: (data) => {
			rcmail.loadActionBar();
		},
		callbackPostShown: postShown,
		data: relatedParams,
		noCache: true
	});
};
rcube_webmail.prototype.getCrmWindow = function () {
	if (opener !== null && opener.parent.CONFIG == 'object') {
		return opener.parent;
	} else if (typeof parent.CONFIG == 'object') {
		return parent;
	} else if (typeof parent.parent.CONFIG == 'object') {
		return parent.parent;
	} else if (opener !== null && typeof opener.crm.CONFIG == 'object') {
		return opener.crm;
	}
	return false;
};
// Get raw mail body
rcube_webmail.prototype.loadMailAnalysis = function (props) {
	this.http_post('plugin.yetiforce-loadMailAnalysis', this.selection_post_data(), this.set_busy(true, 'loading'));
};
//Show mail analysis modal
rcube_webmail.prototype.showMailAnalysis = function (content) {
	let progressIndicatorElement = rcmail.crm.$.progressIndicator();
	rcmail.crm.AppConnector.request({
		module: 'AppComponents',
		view: 'MailMessageAnalysisModal',
		content: content
	})
		.done(function (data) {
			progressIndicatorElement.progressIndicator({ mode: 'hide' });
			rcmail.crm.app.showModalWindow(data);
		})
		.fail(function () {
			progressIndicatorElement.progressIndicator({ mode: 'hide' });
			rcmail.crm.app.showNotify({
				text: rcmail.crm.app.vtranslate('JS_ERROR'),
				type: 'error'
			});
		});
};
