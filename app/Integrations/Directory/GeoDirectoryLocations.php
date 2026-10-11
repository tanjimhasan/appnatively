<?php

namespace Crafium\AppNatively\App\Integrations\Directory;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\Filtering\TermSource;
use Crafium\AppNatively\WpMVC\Database\Query\Builder;
use Crafium\AppNatively\WpMVC\Database\Query\JoinClause;
use Crafium\AppNatively\WpMVC\Database\Schema\Schema;

/**
 * GeoDirectory's locations, which its Location Manager add-on keeps in
 * `geodir_post_locations` — one row per city, with a `location_id` of its own —
 * and links to a listing by the `country`, `region` and `city` names on the
 * listing's detail table. There is no taxonomy, no term and no location id on
 * the listing, so this class is both the `location` facet's TermSource and where
 * the provider reads its location DTOs. Both share one instance, for the
 * per-request lookups below.
 *
 * Narrowing resolves the wanted locations to name tuples in PHP and then matches
 * those columns, rather than joining or sub-querying `post_locations`: that
 * table is indexed by `location_id` alone, so a correlated lookup would scan it
 * once per candidate listing.
 *
 * A city's identity is its `(country, region, city)` tuple, so `city_slug` can
 * repeat across regions. The facet keys on the slug and merges the duplicates
 * into one option, while the locations list keys on `location_id` and keeps them
 * apart, each addressable on its own — the same split the taxonomy integrations
 * have, where a facet keys on a term's slug and the list on its id.
 */
final class GeoDirectoryLocations implements TermSource {
    /** Locations resolved in one statement at most. */
    private const MAX_RESOLVED = 200;

    /** The columns a city row carries, under the locations table's alias. */
    private const CITY_COLUMNS = [ 'l.location_id', 'l.country', 'l.region', 'l.city', 'l.city_slug', 'l.country_slug', 'l.region_slug' ];

    /** The listing post type locations are counted for. */
    private string $post_type;

    /** @var array<string, object|null> Tuple key => its city row, or null when it has none. */
    private array $tuples = [];

    /** @var array<string, object[]> Lookup key => the city rows it resolved to. */
    private array $resolved = [];

    /** @var array<int, string|null> Listing id => its tuple key, or null when it has no city. */
    private array $post_tuples = [];

    /**
     * Whether the Location Manager add-on is there. It defines the table constant while
     * booting on `geodirectory_loaded`, so an undefined constant is not remembered.
     */
    public static function available(): bool {
        static $available = null;

        if ( ! defined( 'GEODIR_LOCATIONS_TABLE' ) ) {
            return false;
        }

        if ( $available === null ) {
            $available = Schema::has_table( self::table_name( GEODIR_LOCATIONS_TABLE ) );
        }

        return $available;
    }

    public function __construct( string $post_type ) {
        $this->post_type = $post_type;
    }

    // -------------------------------------------------------------------------
    // The `location` facet
    // -------------------------------------------------------------------------

    public function label(): string {
        return __( 'Location', 'appnatively' );
    }

    public function where_ids( Builder $query, array $ids ): void {
        $this->where_tuples( $query, $this->rows_by_ids( $ids ) );
    }

    public function where_slugs( Builder $query, array $slugs ): void {
        $this->where_tuples( $query, $this->rows_by_slugs( $slugs ) );
    }

    public function options( callable $fresh_query, int $limit ): array {
        // The detail table is joined with a LEFT JOIN, so listings without a city would
        // otherwise all land in one empty group.
        $rows = $fresh_query()
            ->where_raw( "gd_detail.city IS NOT NULL AND gd_detail.city <> ''" )
            ->select( [ 'gd_detail.country as country', 'gd_detail.region as region', 'gd_detail.city as city', 'COUNT(DISTINCT posts.ID) as post_count' ] )
            ->group_by( [ 'gd_detail.country', 'gd_detail.region', 'gd_detail.city' ] )
            ->order_by( 'gd_detail.city', 'asc' )
            ->limit( $limit )
            ->get();

        $tuples = [];
        foreach ( $rows as $row ) {
            $tuples[] = [ (string) $row->country, (string) $row->region, (string) $row->city ];
        }
        $cities = $this->resolve_tuples( $tuples );

        $options = [];
        foreach ( $rows as $row ) {
            $city = $cities[ $this->tuple_key( (string) $row->country, (string) $row->region, (string) $row->city ) ] ?? null;
            if ( ! $city || '' === (string) $city->city_slug ) {
                // No location row: nothing to select it by, and no page to open for it.
                continue;
            }

            $slug = (string) $city->city_slug;
            if ( isset( $options[ $slug ] ) ) {
                // Cities sharing a slug are one option, since where_slugs() matches them all.
                // A listing has exactly one city, so the groups are disjoint and the counts add up.
                $options[ $slug ][2] += (int) $row->post_count;
                continue;
            }
            $options[ $slug ] = [ $slug, (string) $city->city, (int) $row->post_count ];
        }

        return array_values( $options );
    }

