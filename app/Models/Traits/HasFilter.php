<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasFilter {
    /**
     * Scope, применяющий к запросу фильтр модели.
     * Класс фильтра определяется по имени модели: Post → App\Filters\PostFilter.
     *
     * @param  array<string, mixed>  $data
     */
    public function scopeFilter(Builder $builder, array $data): Builder {
        $className = 'App\\Filters\\'.class_basename($this).'Filter';

        return (new $className)->apply($builder, $data);
    }
}
