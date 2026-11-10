<?php

use Elcreator\aDataForSeo\Api\Client;
use Elcreator\aDataForSeo\Api\DemoTransport;
use Elcreator\aDataForSeo\Api\Pricing;
use Elcreator\aDataForSeo\Api\Transport;
use Elcreator\aDataForSeo\Integrations\SSeo;
use Elcreator\aDataForSeo\Models\Job;
use Elcreator\aDataForSeo\Models\Project;
use Elcreator\aDataForSeo\Support\Config;
use Elcreator\aDataForSeo\Support\EventLog;
use Elcreator\aDataForSeo\Support\Keys;
use Elcreator\aDataForSeo\Support\UrlMap;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/**
 * Shared setup. Helpers are prefixed `adfs` so they never collide with
 * another package's. The aDataForSeo tables come from the package's real
 * migrations; the CMS tables it reads are declared with the columns it uses.
 */

function adfsBoot(): void
{
    static $booted = false;

    if ($booted) {
        return;
    }

    $booted = true;

    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher(new Illuminate\Events\Dispatcher(new Container()));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::setConnectionResolver($capsule->getDatabaseManager());

    $container = new Container();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->schema());
    $container->instance('translator', new class {
        public function get($key, array $replace = [], $locale = null)
        {
            return (string) $key;
        }
    });
    $container->bind('config', static fn () => AdfsTestCore::config());
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    adfsCreateCmsTables($capsule);

    $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        (require $file)->up();
    }
}

function adfsCreateCmsTables(Capsule $capsule): void
{
    $schema = $capsule->schema();

    $schema->create('users', function (Blueprint $t) {
        $t->increments('id');
        $t->string('username', 100)->default('');
    });
    $schema->create('user_attributes', function (Blueprint $t) {
        $t->increments('id');
        $t->unsignedInteger('internalKey');
        $t->integer('role')->default(0);
        $t->integer('blocked')->default(0);
        $t->integer('blockeduntil')->default(0);
        $t->integer('blockedafter')->default(0);
    });
    $schema->create('member_groups', function (Blueprint $t) {
        $t->increments('id');
        $t->unsignedInteger('user_group')->default(0);
        $t->unsignedInteger('member')->default(0);
    });
    $schema->create('membergroup_access', function (Blueprint $t) {
        $t->increments('id');
        $t->unsignedInteger('membergroup')->default(0);
        $t->unsignedInteger('documentgroup')->default(0);
    });
    $schema->create('document_groups', function (Blueprint $t) {
        $t->increments('id');
        $t->unsignedInteger('document_group')->default(0);
        $t->unsignedInteger('document')->default(0);
    });
    $schema->create('permissions_groups', function (Blueprint $t) {
        $t->increments('id');
        $t->string('name', 100)->unique();
        $t->string('lang_key', 100)->nullable();
        $t->timestamps();
    });
    $schema->create('permissions', function (Blueprint $t) {
        $t->increments('id');
        $t->string('key', 100)->unique();
        $t->string('name', 100)->nullable();
        $t->string('lang_key', 100)->nullable();
        $t->unsignedInteger('group_id')->nullable();
        $t->boolean('disabled')->default(false);
        $t->timestamps();
    });
    $schema->create('role_permissions', function (Blueprint $t) {
        $t->increments('id');
        $t->unsignedInteger('role_id');
        $t->string('permission', 100);
        $t->timestamps();
    });
    $schema->create('site_content', function (Blueprint $t) {
        $t->increments('id');
        $t->string('type', 20)->default('document');
        $t->string('contentType', 50)->default('text/html');
        $t->string('pagetitle', 255)->default('');
        $t->string('longtitle', 255)->default('');
        $t->string('description', 255)->default('');
        $t->string('alias', 245)->default('');
        $t->integer('published')->default(0);
        $t->integer('parent')->default(0);
        $t->integer('isfolder')->default(0);
        $t->text('introtext')->nullable();
        $t->text('content')->nullable();
        $t->integer('richtext')->default(1);
        $t->integer('template')->default(0);
        $t->integer('menuindex')->default(0);
        $t->integer('searchable')->default(1);
        $t->integer('cacheable')->default(1);
        $t->integer('createdby')->default(0);
        $t->integer('createdon')->default(0);
        $t->integer('editedby')->default(0);
        $t->integer('editedon')->default(0);
        $t->integer('publishedon')->default(0);
        $t->integer('publishedby')->default(0);
        $t->integer('deleted')->default(0);
        $t->integer('hidemenu')->default(0);
    });
    $schema->create('system_cli_tasks', function (Blueprint $t) {
        $t->increments('id');
        $t->string('uuid', 36)->unique();
        $t->string('type', 64)->default('');
        $t->string('target', 191)->default('');
        $t->string('requested_version', 191)->default('');
        $t->string('status', 32)->default('queued');
        $t->string('step', 64)->default('');
        $t->unsignedSmallInteger('progress')->default(0);
        $t->string('message', 255)->default('');
        $t->text('payload_json')->nullable();
        $t->text('result_json')->nullable();
        $t->unsignedInteger('created_by')->nullable();
        $t->string('locked_by', 191)->default('');
        $t->unsignedInteger('attempt_count')->default(0);
        $t->dateTime('lease_expires_at')->nullable();
        $t->string('worker_host', 191)->default('');
        $t->integer('worker_pid')->nullable();
        $t->string('error_code', 64)->default('');
        $t->string('catalog_snapshot_hash', 64)->default('');
        $t->text('requested_by_snapshot')->nullable();
        $t->dateTime('started_at')->nullable();
        $t->dateTime('heartbeat_at')->nullable();
        $t->dateTime('cancellation_requested_at')->nullable();
        $t->dateTime('finished_at')->nullable();
        $t->timestamps();
    });
}

