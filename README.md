# SKRIME Domain WHMCS Module

## Overview

This module allows the integration of SKRIME's domain registration service into WHMCS. With this module, you can register, renew, and manage domains through SKRIME directly from your WHMCS dashboard. Since version 2.0 the module can also keep your TLD prices and the renewal prices of existing domains up to date automatically.

## System Requirements

- WHMCS version 7.0 or higher (WHMCS 8.x recommended)
- PHP version 7.2 or higher (PHP 8.x supported)
- A working WHMCS cron job (required for the automatic price updates)

## Installation

1. **Download:** Download the `modules.zip` file that contains the SKRIME WHMCS module.

2. **Unzip:** Unzip the `modules.zip` file in the root directory of your WHMCS installation. This will copy the necessary files into the appropriate directories.

    - `/modules/registrars/skrime/` (the registrar module)
    - `/includes/hooks/skrime.php` (optional, makes sure the cron hooks are loaded even if WHMCS has not detected the module hooks yet)

3. **Activate the Module:**

    - Log in to your WHMCS admin dashboard.
    - Navigate to `System Settings` > `Domain Registrars`.
    - Find `SKRIME` and click on `Activate`.

4. **Configuration:**

    - After the module is activated, click on `Configure`.
    - Enter your SKRIME API key.
    - Adjust the settings described below and save the configuration. The API key is validated against the SKRIME API when saving (can be disabled).

### Updating from 1.x

- Replace the files in `/modules/registrars/skrime/` and copy `/includes/hooks/skrime.php`.
- Open `System Settings` > `Domain Registrars` > `SKRIME` and save the configuration once so the new settings are stored.
- Your API key is kept. The automatic price updates (TLD-Sync, Domain-Sync) stay disabled until you enable them.
- Active by default and configurable: DNSSEC in the client area, WHOIS privacy after ordering (only if ID protection was booked), premium domains shown as unavailable, protection of DNS records WHMCS cannot display, company name cleaning on orders, the dashboard widget.
- Always active: transfer synchronisation and the status synchronisation (expired/cancelled/transferred).
- Multi-year registrations and renewals are rejected with an error by default, because SKRIME registers and renews one year per request. Set "Mehrjährige Laufzeiten" to "Weitere Jahre per Verlängerung buchen" to book additional years via renewals. Transfers are always one year.

## Features

- **Domain Registration & Transfer:** Register and transfer domains through SKRIME. Missing nameservers are filled up with configurable default nameservers (SKRIME requires 2 to 6).
- **Domain Renewal:** Renew existing domains.
- **Multi-year terms:** Rejected or booked as additional renewals (configurable).
- **Nameserver Management:** Read and update nameservers.
- **Contact Management:** Read and update the registrant contact (house number handling included).
- **DNS Management:** Manage the DNS zone. MX priorities are supported. Records WHMCS cannot display (SRV, CAA, TLSA, DS, ...) are hidden and preserved when saving.
- **DNSSEC:** Clients can enable, change and disable DNSSEC (DNSKEY) in the client area.
- **WHOIS Privacy:** ID protection toggles the SKRIME WHOIS privacy. Optionally enabled directly after registration/transfer.
- **EPP Code:** Retrieve the auth code.
- **Availability Check:** Supports multiple TLDs per search and premium domain handling.
- **Domain Sync & Transfer Sync:** Expiry date and status synchronisation, completion of incoming transfers.
- **TLD Pricing Import:** WHMCS TLD & pricing sync (Utilities > Registrar TLD Sync). Imports one-year terms unless multi-year terms are booked via renewals.
- **Automatic TLD Price Update:** Updates register, transfer, renewal and optionally redemption prices from the SKRIME pricelist.
- **Automatic Domain Price Update:** Updates the recurring amount of existing domains when the price of the individual SKRIME product (the domain) changes.
- **Admin Tools:** Admin buttons on the domain page, a dashboard widget, a price change log and admin/client notifications.

## Automatic Price Updates

The price updates run in the WHMCS cron (`AfterCronJob`). Every price is calculated as:

```
SKRIME purchase price (EUR, per year)
  + margin in % (general or per type)
  + fixed margin (EUR)
  -> at least purchase price + minimum profit, at least minimum price
  -> converted into every WHMCS currency using the WHMCS exchange rates
  -> rounded according to the rounding rule
  x number of years
```

### TLD price sync

Updates `domainregister`, `domaintransfer` and `domainrenew` pricing of your TLDs.

