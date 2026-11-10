<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The permissions aDataForSeo is gated by, and their assignment to roles.
 *
 *   adataforseo         open the module, read reports and cached data;
 *   adataforseo_spend   start or approve work that costs money, within the budgets;
 *   adataforseo_manage  keys, budgets, schedules, project settings.
 *
 * Administrators (role 1) get all three; other roles nothing by default, since
 * "who may spend the site's DataForSEO balance" has no safe default answer.
 * Re-runnable: upserts everywhere, a unique-key race reads as "someone else got
 * there first".
 */
return new class extends Migration {
    public $withinTransaction = false;

    private const GROUP = 'aDataForSeo';

    private const PERMISSIONS = [
        'adataforseo' => ['name' => 'Use aDataForSeo', 'lang_key' => 'aDataForSeo::global.permission_use'],
        'adataforseo_spend' => ['name' => 'Spend the DataForSEO balance', 'lang_key' => 'aDataForSeo::global.permission_spend'],
        'adataforseo_manage' => ['name' => 'Manage aDataForSeo', 'lang_key' => 'aDataForSeo::global.permission_manage'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions_groups') || !Schema::hasTable('permissions')) {
            return;
        }

        $groupId = $this->group();

        foreach (self::PERMISSIONS as $key => $meta) {
            $attributes = $meta + ['group_id' => $groupId, 'disabled' => 0, 'updated_at' => now()];

            if (DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->where('key', $key)->update($attributes);
            } else {
                try {
                    DB::table('permissions')->insert($attributes + ['key' => $key, 'created_at' => now()]);
                } catch (QueryException $e) {
                    DB::table('permissions')->where('key', $key)->update($attributes);
                }
            }

            $this->grantToAdministrator($key);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission', array_keys(self::PERMISSIONS))->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
        }

        if (Schema::hasTable('permissions_groups') && Schema::hasTable('permissions')) {
            $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

            if ($group && !DB::table('permissions')->where('group_id', $group->id)->exists()) {
                DB::table('permissions_groups')->where('id', $group->id)->delete();
            }
        }
    }

    private function group(): int
    {
        $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

        if ($group) {
            return (int) $group->id;
        }

        try {
            return (int) DB::table('permissions_groups')->insertGetId([
                'name' => self::GROUP,
                'lang_key' => 'aDataForSeo::global.permissions_group',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $group = DB::table('permissions_groups')->where('name', self::GROUP)->first();

            if ($group) {
                return (int) $group->id;
            }

            throw $e;
        }
    }

    private function grantToAdministrator(string $permission): void
    {
        if (!Schema::hasTable('role_permissions')) {
            return;
        }

        if (DB::table('role_permissions')->where('role_id', 1)->where('permission', $permission)->exists()) {
            return;
        }

        try {
            DB::table('role_permissions')->insert([
                'role_id' => 1,
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Already there: a concurrent run won the race.
        }
    }
};
