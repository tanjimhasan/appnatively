<?php

namespace Crafium\AppNatively\App\Integrations\Directory;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\DTO\Directory\CategoryDTO;
use Crafium\AppNatively\App\DTO\Directory\CategoryPaginatorDTO;
use Crafium\AppNatively\App\DTO\Directory\ListingDTO;
use Crafium\AppNatively\App\DTO\Directory\ListingPaginatorDTO;
use Crafium\AppNatively\App\DTO\Directory\TermDTO;
use Crafium\AppNatively\App\DTO\Directory\TermPaginatorDTO;
use Crafium\AppNatively\App\Integrations\Directory\Catalog\HivePressCatalog;
use Crafium\AppNatively\App\Integrations\Directory\Catalog\ListingCatalog;
use Crafium\AppNatively\App\Integrations\Directory\Concerns\ListingIntegrationHelpers;
use Crafium\AppNatively\WpMVC\Contracts\Provider;
use Crafium\AppNatively\WpMVC\RequestValidator\Request;
use WP_Comment;
use WP_Post;
use WP_Query;

class HivePress extends Provider {
    use ListingIntegrationHelpers;

    private ?HivePressCatalog $catalog = null;

    public function register() {}

    public function boot(): void {
        if ( ! $this->is_loaded() ) {
            return;
        }

        $this->register_listing_hooks( "hivepress" );
        add_filter( "craf_appna_directory_hivepress_listing", [$this, "listing"], 10, 3 );
        add_filter( "craf_appna_directory_hivepress_related_listings", [$this, "related_listings"], 10, 3 );
        add_filter( "craf_appna_directory_hivepress_reviews", [$this, "reviews"], 10, 2 );
        add_filter( "craf_appna_directory_hivepress_categories", [$this, "categories"], 10, 3 );
        add_filter( "craf_appna_directory_hivepress_category", [$this, "category"], 10, 3 );
        add_filter( "craf_appna_directory_hivepress_tags", [$this, "tags"], 10, 3 );
        add_filter( "craf_appna_directory_hivepress_locations", [$this, "locations"], 10, 3 );
        add_filter( "craf_appna_directory_hivepress_location", [$this, "location"], 10, 3 );
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

        if ( ! $post || ! taxonomy_exists( $this->catalog()->category_taxonomy() ) ) {
            return new ListingPaginatorDTO( $page, $per_page, 0, 1, [] );
        }

        $category_ids = wp_get_post_terms( $listing_id, $this->catalog()->category_taxonomy(), ["fields" => "ids"] );
        if ( is_wp_error( $category_ids ) || empty( $category_ids ) ) {
            return new ListingPaginatorDTO( $page, $per_page, 0, 1, [] );
        }

        $query = new WP_Query(
            [
                "post_type"      => $this->catalog()->post_types()[0],
                "post_status"    => "publish",
                "has_password"   => false,
                "paged"          => $page,
                "posts_per_page" => $per_page,
                //phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small, bounded exclusion of the current listing from its own "related" query.
                "post__not_in"   => [$listing_id],
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering listings by taxonomy is the point of this query.
                "tax_query"      => [
                    [
                        "taxonomy" => $this->catalog()->category_taxonomy(),
                        "field"    => "term_id",
                        "terms"    => array_map( "intval", $category_ids ),
                    ],
                ],
                "orderby"        => "date",
                "order"          => "DESC",
            ]
        );

        $items = [];
        foreach ( $query->posts as $post ) {
            if ( $post instanceof WP_Post ) {
                $items[] = $this->map_listing_to_dto( $post, $fields );
            }
        }

        return new ListingPaginatorDTO( $page, $per_page, (int) $query->found_posts, max( 1, (int) $query->max_num_pages ), $items );
    }

    public function reviews( ?array $reviews, Request $request ): ?array {
        $listing_id = (int) craf_appna_route_param( $request, "id" );

        if ( ! $this->get_listing_post( $listing_id ) ) {
            return null;
        }

        return $this->query_comment_reviews( $listing_id, $request );
    }

    public function categories( ?CategoryPaginatorDTO $category_paginator, Request $request, array $fields = [] ): CategoryPaginatorDTO {
        return $this->query_categories( $request, $fields );
    }