    // -------------------------------------------------------------------------
    // Reading locations
    // -------------------------------------------------------------------------

    /**
     * One page of cities with their listing counts, searched by name and sorted by the
     * `sort` token the locations endpoint takes. Cities without listings are included,
     * as `hide_empty => false` does for the taxonomy integrations.
     *
     * @return array{items: object[], total: int}
     */
    public function page( string $search, string $sort, int $per_page, int $offset ): array {
        [ $order_by, $order ] = $this->order( $sort );

        // A city's listings are counted through its name tuple, and over published posts
        // only, which is why both joins are LEFT: a city with none still has a row, with a
        // count of 0.
        $items = $this->named_cities( $search )
            ->add_select( [ 'COUNT(DISTINCT p.ID) as post_count' ] )
            ->left_join(
                $this->detail_table() . ' as d',
                function ( JoinClause $join ): void {
                    $join->on_column( 'd.country', '=', 'l.country' )
                        ->on_column( 'd.region', '=', 'l.region' )
                        ->on_column( 'd.city', '=', 'l.city' );
                }
            )
            ->left_join(
                'posts as p',
                function ( JoinClause $join ): void {
                    // Literal conditions only: a value bound inside an ON clause never
                    // reaches the statement the builder prepares. None is needed for the
                    // post type either — a detail table holds that one post type.
                    $join->on_column( 'p.ID', '=', 'd.post_id' )
                        ->on_raw( "p.post_status = 'publish' AND p.post_password = ''" );
                }
            )
            ->group_by( self::CITY_COLUMNS )
            ->order_by( $order_by, $order )
            ->order_by( 'l.location_id', 'asc' )
            ->limit( $per_page )
            ->offset( $offset )
            ->get()
            ->all();

        return [
            'items' => $items,
            'total' => $this->named_cities( $search )->count(),
        ];
    }

    /**
     * The cities the locations endpoint lists: the named ones, narrowed by its search term.
     */
    private function named_cities( string $search ): Builder {
        $query = $this->cities_query()->where( 'l.city', '<>', '' );

        if ( '' !== $search ) {
            global $wpdb;
            // The builder binds the term; the wildcards in the term itself still need escaping.
            $query->where_like( 'l.city', '%' . $wpdb->esc_like( $search ) . '%' );
        }

        return $query;
    }

    /**
     * One city by its location id.
     */
    public function city( int $location_id ): ?object {
        $rows = $this->rows_by_ids( [ $location_id ] );
        return $rows ? reset( $rows ) : null;
    }

    /**
     * How many listings a city has.
     */
    public function count_for( object $city ): int {
        return $this->published_listings()
            ->where( 'd.country', (string) $city->country )
            ->where( 'd.region', (string) $city->region )
            ->where( 'd.city', (string) $city->city )
            ->distinct()
            ->count( 'p.ID' );
    }

