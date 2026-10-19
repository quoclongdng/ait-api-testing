<?php

namespace App\Models\Concerns;

use LogicException;

trait InsertOnly
{
    /**
     * Boot the insert-only trait for an immutable model.
     */
    public static function bootInsertOnly(): void
    {
        static::updating(function (): never {
            throw new LogicException(static::class.' is an append-only model and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException(static::class.' is an append-only model and cannot be deleted.');
        });
    }
}