- Scope: only TLDs with SKRIME as auto registrar, all existing TLDs, or all TLDs including automatic creation of missing ones.
- Trigger: only when the SKRIME price changed (manual changes stay until the next SKRIME price change) or enforce on every run.
- Include/exclude lists, client group pricing stays untouched unless enabled.
- Maximum term (default 1 year): terms above it are disabled. Disabled terms (`-1`) up to the maximum stay disabled unless enabled. Multi-year register prices are calculated as first year plus renewal price for every further year. Transfer prices are maintained for one year only.
- A failed pricelist download is retried after 15 minutes.
- Offers (`offer` in the pricelist): apply the offer price, use the regular renewal price as basis, or do not change the affected prices.

### Domain price sync (existing domains)

Updates the recurring amount (`recurringamount`) of existing SKRIME domains.

- Price source:
    - **SKRIME product price** of the individual domain (recommended). The price of the product is normalised to one year (`price` / `duration`).
    - Product price with fallback to the SKRIME pricelist.
    - SKRIME pricelist renewal price.
    - WHMCS TLD renewal price (use together with the TLD price sync).
- Trigger: only when the source price changed (the first run stores the current SKRIME price as baseline unless "apply on first run" is enabled) or enforce on every run.
- Runs in batches (configurable) and optionally during the regular WHMCS domain sync of each domain.
- Domain addons (DNS management, email forwarding, ID protection) are added to the recurring amount.
- Domains with promotion codes, free domains (recurring amount 0), premium domains, domains set to "do not renew" and clients whose client group has its own TLD pricing can be skipped. The WHMCS TLD source uses the client group price.
- With "Preis: Währungen = Nur EUR" only clients with the EUR currency are updated.
- If the SKRIME API is unreachable the batch stops and continues with the next cron run.
- Price increases, price decreases and a minimum change in percent can be configured.
- Optional client notification via a domain email template. Available merge fields: `{$skrime_old_price}`, `{$skrime_new_price}`, `{$skrime_currency}`.

### Manual runs

On the admin domain page the following buttons are available:

- `SKRIME: Domainpreis abgleichen` (this domain)
- `SKRIME: Alle Domainpreise abgleichen` (all domains, up to 120 seconds, the rest is continued by the cron even if the Domain-Sync is disabled)
- `SKRIME: TLD-Preise abgleichen` (all TLDs)

Manual runs ignore the interval and the trigger but still respect the price rules (increase/decrease/minimum change).

### Log & notifications

All price changes are written to the table `mod_skrime_price_log` and shown in the dashboard widget. Administrators receive one summary email per cron run if changes were made. The module stores its state in the tables `mod_skrime_kv`, `mod_skrime_tld_prices`, `mod_skrime_domain_prices` and `mod_skrime_price_log`, which are created automatically.

## Settings

| Setting | Description |
| --- | --- |
| API Key / API URL / API Timeout | Connection to the SKRIME API |
| Verbindung beim Speichern prüfen | Validate the API key when saving |
| Standard-Nameserver / erzwingen | Default nameservers for registrations and transfers |
| Firmennamen bereinigen | Remove parentheses and Ä/Ö/Ü from company names on orders |
| Premium-Domains | Show premium domains as unavailable (registrations of premium domains are refused) or offer them at the regular TLD price |
| Mehrjährige Laufzeiten | Reject registration/renewal terms above 1 year or book further years via renewals |
| WHOIS-Privacy bei Bestellung / Felder | WHOIS privacy after ordering and the fields to hide |
| Sync: Fehlende Domains kündigen | Mark domains missing at SKRIME as cancelled |
| DNS: MX/SRV-Priorität | Send the priority inside the data field or as separate field |
| DNS: Erweiterte Records schützen | Hide and preserve records WHMCS cannot display |
| DNSSEC im Kundenbereich | Enable the DNSSEC page for clients |
| TLD-Sync: ... | Enable, interval, trigger, first run, scope, include/exclude, price types, redemption fee, maximum term, disabled terms, client groups |
| Aktionspreise | Handling of SKRIME offers |
| Preis: ... | Margins (general and per type), minimum profit, minimum price, rounding, increases, decreases, minimum change, currencies |
| Domain-Sync: ... | Enable, price source, trigger, first run, interval, during WHMCS domain sync, bulk request, batch size, product price field, margin, addons, statuses, skip rules |
| Benachrichtigung: ... | Admin summary, client email template, only on increases |
| Protokoll: Aufbewahrung | Retention of the price log in days |
| Dashboard-Widget | Show the SKRIME widget on the admin dashboard |

## Development

```
composer install
vendor/bin/phpunit
```

## Support

If you need assistance with installing or using the module, please contact SKRIME support through our ticket system:

- Support ticket: https://skrime.eu/support/overview

Our support team will be happy to assist you with any questions or issues you may encounter.

## License

This module is released under the MIT License. See the LICENSE file for more details.
