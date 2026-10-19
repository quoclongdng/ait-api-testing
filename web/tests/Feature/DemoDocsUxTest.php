<?php

namespace Tests\Feature;

use App\Models\ApiSpec;
use App\Models\Benchmark;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S13-03 regressions from the clean-clone verification of the demo and the user guide.
 */
class DemoDocsUxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * M14 listed benchmarks alphabetically ("[Benchmark #1] Appointment Booking"), while docs/DESIGN.md §7
     * and the guide number simple-store #1, ecommerce-order #2, appointment-booking #3.
     */
    public function test_m14_numbers_benchmarks_canonically_then_others_by_slug(): void
    {
        $admin = User::factory()->admin()->create();
        foreach ([
            'zz-custom' => 'ZZ Custom',
            'appointment-booking' => 'Appointment Booking',
            'aa-custom' => 'AA Custom',
            'ecommerce-order' => 'Ecommerce Order',
            'simple-store' => 'Simple Store',
        ] as $slug => $name) {
            Benchmark::factory()->create(['slug' => $slug, 'name' => $name, 'version' => 'v1.0']);
        }

        $this->actingAs($admin)->get(route('admin.benchmarks.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '[Benchmark #1] Simple Store (v1.0)',
                '[Benchmark #2] Ecommerce Order (v1.0)',
                '[Benchmark #3] Appointment Booking (v1.0)',
                '[Benchmark #4] AA Custom (v1.0)',
                '[Benchmark #5] ZZ Custom (v1.0)',
            ], false);
    }

    public function test_m14_keeps_the_canonical_number_when_only_some_benchmarks_are_imported(): void
    {
        $admin = User::factory()->admin()->create();
        Benchmark::factory()->create(['slug' => 'appointment-booking', 'name' => 'Appointment Booking', 'version' => 'v1.0']);

        $this->actingAs($admin)->get(route('admin.benchmarks.index'))
            ->assertOk()
            ->assertSee('[Benchmark #3] Appointment Booking (v1.0)', false)
            ->assertDontSee('[Benchmark #1]', false);
    }

    /**
     * M08 cited a non-existent "docs/DESIGN.md §1.2"; later QA (S13-03) removed internal document
     * references from end-user copy altogether.
     */
    public function test_m08_subtitle_has_no_internal_document_references(): void
    {
        $owner = User::factory()->tester()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        ApiSpec::factory()->create(['project_id' => $project->id]);

        $this->actingAs($owner)->get("/projects/{$project->id}/business-rules")
            ->assertOk()
            ->assertDontSee('§', false)
            ->assertDontSee('docs/DESIGN.md', false)
            ->assertSee('dùng làm ngữ cảnh khi sinh test biên', false);
    }
}
