<?php

namespace Elcreator\aDataForSeo;

use Elcreator\aDataForSeo\Console\DemoCommand;
use Elcreator\aDataForSeo\Console\JobHandler;
use Elcreator\aDataForSeo\Console\TickCommand;
use Elcreator\aDataForSeo\Listeners\DashboardWidget;
use Elcreator\aDataForSeo\Listeners\DocumentPanel;
use Elcreator\aDataForSeo\Listeners\GatewayReturn;
use Elcreator\aDataForSeo\Listeners\Pingback;
use Elcreator\aDataForSeo\Listeners\UrlMapInvalidation;
use Elcreator\aDataForSeo\Queue\STaskQueue;
use Elcreator\aDataForSeo\Support\Config;
use EvolutionCMS\ServiceProvider;
use EvolutionCMS\Services\SystemTasks\SystemTaskRegistry;
use Illuminate\Console\Scheduling\Schedule;

class aDataForSeoServiceProvider extends ServiceProvider
{
    /** The manager menu entry, and the name the route prefix is derived from. */
    public const MODULE_NAME = 'aDataForSeo';

    /** Menu icon on 3.5 (Font Awesome); 3.6+ gets the Tabler `evo-icon` class, see registerManagerModule(). */
    public const MODULE_ICON = 'fa fa-search';

    public function register(): void
    {
        // In register(): the task registration reads it, and a worker resolves
        // handlers before any boot method of ours has run.
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/aDataForSeo.php', 'cms.settings.aDataForSeo');

        $this->registerTaskType();
    }

    public function boot(): void
    {
        if (!$this->inBackend()) {
            // Front end: the pingback listener when enabled and the gateway's return page (a
            // stateless relay, see Gateway\ConnectRelay) — no database, no config sync, no worker registration.
            if (Config::isEnabled()) {
                if (Config::pingbackEnabled()) {
                    $this->app['events']->listen('evolution.OnPageNotFound', [Pingback::class, 'handle']);
                }

                $this->app['events']->listen('evolution.OnPageNotFound', [GatewayReturn::class, 'handle']);
            }

            return;
        }

        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
        $this->loadTranslationsFrom(dirname(__DIR__) . '/lang', 'aDataForSeo');
        $this->loadViewsFrom(dirname(__DIR__) . '/views', 'aDataForSeo');
        $this->ensureTranslationFallback();

        if (!Config::isEnabled()) {
            return;
        }

        $this->commands([TickCommand::class, DemoCommand::class]);
        $this->scheduleTick();
        $this->registerEvents();

        STaskQueue::ensureRegistered();

        $this->registerMcpTools();
        $this->registerManagerModule();
    }

    /**
     * Declare `adataforseo.job` to the CMS task queue: unconditionally and in
     * register(), because its consumer `system:task-worker` is a CLI process
     * with no routes and no module menu. `concurrent`: a job does not rewrite
     * the installation and must not block site updates.
     */
    protected function registerTaskType(): void
    {
        if (!class_exists(SystemTaskRegistry::class) || SystemTaskRegistry::has(JobHandler::TYPE)) {
            return;
        }

        SystemTaskRegistry::register(JobHandler::TYPE, JobHandler::class, [
            'mode' => SystemTaskRegistry::MODE_CONCURRENT,
            'parallelism' => max(1, (int) Config::limit('parallelism', 2)),
            'permissions' => ['adataforseo_spend'],
            'label' => 'aDataForSeo job',
        ]);
    }

    /** `adfs:tick` every minute, through the Laravel scheduler the CMS cron line drives. */
    protected function scheduleTick(): void
    {
        $this->app->afterResolving(Schedule::class, static function (Schedule $schedule) {
            foreach ($schedule->events() as $event) {
                if ((string) ($event->description ?? '') === 'adfs:tick') {
                    return;
                }
            }

            $schedule->command('adfs:tick')->everyMinute()->withoutOverlapping()->name('adfs:tick');
        });
    }

    protected function registerEvents(): void
    {
        $events = $this->app['events'];

        $events->listen('evolution.OnManagerWelcomeHome', [DashboardWidget::class, 'handle']);
        $events->listen('evolution.OnDocFormRender', [DocumentPanel::class, 'handle']);

        foreach (['OnDocFormSave', 'OnDocPublished', 'OnDocUnPublished', 'OnDocFormDelete'] as $event) {
            $events->listen('evolution.' . $event, [UrlMapInvalidation::class, 'handle']);
        }
    }

    protected function inBackend(): bool
    {
        return $this->app->runningInConsole() || (defined('IN_MANAGER_MODE') && IN_MANAGER_MODE === true);
    }

    /** eMCP tools, a soft dependency: the tool classes extend Laravel MCP's Tool. */
    protected function registerMcpTools(): void
    {
        if (!(bool) Config::get('mcp.enabled', true)) {
            return;
        }

        $registry = 'EvolutionCMS\eMCP\Services\ToolRegistry';

        if (!interface_exists('EvolutionCMS\eMCP\Contracts\ToolProvider') || !class_exists($registry) || !class_exists('Laravel\Mcp\Server\Tool')) {
            return;
        }

        $this->app->make($registry)->register(new Mcp\ToolProvider());
    }

    /** `registerRoutingModule()` refuses outside the backend: the routes exist only where a manager session can reach them. */
    protected function registerManagerModule(): void
    {
        $this->app->registerRoutingModule(self::MODULE_NAME, __DIR__ . '/Http/routes.php', Support\Manager::icon('search', 'search'));
    }

    protected function ensureTranslationFallback(): void
    {
        try {
            $translator = $this->app['translator'];
        } catch (\Throwable) {
            return;
        }

        if (!method_exists($translator, 'getFallback') || !method_exists($translator, 'setFallback') || (string) $translator->getFallback() !== '') {
            return;
        }

        $fallback = trim((string) config('app.fallback_locale', 'en'));
        $translator->setFallback($fallback !== '' ? $fallback : 'en');
    }
}
