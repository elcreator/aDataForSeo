<?php

use Elcreator\aDataForSeo\Http\Controllers\BootstrapController;
use Elcreator\aDataForSeo\Http\Controllers\CardController;
use Elcreator\aDataForSeo\Http\Controllers\DataController;
use Elcreator\aDataForSeo\Http\Controllers\ExplorerController;
use Elcreator\aDataForSeo\Http\Controllers\JobController;
use Elcreator\aDataForSeo\Http\Controllers\PageController;
use Elcreator\aDataForSeo\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/**
 * The module's routes, included by `registerRoutingModule()` inside a group
 * that already carries the `modules/{id}` prefix and the `mgr` middleware.
 * `mgr` includes CSRF verification, which fails closed: every POST carries
 * `X-CSRF-TOKEN`. Mutations are POST only. The prefix is a hash of the module
 * name: the page gets its base URL from the controller, nothing hardcodes it.
 */

Route::get('/', [PageController::class, 'index'])->name('adataforseo.index');
Route::get('/bootstrap', [BootstrapController::class, 'show'])->name('adataforseo.bootstrap');

// Settings, keys (adataforseo_manage).
Route::post('/project', [SettingsController::class, 'project'])->name('adataforseo.project');
Route::post('/key', [SettingsController::class, 'key'])->name('adataforseo.key');
Route::post('/key/verify', [SettingsController::class, 'verify'])->name('adataforseo.key.verify');
Route::post('/key/delete', [SettingsController::class, 'deleteKey'])->name('adataforseo.key.delete');

// Jobs (estimate: adataforseo; start/approve/cancel/retry: adataforseo_spend).
Route::get('/jobs', [JobController::class, 'index'])->name('adataforseo.jobs');
Route::post('/jobs/estimate', [JobController::class, 'estimate'])->name('adataforseo.jobs.estimate');
Route::post('/jobs', [JobController::class, 'start'])->name('adataforseo.jobs.start');
Route::get('/jobs/{uuid}', [JobController::class, 'show'])->name('adataforseo.jobs.show');
Route::post('/jobs/{uuid}/approve', [JobController::class, 'approve'])->name('adataforseo.jobs.approve');
Route::post('/jobs/{uuid}/cancel', [JobController::class, 'cancel'])->name('adataforseo.jobs.cancel');
Route::post('/jobs/{uuid}/retry', [JobController::class, 'retry'])->name('adataforseo.jobs.retry');

// Cards and fixes.
Route::get('/cards', [CardController::class, 'index'])->name('adataforseo.cards');
Route::post('/issues/{id}/preview', [CardController::class, 'preview'])->whereNumber('id')->name('adataforseo.issues.preview');
Route::post('/issues/{id}/apply', [CardController::class, 'apply'])->whereNumber('id')->name('adataforseo.issues.apply');
Route::post('/issues/{id}/ignore', [CardController::class, 'ignore'])->whereNumber('id')->name('adataforseo.issues.ignore');
Route::post('/targets/{id}/preview', [CardController::class, 'previewTarget'])->whereNumber('id')->name('adataforseo.targets.preview');
Route::post('/targets/{id}/apply', [CardController::class, 'applyTarget'])->whereNumber('id')->name('adataforseo.targets.apply');
Route::post('/targets/{id}/document', [CardController::class, 'assign'])->whereNumber('id')->name('adataforseo.targets.document');
Route::post('/changes/{id}/undo', [CardController::class, 'undo'])->whereNumber('id')->name('adataforseo.changes.undo');

// Data behind the cards (pro tabs).
Route::get('/matrix', [DataController::class, 'matrix'])->name('adataforseo.matrix');
Route::get('/keywords', [DataController::class, 'keywords'])->name('adataforseo.keywords');
Route::get('/rankings', [DataController::class, 'rankings'])->name('adataforseo.rankings');
Route::get('/issues', [DataController::class, 'issues'])->name('adataforseo.issues');
Route::get('/ledger', [DataController::class, 'ledger'])->name('adataforseo.ledger');
Route::get('/changes', [DataController::class, 'changes'])->name('adataforseo.changes');
Route::get('/documents/{id}', [DataController::class, 'document'])->whereNumber('id')->name('adataforseo.document');
Route::get('/export/{kind}.{format}', [DataController::class, 'export'])->where(['kind' => '[a-z]+', 'format' => 'csv|xlsx'])->name('adataforseo.export');

// API explorer (professionals).
Route::post('/explorer/estimate', [ExplorerController::class, 'estimate'])->name('adataforseo.explorer.estimate');
Route::post('/explorer/run', [ExplorerController::class, 'run'])->name('adataforseo.explorer.run');
