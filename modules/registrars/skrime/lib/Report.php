<?php

namespace WHMCS\Module\Registrar\Skrime;

class Report
{
    private $title;
    private $changes = [];
    private $errors = [];
    private $counters = [];

    public function __construct($title)
    {
        $this->title = $title;
    }

    public function change($reference, $detail, $currency, $old, $new)
    {
        $this->changes[] = [
            'reference' => (string) $reference,
            'detail' => (string) $detail,
            'currency' => (string) $currency,
            'old' => $old === null ? null : (float) $old,
            'new' => (float) $new,
        ];
    }

    public function error($message)
    {
        $this->errors[] = (string) $message;
    }

    public function count($name, $step = 1)
    {
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = 0;
        }
        $this->counters[$name] += $step;
    }

    public function counter($name)
    {
        return isset($this->counters[$name]) ? $this->counters[$name] : 0;
    }

    public function changes()
    {
        return $this->changes;
    }

    public function errors()
    {
        return $this->errors;
    }

    public function summary()
    {
        $parts = [count($this->changes) . ' Preisänderung(en)'];
        foreach ($this->counters as $name => $value) {
            $parts[] = $name . ': ' . $value;
        }
        if (!empty($this->errors)) {
            $parts[] = count($this->errors) . ' Fehler';
        }

        return $this->title . ' - ' . implode(', ', $parts);
    }

    public function finish()
    {
        if (!function_exists('logActivity') || (empty($this->changes) && empty($this->errors))) {
            return;
        }
        logActivity('SKRIME ' . $this->summary());
        foreach (array_slice($this->errors, 0, 20) as $error) {
            logActivity('SKRIME ' . $this->title . ' Fehler: ' . $error);
        }
    }
}
