<?php
/* Copyright (C) 2026 Gregor Ljubic
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       htdocs/custom/furssi/class/actions_furssi.class.php
 * \ingroup    furssi
 * \brief      Hook that stamps ZOI, EOR and QR / PDF417 / Code128 on generated invoice PDFs.
 */
require_once __DIR__.'/FursCrypto.class.php';
require_once __DIR__.'/FursClient.class.php';

/**
 * Hook class
 */
class ActionsFurssi
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var string */
	public $resprints = '';
	/** @var array */
	public $results = array();

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
	 * Hook afterPDFCreation: re-open the generated PDF and stamp fiscal data on the last page.
	 *
	 * @param  array        $parameters Hook parameters (file, object, outputlangs)
	 * @param  CommonObject $object     PDF model
	 * @param  string       $action     Action
	 * @return int                      0 OK, <0 error
	 */
	public function afterPDFCreation($parameters, &$object, &$action)
	{
		$invoice = $parameters['object'] ?? null;
		$file = $parameters['file'] ?? '';
		if (!($invoice instanceof Facture) || !$file || !is_readable($file) || strtolower(substr($file, -4)) != '.pdf') {
			return 0;
		}
		$client = new FursClient($this->db);
		$rec = $client->fetchRecord($invoice->id);
		if (!$rec) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
		try {
			$record = FursCrypto::dataRecord($rec->zoi, getDolGlobalString('FURSSI_TAXNUMBER'), $rec->issue_datetime);
			$type = getDolGlobalString('FURSSI_CODE_TYPE', 'qr');

			$pdf = pdf_getInstance();
			$pdf->SetAutoPageBreak(false);
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
			$n = $pdf->setSourceFile($file);
			for ($i = 1; $i <= $n; $i++) {
				$tpl = $pdf->importPage($i);
				$size = $pdf->getTemplateSize($tpl);
				$pdf->AddPage($size['h'] > $size['w'] ? 'P' : 'L', array($size['w'], $size['h']));
				$pdf->useTemplate($tpl);
				if ($i < $n) {
					continue;
				}
				$x = 10;
				$y = $size['h'] - 52; // above the standard Dolibarr footer
				$pdf->SetFont('helvetica', '', 7);
				$pdf->SetTextColor(0, 0, 0);
				$pdf->SetXY($x, $y);
				$pdf->Cell(120, 3, 'ZOI: '.$rec->zoi, 0, 2, 'L');
				$pdf->Cell(120, 3, 'EOR: '.($rec->eor ?: '-'), 0, 2, 'L');
				$by = $y + 7;
				if ($type == 'code128') {
					foreach (FursCrypto::code128Lines($record) as $k => $line) {
						$pdf->write1DBarcode($line, 'C128C', $x, $by + $k * 5, 60, 4, 0.4, array('stretch' => true, 'border' => false, 'text' => false));
					}
				} elseif ($type == 'pdf417') {
					$pdf->write2DBarcode($record, 'PDF417,,2', $x, $by, 60, 12, array('border' => 0, 'padding' => 0), 'N');
				} else {
					$pdf->write2DBarcode($record, 'QRCODE,M', $x, $by, 18, 18, array('border' => 0, 'padding' => 0, 'fgcolor' => array(0, 0, 0), 'bgcolor' => false), 'N');
				}
			}
			$pdf->Output($file, 'F');
			dolChmod($file);
		} catch (Exception $e) {
			dol_syslog('furssi: cannot stamp pdf: '.$e->getMessage(), LOG_ERR);
			$this->error = $e->getMessage();
			return -1;
		}
		return 0;
	}
}
