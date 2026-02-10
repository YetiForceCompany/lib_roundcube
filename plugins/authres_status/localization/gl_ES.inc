<?php

$labels = array();
$labels['nosignature'] = 'Non hai información sobre a sinatura';
$labels['noauthresults'] = 'Atopouse unha sinatura, pero non a cabeceria de resultados da autenticación. Pode ser que haxa un problema de configuración no servidor?';
$labels['signaturepass'] = 'Sinatura(s) válida(s) do dominio do remitente. Verificado por';
$labels['partialpass'] = 'Algunha sinaturas non son válidas, pero polo menos unha é válida para o dominio do remitente. Verificado por';
$labels['thirdparty'] = 'Asinado por unha terceira parte. A sinatura está presente, pero para un dominio diferente ao do enderezo. Verificado por';
$labels['temporaryinvalid'] = 'A sinatura non é válida (temporalmente)! Verificado por';
$labels['invalidsignature'] = 'A sinatura non é valida! Verificado por';

$labels['for'] = 'para';
$labels['by'] = 'por';

$labels['section_title']  = 'Estado de autenticación';
$labels['column_title']  = 'Resultado da autenticación';

$labels['title_enable_column']  = 'Activar columna';
$labels['label_enable_column']  = 'Amosar columna co resultado da autenticación na lista de mensaxes';
$labels['label_fallback_verifier'] = 'Usar o verificador DKIM interno (se se atopou a sinatura pero non a cabeceira de resultados de autenticación)';

$labels['title_trusted_mtas'] = 'Servidores de correos nos que confía';
$labels['label_trusted_mtas'] = 'Lista authser-id separada por comas dos servidores nos que confía. Só se amosarán os resultados destes servidores.';

$labels['title_include_status'] = 'Amosar só os seguintes estados';
$labels['label_include_status1']  = 'Non se atopou a sinatura';
$labels['label_include_status2']  = 'Atopouse a sinatura, pero non a cabeceria de resultados de autenticación';
$labels['label_include_status4']  = 'Todas as sinaturas foron validadas';
$labels['label_include_status8']  = 'Polo menos unha sinatura foi validada';
$labels['label_include_status16']  = 'Sinatura de terceira parte';
$labels['label_include_status32']  = 'A sinatura fallou temporalmente';
$labels['label_include_status64']  = 'A sinatura fallou permanentemente';

?>
