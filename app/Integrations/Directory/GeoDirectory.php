<?php

namespace Crafium\AppNatively\App\Integrations\Directory;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\DTO\Directory\CategoryDTO;
use Crafium\AppNatively\App\DTO\Directory\CategoryPaginatorDTO;
use Crafium\AppNatively\App\DTO\Directory\ListingDTO;
use Crafium\AppNatively\App\DTO\Directory\ListingPaginatorDTO;
use Crafium\AppNatively\App\DTO\Directory\TermDTO;
use Crafium\AppNatively\App\DTO\Directory\TermPaginatorDTO;
use Crafium\AppNatively\App\Integrations\Directory\Catalog\GeoDirectoryCatalog;
use Crafium\AppNatively\App\Integrations\Directory\Catalog\ListingCatalog;
use Crafium\AppNatively\App\Integrations\Directory\Concerns\ListingIntegrationHelpers;
use Crafium\AppNatively\WpMVC\Contracts\Provider;
use Crafium\AppNatively\WpMVC\RequestValidator\Request;
use WP_Comment;
use WP_Post;
use WP_Query;

class GeoDirectory extends Provider {
    use ListingIntegrationHelpers;

    private ?GeoDirectoryCatalog $catalog = null;

    public function register() {}

    public function boot(): void {
        if ( ! $this->is_loaded() ) {
            return;
        }

        $this->register_listing_hooks( "geodirectory" );
        add_filter( "craf_appna_directory_geodirectory_listing", [$this, "listing"], 10, 3 );
        add_filter( "craf_appna_directory_geodirectory_related_listings", [$this, "related_listings"], 10, 3 );
        add_filter( "craf_appna_directory_geodirectory_reviews", [$this, "reviews"], 10, 2 );
        add_filter( "craf_appna_directory_geodirectory_categories", [$this, "categories"], 10, 3 );
        add_filter( "craf_appna_directory_geodirectory_category", [$this, "category"], 10, 3 );
        add_filter( "craf_appna_directory_geodirectory_tags", [$this, "tags"], 10, 3 );
        add_filter( "craf_appna_directory_geodirectory_locations", [$this, "locations"], 10, 3 );
        add_filter( "craf_appna_directory_geodirectory_location", [$this, "location"], 10, 3 );
    }

    public function listing( ?ListingDTO $listing, Request $request, array $fields = [] ): ?ListingDTO {
        $post = $this->get_listing_post( (int) craf_appna_route_param( $request, "id" ) );
        return $post ? $this->map_listing_to_dto( $post, $fields ) : null;
    }

    public function related_listings( ?ListingPaginatorDTO $listing_paginator, Request $request, array $fields = [] ): ListingPaginatorDTO {
        $listing_id = (int) craf_appna_route_param( $request, "id" );
        $page       = (int) $request->get_param( "page" ) ?: 1;
        $per_page   = (int) $request->get_param( "per_page" ) ?: 10;
        $post       = $this->get_listing_post( $listing_id );

        if ( ! $post ) {
            return new ListingPaginatorDTO( $page, $per_page, 0, 1, [] );
        }

        return $this->query_related_listings( $listing_id, $request, $fields );
    }

    public function reviews( ?array $reviews, Request $request ): ?array {
        $listing_id = (int) craf_appna_route_param( $request, "id" );

        if ( ! $this->get_listing_post( $listing_id ) ) {
            return null;
        }

        return $this->query_comment_reviews( $listing_id, $request, ["rating", "_rating", "geodir_overallrating", "_geodir_overallrating", "overall_rating"] );
    }

    public function categories( ?CategoryPaginatorDTO $category_paginator, Request $request, array $fields = [] ): CategoryPaginatorDTO {
        return $this->query_categories( $this->category_taxonomy(), $request, $fields, ["ct_cat_icon", "ct_cat_default_img", "thumbnail_id"] );
    }

    public function category( ?CategoryDTO $category, Request $request, array $fields = [] ): ?CategoryDTO {
        $term = get_term( (int) craf_appna_route_param( $request, "id" ), $this->category_taxonomy() );
        return ( $term && ! is_wp_error( $term ) ) ? $this->map_category_to_dto( $term, $fields, ["ct_cat_icon", "ct_cat_default_img", "thumbnail_id"] ) : null;
    }

    public function tags( ?TermPaginatorDTO $tag_paginator, Request $request, array $fields = [] ): TermPaginatorDTO {
        return $this->query_terms( $this->tag_taxonomy(), $request, $fields );
    }

