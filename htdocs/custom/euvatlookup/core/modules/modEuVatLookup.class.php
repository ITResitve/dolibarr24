<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup   euvatlookup     Module EuVatLookup
 * \brief      Check an EU VAT number with VIES and fill the third party form.
 * \file       htdocs/custom/euvatlookup/core/modules/modEuVatLookup.class.php
 * \ingroup    euvatlookup
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module EuVatLookup
 */
class modEuVatLookup extends DolibarrModules
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

		$this->numero = 500110;
		$this->rights_class = 'euvatlookup';
		$this->family = 'crm';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'EuVatLookupDescription';
		$this->descriptionlong = 'EuVatLookupDescriptionLong';
		$this->editor_name = 'Gregor Ljubic - Radioglas';
		$this->editor_url = 'https://www.radioglas.com';
		$this->version = '1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'company';

		$this->module_parts = array(
			'hooks' => array(
				'data' => array('thirdpartycard'),
				'entity' => '0',
			),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@euvatlookup');
		$this->hidden = false;
		$this->depends = array('modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('euvatlookup@euvatlookup');
		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 1;

		$this->const = array();

		if (!isModEnabled('euvatlookup')) {
			$conf->euvatlookup = new stdClass();
			$conf->euvatlookup->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
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
