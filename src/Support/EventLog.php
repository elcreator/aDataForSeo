<?php

namespace Elcreator\aDataForSeo\Support;

/**
 * One line in the CMS event log (source `aDataForSeo`) for every refusal.
 *
 * Request values are escaped and nothing secret is ever passed in. Tests read
 * `$lines` instead of a database log.
 */
final class EventLog
{
    public const SOURCE = 'aDataForSeo';

    /** @var string[] */
    public static array $lines = [];

    /** @param 1|2|3 $type information, warning, error */
    public static function write(string $message, int $type = 2): void
    {
        $message = mb_substr(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 0, 1000);
        self::$lines[] = $message;

        try {
            $core = evo();

            if (is_object($core) && method_exists($core, 'logEvent')) {
                $core->logEvent(0, $type, $message, self::SOURCE);
            }
        } catch (\Throwable) {
            // No CMS log in this process (unit tests, a broken install): the line above is all there is.
        }
    }

    public static function refusal(string $what, string $code, array $context = []): void
    {
        $parts = [];

        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value));
        }

        self::write('Refused ' . $what . ': ' . $code . ($parts ? ' (' . implode(', ', $parts) . ')' : ''));
    }
}