    public function locations( ?TermPaginatorDTO $location_paginator, Request $request, array $fields = [] ): TermPaginatorDTO {
        $store = $this->location_store();

        if ( ! $store ) {
            return $this->empty_term_paginator( $request );
        }

        $page     = (int) $request->get_param( "page" ) ?: 1;
        $per_page = (int) $request->get_param( "per_page" ) ?: 10;
        $cities   = $store->page(
            sanitize_text_field( (string) $request->get_param( "search" ) ),
            sanitize_text_field( (string) $request->get_param( "sort" ) ),
            $per_page,
            ( $page - 1 ) * $per_page
        );

        $images = $this->location_images( $store, $cities["items"], $fields );
        $items  = [];

        foreach ( $cities["items"] as $city ) {
            $items[] = $this->map_city_to_dto( $city, $fields, (int) $city->post_count, $images[(int) $city->location_id] ?? [] );
        }

        return new TermPaginatorDTO( $page, $per_page, $cities["total"], max( 1, (int) ceil( $cities["total"] / $per_page ) ), $items );
    }

    public function location( ?TermDTO $location, Request $request, array $fields = [] ): ?TermDTO {
        $store = $this->location_store();
        $city  = $store ? $store->city( (int) craf_appna_route_param( $request, "id" ) ) : null;

        if ( ! $city ) {
            return null;
        }

        $images = $this->location_images( $store, [$city], $fields );
        $count  = in_array( "count", $fields, true ) ? $store->count_for( $city ) : 0;

        return $this->map_city_to_dto( $city, $fields, $count, $images[(int) $city->location_id] ?? [] );
    }

    protected function prime_listings( array $listing_ids, array $fields ): void {
        $store = $this->location_store();

        if ( $store && ! empty( $listing_ids ) && in_array( "locations", $fields, true ) ) {
            $store->prime( $listing_ids );
        }
    }

    protected function catalog(): ListingCatalog {
        return $this->geodirectory_catalog();
    }

    private function geodirectory_catalog(): GeoDirectoryCatalog {
        return $this->catalog ??= new GeoDirectoryCatalog();
    }

    /**
     * Where GeoDirectory's locations are, or null without the Location Manager add-on.
     * The catalog's own instance, so the filter and these reads share its lookups.
     */
    private function location_store(): ?GeoDirectoryLocations {
        return $this->geodirectory_catalog()->geodirectory_locations();
    }

    private function is_loaded(): bool {
        return function_exists( "geodir_get_post_info" ) || function_exists( "geodir_get_posttypes" ) || class_exists( "GeoDirectory" );
    }

    private function post_type(): string {
        return $this->catalog()->post_types()[0];
    }

    private function category_taxonomy(): string {
        return $this->catalog()->category_taxonomy();
    }

    private function tag_taxonomy(): string {
        return (string) $this->catalog()->tag_taxonomy();
    }

    private function get_listing_post( int $listing_id ): ?WP_Post {
        $post = get_post( $listing_id );

        if ( ! $post instanceof WP_Post || $post->post_type !== $this->post_type() || "publish" !== $post->post_status || craf_appna_is_post_password_protected( $post ) ) {
            return null;
        }

        return $post;
    }

    private function query_related_listings( int $listing_id, Request $request, array $fields ): ListingPaginatorDTO {
        $page      = (int) $request->get_param( "page" ) ?: 1;
        $per_page  = (int) $request->get_param( "per_page" ) ?: 10;
        $tax_query = ["relation" => "OR"];

        foreach ( [$this->category_taxonomy(), $this->tag_taxonomy()] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $term_ids = wp_get_post_terms( $listing_id, $taxonomy, ["fields" => "ids"] );
            if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
                $tax_query[] = [
                    "taxonomy" => $taxonomy,
                    "field"    => "term_id",
                    "terms"    => array_map( "intval", $term_ids ),
                ];
            }
        }

        if ( 1 === count( $tax_query ) ) {
            return new ListingPaginatorDTO( $page, $per_page, 0, 1, [] );
        }

        $query = new WP_Query(
            [
                "post_type"      => $this->post_type(),
                "post_status"    => "publish",
                "has_password"   => false,
                "paged"          => $page,
                "posts_per_page" => $per_page,
                //phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small, bounded exclusion of the current listing from its own "related" query.
                "post__not_in"   => [$listing_id],
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering listings by taxonomy is the point of this query.
                "tax_query"      => $tax_query,
                "orderby"        => "date",
                "order"          => "DESC",
            ]
        );

