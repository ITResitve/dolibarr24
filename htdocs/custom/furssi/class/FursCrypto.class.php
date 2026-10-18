<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/class/FursCrypto.class.php
 * \ingroup    furssi
 * \brief      Crypto helpers for FURS (Slovenia) fiscal verification: ZOI, JWS, QR/barcode payload.
 *             Based on "Tehnicna specifikacija davcnega potrjevanja racunov" v3.2.
 */

/**
 * Static helpers, no database access.
 */
class FursCrypto
{
	/**
	 * Read a PKCS#12 file.
	 *
	 * @param  string $p12data Binary content of the .p12 file
	 * @param  string $pass    Password
	 * @return array{cert:string,pkey:string}|string  Array with PEM cert and key, or error string
	 */
	public static function readP12($p12data, $pass)
	{
		$out = array();
		if (!function_exists('openssl_pkcs12_read')) {
			return 'PHP openssl extension missing';
		}
		if (!openssl_pkcs12_read($p12data, $out, $pass)) {
			$err = '';
			while (($e = openssl_error_string()) !== false) {
				$err .= $e.' ';
			}
			return 'Cannot read p12 (wrong password or legacy algorithm with OpenSSL 3): '.trim($err);
		}
		return array('cert' => $out['cert'], 'pkey' => $out['pkey']);
	}

	/**
	 * Convert an openssl "/C=SI/O=x/CN=y" name into RFC2253 string as FURS expects in JWS header
	 * (reverse order, escaped, serialNumber as 2.5.4.5=#13xx...).
	 *
	 * @param  string $name  Output of openssl_x509_parse()['name'] or ['issuer'] converted by self::oneline()
	 * @return string
	 */
	public static function rfc2253($name)
	{
		$parts = preg_split('#/(?=[A-Za-z0-9.]+=)#', ltrim($name, '/'));
		$parts = array_reverse($parts);
		$res = array();
		foreach ($parts as $part) {
			$pos = strpos($part, '=');
			if ($pos === false) {
				continue;
			}
			$k = substr($part, 0, $pos);
			$v = substr($part, $pos + 1);
			// openssl escapes non ASCII as \xHH
			$v = preg_replace_callback('/\\\\x([0-9A-Fa-f]{2})/', function ($m) {
				return chr(hexdec($m[1]));
			}, $v);
			if (strtolower($k) == 'serialnumber') {
				$res[] = '2.5.4.5=#13'.sprintf('%02x', strlen($v)).bin2hex($v);
			} else {
				$res[] = $k.'='.preg_replace('/([,+"\\\\<>;])/', '\\\\$1', $v);
			}
		}
		return implode(',', $res);
	}

