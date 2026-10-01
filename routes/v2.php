<?php

/*
| v2: company website + submission form + admin dashboard, all under /v2.
| v1 (the original demo at /) is untouched.
*/

use App\Http\Controllers\V2\Admin;
use App\Http\Controllers\V2\SiteController;
use App\Http\Controllers\V2\SubmitController;
use App\Http\Controllers\V2\TrackController;
use App\Http\Middleware\V2\AdminLocale;
use App\Http\Middleware\V2\EnsureInstalled;
use App\Http\Middleware\V2\ScopeSubmission;
use App\Http\Middleware\V2\SetLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('v2')->name('v2.')->middleware([EnsureInstalled::class, SetLocale::class])->group(function () {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    Route::get('about', [SiteController::class, 'about'])->name('about');
    Route::get('services', [SiteController::class, 'services'])->name('services');
    Route::get('projects', [SiteController::class, 'projects'])->name('projects');
    Route::get('projects/{project}', [SiteController::class, 'project'])->name('project');
    Route::get('contact', [SiteController::class, 'contact'])->name('contact');
    Route::post('contact', [SiteController::class, 'contactSend'])->middleware('throttle:v2-contact')->name('contact.send');
    Route::get('media/{path}', [SiteController::class, 'media'])->where('path', '.*')->name('media');

    Route::get('submit', [SubmitController::class, 'form'])->name('submit');
    Route::post('submit', [SubmitController::class, 'create'])->middleware('throttle:v2-submit')->name('submit.create');
    Route::post('submit/{code}/chunk', [SubmitController::class, 'chunk'])->name('submit.chunk');
    Route::post('submit/{code}/step', [SubmitController::class, 'step'])->name('submit.step');
    Route::get('submit/{code}/thanks', [SubmitController::class, 'thanks'])->name('submit.thanks');

    Route::get('track', [TrackController::class, 'form'])->middleware('throttle:v2-track')->name('track');
    Route::get('track/{code}', [TrackController::class, 'show'])->middleware('throttle:v2-track')->name('track.show');
    Route::get('track/{code}/download', [TrackController::class, 'download'])->middleware('throttle:v2-track')->name('track.download');

    // ---- admin
    Route::prefix('admin')->name('admin.')->middleware(AdminLocale::class)->group(function () {
        Route::get('login', [Admin\AuthController::class, 'form'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:v2-login')->name('login.post');

        Route::middleware('auth')->group(function () {
            Route::post('logout', [Admin\AuthController::class, 'logout'])->name('logout');
            Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

            Route::get('submissions', [Admin\SubmissionController::class, 'index'])->name('submissions');
            Route::get('submissions/export', [Admin\SubmissionController::class, 'export'])->name('submissions.export');
            Route::get('submissions/{submission}', [Admin\SubmissionController::class, 'show'])->name('submissions.show');
            Route::patch('submissions/{submission}', [Admin\SubmissionController::class, 'update'])->name('submissions.update');
            Route::delete('submissions/{submission}', [Admin\SubmissionController::class, 'destroy'])->name('submissions.destroy');
            Route::get('submissions/{submission}/original', [Admin\SubmissionController::class, 'original'])->name('submissions.original');
            Route::get('submissions/{submission}/issued', [Admin\SubmissionController::class, 'issued'])->name('submissions.issued');
            Route::post('submissions/{submission}/email', [Admin\SubmissionController::class, 'email'])->name('submissions.email');
            Route::get('submissions/{submission}/workspace', [Admin\SubmissionController::class, 'workspace'])->name('submissions.workspace');

            // the analysis tool (same engine and screens as v1), scoped to one submission's folder
            Route::prefix('submissions/{submission}')->middleware(ScopeSubmission::class)->name('ws.')->group(function () {
                Route::get('api/state', [Admin\WorkspaceController::class, 'state'])->name('state');
                Route::post('api/start', [Admin\WorkspaceController::class, 'start'])->name('start');
                Route::post('api/step', [Admin\WorkspaceController::class, 'step'])->name('step');
                Route::post('api/review', [Admin\WorkspaceController::class, 'review'])->name('review');
                Route::post('api/settings', [Admin\WorkspaceController::class, 'settings'])->name('settings');
                Route::post('api/rules', [Admin\WorkspaceController::class, 'rules'])->name('rules');
                Route::post('api/generate', [Admin\WorkspaceController::class, 'generate'])->name('generate');
                Route::get('out/{name}', [Admin\WorkspaceController::class, 'output'])->where('name', '[A-Za-z0-9._-]+')->name('output');
            });

            Route::get('messages', [Admin\MessageController::class, 'index'])->name('messages');
            Route::get('messages/{message}', [Admin\MessageController::class, 'show'])->name('messages.show');
            Route::delete('messages/{message}', [Admin\MessageController::class, 'destroy'])->name('messages.destroy');

            Route::resource('projects', Admin\ProjectController::class)->except('show');
            Route::resource('services', Admin\ServiceController::class)->except('show');

            Route::get('company', [Admin\SettingsController::class, 'company'])->name('company');
            Route::put('company', [Admin\SettingsController::class, 'saveCompany'])->name('company.save');
            Route::get('settings', [Admin\SettingsController::class, 'settings'])->name('settings');
            Route::put('settings', [Admin\SettingsController::class, 'saveSettings'])->name('settings.save');
            Route::put('account', [Admin\SettingsController::class, 'password'])->name('account.password');
            Route::post('settings/test-mail', [Admin\SettingsController::class, 'testMail'])->name('settings.test-mail');
        });
    });
});
