<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

/**
 * Close variants that are one demand, counted once.
 *
 * Union-find inside one target page. Two keywords share a cluster when
 *  (a) Labs gives the same `core_keyword`, or
 *  (b) Google Ads reports the same volume and the same 12-month vector
 *      (volume ≥ 50, vector not constant): Ads buckets close variants —
 *      13 spellings of "apartments for rent prague" each reported 2400,
 *      real demand 2400, naive sum 33,100 — or
 *  (c) the same token bag (folded, stop words removed, order ignored).
 * One keyword per cluster counts: the highest volume. Different volumes or
 * vectors are different demand and are summed.
 */
final class Clusters
{
    public const MIN_BUCKET_VOLUME = 50;

    /** @var array<int, int> */
    private array $parent = [];

    /**
     * @param array<int, array{id: int, keyword: string, volume: ?int, monthly?: ?array, core_keyword?: ?string, stop?: array}> $keywords
     * @return array<int, int> keyword id => id of its cluster's counted keyword
     */
    public function build(array $keywords, array $stop = []): array
    {
        $this->parent = [];
        $byKey = [];

        foreach ($keywords as $keyword) {
            $id = (int) $keyword['id'];
            $this->parent[$id] = $id;

            foreach (self::keys($keyword, $stop) as $key) {
                if (isset($byKey[$key])) {
                    $this->union($byKey[$key], $id);
                } else {
                    $byKey[$key] = $id;
                }
            }
        }

        $groups = [];

        foreach ($keywords as $keyword) {
            $groups[$this->find((int) $keyword['id'])][] = $keyword;
        }

        $representative = [];

        foreach ($groups as $members) {
            usort($members, static fn ($a, $b) => [(int) ($b['volume'] ?? -1), mb_strlen($a['keyword'])] <=> [(int) ($a['volume'] ?? -1), mb_strlen($b['keyword'])]);
            $head = (int) $members[0]['id'];

            foreach ($members as $member) {
                $representative[(int) $member['id']] = $head;
            }
        }

        return $representative;
    }

    /** The identities a keyword can share with another. */
    public static function keys(array $keyword, array $stop = []): array
    {
        $keys = [];
        $core = trim((string) ($keyword['core_keyword'] ?? ''));

        if ($core !== '') {
            $keys[] = 'core:' . Text::fold($core);
        }

        $bucket = self::bucket($keyword);

        if ($bucket !== null) {
            $keys[] = $bucket;
        }

        $bag = Text::bag((string) $keyword['keyword'], $stop);

        if ($bag !== '') {
            $keys[] = 'bag:' . $bag;
        }

        return $keys;
    }

    /** The Ads bucket: same volume and same 12-month vector, when that is evidence at all. */
    public static function bucket(array $keyword): ?string
    {
        $volume = $keyword['volume'] ?? null;
        $monthly = array_values(array_map('intval', (array) ($keyword['monthly'] ?? [])));

        if ($volume === null || (int) $volume < self::MIN_BUCKET_VOLUME || count($monthly) < 12 || count(array_unique($monthly)) === 1) {
            return null;
        }

        return 'ads:' . (int) $volume . ':' . implode(',', array_slice($monthly, -12));
    }

    private function find(int $id): int
    {
        while ($this->parent[$id] !== $id) {
            $this->parent[$id] = $this->parent[$this->parent[$id]];
            $id = $this->parent[$id];
        }

        return $id;
    }

    private function union(int $a, int $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);

        if ($ra !== $rb) {
            $this->parent[$rb] = $ra;
        }
    }
}
