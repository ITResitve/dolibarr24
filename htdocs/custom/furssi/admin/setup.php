<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/admin/setup.php
 * \ingroup    furssi
 * \brief      Setup page of module Furssi
 */
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/../lib/furssi.lib.php';
require_once __DIR__.'/../class/FursClient.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
$langs->loadLangs(array('admin', 'furssi@furssi'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$client = new FursClient($db);

$textfields = array(
	'FURSSI_SOFTWARE_TAXNUMBER', 'FURSSI_PREMISE_ID', 'FURSSI_DEVICE_ID',
	'FURSSI_CASH_CODES', 'FURSSI_CADASTRAL', 'FURSSI_BUILDING', 'FURSSI_SECTION', 'FURSSI_STREET', 'FURSSI_HOUSENO',
	'FURSSI_HOUSENO_ADD', 'FURSSI_COMMUNITY', 'FURSSI_CITY', 'FURSSI_POSTCODE', 'FURSSI_VALIDITY_DATE',
);
$selectfields = array('FURSSI_ENV', 'FURSSI_NUMBERING', 'FURSSI_TRIGGER_MODE', 'FURSSI_CODE_TYPE', 'FURSSI_PREMISE_KIND', 'FURSSI_VERIFY_RESPONSE');

if ($action == 'save') {
	if (!hash_equals($_SESSION['newtoken'] ?? '', GETPOST('token', 'alpha'))) {
		accessforbidden('Bad token');
	}
	foreach (array_merge($textfields, $selectfields) as $c) {
		dolibarr_set_const($db, $c, trim(GETPOST($c, 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	}
	foreach (array('TEST', 'PROD') as $e) {
		$pw = GETPOST('FURSSI_CERT_PASS_'.$e, 'none');
		if ($pw !== '') {
			dolibarr_set_const($db, 'FURSSI_CERT_PASS_'.$e, dolEncrypt($pw), 'chaine', 0, '', $conf->entity);
		}
	}
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'uploadcert') {
	if (!hash_equals($_SESSION['newtoken'] ?? '', GETPOST('token', 'alpha'))) {
		accessforbidden('Bad token');
	}
	$env = GETPOST('certenv', 'alpha') == 'prod' ? 'prod' : 'test';
	if (!empty($_FILES['p12file']['tmp_name']) && is_uploaded_file($_FILES['p12file']['tmp_name'])) {
		dol_mkdir($conf->furssi->dir_output);
		$dest = FursClient::certPath($env);
		if (move_uploaded_file($_FILES['p12file']['tmp_name'], $dest)) {
			@chmod($dest, 0600);
			// Validate immediately with the stored password
			$cl = new FursClient($db);
			$old = getDolGlobalString('FURSSI_ENV');
			$conf->global->FURSSI_ENV = $env;
			$ok = $cl->credentials();
			$conf->global->FURSSI_ENV = $old;
			if ($ok) {
				setEventMessages($langs->trans('FursCertUploaded'), null, 'mesgs');
			} else {
				setEventMessages($cl->error, null, 'warnings');
			}
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if (in_array($action, array('echo', 'registerpremise', 'closepremise'))) {
	if (!hash_equals($_SESSION['newtoken'] ?? '', GETPOST('token', 'alpha'))) {
		accessforbidden('Bad token');
	}
	if ($action == 'echo') {
		$ok = $client->echoTest();
	} else {
		$ok = $client->registerPremise($action == 'closepremise');
	}
	if ($ok) {
		setEventMessages($langs->trans('FursActionOk'), null, 'mesgs');
	} else {
		setEventMessages($client->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$form = new Form($db);
llxHeader('', $langs->trans('FursSetup'));
print load_fiche_titre($langs->trans('FursSetup'), '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>', 'title_setup');
print dol_get_fiche_head(furssiAdminPrepareHead(), 'setup', $langs->trans('FursSetup'), -1, 'bill');

/**
 * Print a select row
 */
function furssiSelect($name, $options, $default = '')
{
	$cur = getDolGlobalString($name, $default);
	$s = '<select name="'.$name.'" class="flat minwidth200">';
	foreach ($options as $k => $label) {
		$s .= '<option value="'.dol_escape_htmltag($k).'"'.($cur == $k ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
	}
	return $s.'</select>';
}

/**
 * Print a text input
 */
function furssiInput($name, $size = 20)
{
	return '<input type="text" class="flat" size="'.$size.'" name="'.$name.'" value="'.dol_escape_htmltag(getDolGlobalString($name)).'">';
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';

$rows = array(
	'sep1' => $langs->trans('FursSectionGeneral'),
	'FURSSI_ENV' => furssiSelect('FURSSI_ENV', array('test' => $langs->trans('FursEnvTest'), 'prod' => $langs->trans('FursEnvProd')), 'test'),
	'FURSSI_TAXNUMBER' => (FursClient::taxNumber() !== '' ? '<strong>'.dol_escape_htmltag(FursClient::taxNumber()).'</strong>' : '<span class="error">'.$langs->trans('FursTaxNumberMissing').'</span>')
		.' <a href="'.DOL_URL_ROOT.'/admin/company.php">'.$langs->trans('FursTaxNumberFromCompany').'</a>',
	'FURSSI_OPERATOR_TAXNUMBER' => '<span class="opacitymedium">'.$langs->trans('FursOperatorFromUser').'</span>'
		.(FursClient::operatorTaxNumber($user) !== '' ? ' - '.$langs->trans('FursYourValue').': <strong>'.dol_escape_htmltag(FursClient::operatorTaxNumber($user)).'</strong>' : ' - <span class="warning">'.$langs->trans('FursYourValueMissing').'</span>'),
	'FURSSI_SOFTWARE_TAXNUMBER' => furssiInput('FURSSI_SOFTWARE_TAXNUMBER', 10),
	'FURSSI_PREMISE_ID' => furssiInput('FURSSI_PREMISE_ID'),
	'FURSSI_DEVICE_ID' => furssiInput('FURSSI_DEVICE_ID'),
	'FURSSI_NUMBERING' => furssiSelect('FURSSI_NUMBERING', array('B' => $langs->trans('FursNumberingB'), 'C' => $langs->trans('FursNumberingC')), 'B'),
	'FURSSI_TRIGGER_MODE' => furssiSelect('FURSSI_TRIGGER_MODE', array('paid_cash' => $langs->trans('FursTriggerPaidCash'), 'validate' => $langs->trans('FursTriggerValidate')), 'paid_cash'),
	'FURSSI_CASH_CODES' => furssiInput('FURSSI_CASH_CODES', 20),
	'FURSSI_CODE_TYPE' => furssiSelect('FURSSI_CODE_TYPE', array('qr' => 'QR', 'pdf417' => 'PDF417', 'code128' => 'Code 128'), 'qr'),
	'FURSSI_VERIFY_RESPONSE' => furssiSelect('FURSSI_VERIFY_RESPONSE', array('1' => $langs->trans('Yes'), '0' => $langs->trans('No')), '1'),
	'sep2' => $langs->trans('FursSectionPremise'),
	'FURSSI_PREMISE_KIND' => furssiSelect('FURSSI_PREMISE_KIND', array('real' => $langs->trans('FursPremiseReal'), 'A' => 'A', 'B' => 'B', 'C' => 'C'), 'real'),
	'FURSSI_CADASTRAL' => furssiInput('FURSSI_CADASTRAL', 6),
	'FURSSI_BUILDING' => furssiInput('FURSSI_BUILDING', 6),
	'FURSSI_SECTION' => furssiInput('FURSSI_SECTION', 6),
	'FURSSI_STREET' => furssiInput('FURSSI_STREET', 40),
	'FURSSI_HOUSENO' => furssiInput('FURSSI_HOUSENO', 6),
	'FURSSI_HOUSENO_ADD' => furssiInput('FURSSI_HOUSENO_ADD', 6),
	'FURSSI_COMMUNITY' => furssiInput('FURSSI_COMMUNITY', 30),
	'FURSSI_CITY' => furssiInput('FURSSI_CITY', 30),
	'FURSSI_POSTCODE' => furssiInput('FURSSI_POSTCODE', 6),
	'FURSSI_VALIDITY_DATE' => furssiInput('FURSSI_VALIDITY_DATE', 10).' <span class="opacitymedium">YYYY-MM-DD</span>',
	'sep3' => $langs->trans('FursSectionCerts'),
	'FURSSI_CERT_PASS_TEST' => '<input type="password" class="flat" autocomplete="new-password" name="FURSSI_CERT_PASS_TEST" placeholder="'.(getDolGlobalString('FURSSI_CERT_PASS_TEST') ? '********' : '').'">',
	'FURSSI_CERT_PASS_PROD' => '<input type="password" class="flat" autocomplete="new-password" name="FURSSI_CERT_PASS_PROD" placeholder="'.(getDolGlobalString('FURSSI_CERT_PASS_PROD') ? '********' : '').'">',
);
foreach ($rows as $key => $html) {
	if (strpos($key, 'sep') === 0) {
		print '<tr class="liste_titre"><td colspan="2">'.$html.'</td></tr>';
		continue;
	}
	print '<tr class="oddeven"><td class="titlefieldmiddle">'.$langs->trans($key).'</td><td>'.$html.'</td></tr>';
}
print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form><br>';

// Certificates upload
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('FursCertUpload').'</td></tr>';
foreach (array('test', 'prod') as $e) {
	$present = is_readable(FursClient::certPath($e));
	print '<tr class="oddeven"><td class="titlefieldmiddle">'.$langs->trans($e == 'test' ? 'FursEnvTest' : 'FursEnvProd').' ('.($present ? $langs->trans('FursCertPresent') : $langs->trans('FursCertMissing')).')</td><td>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="uploadcert"><input type="hidden" name="certenv" value="'.$e.'">';
	print '<input type="file" name="p12file" accept=".p12,.pfx"> <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Upload').'">';
	print '</form></td></tr>';
}
print '</table></div><br>';

// Actions
print '<div class="tabsAction">';
foreach (array('echo' => 'FursActionEcho', 'registerpremise' => 'FursActionRegister', 'closepremise' => 'FursActionClose') as $a => $label) {
	$confirm = ($a == 'closepremise') ? ' onclick="return confirm(\''.dol_escape_js($langs->trans('FursConfirmClose')).'\');"' : '';
	print '<a class="butAction'.($a == 'closepremise' ? 'Delete' : '').'" href="'.$_SERVER['PHP_SELF'].'?action='.$a.'&token='.newToken().'"'.$confirm.'>'.$langs->trans($label).'</a>';
}
print '</div>';
print '<div class="opacitymedium">'.$langs->trans('FursUrl').': '.dol_escape_htmltag($client->baseUrl()).'</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