    public function category( ?CategoryDTO $category, Request $request, array $fields = [] ): ?CategoryDTO {
        $term = get_term( (int) craf_appna_route_param( $request, "id" ), $this->catalog()->category_taxonomy() );
        return ( $term && ! is_wp_error( $term ) ) ? $this->map_category_to_dto( $term, $fields ) : null;
    }

    public function tags( ?TermPaginatorDTO $tag_paginator, Request $request, array $fields = [] ): TermPaginatorDTO {
        return $this->empty_term_paginator( $request );
    }

    public function locations( ?TermPaginatorDTO $location_paginator, Request $request, array $fields = [] ): TermPaginatorDTO {
        $taxonomy = $this->location_taxonomy();
        if ( ! $taxonomy ) {
            return $this->empty_term_paginator( $request );
        }

        return $this->query_terms( $taxonomy, $request, $fields );
    }

    public function location( ?TermDTO $location, Request $request, array $fields = [] ): ?TermDTO {
        $taxonomy = $this->location_taxonomy();
        if ( ! $taxonomy ) {
            return null;
        }

        $term = get_term( (int) craf_appna_route_param( $request, "id" ), $taxonomy );
        return ( $term && ! is_wp_error( $term ) ) ? $this->map_term_to_dto( $term, $fields ) : null;
    }

    protected function catalog(): ListingCatalog {
        return $this->catalog ??= new HivePressCatalog();
    }

    private function is_loaded(): bool {
        return post_type_exists( $this->catalog()->post_types()[0] ) || class_exists( "HivePress\\Models\\Listing" );
    }

    private function get_listing_post( int $listing_id ): ?WP_Post {
        $post = get_post( $listing_id );
        if ( ! $post instanceof WP_Post || $post->post_type !== $this->catalog()->post_types()[0] || "publish" !== $post->post_status || craf_appna_is_post_password_protected( $post ) ) {
            return null;
        }

        return $post;
    }

