<?php

namespace Crafium\AppNatively\Tests\Integrations;

require_once __DIR__ . "/DirectoryIntegrationTestCase.php";

class GeoDirectoryTest extends DirectoryIntegrationTestCase {
    private string $integration = "geodirectory";

    private string $post_type = "gd_place";

    private string $category_taxonomy = "gd_placecategory";

    private string $tag_taxonomy = "gd_place_tags";

    /** @var int[] Location Manager rows this test inserted. */
    private array $created_location_ids = [];

    public function setUp(): void {
        parent::setUp();

        if ( function_exists( "geodir_get_posttypes" ) ) {
            $post_types = geodir_get_posttypes( "array" );
            if ( is_array( $post_types ) && ! empty( $post_types ) ) {
                $this->post_type = (string) array_key_first( $post_types );
            }
        }

        $this->category_taxonomy = $this->post_type . "category";
        $this->tag_taxonomy      = $this->post_type . "_tags";

        $this->ensure_post_type( $this->post_type );
        $this->ensure_taxonomy( $this->category_taxonomy, $this->post_type );
        $this->ensure_taxonomy( $this->tag_taxonomy, $this->post_type, false );
    }

    public function test_geodirectory_adapter_uses_real_directory_data(): void {
        $category_id = $this->create_term( $this->category_taxonomy, "GeoDirectory Category" );
        $tag_id      = $this->create_term( $this->tag_taxonomy, "geodirectory-tag" );
        $source_id   = $this->create_listing( $this->post_type, "GeoDirectory Source" );
        $related_id  = $this->create_listing( $this->post_type, "GeoDirectory Related" );

        $this->assign_terms( $source_id, $this->category_taxonomy, [$category_id] );
        $this->assign_terms( $source_id, $this->tag_taxonomy, [$tag_id] );
        $this->assign_terms( $related_id, $this->category_taxonomy, [$category_id] );

        $comment_id = wp_insert_comment(
            [
                "comment_post_ID"      => $source_id,
                "comment_author"       => "Geo Reviewer",
                "comment_author_email" => "geo-reviewer@example.com",
                "comment_content"      => "Geo review.",
                "comment_approved"     => 1,
            ]
        );
        $this->create_geodirectory_review_rating( $source_id, (int) $comment_id, 4.0 );

        $this->assert_provider_filter_surface( $this->integration );
        $this->assert_listing_collection( $this->integration, $source_id, $category_id );
        $this->assert_single_listing( $this->integration, $source_id, "GeoDirectory Source" );
        $this->assert_related_listings( $this->integration, $source_id, $related_id );
        $this->assert_categories( $this->integration, $category_id );
        $this->assert_tag_collection( $this->integration );
        $this->assert_review_payload( $this->integration, $source_id );
    }

    public function test_geodirectory_locations_use_the_location_manager_tables(): void {
        $location_id = $this->create_geodirectory_city( "Dhaka", "Dhaka Division", "Bangladesh" );

        if ( 0 === $location_id ) {
            $this->markTestSkipped( "GeoDirectory Location Manager is not active." );
        }

        $in_city_id = $this->create_listing( $this->post_type, "GeoDirectory In Dhaka" );
        $elsewhere  = $this->create_listing( $this->post_type, "GeoDirectory Nowhere" );

        if ( ! $this->set_geodirectory_listing_city( $in_city_id, "Dhaka", "Dhaka Division", "Bangladesh" ) ) {
            $this->markTestSkipped( "GeoDirectory did not store listing detail rows." );
        }
        $this->set_geodirectory_listing_city( $elsewhere, "", "", "" );

        // The locations endpoints.
        $this->assert_location_collection( $this->integration, $location_id, "dhaka" );
        $this->assert_single_location( $this->integration, $location_id, "Dhaka" );

        $locations = apply_filters(
            "craf_appna_directory_{$this->integration}_locations",
            null,
            $this->create_request( ["page" => 1, "per_page" => 100, "integration" => $this->integration] ),
            $this->term_fields()
        );
        $this->assertSame( 1, $locations->get_items()[0]->get_count() );

        // The page context narrows by location id.
        $this->assertSame( [$in_city_id], $this->listing_ids_for( $this->integration, ["locations" => [$location_id]] ) );

        // The filter facet offers the city by slug, with its count.
        $filters = apply_filters(
            "craf_appna_directory_{$this->integration}_listings_filters",
            null,
            $this->create_request( ["facets" => ["location"], "integration" => $this->integration] )
        );
        $facet = $filters->get_facets()[0];
        $this->assertSame( "location", $facet->get_id() );
        $this->assertSame( "Location", $facet->get_label() );
        $this->assertCount( 1, $facet->get_options() );
        $this->assertSame( "dhaka", $facet->get_options()[0]->get_value() );
        $this->assertSame( "Dhaka", $facet->get_options()[0]->get_label() );
        $this->assertSame( 1, $facet->get_options()[0]->get_count() );

        // A selection narrows by that same slug.
        $this->assertSame( [$in_city_id], $this->listing_ids_for( $this->integration, ["values" => ["location" => ["dhaka"]]] ) );

        // And the listing carries its city.
        $listing = apply_filters(
            "craf_appna_directory_{$this->integration}_listing",
            null,
            $this->create_request( ["id" => $in_city_id, "integration" => $this->integration] ),
            $this->listing_fields()
        );
        $this->assertSame(
            [["id" => $location_id, "name" => "Dhaka", "slug" => "dhaka"]],
            $listing->get_locations()
        );
    }

