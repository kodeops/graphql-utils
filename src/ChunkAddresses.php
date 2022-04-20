<?php
namespace kodeops\LaravelGraphQL;

use kodeops\LaravelGraphQL\Query;
use Illuminate\Support\Arr;

class ChunkAddresses
{
    public static function resolve(
        $indexer, 
        $query, 
        $variables, 
        $replace_needle,
        $fragments, 
        $results_needle
    )
    {
        $addresses = Arr::get($variables, $replace_needle);

        if (count($addresses) > 150) {
            $events = collect([]);
            foreach (collect($addresses)->chunk(100) as $chunk) {
                Arr::set($variables, $replace_needle, $chunk->values()->toArray());
                $events = $events->merge((new Query($indexer))
                    ->resolve($query, $variables, $fragments)['data'][$results_needle]);
            }
            return $events;
        }

        return (new Query($indexer))->resolve($query, $variables, $fragments)['data'][$results_needle];
    }
}