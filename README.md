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
- Your API key is kept. The automatic syncs (TLD-Sync, Produkt-Sync) stay disabled until you enable them.
- Active by default and configurable: the SKRIME DNS editor (for domains with DNS management) and DNSSEC in the client area, WHOIS privacy after ordering (only if ID protection was booked), premium domains shown as unavailable, company name cleaning on orders, the dashboard widget.
- Always active: transfer synchronisation, the status synchronisation (expired/cancelled/transferred) and the SKRIME fields in the admin domain tab.
- SKRIME renews a domain by exactly 12 months per `product/renew` call. Multi-year registrations and renewals are booked as several renewals (one per additional year). If one of them fails, the registration still counts as successful and a notice is created; a failed renewal can simply be retried and only books the missing years. This can be switched to "reject" in "Mehrjährige Laufzeiten". Transfers are always one year.

## Features

- **Domain Registration & Transfer:** Register and transfer domains through SKRIME. Missing nameservers are filled up with configurable default nameservers (SKRIME requires 2 to 6).
- **Domain Renewal:** Renew existing domains.
- **Multi-year terms:** Booked as one `product/renew` call per additional year (12 months each) or rejected (configurable).
- **Nameserver Management:** Read and update nameservers.
- **Contact Management:** Read and update the registrant contact (house number handling included).
- **DNS Management:** Own SKRIME DNS editor in the client area with all SKRIME record types (A, AAAA, CNAME, ALIAS, MX, SRV, TXT, CAA, PTR, TLSA, DS, DNSKEY), by default only for domains with the DNS management addon. The WHMCS DNS page redirects to it. The editor only saves when the zone was loaded and has not been changed in the meantime. Alternatively the WHMCS standard page can be used; types SKRIME does not support (URL, FRAME, MXE) are then hidden.
- **DNSSEC:** Clients can enable, change and disable DNSSEC (DNSKEY) in the client area.
- **WHOIS Privacy:** ID protection toggles the SKRIME WHOIS privacy. Optionally enabled directly after registration/transfer.
- **EPP Code:** Retrieve the auth code.
- **Availability Check:** Supports multiple TLDs per search and premium domain handling.
- **Domain Sync & Transfer Sync:** Expiry date and status synchronisation, completion of incoming transfers.
- **TLD Pricing Import:** WHMCS TLD & pricing sync (Utilities > Registrar TLD Sync). Imports one-year terms unless multi-year terms are booked via renewals.
- **Automatic TLD Price Update:** Updates register, transfer, renewal and optionally redemption prices from the SKRIME pricelist.
- **Daily Product Sync:** Compares every SKRIME domain with its SKRIME product (`product/single` / `product/all`): purchase price, expiry date, status and auto renewal. Every change is logged.
- **Automatic Domain Price Update:** When the purchase price of a product changes, the renewal price of the domain is adjusted and the previous margin is kept.
- **Prices per domain:** In the admin domain tab every domain can follow the module settings, keep a fixed price that is never synced, or use its own margin.
- **Admin Tools:** SKRIME fields in the admin domain tab, admin buttons, a dashboard widget with open notices, a price and change log and admin/client notifications.

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
- Maximum term (default 10 years): terms above it are disabled. Disabled terms (`-1`) up to the maximum stay disabled unless enabled. Multi-year register prices are calculated as first year plus renewal price for every further year. Transfer prices are maintained for one year only.
- A failed pricelist download is retried after 15 minutes.
- Offers (`offer` in the pricelist): apply the offer price, use the regular renewal price as basis, or do not change the affected prices.

### Product sync (existing domains)

Runs daily (configurable) for every SKRIME domain and uses the SKRIME product of the domain, not the pricelist. The product price is the price for one renewal of 12 months. It can differ from the pricelist, for example when SKRIME uses a different or cheaper upstream registrar; the end customer never sees the purchase price.

What is compared and logged:

| Change | Logged as | Needs attention |
| --- | --- | --- |
| Purchase price of the product | Einkaufspreis | Only if prices are not adjusted automatically or the domain has a fixed price |
| Renewal price adjusted | Verkaufspreis | No |
| Expiry date at SKRIME | Ablaufdatum | No |
| Expiry date in WHMCS differs from SKRIME | Ablaufdatum abweichend | Yes (or adjusted automatically with "Ablaufdatum übernehmen") |
| Status at SKRIME (e.g. expired) | Status | Yes, if not active |
| Auto renewal active at SKRIME | Auto-Verlängerung | Yes (WHMCS renews as well) |
| Product missing at SKRIME | Nicht gefunden | Yes |

