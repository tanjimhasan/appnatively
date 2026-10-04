<?php

namespace Crafium\AppNatively\Tests\Integrations;

require_once __DIR__ . "/DirectoryIntegrationTestCase.php";

class HivePressTest extends DirectoryIntegrationTestCase {
    private string $integration = "hivepress";

    private string $post_type = "hp_listing";

    private string $category_taxonomy = "hp_listing_category";

    private string $location_taxonomy = "hp_listing_region";

    public function setUp(): void {
        parent::setUp();

        $this->ensure_post_type( $this->post_type );
        $this->ensure_taxonomy( $this->category_taxonomy, $this->post_type );
    }

    public function test_hivepress_adapter_uses_real_directory_data(): void {
        $category_id = $this->create_term( $this->category_taxonomy, "HivePress Category" );
        $source_id   = $this->create_listing(
            $this->post_type,
            "HivePress Source",
            "publish",
            [
                "hp_address"   => "HivePress address",
                "hp_phone"     => "123",
                "hp_email"     => "hivepress@example.com",
                "hp_website"   => "https://example.com",
                "hp_featured"  => "1",
                "hp_price"     => "200",
                "hp_latitude"  => "23.7808875",
                "hp_longitude" => "90.2792371",
            ]
        );
        $related_id  = $this->create_listing( $this->post_type, "HivePress Related" );

        $this->assign_terms( $source_id, $this->category_taxonomy, [$category_id] );
        $this->assign_terms( $related_id, $this->category_taxonomy, [$category_id] );

        $this->assert_provider_filter_surface( $this->integration );
        $this->assert_listing_collection( $this->integration, $source_id, $category_id );
        $this->assert_featured_listing( $this->integration, $source_id );
        $this->assert_single_listing( $this->integration, $source_id, "HivePress Source" );
        $this->assert_related_listings( $this->integration, $source_id, $related_id );
        $this->assert_categories( $this->integration, $category_id );
        $this->assert_tag_collection( $this->integration );
        $this->assert_review_payload( $this->integration, $source_id );
    }

    /**
     * HivePress keeps its locations in the Geolocation extension's `hp_listing_region`
     * taxonomy: a tree of country > region > district > place of which a listing holds
     * only the deepest term, so both the list and the facet have to count a parent
     * together with its children.
     */
    public function test_hivepress_locations_use_the_geolocation_regions(): void {
        if ( ! taxonomy_exists( $this->location_taxonomy ) ) {
            $this->markTestSkipped( "The HivePress Geolocation extension is not active." );
        }

        $country_id = $this->create_term( $this->location_taxonomy, "United States" );
        $region_id  = $this->create_term( $this->location_taxonomy, "Austin", $country_id );

        $in_region = $this->create_listing( $this->post_type, "HivePress In Austin" );
        $elsewhere = $this->create_listing( $this->post_type, "HivePress Nowhere" );

        // Only the deepest region, exactly as Geolocation's update_location() stores it.
        $this->assign_terms( $in_region, $this->location_taxonomy, [$region_id] );

        // The locations endpoints.
        $this->assert_location_collection( $this->integration, $region_id, "austin" );
        $this->assert_single_location( $this->integration, $region_id, "Austin" );

        $locations = apply_filters(
            "craf_appna_directory_{$this->integration}_locations",
            null,
            $this->create_request( ["page" => 1, "per_page" => 100, "integration" => $this->integration] ),
            $this->term_fields()
        );
        $counts = [];
        foreach ( $locations->get_items() as $item ) {
            $counts[$item->get_id()] = $item->get_count();
        }
        $this->assertSame( 1, $counts[$region_id] );
        // The country holds no listing of its own and still counts the one in Austin.
        $this->assertSame( 1, $counts[$country_id] );

        // The page context narrows by a parent region, through its children.
        $this->assertSame( [$in_region], $this->listing_ids_for( $this->integration, ["locations" => [$country_id]] ) );

        // The facet keeps HivePress's own word for a location, and counts both levels.
        $filters = apply_filters(
            "craf_appna_directory_{$this->integration}_listings_filters",
            null,
            $this->create_request( ["facets" => ["location"], "integration" => $this->integration] )
        );
        $facet = $filters->get_facets()[0];
        $this->assertSame( "location", $facet->get_id() );
        $this->assertSame( "Region", $facet->get_label() );

        $options = [];
        foreach ( $facet->get_options() as $option ) {
            $options[$option->get_value()] = $option->get_count();
        }
        $this->assertSame( ["austin" => 1, "united-states" => 1], $options );

        // A selection narrows by that same slug.
        $this->assertSame( [$in_region], $this->listing_ids_for( $this->integration, ["values" => ["location" => ["united-states"]]] ) );

        // The listing carries its own region only, never an ancestor.
        $listing = apply_filters(
            "craf_appna_directory_{$this->integration}_listing",
            null,
            $this->create_request( ["id" => $in_region, "integration" => $this->integration] ),
            $this->listing_fields()
        );
        $this->assertSame( [["id" => $region_id, "name" => "Austin", "slug" => "austin"]], $listing->get_locations() );

        $elsewhere_listing = apply_filters(
            "craf_appna_directory_{$this->integration}_listing",
            null,
            $this->create_request( ["id" => $elsewhere, "integration" => $this->integration] ),
            $this->listing_fields()
        );
        $this->assertSame( [], $elsewhere_listing->get_locations() );

        // And the taxonomy behind the location facet is not offered a second time as a
        // `taxonomy:` filter row, which is where HivePress's `region` attribute put it.
        $sources = apply_filters(
            "craf_appna_directory_{$this->integration}_listings_filter_sources",
            [],
            $this->create_request( ["integration" => $this->integration] )
        );
        foreach ( (array) $sources as $source ) {
            $this->assertNotSame(
                "taxonomy:{$this->location_taxonomy}",
                "{$source->get_source()}:{$source->get_key()}",
                "The location taxonomy is also offered as a taxonomy filter row."
            );
        }
    }
}
