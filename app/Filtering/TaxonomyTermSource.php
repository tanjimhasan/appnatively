<?php

namespace Crafium\AppNatively\App\Filtering;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\WpMVC\Database\Query\Builder;

/**
 * A term facet backed by a WordPress taxonomy: the usual case, and what
 * `category`, `tag` and the `taxonomy:`/`attribute:` facets all use.
 *
 * In a hierarchical taxonomy, picking a term includes its children, so parent
 * terms are offered even without posts of their own and counted with their
 * descendants.
 */
final class TaxonomyTermSource implements TermSource {
    private string $taxonomy;

    /** The facet's name; null takes the taxonomy's own singular name (see label()). */
    private ?string $label;

    public function __construct( string $taxonomy, ?string $label = null ) {
        $this->taxonomy = $taxonomy;
        $this->label    = $label;
    }

    /**
     * Resolved late: a source is built every time a catalog describes its facets,
     * long before any label is shown.
     */
    public function label(): string {
        if ( $this->label !== null ) {
            return $this->label;
        }
        $object = get_taxonomy( $this->taxonomy );
        return $object ? (string) $object->labels->singular_name : $this->taxonomy;
    }

    public function where_ids( Builder $query, array $ids ): void {
        $this->where_has_terms( $query, $this->with_descendants( array_map( 'intval', $ids ) ) );
    }

    public function where_slugs( Builder $query, array $slugs ): void {
        $slugs    = array_values( array_filter( array_map( 'sanitize_title', $slugs ) ) );
        $term_ids = empty( $slugs ) ? [] : get_terms(
            [
                'taxonomy'   => $this->taxonomy,
                'slug'       => $slugs,
                'fields'     => 'ids',
                'hide_empty' => false,
            ]
        );

        if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
            // The requested option(s) don't exist, so nothing can match.
            $query->where( 'posts.ID', '=', 0 );
            return;
        }

        $this->where_has_terms( $query, $this->with_descendants( array_map( 'intval', $term_ids ) ) );
    }

    public function options( callable $fresh_query, int $limit ): array {
        $rows = $fresh_query()
            ->join( 'term_relationships', 'posts.ID', '=', 'term_relationships.object_id' )
            ->join( 'term_taxonomy', 'term_relationships.term_taxonomy_id', '=', 'term_taxonomy.term_taxonomy_id' )
            ->join( 'terms', 'term_taxonomy.term_id', '=', 'terms.term_id' )
            ->where( 'term_taxonomy.taxonomy', $this->taxonomy )
            ->select( [ 'terms.term_id as term_id', 'terms.slug as value', 'terms.name as label', 'COUNT(DISTINCT posts.ID) as post_count' ] )
            ->group_by( [ 'terms.term_id', 'terms.slug', 'terms.name' ] )
            ->order_by( 'terms.name', 'asc' )
            ->limit( $limit )
            ->get();

        $terms = [];
        foreach ( $rows as $row ) {
            $terms[ (int) $row->term_id ] = [ (string) $row->value, (string) $row->label, (int) $row->post_count ];
        }

        if ( ! empty( $terms ) && is_taxonomy_hierarchical( $this->taxonomy ) ) {
            $parents = [];
            foreach ( array_keys( $terms ) as $term_id ) {
                foreach ( get_ancestors( $term_id, $this->taxonomy, 'taxonomy' ) as $ancestor_id ) {
                    $parents[ (int) $ancestor_id ] = true;
                }
                $children = get_term_children( $term_id, $this->taxonomy );
                if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
                    $parents[ $term_id ] = true;
                }
            }
            foreach ( array_keys( $parents ) as $parent_id ) {
                $term = get_term( $parent_id, $this->taxonomy );
                if ( ! $term || is_wp_error( $term ) ) {
                    continue;
                }
                // A fresh, ungrouped query: Builder::count() counts groups once one is grouped.
                $query = $fresh_query();
                $this->where_has_terms( $query, $this->with_descendants( [ $parent_id ] ) );
                $terms[ $parent_id ] = [ (string) $term->slug, (string) $term->name, (int) $query->count() ];
            }
            uasort( $terms, fn( $a, $b ) => strcasecmp( $a[1], $b[1] ) );
        }

        return array_values( $terms );
    }

    /**
     * Keep posts that have any of `$term_ids`.
     *
     * @param int[] $term_ids
     */
    private function where_has_terms( Builder $query, array $term_ids ): void {
        $taxonomy = $this->taxonomy;
        $query->where_has(
            'terms', function( $q ) use ( $taxonomy, $term_ids ) {
                $q->where( 'taxonomy', $taxonomy )
                    ->where_in( 'term_id', array_values( $term_ids ) );
            }
        );
    }

    /**
     * `$term_ids` plus their descendants, for a hierarchical taxonomy.
     *
     * @param int[] $term_ids
     * @return int[]
     */
    private function with_descendants( array $term_ids ): array {
        if ( ! is_taxonomy_hierarchical( $this->taxonomy ) ) {
            return $term_ids;
        }
        $all = $term_ids;
        foreach ( $term_ids as $term_id ) {
            $children = get_term_children( $term_id, $this->taxonomy );
            if ( ! is_wp_error( $children ) ) {
                $all = array_merge( $all, array_map( 'intval', $children ) );
            }
        }
        return array_values( array_unique( $all ) );
    }
}
