-- Copyright (C) 2026 Gregor Ljubic
ALTER TABLE llx_furssi_invoice ADD UNIQUE INDEX uk_furssi_invoice_facture (fk_facture, entity);
ALTER TABLE llx_furssi_invoice ADD INDEX idx_furssi_invoice_status (status);
