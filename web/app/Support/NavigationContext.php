<?php

namespace App\Support;

use App\Models\ApiSpec;
use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\TestCase;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Http\Request;

/**
 * Project (and test suite) the current page belongs to, so the sidebar shows that project's contextual
 * links (M06/M07, M08, M09, M12) instead of only the project list (S13-03, S14-04). Resolved only from
 * models bound to the current route (never a view variable) and only when the signed-in user may view
 * the project.
 */
final class NavigationContext
{
    public static function project(Request $request): ?Project
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        $project = null;
        $bound = $route->parameter('project');
        if ($bound instanceof Project) {
            $project = $bound;
        } else {
            // boundSuite() also covers generation jobs (test_suite_id is required on generation_jobs).
            $suite = self::boundSuite($request);
            if ($suite instanceof TestSuite) {
                $project = $suite->project;
            } elseif (($spec = $route->parameter('spec')) instanceof ApiSpec) {
                $project = $spec->project;
            }
        }

        if (! $project instanceof Project || ! ($request->user()?->can('view', $project) ?? false)) {
            return null;
        }

        return $project;
    }

    /**
     * Suite whose runs the "Lần chạy" link opens inside $project: the suite bound to the current route
     * (suite, run, test case or generation job screens), otherwise the project's latest suite; null when
     * the project has no suite yet.
     */
    public static function runsSuite(Request $request, Project $project): ?TestSuite
    {
        $suite = self::boundSuite($request);
        if ($suite instanceof TestSuite && $suite->project_id === $project->id) {
            return $suite;
        }

        return $project->testSuites()->latest('id')->first();
    }

    private static function boundSuite(Request $request): ?TestSuite
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        return match (true) {
            ($value = $route->parameter('suite')) instanceof TestSuite => $value,
            ($value = $route->parameter('run')) instanceof TestRun => $value->testSuite,
            ($value = $route->parameter('testCase')) instanceof TestCase => $value->suite,
            ($value = $route->parameter('generationJob')) instanceof GenerationJob => $value->testSuite,
            default => null,
        };
    }
}