    /**
     * The stored image of each of these cities, by location id, as Location Manager keeps
     * it (an attachment id, or a URL on older sites). Left out when the city has none.
     *
     * One query for the whole page instead of GeoDir_Location_SEO::get_seo_by_slug() per
     * city, preferring the row that names the same country and region and falling back to
     * the first row for the slug alone, which is the narrowing that function does.
     *
     * @param object[] $cities
     * @return array<int, string>
     */
    public function image_values( array $cities ): array {
        if ( ! defined( 'GEODIR_LOCATION_SEO_TABLE' ) || empty( $cities ) ) {
            return [];
        }

        $slugs = [];
        foreach ( $cities as $city ) {
            $slug = (string) ( $city->city_slug ?? '' );
            if ( '' !== $slug ) {
                $slugs[ $slug ] = true;
            }
        }
        if ( empty( $slugs ) ) {
            return [];
        }
        $slugs = array_slice( array_keys( $slugs ), 0, self::MAX_RESOLVED );

        $rows = GeoDirectoryRow::from_table( self::table_name( GEODIR_LOCATION_SEO_TABLE ), 's' )
            ->select( [ 's.city_slug', 's.country_slug', 's.region_slug', 's.image' ] )
            ->where( 's.location_type', 'city' )
            ->where_in( 's.city_slug', $slugs )
            ->order_by( 's.seo_id' )
            ->get()
            ->all();

        $exact   = [];
        $by_slug = [];
        foreach ( $rows as $row ) {
            $image = (string) $row->image;
            if ( '' === $image ) {
                continue;
            }
            $key = $this->tuple_key( (string) $row->country_slug, (string) $row->region_slug, (string) $row->city_slug );
            if ( ! isset( $exact[ $key ] ) ) {
                $exact[ $key ] = $image;
            }
            $slug = strtolower( trim( (string) $row->city_slug ) );
            if ( ! isset( $by_slug[ $slug ] ) ) {
                $by_slug[ $slug ] = $image;
            }
        }

        $images = [];
        foreach ( $cities as $city ) {
            if ( '' === (string) ( $city->city_slug ?? '' ) ) {
                continue;
            }
            $key   = $this->tuple_key( (string) ( $city->country_slug ?? '' ), (string) ( $city->region_slug ?? '' ), (string) $city->city_slug );
            $slug  = strtolower( trim( (string) $city->city_slug ) );
            $image = $exact[ $key ] ?? $by_slug[ $slug ] ?? '';
            if ( '' !== $image ) {
                $images[ (int) $city->location_id ] = $image;
            }
        }

        return $images;
    }

    /**
     * A published listing of each of these cities, by location id: the stand-in for a city
     * that has no image of its own. A city image is optional in Location Manager and most
     * sites never set one, so its own location pages fall back the same way, to an image
     * from a listing in that city (GeoDir_Location_SEO::get_image_tag(), `fallback_image`).
     *
     * The newest listing of every city in one statement. Should that one have no image the
     * city is left without one, rather than paying a query per listing to look further.
     *
     * @param object[] $cities
     * @return array<int, int> Location id => listing id.
     */
    public function listing_ids( array $cities ): array {
        $wanted = [];
        foreach ( $cities as $city ) {
            $city_name = (string) ( $city->city ?? '' );
            if ( '' !== trim( $city_name ) ) {
                $wanted[ $this->tuple_key( (string) ( $city->country ?? '' ), (string) ( $city->region ?? '' ), $city_name ) ] = [ (string) ( $city->country ?? '' ), (string) ( $city->region ?? '' ), $city_name ];
            }
        }
        if ( empty( $wanted ) ) {
            return [];
        }
        $wanted = array_slice( $wanted, 0, self::MAX_RESOLVED, true );

        $query = $this->published_listings()
            ->select( [ 'd.country', 'd.region', 'd.city', 'MAX(d.post_id) as post_id' ] )
            ->group_by( [ 'd.country', 'd.region', 'd.city' ] );
        $this->where_any_tuple( $query, 'd', array_values( $wanted ) );

        $rows = $query->get()->all();

        $by_tuple = [];
        foreach ( $rows as $row ) {
            $by_tuple[ $this->tuple_key( (string) $row->country, (string) $row->region, (string) $row->city ) ] = (int) $row->post_id;
        }

        $listings = [];
        foreach ( $cities as $city ) {
            $key = $this->tuple_key( (string) ( $city->country ?? '' ), (string) ( $city->region ?? '' ), (string) ( $city->city ?? '' ) );
            if ( ! empty( $by_tuple[ $key ] ) ) {
                $listings[ (int) $city->location_id ] = $by_tuple[ $key ];
            }
        }

        return $listings;
    }

    // -------------------------------------------------------------------------
    // A listing's own city
    // -------------------------------------------------------------------------