    private function map_listing_to_dto( WP_Post $post, array $fields ): ListingDTO {
        $dto = new ListingDTO();

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
            $dto->set_views_count( (int) $this->get_meta_value( $post->ID, ["hp_view_count", "_hp_view_count", "_view_count"] ) );
        }
        if ( in_array( "address", $fields, true ) ) {
            $dto->set_address( $this->get_meta_value( $post->ID, ["hp_address", "_hp_address", "address"] ) );
        }
        if ( in_array( "latitude", $fields, true ) ) {
            $dto->set_latitude( $this->normalize_coordinate( $this->get_meta_value( $post->ID, ["hp_latitude", "_hp_latitude", "latitude"] ), -90, 90 ) );
        }
        if ( in_array( "longitude", $fields, true ) ) {
            $dto->set_longitude( $this->normalize_coordinate( $this->get_meta_value( $post->ID, ["hp_longitude", "_hp_longitude", "longitude"] ), -180, 180 ) );
        }
        if ( in_array( "phone", $fields, true ) ) {
            $dto->set_phone( $this->get_meta_value( $post->ID, ["hp_phone", "_hp_phone", "phone"] ) );
        }
        if ( in_array( "email", $fields, true ) ) {
            $dto->set_email( $this->get_meta_value( $post->ID, ["hp_email", "_hp_email", "email"] ) );
        }
        if ( in_array( "website", $fields, true ) ) {
            $dto->set_website( $this->get_meta_value( $post->ID, ["hp_website", "_hp_website", "website"] ) );
        }
        if ( in_array( "favorite", $fields, true ) ) {
            $dto->set_favorite( false );
        }
        if ( in_array( "featured", $fields, true ) ) {
            $dto->set_featured( (bool) $this->get_meta_value( $post->ID, ["hp_featured", "_hp_featured"] ) );
        }
        if ( in_array( "new", $fields, true ) ) {
            $dto->set_new( false );
        }
        if ( in_array( "popular", $fields, true ) ) {
            $dto->set_popular( false );
        }
        if ( in_array( "pricing", $fields, true ) ) {
            $dto->set_pricing(
                [
                    "price"       => $this->get_meta_value( $post->ID, ["hp_price", "_hp_price", "price"] ),
                    "price_type"  => $this->get_meta_value( $post->ID, ["hp_price_type", "_hp_price_type"] ),
                    "price_range" => $this->get_meta_value( $post->ID, ["hp_price_range", "_hp_price_range"] ),
                ]
            );
        }
        if ( in_array( "categories", $fields, true ) ) {
            $dto->set_categories( $this->get_listing_terms( $post->ID, $this->catalog()->category_taxonomy() ) );
        }
        if ( in_array( "locations", $fields, true ) ) {
            $taxonomy = $this->location_taxonomy();
            $dto->set_locations( $taxonomy ? $this->get_listing_terms( (int) $post->ID, $taxonomy ) : [] );
        }
        if ( in_array( "tags", $fields, true ) ) {
            $dto->set_tags( [] );
        }
        if ( in_array( "rating", $fields, true ) ) {
            $dto->set_rating( $this->get_listing_aggregate_rating( (int) $post->ID, 0.0 ) );
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

    private function query_categories( Request $request, array $fields ): CategoryPaginatorDTO {
        $page     = (int) $request->get_param( "page" ) ?: 1;
        $per_page = (int) $request->get_param( "per_page" ) ?: 10;

        if ( ! taxonomy_exists( $this->catalog()->category_taxonomy() ) ) {
            return new CategoryPaginatorDTO( $page, $per_page, 0, 1, [] );
        }

        $terms = $this->get_terms_page( $this->catalog()->category_taxonomy(), $request, $page, $per_page );
        $items = [];
        foreach ( $terms["items"] as $term ) {
            $items[] = $this->map_category_to_dto( $term, $fields );
        }

        return new CategoryPaginatorDTO( $page, $per_page, $terms["total"], $terms["last_page"], $items );
    }

    private function get_terms_page( string $taxonomy, Request $request, int $page, int $per_page, bool $pad_counts = false ): array {
        $sort  = sanitize_text_field( (string) $request->get_param( "sort" ) );
        $desc  = 0 === strpos( $sort, "-" );
        $key   = $desc ? ltrim( $sort, "-" ) : $sort;
        $map   = [
            "name"  => "name",
            "id"    => "term_id",
            "slug"  => "slug",
            "count" => "count",
        ];
        $args  = [
            "taxonomy"   => $taxonomy,
            "hide_empty" => false,
            "number"     => $per_page,
            "offset"     => ( $page - 1 ) * $per_page,
            "search"     => sanitize_text_field( (string) $request->get_param( "search" ) ),
            "orderby"    => $map[$key] ?? "name",
            "order"      => $desc ? "DESC" : "ASC",
        ];
        $items = get_terms( $args );
        $total = wp_count_terms( ["taxonomy" => $taxonomy, "hide_empty" => false, "search" => $args["search"]] );

        if ( is_wp_error( $items ) ) {
            $items = [];
        }
        if ( is_wp_error( $total ) ) {
            $total = 0;
        }
        if ( $pad_counts ) {
            $items = $this->pad_term_counts( $items, $taxonomy );
        }

        return [
            "items"     => $items,
            "total"     => (int) $total,
            "last_page" => max( 1, (int) ceil( (int) $total / $per_page ) ),
        ];
    }

    /**
     * Each term's own count plus its descendants'. The Geolocation extension assigns a
     * listing only its deepest region, so every region above it counts none of its own,
     * while the `location` facet counts a parent together with its children
     * (TaxonomyTermSource::options()) — both surfaces describe the same terms, so they
     * have to agree.
     *
     * One read for the whole page, however deep the tree: the hierarchy itself comes
     * from the term cache. A listing held by both a term and one of its descendants
     * would be counted twice, which HivePress never stores; the facet's
     * COUNT(DISTINCT) is the exact number where exactness matters.
     *
     * @param array $items Terms of this page.
     * @return array
     */
    private function pad_term_counts( array $items, string $taxonomy ): array {
        if ( empty( $items ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
            return $items;
        }

        $children = [];
        $wanted   = [];
        foreach ( $items as $term ) {
            $ids = get_term_children( (int) $term->term_id, $taxonomy );
            if ( is_wp_error( $ids ) || empty( $ids ) ) {
                continue;
            }
            $children[(int) $term->term_id] = array_map( "intval", $ids );
            $wanted                         = array_merge( $wanted, $children[(int) $term->term_id] );
        }
        if ( empty( $wanted ) ) {
            return $items;
        }

        $descendants = get_terms(
            [
                "taxonomy"   => $taxonomy,
                "include"    => array_values( array_unique( $wanted ) ),
                "hide_empty" => false,
            ]
        );
        if ( is_wp_error( $descendants ) ) {
            return $items;
        }

        $counts = [];
        foreach ( (array) $descendants as $descendant ) {
            $counts[(int) $descendant->term_id] = (int) $descendant->count;
        }

        $padded = [];
        foreach ( $items as $term ) {
            $sum = 0;
            foreach ( $children[(int) $term->term_id] ?? [] as $child_id ) {
                $sum += (int) ( $counts[$child_id] ?? 0 );
            }
            if ( $sum > 0 ) {
                // A term object is shared through the term cache, so pad a copy of it.
                $term        = clone $term;
                $term->count = (int) $term->count + $sum;
            }
            $padded[] = $term;
        }

        return $padded;
    }

    private function map_category_to_dto( $term, array $fields ): CategoryDTO {
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
            $dto->set_image( $this->get_term_image( (int) $term->term_id ) );
        }

        return $dto;
    }

    private function get_listing_image( int $post_id ): array {
        $image_id = $this->get_listing_image_id( $post_id );

        return $image_id ? $this->map_attachment_image( $image_id ) : [];
    }

    private function get_listing_images( int $post_id, bool $exclude_cover = false ): array {
        $image_ids = $this->get_listing_image_ids( $post_id );
        if ( $exclude_cover ) {
            $cover_id  = $this->get_listing_image_id( $post_id );
            $image_ids = array_values( array_filter( $image_ids, fn( int $image_id ): bool => $image_id !== $cover_id ) );
        }

        return array_values(
            array_filter(
                array_map( [$this, "map_attachment_image"], $image_ids )
            )
        );
    }

    private function get_listing_image_id( int $post_id ): int {
        $image_id = (int) get_post_thumbnail_id( $post_id );
        if ( $image_id > 0 ) {
            return $image_id;
        }

        $image_ids = $this->get_listing_image_ids( $post_id );
        return $image_ids ? (int) reset( $image_ids ) : 0;
    }

    private function get_listing_image_ids( int $post_id ): array {
        $image_ids = [];

        if ( class_exists( "HivePress\\Models\\Listing" ) ) {
            $listing = \HivePress\Models\Listing::query()->get_by_id( $post_id );
            if ( $listing && method_exists( $listing, "get_images__id" ) ) {
                $image_ids = array_merge( $image_ids, $this->positive_ids( $listing->get_images__id() ) );
            }
        }

        foreach ( get_attached_media( "image", $post_id ) as $image ) {
            $parent_field = is_object( $image ) && isset( $image->hp_parent_field ) ? (string) $image->hp_parent_field : "";
            if ( "images" === $parent_field || "" === $parent_field ) {
                $image_ids[] = (int) $image->ID;
            }
        }

        $thumbnail_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id > 0 ) {
            array_unshift( $image_ids, $thumbnail_id );
        }

        return array_values( array_unique( array_filter( $image_ids ) ) );
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

    private function get_term_image( int $term_id ): array {
        foreach ( ["image", "thumbnail_id", "_thumbnail_id"] as $key ) {
            $value = get_term_meta( $term_id, $key, true );
            $id    = is_numeric( $value ) ? (int) $value : 0;
            $src   = $id ? wp_get_attachment_url( $id ) : ( is_string( $value ) ? $value : "" );
            if ( $src && filter_var( $src, FILTER_VALIDATE_URL ) ) {
                return ["id" => $id, "src" => esc_url_raw( $src )];
            }
        }

        return [];
    }

    private function get_listing_terms( int $post_id, string $taxonomy ): array {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return [];
        }

        return array_map(
            function( $term ): array {
                return ["id" => (int) $term->term_id, "name" => (string) $term->name, "slug" => (string) $term->slug];
            },
            $terms
        );
    }

