<?php

$labels = array();
$labels['nosignature'] = 'Няма звестак пра подпіс';
$labels['noauthresults'] = 'Подпіс знойдзены, але няма загалоўка вынікаў аўтэнтыфікацыі. Праблема канфігурацыі сервера?';
$labels['signaturepass'] = 'Прыдатны(ыя) подпіс(ы) з дамена адпраўніка. праверана';
$labels['partialpass'] = 'Некаторыя подпісы непрыдатныя, але прынамсі адзін прыдатны для дамена адпраўніка. праверана';
$labels['thirdparty'] = 'Падпісана іншай праграмай, подпіс прысутнічае, але для іншага дамена, адрознага ад адраса. праверана для';
$labels['temporaryinvalid'] = 'Подпіс (часова) не дзейнічае! праверана';
$labels['invalidsignature'] = 'Подпіс непрыдатны! праверана';

$labels['for'] = 'для';
$labels['by'] = 'па';

$labels['section_title']  = 'Статус аўтэнтыфікацыі';
$labels['column_title']  = 'Вынікі аўтэнтыфікацыі';

$labels['title_enable_column']  = 'Уключыць слупок';
$labels['label_enable_column']  = 'Уключэнне спіса вынікаў аўтэнтыфікацыі ў спісе лістоў';
$labels['label_fallback_verifier'] = 'Выкарыстоўваць ўнутраную сістэму верыфікацыі DKIM (калі подпіс знойдзены, але не ідэнтыфікаваны загаловак вынікаў аўтэнтыфікацыі)';

$labels['title_trusted_mtas'] = 'Trusted mta\'s';
$labels['label_trusted_mtas'] = 'Comma separated authserv-id list of mta\'s you trust. Only results from these mta\'s will be shown';

$labels['title_include_status'] = 'Паказваць толькі наступныя статусы';
$labels['label_include_status1']  = 'Няма подпісу';
$labels['label_include_status2']  = 'Signature found but no auth. results header';
$labels['label_include_status4']  = 'All signatures passed validation';
$labels['label_include_status8']  = 'At least one signature passed validation';
$labels['label_include_status16']  = 'Third party signature';
$labels['label_include_status32']  = 'Signature temporary failed';
$labels['label_include_status64']  = 'Signature permanently failed';

?>
