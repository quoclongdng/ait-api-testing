<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoProjectSeeder extends Seeder
{
    /**
     * Seed a demo project matching screens wireframe.
     */
    public function run(): void
    {
        // ADR-0007: no demo data in production.
        if (app()->environment('production')) {
            return;
        }

        $admin = User::first();
        if (! $admin) {
            return;
        }

        Project::firstOrCreate(
            [
                'name' => 'Hệ thống Quản lý Bán hàng',
                'owner_id' => $admin->id,
            ],
            [
                'description' => 'Kiểm thử API đơn hàng, sản phẩm và kho hàng (Simple Store)',
                'default_target_url' => 'http://benchmark:8000',
            ]
        );
    }
}
