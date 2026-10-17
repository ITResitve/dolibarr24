/* Copyright (C) 2026 Gregor Ljubic - GPL v3+
 *
 * EU VAT lookup: adds a "Check VIES" button next to the VAT number on the third party form.
 * Empty fields are filled. Fields that already contain a different value are listed and are
 * only changed if the user ticks them. The form is never submitted: nothing is saved until
 * the user clicks the normal Save button.
 */
jQuery(document).ready(function ($) {
	var cfg = window.euVatLookupConfig;
	var $vat = $('#intra_vat');
	if (!cfg || !$vat.length) {
		return;
	}
	var T = cfg.texts;

	// Dolibarr field id -> {label, key in ajax result}
	var fields = [
		{ id: 'name', label: T.FieldName, key: 'name' },
		{ id: 'address', label: T.FieldAddress, key: 'street' },
		{ id: 'zipcode', label: T.FieldZip, key: 'zip' },
		{ id: 'town', label: T.FieldTown, key: 'town' },
		{ id: 'selectcountry_id', label: T.FieldCountry, key: 'country_id', select: true },
		{ id: 'intra_vat', label: T.FieldVat, key: 'vat' }
	];

	var $btn = $('<button type="button" class="button smallpaddingimp euvatlookup-btn"></button>').text(T.Button);
	var $box = $('<div class="euvatlookup-box" style="margin-top:6px;"></div>');
	$vat.after($btn);
	$btn.after($box);
	$btn.css('margin-left', '6px');

	function msg(cls, text) {
		return $('<div></div>').addClass(cls).text(text);
	}

	function getValue(f) {
		var $el = $('#' + f.id);
		if (!$el.length) { return null; }
		return $.trim($el.val() || '');
	}

	function setValue(f, value) {
		var $el = $('#' + f.id);
		$el.val(value);
		// Update select2 display without triggering Dolibarr's native "change" handlers (which reload the form)
		$el.trigger(f.select ? 'change.select2' : 'input');
	}

	function displayValue(f, value) {
		if (f.select) {
			var $o = $('#' + f.id + ' option[value="' + value + '"]');
			return $o.length ? $.trim($o.text()) : String(value);
		}
		return String(value).replace(/\n/g, ', ');
	}

	function same(f, a, b) {
		return String(a).replace(/\s+/g, ' ').toUpperCase() === String(b).replace(/\s+/g, ' ').toUpperCase();
	}

	// German company register (Handelsregister, via OpenRegister): only if an API key is set in the module setup
	if (cfg.registerUrl) {
		var $rbtn = $('<button type="button" class="button smallpaddingimp euvatlookup-btn"></button>').text(T.RegisterButton).css('margin-left', '6px');
		$btn.after($rbtn);
		$rbtn.on('click', function () {
			var q = $.trim($('#name').val() || '');
			$box.empty();
			if (q.length < 3) {
				$box.append(msg('error', T.RegisterNameShort));
				return;
			}
			$rbtn.prop('disabled', true);
			$box.append(msg('opacitymedium', T.Checking));
			$.getJSON(cfg.registerUrl, { action: 'search', q: q })
				.done(function (d) {
					$box.empty();
					if (d.status !== 'ok') {
						$box.append(msg('error', d.status === 'auth' ? T.RegisterAuth : (d.status === 'limit' ? T.RegisterLimit : T.RegisterError)));
						return;
					}
					if (!d.results.length) {
						$box.append(msg('opacitymedium', T.RegisterNone));
						return;
					}
					$box.append(msg('', T.RegisterPick));
					var $tbl = $('<table class="noborder"></table>');
					$.each(d.results, function (i, c) {
						var $use = $('<button type="button" class="button smallpaddingimp"></button>').text(T.RegisterUse).on('click', function () {
							$box.empty();
							applyData({ name: c.name, street: c.street, zip: c.zip, town: c.town, country_id: c.country_id });
						});
						var label = c.name + (c.active ? '' : ' (' + T.RegisterInactive + ')');
						$tbl.append($('<tr class="oddeven"></tr>')
							.append($('<td></td>').text(label))
							.append($('<td class="small"></td>').text(c.register))
							.append($('<td class="small"></td>').text(c.street + ', ' + c.zip + ' ' + c.town))
							.append($('<td></td>').append($use)));
					});
					$box.append($tbl);
				})
				.fail(function () { $box.empty().append(msg('error', T.RegisterError)); })
				.always(function () { $rbtn.prop('disabled', false); });
		});
	}

	$btn.on('click', function () {
		var vatnum = $.trim($vat.val());
		$box.empty();
		if (!vatnum) {
			$box.append(msg('error', T.EnterVat));
			return;
		}
		$btn.prop('disabled', true);
		$box.append(msg('opacitymedium', T.Checking));

		$.getJSON(cfg.url, { vat: vatnum, socid: cfg.socid })
			.done(function (r) { show(r); })
			.fail(function () { $box.empty().append(msg('error', T.ServiceError)); })
			.always(function () { $btn.prop('disabled', false); });
	});

	function show(r) {
		$box.empty();
		if (r.status === 'invalid_format') {
			$box.append(msg('error', T.NotValid));
			return;
		}
		if (r.status === 'error') {
			$box.append(msg('error', T.ServiceError));
			return;
		}
		if (r.status === 'not_valid') {
			$box.append(msg('error', T.NotValid));
			return;
		}
		$box.append(msg('ok', T.Valid));

		if (r.duplicates && r.duplicates.length) {
			var $dup = $('<div class="warning"></div>').text(T.Duplicates + ' ');
			$.each(r.duplicates, function (i, d) {
				if (i) { $dup.append(', '); }
				$dup.append($('<a target="_blank" rel="noopener"></a>').attr('href', d.url).text(d.name));
			});
			$box.append($dup);
		}

		applyData(r);
	}

	// Fill empty fields, list the fields that differ. Used for VIES and for the German register.
	function applyData(r) {
		var filled = [], conflicts = [], hasData = false;
		$.each(fields, function (i, f) {
			var newv = r[f.key];
			if (newv === undefined || newv === null || newv === '' || newv === 0) { return; }
			if (f.key !== 'vat') { hasData = true; }
			var cur = getValue(f);
			if (cur === null) { return; } // field not on this form
			if (cur === '' || (f.select && (cur === '0' || cur === '-1'))) {
				setValue(f, newv);
				if (f.key !== 'vat') { filled.push(f.label); }
			} else if (!same(f, cur, newv)) {
				if (f.key === 'vat') {
					// only normalize the number the user typed (spaces, case)
					if (same(f, cur.replace(/[\s.\-]/g, ''), newv)) { setValue(f, newv); }
					return;
				}
				conflicts.push({ f: f, cur: cur, newv: newv });
			}
		});

		if (!hasData) {
			$box.append(msg('opacitymedium', T.NoData));
			return;
		}
		if (filled.length) {
			$box.append(msg('', T.Filled + ' ' + filled.join(', ')));
		}
		if (!conflicts.length) {
			if (!filled.length) { $box.append(msg('opacitymedium', T.AlreadyUpToDate)); }
			$box.append(msg('opacitymedium small', T.NotSavedYet));
			return;
		}

		var $tbl = $('<table class="noborder" style="margin-top:6px;"></table>');
		$tbl.append('<tr class="liste_titre"><td></td><td></td><td></td><td></td></tr>');
		$tbl.find('tr td').eq(0).text(T.Field);
		$tbl.find('tr td').eq(1).text(T.Current);
		$tbl.find('tr td').eq(2).text(T.FromVies);
		$tbl.find('tr td').eq(3).text(T.Overwrite);
		$.each(conflicts, function (i, c) {
			var $tr = $('<tr class="oddeven"></tr>');
			$tr.append($('<td></td>').text(c.f.label));
			$tr.append($('<td></td>').text(displayValue(c.f, c.cur)));
			$tr.append($('<td></td>').text(displayValue(c.f, c.newv)));
			$tr.append($('<td class="center"></td>').append($('<input type="checkbox">').data('conflict', c)));
			$tbl.append($tr);
		});
		var $apply = $('<button type="button" class="button smallpaddingimp"></button>').text(T.ApplySelected);
		$apply.on('click', function () {
			var n = [];
			$tbl.find('input:checked').each(function () {
				var c = $(this).data('conflict');
				setValue(c.f, c.newv);
				n.push(c.f.label);
			});
			if (n.length) {
				$box.append(msg('', T.Filled + ' ' + n.join(', ')));
				$box.append(msg('opacitymedium small', T.NotSavedYet));
			}
			$tbl.remove();
			$apply.remove();
		});
		$box.append(msg('warning', T.Differences)).append($tbl).append($apply);
		$box.append(msg('opacitymedium small', T.NotSavedYet));
	}
});
