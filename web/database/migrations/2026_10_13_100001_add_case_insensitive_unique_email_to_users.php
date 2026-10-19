<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA S13-03: users_email_unique is a plain btree, so on PostgreSQL QA-User@ait.local and
 * qa-user@ait.local could be two accounts and login was case-sensitive. Emails are now stored in
 * lower case (User::email mutator) and lower(email) is unique.
 *
 * Existing mixed-case rows are lower-cased first. When two accounts already differ only by letter
 * case the migration stops and names them: the operator must change the email of the extra accounts first, since
 * picking which account survives is not a decision a migration can make.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $collisions = DB::table('users')
            ->selectRaw('LOWER(email) AS email, COUNT(*) AS accounts')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('LOWER(email)')
            ->pluck('email')
            ->all();
        if ($collisions !== []) {
            throw new RuntimeException(
                'Cannot create users_email_lower_unique: these emails belong to several accounts that differ only by letter case: '
                .implode(', ', $collisions)
                .'. Change the email of all but one of them (UPDATE users SET email = ... WHERE id = ...), then run the migration again.'
            );
        }

        DB::statement('UPDATE users SET email = LOWER(email) WHERE email <> LOWER(email)');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');
    }

    /**
     * Drops the index only: the lower-cased emails stay as they are (the original case is not kept).
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
