-- Copyright (C) 2026 Gregor Ljubic
-- FURS fiscal verification log, one row per fiscalized invoice
CREATE TABLE llx_furssi_invoice(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_facture integer NOT NULL,
	environment varchar(4) DEFAULT 'test' NOT NULL,
	invoice_number varchar(20) NOT NULL,
	premise_id varchar(20) NOT NULL,
	device_id varchar(20) NOT NULL,
	issue_datetime varchar(19) NOT NULL,
	amount double(24,8) NOT NULL,
	zoi varchar(32) NOT NULL,
	operator_taxnumber varchar(8),
	eor varchar(36),
	status smallint DEFAULT 0 NOT NULL,
	subsequent smallint DEFAULT 0 NOT NULL,
	nb_try integer DEFAULT 0 NOT NULL,
	last_message_id varchar(36),
	last_error text,
	date_creation datetime NOT NULL,
	date_sent datetime,
	tms timestamp
) ENGINE=innodb;
