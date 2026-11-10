<?php

namespace Elcreator\aDataForSeo\Analysis\Research;

/**
 * Text plumbing for keywords: folding, tokens, language guesses.
 *
 * Folding lower-cases and drops diacritics ("pronájem" = "pronajem", "ё" =
 * "е"), which is what makes spelling variants land in one token bag.
 */
final class Text
{
    private const LATIN = [
        'á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ą' => 'a', 'ã' => 'a', 'å' => 'a', 'č' => 'c', 'ć' => 'c', 'ç' => 'c',
        'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'è' => 'e', 'ê' => 'e', 'ę' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ĺ' => 'l', 'ľ' => 'l', 'ł' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ò' => 'o',
        'õ' => 'o', 'ő' => 'o', 'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ß' => 'ss', 'ť' => 't', 'ú' => 'u', 'ů' => 'u',
        'ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ё' => 'е',
    ];

    /** Letters that give a language away. */
    private const MARKERS = [
        'uk' => '/[іїєґ]/u',
        'ru' => '/[ыэъ]/u',
        'cs' => '/[řěů]/u',
        'sk' => '/[ľĺŕô]/u',
        'pl' => '/[łąęśźżńć]/u',
        'de' => '/[ßöü]/u',
    ];

    private const CYRILLIC = ['uk', 'ru', 'be', 'bg', 'sr', 'mk'];

    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return strtr($text, self::LATIN);
    }

    /** @return string[] folded tokens without stop words */
    public static function tokens(string $text, array $stop = []): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = array_map([self::class, 'fold'], $stop);

        return array_values(array_filter($parts, static fn ($t) => !in_array($t, $stop, true)));
    }

    /** Order-free identity of a phrase: "apartments rent prague" = "prague apartments for rent". */
    public static function bag(string $text, array $stop = []): string
    {
        $tokens = array_unique(self::tokens($text, $stop));
        sort($tokens);

        return implode(' ', $tokens);
    }

    public static function script(string $text): string
    {
        if (preg_match('/\p{Cyrillic}/u', $text)) {
            return 'cyrillic';
        }

        return preg_match('/\p{Latin}/u', $text) ? 'latin' : 'other';
    }

    /** Does a (possibly multi-word) stem occur among the tokens? Each word must start a token. */
    public static function hasStem(array $tokens, string $stem): bool
    {
        foreach (self::tokens($stem) as $word) {
            $hit = false;

            foreach ($tokens as $token) {
                if (str_starts_with($token, $word)) {
                    $hit = true;
                    break;
                }
            }

            if (!$hit) {
                return false;
            }
        }

        return true;
    }

    /** Close enough for a place name in another case ("варшава" / "варшаві", "praha" / "praze" is not). */
    public static function sameWord(string $token, string $word): bool
    {
        $token = self::fold($token);
        $word = self::fold($word);

        if ($token === $word) {
            return true;
        }

        $length = mb_strlen($word);

        if ($length < 4) {
            return false;
        }

        $stem = mb_substr($word, 0, max(4, $length - 2));

        return str_starts_with($token, $stem);
    }

    /**
     * The language of a query or a title, among the candidates.
     *
     * Marker letters decide first; otherwise vocabulary from the lexicon
     * (a diacritics-free "byty praha" is still Czech); otherwise the script.
     * Null when nothing fits.
     */
    public static function detectLang(string $text, array $candidates = [], ?Lexicon $lexicon = null): ?string
    {
        $raw = mb_strtolower($text);
        $script = self::script($raw);

        foreach (self::MARKERS as $lang => $pattern) {
            if (preg_match($pattern, $raw) && ($candidates === [] || in_array($lang, $candidates, true))) {
                return $lang;
            }
        }

        $pool = $candidates !== [] ? $candidates : ($lexicon?->languages() ?? []);
        $pool = array_values(array_filter($pool, static fn ($l) => ($script === 'cyrillic') === in_array($l, self::CYRILLIC, true)));

        if ($lexicon !== null && $pool !== []) {
            $tokens = self::tokens($raw);
            $best = null;
            $bestScore = 0;

            foreach ($pool as $lang) {
                $score = $lexicon->vocabularyScore($tokens, $lang);

                if ($score > $bestScore) {
                    $best = $lang;
                    $bestScore = $score;
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        if ($script === 'cyrillic') {
            return $pool[0] ?? 'ru';
        }

        if ($script === 'latin') {
            return in_array('en', $pool, true) || $pool === [] ? 'en' : $pool[0];
        }

        return null;
    }
}
