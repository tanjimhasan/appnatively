<?php

namespace Crafium\AppNatively\App\Integrations\Directory\Catalog;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\Filtering\Catalog;
use Crafium\AppNatively\App\Filtering\RangeFacet;
use Crafium\AppNatively\App\Filtering\TaxonomyTermSource;
use Crafium\AppNatively\App\Filtering\TermSource;

/**
 * Where one directory plugin keeps what the listing list and filter read: its
 * post types, taxonomies, and SQL for featured, price, rating, views and
 * location, plus its opening hours. From these it describes the listing facets
 * (price and rating ranges, the `status` flags `featured` and `open_now`) and a
 * list's `featured` setting to CatalogQuery.
 */
abstract class ListingCatalog extends Catalog {
    /** Seconds the ids of open listings are reused for. */
    private const OPEN_NOW_CACHE_TTL = MINUTE_IN_SECONDS;

    /** @var array<string, TermSource>|null Memoized (see term_facets()). */
    private ?array $term_facets = null;

    /**
     * The taxonomy behind the `category` facet and a page's `categories[]`.
     */
    abstract public function category_taxonomy(): string;

    /**
     * The taxonomy behind the `tag` facet and a list's `tags[]`, when the plugin has tags.
     */
    public function tag_taxonomy(): ?string {
        return null;
    }

    /**
     * The taxonomy behind the `location` facet and a page's `locations[]`, when the plugin has one.
     */
    public function location_taxonomy(): ?string {
        return null;
    }

    /**
     * Where the `location` facet's terms come from. Most plugins keep locations in a
     * taxonomy, so this follows location_taxonomy(); one that stores them its own way
     * (GeoDirectory) overrides this instead.
     */
    public function location_source(): ?TermSource {
        $taxonomy = $this->location_taxonomy();
        return $taxonomy ? new TaxonomyTermSource( $taxonomy ) : null;
    }

    /**
     * Other taxonomies a visitor may filter by (`taxonomy:<name>` facets), name => label.
     *
     * @return array<string, string>
     */
    public function extra_taxonomies(): array {
        return [];
    }

    /**
     * Condition: the listing is featured.
     */
    abstract public function featured_sql(): string;

    /**
     * A listing's lowest and highest price, when the plugin has prices.
     *
     * @return array{0: string, 1: string}|null
     */
    public function price_sql(): ?array {
        return null;
    }

    /**
     * A listing's average star rating (0-5), when the plugin has reviews.
     */
    public function rating_sql(): ?string {
        return null;
    }

    /**
     * Condition: the listing has at least one rating.
     */
    public function rated_sql(): ?string {
        return null;
    }

    /**
     * A listing's view count, when the plugin tracks it.
     */
    public function views_sql(): ?string {
        return null;
    }

    /**
     * Whether the plugin stores opening hours (enables the `open_now` flag).
     */
    public function has_business_hours(): bool {
        return false;
    }

    /**
     * Every published listing's opening hours, by post id (listings without hours left out).
     *
     * @return array<int, BusinessHours>
     */
    protected function business_hours(): array {
        return [];
    }

    /**
     * Memoized: CatalogQuery asks for the facets once per narrowing, which is many
     * times per request, and a source may hold per-request lookups of its own.
     */
    public function term_facets(): array {
        if ( $this->term_facets === null ) {
            $this->term_facets = $this->taxonomy_facets(
                [
                    'category' => $this->category_taxonomy(),
                    'tag'      => $this->tag_taxonomy(),
                ]
            );
            $location          = $this->location_source();
            if ( $location ) {
                $this->term_facets['location'] = $location;
            }
        }
        return $this->term_facets;
    }

    /**
     * A taxonomy that already backs a term facet is left out: offered as a
     * `taxonomy:` facet too it would be two filter rows over one set of terms
     * (HivePress's `hp_listing_region` is both its `region` attribute and its
     * location taxonomy).
     */
    public function taxonomy_groups(): array {
        $reserved = array_filter( [ $this->category_taxonomy(), $this->tag_taxonomy(), $this->location_taxonomy() ] );
        return [ 'taxonomy' => array_diff_key( $this->extra_taxonomies(), array_flip( $reserved ) ) ];
    }

    public function search_columns(): array {
        return [ 'posts.post_title', 'posts.post_content', 'posts.post_excerpt' ];
    }