    private function get_meta_value( int $post_id, array $keys ): string {
        foreach ( $keys as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( "" !== $value && null !== $value ) {
                return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : "";
            }
        }

        return "";
    }

    private function query_comment_reviews( int $listing_id, Request $request ): array {
        $base         = [
            "post_id" => $listing_id,
            "status"  => "approve",
            "type"    => "hp_review",
            "parent"  => 0,
        ];
        $query        = $this->query_directory_review_rows(
            $base,
            $request,
            fn( WP_Comment $comment ): float => (float) $comment->comment_karma
        );
        $review_count = $this->get_listing_review_count( $listing_id );

        $items = [];

        foreach ( $query["rows"] as $row ) {
            $items[] = $this->map_review_comment( $row["comment"], $row["rating"] );
        }

        $rating_counts = $this->get_review_rating_counts( $listing_id );
        $average       = $this->calculate_average_rating( $rating_counts );

        return [
            "current_page"   => $query["current_page"],
            "per_page"       => $query["per_page"],
            "total"          => $query["total"],
            "last_page"      => $query["last_page"],
            "average_rating" => $this->get_listing_aggregate_rating( $listing_id, $average ),
            "review_count"   => $review_count,
            "rating_counts"  => $rating_counts,
            "items"          => $items,
        ];
    }