    /**
     * Cities sharing a slug are one filter option whose counts add up, and one selection
     * matches them all, while the locations list keeps them apart.
     */
    public function test_geodirectory_merges_cities_that_share_a_slug(): void {
        $first  = $this->create_geodirectory_city( "Springfield", "Illinois", "United States" );
        $second = $this->create_geodirectory_city( "Springfield", "Missouri", "United States" );

        if ( 0 === $first || 0 === $second ) {
            $this->markTestSkipped( "GeoDirectory Location Manager is not active." );
        }

        $illinois_id = $this->create_listing( $this->post_type, "GeoDirectory Springfield IL" );
        $missouri_id = $this->create_listing( $this->post_type, "GeoDirectory Springfield MO" );

        if ( ! $this->set_geodirectory_listing_city( $illinois_id, "Springfield", "Illinois", "United States" ) ) {
            $this->markTestSkipped( "GeoDirectory did not store listing detail rows." );
        }
        $this->set_geodirectory_listing_city( $missouri_id, "Springfield", "Missouri", "United States" );

        $filters = apply_filters(
            "craf_appna_directory_{$this->integration}_listings_filters",
            null,
            $this->create_request( ["facets" => ["location"], "integration" => $this->integration] )
        );
        $options = $filters->get_facets()[0]->get_options();

        $this->assertCount( 1, $options );
        $this->assertSame( "springfield", $options[0]->get_value() );
        $this->assertSame( 2, $options[0]->get_count() );

        $this->assertSame(
            [$missouri_id, $illinois_id],
            $this->listing_ids_for( $this->integration, ["values" => ["location" => ["springfield"]]] )
        );

        // Each city stays addressable on its own.
        $this->assert_location_collection( $this->integration, $first, "springfield" );
        $this->assert_location_collection( $this->integration, $second, "springfield" );
        $this->assertSame( [$illinois_id], $this->listing_ids_for( $this->integration, ["locations" => [$first]] ) );
    }

