<?php

namespace Crafium\AppNatively\App\Filtering;

defined( "ABSPATH" ) || exit;

use Closure;
use WP_REST_Request;
use Crafium\AppNatively\App\Models\Post;
use Crafium\AppNatively\App\DTO\Filter\FacetDTO;
use Crafium\AppNatively\App\DTO\Filter\FacetOptionDTO;
use Crafium\AppNatively\App\DTO\Filter\FilterSourceDTO;
use Crafium\AppNatively\App\DTO\Filter\FiltersDTO;
use Crafium\AppNatively\WpMVC\Database\Query\Builder;

/**
 * Lists and their filter's facets for one plugin's catalog (products or
 * directory listings, see Catalog).
 *
 * A request narrows the posts in two layers:
 *
 * - Page context: `categories[]`, `tags[]`, `locations[]` (term ids; hierarchical
 *   taxonomies include children), `search`, and a list's own flags
 *   (Catalog::context_flags(), e.g. `in_stock`, `featured`).
 * - The shopper's filter selection, keyed by facet id: `values[<facet>][]`,
 *   `ranges[<facet>][min|max]`, `sort`, and `near[lat|lng]` for distance.
 *
 * Facet ids: term facets (`category`, `tag`, `location`), `taxonomy:<name>`,
 * `attribute:<name>`, ranges (`price`, `rating`, and `distance` in km),
 * flags (`availability`, `status`), `meta:<key>` (ACF and allowed meta) and
 * `field:<key>` (the plugin's own fields). Term and field values are OR-ed
 * within a facet, flags are AND-ed, and facets are AND-ed.
 *
 * A term facet is backed by a TermSource, which need not be a taxonomy: it
 * narrows by term id for the page context and by slug for the selection, and
 * mints the option values itself.
 */
class CatalogQuery {
    /** Facets computed per filters request at most. */
    private const MAX_FACETS = 8;

    /** Options returned per choice facet at most. */
    private const MAX_FACET_OPTIONS = 100;

    /** Values read per facet at most, and their longest accepted length. */
    private const MAX_SELECTED_VALUES = 50;
    private const MAX_VALUE_LENGTH    = 200;

    /** Seconds a filters response is cached for one context (one with time-dependent facets: a minute). */
    private const FACETS_CACHE_TTL   = 5 * MINUTE_IN_SECONDS;
    private const FACETS_CACHE_GROUP = 'craf_appna_filters';

    /** Discovered meta keys listed to the builder at most. */
    private const MAX_DISCOVERED_META_KEYS = 200;

    /** ACF field types that make sense as a filter. */
    private const ACF_FILTERABLE_TYPES = [ 'text', 'number', 'range', 'select', 'checkbox', 'radio', 'button_group', 'true_false' ];

    /** Context params narrowing by terms => the term facet they narrow by. */
    private const CONTEXT_TERM_PARAMS = [
        'categories' => 'category',
        'tags'       => 'tag',
        'locations'  => 'location',
    ];

    private const EARTH_RADIUS_KM = 6371;

    private Catalog $catalog;

    /** @var array<string, Field>|null Memoized ACF fields placed on the post types (see acf_fields()). */
    private ?array $acf_fields = null;

    /** @var string[]|null Memoized custom-field allow-list (see allowed_fields()). */
    private ?array $allowed_fields = null;

    public function __construct( Catalog $catalog ) {
        $this->catalog = $catalog;
    }

    /**
     * Published, unprotected posts the site lists, with the catalog's joins.
     *
     * @param bool $searching Whether the list is a search (visibility can differ).
     * @return Builder
     */
    public function base( bool $searching = false ): Builder {
        $query = Post::where_in( 'posts.post_type', $this->post_types() )
            ->where( 'posts.post_status', 'publish' )
            ->where( 'posts.post_password', '' );
        $this->catalog->prepare( $query );
        $this->catalog->where_visible( $query, $searching );
        return $query;
    }

