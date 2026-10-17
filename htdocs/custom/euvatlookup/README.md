# EU VAT Lookup for Dolibarr

Adds a **Check VIES and fill** button next to the VAT number on the third party create/edit form.

The VAT number is checked on the official EU VIES service. The company name, address, zip, town and country are then put in the form:

- empty fields are filled in directly;
- fields that already contain a different value are listed, and only replaced if you tick them;
- nothing is saved until you click **Save**;
- a warning is shown if another third party already has the same VAT number.

## Limits

VIES is a VAT validation service, not a company register. Some countries (for example Germany) return no name or address. Those fields are left empty. The single address line returned by VIES is split into street, zip and town with a heuristic, so check it before saving.

## German companies (OpenRegister)

VIES returns no company data for Germany. In the module setup, enter your own OpenRegister API key (https://openregister.de/keys). A second button then searches the German Handelsregister by company name and fills the same fields.

The setup page also has a data explorer. It shows every field OpenRegister has for a company and which Dolibarr fields it could fill. Showing all data costs 10 OpenRegister credits, the search is cheap. The key is stored only in your Dolibarr database, never in the module files.

## Install

Copy the `euvatlookup` directory to `htdocs/custom/`, then enable the module in Home - Setup - Modules.

Languages: English, Slovenian, French, Italian, German.

Author: Gregor Ljubic - https://www.radioglas.com - License GPL v3+