The log is shown in the admin domain tab ("SKRIME Änderungen", open notices can be marked as done), in the dashboard widget and in the admin summary email.

Price adjustment when the purchase price changes ("Produkt-Sync: Marge"):

- **Keep margin (amount, default):** The margin (renewal price − purchase price) is stored for every domain. The new price is the new purchase price plus the same margin. Example: 15,00 sold, 4,00 purchase → margin 11,00; purchase 5,00 → new price 16,00.
- **Keep margin (percent):** The price changes by the same factor as the purchase price.
- **Recalculate:** Margin, minimum profit and rounding from the module settings.

Price mode per domain (admin domain tab, "SKRIME Preis-Modus"):

- **Automatisch:** Follows the module settings.
- **Fester Preis:** The recurring amount set in WHMCS is never changed. A purchase price change is reported as a notice.
- **Eigene Marge:** Purchase price of the product + own percentage + own fixed amount (EUR), rounded like all prices. Applied immediately when saved and on every sync, independent of the price source, the currency setting, "Verkaufspreise anpassen" and the increase/decrease rules. Invalid input is not saved and shown as a notice.

Further options:

- Price source: SKRIME product (recommended), product with fallback to the pricelist, pricelist, or WHMCS TLD renewal price.
- Domain addons (DNS management, email forwarding, ID protection) stay part of the recurring amount.
- Domains with promotion codes, free domains, premium domains, domains set to "do not renew" and clients whose client group has its own TLD pricing are skipped in automatic mode (configurable).
- Price increases, price decreases and a minimum change in percent can be limited. A blocked change is shown as a notice and stays pending, so it is applied later in full when the rules allow it.
- Every purchase price change that did not lead to a new selling price (fixed price, skipped domain, blocked change, other price source) or that results in a negative margin is shown as a notice.
- Open notices of deleted domains or domains moved to another registrar are closed automatically; open notices are never removed by the log retention.
- Optional client notification via a domain email template. Merge fields: `{$skrime_old_price}`, `{$skrime_new_price}`, `{$skrime_currency}`.
- If the SKRIME API is unreachable the batch stops and continues with the next cron run.

### Manual runs

On the admin domain page the following buttons are available:

- `SKRIME: Domainpreis abgleichen` (this domain)
- `SKRIME: Alle Domainpreise abgleichen` (all domains, up to 120 seconds, the rest is continued by the cron even if the Produkt-Sync is disabled)
- `SKRIME: TLD-Preise abgleichen` (all TLDs)

Manual runs ignore the interval and the trigger but still respect the price rules (increase/decrease/minimum change).

### Log & notifications

All price changes are written to `mod_skrime_price_log`, all product changes to `mod_skrime_events`. Both are shown in the dashboard widget and the admin domain tab. Administrators receive one summary email per cron run if something changed. The module stores its state in the tables `mod_skrime_kv`, `mod_skrime_tld_prices`, `mod_skrime_domain_prices`, `mod_skrime_price_log` and `mod_skrime_events`, which are created and updated automatically.

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
| DNS: Editor im Kundenbereich | SKRIME DNS editor (all SKRIME types) or WHMCS standard page with unsupported types hidden |
| DNS: Editor nur mit DNS-Management | Show the SKRIME DNS editor only for domains with the DNS management addon |
| DNS: MX/SRV-Priorität | Send the priority inside the data field or as separate field |
| DNS: Erweiterte Records schützen | WHMCS standard page only: hide and preserve records WHMCS cannot display |
| DNSSEC im Kundenbereich | Enable the DNSSEC page for clients |
| TLD-Sync: ... | Enable, interval, trigger, first run, scope, include/exclude, price types, redemption fee, maximum term, disabled terms, client groups |
| Aktionspreise | Handling of SKRIME offers |
| Preis: ... | Margins (general and per type), minimum profit, minimum price, rounding, increases, decreases, minimum change, currencies |
| Produkt-Sync: ... | Enable, adjust prices, margin handling, purchase price source, take over expiry dates, trigger, first run, interval, during WHMCS domain sync, bulk request, batch size, product price field, margin for recalculation, addons, statuses, skip rules |
| Benachrichtigung: ... | Admin summary, client email template, only on increases |
| Protokoll: Aufbewahrung | Retention of the price and change log in days |
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
