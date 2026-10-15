<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Registrar\Skrime\PriceCalculator;
use WHMCS\Module\Registrar\Skrime\Settings;

class PriceCalculatorTest extends TestCase
{
    private function calculator(array $settings)
    {
        return new PriceCalculator(new Settings($settings));
    }

    public function testRounding()
    {
        $cases = [
            ['none', 12.344, 12.34],
            ['up_99', 12.00, 12.99],
            ['up_99', 11.99, 11.99],
            ['up_99', 12.995, 13.99],
            ['up_49_99', 12.30, 12.49],
            ['up_49_99', 12.50, 12.99],
            ['up_x9', 12.34, 12.39],
            ['up_50', 12.01, 12.50],
            ['up_50', 12.00, 12.00],
            ['up_whole', 12.01, 13.00],
            ['nearest_whole', 12.49, 12.00],
            ['nearest_whole', 0.20, 1.00],
        ];

        foreach ($cases as $case) {
            $this->assertEqualsWithDelta($case[2], $this->calculator(['rounding' => $case[0]])->round($case[1]), 0.0001, $case[0] . ' ' . $case[1]);
        }
    }

    public function testMarkup()
    {
        $calculator = $this->calculator([
            'marginPercent' => '10',
            'marginFixed' => '1',
            'minProfit' => '5',
            'marginRenew' => '50',
            'minPrice' => '0',
        ]);

        $this->assertEqualsWithDelta(15.0, $calculator->markup(10, 'register'), 0.0001);
        $this->assertEqualsWithDelta(16.0, $calculator->markup(10, 'renew'), 0.0001);
        $this->assertEqualsWithDelta(16.0, $calculator->markup(10, 'domain'), 0.0001);

        $calculator = $this->calculator(['marginPercent' => '20', 'domainMargin' => '5', 'minPrice' => '9,90']);
        $this->assertEqualsWithDelta(9.9, $calculator->markup(2, 'domain'), 0.0001);
        $this->assertEqualsWithDelta(12.6, $calculator->markup(12, 'domain'), 0.0001);
    }

    public function testYearlyPriceConvertsAndRounds()
    {
        $calculator = $this->calculator(['marginPercent' => '20', 'rounding' => 'up_99']);
        $this->assertEqualsWithDelta(4.99, $calculator->yearlyPrice(3.5, 'register', ['factor' => 1.0]), 0.0001);
        $this->assertEqualsWithDelta(5.99, $calculator->yearlyPrice(4.5, 'register', ['factor' => 1.1]), 0.0001);
        $this->assertNull($calculator->yearlyPrice(4.5, 'register', ['factor' => null]));
    }

    public function testShouldApply()
    {
        $calculator = $this->calculator([]);
        $this->assertTrue($calculator->shouldApply(10, 11));
        $this->assertTrue($calculator->shouldApply(0, 11));
        $this->assertFalse($calculator->shouldApply(10, 10.001));

        $calculator = $this->calculator(['priceIncrease' => '', 'priceDecrease' => 'on']);
        $this->assertFalse($calculator->shouldApply(10, 11));
        $this->assertTrue($calculator->shouldApply(10, 9));

        $calculator = $this->calculator(['priceDecrease' => '']);
        $this->assertFalse($calculator->shouldApply(10, 9));

        $calculator = $this->calculator(['minChangePercent' => '5']);
        $this->assertFalse($calculator->shouldApply(10, 10.4));
        $this->assertTrue($calculator->shouldApply(10, 10.6));
    }
}
