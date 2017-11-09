/* {[The file is published on the basis of MIT License]} */
window.rcmail && rcmail.addEventListener('listupdate', function (evt) {
	//window.crm = getCrmWindow();
	rcmail.register_command('yetiforce.importICS', function (ics, element, e) {

	}, true);
	var container = $('#messagelistcontainer');
	var headerFixed = container.find('.records-table.messagelist.sortheader.fixedheader.fixedcopy');
	var messageList = container.find('#messagelist');
	/*
	 var columnsWidth = window.crm.app.moduleCacheGet('widthColumns');
	 if (columnsWidth != null) {
	 messageList.find('th,td').each(function (index) {
	 $(this).width(columnsWidth[index]);
	 });
	 headerFixed.find('th,td').each(function (index) {
	 $(this).width(columnsWidth[index]);
	 });
	 }
	 */
	/*
	 headerFixed.colResizable({
	 onResize: function (e) {
	 resizeContentTable(headerFixed, messageList, e);
	 },
	 resizeMode:'fit'
	 });
	 */
});

function resizeContentTable(headerFixed, messageList, e)
{
	var column_widths = [];
	headerFixed.find('thead th,thead td').each(function (index) {
		column_widths[index] = $(this).width();
	});
	messageList.find('th,td').each(function (index) {
		$(this).width(column_widths[index]);
	});
	window.crm.app.moduleCacheSet('widthColumns', column_widths);
	$(window).scroll();
}