    /**
     * One page of the request's posts (context, filter selection and sort), as ids in order.
     *
     * @param WP_REST_Request $request
     * @return array{ids: int[], page: int, per_page: int, total: int, last_page: int}
     */
    public function paginate( WP_REST_Request $request ): array {
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 10 ) );
        $query    = $this->context_query( $request )->select( [ 'posts.ID' ] );

        foreach ( $this->sort_orders( $request ) as [ $expression, $direction ] ) {
            // The builder quotes a bare `table.column`; any other expression is parenthesized so it isn't.
            $query->order_by( preg_match( '/^[A-Za-z_][\w]*(\.[A-Za-z_][\w]*)?$/', $expression ) ? $expression : "({$expression})", $direction );
        }

        // The id tiebreaker keeps pages stable when many posts share a price or name.
        $paginator = $query->order_by( 'posts.ID', 'desc' )->paginate( $page, $per_page, 1 );

        $ids = [];
        foreach ( $paginator->items() as $post ) {
            $ids[] = (int) $post->ID;
        }

        return [
            'ids'       => $ids,
            'page'      => $page,
            'per_page'  => $per_page,
            'total'     => (int) $paginator->total(),
            'last_page' => max( 1, (int) $paginator->last_page() ),
        ];
    }

    /**
     * The facets a filters request asks for (`facets[]`, at most MAX_FACETS), with counts
     * for the request's context, plus the sort orders this catalog supports.
     *
     * Cached in the object cache; a context without search text, a selection or a location
     * (what a filter opens with) is also kept as a transient, so the number of transients
     * stays bounded by the site's terms and locations. Time-dependent facets (open now) are
     * cached for a minute and never as a transient.
     *
     * @param WP_REST_Request $request
     * @return FiltersDTO
     */
    public function filters( WP_REST_Request $request ): FiltersDTO {
        $dto       = ( new FiltersDTO() )->set_sort_options( $this->sort_options() );
        $facet_ids = $this->requested_facet_ids( $request );
        $context   = $this->context_cache_params( $request );
        $volatile  = ! empty( array_intersect( $facet_ids, $this->catalog->volatile_facets() ) );
        $key_parts = [ $this->post_types(), $facet_ids, $context ];
        if ( $volatile ) {
            $key_parts[] = (int) floor( time() / MINUTE_IN_SECONDS );
        }
        $cache_key = 'craf_appna_filters_' . md5( (string) wp_json_encode( $key_parts ) );
        $persist   = ! $volatile && $context['search'] === '' && empty( $context['values'] ) && empty( $context['ranges'] ) && $context['near'] === null;

        $cached = wp_cache_get( $cache_key, self::FACETS_CACHE_GROUP );
        if ( ! is_array( $cached ) && $persist ) {
            $cached = get_transient( $cache_key );
        }
        if ( is_array( $cached ) ) {
            return $dto->set_facets( $cached );
        }

        $facets = [];
        foreach ( $facet_ids as $facet_id ) {
            $facet = $this->build_facet( $facet_id, $request );
            if ( $facet ) {
                $facets[] = $facet;
            }
        }

        wp_cache_set( $cache_key, $facets, self::FACETS_CACHE_GROUP, $volatile ? MINUTE_IN_SECONDS : self::FACETS_CACHE_TTL );
        if ( $persist ) {
            set_transient( $cache_key, $facets, self::FACETS_CACHE_TTL );
        }

        return $dto->set_facets( $facets );
    }

    /**
     * What a merchant can add as filter rows in the app builder: extra and attribute
     * taxonomies, the plugin's own fields, and custom fields. Fields outside the
     * allow-list are listed with `enabled: false` (filtering by them does nothing
     * until the site allows them, see allowed_fields()).
     *
     * @return FilterSourceDTO[]
     */
    public function filter_sources(): array {
        $sources = [];

        foreach ( $this->catalog->taxonomy_groups() as $prefix => $taxonomies ) {
            foreach ( $taxonomies as $taxonomy => $label ) {
                $sources[] = $this->source( $prefix, $taxonomy, $label, Field::TYPE_CHOICE, true );
            }
        }

        $allowed = $this->allowed_fields();
        foreach ( $this->catalog->fields() as $key => $field ) {
            $sources[] = $this->source( 'field', $key, $field->label, $field->value_type, in_array( "field:{$key}", $allowed, true ) );
        }

        $meta_keys = array_keys( $this->acf_fields() );
        foreach ( $allowed as $facet_id ) {
            if ( str_starts_with( $facet_id, 'meta:' ) ) {
                $meta_keys[] = substr( $facet_id, 5 );
            }
        }
        $meta_keys = array_values( array_unique( $meta_keys ) );
        foreach ( array_merge( $meta_keys, array_diff( $this->discover_meta_keys(), $meta_keys ) ) as $key ) {
            $field     = $this->meta_field( $key );
            $sources[] = $this->source( 'meta', $key, $field->label, $field->value_type, in_array( "meta:{$key}", $allowed, true ) );
        }

        return $sources;
    }

    /**
     * Sort tokens this catalog supports, in FiltersDTO::SORT_TOKENS order.
     *
     * @return string[]
     */
    private function sort_options(): array {
        $tokens = array_keys( $this->catalog->sorts() );
        if ( $this->catalog->coordinates() ) {
            $tokens[] = 'distance';
        }
        return array_values( array_intersect( FiltersDTO::SORT_TOKENS, $tokens ) );
    }

    /**
     * The request's sort as [expression, direction] pairs; `distance` needs `near`
     * and falls back to relevance without it.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function sort_orders( WP_REST_Request $request ): array {
        $token = $this->scalar( $request->get_param( 'sort' ) );
        if ( $token === 'distance' ) {
            $distance = $this->distance_sql( $request );
            if ( $distance ) {
                // Posts without a location (NULL) go last, not first.
                return [ [ "({$distance} IS NULL)", 'asc' ], [ $distance, 'asc' ] ];
            }
        }

        $sorts  = $this->catalog->sorts();
        $sort   = $sorts[ $token ] ?? $sorts['relevance'];
        $orders = [];
        foreach ( is_array( $sort[0] ) ? $sort : [ $sort ] as [ $expression, $direction ] ) {
            // MySQL sorts NULL first ascending: posts without the value (no price) go last.
            if ( $direction === 'asc' && ! str_starts_with( $expression, 'posts.' ) ) {
                $orders[] = [ "({$expression} IS NULL)", 'asc' ];
            }
            $orders[] = [ $expression, $direction ];
        }
        return $orders;
    }

    private function source( string $source, string $key, string $label, string $value_type, bool $enabled ): FilterSourceDTO {
        return ( new FilterSourceDTO() )->set_source( $source )->set_key( $key )->set_label( $label )
            ->set_value_type( $value_type )->set_enabled( $enabled );
    }

    /**
     * The catalog's post types; a catalog without any (no directory set up yet) lists nothing.
     *
     * @return string[]
     */
    private function post_types(): array {
        return $this->catalog->post_types() ?: [ '' ];
    }

    // -------------------------------------------------------------------------
    // Request reading. Every nested param is read as scalars only: a malformed
    // request narrows nothing instead of reaching a string function with an array.
    // -------------------------------------------------------------------------

    /**
     * @param mixed $value
     */
    private function scalar( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }

    /**
     * Non-empty scalar strings from a request array param, capped.
     *
     * @param mixed $value
     * @return string[]
     */
    private function scalar_list( $value ): array {
        $list = [];
        foreach ( is_array( $value ) ? $value : [ $value ] as $item ) {
            $item = $this->scalar( $item );
            if ( $item !== '' && strlen( $item ) <= self::MAX_VALUE_LENGTH ) {
                $list[] = $item;
            }
        }
        return array_slice( array_values( array_unique( $list ) ), 0, self::MAX_SELECTED_VALUES );
    }

    /**
     * Positive integer ids from a request array param.
     *
     * @param mixed $value
     * @return int[]
     */
    private function positive_ids( $value ): array {
        return array_values( array_filter( array_map( 'intval', $this->scalar_list( $value ) ), fn( $id ) => $id > 0 ) );
    }

    /**
     * The selection's choice values: facet id => values.
     *
     * @return array<string, string[]>
     */
    private function selected_values( WP_REST_Request $request ): array {
        $values = $request->get_param( 'values' );
        $result = [];
        foreach ( is_array( $values ) ? $values : [] as $facet_id => $list ) {
            $list = $this->scalar_list( $list );
            if ( is_string( $facet_id ) && ! empty( $list ) ) {
                $result[ $facet_id ] = $list;
            }
        }
        return $result;
    }

    /**
     * The selection's ranges: facet id => {min?, max?} (numeric bounds only).
     *
     * @return array<string, array{min?: float, max?: float}>
     */
    private function selected_ranges( WP_REST_Request $request ): array {
        $ranges = $request->get_param( 'ranges' );
        $result = [];
        foreach ( is_array( $ranges ) ? $ranges : [] as $facet_id => $range ) {
            if ( ! is_string( $facet_id ) || ! is_array( $range ) ) {
                continue;
            }
            $bounds = [];
            foreach ( [ 'min', 'max' ] as $bound ) {
                if ( isset( $range[ $bound ] ) && is_numeric( $range[ $bound ] ) ) {
                    $bounds[ $bound ] = (float) $range[ $bound ];
                }
            }
            if ( ! empty( $bounds ) ) {
                $result[ $facet_id ] = $bounds;
            }
        }
        return $result;
    }

    /**
     * The shopper's location (`near[lat]`, `near[lng]`), when valid.
     *
     * @return array{0: float, 1: float}|null
     */
    private function near( WP_REST_Request $request ): ?array {
        $near = $request->get_param( 'near' );
        if ( ! is_array( $near ) || ! isset( $near['lat'], $near['lng'] ) || ! is_numeric( $near['lat'] ) || ! is_numeric( $near['lng'] ) ) {
            return null;
        }
        $lat = (float) $near['lat'];
        $lng = (float) $near['lng'];
        return abs( $lat ) <= 90 && abs( $lng ) <= 180 ? [ $lat, $lng ] : null;
    }

    /**
     * Normalize a boolean-ish request flag (`"false"` and `"0"` must not count as set).
     *
     * @param mixed $value
     */
    private function flag( $value ): bool {
        return is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }

    /**
     * The facet ids a filters request asks for (deduped, capped). None asks only for the sort
     * orders (a filter with just a Sort row).
     *
     * @return string[]
     */
    private function requested_facet_ids( WP_REST_Request $request ): array {
        $requested = $request->get_param( 'facets' );
        return array_slice( is_array( $requested ) ? $this->scalar_list( $requested ) : [], 0, self::MAX_FACETS );
    }

    /**
     * The request params that narrow the context, normalized for a cache key. The location
     * is rounded to about a kilometre.
     *
     * @return array{search: string, terms: array, flags: string[], values: array, ranges: array, near: array|null}
     */
    private function context_cache_params( WP_REST_Request $request ): array {
        $terms = [];
        foreach ( array_keys( self::CONTEXT_TERM_PARAMS ) as $param ) {
            $terms[ $param ] = $this->positive_ids( $request->get_param( $param ) );
        }
        $flags = array_values( array_filter( array_keys( $this->catalog->context_flags() ), fn( $flag ) => $this->flag( $request->get_param( $flag ) ) ) );
        $near  = $this->near( $request );

        return [
            'search' => $this->scalar( $request->get_param( 'search' ) ),
            'terms'  => $terms,
            'flags'  => $flags,
            'values' => $this->selected_values( $request ),
            'ranges' => $this->selected_ranges( $request ),
            'near'   => $near ? [ round( $near[0], 2 ), round( $near[1], 2 ) ] : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Narrowing
    // -------------------------------------------------------------------------

    /**
     * Posts matching the request's page context and filter selection.
     *
     * @param WP_REST_Request $request
     * @param string|null $exclude_facet A facet whose own selection is skipped (to count that facet's options).
     * @return Builder
     */
    private function context_query( WP_REST_Request $request, ?string $exclude_facet = null ): Builder {
        $search      = $this->scalar( $request->get_param( 'search' ) );
        $query       = $this->base( $search !== '' );
        $term_facets = $this->catalog->term_facets();

        foreach ( self::CONTEXT_TERM_PARAMS as $param => $facet_id ) {
            $ids    = $this->positive_ids( $request->get_param( $param ) );
            $source = $term_facets[ $facet_id ] ?? null;
            if ( ! empty( $ids ) && $source ) {
                $source->where_ids( $query, $ids );
            }
        }

        if ( $search !== '' ) {
            global $wpdb;
            $columns = $this->catalog->search_columns();
            $query->where_raw(
                '(' . implode( ' OR ', array_map( fn( $column ) => "{$column} LIKE %s", $columns ) ) . ')',
                array_fill( 0, count( $columns ), '%' . $wpdb->esc_like( $search ) . '%' )
            );
        }

        foreach ( $this->catalog->context_flags() as $flag => $condition ) {
            if ( $this->flag( $request->get_param( $flag ) ) ) {
                $query->where_raw( $condition );
            }
        }

        foreach ( $this->selected_values( $request ) as $facet_id => $values ) {
            if ( $facet_id !== $exclude_facet ) {
                $this->apply_values( $query, $facet_id, $values );
            }
        }
        foreach ( $this->selected_ranges( $request ) as $facet_id => $range ) {
            if ( $facet_id !== $exclude_facet ) {
                $this->apply_range( $query, $facet_id, $range, $request );
            }
        }

        return $query;
    }

    /**
     * Narrow by a choice facet's selected values. Unknown or disallowed facets narrow nothing.
     *
     * @param string[] $values
     */
    private function apply_values( Builder $query, string $facet_id, array $values ): void {
        $flag = $this->catalog->flags()[ $facet_id ] ?? null;
        if ( $flag ) {
            foreach ( $values as $value ) {
                if ( isset( $flag['options'][ $value ] ) ) {
                    $query->where_raw( $this->condition( $flag['options'][ $value ][1] ) );
                }
            }
            return;
        }

        $source = $this->term_source( $facet_id );
        if ( $source ) {
            $source->where_slugs( $query, $values );
            return;
        }

        $field = $this->facet_field( $facet_id );
        if ( $field ) {
            $this->where_field( $query, $field, [ 'values' => $values ] );
        }
    }

    /**
     * Narrow by a range facet's bounds (shown units: currency, stars, kilometres).
     *
     * @param array{min?: float, max?: float} $range
     */
    private function apply_range( Builder $query, string $facet_id, array $range, WP_REST_Request $request ): void {
        if ( $facet_id === 'distance' ) {
            $distance = $this->distance_sql( $request );
            if ( $distance && isset( $range['max'] ) ) {
                $query->where_raw( $distance . ' <= %f', [ $range['max'] ] );
            }
            return;
        }

        $range_facet = $this->catalog->ranges()[ $facet_id ] ?? null;
        if ( $range_facet ) {
            // A post's span overlaps the chosen range.
            if ( isset( $range['min'] ) ) {
                $query->where_raw( $range_facet->max_sql . ' >= %f', [ $range['min'] * $range_facet->divisor ] );
            }
            if ( isset( $range['max'] ) ) {
                $query->where_raw( $range_facet->min_sql . ' <= %f', [ $range['max'] * $range_facet->divisor ] );
            }
            return;
        }

        $field = $this->facet_field( $facet_id );
        if ( $field ) {
            $this->where_field( $query, $field, $range );
        }
    }

    /**
     * Kilometres between a post and the request's `near` point, or null without one
     * (or when the catalog has no locations). Posts without coordinates are NULL.
     */
    private function distance_sql( WP_REST_Request $request ): ?string {
        $near        = $this->near( $request );
        $coordinates = $this->catalog->coordinates();
        if ( ! $near || ! $coordinates ) {
            return null;
        }

        [ $lat, $lng ] = $coordinates;
        $near_lat      = sprintf( '%.6F', $near[0] );
        $near_lng      = sprintf( '%.6F', $near[1] );
        // NULLIF: a missing coordinate is stored as 0 or empty, never a real 0,0 listing.
        return sprintf(
            '(%d * ACOS(LEAST(1, COS(RADIANS(%s)) * COS(RADIANS(NULLIF(%s, 0))) * COS(RADIANS(NULLIF(%s, 0)) - RADIANS(%s)) + SIN(RADIANS(%s)) * SIN(RADIANS(NULLIF(%s, 0))))))',
            self::EARTH_RADIUS_KM,
            $near_lat,
            $lat,
            $lng,
            $near_lng,
            $near_lat,
            $lat
        );
    }

    /**
     * A flag condition as SQL (a Closure builds a costly one only when used).
     *
     * @param string|Closure $condition
     */
    private function condition( $condition ): string {
        return $condition instanceof Closure ? (string) $condition() : $condition;
    }

    /**
     * Where a term facet id's terms come from, or null when it names none this catalog allows.
     */
    private function term_source( string $facet_id ): ?TermSource {
        $term_facets = $this->catalog->term_facets();
        if ( isset( $term_facets[ $facet_id ] ) ) {
            return $term_facets[ $facet_id ];
        }
        $separator = strpos( $facet_id, ':' );
        if ( $separator === false ) {
            return null;
        }
        $taxonomy = substr( $facet_id, $separator + 1 );
        $group    = $this->catalog->taxonomy_groups()[ substr( $facet_id, 0, $separator ) ] ?? [];
        // The group names it, so its label comes from there rather than from the taxonomy.
        return isset( $group[ $taxonomy ] ) ? new TaxonomyTermSource( $taxonomy, $group[ $taxonomy ] ) : null;
    }

    /**
     * The custom field behind a `meta:<key>` or `field:<key>` facet id, or null when it isn't allowed.
     */
    private function facet_field( string $facet_id ): ?Field {
        if ( ! in_array( $facet_id, $this->allowed_fields(), true ) ) {
            return null;
        }
        if ( str_starts_with( $facet_id, 'field:' ) ) {
            return $this->catalog->fields()[ substr( $facet_id, 6 ) ] ?? null;
        }
        return str_starts_with( $facet_id, 'meta:' ) ? $this->meta_field( substr( $facet_id, 5 ) ) : null;
    }

    /**
     * Keep posts whose field matches `$constraint`: any of `values` (a multiple field
     * matched by its list pattern, a true/false field by any truthy value), and/or a
     * numeric `min` / `max`. Values are bound as-is, so they match stored values exactly.
     *
     * @param array $constraint
     */
    private function where_field( Builder $query, Field $field, array $constraint ): void {
        global $wpdb;
        $subject    = $field->meta_key !== null ? 'pm.meta_value' : $field->column;
        $conditions = [];
        $bindings   = [];

        $values = $this->scalar_list( $constraint['values'] ?? [] );
        if ( ! empty( $values ) ) {
            if ( $field->value_type === Field::TYPE_BOOLEAN ) {
                $values = Field::TRUTHY_VALUES;
            }
            $matches = [];
            foreach ( $values as $value ) {
                $matches[]  = "{$subject} = %s";
                $bindings[] = $value;
                if ( $field->multiple ) {
                    $matches[]  = "{$subject} LIKE %s";
                    $bindings[] = '%' . str_replace( '{value}', $wpdb->esc_like( $value ), $field->list_pattern ) . '%';
                }
            }
            $conditions[] = '(' . implode( ' OR ', $matches ) . ')';
        }

        foreach ( [ 'min' => '>=', 'max' => '<=' ] as $bound => $operator ) {
            if ( isset( $constraint[ $bound ] ) && is_numeric( $constraint[ $bound ] ) ) {
                $conditions[] = "CAST({$subject} AS DECIMAL(20,4)) {$operator} %f";
                $bindings[]   = (float) $constraint[ $bound ];
            }
        }

        if ( empty( $conditions ) ) {
            return;
        }

        if ( $field->meta_key === null ) {
            $query->where_raw( '(' . implode( ' AND ', $conditions ) . ')', $bindings );
            return;
        }

        $query->where_raw(
            "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = posts.ID AND pm.meta_key = %s AND " . implode( ' AND ', $conditions ) . ')',
            array_merge( [ $field->meta_key ], $bindings )
        );
    }

    // -------------------------------------------------------------------------
    // Facets
    // -------------------------------------------------------------------------

    /**
     * Build one facet by id, or null when the id is unknown, not allowed, or has
     * nothing to offer in the current context.
     */
    private function build_facet( string $facet_id, WP_REST_Request $request ): ?FacetDTO {
        $range = $this->catalog->ranges()[ $facet_id ] ?? null;
        if ( $range ) {
            return $this->build_range_facet( $facet_id, $range, $request );
        }

        $flag = $this->catalog->flags()[ $facet_id ] ?? null;
        if ( $flag ) {
            return $this->build_flag_facet( $facet_id, $flag, $request );
        }

        $source = $this->term_source( $facet_id );
        if ( $source ) {
            return $this->build_term_facet( $facet_id, $source, $request );
        }

        $field = $this->facet_field( $facet_id );
        return $field ? $this->build_field_facet( $facet_id, $field, $request ) : null;
    }

    private function build_range_facet( string $facet_id, RangeFacet $range, WP_REST_Request $request ): ?FacetDTO {
        $query = $this->context_query( $request, $facet_id );
        $facet = ( new FacetDTO() )->set_id( $facet_id )->set_label( $range->label )->set_kind( FacetDTO::KIND_RANGE );

        if ( $range->bounds ) {
            if ( $range->exists_sql && ! $query->where_raw( $range->exists_sql )->exists() ) {
                return null;
            }
            return $facet->set_min( $range->bounds[0] )->set_max( $range->bounds[1] );
        }

        $min = ( clone $query )->min( $range->min_sql );
        $max = $query->max( $range->max_sql );
        if ( $min === null || $max === null ) {
            return null;
        }
        return $facet->set_min( (float) $min / $range->divisor )->set_max( (float) $max / $range->divisor );
    }

    /**
     * @param array{label: string, options: array<string, array{0: string, 1: string|Closure}>} $flag
     */
    private function build_flag_facet( string $facet_id, array $flag, WP_REST_Request $request ): FacetDTO {
        $options = [];
        foreach ( $flag['options'] as $value => [ $label, $condition ] ) {
            $count     = (int) $this->context_query( $request, $facet_id )->where_raw( $this->condition( $condition ) )->count();
            $options[] = $this->facet_option( (string) $value, $label, $count );
        }

        return ( new FacetDTO() )->set_id( $facet_id )->set_label( $flag['label'] )
            ->set_kind( FacetDTO::KIND_CHOICE )->set_options( $options );
    }

    /**
     * Options of one term source with post counts. Its own selection is left out of the
     * context, so counts show what adding another option would give (OR within a facet).
     */
    private function build_term_facet( string $facet_id, TermSource $source, WP_REST_Request $request ): ?FacetDTO {
        // A factory, not one query: a source may need several, and each must be ungrouped.
        $terms = $source->options( fn(): Builder => $this->context_query( $request, $facet_id ), self::MAX_FACET_OPTIONS );

        $options = [];
        foreach ( $terms as [ $value, $option_label, $count ] ) {
            if ( $count > 0 ) {
                $options[] = $this->facet_option( $value, $option_label, $count );
            }
        }

        if ( empty( $options ) ) {
            return null;
        }

        return ( new FacetDTO() )->set_id( $facet_id )->set_label( $source->label() )
            ->set_kind( FacetDTO::KIND_CHOICE )->set_options( array_slice( $options, 0, self::MAX_FACET_OPTIONS ) );
    }

    /**
     * A custom field's facet: a range for numbers, a toggle for true/false, or its
     * distinct values (fixed choices with their labels) with counts.
     */
    private function build_field_facet( string $facet_id, Field $field, WP_REST_Request $request ): ?FacetDTO {
        $facet = ( new FacetDTO() )->set_id( $facet_id )->set_label( $field->label );

        // How many posts in context (this facet's own selection left out) match one value.
        $count_matching = function ( string $value ) use ( $request, $facet_id, $field ): int {
            $query = $this->context_query( $request, $facet_id );
            $this->where_field( $query, $field, [ 'values' => [ $value ] ] );
            return (int) $query->count();
        };

        $query = $this->context_query( $request, $facet_id );
        if ( $field->meta_key !== null ) {
            $subject = 'facet_meta.meta_value';
            $query->join( 'postmeta as facet_meta', 'posts.ID', '=', 'facet_meta.post_id' )
                ->where( 'facet_meta.meta_key', $field->meta_key )
                ->where( 'facet_meta.meta_value', '!=', '' );
        } else {
            $subject = $field->column;
            $query->where_raw( "{$subject} IS NOT NULL AND {$subject} <> ''" );
        }

        if ( $field->value_type === Field::TYPE_NUMBER ) {
            $cast = "CAST({$subject} AS DECIMAL(20,4))";
            $min  = ( clone $query )->min( $cast );
            $max  = $query->max( $cast );
            if ( $min === null || $max === null ) {
                return null;
            }
            return $facet->set_kind( FacetDTO::KIND_RANGE )->set_min( (float) $min )->set_max( (float) $max );
        }

        if ( $field->value_type === Field::TYPE_BOOLEAN ) {
            return $facet->set_kind( FacetDTO::KIND_TOGGLE )->set_options( [ $this->facet_option( '1', $field->label, $count_matching( '1' ) ) ] );
        }

        $options = [];
        if ( $field->multiple ) {
            // Several choices stored in one value can't be grouped: count each choice.
            foreach ( array_slice( $field->choices, 0, self::MAX_FACET_OPTIONS, true ) as $value => $choice_label ) {
                $count = $count_matching( (string) $value );
                if ( $count > 0 ) {
                    $options[] = $this->facet_option( (string) $value, (string) $choice_label, $count );
                }
            }
        } else {
            $rows = $query->select( [ "{$subject} as value", 'COUNT(DISTINCT posts.ID) as post_count' ] )
                ->group_by( [ $subject ] )
                ->order_by( $subject, 'asc' )
                ->limit( self::MAX_FACET_OPTIONS )
                ->get();
            foreach ( $rows as $row ) {
                $value     = (string) $row->value;
                $options[] = $this->facet_option( $value, (string) ( $field->choices[ $value ] ?? $value ), (int) $row->post_count );
            }
        }

        return empty( $options ) ? null : $facet->set_kind( FacetDTO::KIND_CHOICE )->set_options( $options );
    }

    private function facet_option( string $value, string $label, int $count ): FacetOptionDTO {
        return ( new FacetOptionDTO() )->set_value( $value )->set_label( $label )->set_count( $count );
    }

    // -------------------------------------------------------------------------
    // Custom fields
    // -------------------------------------------------------------------------

    /**
     * Facet ids of the custom fields a filter may narrow by. Filtering by a field reveals
     * its values (a request can probe them), so only ACF fields placed on the post types
     * and the plugin fields it marks searchable are allowed by default; a site changes
     * the list with the `craf_appna_filter_allowed_fields` filter.
     *
     * @return string[]
     */
    private function allowed_fields(): array {
        if ( $this->allowed_fields === null ) {
            $defaults = array_map( fn( $key ) => "meta:{$key}", array_keys( $this->acf_fields() ) );
            foreach ( $this->catalog->fields() as $key => $field ) {
                if ( $field->enabled ) {
                    $defaults[] = "field:{$key}";
                }
            }

            /**
             * Filters which custom fields the app may filter lists by.
             *
             * @param string[] $facet_ids  `meta:<key>` (ACF fields placed on the post types) and
             *                             `field:<key>` (the plugin's own searchable fields).
             * @param string[] $post_types The listed post types (products or directory listings).
             */
            $ids                  = apply_filters( 'craf_appna_filter_allowed_fields', $defaults, $this->post_types() );
            $this->allowed_fields = array_values( array_unique( array_filter( (array) $ids, fn( $id ) => is_string( $id ) && preg_match( '/^(meta|field):./', $id ) ) ) );
        }
        return $this->allowed_fields;
    }

    /**
     * A post-meta field: the ACF field when there is one, otherwise typed from its stored values.
     */
    private function meta_field( string $key ): Field {
        return $this->acf_fields()[ $key ] ?? Field::meta( $key, ucwords( str_replace( [ '_', '-' ], ' ', $key ) ), $this->sampled_value_type( $key ) );
    }

    /**
     * Public (non-underscore) meta keys stored on the post types, for the builder to list,
     * without the catalog's internal keys and its own fields' keys.
     *
     * @return string[]
     */
    private function discover_meta_keys(): array {
        global $wpdb;
        $post_types = $this->post_types();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- builder-only listing.
        $keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ") AND pm.meta_key NOT LIKE %s
                LIMIT %d",
                array_merge( $post_types, [ $wpdb->esc_like( '_' ) . '%', self::MAX_DISCOVERED_META_KEYS ] )
            )
        );

        $field_keys = [];
        foreach ( $this->catalog->fields() as $field ) {
            if ( $field->meta_key !== null ) {
                $field_keys[] = $field->meta_key;
            }
        }
        return array_values( array_filter( (array) $keys, fn( $key ) => ! in_array( $key, $field_keys, true ) && ! $this->catalog->is_internal_meta_key( (string) $key ) ) );
    }

    /**
     * Top-level ACF fields of field groups placed on the post types, keyed by name.
     * Nested fields (repeaters, groups) store prefixed meta keys and are left out.
     *
     * @return array<string, Field>
     */
    private function acf_fields(): array {
        if ( $this->acf_fields !== null ) {
            return $this->acf_fields;
        }

        $this->acf_fields = [];
        if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return $this->acf_fields;
        }

        foreach ( $this->post_types() as $post_type ) {
            foreach ( acf_get_field_groups( [ 'post_type' => $post_type ] ) as $group ) {
                foreach ( (array) acf_get_fields( $group ) as $field ) {
                    $type = $field['type'] ?? '';
                    if ( empty( $field['name'] ) || ! in_array( $type, self::ACF_FILTERABLE_TYPES, true ) ) {
                        continue;
                    }
                    $value_type = in_array( $type, [ 'number', 'range' ], true ) ? Field::TYPE_NUMBER
                        : ( $type === 'true_false' ? Field::TYPE_BOOLEAN : Field::TYPE_CHOICE );

                    $this->acf_fields[ $field['name'] ] = Field::meta(
                        $field['name'],
                        (string) ( $field['label'] ?? $field['name'] ),
                        $value_type,
                        is_array( $field['choices'] ?? null ) ? $field['choices'] : [],
                        $type === 'checkbox' || ! empty( $field['multiple'] ),
                        true
                    );
                }
            }
        }

        return $this->acf_fields;
    }

    /**
     * `number` when a sample of stored values is all numeric, otherwise `choice`.
     */
    private function sampled_value_type( string $key ): string {
        global $wpdb;
        $post_types = $this->post_types();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- small sample; facets are cached by the caller.
        $sample = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ") AND pm.meta_key = %s AND pm.meta_value <> ''
                LIMIT 20",
                array_merge( $post_types, [ $key ] )
            )
        );

        if ( ! empty( $sample ) && count( array_filter( $sample, 'is_numeric' ) ) === count( $sample ) ) {
            return Field::TYPE_NUMBER;
        }
        return Field::TYPE_CHOICE;
    }
}
