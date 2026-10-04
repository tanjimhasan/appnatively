<?php

namespace Crafium\AppNatively\Tests\Integrations;

use Crafium\AppNatively\App\DTO\Directory\CategoryDTO;
use Crafium\AppNatively\App\DTO\Directory\CategoryPaginatorDTO;
use Crafium\AppNatively\App\DTO\Directory\ListingDTO;
use Crafium\AppNatively\App\DTO\Directory\ListingPaginatorDTO;
use Crafium\AppNatively\App\DTO\Directory\TermDTO;
use Crafium\AppNatively\App\DTO\Directory\TermPaginatorDTO;
use Crafium\AppNatively\App\DTO\Filter\FiltersDTO;
use Crafium\AppNatively\WpMVC\RequestValidator\Request;

abstract class DirectoryIntegrationTestCase extends \WP_UnitTestCase {
    protected array $created_post_ids = [];

    protected array $created_terms = [];

    protected function ensure_post_type( string $post_type ): void {
        if ( post_type_exists( $post_type ) ) {
            return;
        }

        register_post_type(
            $post_type,
            [
                "public"       => true,
                "show_ui"      => true,
                "has_archive"  => true,
                "supports"     => ["title", "editor", "excerpt", "thumbnail", "comments"],
                "rewrite"      => false,
                "show_in_rest" => true,
            ]
        );
    }

    protected function ensure_taxonomy( string $taxonomy, string $post_type, bool $hierarchical = true ): void {
        if ( taxonomy_exists( $taxonomy ) ) {
            register_taxonomy_for_object_type( $taxonomy, $post_type );
            return;
        }

        register_taxonomy(
            $taxonomy,
            $post_type,
            [
                "public"       => true,
                "hierarchical" => $hierarchical,
                "show_in_rest" => true,
            ]
        );
    }

    protected function create_term( string $taxonomy, string $name, int $parent = 0 ): int {
        $term = wp_insert_term( $name, $taxonomy, $parent > 0 ? ["parent" => $parent] : [] );

        if ( is_wp_error( $term ) ) {
            $existing = get_term_by( "name", $name, $taxonomy );
            $term_id  = $existing ? (int) $existing->term_id : 0;
        } else {
            $term_id = (int) $term["term_id"];
        }

        $this->created_terms[] = [
            "id"       => $term_id,
            "taxonomy" => $taxonomy,
        ];

        return $term_id;
    }

    protected function create_listing( string $post_type, string $title, string $status = "publish", array $meta = [] ): int {
        $post_id = wp_insert_post(
            [
                "post_title"   => $title,
                "post_name"    => sanitize_title( $title ),
                "post_type"    => $post_type,
                "post_status"  => $status,
                "post_content" => "Directory listing body for {$title}.",
                "post_excerpt" => "Directory listing excerpt for {$title}.",
            ]
        );

        $this->assertIsInt( $post_id );
        $this->assertGreaterThan( 0, $post_id );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        $this->created_post_ids[] = $post_id;

        return $post_id;
    }

    protected function assign_terms( int $post_id, string $taxonomy, array $term_ids ): void {
        wp_set_object_terms( $post_id, array_map( "intval", $term_ids ), $taxonomy );
    }

    /**
     * The listing ids a listings request returns, in order.
     *
     * @param array $params Request params besides the integration.
     * @return int[]
     */
    protected function listing_ids_for( string $integration, array $params ): array {
        $paginator = apply_filters(
            "craf_appna_directory_{$integration}_listings",
            null,
            $this->create_request( array_merge( ["integration" => $integration], $params ) ),
            $this->listing_fields()
        );

        return array_map( fn( $listing ): int => $listing->get_id(), $paginator->get_items() );
    }

    protected function create_request( array $params = [] ): Request {
        $wp_request = new \WP_REST_Request();

        foreach ( $params as $key => $value ) {
            $wp_request->set_param( $key, $value );
        }

        return new Request( $wp_request );
    }

    protected function listing_fields(): array {
        return [
            "id",
            "title",
            "slug",
            "description",
            "excerpt",
            "status",
            "views_count",
            "address",
            "latitude",
            "longitude",
            "phone",
            "email",
            "website",
            "favorite",
            "featured",
            "new",
            "popular",
            "pricing",
            "categories",
            "locations",
            "tags",
            "rating",
        ];
    }

    protected function category_fields(): array {
        return ["id", "name", "slug", "description", "parent", "count", "image"];
    }

    protected function term_fields(): array {
        return ["id", "name", "slug", "count", "image"];
    }

    protected function assert_provider_filter_surface( string $integration ): void {
        foreach ( ["listings", "listings_filters", "listings_filter_sources", "listing", "related_listings", "reviews", "categories", "category", "tags", "locations", "location"] as $operation ) {
            $this->assertNotFalse(
                has_filter( "craf_appna_directory_{$integration}_{$operation}" ),
                "Missing directory filter for {$integration}: {$operation}"
            );
        }
    }

    protected function assert_listing_collection( string $integration, int $listing_id, int $category_id ): void {
        $request = $this->create_request(
            [
                "page"        => 1,
                "per_page"    => 10,
                "categories"  => [$category_id],
                "integration" => $integration,
            ]
        );

        $paginator = apply_filters( "craf_appna_directory_{$integration}_listings", null, $request, $this->listing_fields() );

        $this->assertInstanceOf( ListingPaginatorDTO::class, $paginator );
        $this->assertGreaterThanOrEqual( 1, $paginator->get_total() );
        $this->assertSame( $listing_id, $paginator->get_items()[0]->get_id() );
    }