/** Put the world back between tests. */
function adfsReset(): void
{
    foreach (['adfs_changes', 'adfs_issues', 'adfs_rankings', 'adfs_targets', 'adfs_keywords', 'adfs_calls', 'adfs_steps', 'adfs_jobs', 'adfs_keys', 'adfs_projects',
        'users', 'user_attributes', 'member_groups', 'membergroup_access', 'document_groups', 'role_permissions', 'site_content', 'system_cli_tasks'] as $table) {
        if (Schema::hasTable($table)) {
            Capsule::table($table)->delete();
        }
    }

    AdfsTestCore::reset();
    EventLog::$lines = [];
    Client::useTransport(null);
    Pricing::useTable(DemoTransport::PRICES);
    UrlMap::useProvider(null);
    SSeo::fake(null);
    \Carbon\Carbon::setTestNow();
    adfsRemoveTree(Config::storagePath());
}

function adfsRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        file_exists($path) && @unlink($path);

        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        adfsRemoveTree($path . '/' . $entry);
    }

    @rmdir($path);
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** A manager whose role holds exactly `$permissions` (role 1 = administrator: all). */
function adfsUser(int $id, array $permissions = ['adataforseo', 'adataforseo_spend'], ?int $role = null, array $documentGroups = []): int
{
    $role ??= 100 + $id;
    Capsule::table('users')->insert(['id' => $id, 'username' => 'user' . $id]);
    Capsule::table('user_attributes')->insert(['internalKey' => $id, 'role' => $role]);

    foreach ($permissions as $permission) {
        Capsule::table('role_permissions')->insert(['role_id' => $role, 'permission' => $permission]);
    }

    foreach ($documentGroups as $group) {
        Capsule::table('member_groups')->insert(['user_group' => 100 + $group, 'member' => $id]);
        Capsule::table('membergroup_access')->insert(['membergroup' => 100 + $group, 'documentgroup' => $group]);
    }

    return $id;
}

function adfsAdmin(int $id = 1): int
{
    return adfsUser($id, [], 1);
}

function adfsRevoke(int $userId, string $permission): void
{
    $role = (int) Capsule::table('user_attributes')->where('internalKey', $userId)->value('role');
    Capsule::table('role_permissions')->where('role_id', $role)->where('permission', $permission)->delete();
}

function adfsDocument(string $alias, array $attributes = []): int
{
    return (int) Capsule::table('site_content')->insertGetId($attributes + [
        'pagetitle' => ucfirst(str_replace('-', ' ', $alias)), 'alias' => $alias, 'published' => 1, 'deleted' => 0,
        'content' => '<p>' . str_repeat('A useful page about ' . $alias . '. ', 5) . '</p>', 'createdon' => 1000, 'editedon' => 1000,
    ]);
}

/** The demo project: Czechia, cs/en/uk, Atlantis and Avalon. */
function adfsProject(array $attributes = []): Project
{
    return Project::query()->create($attributes + [
        'domain' => 'example.test',
        'domain_key' => 'default',
        'mode' => 'pro',
        'markets' => [[
            'country' => 'CZ', 'location_code' => 2203, 'languages' => ['cs', 'en', 'uk'],
            'geo' => [
                ['name' => 'Atlantida', 'spellings' => ['cs' => ['Atlantida', 'Atlantidě'], 'en' => ['Atlantis'], 'uk' => ['Атлантида', 'Атлантиді'], 'ru' => ['Атлантида']]],
                ['name' => 'Avalon', 'spellings' => ['en' => ['Avalon'], 'uk' => ['Авалон']]],
            ],
        ]],
        'competitors' => ['starlinghomes.test'],
        'settings' => ['site_languages' => ['cs', 'en']],
    ]);
}

