<?php

namespace Crafium\AppNatively\App\Integrations\Ecommerce\Catalog;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\Filtering\Catalog;
use Crafium\AppNatively\App\Filtering\RangeFacet;
use Crafium\AppNatively\App\Filtering\TermSource;

/**
 * Where one ecommerce plugin keeps what the product list and filter read: its
 * post type, taxonomies, and SQL for price, stock, sale and rating. From these
 * it describes the product facets (price and rating ranges, the `availability`
 * flags) and a list's `in_stock` setting to CatalogQuery.
 */
abstract class ProductCatalog extends Catalog {
    /** @var array<string, TermSource>|null Memoized (see term_facets()). */
    private ?array $term_facets = null;

    /**
     * The products' post type.
     */
    abstract public function post_type(): string;

    /**
     * The taxonomy behind the `category` facet and a page's `categories[]`.
     */
    abstract public function category_taxonomy(): string;

    /**
     * The taxonomy behind the `tag` facet and a list's `tags[]`, when the store has tags.
     */
    public function tag_taxonomy(): ?string {
        return null;
    }

    /**
     * Attribute taxonomies (`attribute:<name>` facets), name => label.
     *
     * @return array<string, string>
     */
    public function attribute_taxonomies(): array {
        return [];
    }

    /**
     * Other taxonomies a shopper may filter by (`taxonomy:<name>` facets, e.g. brands), name => label.
     *
     * @return array<string, string>
     */
    public function extra_taxonomies(): array {
        return [];
    }

    /**
     * A product's lowest price, in stored units.
     */
    abstract public function price_min_sql(): string;

    /**
     * A product's highest price, in stored units.
     */
    abstract public function price_max_sql(): string;

    /**
     * Stored price units per currency unit (100 for stores that keep cents).
     */
    public function price_divisor(): int {
        return 1;
    }

    /**
     * Condition: the product can be bought now.
     */
    abstract public function in_stock_sql(): string;

    /**
     * Condition: the product is discounted.
     */
    abstract public function on_sale_sql(): string;

    /**
     * A product's average star rating (0-5), when the store has reviews.
     */
    public function rating_sql(): ?string {
        return null;
    }

    /**
     * Condition: the product has at least one rating.
     */
    public function rated_sql(): ?string {
        return null;
    }

    public function post_types(): array {
        return [ $this->post_type() ];
    }

    /**
     * Memoized: CatalogQuery asks for the facets once per narrowing, which is many
     * times per request.
     */
    public function term_facets(): array {
        if ( $this->term_facets === null ) {
            $this->term_facets = $this->taxonomy_facets(
                [
                    'category' => $this->category_taxonomy(),
                    'tag'      => $this->tag_taxonomy(),
                ]
            );
        }
        return $this->term_facets;
    }

    public function taxonomy_groups(): array {
        return [
            'taxonomy'  => $this->extra_taxonomies(),
            'attribute' => $this->attribute_taxonomies(),
        ];
    }

    public function ranges(): array {
        $ranges = [
            'price' => new RangeFacet( __( 'Price', 'appnatively' ), $this->price_min_sql(), $this->price_max_sql(), $this->price_divisor() ),
        ];
        if ( $this->rating_sql() && $this->rated_sql() ) {
            $ranges['rating'] = new RangeFacet( __( 'Rating', 'appnatively' ), $this->rating_sql(), $this->rating_sql(), 1, [ 0, 5 ], $this->rated_sql() );
        }
        return $ranges;
    }

    public function flags(): array {
        return [
            'availability' => [
                'label'   => __( 'Availability', 'appnatively' ),
                'options' => [
                    'in_stock' => [ __( 'In stock', 'appnatively' ), $this->in_stock_sql() ],
                    'on_sale'  => [ __( 'On sale', 'appnatively' ), $this->on_sale_sql() ],
                ],
            ],
        ];
    }

    public function context_flags(): array {
        return [ 'in_stock' => $this->in_stock_sql() ];
    }
}
