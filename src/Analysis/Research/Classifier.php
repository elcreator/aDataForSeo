<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

/**
 * keyword → place, deal, type, brand.
 *
 * Place spellings come from the project's markets (`geo[].spellings`, any
 * language, matched with case endings tolerated); deal and type stems from the
 * vertical's lexicon in the keyword's language, with English as a fallback
 * because mixed queries ("apartment praha") are common.
 */
final class Classifier
{
    public function __construct(private readonly Lexicon $lexicon, private readonly array $brandTerms = [])
    {
    }

    /** @return array{geo: string, deal: string, type: string, brand: bool} */
    public function classify(string $keyword, array $market, string $lang): array
    {
        $tokens = Text::tokens($keyword, $this->lexicon->stop($lang));

        return [
            'geo' => $this->geo($tokens, $market),
            'deal' => $this->match($tokens, $lang, 'deal'),
            'type' => $this->match($tokens, $lang, 'type') ?: 'any',
            'brand' => $this->brand($tokens),
        ];
    }

    public function geo(array $tokens, array $market): string
    {
        $best = '';
        $bestLength = 0;

        foreach ((array) ($market['geo'] ?? []) as $geo) {
            $name = (string) ($geo['name'] ?? '');
            $spellings = [$name];

            foreach ((array) ($geo['spellings'] ?? []) as $words) {
                foreach ((array) $words as $word) {
                    $spellings[] = (string) $word;
                }
            }

            foreach ($spellings as $spelling) {
                $words = Text::tokens($spelling);

                if ($words === [] || count($words) > count($tokens)) {
                    continue;
                }

                $all = true;

                foreach ($words as $word) {
                    $hit = false;

                    foreach ($tokens as $token) {
                        if (Text::sameWord($token, $word)) {
                            $hit = true;
                            break;
                        }
                    }

                    $all = $all && $hit;
                }

                if ($all && mb_strlen($spelling) > $bestLength) {
                    $best = $name;
                    $bestLength = mb_strlen($spelling);
                }
            }
        }

        return $best;
    }

    private function match(array $tokens, string $lang, string $what): string
    {
        foreach (array_unique([$lang, 'en']) as $language) {
            $stems = $what === 'deal' ? $this->lexicon->dealStems($language) : $this->lexicon->typeStems($language);

            foreach ($stems as $value => $list) {
                foreach ((array) $list as $stem) {
                    if (Text::hasStem($tokens, (string) $stem)) {
                        return (string) $value;
                    }
                }
            }
        }

        return '';
    }

    private function brand(array $tokens): bool
    {
        foreach ($this->brandTerms as $term) {
            $term = Text::fold((string) $term);

            if ($term !== '' && in_array($term, $tokens, true)) {
                return true;
            }
        }

        return false;
    }
}
