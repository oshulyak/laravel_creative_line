<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

abstract class AbstractFilter {
    /**
     * Разрешённые ключи фильтра. Каждому ключу соответствует одноимённый метод.
     *
     * @var list<string>
     */
    protected array $keys = [];

    /**
     * Применяет к запросу все пришедшие в $data фильтры.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(Builder $builder, array $data): Builder {
        foreach ($this->keys as $key) {
            if (isset($data[$key])) {
                $methodName = Str::camel($key);
                $this->{$methodName}($builder, $data[$key]);
            }
        }

        return $builder;
    }
}
