<?php

namespace Crafium\AppNatively\Tests\Integrations;

require_once __DIR__ . "/DirectoryIntegrationTestCase.php";

class ClassifiedListingTest extends DirectoryIntegrationTestCase {
    private string $integration = "classified-listing";

    private string $post_type = "rtcl_listing";

    private string $category_taxonomy = "rtcl_category";

    private string $tag_taxonomy = "rtcl_tag";

    private string $location_taxonomy = "rtcl_location";

    public function setUp(): void {
        parent::setUp();

        if ( function_exists( "rtcl" ) && is_object( rtcl() ) ) {
            $this->post_type         = isset( rtcl()->post_type ) ? (string) rtcl()->post_type : $this->post_type;
            $this->category_taxonomy = isset( rtcl()->category ) ? (string) rtcl()->category : $this->category_taxonomy;
            $this->tag_taxonomy      = isset( rtcl()->tag ) ? (string) rtcl()->tag : $this->tag_taxonomy;
            $this->location_taxonomy = isset( rtcl()->location ) ? (string) rtcl()->location : $this->location_taxonomy;
        }

        $this->ensure_post_type( $this->post_type );
        $this->ensure_taxonomy( $this->category_taxonomy, $this->post_type );
        $this->ensure_taxonomy( $this->tag_taxonomy, $this->post_type, false );
        $this->ensure_taxonomy( $this->location_taxonomy, $this->post_type );
    }

    public function test_classified_listing_adapter_uses_real_directory_data(): void {
        $category_id = $this->create_term( $this->category_taxonomy, "Classified Listing Category" );
        $tag_id      = $this->create_term( $this->tag_taxonomy, "classified-listing-tag" );
        $location_id = $this->create_term( $this->location_taxonomy, "Classified Listing Location" );
        $source_id   = $this->create_listing(
            $this->post_type,
            "Classified Listing Source",
            "publish",
            [
                "address"     => "Classified Listing address",
                "phone"       => "123",
                "email"       => "classified-listing@example.com",
                "website"     => "https://example.com",
                "featured"    => "1",
                "price"       => "400",
                "latitude"    => "23.7808875",
                "longitude"   => "90.2792371",
                "price_type"  => "fixed",
                "price_range" => "",
            ]
        );
        $related_id  = $this->create_listing( $this->post_type, "Classified Listing Related" );

        $this->assign_terms( $source_id, $this->category_taxonomy, [$category_id] );
        $this->assign_terms( $source_id, $this->tag_taxonomy, [$tag_id] );
        $this->assign_terms( $source_id, $this->location_taxonomy, [$location_id] );
        $this->assign_terms( $related_id, $this->category_taxonomy, [$category_id] );

        wp_insert_comment(
            [
                "comment_post_ID"      => $source_id,
                "comment_author"       => "Classified Reviewer",
                "comment_author_email" => "classified-reviewer@example.com",
                "comment_content"      => "Classified review.",
                "comment_approved"     => 1,
            ]
        );

        $this->assert_provider_filter_surface( $this->integration );
        $this->assert_listing_collection( $this->integration, $source_id, $category_id );
        $this->assert_featured_listing( $this->integration, $source_id );
        $this->assert_single_listing( $this->integration, $source_id, "Classified Listing Source" );
        $this->assert_related_listings( $this->integration, $source_id, $related_id );
        $this->assert_categories( $this->integration, $category_id );
        $this->assert_tag_collection( $this->integration );
        $this->assert_location_collection( $this->integration, $location_id, get_term_field( "slug", $location_id, $this->location_taxonomy ) );
        $this->assert_single_location( $this->integration, $location_id, "Classified Listing Location" );
        $this->assert_review_payload( $this->integration, $source_id );
    }
}
