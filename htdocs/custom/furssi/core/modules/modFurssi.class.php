<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup   furssi     Module Furssi
 * \brief      Slovenian fiscal verification of invoices (FURS / davcno potrjevanje racunov).
 * \file       htdocs/custom/furssi/core/modules/modFurssi.class.php
 * \ingroup    furssi
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Furssi
 */
class modFurssi extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		$this->numero = 500111;
		$this->rights_class = 'furssi';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'FurssiDescription';
		$this->descriptionlong = 'FurssiDescriptionLong';
		$this->editor_name = 'Gregor Ljubic - Radioglas';
		$this->editor_url = 'https://www.radioglas.com';
		$this->version = '1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';

		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array(
				'data' => array('pdfgeneration'),
				'entity' => '0',
			),
		);

		$this->dirs = array('/furssi');
		$this->config_page_url = array('setup.php@furssi');
		$this->hidden = false;
		$this->depends = array('modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('furssi@furssi');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, -3);

		$this->const = array();

		if (!isModEnabled('furssi')) {
			$conf->furssi = new stdClass();
			$conf->furssi->enabled = 0;
		}

		$this->tabs = array(
			'invoice:+furs:FursTab:furssi@furssi:$user->hasRight("furssi", "read"):/furssi/invoice_tab.php?id=__ID__',
		);
		$this->dictionaries = array();
		$this->boxes = array();

		$this->cronjobs = array(
			0 => array(
				'label' => 'FursResendPending',
				'jobtype' => 'method',
				'class' => '/furssi/class/FursClient.class.php',
				'objectname' => 'FursClient',
				'method' => 'resendPending',
				'parameters' => '50',
				'comment' => 'Resend invoices not yet confirmed by FURS',
				'frequency' => 10,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("furssi")',
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = 'Read FURS fiscal data';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero + 2;
		$this->rights[$r][1] = 'Resend invoices to FURS';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';

		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>      1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/furssi/sql/');
		if ($result < 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string $options Options when disabling module ('', 'noboxes')
	 * @return int<-1,1>      1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
