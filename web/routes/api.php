<?php

use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AllowedTargetController;
use App\Http\Controllers\Api\V1\ApiSpecController;
use App\Http\Controllers\Api\V1\BenchmarkController;
use App\Http\Controllers\Api\V1\BusinessRuleController;
use App\Http\Controllers\Api\V1\EndpointController;
use App\Http\Controllers\Api\V1\ExperimentController;
use App\Http\Controllers\Api\V1\GenerationJobController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TestCaseController;
use App\Http\Controllers\Api\V1\TestRunController;
use App\Http\Controllers\Api\V1\TestSuiteController;
use Illuminate\Support\Facades\Route;

/*
 * JSON API consumed by the dashboard (Alpine). Contract: docs/contracts/web-api.openapi.yaml.
 * All routes are prefixed with /api by bootstrap/app.php.
 */
Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    // The internal JSON API uses the same Laravel session cookie as the Blade dashboard (D11).
    Route::middleware(['web', 'auth'])->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('api.v1.projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('api.v1.projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('api.v1.projects.show');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('api.v1.projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('api.v1.projects.destroy');

        Route::get('/projects/{project}/business-rules', [BusinessRuleController::class, 'index'])
            ->name('api.v1.projects.business-rules.index');
        Route::post('/projects/{project}/business-rules', [BusinessRuleController::class, 'store'])
            ->name('api.v1.projects.business-rules.store');
        Route::get('/business-rules/{businessRule}', [BusinessRuleController::class, 'show'])->name('api.v1.business-rules.show');
        Route::patch('/business-rules/{businessRule}', [BusinessRuleController::class, 'update'])->name('api.v1.business-rules.update');
        Route::delete('/business-rules/{businessRule}', [BusinessRuleController::class, 'destroy'])->name('api.v1.business-rules.destroy');

        Route::get('/projects/{project}/suites', [TestSuiteController::class, 'index'])->name('api.v1.projects.suites.index');
        Route::post('/projects/{project}/suites', [TestSuiteController::class, 'store'])->name('api.v1.projects.suites.store');
        Route::get('/suites/{suite}', [TestSuiteController::class, 'show'])->name('api.v1.suites.show');

        // ADMIN only. The role is checked before route-model binding (priority list in bootstrap/app.php),
        // so a TESTER gets 403 FORBIDDEN whether or not the id exists and cannot enumerate ids (S13-03).
        // The policies in the controllers and FormRequests still apply.
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('/admin/users', [AdminUserController::class, 'index'])->name('api.v1.admin.users.index');
            Route::post('/admin/users', [AdminUserController::class, 'store'])->name('api.v1.admin.users.store');
            Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->name('api.v1.admin.users.update');

            Route::get('/admin/allowed-targets', [AllowedTargetController::class, 'index'])
                ->name('api.v1.admin.allowed-targets.index');
            Route::post('/admin/allowed-targets', [AllowedTargetController::class, 'store'])
                ->name('api.v1.admin.allowed-targets.store');
            Route::delete('/admin/allowed-targets/{allowedTarget}', [AllowedTargetController::class, 'destroy'])
                ->name('api.v1.admin.allowed-targets.destroy');

            Route::get('/admin/benchmarks', [BenchmarkController::class, 'index'])->name('api.v1.admin.benchmarks.index');
            Route::post('/admin/benchmarks', [BenchmarkController::class, 'store'])->name('api.v1.admin.benchmarks.store');
            Route::delete('/admin/benchmarks/{benchmark}', [BenchmarkController::class, 'destroy'])->name('api.v1.admin.benchmarks.destroy');
        });

        Route::get('/experiments', [ExperimentController::class, 'index'])->name('api.v1.experiments.index');
        Route::post('/experiments', [ExperimentController::class, 'store'])
            ->middleware('throttle:experiments')
            ->name('api.v1.experiments.store');
        Route::get('/experiments/{experiment}', [ExperimentController::class, 'show'])->name('api.v1.experiments.show');
        Route::get('/experiments/{experiment}/export', [ExperimentController::class, 'export'])->name('api.v1.experiments.export');

        Route::post('/projects/{project}/specs', [ApiSpecController::class, 'store'])
            ->middleware('throttle:spec-uploads')
            ->name('api.v1.projects.specs.store');
        Route::get('/specs/{spec}', [ApiSpecController::class, 'show'])->name('api.v1.specs.show');
        Route::get('/specs/{spec}/endpoints', [EndpointController::class, 'index'])->name('api.v1.specs.endpoints.index');

        Route::post('/suites/{suite}/runs', [TestRunController::class, 'store'])
            ->middleware('throttle:test-runs')
            ->name('api.v1.suites.runs.store');
        Route::get('/runs/{run}', [TestRunController::class, 'show'])->name('api.v1.runs.show');
        Route::get('/runs/{run}/results', [TestRunController::class, 'results'])->name('api.v1.runs.results');
        Route::get('/runs/{run}/export', [TestRunController::class, 'export'])->name('api.v1.runs.export');
        Route::post('/runs/{run}/cancel', [TestRunController::class, 'cancel'])->name('api.v1.runs.cancel');

        Route::get('/suites/{suite}/test-cases', [TestCaseController::class, 'index'])->name('api.v1.suites.test-cases.index');
        Route::get('/test-cases/{testCase}', [TestCaseController::class, 'show'])->name('api.v1.test-cases.show');
        Route::patch('/test-cases/{testCase}', [TestCaseController::class, 'update'])->name('api.v1.test-cases.update');
        Route::post('/test-cases/{testCase}/approve', [TestCaseController::class, 'approve'])->name('api.v1.test-cases.approve');
        Route::post('/test-cases/{testCase}/archive', [TestCaseController::class, 'archive'])->name('api.v1.test-cases.archive');

        Route::post('/suites/{suite}/generation-jobs', [GenerationJobController::class, 'store'])
            ->middleware('throttle:generation')
            ->name('api.v1.suites.generation-jobs.store');
        Route::get('/generation-jobs/{generationJob}', [GenerationJobController::class, 'show'])
            ->name('api.v1.generation-jobs.show');
    });
});
