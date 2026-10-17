<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/euvatlookup/ajax/openregister.php
 * \ingroup    euvatlookup
 * \brief      Ajax endpoint for the German company register (OpenRegister). Nothing is written to the database.
 *             action=search  : search companies by name (users allowed to create third parties)
 *             action=details : full record with all fields (administrators only, used by the setup page explorer)
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
require_once __DIR__.'/../class/OpenRegisterClient.php';

top_httphead('application/json');

$action = GETPOST('action', 'aZ09');

if (!isModEnabled('euvatlookup') || !$user->hasRight('societe', 'creer') || ($action == 'details' && !$user->admin)) {
	http_response_code(403);
	print json_encode(array('status' => 'forbidden'));
	exit;
}

$client = new OpenRegisterClient();

if ($action == 'search' || $action == 'vat') {
	if ($action == 'vat') {
		$vat = strtoupper(preg_replace('/[\s.\-]/', '', GETPOST('vat', 'alphanohtml')));
		if (!preg_match('/^DE\d{9}$/', $vat)) {
			print json_encode(array('status' => 'bad_vat'));
			exit;
		}
		$results = $client->searchByVat($vat);
	} else {
		$q = trim(GETPOST('q', 'alphanohtml'));
		if (dol_strlen($q) < 3) {
			print json_encode(array('status' => 'too_short'));
			exit;
		}
		$results = $client->search($q);
	}
	if ($results === null) {
		print json_encode(array('status' => $client->error == 'NO_KEY' ? 'no_key' : ($client->error == 'AUTH' ? 'auth' : ($client->error == 'LIMIT' ? 'limit' : 'error'))));
		exit;
	}

	// Germany: country id for the form
	$country_id = 0;
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE active = 1 AND code = 'DE'");
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$country_id = (int) $obj->rowid;
	}
	foreach ($results as &$r) {
		$r['country_id'] = $country_id;
	}
	unset($r);

	print json_encode(array('status' => 'ok', 'results' => $results));
} elseif ($action == 'details') {
	global $langs;
	$langs->load('euvatlookup@euvatlookup');
	$data = $client->details(GETPOST('id', 'alphanohtml'));
	if ($data === null) {
		print json_encode(array('status' => 'error', 'error' => $client->error));
		exit;
	}
	$hints = OpenRegisterClient::fieldHints();
	$rows = array();
	foreach (OpenRegisterClient::flatten($data) as $path => $value) {
		$generic = preg_replace('/\.\d+(?=\.|$)/', '', $path); // addresses.0.city -> addresses.city
		$hint = '';
		if (isset($hints[$path])) {
			$hint = $langs->transnoentitiesnoconv($hints[$path]);
		}
		$rows[] = array('path' => $path, 'value' => dol_trunc($value, 300), 'hint' => $hint, 'generic' => $generic);
	}
	print json_encode(array('status' => 'ok', 'rows' => $rows));
} else {
	print json_encode(array('status' => 'bad_request'));
}
$db->close();
