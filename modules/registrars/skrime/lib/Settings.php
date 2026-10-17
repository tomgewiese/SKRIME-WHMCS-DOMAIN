<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class Settings
{
    const MODULE = 'skrime';
    const VERSION = '2.0.0';

    private $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public static function fromParams(array $params)
    {
        return new self($params);
    }

    public static function fromRegistrar()
    {
        try {
            if (!Capsule::table('tblregistrars')->where('registrar', self::MODULE)->exists()) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $values = [];
        if (function_exists('getRegistrarConfigOptions')) {
            $values = getRegistrarConfigOptions(self::MODULE);
        } else {
            $rows = Capsule::table('tblregistrars')->where('registrar', self::MODULE)->get();
            foreach ($rows as $row) {
                $values[$row->setting] = function_exists('decrypt') ? decrypt($row->value) : $row->value;
            }
        }

        return new self(is_array($values) ? $values : []);
    }

    public static function intervalOptions()
    {
        return [
            '1' => 'Stündlich',
            '3' => 'Alle 3 Stunden',
            '6' => 'Alle 6 Stunden',
            '12' => 'Alle 12 Stunden',
            '24' => 'Täglich',
            '48' => 'Alle 2 Tage',
            '168' => 'Wöchentlich',
        ];
    }

    public static function definitions()
    {
        $years = [];
        for ($i = 1; $i <= 10; $i++) {
            $years[(string) $i] = $i . ($i === 1 ? ' Jahr' : ' Jahre');
        }

        $trigger = [
            'change' => 'Nur wenn sich der SKRIME-Preis geändert hat',
            'always' => 'Bei jedem Lauf erzwingen',
        ];

        return [
            'apiKey' => [
                'FriendlyName' => 'API Key',
                'Type' => 'text',
                'Size' => '60',
                'Default' => '',
                'Description' => 'SKRIME API Key',
            ],
            'apiUrl' => [
                'FriendlyName' => 'API URL',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'https://skrime.eu/api/',
                'Description' => 'Basis-URL der SKRIME API',
            ],
            'apiTimeout' => [
                'FriendlyName' => 'API Timeout',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '30',
                'Description' => 'Sekunden',
            ],
            'validateOnSave' => [
                'FriendlyName' => 'Verbindung beim Speichern prüfen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'API Key beim Speichern der Einstellungen gegen die SKRIME API testen',
            ],
            'defaultNameservers' => [
                'FriendlyName' => 'Standard-Nameserver',
                'Type' => 'text',
                'Size' => '80',
                'Default' => 'nameserver01.eu,nameserver02.eu',
                'Description' => 'Kommagetrennt. Wird ergänzt, wenn bei Registrierung/Transfer weniger als 2 Nameserver übergeben werden',
            ],
            'forceDefaultNameservers' => [
                'FriendlyName' => 'Standard-Nameserver erzwingen',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Bei Registrierung/Transfer immer die Standard-Nameserver verwenden',
            ],
            'cleanCompanyName' => [
                'FriendlyName' => 'Firmennamen bereinigen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Klammern und Ä/Ö/Ü aus dem Firmennamen entfernen',
            ],
            'premiumHandling' => [
                'FriendlyName' => 'Premium-Domains',
                'Type' => 'dropdown',
                'Options' => [
                    'unavailable' => 'Als nicht verfügbar anzeigen',
                    'available' => 'Zum normalen TLD-Preis anbieten',
                ],
                'Default' => 'unavailable',
                'Description' => 'Die SKRIME API liefert keine Premium-Preise. "Anbieten" verkauft Premium-Domains zum normalen TLD-Preis, SKRIME berechnet ggf. den Premium-Preis. Ist der Premium-Verkauf in WHMCS deaktiviert, gelten sie immer als nicht verfügbar',
            ],
            'multiYearMode' => [
                'FriendlyName' => 'Mehrjährige Laufzeiten',
                'Type' => 'dropdown',
                'Options' => [
                    'renew' => 'Weitere Jahre per Verlängerung buchen (je 12 Monate ein Renew)',
                    'reject' => 'Ablehnen (nur 1 Jahr erlauben)',
                ],
                'Default' => 'renew',
                'Description' => 'SKRIME verlängert Domains je Aufruf um genau 12 Monate. Mehrere Jahre werden als mehrere Verlängerungen gebucht. Transfers sind immer 1 Jahr',
            ],
            'idProtectionOnOrder' => [
                'FriendlyName' => 'WHOIS-Privacy bei Bestellung',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'WHOIS-Privacy direkt nach Registrierung/Transfer aktivieren, wenn ID-Protection gebucht wurde',
            ],
            'privacyFields' => [
                'FriendlyName' => 'WHOIS-Privacy Felder',
                'Type' => 'text',
                'Size' => '80',
                'Default' => 'organization,name,email,voice,addressLine,city,postalCode',
                'Description' => 'Kommagetrennt: organization, name, email, voice, addressLine, city, postalCode',
            ],
            'syncMissingAsCancelled' => [
                'FriendlyName' => 'Sync: Fehlende Domains kündigen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Domains, die bei SKRIME nicht (mehr) existieren, beim Domain-Sync als gekündigt markieren',
            ],
            'dnsMxMode' => [
                'FriendlyName' => 'DNS: MX/SRV-Priorität',
                'Type' => 'dropdown',
                'Options' => [
                    'data' => 'Im Datenfeld (z. B. "10 mx.domain.tld")',
                    'field' => 'Separates Feld "priority"',
                ],
                'Default' => 'data',
                'Description' => 'Übertragung der Priorität an die SKRIME API',
            ],
            'dnsKeepUnsupported' => [
                'FriendlyName' => 'DNS: Erweiterte Records schützen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Records, die WHMCS nicht darstellen kann (SRV, CAA, TLSA, ...), ausblenden und beim Speichern beibehalten',
            ],
            'dnsEditor' => [
                'FriendlyName' => 'DNS: Editor im Kundenbereich',
                'Type' => 'dropdown',
                'Options' => [
                    'skrime' => 'SKRIME DNS-Editor (alle SKRIME-Record-Typen)',
                    'whmcs' => 'WHMCS-Standard (nicht unterstützte Typen werden ausgeblendet)',
                ],
                'Default' => 'skrime',
                'Description' => 'Der SKRIME DNS-Editor unterstützt A, AAAA, CNAME, ALIAS, MX, SRV, TXT, CAA, PTR, TLSA, DS und DNSKEY. Die WHMCS-DNS-Seite leitet dann auf den Editor weiter',
            ],
            'dnssecClientArea' => [
                'FriendlyName' => 'DNSSEC im Kundenbereich',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'DNSSEC-Verwaltung für Kunden anzeigen',
            ],
            'tldSyncEnabled' => [
                'FriendlyName' => 'TLD-Sync: Aktiv',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'TLD-Preise (Registrierung/Transfer/Verlängerung) automatisch aus der SKRIME-Preisliste aktualisieren. Vorher den Aufschlag konfigurieren',
            ],
            'tldSyncInterval' => [
                'FriendlyName' => 'TLD-Sync: Intervall',
                'Type' => 'dropdown',
                'Options' => self::intervalOptions(),
                'Default' => '24',
                'Description' => 'Die SKRIME-Preisliste wird stündlich aktualisiert',
            ],
            'tldSyncTrigger' => [
                'FriendlyName' => 'TLD-Sync: Auslöser',
                'Type' => 'dropdown',
                'Options' => $trigger,
                'Default' => 'change',
                'Description' => '"Nur bei Änderung" lässt manuelle Anpassungen bis zur nächsten SKRIME-Preisänderung bestehen',
            ],
            'tldSyncInitial' => [
                'FriendlyName' => 'TLD-Sync: Beim ersten Lauf anwenden',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Preise beim ersten Abgleich einer TLD sofort setzen',
            ],
            'tldSyncScope' => [
                'FriendlyName' => 'TLD-Sync: Umfang',
                'Type' => 'dropdown',
                'Options' => [
                    'assigned' => 'Nur TLDs mit SKRIME als Auto-Registrar',
                    'existing' => 'Alle in WHMCS angelegten TLDs',
                    'create' => 'Alle TLDs, fehlende automatisch anlegen',
                ],
                'Default' => 'assigned',
                'Description' => '',
            ],
            'tldInclude' => [
                'FriendlyName' => 'TLD-Sync: Nur diese TLDs',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Kommagetrennt, z. B. de,com,eu. Leer = alle',
            ],
            'tldExclude' => [
                'FriendlyName' => 'TLD-Sync: TLDs ausschließen',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Kommagetrennt',
            ],
            'tldUpdateRegister' => [
                'FriendlyName' => 'TLD-Sync: Registrierungspreise',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Aktualisieren',
            ],
            'tldUpdateTransfer' => [
                'FriendlyName' => 'TLD-Sync: Transferpreise',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Aktualisieren',
            ],
            'tldUpdateRenew' => [
                'FriendlyName' => 'TLD-Sync: Verlängerungspreise',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Aktualisieren',
            ],
            'tldUpdateRedemption' => [
                'FriendlyName' => 'TLD-Sync: Redemption-Gebühr',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Redemption-Gebühr aus dem Restore-Preis setzen (Standardwährung)',
            ],
            'tldMaxYears' => [
                'FriendlyName' => 'TLD-Sync: Maximale Laufzeit',
                'Type' => 'dropdown',
                'Options' => $years,
                'Default' => '10',
                'Description' => 'Preise bis zu dieser Laufzeit pflegen, längere Laufzeiten werden deaktiviert. Mehr als 1 Jahr nur mit "Weitere Jahre per Verlängerung buchen"',
            ],
            'tldEnableYears' => [
                'FriendlyName' => 'TLD-Sync: Deaktivierte Laufzeiten aktivieren',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Auch Laufzeiten mit Preis -1 (deaktiviert) überschreiben',
            ],
            'tldClientGroups' => [
                'FriendlyName' => 'TLD-Sync: Kundengruppen-Preise',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Auch abweichende Preise für Kundengruppen überschreiben',
            ],
            'offerMode' => [
                'FriendlyName' => 'Aktionspreise',
                'Type' => 'dropdown',
                'Options' => [
                    'apply' => 'Aktionspreis übernehmen',
                    'regular' => 'Regulären Preis (Verlängerung) als Basis verwenden',
                    'skip' => 'Betroffene Preise nicht ändern',
                ],
                'Default' => 'regular',
                'Description' => 'Umgang mit SKRIME-Angeboten (offer)',
            ],
            'marginPercent' => [
                'FriendlyName' => 'Preis: Aufschlag %',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '0',
                'Description' => 'Prozentualer Aufschlag auf den SKRIME-Einkaufspreis',
            ],
            'marginFixed' => [
                'FriendlyName' => 'Preis: Aufschlag fix',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '0',
                'Description' => 'Fester Aufschlag in EUR pro Jahr',
            ],
            'marginRegister' => [
                'FriendlyName' => 'Preis: Aufschlag % Registrierung',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '',
                'Description' => 'Leer = allgemeiner Aufschlag',
            ],
            'marginTransfer' => [
                'FriendlyName' => 'Preis: Aufschlag % Transfer',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '',
                'Description' => 'Leer = allgemeiner Aufschlag',
            ],
            'marginRenew' => [
                'FriendlyName' => 'Preis: Aufschlag % Verlängerung',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '',
                'Description' => 'Leer = allgemeiner Aufschlag',
            ],
            'minProfit' => [
                'FriendlyName' => 'Preis: Mindestgewinn',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '0',
                'Description' => 'EUR pro Jahr',
            ],
            'minPrice' => [
                'FriendlyName' => 'Preis: Mindestpreis',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '0',
                'Description' => 'EUR pro Jahr',
            ],
            'rounding' => [
                'FriendlyName' => 'Preis: Rundung',
                'Type' => 'dropdown',
                'Options' => [
                    'none' => 'Keine (auf Cent)',
                    'up_99' => 'Auf ,99 aufrunden',
                    'up_49_99' => 'Auf ,49 oder ,99 aufrunden',
                    'up_x9' => 'Auf ,x9 aufrunden',
                    'up_50' => 'Auf ,00 oder ,50 aufrunden',
                    'up_whole' => 'Auf ganze Beträge aufrunden',
                    'nearest_whole' => 'Kaufmännisch auf ganze Beträge',
                ],
                'Default' => 'none',
                'Description' => 'Wird nach der Währungsumrechnung auf den Jahrespreis angewendet',
            ],
            'priceIncrease' => [
                'FriendlyName' => 'Preis: Erhöhungen übernehmen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '',
            ],
            'priceDecrease' => [
                'FriendlyName' => 'Preis: Senkungen übernehmen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '',
            ],
            'minChangePercent' => [
                'FriendlyName' => 'Preis: Mindeständerung %',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '0',
                'Description' => 'Kleinere Abweichungen werden ignoriert',
            ],
            'currencyMode' => [
                'FriendlyName' => 'Preis: Währungen',
                'Type' => 'dropdown',
                'Options' => [
                    'all' => 'Alle Währungen (Umrechnung über WHMCS-Wechselkurs)',
                    'eur' => 'Nur EUR',
                ],
                'Default' => 'all',
                'Description' => '',
            ],
            'currencyFallback' => [
                'FriendlyName' => 'Preis: Standardwährung als EUR',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Standardwährung als EUR behandeln, wenn keine EUR-Währung angelegt ist',
            ],
            'domainSyncEnabled' => [
                'FriendlyName' => 'Produkt-Sync: Aktiv',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Täglicher Abgleich jeder SKRIME-Domain über product/single bzw. product/all: Einkaufspreis, Ablaufdatum, Status und Auto-Verlängerung. Jede Änderung wird protokolliert und im Domain-Tab, im Dashboard-Widget und per E-Mail gemeldet',
            ],
            'domainPriceUpdate' => [
                'FriendlyName' => 'Produkt-Sync: Verkaufspreise anpassen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Ändert sich der Einkaufspreis eines Produkts, wird der Verlängerungspreis der Domain angepasst. Ohne Haken wird die Änderung nur gemeldet. Domains mit Preis-Modus "Fester Preis" werden nie geändert',
            ],
            'domainMarginMode' => [
                'FriendlyName' => 'Produkt-Sync: Marge',
                'Type' => 'dropdown',
                'Options' => [
                    'keep_amount' => 'Bisherige Marge beibehalten (Betrag)',
                    'keep_percent' => 'Bisherige Marge beibehalten (Prozent)',
                    'calculate' => 'Neu berechnen mit Aufschlag und Rundung aus diesen Einstellungen',
                ],
                'Default' => 'keep_amount',
                'Description' => 'Marge = Verkaufspreis − Einkaufspreis. Sie wird bei jedem Abgleich je Domain gespeichert und bei einer Preisänderung auf den neuen Einkaufspreis aufgeschlagen',
            ],
            'domainSyncSource' => [
                'FriendlyName' => 'Produkt-Sync: Einkaufspreis',
                'Type' => 'dropdown',
                'Options' => [
                    'product' => 'Preis des SKRIME-Produkts (product/single), empfohlen',
                    'product_pricelist' => 'Preis des SKRIME-Produkts, sonst SKRIME-Preisliste',
                    'pricelist' => 'SKRIME-Preisliste (Verlängerung)',
                    'whmcs' => 'WHMCS-TLD-Verlängerungspreis',
                ],
                'Default' => 'product',
                'Description' => 'Der Produktpreis gilt für eine Verlängerung um 12 Monate und kann von der Preisliste abweichen',
            ],
            'syncExpiryToWhmcs' => [
                'FriendlyName' => 'Produkt-Sync: Ablaufdatum übernehmen',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Abweichende Ablaufdaten von SKRIME automatisch in WHMCS eintragen. Ohne Haken wird die Abweichung nur gemeldet',
            ],
            'domainSyncTrigger' => [
                'FriendlyName' => 'Produkt-Sync: Auslöser',
                'Type' => 'dropdown',
                'Options' => $trigger,
                'Default' => 'change',
                'Description' => '',
            ],
            'domainSyncInitial' => [
                'FriendlyName' => 'Produkt-Sync: Beim ersten Lauf anwenden',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Nur bei Marge "Neu berechnen": Preise beim ersten Abgleich sofort berechnen. Ohne Haken wird zuerst nur der aktuelle Einkaufspreis gespeichert',
            ],
            'domainSyncInterval' => [
                'FriendlyName' => 'Produkt-Sync: Intervall',
                'Type' => 'dropdown',
                'Options' => self::intervalOptions(),
                'Default' => '24',
                'Description' => '',
            ],
            'domainSyncOnSync' => [
                'FriendlyName' => 'Produkt-Sync: Beim WHMCS Domain-Sync',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Produkt zusätzlich bei jedem WHMCS Domain-Sync der Domain abgleichen',
            ],
            'domainSyncBulk' => [
                'FriendlyName' => 'Produkt-Sync: Sammelabfrage',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Produktdaten über product/all statt einzeln über product/single abrufen',
            ],
            'domainSyncBatch' => [
                'FriendlyName' => 'Produkt-Sync: Domains pro Lauf',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '100',
                'Description' => 'Pro Cron-Lauf verarbeitete Domains',
            ],
            'domainSyncPriceField' => [
                'FriendlyName' => 'Produkt-Sync: Produktpreis',
                'Type' => 'dropdown',
                'Options' => [
                    'price' => 'Preis nach Rabatt (price)',
                    'priceBeforeDiscount' => 'Preis vor Rabatt (priceBeforeDiscount)',
                ],
                'Default' => 'price',
                'Description' => 'Feld des SKRIME-Produkts, das als Einkaufspreis für 12 Monate gilt',
            ],
            'domainMargin' => [
                'FriendlyName' => 'Produkt-Sync: Aufschlag %',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '',
                'Description' => 'Nur bei Marge "Neu berechnen". Leer = Aufschlag Verlängerung',
            ],
            'domainKeepAddons' => [
                'FriendlyName' => 'Produkt-Sync: Addons einrechnen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'DNS-Management, E-Mail-Weiterleitung und ID-Protection im wiederkehrenden Betrag berücksichtigen',
            ],
            'domainStatuses' => [
                'FriendlyName' => 'Produkt-Sync: Status',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'Active',
                'Description' => 'Kommagetrennt, z. B. Active,Grace',
            ],
            'domainSkipPromo' => [
                'FriendlyName' => 'Produkt-Sync: Promo-Domains überspringen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '',
            ],
            'domainSkipFree' => [
                'FriendlyName' => 'Produkt-Sync: Kostenlose Domains überspringen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Domains mit wiederkehrendem Betrag 0',
            ],
            'domainSkipPremium' => [
                'FriendlyName' => 'Produkt-Sync: Premium-Domains überspringen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '',
            ],
            'domainSkipClientGroups' => [
                'FriendlyName' => 'Produkt-Sync: Kundengruppen-Preise schützen',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Domains von Kunden überspringen, deren Kundengruppe eigene TLD-Preise hat (bei Preisquelle WHMCS wird der Gruppenpreis verwendet)',
            ],
            'domainSkipDoNotRenew' => [
                'FriendlyName' => 'Produkt-Sync: "Nicht verlängern" überspringen',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => '',
            ],
            'notifyAdmin' => [
                'FriendlyName' => 'Benachrichtigung: Admin',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Zusammenfassung der Preisänderungen per E-Mail an die Administratoren',
            ],
            'notifyClientTemplate' => [
                'FriendlyName' => 'Benachrichtigung: Kunden-Vorlage',
                'Type' => 'text',
                'Size' => '40',
                'Default' => '',
                'Description' => 'Name einer Domain-E-Mail-Vorlage. Variablen: {$skrime_old_price}, {$skrime_new_price}, {$skrime_currency}. Leer = aus',
            ],
            'notifyClientIncreaseOnly' => [
                'FriendlyName' => 'Benachrichtigung: Nur bei Erhöhung',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Kunden nur bei Preiserhöhungen benachrichtigen',
            ],
            'logRetentionDays' => [
                'FriendlyName' => 'Protokoll: Aufbewahrung',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '365',
                'Description' => 'Tage',
            ],
            'widgetEnabled' => [
                'FriendlyName' => 'Dashboard-Widget',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'SKRIME-Widget im Admin-Dashboard anzeigen',
            ],
        ];
    }

    public static function configArray()
    {
        return array_merge(
            [
                'FriendlyName' => [
                    'Type' => 'System',
                    'Value' => 'SKRIME',
                ],
                'Description' => [
                    'Type' => 'System',
                    'Value' => 'SKRIME Domain-Registrar v' . self::VERSION,
                ],
            ],
            self::definitions()
        );
    }

    public function all()
    {
        return $this->values;
    }

    public function raw($key)
    {
        if (array_key_exists($key, $this->values) && $this->values[$key] !== null) {
            return $this->values[$key];
        }
        $definitions = self::definitions();

        return isset($definitions[$key]['Default']) ? $definitions[$key]['Default'] : '';
    }

    public function string($key)
    {
        $value = $this->raw($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function bool($key)
    {
        $value = $this->raw($key);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['on', 'yes', '1', 'true'], true);
    }

    public function int($key, $min = null, $max = null)
    {
        $value = (int) $this->number($this->string($key), 0);
        if ($min !== null && $value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    public function float($key, $default = 0.0)
    {
        return $this->number($this->string($key), $default);
    }

    public function floatOrNull($key)
    {
        $value = $this->string($key);
        if ($value === '') {
            return null;
        }

        return $this->number($value, null);
    }

    public function choice($key)
    {
        $value = $this->string($key);
        $definitions = self::definitions();
        if (isset($definitions[$key]['Options']) && is_array($definitions[$key]['Options'])
            && !array_key_exists($value, $definitions[$key]['Options'])) {
            return (string) $definitions[$key]['Default'];
        }

        return $value;
    }

    public function csv($key, $lowercase = true)
    {
        $items = [];
        foreach (preg_split('/[\s,;]+/', $this->string($key)) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $items[] = $lowercase ? strtolower($item) : $item;
        }

        return array_values(array_unique($items));
    }

    public function tldList($key)
    {
        $list = [];
        foreach ($this->csv($key) as $tld) {
            $list[] = ltrim($tld, '.');
        }

        return array_values(array_unique($list));
    }

    private function number($value, $default)
    {
        $value = str_replace(' ', '', (string) $value);
        if (strpos($value, ',') !== false && strpos($value, '.') === false) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : $default;
    }
}