    /**
     * Load the cities of a page of listings: two statements however many listings there
     * are, so mapping them one by one costs no further query.
     *
     * Read the tuples from the detail table first and resolve the distinct ones together,
     * rather than joining `post_locations` from the detail side — that way the unindexed
     * table is scanned once instead of once per listing.
     *
     * @param int[] $post_ids
     */
    public function prime( array $post_ids ): void {
        $post_ids = array_values( array_filter( array_map( 'intval', $post_ids ), fn( int $id ): bool => $id > 0 && ! array_key_exists( $id, $this->post_tuples ) ) );
        if ( empty( $post_ids ) ) {
            return;
        }
        $post_ids = array_slice( array_values( array_unique( $post_ids ) ), 0, self::MAX_RESOLVED );

        $rows = GeoDirectoryRow::from_table( $this->detail_table(), 'd' )
            ->select( [ 'd.post_id', 'd.country', 'd.region', 'd.city' ] )
            ->where_in( 'd.post_id', $post_ids )
            ->get()
            ->all();

        $tuples = [];
        foreach ( $rows as $row ) {
            if ( '' === trim( (string) $row->city ) ) {
                continue;
            }
            $this->post_tuples[ (int) $row->post_id ] = $this->tuple_key( (string) $row->country, (string) $row->region, (string) $row->city );
            $tuples[]                                 = [ (string) $row->country, (string) $row->region, (string) $row->city ];
        }

        // Listings with no detail row or no city: remembered so they are never looked up again.
        foreach ( $post_ids as $post_id ) {
            if ( ! array_key_exists( $post_id, $this->post_tuples ) ) {
                $this->post_tuples[ $post_id ] = null;
            }
        }

        $this->resolve_tuples( $tuples );
    }

    /**
     * A listing's city, or null when it has none the add-on knows.
     */
    public function city_for_post( int $post_id ): ?object {
        if ( ! array_key_exists( $post_id, $this->post_tuples ) ) {
            $this->prime( [ $post_id ] );
        }

        $key = $this->post_tuples[ $post_id ] ?? null;
        return $key === null ? null : ( $this->tuples[ $key ] ?? null );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Keep listings whose detail columns match any of these cities.
     *
     * @param object[] $rows
     */
    private function where_tuples( Builder $query, array $rows ): void {
        $tuples = [];

        foreach ( $rows as $row ) {
            if ( '' !== trim( (string) $row->city ) ) {
                $tuples[] = [ (string) $row->country, (string) $row->region, (string) $row->city ];
            }
        }

        if ( empty( $tuples ) ) {
            // The requested location(s) don't exist, so nothing can match.
            $query->where( 'posts.ID', '=', 0 );
            return;
        }

        $this->where_any_tuple( $query, 'gd_detail', $tuples );
    }

    /**
     * Keep the rows of `$alias` whose `country`, `region` and `city` are one of these
     * tuples: one nested OR group, so the caller's other conditions still apply.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tuples
     */
    private function where_any_tuple( Builder $query, string $alias, array $tuples ): void {
        $query->where(
            function ( Builder $group ) use ( $alias, $tuples ): void {
                foreach ( $tuples as [ $country, $region, $city ] ) {
                    $group->or_where(
                        function ( Builder $tuple ) use ( $alias, $country, $region, $city ): void {
                            $tuple->where( "{$alias}.country", $country )
                                ->where( "{$alias}.region", $region )
                                ->where( "{$alias}.city", $city );
                        }
                    );
                }
            }
        );
    }

    /**
     * The city rows of these location ids.
     *
     * @param int[] $ids
     * @return object[]
     */
    private function rows_by_ids( array $ids ): array {
        $ids = array_values( array_filter( array_map( 'intval', $ids ), fn( int $id ): bool => $id > 0 ) );
        $ids = array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_RESOLVED );
        if ( empty( $ids ) ) {
            return [];
        }

        $key = 'id:' . implode( ',', $ids );
        if ( ! isset( $this->resolved[ $key ] ) ) {
            $this->resolved[ $key ] = $this->cities_query()->where_in( 'l.location_id', $ids )->order_by( 'l.location_id' )->get()->all();
            $this->remember( $this->resolved[ $key ] );
        }

        return $this->resolved[ $key ];
    }

