<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Route model binding for resources owned by a project: a resource whose project was soft-deleted
 * (DELETE /projects/{id}) resolves to 404 NOT_FOUND like the project itself, for every role,
 * instead of reaching policies that dereference a null `project` relation (500 INTERNAL_ERROR).
 */
trait BelongsToLiveProject
{
    /**
     * Dotted relation path from this model to its Project (e.g. "project", "testSuite.project").
     */
    abstract protected function liveProjectRelation(): string;

    /**
     * Only rows whose project is not soft-deleted. System-wide listings and counts (ADMIN M02)
     * start from the child table, so the SoftDeletes scope of Project is never applied there by
     * itself: without this a deleted project's runs show up with links that are 404 (S14-06).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithLiveProject(Builder $query): void
    {
        $query->whereHas($this->liveProjectRelation());
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery($this->newQuery(), $value, $field)
            ->whereHas($this->liveProjectRelation())
            ->first();
    }
}
