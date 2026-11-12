<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay from the ai.artur.work balance instead of a DataForSEO account.
 *
 * - `adfs_projects.billing`: `own` (DataForSEO credentials, US dollars) or `gateway` (an
 *   ai.artur.work key, euros).
 * - `adfs_keys.provider`: which kind of secret a row holds, so a project can keep both.
 *   Existing rows are DataForSEO logins.
 * - `adfs_jobs.currency` / `adfs_calls.currency`: the unit of `estimate`, `spent` and `cost`.
 *   Every existing row is US dollars, which is what DataForSEO bills; the ledger never adds
 *   two currencies together.
 * Re-runnable: each change is guarded.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->add('adfs_projects', 'billing', fn (Blueprint $t) => $t->string('billing', 8)->default('own'));
        $this->add('adfs_keys', 'provider', fn (Blueprint $t) => $t->string('provider', 12)->default('dataforseo'));
        $this->add('adfs_jobs', 'currency', fn (Blueprint $t) => $t->string('currency', 3)->default('USD'));
        $this->add('adfs_calls', 'currency', fn (Blueprint $t) => $t->string('currency', 3)->default('USD'));
    }

    public function down(): void
    {
        foreach ([['adfs_projects', 'billing'], ['adfs_keys', 'provider'], ['adfs_jobs', 'currency'], ['adfs_calls', 'currency']] as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function add(string $table, string $column, callable $definition): void
    {
        if (Schema::hasTable($table) && !Schema::hasColumn($table, $column)) {
            Schema::table($table, static function (Blueprint $t) use ($definition) {
                $definition($t);
            });
        }
    }
};
