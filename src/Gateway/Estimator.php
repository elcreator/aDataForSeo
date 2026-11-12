<?php

namespace Elcreator\aDataForSeo\Gateway;

use Elcreator\aDataForSeo\Api\Estimate;

/**
 * Prices a gateway call by asking the gateway (`quote: true`: free, runs nothing).
 *
 * Same contract as the DataForSEO estimator: a range when the cost depends on what the
 * answer holds (an audit between one page and `maxPages`), `null` when the gateway's price
 * table cannot price it. Unknown is never zero: it needs a manager's approval.
 * Amounts are euros.
 */
final class Estimator
{
    public function __construct(private readonly Client $client)
    {
    }

    public function quote(string $op, array $body): Estimate
    {
        $quote = $this->client->quote($op, $body);

        if ($quote['low'] === null || $quote['high'] === null) {
            return Estimate::unknown('gateway_quote');
        }

        $low = min($quote['low'], $quote['high']);
        $high = max($quote['low'], $quote['high']);

        // `amount` is the bottom of the range so the page shows "low – high"; budgets read the top.
        return new Estimate(round($low, 6), round($low, 6), round($high, 6), 'gateway_quote', $low === $high);
    }
}
