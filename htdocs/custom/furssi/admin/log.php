<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/admin/log.php
 * \ingroup    furssi
 * \brief      List of fiscalized invoices and resend of pending ones
 */
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
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

if ($action == 'resendall') {
	if (!hash_equals($_SESSION['newtoken'] ?? '', GETPOST('token', 'alpha'))) {
		accessforbidden('Bad token');
	}
	$client = new FursClient($db);
	$client->resendPending(200);
	setEventMessages($langs->trans('FursActionOk'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('FursLog'));
print load_fiche_titre($langs->trans('FursSetup'), '', 'title_setup');
print dol_get_fiche_head(furssiAdminPrepareHead(), 'log', $langs->trans('FursSetup'), -1, 'bill');

$sql = "SELECT l.rowid, l.fk_facture, l.environment, l.premise_id, l.device_id, l.invoice_number, l.issue_datetime, l.amount, l.zoi, l.eor, l.status, l.nb_try, l.last_error, f.ref";
$sql .= " FROM ".MAIN_DB_PREFIX."furssi_invoice as l LEFT JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = l.fk_facture";
$sql .= " WHERE l.entity = ".((int) $conf->entity)." ORDER BY l.rowid DESC".$db->plimit(200);
$resql = $db->query($sql);

print '<div class="tabsAction"><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=resendall&token='.newToken().'">'.$langs->trans('FursResendAll').'</a></div>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('FursInvoiceId').'</td><td>'.$langs->trans('FursIssueDateTime').'</td><td class="right">'.$langs->trans('Amount').'</td><td>'.$langs->trans('FursZoi').'</td><td>'.$langs->trans('FursEor').'</td><td>'.$langs->trans('FursEnvironment').'</td><td>'.$langs->trans('FursStatus').'</td><td>'.$langs->trans('FursLastError').'</td></tr>';
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		print '<tr class="oddeven"><td><a href="'.dol_buildpath('/furssi/invoice_tab.php', 1).'?id='.((int) $o->fk_facture).'">'.dol_escape_htmltag($o->ref).'</a></td>';
		print '<td>'.dol_escape_htmltag($o->premise_id.'-'.$o->device_id.'-'.$o->invoice_number).'</td>';
		print '<td>'.dol_escape_htmltag($o->issue_datetime).'</td><td class="right">'.price($o->amount).'</td>';
		print '<td class="small">'.dol_escape_htmltag($o->zoi).'</td><td class="small">'.dol_escape_htmltag((string) $o->eor).'</td>';
		print '<td>'.dol_escape_htmltag($o->environment).'</td><td>'.furssiStatusLabel($o->status).'</td>';
		print '<td class="small">'.dol_escape_htmltag((string) $o->last_error).'</td></tr>';
	}
}
print '</table></div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
