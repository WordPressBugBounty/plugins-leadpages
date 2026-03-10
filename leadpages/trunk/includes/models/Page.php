<?php

namespace Leadpages\models;

defined('ABSPATH') || die('No script kiddies please!');

use Leadpages\models\exceptions\DatabaseError;
use Leadpages\models\ModelBase;

/**
 * A class to interact with the landing page database table
 */
class Page extends ModelBase {
    /* Database table name for storing landing page data */
    // phpcs:ignore Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
    public const table_name = LEADPAGES_DB_PREFIX . '_landingpages';

    /**
     * Create a table name to store landing page data synced from Leadpages
     *   - uuid             - unique identifier for the page in Leadpages
     *   - name             - name of landing page in Leadpages
     *   - published_url    - url of landing page if published with Leadpages, null otherwise
     *   - redirect         - JSON data
     *   - lp_slug          - slug of landing page as published with Leadpages
     *   - last_published   - last time the landing page was published with Leadpages, null if never published
     *   - current_edition  - indicates whether a page is published in Leadpages, null if not currently published
     *   - split_test       - indicates that the page is a split test variation. JSON data  - see Scribe
     *                        for the property schema. This will be null on LeadpageSplitTestV2 pages.
     *   - variations       - indicates which page variations are in a split test. JSON data. This will be null
     *                        on LeadpageV3 pages.
     *   - kind             - Leadpages kind of page (either LeadpageV3 | LeadpageSplitTestV2)
     *   - conversions      - analytic data for the page
     *   - conversion_rate  - analytic data for the page
     *   - lead_value       - analytic data for the page
     *   - views            - analytic data for the page
     *   - visitors         - analytic data for the page
     *   - updated_at       - date the page was last updated on the Leadpages platform
     *   - deleted_at       - date the page was deleted from Leadpages
     *   - connected        - whether or not the landing page is being served through WordPress
     *   - wp_page_type     - identifies how the page should be served in WordPress (not currently in use)
     *   - wp_slug          - slug the page is connected under in WordPress
     *
     * @return void
     * @throws Exception
     */
    public static function create_table() {
        global $wpdb;

        $sql = "
            CREATE TABLE $wpdb->prefix" . self::table_name . " (
                id INT NOT null AUTO_INCREMENT,
                uuid VARCHAR(100) UNIQUE NOT null,
                name VARCHAR(255) NOT null,
                published_url VARCHAR(255) null,
                redirect LONGTEXT null,
                lp_slug VARCHAR(255) NOT null,
                last_published DATETIME null,
                current_edition VARCHAR(25) null,
                split_test LONGTEXT null,
                variations LONGTEXT null,
                kind VARCHAR(50) NOT null,
                conversion_rate FLOAT null,
                lead_value FLOAT null,
                conversions INT null,
                views INT null,
                visitors INT null,
                updated_at DATETIME null,
                deleted_at DATETIME null,
                connected BOOLEAN DEFAULT FALSE not null,
                wp_page_type VARCHAR(10) UNIQUE null,
                wp_slug VARCHAR(100) UNIQUE null,
                PRIMARY KEY  (id),
                INDEX uuid_idx (uuid),
                INDEX wp_slug_idx (wp_slug)
            ) {$wpdb->get_charset_collate()};
        ";

        // must require this file to use dbDelta
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // there does not seem to be a way to catch errors during table creation with dbDelta
        // so we'll check if the table exists and throw an exception if it doesn't
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . self::table_name));
        if (! $exists) {
            throw new \Exception('Could not create table: ' . esc_html(self::table_name));
        }
    }

    /**
     * Prepare raw landing page data from a foundry request for the database
     *
     * @param object $data should be raw landing page data from foundry
     * @return array
     */
    private static function prepare_foundry_data( $data ) {
        $content = $data->content;
        $meta = $data->_meta;

        return [
            // phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            'uuid'            => $meta->id,
            'name'            => $content->name,
            'published_url'   => $content->publishedUrl,
            'redirect'        => isset($content->redirect) ? wp_json_encode($content->redirect) : null,
            'lp_slug'         => $content->slug,
            'last_published'  => $content->lastPublished,
            'current_edition' => $content->currentEdition,
            'split_test'      => isset($content->splitTest) ? wp_json_encode($content->splitTest) : null,
            'variations'      => isset($content->variations) ? wp_json_encode($content->variations) : null,
            'kind'            => $data->kind,
            'conversion_rate' => $content->conversionRate,
            'lead_value'      => isset($content->leadValue) ? $content->leadValue : null,
            'conversions'     => $content->conversions,
            'views'           => $content->views,
            'visitors'        => $content->visitors,
            'updated_at'      => $meta->updated,
            'deleted_at'      => $meta->deleted,
            // phpcs:enable
        ];
    }

    /**
     * Create a new entry in the database for a raw landing page from a foundry request
     *
     * @param object $data should be raw landing page data from foundry
     * @return int the number of rows inserted
     * @throws DatabaseError
     */
    public static function create( $data ) {
        global $wpdb;

        $data = self::prepare_foundry_data($data);

        $wpdb->insert(
            $wpdb->prefix . self::table_name,
            $data
        );

        self::throw_on_db_error();
        return $wpdb->insert_id;
    }

    /**
     * Update a page by it's "id" in WordPress or the "uuid" of the page in Leadpages
     *
     * If the data passed in as the second argument is an object it will be assumed that it's
     * raw landing page data from a foundry request and will be prepared for database entry. Otherwise
     * the values will be treated as an array of values to update.
     *
     * @param string|int $identifier can be int (id) or string (uuid)
     * @param array|object $data can be an array of values to update or raw landing page data from foundry
     * @return false|int number of rows updated or false when no rows were updated
     * @throws DatabaseError
     */
    public static function update( $identifier, $data ) {
        global $wpdb;

        // If data is an object it must be a raw landing page data from foundry request
        if (is_object($data)) {
            $data = self::prepare_foundry_data($data);
        }

        // Determine the identifier type (ID or UUID)
        $identifier_type = is_int($identifier) ? 'id' : 'uuid';

        $result = $wpdb->update(
            $wpdb->prefix . self::table_name,
            $data,
            [ $identifier_type => $identifier ]
        );

        self::throw_on_db_error();
        return $result;
    }

    /**
     * Get a page by it's "id" in WordPress or the "uuid" of the page in Leadpages
     *
     * @param mixed $identifier can be int (id) or string (uuid)
     * @return object landing page data
     * @throws DatabaseError
     */
    public static function get( $identifier ) {
        global $wpdb;

        // Determine the identifier type (ID or UUID)
        // Identifier type is now a controlled value. It can be 'id' or 'uuid'
        $identifier_type = is_int($identifier) ? 'id' : 'uuid';

        $result = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
                'SELECT * FROM ' . $wpdb->prefix . self::table_name . " WHERE $identifier_type = %s",
                $identifier
            )
        );

        self::throw_on_db_error();
        return $result;
    }

    /**
     * Retrieve a landing page by the slug it's connected to WordPress under
     *
     * @param string $slug
     * return object|null the landing page data or null if not found
     */
    public static function get_by_slug( $slug ) {
        global $wpdb;
        $slug = sanitize_title($slug);

        $result = $wpdb ->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'SELECT * FROM ' . $wpdb->prefix . self::table_name . ' WHERE connected = 1 AND wp_slug = %s',
                $slug
            )
        );

        return $result;
    }


    /**
     * Get all pages with support for pagination and filtering
     *
     * @param int $page
     * @param int $per_page
     * @param bool|null $connected
     * @param string $order_by
     * @param string $order
     * @param string $search
     * @return array results and total page count for the query returned in the form [ results, total_count ]
     * @throws DatabaseError
     */
    public static function get_many( $page, $per_page, $connected, $order_by, $order, $search ) {
        global $wpdb;

        $allowed_orderby = [ 'ASC', 'DESC' ];
        $order = strtoupper($order);
        if (!in_array($order, $allowed_orderby, true)) {
            throw new \InvalidArgumentException(
                'Order must be one of: ' . esc_html(implode(', ', $allowed_orderby))
            );
        }
        $column = 'date' === $order_by ? 'updated_at' : 'name';
        $start = ( $page - 1 ) * $per_page;

        $page_sql = 'SELECT * FROM ' . $wpdb->prefix . self::table_name . ' WHERE current_edition IS NOT NULL AND deleted_at IS NULL AND split_test IS NULL';
        $count_sql = 'SELECT COUNT(*) FROM ' . $wpdb->prefix . self::table_name . ' WHERE current_edition IS NOT NULL AND deleted_at IS NULL AND split_test IS NULL';

        if (null !== $connected) {
            $page_sql .= $wpdb->prepare(' AND connected = %d', $connected);
            $count_sql .= $wpdb->prepare(' AND connected = %d', $connected);
        }

        if ('' !== $search) {
            $like = $wpdb->esc_like($search);
            $page_sql .= $wpdb->prepare(' AND name LIKE %s', "%$like%");
        }

        // column and order are controlled
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $page_sql .= $wpdb->prepare(" ORDER BY $column $order LIMIT %d, %d", $start, $per_page);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total_count = $wpdb->get_var($count_sql);
        self::throw_on_db_error();

        if (0 === $total_count) {
            return [ [], $total_count ];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($page_sql);
        self::throw_on_db_error();

        return [ $results, $total_count ];
    }

    /**
     * Check if there are any WordPress posts or pages with the same name as the provided slug
     *
     * @param string $slug
     * @return \WP_Post[]
     */
    public static function is_post_name_collision( $slug ) {
        global $wpdb;
        $slug = sanitize_title($slug);

        $results = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
                "SELECT p.ID, p.post_title, p.post_name FROM $wpdb->posts p WHERE
                EXISTS ( SELECT 1 FROM " . $wpdb->prefix . self::table_name . " WHERE p.post_name = %s)
                AND p.post_type IN ('post', 'page')",
                // phpcs:enable
                $slug
            )
            // phpcs:enable
        );
        return $results;
    }
}
