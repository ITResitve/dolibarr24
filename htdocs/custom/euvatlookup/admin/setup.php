<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/euvatlookup/admin/setup.php
 * \ingroup    euvatlookup
 * \brief      Setup page: OpenRegister API key (German market) and data explorer.
 */

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
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->loadLangs(array('admin', 'euvatlookup@euvatlookup'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'save') {
	$key = trim(GETPOST('openregister_key', 'alphanohtml'));
	$error = 0;
	// An empty field keeps the existing key, "remove" deletes it
	if (GETPOST('remove_key', 'int')) {
		dolibarr_del_const($db, 'EUVATLOOKUP_OPENREGISTER_KEY', $conf->entity);
	} elseif ($key !== '') {
		if (!preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $key)) {
			setEventMessages($langs->trans('EuVatLookupBadKey'), null, 'errors');
			$error++;
		} else {
			dolibarr_set_const($db, 'EUVATLOOKUP_OPENREGISTER_KEY', $key, 'chaine', 0, '', $conf->entity);
		}
	}
	if (!$error) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

$haskey = getDolGlobalString('EUVATLOOKUP_OPENREGISTER_KEY') !== '';
$ajaxurl = dol_buildpath('/euvatlookup/ajax/openregister.php', 1);

llxHeader('', $langs->trans('ModuleEuVatLookupName'));

print load_fiche_titre($langs->trans('ModuleEuVatLookupName'), '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>', 'title_setup');

print '<div class="opacitymedium">'.$langs->trans('EuVatLookupSetupIntro').'</div><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('EuVatLookupGermanRegister').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('EuVatLookupApiKey').'<br><span class="opacitymedium small">'.$langs->trans('EuVatLookupApiKeyHelp').'</span></td><td>';
print '<input type="password" name="openregister_key" class="minwidth300" autocomplete="off" placeholder="'.($haskey ? dol_escape_htmltag($langs->trans('EuVatLookupKeySet')) : 'sk_live_...').'">';
if ($haskey) {
	print ' <label><input type="checkbox" name="remove_key" value="1"> '.$langs->trans('EuVatLookupRemoveKey').'</label>';
}
print '</td></tr>';
print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form><br>';

// Data explorer
print load_fiche_titre($langs->trans('EuVatLookupExplorer'), '', '');
if (!$haskey) {
	print '<div class="warning">'.$langs->trans('EuVatLookupExplorerNoKey').'</div>';
} else {
	print '<div class="opacitymedium">'.$langs->trans('EuVatLookupExplorerHelp').'</div><br>';
	print '<input type="text" id="evl_q" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('EuVatLookupExplorerPlaceholder')).'"> ';
	print '<button type="button" class="button" id="evl_search">'.$langs->trans('Search').'</button>';
	print '<div id="evl_results" style="margin-top:10px;"></div>';
	print '<div id="evl_details" style="margin-top:10px;"></div>';

	$js = array(
		'url' => $ajaxurl,
		't' => array(
			'show' => $langs->transnoentitiesnoconv('EuVatLookupShowAll'),
			'confirm' => $langs->transnoentitiesnoconv('EuVatLookupConfirmCredits'),
			'error' => $langs->transnoentitiesnoconv('EuVatLookupRegisterError'),
			'auth' => $langs->transnoentitiesnoconv('EuVatLookupRegisterAuth'),
			'limit' => $langs->transnoentitiesnoconv('EuVatLookupRegisterLimit'),
			'none' => $langs->transnoentitiesnoconv('EuVatLookupRegisterNone'),
			'path' => $langs->transnoentitiesnoconv('EuVatLookupPath'),
			'value' => $langs->transnoentitiesnoconv('Value'),
			'hint' => $langs->transnoentitiesnoconv('EuVatLookupCanFill'),
		),
	);
	print '<script>
jQuery(function ($) {
	var cfg = '.json_encode($js).';
	var T = cfg.t;
	function err(status) { return status === "auth" ? T.auth : (status === "limit" ? T.limit : T.error); }
	$("#evl_search").on("click", function () {
		var $r = $("#evl_results").empty(); $("#evl_details").empty();
		$.getJSON(cfg.url, { action: "search", q: $("#evl_q").val() }).done(function (d) {
			if (d.status !== "ok") { $r.append($("<div class=\"error\"></div>").text(err(d.status))); return; }
			if (!d.results.length) { $r.text(T.none); return; }
			var $t = $("<table class=\"noborder\"></table>");
			$.each(d.results, function (i, c) {
				var $b = $("<button type=\"button\" class=\"button smallpaddingimp\"></button>").text(T.show).on("click", function () {
					if (!window.confirm(T.confirm)) { return; }
					details(c.company_id);
				});
				$t.append($("<tr class=\"oddeven\"></tr>")
					.append($("<td></td>").text(c.name))
					.append($("<td></td>").text(c.register))
					.append($("<td></td>").text(c.street + ", " + c.zip + " " + c.town))
					.append($("<td></td>").append($b)));
			});
			$r.append($t);
		}).fail(function () { $r.append($("<div class=\"error\"></div>").text(T.error)); });
	});
	function details(id) {
		var $d = $("#evl_details").empty();
		$.getJSON(cfg.url, { action: "details", id: id }).done(function (d) {
			if (d.status !== "ok") { $d.append($("<div class=\"error\"></div>").text(err(d.error === "AUTH" ? "auth" : (d.error === "LIMIT" ? "limit" : "")))); return; }
			var $t = $("<table class=\"noborder\"></table>");
			$t.append($("<tr class=\"liste_titre\"></tr>").append($("<td></td>").text(T.path)).append($("<td></td>").text(T.value)).append($("<td></td>").text(T.hint)));
			$.each(d.rows, function (i, r) {
				$t.append($("<tr class=\"oddeven\"></tr>")
					.append($("<td class=\"small\"></td>").text(r.path))
					.append($("<td></td>").text(r.value))
					.append($("<td class=\"ok\"></td>").text(r.hint)));
			});
			$d.append($t);
		}).fail(function () { $d.append($("<div class=\"error\"></div>").text(T.error)); });
	}
	$("#evl_q").on("keydown", function (e) { if (e.which === 13) { e.preventDefault(); $("#evl_search").click(); } });
});
</script>';
}

llxFooter();
$db->close();
