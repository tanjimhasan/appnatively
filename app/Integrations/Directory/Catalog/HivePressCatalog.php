<?php

namespace Crafium\AppNatively\App\Integrations\Directory\Catalog;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\Filtering\Field;

/**
 * HivePress's listing data: post meta prefixed `hp_` (`hp_featured`, `hp_price`,
 * `hp_rating`, and `hp_latitude` / `hp_longitude` from its Geolocation extension),
 * and listing attributes: those with options become `hp_listing_<name>`
 * taxonomies, the others are stored as `hp_<name>` meta. HivePress has no tags
 * and no opening hours.
 */
class HivePressCatalog extends ListingCatalog {
    /** Attribute field types that make sense as a filter, => value type. */
    private const FILTERABLE_TYPES = [
        'text'     => Field::TYPE_CHOICE,
        'number'   => Field::TYPE_NUMBER,
        'checkbox' => Field::TYPE_BOOLEAN,
    ];

    /** Attributes the catalog reads itself, never offered as custom fields. */
    private const CORE_ATTRIBUTES = [ 'price', 'featured', 'rating', 'latitude', 'longitude', 'location', 'region' ];

    /** @var array<string, array>|null Listing attributes by name (see attributes()). */
    private ?array $attributes = null;

    /** @var array<string, Field>|null */
    private ?array $fields = null;

    public function post_types(): array {
        return [ 'hp_listing' ];
    }

    public function category_taxonomy(): string {
        return 'hp_listing_category';
    }

    /**
     * Regions, when the Geolocation extension generates them.
     */
    public function location_taxonomy(): ?string {
        return get_option( 'hp_geolocation_generate_regions' ) && taxonomy_exists( 'hp_listing_region' ) ? 'hp_listing_region' : null;
    }

    /**
     * Attributes with options (stored as taxonomies).
     */
    public function extra_taxonomies(): array {
        $taxonomies = [];
        foreach ( $this->attributes() as $attribute ) {
            $taxonomy = $attribute['edit_field']['option_args']['taxonomy'] ?? null;
            if ( is_string( $taxonomy ) && taxonomy_exists( $taxonomy ) ) {
                $taxonomies[ $taxonomy ] = (string) ( $attribute['label'] ?? $taxonomy );
            }
        }
        return $taxonomies;
    }

    public function featured_sql(): string {
        return $this->meta_in( 'hp_featured', [ '1' ] );
    }

    public function price_sql(): ?array {
        $price = $this->meta_number( 'hp_price' );
        return [ $price, $price ];
    }

    public function rating_sql(): ?string {
        return $this->meta_number( 'hp_rating' );
    }

    public function rated_sql(): ?string {
        return $this->meta_number( 'hp_rating' ) . ' > 0';
    }

    public function coordinates(): ?array {
        return [ $this->meta_number( 'hp_latitude' ), $this->meta_number( 'hp_longitude' ) ];
    }

    public function internal_meta_keys(): array {
        return [
            'hp_featured', 'hp_price', 'hp_rating', 'hp_rating_count', 'hp_latitude', 'hp_longitude', 'hp_location', 'hp_verified', 'hp_vendor',
            'hp_image', 'hp_images', 'hp_expired_time', 'hp_featured_time', 'hp_address', 'hp_phone', 'hp_email', 'hp_website',
        ];
    }

    /**
     * Attributes stored as meta (without options); enabled when HivePress marks them filterable.
     */
    public function fields(): array {
        if ( $this->fields !== null ) {
            return $this->fields;
        }

        $this->fields = [];
        foreach ( $this->attributes() as $name => $attribute ) {
            $type = (string) ( $attribute['edit_field']['type'] ?? '' );
            if ( in_array( $name, self::CORE_ATTRIBUTES, true ) || isset( $attribute['edit_field']['options'] ) || ! isset( self::FILTERABLE_TYPES[ $type ] ) ) {
                continue;
            }
            $this->fields[ (string) $name ] = Field::meta(
                'hp_' . $name,
                (string) ( $attribute['label'] ?? $name ),
                self::FILTERABLE_TYPES[ $type ],
                [],
                false,
                ! empty( $attribute['filterable'] )
            );
        }
        return $this->fields;
    }

    /**
     * @return array<string, array>
     */
    private function attributes(): array {
        if ( $this->attributes === null ) {
            // hivepress()->attribute is a magic property: isset() can't test it.
            $this->attributes = function_exists( 'hivepress' ) ? (array) hivepress()->attribute->get_attributes( 'listing' ) : [];
        }
        return $this->attributes;
    }
}
