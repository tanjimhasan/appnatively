<?php

namespace Crafium\AppNatively\App\Integrations\Directory;

defined( "ABSPATH" ) || exit;

use Crafium\AppNatively\WpMVC\Database\Eloquent\Model;
use Crafium\AppNatively\WpMVC\Database\Query\Builder;

/**
 * A row of one of GeoDirectory's own tables, which is what the query builder needs to
 * run a statement: it reads a table's site prefix through a model and hydrates the rows
 * into one.
 *
 * Which table is chosen per query — the listing detail table's name holds the post type,
 * and Location Manager keeps cities and their SEO rows in two more — so the model names
 * only the table GeoDirectoryLocations starts from, and declares no columns and no casts:
 * a value arrives exactly as it did on a `$wpdb` row, for the callers that cast it
 * themselves.
 */
final class GeoDirectoryRow extends Model {
    /** None of these tables keep timestamps. */
    public bool $timestamps = false;

    /**
     * A builder over one of the tables, named without the site prefix, which the
     * resolver adds, and aliased so its columns can be qualified.
     */
    public static function from_table( string $table, string $as ): Builder {
        return ( new self() )->new_query()->from( $table, $as );
    }

    public static function get_table_name(): string {
        return 'geodir_post_locations';
    }
}