    /**
     * The featured listing is listed by `featured`, counted by the `status` facet and listed first.
     */
    protected function assert_featured_listing( string $integration, int $listing_id ): void {
        $featured = apply_filters(
            "craf_appna_directory_{$integration}_listings",
            null,
            $this->create_request( [ "featured" => "1", "integration" => $integration ] ),
            $this->listing_fields()
        );
        $this->assertInstanceOf( ListingPaginatorDTO::class, $featured );
        $this->assertSame( [ $listing_id ], array_map( fn( $listing ) => $listing->get_id(), $featured->get_items() ) );

        $filters = apply_filters(
            "craf_appna_directory_{$integration}_listings_filters",
            null,
            $this->create_request( [ "facets" => [ "status" ], "integration" => $integration ] )
        );
        $this->assertInstanceOf( FiltersDTO::class, $filters );
        $status = $filters->get_facets()[0];
        $this->assertSame( "status", $status->get_id() );
        $this->assertSame( "featured", $status->get_options()[0]->get_value() );
        $this->assertSame( 1, $status->get_options()[0]->get_count() );

        $relevance = apply_filters( "craf_appna_directory_{$integration}_listings", null, $this->create_request( [ "integration" => $integration ] ), $this->listing_fields() );
        $this->assertSame( $listing_id, $relevance->get_items()[0]->get_id() );
    }

    protected function assert_single_listing( string $integration, int $listing_id, string $title ): void {
        $request = $this->create_request(
            [
                "id"          => $listing_id,
                "integration" => $integration,
            ]
        );

        $listing = apply_filters( "craf_appna_directory_{$integration}_listing", null, $request, $this->listing_fields() );

        $this->assertInstanceOf( ListingDTO::class, $listing );
        $this->assertSame( $listing_id, $listing->get_id() );
        $this->assertSame( $title, $listing->get_title() );
    }

    protected function assert_related_listings( string $integration, int $source_id, int $related_id ): void {
        $request = $this->create_request(
            [
                "id"          => $source_id,
                "page"        => 1,
                "per_page"    => 10,
                "integration" => $integration,
            ]
        );

        $paginator = apply_filters( "craf_appna_directory_{$integration}_related_listings", null, $request, $this->listing_fields() );
        $ids       = array_map(
            fn( ListingDTO $listing ): int => $listing->get_id(),
            $paginator->get_items()
        );

        $this->assertInstanceOf( ListingPaginatorDTO::class, $paginator );
        $this->assertContains( $related_id, $ids );
        $this->assertNotContains( $source_id, $ids );
    }

    protected function assert_categories( string $integration, int $category_id ): void {
        $request = $this->create_request(
            [
                "page"        => 1,
                "per_page"    => 10,
                "integration" => $integration,
            ]
        );

        $paginator = apply_filters( "craf_appna_directory_{$integration}_categories", null, $request, $this->category_fields() );
        $ids       = array_map(
            fn( CategoryDTO $category ): int => $category->get_id(),
            $paginator->get_items()
        );

        $this->assertInstanceOf( CategoryPaginatorDTO::class, $paginator );
        $this->assertContains( $category_id, $ids );
    }

    protected function assert_tag_collection( string $integration ): void {
        $request = $this->create_request(
            [
                "page"        => 1,
                "per_page"    => 10,
                "integration" => $integration,
            ]
        );

        $paginator = apply_filters( "craf_appna_directory_{$integration}_tags", null, $request, $this->term_fields() );

        $this->assertInstanceOf( TermPaginatorDTO::class, $paginator );
    }

    /**
     * The locations endpoint answers with a term paginator, and lists the expected location
     * when one is given. Pass no id for an integration whose locations are optional.
     */
    protected function assert_location_collection( string $integration, int $expected_id = 0, string $expected_slug = "" ): void {
        $request = $this->create_request(
            [
                "page"        => 1,
                "per_page"    => 100,
                "integration" => $integration,
            ]
        );

        $paginator = apply_filters( "craf_appna_directory_{$integration}_locations", null, $request, $this->term_fields() );

        $this->assertInstanceOf( TermPaginatorDTO::class, $paginator );

        if ( $expected_id > 0 ) {
            $items = [];
            foreach ( $paginator->get_items() as $item ) {
                $items[$item->get_id()] = $item;
            }

            $this->assertArrayHasKey( $expected_id, $items );

            if ( "" !== $expected_slug ) {
                $this->assertSame( $expected_slug, $items[$expected_id]->get_slug() );
            }
        }
    }

    protected function assert_single_location( string $integration, int $location_id, string $name ): void {
        $request = $this->create_request(
            [
                "id"          => $location_id,
                "integration" => $integration,
            ]
        );

        $location = apply_filters( "craf_appna_directory_{$integration}_location", null, $request, $this->term_fields() );

        $this->assertInstanceOf( TermDTO::class, $location );
        $this->assertSame( $location_id, $location->get_id() );
        $this->assertSame( $name, $location->get_name() );
    }

    protected function assert_review_payload( string $integration, int $listing_id ): void {
        $request = $this->create_request(
            [
                "id"          => $listing_id,
                "page"        => 1,
                "per_page"    => 10,
                "integration" => $integration,
            ]
        );

        $reviews = apply_filters( "craf_appna_directory_{$integration}_reviews", null, $request );

        $this->assertIsArray( $reviews );
        foreach ( ["current_page", "per_page", "total", "last_page", "average_rating", "review_count", "rating_counts", "items"] as $key ) {
            $this->assertArrayHasKey( $key, $reviews );
        }
    }

    public function tearDown(): void {
        foreach ( $this->created_post_ids as $post_id ) {
            wp_delete_post( $post_id, true );
        }

        foreach ( $this->created_terms as $term ) {
            if ( ! empty( $term["id"] ) && ! empty( $term["taxonomy"] ) ) {
                wp_delete_term( (int) $term["id"], (string) $term["taxonomy"] );
            }
        }

        parent::tearDown();
    }
}
