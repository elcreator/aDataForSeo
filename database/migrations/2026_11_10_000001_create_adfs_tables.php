<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every aDataForSeo table. JSON columns hold arrays; nothing stores a secret in
 * clear (the DataForSEO password is `password_enc`, AES-256-GCM).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('adfs_projects')) {
            Schema::create('adfs_projects', function (Blueprint $table) {
                $table->increments('id');
                $table->string('domain', 191);
                $table->string('domain_key', 64)->default('default');
                $table->text('markets')->nullable();
                $table->text('competitors')->nullable();
                $table->decimal('monthly_cap', 10, 4)->nullable();
                $table->decimal('job_cap', 10, 4)->nullable();
                $table->decimal('approve_above', 10, 4)->nullable();
                $table->string('mode', 16)->default('beginner');
                $table->text('schedule')->nullable();
                $table->text('settings')->nullable();
                $table->unsignedInteger('authorized_by')->nullable();
                $table->timestamps();
                $table->unique('domain_key');
            });
        }

        if (!Schema::hasTable('adfs_keys')) {
            Schema::create('adfs_keys', function (Blueprint $table) {
                $table->increments('id');
                $table->string('scope', 8)->default('site');
                $table->unsignedInteger('user_id')->nullable();
                $table->string('login', 191)->default('');
                $table->text('password_enc')->nullable();
                $table->string('env', 16)->default('prod');
                $table->dateTime('verified_at')->nullable();
                $table->decimal('balance', 12, 4)->nullable();
                $table->text('subscriptions')->nullable();
                $table->timestamps();
                $table->index(['scope', 'user_id']);
            });
        }

        if (!Schema::hasTable('adfs_jobs')) {
            Schema::create('adfs_jobs', function (Blueprint $table) {
                $table->increments('id');
                $table->string('uuid', 36)->unique();
                $table->unsignedInteger('project_id');
                $table->unsignedInteger('user_id');
                $table->string('kind', 24);
                $table->string('status', 24)->default('draft');
                $table->string('phase', 64)->default('');
                $table->unsignedSmallInteger('progress')->default(0);
                $table->decimal('estimate', 10, 4)->nullable();
                $table->decimal('estimate_high', 10, 4)->nullable();
                $table->decimal('spent', 10, 4)->default(0);
                $table->string('trigger', 16)->default('manual');
                $table->boolean('approved')->default(false);
                $table->unsignedInteger('approved_by')->nullable();
                $table->dateTime('wake_at')->nullable();
                $table->dateTime('heartbeat_at')->nullable();
                $table->unsignedInteger('slices')->default(0);
                $table->string('queue', 16)->nullable();
                $table->unsignedInteger('task_id')->nullable();
                $table->text('options')->nullable();
                $table->text('result')->nullable();
                $table->text('error')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->timestamps();
                $table->index(['project_id', 'status']);
                $table->index(['status', 'wake_at']);
            });
        }

        if (!Schema::hasTable('adfs_steps')) {
            Schema::create('adfs_steps', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('job_id');
                $table->unsignedInteger('seq')->default(0);
                $table->string('name', 64);
                $table->string('kind', 16);
                $table->string('endpoint', 191)->default('');
                $table->text('payload')->nullable();
                $table->string('tag', 255)->nullable();
                $table->text('dfs_task_ids')->nullable();
                $table->string('status', 16)->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->unsignedInteger('polls')->default(0);
                $table->decimal('estimate', 10, 4)->nullable();
                $table->decimal('cost', 10, 4)->default(0);
                $table->text('output')->nullable();
                $table->text('error')->nullable();
                $table->dateTime('next_check_at')->nullable();
                $table->timestamps();
                $table->index(['job_id', 'seq']);
            });
        }

        if (!Schema::hasTable('adfs_calls')) {
            Schema::create('adfs_calls', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id')->nullable();
                $table->unsignedInteger('job_id')->nullable();
                $table->unsignedInteger('step_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('env', 16)->default('prod');
                $table->string('endpoint', 191);
                $table->string('cache_key', 40)->nullable();
                $table->decimal('cost', 10, 6)->default(0);
                $table->decimal('estimate', 10, 6)->nullable();
                $table->boolean('cached')->default(false);
                $table->integer('status_code')->default(0);
                $table->unsignedInteger('tasks')->default(0);
                $table->unsignedInteger('items')->default(0);
                $table->unsignedInteger('depth')->nullable();
                $table->string('response_path', 255)->nullable();
                $table->dateTime('fetched_at')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->timestamps();
                $table->index('cache_key');
                $table->index(['project_id', 'fetched_at']);
                $table->index(['job_id']);
                $table->index(['endpoint', 'cached']);
            });
        }

        if (!Schema::hasTable('adfs_keywords')) {
            Schema::create('adfs_keywords', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id');
                $table->string('market', 8);
                $table->string('lang', 8);
                $table->string('keyword', 255);
                $table->unsignedInteger('volume')->nullable();
                $table->text('monthly')->nullable();
                $table->unsignedSmallInteger('kd_labs')->nullable();
                $table->string('core_keyword', 255)->nullable();
                $table->decimal('cpc', 10, 4)->nullable();
                $table->decimal('competition', 6, 4)->nullable();
                $table->string('intent', 24)->nullable();
                $table->string('source', 16)->default('labs');
                $table->string('geo', 64)->default('');
                $table->string('deal', 16)->default('');
                $table->string('type', 16)->default('');
                $table->boolean('brand')->default(false);
                $table->boolean('origin')->default(false);
                $table->unsignedInteger('target_id')->nullable();
                $table->unsignedInteger('cluster_id')->nullable();
                $table->boolean('counted')->default(false);
                $table->boolean('shared')->default(false);
                $table->dateTime('fetched_at')->nullable();
                $table->timestamps();
                $table->index(['project_id', 'market', 'lang']);
                $table->index(['project_id', 'target_id']);
            });
        }

        if (!Schema::hasTable('adfs_targets')) {
            Schema::create('adfs_targets', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id');
                $table->string('country', 8);
                $table->string('geo', 64)->default('');
                $table->string('deal', 16)->default('');
                $table->string('type', 16)->default('');
                $table->string('lang', 8);
                $table->unsignedInteger('document_id')->nullable();
                $table->string('resource_type', 24)->default('document');
                $table->boolean('document_manual')->default(false);
                $table->string('url', 500)->nullable();
                $table->string('proposed_url', 500)->nullable();
                $table->unsignedInteger('primary_keyword_id')->nullable();
                $table->unsignedInteger('demand_real')->nullable();
                $table->unsignedInteger('demand_ambiguous')->nullable();
                $table->unsignedInteger('demand_origin')->nullable();
                $table->unsignedInteger('demand_raw')->nullable();
                $table->decimal('kd_serp', 6, 2)->nullable();
                $table->decimal('native_share', 5, 4)->nullable();
                $table->decimal('score', 14, 4)->nullable();
                $table->string('tier', 4)->nullable();
                $table->string('status', 16)->default('missing');
                $table->text('serp_snapshot')->nullable();
                $table->timestamps();
                $table->unique(['project_id', 'country', 'geo', 'deal', 'type', 'lang'], 'adfs_targets_page');
            });
        }

        if (!Schema::hasTable('adfs_rankings')) {
            Schema::create('adfs_rankings', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id');
                $table->unsignedInteger('target_id')->nullable();
                $table->unsignedInteger('keyword_id')->nullable();
                $table->unsignedInteger('job_id')->nullable();
                $table->string('keyword', 255)->default('');
                $table->date('date');
                $table->unsignedSmallInteger('position')->nullable();
                $table->string('url', 500)->nullable();
                $table->text('serp_features')->nullable();
                $table->timestamps();
                $table->index(['project_id', 'date']);
                $table->index(['target_id', 'date']);
            });
        }

        if (!Schema::hasTable('adfs_issues')) {
            Schema::create('adfs_issues', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id');
                $table->unsignedInteger('job_id')->nullable();
                $table->string('source', 24)->default('');
                $table->string('code', 48);
                $table->string('severity', 8)->default('medium');
                $table->string('url', 500)->default('');
                $table->string('url_hash', 40)->default('');
                $table->unsignedInteger('document_id')->nullable();
                $table->text('details')->nullable();
                $table->string('fix', 24)->nullable();
                $table->string('status', 16)->default('open');
                $table->timestamps();
                $table->index(['project_id', 'status']);
                $table->index(['project_id', 'code', 'url_hash']);
            });
        }

        if (!Schema::hasTable('adfs_changes')) {
            Schema::create('adfs_changes', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('project_id')->nullable();
                $table->unsignedInteger('issue_id')->nullable();
                $table->unsignedInteger('target_id')->nullable();
                $table->unsignedInteger('user_id');
                $table->string('target', 16);
                $table->unsignedInteger('resource_id')->nullable();
                $table->string('resource_type', 24)->default('document');
                $table->string('lang', 8)->default('base');
                $table->string('domain_key', 64)->default('default');
                $table->string('field', 64);
                $table->text('before')->nullable();
                $table->text('after')->nullable();
                $table->dateTime('applied_at');
                $table->dateTime('undone_at')->nullable();
                $table->timestamps();
                $table->index(['resource_id', 'field']);
            });
        }
    }

    public function down(): void
    {
        foreach (['adfs_changes', 'adfs_issues', 'adfs_rankings', 'adfs_targets', 'adfs_keywords', 'adfs_calls', 'adfs_steps', 'adfs_jobs', 'adfs_keys', 'adfs_projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
