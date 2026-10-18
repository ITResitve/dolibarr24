<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/class/FursClient.class.php
 * \ingroup    furssi
 * \brief      REST/JSON client to the FURS information system (ISFU) + invoice fiscalization logic.
 */
require_once __DIR__.'/FursCrypto.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';

/**
 * Client for FURS web service (JSON/JWS over two-way TLS)
 */
class FursClient
{
	const STATUS_PENDING = 0;
	const STATUS_CONFIRMED = 1;
	const STATUS_REJECTED = 2;

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();

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
	 * Reduce a VAT/tax identifier (e.g. "SI12345678", "SI 12345678") to the 8 digit Slovenian tax number.
	 *
	 * @param  string $value Raw value
	 * @return string        8 digits or '' if not valid
	 */
	public static function normalizeTaxNumber($value)
	{
		$digits = preg_replace('/\D/', '', (string) $value);
		return strlen($digits) == 8 ? $digits : '';
	}

	/**
	 * Tax number of the taxpayer: taken from Setup > Company/Organization (VAT ID).
	 *
	 * @return string 8 digits or ''
	 */
	public static function taxNumber()
	{
		global $mysoc;
		return self::normalizeTaxNumber(is_object($mysoc) ? $mysoc->tva_intra : getDolGlobalString('MAIN_INFO_TVAINTRA'));
	}

	/**
	 * Tax number of the operator issuing the invoice: field "National registration number" of the user.
	 * Without a user (cron, API without user) the taxpayer number is used (invoice issued without a person present).
	 *
	 * @param  User|null $user User
	 * @return string          8 digits, or '' when the user has no Slovenian tax number (foreign operator)
	 */
	public static function operatorTaxNumber($user)
	{
		if (!is_object($user) || empty($user->id)) {
			return self::taxNumber();
		}
		return self::normalizeTaxNumber($user->national_registration_number);
	}

	/**
	 * Environment: 'test' or 'prod'
	 *
	 * @return string
	 */
	public function env()
	{
		return getDolGlobalString('FURSSI_ENV', 'test') == 'prod' ? 'prod' : 'test';
	}

	/**
	 * Base URL (without trailing slash) of the service
	 *
	 * @return string
	 */
	public function baseUrl()
	{
		return $this->env() == 'prod' ? 'https://blagajne.fu.gov.si:9003/v1/cash_registers' : 'https://blagajne-test.fu.gov.si:9002/v1/cash_registers';
	}

	/**
	 * Path of the stored p12 for the current environment
	 *
	 * @param  string $env Environment
	 * @return string
	 */
	public static function certPath($env)
	{
		global $conf;
		return $conf->furssi->dir_output.'/cert_'.$env.'.p12';
	}

	/**
	 * Load client certificate and key for current environment.
	 *
	 * @return array{cert:string,pkey:string}|null
	 */
	public function credentials()
	{
		$env = $this->env();
		$file = self::certPath($env);
		if (!is_readable($file)) {
			$this->error = 'Certificate not uploaded for environment '.$env;
			return null;
		}
		$pass = dolDecrypt(getDolGlobalString('FURSSI_CERT_PASS_'.strtoupper($env)));
		$res = FursCrypto::readP12(file_get_contents($file), $pass);
		if (is_string($res)) {
			$this->error = $res;
			return null;
		}
		return $res;
	}

