<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/euvatlookup/class/OpenRegisterClient.php
 * \ingroup    euvatlookup
 * \brief      Client for the OpenRegister API (German Handelsregister). https://docs.openregister.de
 *             The API key is entered by the user in the module setup and stored in constant EUVATLOOKUP_OPENREGISTER_KEY.
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * Class OpenRegisterClient
 */
class OpenRegisterClient
{
	const BASE_URL = 'https://api.openregister.de/v1/';

	/**
	 * @var string Error code: '' | 'NO_KEY' | 'AUTH' | 'LIMIT' | 'SERVICE_ERROR'
	 */
	public $error = '';

	/**
	 * Is an API key configured?
	 *
	 * @return bool
	 */
	public static function isConfigured()
	{
		return getDolGlobalString('EUVATLOOKUP_OPENREGISTER_KEY') !== '';
	}

	/**
	 * GET call on the API.
	 *
	 * @param  string $path Path after /v1/ (already url encoded)
	 * @return array<string,mixed>|null  Decoded JSON or null on error (see $this->error)
	 */
	private function get($path)
	{
		$this->error = '';
		$key = getDolGlobalString('EUVATLOOKUP_OPENREGISTER_KEY');
		if ($key === '') {
			$this->error = 'NO_KEY';
			return null;
		}

		$res = getURLContent(self::BASE_URL.$path, 'GET', '', 1, array('Accept: application/json', 'Authorization: Bearer '.$key), array('https'), 0, -1, 5, 20);
		$json = json_decode($res['content'], true);
		$code = (int) $res['http_code'];

		if ($code == 401 || $code == 403) {
			$this->error = 'AUTH';
			return null;
		}
		if ($code == 402 || $code == 429) {
			$this->error = 'LIMIT';
			return null;
		}
		if (!empty($res['curl_error_no']) || $code != 200 || !is_array($json)) {
			dol_syslog('euvatlookup OpenRegister error http='.$code.' '.$res['curl_error_msg'].' '.substr($res['content'], 0, 300), LOG_WARNING);
			$this->error = 'SERVICE_ERROR';
			return null;
		}
		return $json;
	}

	/**
	 * Search companies by name (Handelsregister).
	 *
	 * @param  string $query Company name
	 * @return array<int,array<string,mixed>>|null  Normalized results or null on error
	 */
	public function search($query)
	{
		$json = $this->get('autocomplete/company?query='.rawurlencode($query));
		if ($json === null) {
			return null;
		}
		$out = array();
		foreach ((isset($json['results']) && is_array($json['results'])) ? $json['results'] : array() as $r) {
			$a = isset($r['address']) && is_array($r['address']) ? $r['address'] : array();
			$out[] = array(
				'company_id' => isset($r['company_id']) ? (string) $r['company_id'] : '',
				'name' => isset($r['name']) ? (string) $r['name'] : '',
				'active' => !isset($r['active']) || $r['active'],
				'legal_form' => isset($r['legal_form']) ? (string) $r['legal_form'] : '',
				'register' => trim((isset($r['register_type']) ? $r['register_type'] : '').' '.(isset($r['register_number']) ? $r['register_number'] : '').' '.(isset($r['register_court']) ? $r['register_court'] : '')),
				'street' => isset($a['street']) ? (string) $a['street'] : '',
				'zip' => isset($a['postal_code']) ? (string) $a['postal_code'] : '',
				'town' => isset($a['city']) ? (string) $a['city'] : '',
			);
		}
		return $out;
	}

	/**
	 * Full company record (costs 10 credits at OpenRegister). Used only by the data explorer in the setup page.
	 *
	 * @param  string $companyId Company id, e.g. DE-HRB-F1103-267645
	 * @return array<string,mixed>|null
	 */
	public function details($companyId)
	{
		if (!preg_match('/^[A-Za-z0-9_\-]{3,60}$/', $companyId)) {
			$this->error = 'SERVICE_ERROR';
			return null;
		}
		return $this->get('company/'.rawurlencode($companyId));
	}

	/**
	 * Flatten a nested array to path => value lines.
	 *
	 * @param  mixed  $data   Data
	 * @param  string $prefix Path prefix
	 * @return array<string,string>
	 */
	public static function flatten($data, $prefix = '')
	{
		$out = array();
		if (!is_array($data)) {
			$out[$prefix] = is_bool($data) ? ($data ? 'true' : 'false') : (string) $data;
			return $out;
		}
		if (!$data) {
			return $out;
		}
		foreach ($data as $k => $v) {
			$out += self::flatten($v, $prefix === '' ? (string) $k : $prefix.'.'.$k);
		}
		return $out;
	}

	/**
	 * Which Dolibarr third party field a path of the API response could fill.
	 *
	 * @return array<string,string> path pattern => language key
	 */
	public static function fieldHints()
	{
		return array(
			'name' => 'EuVatLookupFieldName',
			'address.street' => 'EuVatLookupFieldAddress',
			'address.postal_code' => 'EuVatLookupFieldZip',
			'address.city' => 'EuVatLookupFieldTown',
			'address.country' => 'EuVatLookupFieldCountry',
			'contact.vat_id' => 'EuVatLookupFieldVat',
			'contact.email' => 'Email',
			'contact.phone' => 'Phone',
			'contact.website_url' => 'Web',
			'register.register_number' => 'EuVatLookupHintProfId',
			'register.register_type' => 'EuVatLookupHintProfId',
			'register.register_court' => 'EuVatLookupHintProfId',
			'capital.amount' => 'EuVatLookupHintCapital',
			'legal_form' => 'EuVatLookupHintLegalForm',
		);
	}
}
