<?php

namespace App\Support\Demo;

use App\Enums\GenerationStrategy;
use App\Enums\UserRole;

/**
 * Catalog of the public demo showcase (S14-05, ADR-0007 addendum 2026-10-13: tài khoản demo công khai).
 *
 * The accounts and their password are PUBLIC on purpose (login page, docs): they are shared by every
 * visitor of the demo site and are restored to this canonical state on every deploy and every night
 * (`php artisan ait:demo:showcase --restore-accounts`). They never guard real data or secrets.
 * Everything here is inert unless `DEMO_SHOWCASE_ENABLED=true` (config ait.demo_showcase.enabled).
 */
final class DemoShowcase
{
    /** Shared password of every demo account (deliberately public, see the class comment). */
    public const PASSWORD = '123456';

    public const ADMIN_EMAIL = 'admin@demo.ait.local';

    /** Seed of repetition 1 of the official experiment: the recorded LLM/HYBRID jobs shown in the demo. */
    public const RECORDED_SEED = 42;

    /** Strategies whose recorded job (seed 42) fills the "LLM/HYBRID (bản ghi thực nghiệm)" suite. */
    public const RECORDED_STRATEGIES = [GenerationStrategy::Llm, GenerationStrategy::Hybrid];

    public const SPEC_VERSION = 'v1.0';

    public const RULE_SUITE_NAME = 'RULE (sinh trực tiếp)';

    public const RECORDED_SUITE_NAME = 'LLM/HYBRID (bản ghi thực nghiệm)';

    /**
     * Earlier canonical suite descriptions of the showcase (with internal screen codes): replaced by the
     * current one on the next `ait:demo:showcase` unless a visitor changed them (QA S14-06).
     */
    public const LEGACY_SUITE_DESCRIPTIONS = [
        'Job LLM và HYBRID (seed 42) của thực nghiệm chính thức benchmark-v1.0, nạp lại không gọi LLM; log LLM nguyên văn xem ở M10.',
    ];

    /** Same RULE parameter as the official experiment (experiment-design.md §4.2). */
    public const RULE_MAX_CASES_PER_ENDPOINT = 10;

    /**
     * Description of the recorded LLM/HYBRID suite (screen names only, no internal codes).
     */
    public static function recordedSuiteDescription(): string
    {
        return 'Job LLM và HYBRID (seed '.self::RECORDED_SEED.') của thực nghiệm chính thức benchmark-v1.0, nạp lại không gọi LLM; '
            .'log LLM nguyên văn xem ở tab "Log gọi LLM" của từng test case. Chạy lại suite này trên benchmark của nền tảng dùng '
            .'token sandbox công khai của benchmark như lượt chạy đã ghi.';
    }

    public static function enabled(): bool
    {
        return (bool) config('ait.demo_showcase.enabled', false);
    }

    /**
     * @return list<array{email: string, name: string, role: UserRole}>
     */
    public static function accounts(): array
    {
        return [
            ['email' => self::ADMIN_EMAIL, 'name' => 'Quản trị viên Demo', 'role' => UserRole::Admin],
            ['email' => 'tester@demo.ait.local', 'name' => 'Tester Demo 1', 'role' => UserRole::Tester],
            ['email' => 'tester2@demo.ait.local', 'name' => 'Tester Demo 2', 'role' => UserRole::Tester],
            ['email' => 'tester3@demo.ait.local', 'name' => 'Tester Demo 3', 'role' => UserRole::Tester],
        ];
    }

    /**
     * One demo project per benchmark, each owned by one demo TESTER.
     *
     * @return list<array{owner: string, slug: string, name: string}>
     */
    public static function projects(): array
    {
        return [
            ['owner' => 'tester@demo.ait.local', 'slug' => 'simple-store', 'name' => 'Demo – Simple Store'],
            ['owner' => 'tester2@demo.ait.local', 'slug' => 'ecommerce-order', 'name' => 'Demo – E-commerce Order'],
            ['owner' => 'tester3@demo.ait.local', 'slug' => 'appointment-booking', 'name' => 'Demo – Appointment Booking'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function emails(): array
    {
        return array_map(static fn (array $account): string => $account['email'], self::accounts());
    }

    /**
     * Accounts listed on the login page: empty unless the showcase is enabled.
     *
     * @return list<array{email: string, role: string}>
     */
    public static function loginHints(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return array_map(static fn (array $account): array => [
            'email' => $account['email'],
            'role' => $account['role'] === UserRole::Admin ? 'Quản trị viên (ADMIN)' : 'Kiểm thử viên (TESTER)',
        ], self::accounts());
    }
}