    public function ranges(): array {
        $ranges = [];
        $price  = $this->price_sql();
        if ( $price ) {
            $ranges['price'] = new RangeFacet( __( 'Price', 'appnatively' ), $price[0], $price[1] );
        }
        if ( $this->rating_sql() && $this->rated_sql() ) {
            $ranges['rating'] = new RangeFacet( __( 'Rating', 'appnatively' ), $this->rating_sql(), $this->rating_sql(), 1, [ 0, 5 ], $this->rated_sql() );
        }
        return $ranges;
    }

    public function flags(): array {
        $options = [ 'featured' => [ __( 'Featured', 'appnatively' ), $this->featured_sql() ] ];
        if ( $this->has_business_hours() ) {
            $options['open_now'] = [ __( 'Open now', 'appnatively' ), fn(): string => $this->open_now_sql() ];
        }
        return [
            'status' => [
                'label'   => __( 'Status', 'appnatively' ),
                'options' => $options,
            ],
        ];
    }

    public function context_flags(): array {
        return [ 'featured' => $this->featured_sql() ];
    }

    public function volatile_facets(): array {
        return $this->has_business_hours() ? [ 'status' ] : [];
    }

    public function popularity_sql(): ?string {
        return $this->views_sql();
    }

    /**
     * Relevance lists featured listings first, then the newest.
     */
    public function sorts(): array {
        $sorts              = parent::sorts();
        $sorts['relevance'] = [ [ $this->featured_sql(), 'desc' ], [ 'posts.post_date', 'desc' ] ];
        return $sorts;
    }

    /**
     * Condition: the listing is open now. Opening hours are evaluated in PHP (each
     * plugin has its own format), so this matches the ids of open listings, reused
     * for a minute.
     */
    private function open_now_sql(): string {
        $cache_key = 'craf_appna_open_now_' . md5( implode( ',', $this->post_types() ) );
        $ids       = get_transient( $cache_key );

        if ( ! is_array( $ids ) ) {
            $ids = [];
            $now = time();
            foreach ( $this->business_hours() as $post_id => $hours ) {
                if ( $hours->is_open_at( $now ) ) {
                    $ids[] = (int) $post_id;
                }
            }
            set_transient( $cache_key, $ids, self::OPEN_NOW_CACHE_TTL );
        }

        return empty( $ids ) ? '0 = 1' : 'posts.ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
    }

    // -------------------------------------------------------------------------
    // SQL and loading helpers for post-meta based plugins
    // -------------------------------------------------------------------------

    /**
     * A listing's numeric meta value (NULL when missing or empty). `+ 0` instead of CAST:
     * the query builder reads " AS " in an ORDER BY expression as a column alias.
     */
    protected function meta_number( string $key ): string {
        global $wpdb;
        return "(SELECT NULLIF(listing_meta.meta_value, '') + 0 FROM {$wpdb->postmeta} listing_meta WHERE listing_meta.post_id = posts.ID AND listing_meta.meta_key = '" . esc_sql( $key ) . "' LIMIT 1)";
    }

    /**
     * Condition: the listing's meta value is one of `$values`.
     *
     * @param string[] $values
     */
    protected function meta_in( string $key, array $values ): string {
        global $wpdb;
        $list = implode( ',', array_map( fn( $value ) => "'" . esc_sql( $value ) . "'", $values ) );
        return "EXISTS (SELECT 1 FROM {$wpdb->postmeta} listing_flag WHERE listing_flag.post_id = posts.ID AND listing_flag.meta_key = '" . esc_sql( $key ) . "' AND listing_flag.meta_value IN ({$list}))";
    }

    /**
     * The given meta values of every published listing that has any of them, by post id then key
     * (unserialized).
     *
     * @param string[] $keys
     * @return array<int, array<string, mixed>>
     */
    protected function meta_rows( array $keys ): array {
        global $wpdb;
        $post_types = $this->post_types();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- one bulk read, cached by the caller.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_status = 'publish' AND p.post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')
                AND pm.meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')',
                array_merge( $post_types, $keys )
            )
        );

        $values = [];
        foreach ( (array) $rows as $row ) {
            $values[ (int) $row->post_id ][ $row->meta_key ] = maybe_unserialize( $row->meta_value );
        }
        return $values;
    }
}
