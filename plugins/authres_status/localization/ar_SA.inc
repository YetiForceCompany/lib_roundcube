<?php

$labels = array();
$labels['nosignature'] = 'معلومات التوقيع غير موجودة';
$labels['noauthresults'] = 'تم العثور على توقيع ولكن لم يتم المصادقة. نتائج المقدمات. مشكلة في إعداد الخادم؟';
$labels['signaturepass'] = 'توقيع (التوقيعات) الصالحة من مجال المرسل. أكد عن طريق';
$labels['partialpass'] = 'بعض التوقيعات غير صالحة ولكن واحدة على الأقل صالحة لمجال المرسل. أكد عن طريق';
$labels['thirdparty'] = 'موقع من طرف ثالث ، توقيع موجود ولكن لمجال مختلف عن من في العنوان. أكد عن طريق';
$labels['temporaryinvalid'] = 'توقيع (مؤقتًا) غير صالح! أكد عن طريق';
$labels['invalidsignature'] = 'توقيع غير صالح! أكد عن طريق';

$labels['for'] = 'لـ';
$labels['by'] = 'بواسطة';

$labels['section_title']  = 'حالة المصادقة';
$labels['column_title']  = 'نتيجة. المصادقة';

$labels['title_enable_column']  = 'تفعيل العمود';
$labels['label_enable_column']  = 'تفعيل عمود نتيجة المصادقة في قائمة الرسائل';
$labels['label_fallback_verifier'] = 'استخدم أداة تحقق مفاتيح النطاق التي تم تحديدها بالبريد -DKIM- داخلية (إذا تم العثور على توقيع ولكن لم يتم العثور على مقدمة نتائج المصادقة)';

$labels['title_trusted_mtas'] = 'مُوَثّق mta';
$labels['label_trusted_mtas'] = 'قائمة معرف المصادقة المفصولة بفاصلة لـ mta التي تثق بها. سيتم عرض النتائج من هذه ال mta فقط';

$labels['title_include_status'] = 'عرض الحالات التالية فقط';
$labels['label_include_status1']  = 'لم يعثَر على أي توقيع';
$labels['label_include_status2']  = 'تم العثور على توقيع ولكن لم يتم المصادقة. مقدمات النتائج';
$labels['label_include_status4']  = 'اجتازت جميع التوقيعات عملية التحقق من الصحة';
$labels['label_include_status8']  = 'اجتاز توقيع واحد على الأقل عملية التحقق من الصحة';
$labels['label_include_status16']  = 'توقيع الطرف الثالث';
$labels['label_include_status32']  = 'فشل توقيع مؤقت';
$labels['label_include_status64']  = 'فشل توقيع نهائي';

?>
