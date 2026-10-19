<?php

namespace App\Actions\TestSuites;

use App\Enums\TestCaseStatus;
use App\Models\TestCase;
use App\Models\TestSuite;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListSuiteTestCasesAction
{
    /**
     * M09: filtered, paginated test cases of a suite plus per-status counts.
     *
     * @param  array{search?: string|null, source?: string|null, technique?: string|null, status?: string|null, per_page?: int|null}  $filters
     * @return array{cases: LengthAwarePaginator<int, TestCase>, stats: array<string, int>}
     */
    public function execute(TestSuite $suite, array $filters): array
    {
        $query = $suite->testCases()->with('endpoint')->latest('id');

        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like): void {
                $q->where('title', 'ilike', $like)
                    ->orWhere('fingerprint', 'ilike', $like);
            });
        }

        foreach (['source', 'technique', 'status'] as $column) {
            $value = $filters[$column] ?? null;
            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        $counts = [];
        foreach ($suite->testCases()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate;
        }

        $stats = ['total' => array_sum($counts)];
        foreach (TestCaseStatus::cases() as $status) {
            $stats[strtolower($status->value)] = $counts[$status->value] ?? 0;
        }

        return [
            'cases' => $query->paginate($filters['per_page'] ?? 15)->withQueryString(),
            'stats' => $stats,
        ];
    }
}
