<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/invoice_tab.php
 * \ingroup    furssi
 * \brief      FURS fiscal data tab on the customer invoice card
 */
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once __DIR__.'/lib/furssi.lib.php';
require_once __DIR__.'/class/FursClient.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
$langs->loadLangs(array('bills', 'furssi@furssi'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

$object = new Facture($db);
if ($object->fetch($id) <= 0) {
	accessforbidden();
}
if (!$user->hasRight('furssi', 'read') || !$user->hasRight('facture', 'lire')) {
	accessforbidden();
}
$object->fetch_thirdparty();
$client = new FursClient($db);
$rec = $client->fetchRecord($object->id);

if ($action == 'send' && $user->hasRight('furssi', 'write')) {
	if (!hash_equals($_SESSION['newtoken'] ?? '', GETPOST('token', 'alpha'))) {
		accessforbidden('Bad token');
	}
	$object->fetch_lines();
	if ($rec) {
		$res = $client->sendRecord($rec, $object);
	} else {
		$res = $client->fiscalize($object);
	}
	if ($res > 0) {
		setEventMessages($langs->trans('FursActionOk'), null, 'mesgs');
	} else {
		setEventMessages($client->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

llxHeader('', $langs->trans('FursTab'));
$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'furs', $langs->trans('InvoiceCustomer'), -1, 'bill');
print '<div class="fichecenter"><table class="border centpercent tableforfield">';
if ($rec) {
	require_once __DIR__.'/class/FursCrypto.class.php';
	$rows = array(
		'FursStatus' => furssiStatusLabel($rec->status),
		'FursZoi' => '<span class="badge-like">'.dol_escape_htmltag($rec->zoi).'</span>',
		'FursEor' => dol_escape_htmltag($rec->eor ?: '-'),
		'FursInvoiceId' => dol_escape_htmltag($rec->premise_id.'-'.$rec->device_id.'-'.$rec->invoice_number),
		'FursIssueDateTime' => dol_escape_htmltag($rec->issue_datetime),
		'FursEnvironment' => dol_escape_htmltag($rec->environment),
		'FursTries' => (int) $rec->nb_try.($rec->subsequent ? ' ('.$langs->trans('FursSubsequent').')' : ''),
		'FursLastError' => dol_escape_htmltag((string) $rec->last_error),
		'FursCodeRecord' => dol_escape_htmltag(FursCrypto::dataRecord($rec->zoi, FursClient::taxNumber(), $rec->issue_datetime)),
	);
	foreach ($rows as $k => $v) {
		print '<tr><td class="titlefield">'.$langs->trans($k).'</td><td>'.$v.'</td></tr>';
	}
} else {
	print '<tr><td>'.$langs->trans('FursNotFiscalized').'</td></tr>';
}
print '</table></div>';
print dol_get_fiche_end();

if ($user->hasRight('furssi', 'write') && $object->statut > 0 && (!$rec || $rec->status != 1) && (!$rec || $rec->status != 2)) {
	print '<div class="tabsAction"><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=send&token='.newToken().'">'.$langs->trans($rec ? 'FursResend' : 'FursFiscalizeNow').'</a></div>';
}
llxFooter();
$db->close();
