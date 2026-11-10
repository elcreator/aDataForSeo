<?php

/**
 * Test bootstrap.
 *
 * aDataForSeo has no vendor directory of its own: it borrows an Evolution CMS
 * core's autoloader (`EVO_CORE_PATH_TEST`), which also brings Pest and
 * Eloquent, and maps this package's namespace on top. Everything the CMS
 * would define at boot is stubbed here; the database is in-memory SQLite
 * built by the package's real migrations (see helpers.php).
 */

$core = getenv('EVO_CORE_PATH_TEST') ?: 'C:/projects/Opensource/evo/evolution/core';
$core = rtrim(str_replace('\\', '/', $core), '/');

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "aDataForSeo tests need an Evolution CMS core to borrow an autoloader from.\nLooked in: {$core}\nSet EVO_CORE_PATH_TEST to point at one.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Elcreator\\aDataForSeo\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/adfs-tests-' . getmypid();
@mkdir($root . '/core/custom', 0775, true);
@mkdir($root . '/core/storage', 0775, true);

define('ADFS_TEST_ROOT', $root);
define('EVO_BASE_PATH', $root . '/');
define('EVO_CORE_PATH', $root . '/core/');
define('EVO_STORAGE_PATH', $root . '/core/storage/');
define('IN_MANAGER_MODE', false);
define('IN_INSTALL_MODE', false);
define('EVO_API_MODE', false);

// Pinned so the suite never writes a real secret into core/custom.
putenv('ADATAFORSEO_SECRET=adfs-test-secret');
putenv('DATAFORSEO_LOGIN');
putenv('DATAFORSEO_PASSWORD');

/**
 * Stands in for `EvolutionCMS\Core` so `evo()` and `config()` resolve
 * (`EVO_CLASS` is the documented seam).
 */
final class AdfsTestCore
{
    public static ?AdfsTestCore $instance = null;

    public static ?\Illuminate\Config\Repository $repository = null;

    public static array $config = [];

    public static int $loginUserId = 0;

    /** @var array<int, array{int, string}> */
    public static array $events = [];

    public array $configGlobal = [];

    public static function defaults(): array
    {
        return [
            'use_udperms' => true,
            'site_url' => 'https://example.test/',
            'site_name' => 'Example Realty',
            'default_template' => 3,
        ];
    }

    public function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function make(string $abstract, array $parameters = [])
    {
        return match ($abstract) {
            'config' => self::config(),
            default => null,
        };
    }

    public function getConfig(string $key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    public function getLoginUserID(string $context = ''): int
    {
        return self::$loginUserId;
    }

    /** A worker whose CMS alias cache predates the document: no friendly URL. */
    public static bool $staleAliases = false;

    /** Friendly URLs from the alias, as the CMS would build them for a flat tree. */
    public function makeUrl($id, $alias = '', $args = '', $scheme = ''): string
    {
        if (self::$staleAliases) {
            return rtrim((string) self::$config['site_url'], '/') . '/index.php?id=' . (int) $id;
        }

        $alias = \Illuminate\Support\Facades\DB::table('site_content')->where('id', (int) $id)->value('alias');

        return rtrim((string) self::$config['site_url'], '/') . '/' . ($alias ? $alias : '');
    }

    public function logEvent($id, $type, $message, $source = ''): void
    {
        self::$events[] = [(int) $type, (string) $message];
    }

    public function clearCache($type = ''): void
    {
    }

    public static function config(): \Illuminate\Config\Repository
    {
        return self::$repository ??= new \Illuminate\Config\Repository([
            'cms' => ['settings' => ['aDataForSeo' => require dirname(__DIR__) . '/config/aDataForSeo.php']],
            'app' => ['fallback_locale' => 'en'],
        ]);
    }

    public static function reset(): void
    {
        self::$config = self::defaults();
        self::$repository = null;
        self::$loginUserId = 0;
        self::$events = [];
        self::$staleAliases = false;
    }
}

AdfsTestCore::reset();
define('EVO_CLASS', AdfsTestCore::class);

if (!function_exists('__')) {
    function __($key = null, $replace = [], $locale = null)
    {
        $text = (string) $key;

        foreach ((array) $replace as $search => $value) {
            $text = str_replace(':' . $search, (string) $value, $text);
        }

        return $text;
    }
}

// Loaded from here rather than a Pest.php: Pest's root is the borrowed core.
require __DIR__ . '/helpers.php';

register_shutdown_function(static function () use ($root) {
    adfsRemoveTree($root);
});