	/**
	 * Build the JWS protected header JSON (serial must be a JSON number, may exceed int64).
	 *
	 * @param  string $certpem PEM certificate
	 * @return string|null     JSON or null on error
	 */
	public static function jwsHeader($certpem)
	{
		$p = openssl_x509_parse($certpem);
		if (!$p) {
			return null;
		}
		$subject = self::rfc2253($p['name']);
		$issuerParts = array();
		foreach ($p['issuer'] as $k => $v) {
			foreach ((array) $v as $vv) {
				array_unshift($issuerParts, $k.'='.$vv);
			}
		}
		// openssl returns issuer as assoc array; build "/k=v" in original-like order then reverse inside rfc2253
		$issuer = self::rfc2253('/'.implode('/', array_reverse($issuerParts)));
		// Serial may exceed int64: convert the exact hex value to decimal digits
		$serial = self::hexToDec($p['serialNumberHex']);
		return '{"alg":"RS256","subject_name":'.json_encode($subject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			.',"issuer_name":'.json_encode($issuer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			.',"serial":'.$serial.'}';
	}

	/**
	 * Base64url encode
	 *
	 * @param  string $s Binary
	 * @return string
	 */
	public static function b64u($s)
	{
		return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
	}

	/**
	 * Base64url decode
	 *
	 * @param  string $s Encoded
	 * @return string
	 */
	public static function b64uDecode($s)
	{
		return base64_decode(strtr($s, '-_', '+/'));
	}

	/**
	 * Create signed compact JWS ("token") for a payload.
	 *
	 * @param  array  $payload Payload as PHP array
	 * @param  string $certpem PEM certificate
	 * @param  string $keypem  PEM private key
	 * @return string|null     Token or null
	 */
	public static function signJws(array $payload, $certpem, $keypem)
	{
		$header = self::jwsHeader($certpem);
		if ($header === null) {
			return null;
		}
		$input = self::b64u($header).'.'.self::b64u(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		$sig = '';
		if (!openssl_sign($input, $sig, $keypem, OPENSSL_ALGO_SHA256)) {
			return null;
		}
		return $input.'.'.self::b64u($sig);
	}

	/**
	 * Decode a token returned by FURS and verify its signature using the x5c certificate in header.
	 *
	 * @param  string $token  Compact JWS
	 * @param  bool   $verify Verify signature
	 * @return array{payload:array|null,verified:bool,error:string}
	 */
	public static function readJws($token, $verify = true)
	{
		$res = array('payload' => null, 'verified' => false, 'error' => '');
		$p = explode('.', $token);
		if (count($p) != 3) {
			$res['error'] = 'Invalid token';
			return $res;
		}
		$header = json_decode(self::b64uDecode($p[0]), true);
		$res['payload'] = json_decode(self::b64uDecode($p[1]), true);
		if ($verify) {
			if (empty($header['x5c'][0])) {
				$res['error'] = 'No x5c in response header';
				return $res;
			}
			$pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($header['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";
			$ok = openssl_verify($p[0].'.'.$p[1], self::b64uDecode($p[2]), $pem, OPENSSL_ALGO_SHA256);
			$res['verified'] = ($ok === 1);
			if ($ok !== 1) {
				$res['error'] = 'Response signature not valid';
			}
		}
		return $res;
	}

	/**
	 * Compute ZOI (protective mark of the invoice issuer).
	 * MD5( RSA-SHA256-sign( taxnumber + 'dd.MM.yyyy HH:mm:ss' + InvoiceNumber + BusinessPremiseID + ElectronicDeviceID + InvoiceAmount ) )
	 *
	 * @param  string $keypem   PEM private key
	 * @param  string $tax      Tax number (8 digits)
	 * @param  string $isoDt    Issue datetime 'YYYY-MM-DDTHH:MM:SS'
	 * @param  string $number   Sequence number
	 * @param  string $premise  Business premise id
	 * @param  string $device   Electronic device id
	 * @param  float  $amount   Total invoice amount
	 * @return string|null      32 hex chars or null
	 */
	public static function zoi($keypem, $tax, $isoDt, $number, $premise, $device, $amount)
	{
		$dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $isoDt);
		if (!$dt) {
			return null;
		}
		$data = $tax.$dt->format('d.m.Y H:i:s').$number.$premise.$device.number_format((float) $amount, 2, '.', '');
		$sig = '';
		if (!openssl_sign($data, $sig, $keypem, OPENSSL_ALGO_SHA256)) {
			return null;
		}
		return md5($sig);
	}

	/**
	 * Hexadecimal string to decimal string (no bcmath/gmp needed).
	 *
	 * @param  string $hex Hex
	 * @return string      Decimal digits
	 */
	public static function hexToDec($hex)
	{
		$dec = array(0);
		foreach (str_split($hex) as $c) {
			$carry = hexdec($c);
			for ($i = count($dec) - 1; $i >= 0; $i--) {
				$v = $dec[$i] * 16 + $carry;
				$dec[$i] = $v % 10;
				$carry = intdiv($v, 10);
			}
			while ($carry > 0) {
				array_unshift($dec, $carry % 10);
				$carry = intdiv($carry, 10);
			}
		}
		return ltrim(implode('', $dec), '0') ?: '0';
	}

	/**
	 * 60 digit data record for QR / PDF417 / Code128 (chapter 11 of the specification).
	 *
	 * @param  string $zoi   32 hex chars
	 * @param  string $tax   Tax number (8 digits)
	 * @param  string $isoDt 'YYYY-MM-DDTHH:MM:SS'
	 * @return string        60 digits
	 */
	public static function dataRecord($zoi, $tax, $isoDt)
	{
		$dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $isoDt);
		$rec = str_pad(self::hexToDec($zoi), 39, '0', STR_PAD_LEFT).str_pad($tax, 8, '0', STR_PAD_LEFT).$dt->format('ymdHis');
		return $rec.(array_sum(str_split($rec)) % 10);
	}

	/**
	 * Split the data record into Code 128 strings (4 codes: prefix "44"+index+15 digits).
	 *
	 * @param  string $record 60 digits
	 * @return string[]
	 */
	public static function code128Lines($record)
	{
		$lines = array();
		foreach (str_split($record, 15) as $i => $chunk) {
			$lines[] = '44'.($i + 1).$chunk;
		}
		return $lines;
	}
}
