<?php

namespace Crafium\AppNatively\App\Integrations\Directory\Catalog;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\App\Filtering\Field;
use Crafium\AppNatively\App\Filtering\TermSource;
use Crafium\AppNatively\App\Integrations\Directory\GeoDirectoryLocations;
use Crafium\AppNatively\WpMVC\Database\Query\Builder;

/**
 * GeoDirectory's listing data: one detail table per post type
 * (`geodir_<post type>_detail`, joined as `gd_detail`) whose columns hold
 * featured, rating, coordinates, business hours and every custom field
 * (defined in `geodir_custom_fields`, one column per `htmlvar_name`).
 */
class GeoDirectoryCatalog extends ListingCatalog {
    /** Custom-field types that make sense as a filter, => value type. */
    private const FILTERABLE_TYPES = [
        'text'        => Field::TYPE_CHOICE,
        'select'      => Field::TYPE_CHOICE,
        'radio'       => Field::TYPE_CHOICE,
        'multiselect' => Field::TYPE_CHOICE,
        'checkbox'    => Field::TYPE_BOOLEAN,
    ];

    /** Numeric column types (a `text` field of these types is a number). */
    private const NUMBER_DATA_TYPES = [ 'INT', 'FLOAT', 'DECIMAL' ];

    /** Columns the catalog reads itself, never offered as custom fields. */
    private const CORE_COLUMNS = [
        'post_title', 'post_content', 'post_tags', 'post_category', 'default_category', 'address', 'street', 'street2', 'city',
        'region', 'country', 'zip', 'latitude', 'longitude', 'featured', 'business_hours', 'price', 'email', 'phone', 'website',
        'overall_rating', 'rating_count', 'post_images', 'post_status',
    ];

    /** @var string[]|null */
    private ?array $columns = null;

    /** @var array<string, Field>|null */
    private ?array $fields = null;

    private ?GeoDirectoryLocations $locations = null;

    private bool $locations_resolved = false;

    public function post_types(): array {
        if ( function_exists( 'geodir_get_posttypes' ) ) {
            $post_types = geodir_get_posttypes( 'array' );
            if ( is_array( $post_types ) && ! empty( $post_types ) ) {
                return [ (string) array_key_first( $post_types ) ];
            }
        }
        return [ 'gd_place' ];
    }

    public function category_taxonomy(): string {
        return $this->post_type() . 'category';
    }

    public function tag_taxonomy(): ?string {
        return $this->post_type() . '_tags';
    }

    public function location_source(): ?TermSource {
        return $this->geodirectory_locations();
    }

    /**
     * GeoDirectory's locations, or null without the Location Manager add-on that holds
     * them. The same instance the provider reads its location DTOs from: it remembers
     * the locations it looked up, so a listing page costs no query per listing.
     */
    public function geodirectory_locations(): ?GeoDirectoryLocations {
        if ( ! $this->locations_resolved ) {
            $this->locations_resolved = true;
            $this->locations          = GeoDirectoryLocations::available() ? new GeoDirectoryLocations( $this->post_type() ) : null;
        }
        return $this->locations;
    }

    /**
     * GeoDirectory mirrors its detail columns into post meta; those copies aren't custom fields.
     */
    public function internal_meta_keys(): array {
        return self::CORE_COLUMNS;
    }

    public function prepare( Builder $query ): void {
        $query->left_join( $this->detail_table() . ' as gd_detail', 'posts.ID', '=', 'gd_detail.post_id' );
    }

    public function featured_sql(): string {
        return $this->has_column( 'featured' ) ? 'gd_detail.featured = 1' : '0 = 1';
    }

    public function price_sql(): ?array {
        if ( ! $this->has_column( 'price' ) ) {
            return null;
        }
        $price = "(NULLIF(gd_detail.price, '') + 0)";
        return [ $price, $price ];
    }

    public function rating_sql(): ?string {
        return $this->has_column( 'overall_rating' ) ? 'gd_detail.overall_rating' : null;
    }

    public function rated_sql(): ?string {
        return $this->has_column( 'rating_count' ) ? 'gd_detail.rating_count > 0' : null;
    }

    public function coordinates(): ?array {
        if ( ! $this->has_column( 'latitude' ) || ! $this->has_column( 'longitude' ) ) {
            return null;
        }
        return [ "(NULLIF(gd_detail.latitude, '') + 0)", "(NULLIF(gd_detail.longitude, '') + 0)" ];
    }

    public function has_business_hours(): bool {
        return $this->has_column( 'business_hours' ) && function_exists( 'geodir_schema_to_array' );
    }

