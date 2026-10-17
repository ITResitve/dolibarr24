<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/euvatlookup/ajax/lookup.php
 * \ingroup    euvatlookup
 * \brief      Ajax endpoint: check a VAT number on VIES and return the company data as JSON.
 *             Nothing is written to the database.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}

// Load Dolibarr environment (this file is in htdocs/custom/euvatlookup/ajax or htdocs/euvatlookup/ajax)
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */
require_once __DIR__.'/../class/ViesClient.php';

top_httphead('application/json');

if (!isModEnabled('euvatlookup') || !$user->hasRight('societe', 'creer')) {
	http_response_code(403);
	print json_encode(array('status' => 'forbidden'));
	exit;
}

$vat = GETPOST('vat', 'alphanohtml');
$socid = GETPOSTINT('socid');

$parts = ViesClient::split($vat);
if (!$parts) {
	print json_encode(array('status' => 'invalid_format'));
	exit;
}
list($cc, $number) = $parts;

$vies = new ViesClient();
$result = $vies->check($cc, $number);
if ($result === null) {
	print json_encode(array('status' => 'error'));
	exit;
}

$out = array(
	'status' => $result['valid'] ? 'valid' : 'not_valid',
	'vat' => $cc.$number,
	'name' => $result['name'],
	'street' => '',
	'zip' => '',
	'town' => '',
	'country_id' => 0,
	'duplicates' => array(),
);

if ($result['valid']) {
	$addr = ViesClient::parseAddress($result['address']);
	$out['street'] = $addr['street'];
	$out['zip'] = $addr['zip'];
	$out['town'] = $addr['town'];

	// VIES uses EL for Greece, Dolibarr uses GR. XI (Northern Ireland) has no country of its own.
	$iso = $cc == 'EL' ? 'GR' : ($cc == 'XI' ? 'GB' : $cc);
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE active = 1 AND code = '".$db->escape($iso)."'";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$out['country_id'] = (int) $obj->rowid;
	}

	// Duplicate detection: other third parties with the same VAT number
	$sql = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe";
	$sql .= " WHERE entity IN (".getEntity('societe').")";
	$sql .= " AND REPLACE(REPLACE(UPPER(tva_intra), ' ', ''), '.', '') = '".$db->escape($cc.$number)."'";
	if ($socid > 0) {
		$sql .= " AND rowid <> ".((int) $socid);
	}
	$sql .= " LIMIT 5";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out['duplicates'][] = array('id' => (int) $obj->rowid, 'name' => $obj->nom, 'url' => DOL_URL_ROOT.'/societe/card.php?socid='.((int) $obj->rowid));
		}
	}
}

print json_encode($out);
$db->close();