    /**
     * A city's image comes from the location SEO table, preferring the row that names the
     * same country and region.
     */
    public function test_geodirectory_location_image_comes_from_the_seo_table(): void {
        global $wpdb;

        $location_id = $this->create_geodirectory_city( "Kyoto", "Kansai", "Japan" );

        if ( 0 === $location_id || ! defined( "GEODIR_LOCATION_SEO_TABLE" ) ) {
            $this->markTestSkipped( "GeoDirectory Location Manager is not active." );
        }

        $attachment_id = $this->factory->attachment->create_object(
            [
                "file"           => "kyoto.jpg",
                "post_mime_type" => "image/jpeg",
            ]
        );

        // A slug-only row first, then the one naming this city's country and region: the
        // exact match must win however they are ordered by seo_id.
        $wpdb->insert(
            GEODIR_LOCATION_SEO_TABLE,
            ["location_type" => "city", "country_slug" => "elsewhere", "region_slug" => "elsewhere", "city_slug" => "kyoto", "meta_title" => "", "meta_desc" => "", "image" => "0", "image_tagline" => "", "location_desc" => "", "cpt_desc" => ""],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]
        );
        $wpdb->insert(
            GEODIR_LOCATION_SEO_TABLE,
            ["location_type" => "city", "country_slug" => "japan", "region_slug" => "kansai", "city_slug" => "kyoto", "meta_title" => "", "meta_desc" => "", "image" => (string) $attachment_id, "image_tagline" => "", "location_desc" => "", "cpt_desc" => ""],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]
        );

        $location = apply_filters(
            "craf_appna_directory_{$this->integration}_location",
            null,
            $this->create_request( ["id" => $location_id, "integration" => $this->integration] ),
            $this->term_fields()
        );

        $this->assertSame( (int) $attachment_id, $location->get_image()["id"] );
        $this->assertSame( wp_get_attachment_url( $attachment_id ), $location->get_image()["src"] );

        $wpdb->delete( GEODIR_LOCATION_SEO_TABLE, ["city_slug" => "kyoto"], ["%s"] );
    }

    public function test_geodirectory_location_without_an_image_takes_one_from_its_listings(): void {
        global $wpdb;

        $location_id = $this->create_geodirectory_city( "Osaka", "Kansai", "Japan" );

        if ( 0 === $location_id ) {
            $this->markTestSkipped( "GeoDirectory Location Manager is not active." );
        }

        $listing_id = $this->create_listing( $this->post_type, "Osaka Listing" );
        if ( ! $this->set_geodirectory_listing_city( $listing_id, "Osaka", "Kansai", "Japan" ) ) {
            $this->markTestSkipped( "GeoDirectory did not create a detail row for the listing." );
        }

        $attachment_id = $this->factory->attachment->create_object(
            [
                "file"           => "osaka.jpg",
                "post_mime_type" => "image/jpeg",
            ]
        );
        set_post_thumbnail( $listing_id, $attachment_id );

        $location = apply_filters(
            "craf_appna_directory_{$this->integration}_location",
            null,
            $this->create_request( ["id" => $location_id, "integration" => $this->integration] ),
            $this->term_fields()
        );

        // No row of its own in the SEO table, so the city borrows its listing's image.
        $this->assertSame( (int) $attachment_id, $location->get_image()["id"] );
        $this->assertSame( wp_get_attachment_url( $attachment_id ), $location->get_image()["src"] );

        if ( ! defined( "GEODIR_LOCATION_SEO_TABLE" ) ) {
            return;
        }

        $own_image_id = $this->factory->attachment->create_object(
            [
                "file"           => "osaka-city.jpg",
                "post_mime_type" => "image/jpeg",
            ]
        );
        $wpdb->insert(
            GEODIR_LOCATION_SEO_TABLE,
            ["location_type" => "city", "country_slug" => "japan", "region_slug" => "kansai", "city_slug" => "osaka", "meta_title" => "", "meta_desc" => "", "image" => (string) $own_image_id, "image_tagline" => "", "location_desc" => "", "cpt_desc" => ""],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]
        );

        $located = apply_filters(
            "craf_appna_directory_{$this->integration}_location",
            null,
            $this->create_request( ["id" => $location_id, "integration" => $this->integration] ),
            $this->term_fields()
        );

        // Its own image wins once it has one.
        $this->assertSame( (int) $own_image_id, $located->get_image()["id"] );

        $wpdb->delete( GEODIR_LOCATION_SEO_TABLE, ["city_slug" => "osaka"], ["%s"] );
    }

    public function test_geodirectory_locations_are_empty_without_the_location_manager(): void {
        if ( defined( "GEODIR_LOCATIONS_TABLE" ) ) {
            $this->markTestSkipped( "GeoDirectory Location Manager is active." );
        }

        $locations = apply_filters(
            "craf_appna_directory_{$this->integration}_locations",
            null,
            $this->create_request( ["page" => 1, "per_page" => 10, "integration" => $this->integration] ),
            $this->term_fields()
        );

        $this->assertSame( 0, $locations->get_total() );
        $this->assertSame( [], $locations->get_items() );
        $this->assertNull(
            apply_filters(
                "craf_appna_directory_{$this->integration}_location",
                null,
                $this->create_request( ["id" => 1, "integration" => $this->integration] ),
                $this->term_fields()
            )
        );
    }

    public function test_geodirectory_reviews_read_ratings_from_review_table(): void {
        $source_id  = $this->create_listing( $this->post_type, "GeoDirectory Rated Source" );
        $comment_id = wp_insert_comment(
            [
                "comment_post_ID"      => $source_id,
                "comment_author"       => "Geo Reviewer",
                "comment_author_email" => "geo-reviewer@example.com",
                "comment_content"      => "Geo rated review.",
                "comment_approved"     => 1,
            ]
        );

        $this->create_geodirectory_review_rating( $source_id, (int) $comment_id, 5.0 );

        $reviews = apply_filters(
            "craf_appna_directory_{$this->integration}_reviews",
            null,
            $this->create_request(
                [
                    "id"          => $source_id,
                    "page"        => 1,
                    "per_page"    => 10,
                    "integration" => $this->integration,
                ]
            )
        );

        $this->assertIsArray( $reviews );
        $this->assertSame( 5.0, $reviews["items"][0]["rating"] );
        $this->assertSame( 1, $reviews["rating_counts"]["5"] );
    }

    public function test_geodirectory_reviews_filter_by_exact_rating_and_keep_listing_wide_counts(): void {
        $source_id = $this->create_listing( $this->post_type, "GeoDirectory Filtered Reviews" );

        $this->create_review( $source_id, 5.0, "Excellent geo review", "2024-01-01 10:00:00" );
        $this->create_review( $source_id, 5.0, "Another excellent geo review", "2024-01-02 10:00:00" );
        $this->create_review( $source_id, 3.0, "Average geo review", "2024-01-03 10:00:00" );

        $reviews = apply_filters(
            "craf_appna_directory_{$this->integration}_reviews",
            null,
            $this->create_request(
                [
                    "id"          => $source_id,
                    "rating"      => 5,
                    "page"        => 1,
                    "per_page"    => 10,
                    "integration" => $this->integration,
                ]
            )
        );

        $this->assertIsArray( $reviews );
        $this->assertSame( 2, $reviews["total"] );
        $this->assertCount( 2, $reviews["items"] );
        $this->assertSame( [5.0, 5.0], array_column( $reviews["items"], "rating" ) );
        $this->assertSame( 3, $reviews["review_count"] );
        $this->assertSame( 2, $reviews["rating_counts"]["5"] );
        $this->assertSame( 1, $reviews["rating_counts"]["3"] );
    }

    public function test_geodirectory_reviews_support_newest_and_rating_sort_orders(): void {
        $source_id = $this->create_listing( $this->post_type, "GeoDirectory Sorted Reviews" );

        $this->create_review( $source_id, 1.0, "Old low geo review", "2024-01-01 10:00:00" );
        $this->create_review( $source_id, 5.0, "Middle high geo review", "2024-01-02 10:00:00" );
        $this->create_review( $source_id, 3.0, "Newest medium geo review", "2024-01-03 10:00:00" );

        $base_params = [
            "id"          => $source_id,
            "page"        => 1,
            "per_page"    => 10,
            "integration" => $this->integration,
        ];

        $newest  = apply_filters(
            "craf_appna_directory_{$this->integration}_reviews",
            null,
            $this->create_request( array_merge( $base_params, ["orderby" => "newest"] ) )
        );
        $highest = apply_filters(
            "craf_appna_directory_{$this->integration}_reviews",
            null,
            $this->create_request( array_merge( $base_params, ["orderby" => "rating_desc"] ) )
        );
        $lowest  = apply_filters(
            "craf_appna_directory_{$this->integration}_reviews",
            null,
            $this->create_request( array_merge( $base_params, ["orderby" => "rating_asc"] ) )
        );

        $this->assertSame( ["Newest medium geo review", "Middle high geo review", "Old low geo review"], array_column( $newest["items"], "review" ) );
        $this->assertSame( [5.0, 3.0, 1.0], array_column( $highest["items"], "rating" ) );
        $this->assertSame( [1.0, 3.0, 5.0], array_column( $lowest["items"], "rating" ) );
    }

    /**
     * Insert a Location Manager city.
     *
     * @return int Its location id, or 0 when the add-on is inactive.
     */
    private function create_geodirectory_city( string $city, string $region, string $country ): int {
        global $wpdb;

        if ( ! defined( "GEODIR_LOCATIONS_TABLE" ) ) {
            return 0;
        }

        $wpdb->insert(
            GEODIR_LOCATIONS_TABLE,
            [
                "country"      => $country,
                "region"       => $region,
                "city"         => $city,
                "country_slug" => sanitize_title( $country ),
                "region_slug"  => sanitize_title( $region ),
                "city_slug"    => sanitize_title( $city ),
                "latitude"     => "23.8103",
                "longitude"    => "90.4125",
                "is_default"   => 0,
            ],
            ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d"]
        );

        $location_id                  = (int) $wpdb->insert_id;
        $this->created_location_ids[] = $location_id;

        return $location_id;
    }

    /**
     * Put a listing in a city, creating its detail row when GeoDirectory's own save hooks
     * did not.
     *
     * @return bool Whether the listing now has a detail row.
     */
    private function set_geodirectory_listing_city( int $post_id, string $city, string $region, string $country ): bool {
        global $wpdb;

        $table = $wpdb->prefix . "geodir_" . $this->post_type . "_detail";

        //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE post_id = %d", $post_id ) ) ) {
            return false !== $wpdb->update( $table, ["city" => $city, "region" => $region, "country" => $country], ["post_id" => $post_id], ["%s", "%s", "%s"], ["%d"] );
        }

        return false !== $wpdb->insert(
            $table,
            [
                "post_id"     => $post_id,
                "post_title"  => get_the_title( $post_id ),
                "post_status" => "publish",
                "city"        => $city,
                "region"      => $region,
                "country"     => $country,
            ],
            ["%d", "%s", "%s", "%s", "%s", "%s"]
        );
    }

    public function tearDown(): void {
        global $wpdb;

        if ( defined( "GEODIR_LOCATIONS_TABLE" ) ) {
            foreach ( $this->created_location_ids as $location_id ) {
                $wpdb->delete( GEODIR_LOCATIONS_TABLE, ["location_id" => $location_id], ["%d"] );
            }
        }

        $this->created_location_ids = [];

        parent::tearDown();
    }

    private function create_review( int $post_id, float $rating, string $content, string $date ): int {
        $comment_id = wp_insert_comment(
            [
                "comment_post_ID"      => $post_id,
                "comment_author"       => "Geo Reviewer {$rating}",
                "comment_author_email" => "geo-reviewer-" . md5( $content ) . "@example.com",
                "comment_content"      => $content,
                "comment_approved"     => 1,
                "comment_date"         => $date,
                "comment_date_gmt"     => get_gmt_from_date( $date ),
            ]
        );

        update_comment_meta( $comment_id, "rating", $rating );
        update_comment_meta( $comment_id, "_rating", $rating );
        update_comment_meta( $comment_id, "geodir_overallrating", $rating );
        $this->create_geodirectory_review_rating( $post_id, (int) $comment_id, $rating );

        return (int) $comment_id;
    }

    private function create_geodirectory_review_rating( int $post_id, int $comment_id, float $rating ): void {
        global $wpdb;

        update_comment_meta( $comment_id, "rating", $rating );

        if ( ! defined( "GEODIR_REVIEW_TABLE" ) || ! $comment_id ) {
            return;
        }

        $wpdb->replace(
            GEODIR_REVIEW_TABLE,
            [
                "comment_id"  => $comment_id,
                "post_id"     => $post_id,
                "user_id"     => 0,
                "rating"      => $rating,
                "ratings"     => "",
                "attachments" => "",
                "post_type"   => $this->post_type,
                "city"        => "",
                "region"      => "",
                "country"     => "",
                "latitude"    => "",
                "longitude"   => "",
            ],
            ["%d", "%d", "%d", "%f", "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s"]
        );
    }
}