    /**
     * The city rows of these slugs. A slug can name several cities, in different regions,
     * and all of them are returned: options are OR-ed within a facet.
     *
     * @param string[] $slugs
     * @return object[]
     */
    private function rows_by_slugs( array $slugs ): array {
        // Bound as stored, like a field's values: sanitize_title() could only turn a slug
        // GeoDirectory stored into one it never would, which would match nothing.
        $slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ), fn( string $slug ): bool => '' !== $slug ) ) );
        $slugs = array_slice( $slugs, 0, self::MAX_RESOLVED );
        if ( empty( $slugs ) ) {
            return [];
        }

        $key = 'slug:' . implode( ',', $slugs );
        if ( ! isset( $this->resolved[ $key ] ) ) {
            $this->resolved[ $key ] = $this->cities_query()->where_in( 'l.city_slug', $slugs )->order_by( 'l.location_id' )->get()->all();
            $this->remember( $this->resolved[ $key ] );
        }

        return $this->resolved[ $key ];
    }

    /**
     * The city row behind each of these `(country, region, city)` tuples, by tuple key,
     * in one statement. Tuples with no row are left out, and remembered as having none.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $tuples
     * @return array<string, object>
     */
    private function resolve_tuples( array $tuples ): array {
        $wanted = [];
        foreach ( $tuples as [ $country, $region, $city ] ) {
            $key = $this->tuple_key( $country, $region, $city );
            if ( '' !== trim( $city ) && ! array_key_exists( $key, $this->tuples ) ) {
                $wanted[ $key ] = [ $country, $region, $city ];
            }
        }

        if ( ! empty( $wanted ) ) {
            $wanted = array_slice( $wanted, 0, self::MAX_RESOLVED, true );

            $query = $this->cities_query();
            $this->where_any_tuple( $query, 'l', array_values( $wanted ) );
            $rows = $query->order_by( 'l.location_id' )->get()->all();

            // Remember the misses too: an unknown tuple is never looked up twice.
            foreach ( array_keys( $wanted ) as $key ) {
                $this->tuples[ $key ] = null;
            }
            $this->remember( $rows );
        }

        $found = [];
        foreach ( $tuples as [ $country, $region, $city ] ) {
            $key = $this->tuple_key( $country, $region, $city );
            if ( ! empty( $this->tuples[ $key ] ) ) {
                $found[ $key ] = $this->tuples[ $key ];
            }
        }

        return $found;
    }

    /**
     * Keep these city rows under their tuple key, the lowest location id winning a tie.
     *
     * @param object[] $rows
     */
    private function remember( array $rows ): void {
        foreach ( $rows as $row ) {
            $key = $this->tuple_key( (string) $row->country, (string) $row->region, (string) $row->city );
            if ( empty( $this->tuples[ $key ] ) ) {
                $this->tuples[ $key ] = $row;
            }
        }
    }

    /**
     * A tuple's key, folded the way MySQL compared it: the default collation is
     * case-insensitive, so grouping on the raw strings would lose a city whose stored
     * name differs only in case or padding.
     */
    private function tuple_key( string $country, string $region, string $city ): string {
        return strtolower( trim( $country ) ) . "\0" . strtolower( trim( $region ) ) . "\0" . strtolower( trim( $city ) );
    }

    /**
     * The locations endpoint's `sort` token as a column and a direction, both literal:
     * `-` leads a descending sort, and an unknown token sorts by name.
     *
     * @return array{0: string, 1: string}
     */
    private function order( string $sort ): array {
        $sort      = trim( $sort );
        $direction = 'ASC';

        if ( 0 === strpos( $sort, '-' ) ) {
            $direction = 'DESC';
            $sort      = ltrim( $sort, '-' );
        }

        $columns = [
            'name'  => 'l.city',
            'id'    => 'l.location_id',
            'slug'  => 'l.city_slug',
            'count' => 'post_count',
        ];

        return [ $columns[ $sort ] ?? 'l.city', $direction ];
    }

    /**
     * The query every city lookup starts from: the locations table, as `l`.
     */
    private function cities_query(): Builder {
        return GeoDirectoryRow::from_table( self::table_name( GEODIR_LOCATIONS_TABLE ), 'l' )->select( self::CITY_COLUMNS );
    }

    /**
     * The detail rows of published listings: the detail table as `d`, with its posts as `p`.
     */
    private function published_listings(): Builder {
        return GeoDirectoryRow::from_table( $this->detail_table(), 'd' )
            ->join( 'posts as p', 'p.ID', '=', 'd.post_id' )
            ->where( 'p.post_type', $this->post_type )
            ->where( 'p.post_status', 'publish' )
            ->where( 'p.post_password', '' );
    }

    /**
     * The listing detail table.
     */
    private function detail_table(): string {
        return 'geodir_' . $this->post_type . '_detail';
    }

    /**
     * A table's name without the site prefix, which the builder's resolver adds back.
     * Location Manager's constants carry GeoDirectory's own `$wpdb->prefix . 'geodir_'`,
     * so the prefix comes off rather than the name being spelled out a second time here.
     */
    private static function table_name( string $table ): string {
        global $wpdb;
        return 0 === strpos( $table, $wpdb->prefix ) ? substr( $table, strlen( $wpdb->prefix ) ) : $table;
    }
}
