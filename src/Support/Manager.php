<?php

namespace Elcreator\aDataForSeo\Support;

use Throwable;

/**
 * What the manager this plugin runs in offers for looks: Evolution 3.6+ has
 * evo-ui (shared shell, theme sync) and Tabler icons (`svg()` and the
 * `evo-icon` classes of `manager-icons.css`); 3.5 has Font Awesome under
 * `media/style/common/font-awesome`. Nothing here assumes either.
 */
final class Manager
{
    /** The icons the page uses: our name => Tabler name (full set through svg()). */
    public const ICONS = [
        'lightbulb-o' => 'bulb', 'tasks' => 'list-check', 'th' => 'table', 'key' => 'key', 'line-chart' => 'chart-line',
        'exclamation-triangle' => 'alert-triangle', 'money' => 'cash', 'terminal' => 'terminal-2', 'cog' => 'settings',
        'rocket' => 'rocket', 'check' => 'check', 'times' => 'x', 'play' => 'player-play', 'arrow-right' => 'arrow-right',
        'eye' => 'eye', 'stethoscope' => 'stethoscope', 'search' => 'search', 'signal' => 'chart-bar', 'users' => 'users',
        'file-o' => 'file-plus', 'magic' => 'wand', 'info-circle' => 'info-circle', 'eye-slash' => 'eye-off',
        'download' => 'download', 'refresh' => 'refresh', 'undo' => 'arrow-back-up', 'calculator' => 'calculator',
        'save' => 'device-floppy', 'hourglass-half' => 'hourglass', 'check-circle' => 'circle-check', 'user' => 'user',
    ];

    /** Test seam: renders one Tabler icon to SVG markup. */
    public static ?\Closure $svg = null;

    /** evo-ui is installed and its assets are published: the page can live in its shell. */
    public static function hasEvoUi(): bool
    {
        try {
            return class_exists('EvoUI\Support\ManagerContext')
                && function_exists('view') && view()->exists('evo::partials.assets');
        } catch (Throwable) {
            return false;
        }
    }

    /** 3.6+ ships the Tabler icon classes the menu and widgets use. */
    public static function hasEvoIcons(): bool
    {
        return is_file(self::mediaPath('style/common/icons/manager-icons.css'));
    }

    /** The menu/widget icon class for this manager. */
    public static function icon(string $tabler, string $fontAwesome): string
    {
        return self::hasEvoIcons() ? 'evo-icon evo-icon-' . $tabler : 'fa fa-' . $fontAwesome;
    }

    /** Font Awesome's stylesheet, only when this manager still ships it (3.5). */
    public static function fontAwesomeUrl(): ?string
    {
        $path = 'style/common/font-awesome/css/font-awesome.min.css';

        return is_file(self::mediaPath($path)) ? self::mediaUrl($path) : null;
    }

    /**
     * Inline SVG for every icon the page uses, when the Tabler set is
     * available; empty otherwise (the page then falls back to Font Awesome
     * classes, or to no icon).
     *
     * @return array<string, string> our name => SVG markup
     */
    public static function svgIcons(): array
    {
        $render = self::$svg ?? (function_exists('svg') ? static fn (string $name): string => (string) svg('tabler-' . $name)->toHtml() : null);

        if ($render === null) {
            return [];
        }

        $out = [];

        foreach (self::ICONS as $ours => $tabler) {
            try {
                $markup = $render($tabler);
            } catch (Throwable) {
                continue;
            }

            $markup = self::cleanSvg($markup);

            if ($markup !== null) {
                $out[$ours] = $markup;
            }
        }

        return $out;
    }

    /**
     * blade-icons repeats attributes on the root tag (its defaults, then the
     * icon's own: `width`, `stroke-width`, `class` ...). Browsers forgive that
     * in HTML; XML does not. Keep the icon's own value of each, and drop the
     * size and class, which the page's CSS sets.
     */
    public static function cleanSvg(string $markup): ?string
    {
        $markup = trim($markup);

        if (!preg_match('/^<svg\b([^>]*)>/is', $markup, $open)) {
            return null;
        }

        preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\')/', $open[1], $pairs, PREG_SET_ORDER);
        $attributes = [];

        foreach ($pairs as $pair) {
            $name = strtolower($pair[1]);

            if (in_array($name, ['width', 'height', 'class'], true)) {
                continue;
            }

            // Later wins: the icon's own value comes after the library's default.
            unset($attributes[$name]);
            $attributes[$name] = $pair[1] . '=' . $pair[2];
        }

        $attributes['xmlns'] ??= 'xmlns="http://www.w3.org/2000/svg"';

        return '<svg ' . implode(' ', $attributes) . '>' . substr($markup, strlen($open[0]));
    }

    /** @return array{classes: string, theme: string, mode: string, background: string}|null evo-ui's theme for the current manager */
    public static function evoTheme(): ?array
    {
        if (!self::hasEvoUi()) {
            return null;
        }

        try {
            $manager = app('EvoUI\Support\ManagerContext');
            $theme = (string) $manager->theme();

            return [
                'classes' => (string) $manager->themeClasses($theme),
                'theme' => $theme,
                'mode' => (string) $manager->themeMode($theme),
                'background' => (string) $manager->themeBackground($theme),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public static function mediaPath(string $path): string
    {
        $base = defined('EVO_BASE_PATH') ? rtrim((string) EVO_BASE_PATH, '/\\') : '';
        $manager = defined('MGR_DIR') ? trim((string) MGR_DIR, '/') : 'manager';

        return $base . '/' . $manager . '/media/' . ltrim($path, '/');
    }

    /** `EVO_MANAGER_URL` is right under a request, a working-directory guess otherwise. */
    public static function mediaUrl(string $path): string
    {
        $base = defined('EVO_MANAGER_URL') ? (string) EVO_MANAGER_URL : '';

        if ($base === '' || !preg_match('#^(?:https?:)?//|^/#', $base)) {
            $base = '/' . (defined('MGR_DIR') ? trim((string) MGR_DIR, '/') : 'manager') . '/';
        }

        return rtrim($base, '/') . '/media/' . ltrim($path, '/');
    }
}