    /**
     * `business_hours`: GeoDirectory's schema string (`["Mo 09:00-17:00", …],["UTC":"+1"]`),
     * parsed by its own geodir_schema_to_array().
     */
    protected function business_hours(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one bulk read of a trusted table name, cached by the caller.
        $rows  = $wpdb->get_results( "SELECT post_id, business_hours FROM {$wpdb->prefix}{$this->detail_table()} WHERE post_status = 'publish' AND business_hours <> ''" );
        $hours = [];
        foreach ( (array) $rows as $row ) {
            $schema = geodir_schema_to_array( stripslashes( (string) $row->business_hours ) );
            if ( empty( $schema['hours'] ) || ! is_array( $schema['hours'] ) ) {
                continue;
            }
            $schedule = new BusinessHours( BusinessHours::timezone( $schema['timezone_string'] ?? null ) ?? BusinessHours::timezone( $schema['utc_offset'] ?? null ) );
            foreach ( $schema['hours'] as $day => $slots ) {
                foreach ( (array) $slots as $slot ) {
                    if ( is_array( $slot ) && isset( $slot['opens'], $slot['closes'] ) ) {
                        $schedule->add( (string) $day, (string) $slot['opens'], (string) $slot['closes'] );
                    }
                }
            }
            if ( $schedule->has_hours() ) {
                $hours[ (int) $row->post_id ] = $schedule;
            }
        }
        return $hours;
    }

    /**
     * Active custom fields of the post type; enabled when GeoDirectory's Advanced Search
     * add-on offers them in its search form.
     */
    public function fields(): array {
        if ( $this->fields !== null ) {
            return $this->fields;
        }

        global $wpdb;
        $this->fields = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- builder listing and per-request lookup of a small table.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT htmlvar_name, admin_title, frontend_title, field_type, data_type, option_values FROM {$wpdb->prefix}geodir_custom_fields WHERE post_type = %s AND is_active = 1", $this->post_type() ) );

        $searchable = $this->advanced_search_fields();
        foreach ( (array) $rows as $row ) {
            $key  = (string) $row->htmlvar_name;
            $type = (string) $row->field_type;
            // The key becomes a column name in SQL: plain identifiers only.
            if ( ! isset( self::FILTERABLE_TYPES[ $type ] ) || in_array( $key, self::CORE_COLUMNS, true ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $key ) || ! $this->has_column( $key ) ) {
                continue;
            }

            $value_type = self::FILTERABLE_TYPES[ $type ];
            if ( $type === 'text' && in_array( strtoupper( (string) $row->data_type ), self::NUMBER_DATA_TYPES, true ) ) {
                $value_type = Field::TYPE_NUMBER;
            }

            $field = Field::column(
                "gd_detail.{$key}",
                (string) ( $row->frontend_title ?: $row->admin_title ?: $key ),
                $value_type,
                $this->choices( (string) $row->option_values ),
                $type === 'multiselect',
                in_array( $key, $searchable, true )
            );
            // Multiselect values are stored as a comma list.
            $this->fields[ $key ] = $type === 'multiselect' ? $field->with_list_pattern( '{value}' ) : $field;
        }
        return $this->fields;
    }

    /**
     * Option values (`Label/value,Other`) as value => label.
     *
     * @return array<string, string>
     */
    private function choices( string $option_values ): array {
        if ( $option_values === '' ) {
            return [];
        }
        if ( function_exists( 'geodir_string_values_to_options' ) ) {
            $choices = [];
            foreach ( (array) geodir_string_values_to_options( $option_values, true ) as $option ) {
                if ( is_array( $option ) && isset( $option['value'] ) && $option['value'] !== '' && empty( $option['optgroup'] ) ) {
                    $choices[ (string) $option['value'] ] = (string) ( $option['label'] ?? $option['value'] );
                }
            }
            return $choices;
        }
        $choices = [];
        foreach ( explode( ',', $option_values ) as $option ) {
            $parts = explode( '/', $option, 2 );
            $value = trim( $parts[1] ?? $parts[0] );
            if ( $value !== '' ) {
                $choices[ $value ] = trim( $parts[0] );
            }
        }
        return $choices;
    }

    /**
     * Fields the Advanced Search add-on shows for the post type (none without the add-on).
     *
     * @return string[]
     */
    private function advanced_search_fields(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'geodir_custom_advance_search_fields';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small lookup.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- small lookup of a trusted table name.
        return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT htmlvar_name FROM {$table} WHERE post_type = %s", $this->post_type() ) ) );
    }

    private function post_type(): string {
        return $this->post_types()[0];
    }

    /**
     * The detail table's name without the site prefix (the query builder adds it).
     */
    private function detail_table(): string {
        return 'geodir_' . $this->post_type() . '_detail';
    }

    private function has_column( string $column ): bool {
        if ( $this->columns === null ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema lookup of a trusted table name.
            $this->columns = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}{$this->detail_table()}" ) );
        }
        return in_array( $column, $this->columns, true );
    }
}
