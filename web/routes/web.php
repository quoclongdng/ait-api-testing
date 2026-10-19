<?php

use App\Http\Controllers\Admin\AllowedTargetController;
use App\Http\Controllers\Admin\BenchmarkController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SpecController;
use App\Http\Controllers\Web\BusinessRuleController;
use App\Http\Controllers\Web\ExperimentController;
use App\Http\Controllers\Web\GenerationJobController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\TestCaseController;
use App\Http\Controllers\Web\TestRunController;
use App\Http\Controllers\Web\TestRunReportController;
use App\Http\Controllers\Web\TestSuiteController;
use App\Http\Middleware\StartSessionWhenCookiePresent;
use App\Models\ApiSpec;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// "/": a signed-in user is sent to the dashboard, a guest to the login screen.
// A first anonymous visit starts no session (no `sessions` row, no cookie); see
// StartSessionWhenCookiePresent. GET only, so dropping the CSRF middleware changes nothing.
Route::get('/', fn (Request $request): RedirectResponse => redirect()->route($request->user() !== null ? 'dashboard' : 'login'))
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->middleware(StartSessionWhenCookiePresent::class)
    ->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.post');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // M06 & M07: OpenAPI Specs Management
    Route::prefix('projects/{project}')->name('projects.')->group(function () {
        Route::get('/specs', [SpecController::class, 'index'])->name('specs.index');
        Route::post('/specs', [SpecController::class, 'store'])
            ->middleware('throttle:spec-uploads')
            ->name('specs.store');
        Route::get('/specs/{spec}', [SpecController::class, 'show'])->name('specs.show');

        // M08: Project Business Rules Management
        Route::get('/business-rules', [BusinessRuleController::class, 'index'])->name('business-rules.index');
        Route::post('/business-rules', [BusinessRuleController::class, 'store'])->name('business-rules.store');
        Route::patch('/business-rules/{businessRule}', [BusinessRuleController::class, 'update'])->name('business-rules.update');
        Route::delete('/business-rules/{businessRule}', [BusinessRuleController::class, 'destroy'])->name('business-rules.destroy');
    });

    // Quick direct link for specs (with strict authorization policy check)
    Route::get('/specs/{spec}', function (ApiSpec $spec) {
        Gate::authorize('view', $spec);

        return redirect()->route('projects.specs.show', [$spec->project_id, $spec]);
    })->name('specs.redirect');

    // Test Suites & M09 Screen
    Route::get('/projects/{project}/suites', [TestSuiteController::class, 'index'])->name('projects.suites.index');
    Route::post('/projects/{project}/suites', [TestSuiteController::class, 'store'])->name('projects.suites.store');
    Route::get('/suites/{suite}', [TestSuiteController::class, 'show'])->name('suites.show');
    Route::delete('/suites/{suite}', [TestSuiteController::class, 'destroy'])->name('suites.destroy');
    Route::post('/suites/{suite}/bulk-action', [TestSuiteController::class, 'bulkAction'])->name('suites.bulk-action');

    // Test Generation & M11 Screen
    Route::get('/suites/{suite}/generation-jobs/create', [GenerationJobController::class, 'create'])->name('suites.generation-jobs.create');
    Route::post('/suites/{suite}/generation-jobs', [GenerationJobController::class, 'store'])
        ->middleware('throttle:generation')
        ->name('suites.generation-jobs.store');
    Route::get('/generation-jobs/{generationJob}', [GenerationJobController::class, 'show'])->name('generation-jobs.show');
    Route::post('/generation-jobs/{generationJob}/cancel', [GenerationJobController::class, 'cancel'])->name('generation-jobs.cancel');

    // Test Runs & M12 Screen (start/cancel/poll go through /api/v1)
    Route::get('/suites/{suite}/runs', [TestRunController::class, 'index'])->name('suites.runs.index');
    Route::get('/runs/{run}', [TestRunController::class, 'show'])->name('runs.show');

    // Test Cases & M10 Screen
    Route::get('/test-cases/{testCase}', [TestCaseController::class, 'show'])->name('test-cases.show');
    Route::patch('/test-cases/{testCase}', [TestCaseController::class, 'update'])->name('test-cases.update');
    Route::post('/test-cases/{testCase}/approve', [TestCaseController::class, 'approve'])->name('test-cases.approve');
    Route::post('/test-cases/{testCase}/archive', [TestCaseController::class, 'archive'])->name('test-cases.archive');

    // M13: Run report (exports via GET /api/v1/runs/{run}/export)
    Route::get('/runs/{run}/report', [TestRunReportController::class, 'show'])->name('runs.report');

    // M15: Experiments (TESTER, ADMIN)
    Route::get('/experiments', [ExperimentController::class, 'index'])->name('experiments.index');
    Route::get('/experiments/{experiment}', [ExperimentController::class, 'show'])->name('experiments.show');

    Route::middleware('role:ADMIN')->prefix('admin')->name('admin.')->group(function () {
        // /admin opens the ADMIN dashboard "Tổng quan quản trị", served by /dashboard for an ADMIN (S14-04).
        Route::redirect('/', '/dashboard')->name('dashboard');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');

        Route::get('/allowed-targets', [AllowedTargetController::class, 'index'])->name('allowed-targets.index');
        Route::post('/allowed-targets', [AllowedTargetController::class, 'store'])->name('allowed-targets.store');
        Route::delete('/allowed-targets/{allowedTarget}', [AllowedTargetController::class, 'destroy'])->name('allowed-targets.destroy');

        // M14: Benchmarks & ground truth
        Route::get('/benchmarks', [BenchmarkController::class, 'index'])->name('benchmarks.index');
        Route::post('/benchmarks/import', [BenchmarkController::class, 'import'])->name('benchmarks.import');
        Route::delete('/benchmarks/{benchmark}', [BenchmarkController::class, 'destroy'])->name('benchmarks.destroy');
    });
});
