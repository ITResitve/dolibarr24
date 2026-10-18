<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/core/triggers/interface_99_modFurssi_FursTriggers.class.php
 * \ingroup    furssi
 * \brief      Fiscalize customer invoices when validated or paid in cash.
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Trigger class for module Furssi
 */
class InterfaceFursTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'financial';
		$this->description = 'FURS fiscal verification triggers';
		$this->version = 'dolibarr';
		$this->picto = 'bill';
	}

	/**
	 * Function called when a Dolibarr business event is done.
	 *
	 * @param  string       $action Event code
	 * @param  CommonObject $object Object
	 * @param  User         $user   User
	 * @param  Translate    $langs  Langs
	 * @param  Conf         $conf   Conf
	 * @return int                  Always 0: sending problems never block the invoice (offline operation is allowed)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('furssi')) {
			return 0;
		}
		if ($action != 'BILL_VALIDATE' && $action != 'BILL_PAYED') {
			return 0;
		}
		if (!($object instanceof Facture)) {
			return 0;
		}
		require_once __DIR__.'/../../class/FursClient.class.php';
		$client = new FursClient($this->db);
		$event = ($action == 'BILL_VALIDATE') ? 'validate' : 'paid';
		if (!$client->isEligible($object, $event)) {
			return 0;
		}
		if (empty($object->lines)) {
			$object->fetch_lines();
		}
		$res = $client->fiscalize($object);
		if ($res < 0) {
			dol_syslog('furssi: '.$client->error, LOG_WARNING);
			setEventMessages($langs->trans('FursSendFailed', $client->error), null, 'warnings');
		} elseif ($res == 0 && $client->error) {
			setEventMessages($langs->trans('FursSendPending', $client->error), null, 'warnings');
		}
		return 0;
	}
}
