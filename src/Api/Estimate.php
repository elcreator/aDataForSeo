<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * What a call, a step or a whole job is expected to cost, in US dollars.
 *
 * `amount` null means unknown — no price, or an item count nobody can know in
 * advance — and is never rendered or treated as zero: an unknown estimate
 * always needs a manager's approval. `low`/`high` carry a range calibrated from
 * the ledger when the item count varies.
 */
final class Estimate
{
    public function __construct(
        public readonly ?float $amount,
        public readonly ?float $low = null,
        public readonly ?float $high = null,
        public readonly string $basis = 'price',
        public readonly bool $exact = false
    ) {
    }

    public static function free(): self
    {
        return new self(0.0, 0.0, 0.0, 'free', true);
    }

    public static function unknown(string $basis = 'unknown'): self
    {
        return new self(null, null, null, $basis, false);
    }

    public static function exact(float $amount, string $basis = 'price'): self
    {
        $amount = round($amount, 6);

        return new self($amount, $amount, $amount, $basis, true);
    }

    public static function range(float $amount, float $low, float $high, string $basis = 'history'): self
    {
        return new self(round($amount, 6), round($low, 6), round($high, 6), $basis, false);
    }

    public function isUnknown(): bool
    {
        return $this->amount === null;
    }

    /** The figure budgets are checked against: the top of the range when there is one. */
    public function ceiling(): ?float
    {
        return $this->amount === null ? null : max($this->amount, (float) ($this->high ?? $this->amount));
    }

    /**
     * Fold several estimates into one. One unknown part makes the whole
     * unknown: a total that silently skips a step is worse than none.
     *
     * @param self[] $estimates
     */
    public static function sum(array $estimates): self
    {
        if ($estimates === []) {
            return self::free();
        }

        $amount = $low = $high = 0.0;
        $exact = true;
        $bases = [];

        foreach ($estimates as $estimate) {
            if ($estimate->amount === null) {
                return self::unknown('partial');
            }

            $amount += $estimate->amount;
            $low += (float) ($estimate->low ?? $estimate->amount);
            $high += (float) ($estimate->high ?? $estimate->amount);
            $exact = $exact && $estimate->exact;
            $bases[$estimate->basis] = true;
        }

        $basis = count($bases) === 1 ? (string) array_key_first($bases) : 'mixed';

        return new self(round($amount, 6), round($low, 6), round($high, 6), $basis, $exact);
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'low' => $this->low,
            'high' => $this->high,
            'basis' => $this->basis,
            'exact' => $this->exact,
            'unknown' => $this->amount === null,
        ];
    }
}
