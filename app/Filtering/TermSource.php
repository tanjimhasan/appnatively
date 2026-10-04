<?php

namespace Crafium\AppNatively\App\Filtering;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\WpMVC\Database\Query\Builder;

/**
 * Where the terms of a term facet (`category`, `tag`, `location`) come from.
 *
 * Most plugins keep them in a taxonomy (TaxonomyTermSource), but some store
 * them their own way, so CatalogQuery narrows and counts through this contract
 * instead of assuming a taxonomy. A source narrows by term id for a page's
 * context (`categories[]`, `tags[]`, `locations[]`) and by slug for a visitor's
 * filter selection (`values[<facet>][]`), the two tokens it also mints in
 * options().
 */
interface TermSource {
    /**
     * The facet's name, as the filter shows it.
     */
    public function label(): string;

    /**
     * Keep posts having any of these term ids.
     *
     * @param int[] $ids
     */
    public function where_ids( Builder $query, array $ids ): void;

    /**
     * Keep posts having any of these term slugs.
     *
     * @param string[] $slugs
     */
    public function where_slugs( Builder $query, array $slugs ): void;

    /**
     * The facet's options in context: [value (a slug), label, post count] triples,
     * in the order they should show. Counts may exceed `$limit` rows internally but
     * the returned list is capped by it.
     *
     * @param callable $fresh_query Returns a NEW Builder of the posts in context every
     *                              call: several are needed, and Builder::count() counts
     *                              differently once a builder has been grouped.
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    public function options( callable $fresh_query, int $limit ): array;
}