	/**
	 * POST to ISFU.
	 *
	 * @param  string     $path     Path after base URL ('/invoices', '/invoices/register', '/echo')
	 * @param  array      $payload  Payload array
	 * @param  bool       $sign     Wrap into signed JWS token
	 * @return array{ok:bool,http:int,data:array|null,error:string}  data = decoded response payload
	 */
	public function post($path, array $payload, $sign = true)
	{
		$res = array('ok' => false, 'http' => 0, 'data' => null, 'error' => '');
		$cred = $this->credentials();
		if (!$cred) {
			$res['error'] = $this->error;
			return $res;
		}
		if ($sign) {
			$token = FursCrypto::signJws($payload, $cred['cert'], $cred['pkey']);
			if ($token === null) {
				$res['error'] = 'Cannot sign message';
				return $res;
			}
			$body = json_encode(array('token' => $token));
		} else {
			$body = json_encode($payload);
		}

		$tmp = array();
		$ch = curl_init($this->baseUrl().$path);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		if (defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB')) {
			curl_setopt($ch, CURLOPT_SSLCERT_BLOB, $cred['cert']);
			curl_setopt($ch, CURLOPT_SSLKEY_BLOB, $cred['pkey']);
		} else {
			foreach (array('cert', 'pkey') as $k) {
				$f = tempnam(sys_get_temp_dir(), 'furs');
				chmod($f, 0600);
				file_put_contents($f, $cred[$k]);
				$tmp[$k] = $f;
			}
			curl_setopt($ch, CURLOPT_SSLCERT, $tmp['cert']);
			curl_setopt($ch, CURLOPT_SSLKEY, $tmp['pkey']);
		}
		$raw = curl_exec($ch);
		$res['http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlerr = curl_error($ch);
		curl_close($ch);
		foreach ($tmp as $f) {
			@unlink($f);
		}

		if ($raw === false) {
			$res['error'] = 'Network error: '.$curlerr;
			return $res;
		}
		$json = json_decode($raw, true);
		if (!is_array($json)) {
			$res['error'] = 'HTTP '.$res['http'].': unexpected response';
			return $res;
		}
		if (isset($json['token'])) {
			$r = FursCrypto::readJws($json['token'], getDolGlobalInt('FURSSI_VERIFY_RESPONSE', 1) > 0);
			if ($r['error']) {
				$res['error'] = $r['error'];
				return $res;
			}
			$res['data'] = $r['payload'];
		} else {
			$res['data'] = $json;
		}
		$res['ok'] = true;
		return $res;
	}

	/**
	 * Echo test (connectivity).
	 *
	 * @return bool
	 */
	public function echoTest()
	{
		$r = $this->post('/echo', array('EchoRequest' => 'furs'), false);
		if (!$r['ok'] || ($r['data']['EchoResponse'] ?? '') !== 'furs') {
			$this->error = $r['error'] ?: 'Unexpected echo response';
			return false;
		}
		return true;
	}

	/**
	 * Register (or close) the business premise configured in setup.
	 *
	 * @param  bool $close Mark premise as permanently closed
	 * @return bool
	 */
	public function registerPremise($close = false)
	{
		$tax = FursClient::taxNumber();
		$bp = array(
			'TaxNumber' => (int) $tax,
			'BusinessPremiseID' => getDolGlobalString('FURSSI_PREMISE_ID'),
		);
		$kind = getDolGlobalString('FURSSI_PREMISE_KIND', 'real');
		if ($kind == 'real') {
			$addr = array(
				'Street' => getDolGlobalString('FURSSI_STREET'),
				'HouseNumber' => getDolGlobalString('FURSSI_HOUSENO'),
			);
			if (getDolGlobalString('FURSSI_HOUSENO_ADD') !== '') {
				$addr['HouseNumberAdditional'] = getDolGlobalString('FURSSI_HOUSENO_ADD');
			}
			$addr += array(
				'Community' => getDolGlobalString('FURSSI_COMMUNITY'),
				'City' => getDolGlobalString('FURSSI_CITY'),
				'PostalCode' => getDolGlobalString('FURSSI_POSTCODE'),
			);
			$bp['BPIdentifier'] = array('RealEstateBP' => array(
				'PropertyID' => array(
					'CadastralNumber' => getDolGlobalInt('FURSSI_CADASTRAL'),
					'BuildingNumber' => getDolGlobalInt('FURSSI_BUILDING'),
					'BuildingSectionNumber' => getDolGlobalInt('FURSSI_SECTION'),
				),
				'Address' => $addr,
			));
		} else {
			$bp['BPIdentifier'] = array('PremiseType' => $kind); // A, B or C
		}
		$bp['ValidityDate'] = getDolGlobalString('FURSSI_VALIDITY_DATE', dol_print_date(dol_now(), '%Y-%m-%d'));
		if ($close) {
			$bp['ClosingTag'] = 'Z';
		}
		$bp['SoftwareSupplier'] = array(array('TaxNumber' => (int) getDolGlobalString('FURSSI_SOFTWARE_TAXNUMBER', $tax)));

		$payload = array('BusinessPremiseRequest' => array(
			'Header' => array('MessageID' => self::uuid(), 'DateTime' => date('Y-m-d\TH:i:s')),
			'BusinessPremise' => $bp,
		));
		$r = $this->post('/invoices/register', $payload);
		$err = $r['data']['BusinessPremiseResponse']['Error'] ?? null;
		if (!$r['ok'] || $err) {
			$this->error = $err ? $err['ErrorCode'].': '.$err['ErrorMessage'] : $r['error'];
			return false;
		}
		return true;
	}

	/**
	 * Generate a random UUID v4.
	 *
	 * @return string
	 */
	public static function uuid()
	{
		$d = random_bytes(16);
		$d[6] = chr(ord($d[6]) & 0x0f | 0x40);
		$d[8] = chr(ord($d[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
	}

	/**
	 * Fetch the log row of an invoice.
	 *
	 * @param  int $fk_facture Invoice id
	 * @return object|null
	 */
	public function fetchRecord($fk_facture)
	{
		global $conf;
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."furssi_invoice WHERE fk_facture = ".((int) $fk_facture)." AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			return $obj ?: null;
		}
		return null;
	}

	/**
	 * Sequence number for FURS: trailing digits of ref without leading zeros.
	 * Make sure your invoice numbering mask never restarts within a year (e.g. FA{yyyy}-{0000} with yearly reset).
	 *
	 * @param  Facture $invoice Invoice
	 * @return string
	 */
	public static function invoiceNumber($invoice)
	{
		if (preg_match('/(\d+)$/', $invoice->ref, $m) && ltrim($m[1], '0') !== '') {
			return ltrim($m[1], '0');
		}
		return (string) $invoice->id;
	}

	/**
	 * Decide whether an invoice must be fiscalized with the current setup.
	 *
	 * @param  Facture $invoice Invoice
	 * @param  string  $event   'validate' or 'paid'
	 * @return bool
	 */
	public function isEligible($invoice, $event)
	{
		if (FursClient::taxNumber() === '' || getDolGlobalString('FURSSI_PREMISE_ID') === '' || getDolGlobalString('FURSSI_DEVICE_ID') === '') {
			return false;
		}
		$mode = getDolGlobalString('FURSSI_TRIGGER_MODE', 'paid_cash');
		if ($mode == 'validate') {
			return $event == 'validate';
		}
		if ($event != 'paid') {
			return false;
		}
		$codes = array_filter(array_map('trim', explode(',', getDolGlobalString('FURSSI_CASH_CODES', 'LIQ,CB'))));
		if (!$codes) {
			return true;
		}
		$in = "'".implode("','", array_map(array($this->db, 'escape'), $codes))."'";
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."c_paiement as cp ON cp.id = p.fk_paiement";
		$sql .= " WHERE pf.fk_facture = ".((int) $invoice->id)." AND cp.code IN (".$in.")";
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			return $obj && $obj->nb > 0;
		}
		return false;
	}

	/**
	 * Create the log row (computing ZOI) and try to send it.
	 *
	 * @param  Facture $invoice Invoice
	 * @return int             1 confirmed, 0 pending (will be retried), -1 error
	 */
	public function fiscalize($invoice)
	{
		global $conf;

		if ($this->fetchRecord($invoice->id)) {
			return 0;
		}
		$cred = $this->credentials();
		if (!$cred) {
			return -1;
		}
		$tax = FursClient::taxNumber();
		$number = self::invoiceNumber($invoice);
		$premise = getDolGlobalString('FURSSI_PREMISE_ID');
		$device = getDolGlobalString('FURSSI_DEVICE_ID');
		$amount = round((float) $invoice->total_ttc, 2);
		$dt = date('Y-m-d\TH:i:s');
		$zoi = FursCrypto::zoi($cred['pkey'], $tax, $dt, $number, $premise, $device, $amount);
		if (!$zoi) {
			$this->error = 'Cannot compute ZOI';
			return -1;
		}
		global $user;
		$operator = self::operatorTaxNumber($user);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."furssi_invoice(entity, fk_facture, environment, invoice_number, premise_id, device_id, issue_datetime, amount, zoi, operator_taxnumber, status, date_creation)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $invoice->id).", '".$this->db->escape($this->env())."', '".$this->db->escape($number)."', '".$this->db->escape($premise)."', '".$this->db->escape($device)."', '".$this->db->escape($dt)."', ".((float) $amount).", '".$this->db->escape($zoi)."', ".($operator !== '' ? "'".$this->db->escape($operator)."'" : "NULL").", 0, '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return $this->sendRecord($this->fetchRecord($invoice->id), $invoice);
	}

	/**
	 * Build the InvoiceRequest payload.
	 *
	 * @param  Facture $invoice Invoice
	 * @param  object  $rec     Log row
	 * @return array
	 */
	public function buildInvoicePayload($invoice, $rec)
	{
		$taxes = array('VAT' => array());
		$exempt = 0.0;
		$byrate = array();
		foreach ($invoice->lines as $line) {
			$rate = (string) price2num($line->tva_tx, 2);
			if ((float) $rate == 0) {
				$exempt += (float) $line->total_ht;
				continue;
			}
			if (!isset($byrate[$rate])) {
				$byrate[$rate] = array('base' => 0.0, 'tax' => 0.0);
			}
			$byrate[$rate]['base'] += (float) $line->total_ht;
			$byrate[$rate]['tax'] += (float) $line->total_tva;
		}
		foreach ($byrate as $rate => $v) {
			$taxes['VAT'][] = array('TaxRate' => (float) $rate, 'TaxableAmount' => round($v['base'], 2), 'TaxAmount' => round($v['tax'], 2));
		}
		if (empty($taxes['VAT'])) {
			unset($taxes['VAT']);
		}
		if (abs($exempt) > 0.004) {
			$taxes['ExemptVATTaxableAmount'] = round($exempt, 2);
		}
		if (empty($taxes)) {
			$taxes['ExemptVATTaxableAmount'] = 0.0;
		}

		$amount = round((float) $rec->amount, 2);
		$inv = array(
			'TaxNumber' => (int) FursClient::taxNumber(),
			'IssueDateTime' => $rec->issue_datetime,
			'NumberingStructure' => getDolGlobalString('FURSSI_NUMBERING', 'B') == 'C' ? 'C' : 'B',
			'InvoiceIdentifier' => array(
				'BusinessPremiseID' => $rec->premise_id,
				'ElectronicDeviceID' => $rec->device_id,
				'InvoiceNumber' => $rec->invoice_number,
			),
		);
		if (empty($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		$cvat = preg_replace('/[^A-Za-z0-9]/', '', (string) ($invoice->thirdparty->tva_intra ?? ''));
		if ($cvat !== '') {
			$inv['CustomerVATNumber'] = substr($cvat, 0, 20);
		}
		$inv['InvoiceAmount'] = $amount;
		$inv['PaymentAmount'] = $amount;
		$inv['TaxesPerSeller'] = array($taxes);
		if (!empty($rec->operator_taxnumber)) {
			$inv['OperatorTaxNumber'] = (int) $rec->operator_taxnumber;
		} else {
			$inv['ForeignOperator'] = true;
		}
		$inv['ProtectedID'] = $rec->zoi;
		if ($rec->subsequent) {
			$inv['SubsequentSubmit'] = true;
		}
		// Credit note / replacement refers to the original fiscalized invoice
		if (!empty($invoice->fk_facture_source)) {
			$orig = $this->fetchRecord($invoice->fk_facture_source);
			if ($orig) {
				$inv['ReferenceInvoice'] = array(array(
					'ReferenceInvoiceIdentifier' => array(
						'BusinessPremiseID' => $orig->premise_id,
						'ElectronicDeviceID' => $orig->device_id,
						'InvoiceNumber' => $orig->invoice_number,
					),
					'ReferenceInvoiceIssueDateTime' => $orig->issue_datetime,
				));
			}
		}
		return array('InvoiceRequest' => array(
			'Header' => array('MessageID' => self::uuid(), 'DateTime' => date('Y-m-d\TH:i:s')),
			'Invoice' => $inv,
		));
	}

	/**
	 * Send one log row to FURS and update it.
	 *
	 * @param  object  $rec     Log row
	 * @param  Facture $invoice Invoice (lines loaded)
	 * @return int              1 confirmed, 0 pending, -1 rejected/error
	 */
	public function sendRecord($rec, $invoice)
	{
		if (!$rec || $rec->status == self::STATUS_CONFIRMED) {
			return 1;
		}
		if ($rec->nb_try > 0) {
			$rec->subsequent = 1; // a previous attempt may have reached FURS or not: flag as subsequent
		}
		$payload = $this->buildInvoicePayload($invoice, $rec);
		$msgid = $payload['InvoiceRequest']['Header']['MessageID'];
		$r = $this->post('/invoices', $payload);
		$resp = $r['data']['InvoiceResponse'] ?? null;

		$status = self::STATUS_PENDING;
		$eor = null;
		$err = '';
		if (!$r['ok']) {
			$err = $r['error'];
		} elseif (!empty($resp['Error'])) {
			$err = $resp['Error']['ErrorCode'].': '.$resp['Error']['ErrorMessage'];
			// S100 = system error at FURS: retry; others are permanent
			$status = ($resp['Error']['ErrorCode'] === 'S100' || $resp['Error']['ErrorCode'] === 's100') ? self::STATUS_PENDING : self::STATUS_REJECTED;
		} elseif (!empty($resp['UniqueInvoiceID'])) {
			$status = self::STATUS_CONFIRMED;
			$eor = $resp['UniqueInvoiceID'];
		} else {
			$err = 'Empty response';
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."furssi_invoice SET status = ".((int) $status).", nb_try = nb_try + 1, subsequent = ".((int) ($rec->subsequent ? 1 : 0)).",";
		$sql .= " last_message_id = '".$this->db->escape($msgid)."', last_error = ".($err !== '' ? "'".$this->db->escape($err)."'" : "NULL").",";
		$sql .= " eor = ".($eor ? "'".$this->db->escape($eor)."'" : "eor").",";
		$sql .= " date_sent = ".($status == self::STATUS_CONFIRMED ? "'".$this->db->idate(dol_now())."'" : "date_sent");
		$sql .= " WHERE rowid = ".((int) $rec->rowid);
		$this->db->query($sql);

		$this->error = $err;
		return $status == self::STATUS_CONFIRMED ? 1 : ($status == self::STATUS_PENDING ? 0 : -1);
	}

	/**
	 * Resend all pending invoices (cron job entry point).
	 *
	 * @param  int $limit Max invoices per run
	 * @return int        0 if OK (cron convention)
	 */
	public function resendPending($limit = 50)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$sql = "SELECT rowid, fk_facture FROM ".MAIN_DB_PREFIX."furssi_invoice WHERE status = 0 AND entity = ".((int) $conf->entity)." AND environment = '".$this->db->escape($this->env())."' ORDER BY rowid ASC".$this->db->plimit($limit);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 1;
		}
		$ids = array();
		while ($o = $this->db->fetch_object($resql)) {
			$ids[] = $o->fk_facture;
		}
		foreach ($ids as $id) {
			$inv = new Facture($this->db);
			if ($inv->fetch($id) > 0) {
				$this->sendRecord($this->fetchRecord($id), $inv);
			}
		}
		return 0;
	}
}
