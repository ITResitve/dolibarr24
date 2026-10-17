<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/euvatlookup/class/ViesClient.php
 * \ingroup    euvatlookup
 * \brief      Client for the European Commission VIES REST API.
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

/**
 * Class ViesClient
 */
class ViesClient
{
	const BASE_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/';

	/**
	 * @var string Error code: '' | 'INVALID_FORMAT' | 'SERVICE_ERROR'
	 */
	public $error = '';

	/**
	 * Split a VAT number typed by the user into country code and number.
	 *
	 * @param  string $vat Raw VAT number (e.g. "si 5022.3054")
	 * @return array{0:string,1:string}|null  array(VIES country code, number) or null if format is wrong
	 */
	public static function split($vat)
	{
		$vat = strtoupper(preg_replace('/[\s.\-]/', '', $vat));
		if (!preg_match('/^([A-Z]{2})([A-Z0-9]{2,14})$/', $vat, $m)) {
			return null;
		}
		return array($m[1], $m[2]);
	}

	/**
	 * Check a VAT number on VIES.
	 *
	 * @param  string $countryCode VIES country code (EL for Greece, XI for Northern Ireland)
	 * @param  string $number      VAT number without country prefix
	 * @return array{valid:bool,name:string,address:string}|null  null on service error (see $this->error)
	 */
	public function check($countryCode, $number)
	{
		$this->error = '';

		$res = getURLContent(self::BASE_URL.rawurlencode($countryCode).'/vat/'.rawurlencode($number), 'GET', '', 1, array('Accept: application/json'), array('https'), 0, -1, 5, 20);
		$json = json_decode($res['content'], true);

		if (!empty($res['curl_error_no']) || !is_array($json) || (int) $res['http_code'] >= 500
			|| (isset($json['actionSucceed']) && !$json['actionSucceed'])
			|| (isset($json['errorWrappers']) && !isset($json['isValid']))) {
			dol_syslog('euvatlookup VIES error http='.$res['http_code'].' '.$res['curl_error_msg'].' '.substr($res['content'], 0, 300), LOG_WARNING);
			$this->error = 'SERVICE_ERROR';
			return null;
		}
		if (!isset($json['isValid'])) {
			$this->error = 'SERVICE_ERROR';
			return null;
		}

		return array(
			'valid' => (bool) $json['isValid'],
			'name' => self::clean(isset($json['name']) ? $json['name'] : ''),
			'address' => self::clean(isset($json['address']) ? $json['address'] : ''),
		);
	}

	/**
	 * VIES returns "---" when the member state does not publish the data.
	 *
	 * @param  string $s Value
	 * @return string    Cleaned value or ''
	 */
	private static function clean($s)
	{
		$s = trim(str_replace("\r", '', (string) $s));
		return preg_match('/^-*$/', $s) ? '' : $s;
	}

	/**
	 * Split the single address string returned by VIES into street, zip and town.
	 * Heuristic: the last line having "postal code + city" is zip/town, what is before is the street.
	 * If nothing can be recognized, everything goes into street and zip/town stay empty.
	 *
	 * @param  string $address Address as returned by VIES
	 * @return array{street:string,zip:string,town:string}
	 */
	public static function parseAddress($address)
	{
		$out = array('street' => '', 'zip' => '', 'town' => '');
		if ($address === '') {
			return $out;
		}

		$parts = preg_split('/\n/', $address);
		if (count($parts) == 1) {
			$parts = explode(',', $address);
		}
		$parts = array_values(array_filter(array_map('trim', $parts), 'strlen'));

		// Find zip + town, searching from the end
		for ($i = count($parts) - 1; $i >= 0; $i--) {
			if (preg_match('/^(?:[A-Z]{1,2}[- ])?(\d{2}-\d{3}|\d{3,5}(?:[- ]?\d{2,3})?(?: ?[A-Z]{2})?)\s+(\S.*)$/u', $parts[$i], $m)) {
				$out['zip'] = $m[1];
				$out['town'] = $m[2];
				unset($parts[$i]);
				break;
			}
		}

		// A line equal to the town (e.g. Slovenia: "STREET 1, POSTOJNA, 6230 POSTOJNA") is not part of the street
		if ($out['town'] !== '') {
			foreach ($parts as $k => $p) {
				if (mb_strtoupper($p) === mb_strtoupper($out['town'])) {
					unset($parts[$k]);
				}
			}
		}

		$out['street'] = implode("\n", $parts);
		return $out;
	}
}
