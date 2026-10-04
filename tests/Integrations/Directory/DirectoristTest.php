<?php

namespace Crafium\AppNatively\Tests\Integrations;

require_once __DIR__ . "/DirectoryIntegrationTestCase.php";

class DirectoristTest extends DirectoryIntegrationTestCase {
    private string $integration = "directorist";

    private string $post_type = "at_biz_dir";

    private string $category_taxonomy = "at_biz_dir-category";

    private string $tag_taxonomy = "at_biz_dir-tags";

    private string $location_taxonomy = "at_biz_dir-location";

    public function setUp(): void {
        parent::setUp();

        $this->post_type         = defined( "ATBDP_POST_TYPE" ) ? ATBDP_POST_TYPE : $this->post_type;
        $this->category_taxonomy = defined( "ATBDP_CATEGORY" ) ? ATBDP_CATEGORY : $this->category_taxonomy;
        $this->tag_taxonomy      = defined( "ATBDP_TAGS" ) ? ATBDP_TAGS : $this->tag_taxonomy;
        $this->location_taxonomy = defined( "ATBDP_LOCATION" ) ? ATBDP_LOCATION : $this->location_taxonomy;

        $this->ensure_post_type( $this->post_type );
        $this->ensure_taxonomy( $this->category_taxonomy, $this->post_type );
        $this->ensure_taxonomy( $this->tag_taxonomy, $this->post_type, false );
        $this->ensure_taxonomy( $this->location_taxonomy, $this->post_type );
    }

    public function test_directorist_adapter_uses_real_directory_data(): void {
        $category_id = $this->create_term( $this->category_taxonomy, "Directorist Category" );
        $tag_id      = $this->create_term( $this->tag_taxonomy, "directorist-tag" );
        $location_id = $this->create_term( $this->location_taxonomy, "Directorist Location" );
        $source_id   = $this->create_listing(
            $this->post_type,
            "Directorist Source",
            "publish",
            [
                "_address"    => "Directorist address",
                "_manual_lat" => "23.7808875",
                "_manual_lng" => "90.2792371",
                "_phone"      => "123",
                "_email"      => "directorist@example.com",
                "_website"    => "https://example.com",
                "_featured"   => "1",
                "_price"      => "100",
            ]
        );
        $related_id  = $this->create_listing( $this->post_type, "Directorist Related" );

        $this->assign_terms( $source_id, $this->category_taxonomy, [$category_id] );
        $this->assign_terms( $source_id, $this->tag_taxonomy, [$tag_id] );
        $this->assign_terms( $source_id, $this->location_taxonomy, [$location_id] );
        $this->assign_terms( $related_id, $this->category_taxonomy, [$category_id] );

        wp_insert_comment(
            [
                "comment_post_ID"      => $source_id,
                "comment_author"       => "Reviewer",
                "comment_author_email" => "reviewer@example.com",
                "comment_content"      => "Great listing.",
                "comment_approved"     => 1,
                "comment_type"         => "review",
            ]
        );

        $this->assert_provider_filter_surface( $this->integration );
        $this->assert_listing_collection( $this->integration, $source_id, $category_id );
        $this->assert_featured_listing( $this->integration, $source_id );
        $this->assert_single_listing( $this->integration, $source_id, "Directorist Source" );
        $this->assert_related_listings( $this->integration, $source_id, $related_id );
        $this->assert_categories( $this->integration, $category_id );
        $this->assert_tag_collection( $this->integration );
        $this->assert_location_collection( $this->integration, $location_id, get_term_field( "slug", $location_id, $this->location_taxonomy ) );
        $this->assert_single_location( $this->integration, $location_id, "Directorist Location" );
        $this->assert_review_payload( $this->integration, $source_id );
    }

    public function test_directorist_reviews_support_advanced_review_extension_storage(): void {
        $source_id = $this->create_listing( $this->post_type, "Directorist Advanced Reviews" );

        $this->create_review( $source_id, 5, "Excellent directorist review", "2024-01-01 10:00:00" );
        $this->create_review( $source_id, 5, "Another excellent directorist review", "2024-01-02 10:00:00" );
        $this->create_review( $source_id, 3, "Average directorist review", "2024-01-03 10:00:00" );

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
        $this->assertSame( 4.3, $reviews["average_rating"] );
    }

    public function test_directorist_reviews_support_newest_and_rating_sort_orders(): void {
        $source_id = $this->create_listing( $this->post_type, "Directorist Sorted Reviews" );

        $this->create_review( $source_id, 1, "Old low directorist review", "2024-01-01 10:00:00" );
        $this->create_review( $source_id, 5, "Middle high directorist review", "2024-01-02 10:00:00" );
        $this->create_review( $source_id, 3, "Newest medium directorist review", "2024-01-03 10:00:00" );

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

        $this->assertSame( ["Newest medium directorist review", "Middle high directorist review", "Old low directorist review"], array_column( $newest["items"], "review" ) );
        $this->assertSame( [5.0, 3.0, 1.0], array_column( $highest["items"], "rating" ) );
        $this->assertSame( [1.0, 3.0, 5.0], array_column( $lowest["items"], "rating" ) );
    }

    private function create_review( int $post_id, int $rating, string $content, string $date ): int {
        $comment_id = wp_insert_comment(
            [
                "comment_post_ID"      => $post_id,
                "comment_author"       => "Directorist Reviewer {$rating}",
                "comment_author_email" => "directorist-reviewer-" . md5( $content ) . "@example.com",
                "comment_content"      => $content,
                "comment_approved"     => 1,
                "comment_type"         => "review",
                "comment_parent"       => 0,
                "comment_date"         => $date,
                "comment_date_gmt"     => get_gmt_from_date( $date ),
            ]
        );

        update_comment_meta( $comment_id, "rating", $rating );
        update_comment_meta( $comment_id, "title", "Review title {$rating}" );

        return (int) $comment_id;
    }
}
