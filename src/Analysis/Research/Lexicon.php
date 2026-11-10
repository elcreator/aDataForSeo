<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

use Elcreator\aDataForSeo\Support\Config;

/**
 * The vertical's vocabulary (`config/lexicon/<vertical>.php`), per language.
 * Nothing outside that file assumes real estate.
 */
final class Lexicon
{
    private array $data;

    public function __construct(?string $vertical = null, ?array $data = null)
    {
        $this->data = $data ?? Config::lexicon($vertical);
    }

    /** @return string[] */
    public function languages(): array
    {
        return array_keys((array) ($this->data['languages'] ?? []));
    }

    public function has(string $lang): bool
    {
        return isset($this->data['languages'][$lang]);
    }

    /** @return string[] */
    public function deals(): array
    {
        return (array) ($this->data['deals'] ?? []);
    }

    /** @return string[] */
    public function types(): array
    {
        return (array) ($this->data['types'] ?? []);
    }

    /** @return array<string, string[]> deal => stems */
    public function dealStems(string $lang): array
    {
        return (array) ($this->data['languages'][$lang]['deal'] ?? []);
    }

    /** @return array<string, string[]> type => stems */
    public function typeStems(string $lang): array
    {
        return (array) ($this->data['languages'][$lang]['type'] ?? []);
    }

    /** @return string[] */
    public function stop(string $lang): array
    {
        return (array) ($this->data['languages'][$lang]['stop'] ?? []);
    }

    public function ambiguousDefault(string $type): string
    {
        return (string) ($this->data['ambiguous_default'][$type] ?? ($this->deals()[0] ?? ''));
    }

    /** A display word (`words.deal.rent`, `words.type.flat`, `words.title`). */
    public function word(string $lang, string $path, string $default = ''): string
    {
        $lang = $this->has($lang) ? $lang : 'en';

        return (string) data_get($this->data['languages'][$lang] ?? [], 'words.' . $path, $default);
    }

    /** @return array<string, string> type => nominal form used in research seeds */
    public function seedWords(string $lang): array
    {
        return (array) ($this->data['languages'][$lang]['seed'] ?? []);
    }

    public function slug(string $lang, string $path, string $default = ''): string
    {
        $lang = $this->has($lang) ? $lang : 'en';

        return (string) data_get($this->data['languages'][$lang] ?? [], 'slug.' . $path, $default);
    }

    /** How many tokens look like this language's vocabulary. */
    public function vocabularyScore(array $tokens, string $lang): int
    {
        $score = 0;

        foreach (array_merge(array_merge(...array_values($this->dealStems($lang) ?: [[]])), array_merge(...array_values($this->typeStems($lang) ?: [[]]))) as $stem) {
            if (!str_contains((string) $stem, ' ') && Text::hasStem($tokens, (string) $stem)) {
                $score++;
            }
        }

        return $score;
    }
}
