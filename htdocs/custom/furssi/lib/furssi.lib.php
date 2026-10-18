<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/lib/furssi.lib.php
 * \ingroup    furssi
 * \brief      Library functions for module Furssi
 */

/**
 * Tabs of the admin pages.
 *
 * @return array<int,array<int,string>>
 */
function furssiAdminPrepareHead()
{
	global $langs;
	$langs->load('furssi@furssi');

	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/furssi/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Setup');
	$head[$h][2] = 'setup';
	$h++;
	$head[$h][0] = dol_buildpath('/furssi/admin/log.php', 1);
	$head[$h][1] = $langs->trans('FursLog');
	$head[$h][2] = 'log';
	$h++;
	complete_head_from_modules($GLOBALS['conf'], $langs, null, $head, $h, 'furssi@furssi');
	complete_head_from_modules($GLOBALS['conf'], $langs, null, $head, $h, 'furssi@furssi', 'remove');
	return $head;
}

/**
 * Label of a log status.
 *
 * @param  int    $status Status
 * @return string
 */
function furssiStatusLabel($status)
{
	global $langs;
	if ($status == 1) {
		return '<span class="badge badge-status4 badge-status">'.$langs->trans('FursStatusConfirmed').'</span>';
	}
	if ($status == 2) {
		return '<span class="badge badge-status8 badge-status">'.$langs->trans('FursStatusRejected').'</span>';
	}
	return '<span class="badge badge-status1 badge-status">'.$langs->trans('FursStatusPending').'</span>';
}
