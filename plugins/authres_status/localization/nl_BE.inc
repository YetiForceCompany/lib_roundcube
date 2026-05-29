<?php

$labels = array();
$labels['nosignature'] = 'Geen handtekening informatie';
$labels['noauthresults'] = 'Handtekening gevonden maar geen koptekst met authenticiteitsgegevens. Probleem met de serverconfiguratie?';
$labels['signaturepass'] = 'Geldige handtekening(en) van het domein van de afzender. geverifieerd door ';
$labels['partialpass'] = 'Sommige handtekeningen zijn ongeldig, maar ten minste één is geldig voor het domein van de afzender. geverifieerd door ';
$labels['thirdparty'] = 'Ondertekend door derde partij, handtekening is aanwezig maar voor ander domein dan dit van adres. geverifieerd voor ';
$labels['temporaryinvalid'] = 'Handtekening is (tijdelijk) niet geldig! geverifieerd door ';
$labels['invalidsignature'] = 'Handtekening is niet geldig! geverifieerd door ';

$labels['for'] = 'voor';
$labels['by'] = 'door';

$labels['section_title']  = 'Status van echtheid';
$labels['column_title']  = 'Echth. Resultaat';

$labels['title_enable_column']  = 'Kolom Inschakelen';
$labels['label_enable_column']  = 'Kolom echtheidsresultaat in berichtenlijst inschakelen';
$labels['label_fallback_verifier'] = 'Gebruik interne DKIM-controle (indien handtekening gevonden maar geen kop met echth. resultaten)';

$labels['title_trusted_mtas'] = 'Vertrouwde mta\'s';
$labels['label_trusted_mtas'] = 'Door komma\'s gescheiden authserv-id lijst van mta\'s die u vertrouwt. Alleen resultaten van deze mta\'s worden getoond.';

$labels['title_include_status'] = 'Toon alleen de volgende statuswaarden';
$labels['label_include_status1']  = 'Geen handtekening gevonden';
$labels['label_include_status2']  = 'Handtekening gevonden maar geen echth. resultaten hoofding';
$labels['label_include_status4']  = 'Alle handtekeningen zijn goedgekeurd';
$labels['label_include_status8']  = 'Ten minste een handtekening heeft de validatie doorstaan';
$labels['label_include_status16']  = 'Handtekening van derden';
$labels['label_include_status32']  = 'Handtekening tijdelijk mislukt';
$labels['label_include_status64']  = 'Handtekening permanent mislukt';

?>
