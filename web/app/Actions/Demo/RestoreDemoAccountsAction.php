<?php

namespace App\Actions\Demo;

use App\Models\User;
use App\Support\Demo\DemoShowcase;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Creates or restores the shared demo accounts of the showcase (S14-05, ADR-0007 addendum 2026-10-13)
 * to their canonical state: name, role, active, password `123456` (rehashed only when it differs).
 *
 * Every call also invalidates every session and "remember me" cookie of these accounts and clears the
 * failed-login counters keyed by their email (LoginAction, `email|ip`) when the cache store is the
 * database: whatever a visitor did with a shared account (locked it out, kept a session open with a
 * changed role) is undone. Only these four emails are touched; no other account, project or run.
 *
 * The 12-character rule of M03/ait:admin:create does not apply here: these passwords are public by
 * design and never protect real data. Callers check DemoShowcase::enabled() first.
 */
class RestoreDemoAccountsAction
{
    /**
     * @return list<array{email: string, role: string, created: bool, changes: list<string>}>
     */
    public function execute(): array
    {
        $report = DB::transaction(function (): array {
            $report = [];
            foreach (DemoShowcase::accounts() as $account) {
                $user = User::query()->whereRaw('LOWER(email) = ?', [$account['email']])->lockForUpdate()->first();
                $created = $user === null;
                $user ??= new User(['email' => $account['email']]);

                $changes = [];
                if (! $created) {
                    if ($user->name !== $account['name']) {
                        $changes[] = 'tên';
                    }
                    if ($user->role !== $account['role']) {
                        $changes[] = 'vai trò';
                    }
                    if (! $user->is_active) {
                        $changes[] = 'mở khóa';
                    }
                }
                if ($created || ! Hash::check(DemoShowcase::PASSWORD, $user->password)) {
                    $user->password = DemoShowcase::PASSWORD; // hashed by the model cast
                    if (! $created) {
                        $changes[] = 'mật khẩu';
                    }
                }

                $user->forceFill([
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'is_active' => true,
                    // Invalidates every "remember me" cookie issued for the shared account.
                    'remember_token' => Str::random(60),
                ])->save();

                $report[] = [
                    'email' => $account['email'],
                    'role' => $account['role']->value,
                    'created' => $created,
                    'changes' => $changes,
                    'id' => $user->id,
                ];
            }

            // Sessions of the shared accounts (database session driver, the default): sign every visitor out.
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->whereIn('user_id', array_column($report, 'id'))->delete();
            }

            return $report;
        });

        $this->clearLoginThrottle(DemoShowcase::emails());

        return array_map(static fn (array $row): array => [
            'email' => $row['email'],
            'role' => $row['role'],
            'created' => $row['created'],
            'changes' => $row['changes'],
        ], $report);
    }

    /**
     * Failed-login counters `email|ip` (LoginRequest::throttleKey) of the demo emails. Their IPs are
     * unknown, so only a store whose keys can be listed is cleared: the database store of the
     * deployment (CACHE_STORE=database). The per-IP counter is not per account and is left alone.
     *
     * @param  list<string>  $emails
     */
    private function clearLoginThrottle(array $emails): void
    {
        $store = Cache::store()->getStore();
        if (! $store instanceof DatabaseStore) {
            return;
        }

        $store->getConnection()->table((string) config('cache.stores.database.table', 'cache'))->where(function ($query) use ($emails, $store): void {
            foreach ($emails as $email) {
                $query->orWhere('key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $store->getPrefix().$email.'|').'%');
            }
        })->delete();
    }
}
