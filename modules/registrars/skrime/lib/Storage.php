<?php

namespace WHMCS\Module\Registrar\Skrime;

use WHMCS\Database\Capsule;

class Storage
{
    const TABLE_KV = 'mod_skrime_kv';
    const TABLE_TLD = 'mod_skrime_tld_prices';
    const TABLE_DOMAIN = 'mod_skrime_domain_prices';
    const TABLE_LOG = 'mod_skrime_price_log';

    private static $schemaReady = false;

    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE_KV)) {
            $schema->create(self::TABLE_KV, function ($table) {
                $table->string('name', 191)->primary();
                $table->text('value')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::TABLE_TLD)) {
            $schema->create(self::TABLE_TLD, function ($table) {
                $table->string('tld', 191)->primary();
                $table->decimal('register', 12, 4)->nullable();
                $table->decimal('transfer', 12, 4)->nullable();
                $table->decimal('renew', 12, 4)->nullable();
                $table->decimal('restore', 12, 4)->nullable();
                $table->boolean('offer')->default(false);
                $table->string('offer_types', 191)->default('');
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::TABLE_DOMAIN)) {
            $schema->create(self::TABLE_DOMAIN, function ($table) {
                $table->unsignedInteger('domain_id')->primary();
                $table->string('domain', 191)->default('');
                $table->string('product_id', 64)->default('');
                $table->string('basis_source', 32)->default('');
                $table->decimal('basis', 12, 4)->nullable();
                $table->decimal('product_price', 12, 4)->nullable();
                $table->unsignedInteger('product_duration')->nullable();
                $table->boolean('auto_renew')->nullable();
                $table->dateTime('expire_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::TABLE_LOG)) {
            $schema->create(self::TABLE_LOG, function ($table) {
                $table->increments('id');
                $table->string('type', 16);
                $table->string('reference', 191);
                $table->string('detail', 191)->default('');
                $table->string('currency', 8)->default('');
                $table->decimal('old_price', 12, 2)->nullable();
                $table->decimal('new_price', 12, 2)->nullable();
                $table->decimal('old_cost', 12, 4)->nullable();
                $table->decimal('new_cost', 12, 4)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index('created_at');
            });
        }

        self::$schemaReady = true;
    }

    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    public static function get($name, $default = null)
    {
        self::ensureSchema();
        $value = Capsule::table(self::TABLE_KV)->where('name', $name)->value('value');

        return $value === null ? $default : $value;
    }

    public static function set($name, $value)
    {
        self::ensureSchema();
        $exists = Capsule::table(self::TABLE_KV)->where('name', $name)->exists();
        $data = ['value' => (string) $value, 'updated_at' => self::now()];
        if ($exists) {
            Capsule::table(self::TABLE_KV)->where('name', $name)->update($data);
        } else {
            Capsule::table(self::TABLE_KV)->insert(['name' => $name] + $data);
        }
    }

    public static function acquireLock($name, $ttl)
    {
        self::ensureSchema();
        $key = 'lock_' . $name;
        $now = time();
        try {
            Capsule::table(self::TABLE_KV)->insert(['name' => $key, 'value' => (string) $now, 'updated_at' => self::now()]);

            return true;
        } catch (\Throwable $e) {
            $affected = Capsule::table(self::TABLE_KV)
                ->where('name', $key)
                ->where(function ($query) use ($now, $ttl) {
                    $query->whereNull('value')
                        ->orWhere('value', '')
                        ->orWhere('value', '<', (string) ($now - $ttl));
                })
                ->update(['value' => (string) $now, 'updated_at' => self::now()]);

            return $affected > 0;
        }
    }

    public static function releaseLock($name)
    {
        self::ensureSchema();
        Capsule::table(self::TABLE_KV)->where('name', 'lock_' . $name)->update(['value' => '', 'updated_at' => self::now()]);
    }

    public static function isDue($name, $hours)
    {
        $last = (int) self::get($name, 0);

        return $last <= 0 || time() - $last >= max(1, (int) $hours) * 3600 - 300;
    }

    public static function tldPrice($tld)
    {
        self::ensureSchema();
        $row = Capsule::table(self::TABLE_TLD)->where('tld', $tld)->first();

        return $row ? (array) $row : null;
    }

    public static function saveTldPrice($tld, array $prices, $offer, array $offerTypes)
    {
        self::ensureSchema();
        $data = [
            'register' => $prices['register'],
            'transfer' => $prices['transfer'],
            'renew' => $prices['renew'],
            'restore' => $prices['restore'],
            'offer' => $offer ? 1 : 0,
            'offer_types' => substr(implode(',', $offerTypes), 0, 191),
            'updated_at' => self::now(),
        ];
        if (Capsule::table(self::TABLE_TLD)->where('tld', $tld)->exists()) {
            Capsule::table(self::TABLE_TLD)->where('tld', $tld)->update($data);
        } else {
            Capsule::table(self::TABLE_TLD)->insert(['tld' => $tld] + $data);
        }
    }

    public static function domainState($domainId)
    {
        self::ensureSchema();
        $row = Capsule::table(self::TABLE_DOMAIN)->where('domain_id', (int) $domainId)->first();

        return $row ? (array) $row : null;
    }

    public static function saveDomainState($domainId, array $data)
    {
        self::ensureSchema();
        $data['updated_at'] = self::now();
        if (Capsule::table(self::TABLE_DOMAIN)->where('domain_id', (int) $domainId)->exists()) {
            Capsule::table(self::TABLE_DOMAIN)->where('domain_id', (int) $domainId)->update($data);
        } else {
            Capsule::table(self::TABLE_DOMAIN)->insert(['domain_id' => (int) $domainId] + $data);
        }
    }

    public static function rememberProduct($domainId, $domain, $product)
    {
        if (!is_array($product) || (int) $domainId <= 0) {
            return;
        }
        $data = [
            'domain' => substr((string) $domain, 0, 191),
            'product_id' => isset($product['id']) && is_scalar($product['id']) ? substr((string) $product['id'], 0, 64) : '',
            'product_price' => isset($product['price']) && is_numeric($product['price']) ? (float) $product['price'] : null,
            'product_duration' => isset($product['duration']) && is_numeric($product['duration']) ? (int) $product['duration'] : null,
            'auto_renew' => isset($product['autoRenew']) ? ($product['autoRenew'] ? 1 : 0) : null,
            'expire_at' => isset($product['expireAt']) && Helper::toDate($product['expireAt']) ? date('Y-m-d H:i:s', strtotime($product['expireAt'])) : null,
        ];
        self::saveDomainState($domainId, $data);
    }

    public static function log($type, $reference, $detail, $currency, $oldPrice, $newPrice, $oldCost = null, $newCost = null)
    {
        self::ensureSchema();
        Capsule::table(self::TABLE_LOG)->insert([
            'type' => substr((string) $type, 0, 16),
            'reference' => substr((string) $reference, 0, 191),
            'detail' => substr((string) $detail, 0, 191),
            'currency' => substr((string) $currency, 0, 8),
            'old_price' => $oldPrice === null ? null : round((float) $oldPrice, 2),
            'new_price' => $newPrice === null ? null : round((float) $newPrice, 2),
            'old_cost' => $oldCost === null ? null : round((float) $oldCost, 4),
            'new_cost' => $newCost === null ? null : round((float) $newCost, 4),
            'created_at' => self::now(),
        ]);
    }

    public static function recentLog($limit = 10)
    {
        self::ensureSchema();

        return Capsule::table(self::TABLE_LOG)->orderBy('id', 'desc')->limit((int) $limit)->get();
    }

    public static function countLogSince($datetime)
    {
        self::ensureSchema();

        return (int) Capsule::table(self::TABLE_LOG)->where('created_at', '>=', $datetime)->count();
    }

    public static function pruneLog($days)
    {
        $days = (int) $days;
        if ($days <= 0) {
            return;
        }
        self::ensureSchema();
        Capsule::table(self::TABLE_LOG)->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))->delete();
    }
}
