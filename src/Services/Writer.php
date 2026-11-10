<?php

namespace Elcreator\aDataForSeo\Services;

use Elcreator\aDataForSeo\Analysis\Research\Lexicon;
use Elcreator\aDataForSeo\Models\Target;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Title and description suggestions, `template` driver: no AI, only the
 * document's own words or the target's real geo/deal/type through the
 * vertical's per-language patterns. Suggestions are never applied without a
 * manager's click.
 */
final class Writer
{
    public const TITLE_MAX = 60;
    public const DESCRIPTION_MAX = 155;

    /** @return array{pagetitle: string, longtitle: string, description: string, introtext: string, content: string, alias: string, parent: int, published: int}|null */
    public static function document(int $id): ?array
    {
        try {
            $row = DB::table('site_content')->where('id', $id)->first(['pagetitle', 'longtitle', 'description', 'introtext', 'content', 'alias', 'parent', 'published']);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : [
            'pagetitle' => (string) $row->pagetitle,
            'longtitle' => (string) $row->longtitle,
            'description' => (string) $row->description,
            'introtext' => (string) $row->introtext,
            'content' => (string) $row->content,
            'alias' => (string) $row->alias,
            'parent' => (int) $row->parent,
            'published' => (int) $row->published,
        ];
    }

    public static function siteName(): string
    {
        try {
            return trim((string) evo()->getConfig('site_name', ''));
        } catch (Throwable) {
            return '';
        }
    }

    /** A unique, readable title from the document's own titles, with the site name when there is room. */
    public static function title(array $document, string $siteName = ''): string
    {
        $base = trim($document['longtitle'] ?? '') !== '' ? trim($document['longtitle']) : trim($document['pagetitle'] ?? '');

        if ($base === '') {
            return '';
        }

        if ($siteName !== '' && mb_stripos($base, $siteName) === false && mb_strlen($base) + mb_strlen($siteName) + 3 <= self::TITLE_MAX) {
            $base .= ' | ' . $siteName;
        }

        return self::cut($base, self::TITLE_MAX);
    }

    public static function description(array $document): string
    {
        foreach (['description', 'introtext', 'content'] as $field) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($document[$field] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            // CMS tags ([[snippet]], {{chunk}}, [*tv*]) are not prose.
            $text = trim((string) preg_replace('/\[\[.*?\]\]|\{\{.*?\}\}|\[\*.*?\*\]|\[\(.*?\)\]|@[A-Z_]+\b/u', '', $text));

            if (mb_strlen($text) >= 40) {
                return self::cut($text, self::DESCRIPTION_MAX);
            }
        }

        return '';
    }

    public static function targetTitle(Target $target, Lexicon $lexicon, string $geo): string
    {
        return self::cut(self::fill($lexicon->word((string) $target->lang, 'title', '{Type} {deal} {Geo}'), $target, $lexicon, $geo), self::TITLE_MAX);
    }

    public static function targetDescription(Target $target, Lexicon $lexicon, string $geo): string
    {
        return self::cut(self::fill($lexicon->word((string) $target->lang, 'description', '{Type} {deal} {Geo}'), $target, $lexicon, $geo), self::DESCRIPTION_MAX);
    }

    private static function fill(string $pattern, Target $target, Lexicon $lexicon, string $geo): string
    {
        $lang = (string) $target->lang;
        $type = $lexicon->word($lang, 'type.' . ($target->type ?: 'any'));
        $deal = $target->deal !== '' ? $lexicon->word($lang, 'deal.' . $target->deal) : '';
        $text = strtr($pattern, [
            '{Type}' => self::ucfirst($type), '{type}' => $type,
            '{Deal}' => self::ucfirst($deal), '{deal}' => $deal,
            '{Geo}' => self::ucfirst($geo), '{geo}' => $geo,
        ]);

        return trim((string) preg_replace(['/\s+/u', '/\s+([:,.|])/u', '/\b(in|v|w|в)\s*$/u'], [' ', '$1', ''], $text));
    }

    public static function ucfirst(string $text): string
    {
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Cut on a word boundary. */
    public static function cut(string $text, int $max): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-|") . '…';
    }
}
