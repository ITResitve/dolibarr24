# Furssi - FURS fiscal verification of invoices (Slovenia)

Implements "Tehnične specifikacije za davčno potrjevanje računov" v3.2 (JSON/JWS over two-way TLS).

## Setup
1. Enable module *FURS fiscal verification*.
2. Set the VAT ID (e.g. `SI12345678`) in Setup > Company/Organization: its 8 digits are the taxpayer tax number. Fill "National registration number" on each user card with the operator's 8 digit tax number (empty = sent as foreign operator; no user, e.g. cron/self-service, = taxpayer number).
   Then Admin > Modules > Furssi > setup: premise mark, device mark, premise address/cadastre.
3. Upload the `.p12` client certificate for the test (and later production) environment and set its password.
4. Run **Test connection (echo)**, then **Register / update premise**.
5. Keep environment on *Test* for at least two days before switching to *Production* (spec recommendation).

## Behaviour
- Trigger: invoice fully paid with a cash payment mode (codes in setup, default `LIQ,CB`) or on validation of every invoice.
- ZOI is computed and stored before sending; EOR is stored on confirmation.
- Network failure / FURS system error: invoice stays *Pending*, a cron job (every 10 min) resends it with `SubsequentSubmit=true`. Rejected invoices (schema/signature errors) stay *Rejected* with the FURS error code.
- Credit notes / replacement invoices send `ReferenceInvoice` when the original was fiscalized.
- PDF: after generation, ZOI, EOR and QR (or PDF417 / Code128, see setup) are stamped on the last page, bottom-left above the footer. In "paid" mode regenerate the PDF after payment.

## Requirements / caveats
- Invoice number sent to FURS = trailing digits of the Dolibarr ref without leading zeros. Use a numbering mask that does not restart within a year, e.g. `FA{yyyy}-{0000}` with yearly reset.
- Lines with 0% VAT are reported as exempt supplies (`ExemptVATTaxableAmount`). Reverse-charge / special regimes are not mapped.
- Vending machine premises (types D/E/F) and the batch endpoints are not implemented.
- PHP `openssl`, `curl` required. With OpenSSL 3 a legacy-encrypted `.p12` may need re-export.
- Stamping position is fixed; adapt `class/actions_furssi.class.php` if your PDF template uses the bottom area.