    private function get_listing_review_count( int $listing_id ): int {
        return (int) get_comments( ["post_id" => $listing_id, "status" => "approve", "type" => "hp_review", "parent" => 0, "count" => true] );
    }

    private function get_review_rating_counts( int $listing_id ): array {
        $rating_counts = $this->empty_rating_counts();
        $comments      = get_comments( ["post_id" => $listing_id, "status" => "approve", "type" => "hp_review", "parent" => 0] );

        foreach ( $comments as $comment ) {
            $rating = (float) $comment->comment_karma;
            if ( $rating > 0 ) {
                $rating_counts[(string) max( 1, min( 5, (int) round( $rating ) ) )]++;
            }
        }

        return $rating_counts;
    }

    private function calculate_average_rating( array $rating_counts ): float {
        $total = 0;
        $sum   = 0;
        foreach ( $rating_counts as $rating => $count ) {
            $total += (int) $count;
            $sum   += (int) $rating * (int) $count;
        }
        return $total > 0 ? round( $sum / $total, 1 ) : 0.0;
    }

    private function map_review_comment( \WP_Comment $comment, float $rating ): array {
        return [
            "id"           => (int) $comment->comment_ID,
            "reviewer"     => sanitize_text_field( (string) $comment->comment_author ),
            "review"       => wp_kses_post( (string) $comment->comment_content ),
            "rating"       => $rating,
            "date_created" => (string) get_comment_date( DATE_ATOM, $comment ),
            "avatar_url"   => (string) get_avatar_url( $comment, ["size" => 96] ),
        ];
    }

    private function get_listing_aggregate_rating( int $listing_id, float $fallback ): float {
        $stored = get_post_meta( $listing_id, "hp_rating", true );
        return is_numeric( $stored ) && (float) $stored > 0 ? (float) $stored : $fallback;
    }

    private function empty_rating_counts(): array {
        return ["1" => 0, "2" => 0, "3" => 0, "4" => 0, "5" => 0];
    }

    private function location_taxonomy(): ?string {
        return $this->catalog()->location_taxonomy();
    }

    private function query_terms( string $taxonomy, Request $request, array $fields ): TermPaginatorDTO {
        $page     = (int) $request->get_param( "page" ) ?: 1;
        $per_page = (int) $request->get_param( "per_page" ) ?: 10;
        $terms    = $this->get_terms_page( $taxonomy, $request, $page, $per_page, true );
        $items    = [];

        foreach ( $terms["items"] as $term ) {
            $items[] = $this->map_term_to_dto( $term, $fields );
        }

        return new TermPaginatorDTO( $page, $per_page, $terms["total"], $terms["last_page"], $items );
    }

    private function map_term_to_dto( $term, array $fields ): TermDTO {
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
            $dto->set_image( [] );
        }

        return $dto;
    }
}
