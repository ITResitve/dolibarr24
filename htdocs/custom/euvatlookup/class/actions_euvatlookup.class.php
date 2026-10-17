<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/euvatlookup/class/actions_euvatlookup.class.php
 * \ingroup    euvatlookup
 * \brief      Hook that adds the VIES lookup button on the third party create/edit form.
 */

/**
 * Class ActionsEuvatlookup
 */
class ActionsEuvatlookup
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error message
	 */
	public $error = '';

	/**
	 * @var string[] Error messages
	 */
	public $errors = array();

	/**
	 * @var mixed[] Hook results
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook printCommonFooter: load the lookup script on the third party create/edit form.
	 *
	 * @param  array<string,mixed> $parameters Hook parameters
	 * @param  CommonObject|null   $object     Object
	 * @param  string              $action     Current action
	 * @return int                             0 = continue standard footer
	 */
	public function printCommonFooter($parameters, &$object, &$action)
	{
		global $langs, $user;

		if (empty($parameters['context']) || !in_array('thirdpartycard', explode(':', $parameters['context']))) {
			return 0;
		}
		if (!in_array($action, array('create', 'edit')) || !$user->hasRight('societe', 'creer')) {
			return 0;
		}

		$langs->load('euvatlookup@euvatlookup');

		$texts = array();
		foreach (array('Button', 'Checking', 'Valid', 'NotValid', 'ServiceError', 'NoData', 'Filled', 'AlreadyUpToDate',
			'Differences', 'Field', 'Current', 'FromVies', 'Overwrite', 'ApplySelected', 'NotSavedYet', 'Duplicates',
			'FieldName', 'FieldAddress', 'FieldZip', 'FieldTown', 'FieldCountry', 'FieldVat', 'EnterVat') as $key) {
			$texts[$key] = $langs->transnoentitiesnoconv('EuVatLookup'.$key);
		}

		print "\n<!-- EuVatLookup -->\n";
		print '<script>window.euVatLookupConfig = '.json_encode(array(
			'url' => dol_buildpath('/euvatlookup/ajax/lookup.php', 1),
			'socid' => (int) GETPOSTINT('socid'),
			'texts' => $texts,
		)).';</script>'."\n";
		print '<script src="'.dol_buildpath('/euvatlookup/js/euvatlookup.js', 1).'"></script>'."\n";

		return 0;
	}
}