        $posts = [];
        foreach ( $query->posts as $post ) {
            if ( $post instanceof WP_Post ) {
                $posts[] = $post;
            }
        }

        $this->prime_listings( array_map( fn( WP_Post $post ): int => (int) $post->ID, $posts ), $fields );

        $items = [];
        foreach ( $posts as $post ) {
            $items[] = $this->map_listing_to_dto( $post, $fields );
        }

        return new ListingPaginatorDTO( $page, $per_page, (int) $query->found_posts, max( 1, (int) $query->max_num_pages ), $items );
    }

    private function map_listing_to_dto( WP_Post $post, array $fields ): ListingDTO {
        $dto  = new ListingDTO();
        $info = $this->get_post_info( (int) $post->ID );

        if ( in_array( "id", $fields, true ) ) {
            $dto->set_id( (int) $post->ID );
        }
        if ( in_array( "url", $fields, true ) ) {
            $dto->set_url( (string) get_permalink( $post ) );
        }
        if ( in_array( "title", $fields, true ) ) {
            $dto->set_title( get_the_title( $post ) );
        }
        if ( in_array( "slug", $fields, true ) ) {
            $dto->set_slug( (string) $post->post_name );
        }
        if ( in_array( "description", $fields, true ) ) {
            $dto->set_description( $this->apply_listing_content_filters( $post ) );
        }
        if ( in_array( "excerpt", $fields, true ) ) {
            $dto->set_excerpt( $this->get_listing_excerpt( $post ) );
        }
        if ( in_array( "status", $fields, true ) ) {
            $dto->set_status( (string) $post->post_status );
        }
        if ( in_array( "image", $fields, true ) ) {
            $dto->set_image( $this->get_listing_image( (int) $post->ID ) );
        }
        if ( in_array( "images", $fields, true ) ) {
            $dto->set_images( $this->get_listing_images( (int) $post->ID, in_array( "image", $fields, true ) ) );
        }
        if ( in_array( "views_count", $fields, true ) ) {
            $dto->set_views_count( 0 );
        }
        if ( in_array( "address", $fields, true ) ) {
            $dto->set_address( $this->get_value( $info, ["address", "street", "street2"] ) );
        }
        if ( in_array( "latitude", $fields, true ) ) {
            $dto->set_latitude( $this->normalize_coordinate( $this->get_value( $info, ["latitude", "post_latitude"] ), -90, 90 ) );
        }
        if ( in_array( "longitude", $fields, true ) ) {
            $dto->set_longitude( $this->normalize_coordinate( $this->get_value( $info, ["longitude", "post_longitude"] ), -180, 180 ) );
        }
        if ( in_array( "phone", $fields, true ) ) {
            $dto->set_phone( $this->get_value( $info, ["phone"] ) );
        }
        if ( in_array( "email", $fields, true ) ) {
            $dto->set_email( $this->get_value( $info, ["email"] ) );
        }
        if ( in_array( "website", $fields, true ) ) {
            $dto->set_website( $this->get_value( $info, ["website"] ) );
        }
        if ( in_array( "favorite", $fields, true ) ) {
            $dto->set_favorite( false );
        }
        if ( in_array( "featured", $fields, true ) ) {
            $dto->set_featured( (bool) $this->get_value( $info, ["is_featured", "featured"] ) );
        }
        if ( in_array( "new", $fields, true ) ) {
            $dto->set_new( false );
        }
        if ( in_array( "popular", $fields, true ) ) {
            $dto->set_popular( false );
        }
        if ( in_array( "pricing", $fields, true ) ) {
            $dto->set_pricing( $this->get_pricing( $post->ID, $info ) );
        }
        if ( in_array( "categories", $fields, true ) ) {
            $dto->set_categories( $this->get_listing_terms( $post->ID, $this->category_taxonomy() ) );
        }
        if ( in_array( "locations", $fields, true ) ) {
            $dto->set_locations( $this->get_listing_locations( (int) $post->ID ) );
        }
        if ( in_array( "tags", $fields, true ) ) {
            $dto->set_tags( $this->get_listing_terms( $post->ID, $this->tag_taxonomy() ) );
        }
        if ( in_array( "rating", $fields, true ) ) {
            $dto->set_rating( $this->get_rating( $post->ID ) );
        }
        if ( in_array( "review_count", $fields, true ) || in_array( "rating_count", $fields, true ) ) {
            $review_count = $this->get_listing_review_count( (int) $post->ID );

            if ( in_array( "review_count", $fields, true ) ) {
                $dto->set_review_count( $review_count );
            }
            if ( in_array( "rating_count", $fields, true ) ) {
                $dto->set_rating_count( $review_count );
            }
        }

        return $dto;
    }

    private function get_post_info( int $post_id ) {
        return function_exists( "geodir_get_post_info" ) ? geodir_get_post_info( $post_id ) : null;
    }

    private function get_value( $info, array $keys ): string {
        $values = is_object( $info ) ? get_object_vars( $info ) : ( is_array( $info ) ? $info : [] );

        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $values ) && "" !== $values[$key] && null !== $values[$key] ) {
                return is_scalar( $values[$key] ) ? sanitize_text_field( (string) $values[$key] ) : "";
            }
        }

        return "";
    }

    private function get_pricing( int $post_id, $info ): array {
        return [
            "price"       => $this->get_value( $info, ["price", "price_from"] ),
            "price_type"  => $this->get_value( $info, ["price_type"] ),
            "price_range" => $this->get_value( $info, ["price_range"] ),
        ];
    }

    private function get_listing_image( int $post_id ): array {
        $image_id = (int) get_post_thumbnail_id( $post_id );
        if ( $image_id > 0 ) {
            return $this->map_attachment_image( $image_id );
        }

        $images = $this->get_geodirectory_images( $post_id, 1 );
        return $images ? $this->map_geodirectory_image( reset( $images ) ) : [];
    }

    private function get_listing_images( int $post_id, bool $exclude_cover = false ): array {
        $images = [];
        foreach ( $this->get_geodirectory_images( $post_id ) as $image ) {
            $mapped = $this->map_geodirectory_image( $image );
            if ( $mapped ) {
                $images[] = $mapped;
            }
        }

        $thumbnail_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id > 0 ) {
            $thumbnail = $this->map_attachment_image( $thumbnail_id );
            if ( $thumbnail ) {
                array_unshift( $images, $thumbnail );
            }
        }

        $seen   = [];
        $images = array_values(
            array_filter(
                $images,
                function( array $image ) use ( &$seen ): bool {
                    $key = (string) ( $image["id"] ?: $image["src"] );
                    if ( "" === $key || isset( $seen[$key] ) ) {
                        return false;
                    }
                    $seen[$key] = true;
                    return true;
                }
            )
        );

        if ( $exclude_cover ) {
            $cover     = $this->get_listing_image( $post_id );
            $cover_key = $cover ? (string) ( $cover["id"] ?: $cover["src"] ) : "";
            $images    = array_values(
                array_filter(
                    $images,
                    fn( array $image ): bool => "" === $cover_key || (string) ( $image["id"] ?: $image["src"] ) !== $cover_key
                )
            );
        }

        return $images;
    }

    private function get_geodirectory_images( int $post_id, $limit = "" ): array {
        if ( function_exists( "geodir_get_images" ) ) {
            return (array) geodir_get_images( $post_id, $limit, false, "", ["post_images"], [] );
        }

        if ( class_exists( "\\GeoDir_Media" ) ) {
            return (array) \GeoDir_Media::get_attachments_by_type( $post_id, "post_images", $limit );
        }

        return [];
    }

    private function map_geodirectory_image( $image ): array {
        if ( ! is_object( $image ) ) {
            return [];
        }

        $image_id = isset( $image->ID ) ? (int) $image->ID : 0;
        if ( $image_id > 0 && "attachment" === get_post_type( $image_id ) ) {
            return $this->map_attachment_image( $image_id );
        }

        $src = function_exists( "geodir_get_image_src" ) ? geodir_get_image_src( $image, "" ) : "";
        if ( ! $src ) {
            return [];
        }

        return [
            "id"    => $image_id,
            "src"   => esc_url_raw( (string) $src ),
            "alt"   => sanitize_text_field( (string) ( $image->title ?? "" ) ),
            "title" => sanitize_text_field( (string) ( $image->title ?? "" ) ),
        ];
    }

    private function map_attachment_image( int $image_id ): array {
        $src = wp_get_attachment_url( $image_id );

        return $src ? [
            "id"    => (int) $image_id,
            "src"   => esc_url_raw( (string) $src ),
            "alt"   => sanitize_text_field( (string) get_post_meta( $image_id, "_wp_attachment_image_alt", true ) ),
            "title" => (string) get_the_title( $image_id ),
        ] : [];
    }

    private function get_rating( int $post_id ): float {
        if ( function_exists( "geodir_get_post_rating" ) ) {
            return (float) geodir_get_post_rating( $post_id );
        }

        return (float) get_post_meta( $post_id, "overall_rating", true );
    }

    private function query_categories( string $taxonomy, Request $request, array $fields, array $image_keys ): CategoryPaginatorDTO {
        $page     = (int) $request->get_param( "page" ) ?: 1;
        $per_page = (int) $request->get_param( "per_page" ) ?: 10;

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new CategoryPaginatorDTO( $page, $per_page, 0, 1, [] );
        }

        $terms = $this->get_terms_page( $taxonomy, $request, $page, $per_page );
        $items = [];
        foreach ( $terms["items"] as $term ) {
            $items[] = $this->map_category_to_dto( $term, $fields, $image_keys );
        }

        return new CategoryPaginatorDTO( $page, $per_page, $terms["total"], $terms["last_page"], $items );
    }

    private function query_terms( string $taxonomy, Request $request, array $fields ): TermPaginatorDTO {
        $page     = (int) $request->get_param( "page" ) ?: 1;
        $per_page = (int) $request->get_param( "per_page" ) ?: 10;

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new TermPaginatorDTO( $page, $per_page, 0, 1, [] );
        }

        $terms = $this->get_terms_page( $taxonomy, $request, $page, $per_page );
        $items = [];
        foreach ( $terms["items"] as $term ) {
            $items[] = $this->map_term_to_dto( $term, $fields, [] );
        }

        return new TermPaginatorDTO( $page, $per_page, $terms["total"], $terms["last_page"], $items );
    }

    private function get_terms_page( string $taxonomy, Request $request, int $page, int $per_page ): array {
        $sort     = sanitize_text_field( (string) $request->get_param( "sort" ) );
        $order_by = "name";
        $order    = "ASC";

        if ( "" !== $sort ) {
            if ( 0 === strpos( $sort, "-" ) ) {
                $order_by = ltrim( $sort, "-" );
                $order    = "DESC";
            } else {
                $order_by = $sort;
                $order    = "ASC";
            }
        }

        $sort_map = [
            "name"  => "name",
            "id"    => "term_id",
            "slug"  => "slug",
            "count" => "count",
        ];

        $args = [
            "taxonomy"   => $taxonomy,
            "hide_empty" => false,
            "number"     => $per_page,
            "offset"     => ( $page - 1 ) * $per_page,
            "search"     => sanitize_text_field( (string) $request->get_param( "search" ) ),
            "orderby"    => $sort_map[$order_by] ?? "name",
            "order"      => $order,
        ];

        $items = get_terms( $args );
        $total = wp_count_terms(
            [
                "taxonomy"   => $taxonomy,
                "hide_empty" => false,
                "search"     => $args["search"],
            ]
        );

        if ( is_wp_error( $items ) ) {
            $items = [];
        }
        if ( is_wp_error( $total ) ) {
            $total = 0;
        }

        $total = (int) $total;

        return [
            "items"     => $items,
            "total"     => $total,
            "last_page" => max( 1, (int) ceil( $total / $per_page ) ),
        ];
    }

    private function map_category_to_dto( $term, array $fields, array $image_keys ): CategoryDTO {
        $dto = new CategoryDTO();

        if ( in_array( "id", $fields, true ) ) {
            $dto->set_id( (int) $term->term_id );
        }
        if ( in_array( "name", $fields, true ) ) {
            $dto->set_name( (string) $term->name );
        }
        if ( in_array( "slug", $fields, true ) ) {
            $dto->set_slug( (string) $term->slug );
        }
        if ( in_array( "description", $fields, true ) ) {
            $dto->set_description( (string) $term->description );
        }
        if ( in_array( "parent", $fields, true ) ) {
            $dto->set_parent( (int) $term->parent );
        }
        if ( in_array( "count", $fields, true ) ) {
            $dto->set_count( (int) $term->count );
        }
        if ( in_array( "image", $fields, true ) ) {
            $dto->set_image( $this->get_term_image( (int) $term->term_id, $image_keys ) );
        }

        return $dto;
    }

    private function map_term_to_dto( $term, array $fields, array $image_keys ): TermDTO {
        $dto = new TermDTO();

        if ( in_array( "id", $fields, true ) ) {
            $dto->set_id( (int) $term->term_id );
        }
        if ( in_array( "name", $fields, true ) ) {
            $dto->set_name( (string) $term->name );
        }
        if ( in_array( "slug", $fields, true ) ) {
            $dto->set_slug( (string) $term->slug );
        }
        if ( in_array( "count", $fields, true ) ) {
            $dto->set_count( (int) $term->count );
        }
        if ( in_array( "image", $fields, true ) ) {
            $dto->set_image( $this->get_term_image( (int) $term->term_id, $image_keys ) );
        }

        return $dto;
    }

    private function map_city_to_dto( object $city, array $fields, int $count, array $image ): TermDTO {
        $dto = new TermDTO();

        if ( in_array( "id", $fields, true ) ) {
            $dto->set_id( (int) $city->location_id );
        }
        if ( in_array( "name", $fields, true ) ) {
            $dto->set_name( (string) $city->city );
        }
        if ( in_array( "slug", $fields, true ) ) {
            $dto->set_slug( (string) $city->city_slug );
        }
        if ( in_array( "count", $fields, true ) ) {
            $dto->set_count( $count );
        }
        if ( in_array( "image", $fields, true ) ) {
            $dto->set_image( $image );
        }

        return $dto;
    }

    /**
     * The image of each of these cities, by location id: the one Location Manager keeps for
     * it, or else an image from a listing in that city. That fallback is what Location
     * Manager's own location pages do, a city image being optional and rarely set.
     *
     * @param object[] $cities
     * @return array<int, array>
     */
    private function location_images( GeoDirectoryLocations $store, array $cities, array $fields ): array {
        if ( ! in_array( "image", $fields, true ) || empty( $cities ) ) {
            return [];
        }

        $images = [];
        foreach ( $store->image_values( $cities ) as $location_id => $value ) {
            $image = $this->normalize_image_value( $value );
            if ( $image ) {
                $images[ (int) $location_id ] = $image;
            }
        }

        $without = array_values( array_filter( $cities, fn( $city ): bool => empty( $images[ (int) $city->location_id ] ) ) );
        $listings = $without ? $store->listing_ids( $without ) : [];

        if ( empty( $listings ) ) {
            return $images;
        }

        // The listing images are read post by post, so prime their meta for the whole page.
        _prime_post_caches( array_values( array_unique( $listings ) ), false, true );

        foreach ( $listings as $location_id => $listing_id ) {
            $image = $this->get_listing_image( (int) $listing_id );
            if ( $image ) {
                $images[ (int) $location_id ] = $image;
            }
        }

        return $images;
    }

    /**
     * A listing's city, shaped like its categories and tags. None or one: a GeoDirectory
     * listing sits in exactly one city.
     */
    private function get_listing_locations( int $listing_id ): array {
        $store = $this->location_store();
        $city  = $store ? $store->city_for_post( $listing_id ) : null;

        return $city ? [
            [
                "id"   => (int) $city->location_id,
                "name" => (string) $city->city,
                "slug" => (string) $city->city_slug,
            ],
        ] : [];
    }

    private function get_term_image( int $term_id, array $keys ): array {
        foreach ( $keys as $key ) {
            $image = $this->normalize_image_value( get_term_meta( $term_id, $key, true ) );
            if ( ! empty( $image ) ) {
                return $image;
            }
        }

        return [];
    }

    private function normalize_image_value( $value ): array {
        if ( empty( $value ) ) {
            return [];
        }

        if ( is_array( $value ) ) {
            $id  = (int) ( $value["id"] ?? $value["attachment_id"] ?? 0 );
            $src = (string) ( $value["src"] ?? $value["url"] ?? "" );
        } else {
            $id  = is_numeric( $value ) ? (int) $value : 0;
            $src = is_numeric( $value ) ? (string) wp_get_attachment_url( (int) $value ) : (string) $value;
        }

        if ( ! $src || ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
            return [];
        }

        return [
            "id"  => $id,
            "src" => esc_url_raw( $src ),
        ];
    }

    private function get_listing_terms( int $listing_id, string $taxonomy ): array {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }

        $terms = get_the_terms( $listing_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return [];
        }

        return array_map(
            function( $term ): array {
                return [
                    "id"   => (int) $term->term_id,
                    "name" => (string) $term->name,
                    "slug" => (string) $term->slug,
                ];
            },
            $terms
        );
    }

    private function query_comment_reviews( int $listing_id, Request $request, array $rating_meta_keys ): array {
        $base         = [
            "post_id" => $listing_id,
            "status"  => "approve",
        ];
        $query        = $this->query_directory_review_rows(
            $base,
            $request,
            fn( WP_Comment $comment ): float => $this->get_comment_rating( (int) $comment->comment_ID, $rating_meta_keys )
        );
        $items        = [];
        $review_count = $this->get_listing_review_count( $listing_id );

        foreach ( $query["rows"] as $row ) {
            $items[] = $this->map_review_comment( $row["comment"], $row["rating"] );
        }

        $stored_rating_counts = $this->get_stored_review_rating_counts( $listing_id );
        $rating_counts        = null !== $stored_rating_counts
            ? $stored_rating_counts
            : $this->get_review_rating_counts( $listing_id, $rating_meta_keys );

        return [
            "current_page"   => $query["current_page"],
            "per_page"       => $query["per_page"],
            "total"          => $query["total"],
            "last_page"      => $query["last_page"],
            "average_rating" => $this->get_rating( $listing_id ),
            "review_count"   => $review_count,
            "rating_counts"  => $rating_counts,
            "items"          => $items,
        ];
    }

    private function get_listing_review_count( int $listing_id ): int {
        return (int) get_comments( ["post_id" => $listing_id, "status" => "approve", "count" => true] );
    }

    private function get_review_rating_counts( int $listing_id, array $rating_meta_keys ): array {
        $rating_counts = $this->empty_rating_counts();
        $comments      = get_comments( ["post_id" => $listing_id, "status" => "approve"] );

        foreach ( $comments as $comment ) {
            $rating = $this->get_comment_rating( (int) $comment->comment_ID, $rating_meta_keys );
            if ( $rating > 0 ) {
                $rating_counts[(string) max( 1, min( 5, (int) round( $rating ) ) )]++;
            }
        }

        return $rating_counts;
    }

    private function map_review_comment( $comment, float $rating ): array {
        return [
            "id"           => (int) $comment->comment_ID,
            "reviewer"     => sanitize_text_field( (string) $comment->comment_author ),
            "review"       => wp_kses_post( (string) $comment->comment_content ),
            "rating"       => $rating,
            "date_created" => (string) get_comment_date( DATE_ATOM, $comment ),
            "avatar_url"   => (string) get_avatar_url( $comment, ["size" => 96] ),
        ];
    }

    private function get_comment_rating( int $comment_id, array $keys ): float {
        if ( class_exists( "\GeoDir_Comments" ) && method_exists( "\GeoDir_Comments", "get_comment_rating" ) ) {
            $rating = \GeoDir_Comments::get_comment_rating( $comment_id );
            if ( is_numeric( $rating ) ) {
                return (float) $rating;
            }
        }

        if ( class_exists( "\GeoDir_Comments" ) && method_exists( "\GeoDir_Comments", "get_review" ) ) {
            $review = \GeoDir_Comments::get_review( $comment_id );
            if ( is_object( $review ) && isset( $review->rating ) && is_numeric( $review->rating ) ) {
                return (float) $review->rating;
            }
        }

        foreach ( $keys as $key ) {
            $rating = get_comment_meta( $comment_id, $key, true );
            if ( is_numeric( $rating ) ) {
                return (float) $rating;
            }
        }

        return 0.0;
    }

    private function get_stored_review_rating_counts( int $listing_id ): ?array {
        $rating_counts = $this->empty_rating_counts();

        if ( class_exists( "\GeoDir_Comments" ) && method_exists( "\GeoDir_Comments", "get_post_review_rating_counts" ) ) {
            $counts = \GeoDir_Comments::get_post_review_rating_counts( $listing_id, 1 );
            if ( is_array( $counts ) ) {
                foreach ( $counts as $rating => $count ) {
                    $bucket                  = (string) max( 1, min( 5, (int) round( (float) $rating ) ) );
                    $rating_counts[$bucket] += (int) $count;
                }

                return $rating_counts;
            }
        }

        return null;
    }

    private function empty_rating_counts(): array {
        return [
            "1" => 0,
            "2" => 0,
            "3" => 0,
            "4" => 0,
            "5" => 0,
        ];
    }
}