/** The offline demo key, so jobs have something to call. */
function adfsDemoKey(): void
{
    Keys::save('site', null, 'demo', '', 'demo');
}

/** A job row with options, not planned or dispatched. */
function adfsJob(Project $project, int $userId, string $kind = 'coverage', array $attributes = []): Job
{
    return Job::query()->create($attributes + ['project_id' => $project->getKey(), 'user_id' => $userId, 'kind' => $kind, 'status' => Job::QUEUED, 'options' => []]);
}

/**
 * Run a job to its end through Slice, treating every wait as already over
 * (demo and fake tasks are ready at once). Returns the refreshed job.
 */
function adfsDrive(Job $job, float $budget = 30.0, int $rounds = 100): Job
{
    for ($i = 0; $i < $rounds; $i++) {
        $result = (new \Elcreator\aDataForSeo\Engine\Slice())->run($job, $budget);

        if ($result->status === 'waiting') {
            \Elcreator\aDataForSeo\Models\Step::query()->where('job_id', $job->getKey())->whereNotNull('next_check_at')->update(['next_check_at' => \Carbon\Carbon::now()->subSecond()]);
            $job->forceFill(['status' => Job::QUEUED])->save();

            continue;
        }

        if ($result->status !== 'more') {
            break;
        }
    }

    return $job->refresh();
}

/**
 * A scripted DataForSEO: `$routes` maps a regex on the endpoint path to a
 * callable (method, path, tasks) => response array. Requests are recorded.
 */
final class AdfsFakeTransport implements Transport
{
    /** @var array<int, array{method: string, path: string, tasks: array}> */
    public array $requests = [];

    public function __construct(private array $routes)
    {
    }

    public function send(string $method, string $url, ?string $body, array $headers, int $timeout, int $connectTimeout): array
    {
        $path = trim((string) preg_replace('#^https?://[^/]+/v3/#', '', $url), '/');
        $tasks = $body === null ? [] : (array) json_decode($body, true);
        $this->requests[] = ['method' => $method, 'path' => $path, 'tasks' => $tasks];

        foreach ($this->routes as $pattern => $handler) {
            if (preg_match($pattern, $path)) {
                $answer = $handler($method, $path, $tasks);

                return is_array($answer) && isset($answer[0]) && is_int($answer[0]) ? $answer : [200, json_encode($answer)];
            }
        }

        return [404, '{"status_code":40400,"status_message":"not scripted"}'];
    }

    public function count(string $pattern): int
    {
        return count(array_filter($this->requests, static fn ($r) => preg_match($pattern, $r['path'])));
    }
}

/** A DataForSEO envelope around tasks. */
function adfsEnvelope(array $tasks, float $cost = 0.0): array
{
    return ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost, 'tasks_count' => count($tasks), 'tasks' => $tasks];
}

function adfsTask(array $result, array $data = [], int $code = 20000, float $cost = 0.0, ?string $id = null): array
{
    return ['id' => $id ?? uniqid('t', true), 'status_code' => $code, 'status_message' => $code === 20000 ? 'Ok.' : 'Error.', 'cost' => $cost, 'data' => $data, 'result' => $result];
}

/** An in-memory sSeo for the fixes. */
final class AdfsFakeSeo
{
    public array $fields = [];

    public array $redirects = [];

    public int $writes = 0;

    public function available(): bool
    {
        return true;
    }

    public function read(int $id, string $type, string $lang, array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $out[$field] = (string) ($this->fields[$id][$lang][$field] ?? '');
        }

        return $out;
    }

    public function write(int $id, string $type, string $lang, array $fields): void
    {
        $this->writes++;

        foreach ($fields as $field => $value) {
            $this->fields[$id][$lang][$field] = (string) $value;
        }
    }

    public function redirect(string $old, string $new, int $type, string $site): int
    {
        $this->redirects[] = ['old' => $old, 'new' => $new, 'type' => $type, 'site' => $site];

        return count($this->redirects);
    }

    public function deleteRedirect(int $id, string $old, string $new): bool
    {
        if (($this->redirects[$id - 1]['old'] ?? null) === $old && ($this->redirects[$id - 1]['new'] ?? null) === $new) {
            unset($this->redirects[$id - 1]);

            return true;
        }

        return false;
    }

    public function redirectExists(string $old, string $site): bool
    {
        foreach ($this->redirects as $r) {
            if ($r['old'] === $old) {
                return true;
            }
        }

        return false;
    }
}

adfsBoot();
