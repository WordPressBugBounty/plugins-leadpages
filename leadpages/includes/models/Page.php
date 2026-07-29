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
     *   - platform         - which Leadpages backend the row belongs to ('classic' | 'nova'). Defaults to
     *                        'classic' so pre-existing rows are unaffected.
     *   - nova_page_id     - the Nova page identifier (a nanoid string). Null for classic rows. Stored
     *                        separately from the integer primary key which it must not overload.
     *   - site_id          - the Nova site the page belongs to, if any. Null for classic rows.
     *   - published_at     - the time the page was published on the Nova platform. Null for classic rows.
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
                platform VARCHAR(20) DEFAULT 'classic' NOT null,
                nova_page_id VARCHAR(100) null,
                site_id VARCHAR(100) null,
                published_at DATETIME null,
                PRIMARY KEY  (id),
                INDEX uuid_idx (uuid),
                INDEX wp_slug_idx (wp_slug),
                INDEX nova_page_id_idx (nova_page_id)
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
     * Add the Nova (new Leadpages) columns to the page table for installs created before these
     * columns existed. This is idempotent: each column and index is only added when missing, so it
     * is safe to run on a fresh install (where create_table() already added them) or on an upgrade.
     *
     * @return void
     * @throws DatabaseError
     */
    public static function add_nova_columns() {
        global $wpdb;
        $table = $wpdb->prefix . self::table_name;

        $columns = [
            'platform'     => "ADD COLUMN platform VARCHAR(20) DEFAULT 'classic' NOT NULL",
            'nova_page_id' => 'ADD COLUMN nova_page_id VARCHAR(100) NULL',
            'site_id'      => 'ADD COLUMN site_id VARCHAR(100) NULL',
            'published_at' => 'ADD COLUMN published_at DATETIME NULL',
        ];

        foreach ($columns as $column => $definition) {
            // Table/column names here are controlled constants, never user input.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $column));
            if (! $column_exists) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("ALTER TABLE `$table` $definition");
                self::throw_on_db_error();
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $index_exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name = %s", 'nova_page_id_idx'));
        if (! $index_exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `$table` ADD INDEX nova_page_id_idx (nova_page_id)");
            self::throw_on_db_error();
        }
    }

    /**
     * Widen the columns that store values sourced from the new Leadpages (Nova) backend, where the
     * originals are unbounded `text`. A Nova page whose title exceeded name's old VARCHAR(255) made
     * WordPress's $wpdb reject the write, which aborted the entire sync (HP-2528); production has
     * titles past 600 chars and slugs past 255. lp_slug in particular cannot simply be truncated
     * because it builds the /raw serving URL. Widening is additive and lossless; nullability is
     * preserved to match the original column definitions.
     *
     * ALTER ... MODIFY is effectively idempotent (re-applying the same type is a no-op), so the
     * version-gated migration needs no per-column existence check.
     *
     * @return void
     * @throws DatabaseError
     */
    public static function widen_long_text_columns() {
        global $wpdb;
        $table = $wpdb->prefix . self::table_name;

        $modifications = [
            'MODIFY name TEXT NOT NULL',
            'MODIFY lp_slug VARCHAR(2048) NOT NULL',
            'MODIFY published_url VARCHAR(2048) NULL',
            'MODIFY current_edition VARCHAR(100) NULL',
        ];

        foreach ($modifications as $modification) {
            // Table/column names are controlled constants, never user input.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("ALTER TABLE `$table` $modification");
            self::throw_on_db_error();
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
     * Prepare raw landing page data from a Nova (new Leadpages) request for the database.
     *
     * Nova exposes pages through {NOVA_APP_URL}/api/pages with the shape
     * { id, slug, title, url, siteId, publishedAt, createdAt, updatedAt }. The Nova page id is a
     * nanoid string; it is stored in the dedicated nova_page_id column (and reused as the uuid sync
     * key) rather than overloading the integer primary key. Because the Nova list endpoint only
     * returns published pages, current_edition is marked non-null so the row surfaces in get_many().
     *
     * @param object $data should be a Nova page object from {NOVA_APP_URL}/api/pages
     * @return array
     */
    private static function prepare_nova_data( $data ) {
        // phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        return [
            'uuid'            => $data->id,
            'name'            => $data->title,
            'published_url'   => isset($data->url) ? $data->url : null,
            'lp_slug'         => $data->slug,
            'kind'            => 'NovaPage',
            'current_edition' => $data->id,
            'platform'        => 'nova',
            'nova_page_id'    => $data->id,
            'site_id'         => isset($data->siteId) ? $data->siteId : null,
            'published_at'    => isset($data->publishedAt) ? $data->publishedAt : null,
            'updated_at'      => isset($data->updatedAt) ? $data->updatedAt : null,
            // "LAST MODIFIED" column reads last_published (shared with Classic).
            // Populate it for Nova rows so the table shows a real date instead of
            // "Invalid Date": prefer updatedAt, fall back to publishedAt.
            'last_published'  => isset($data->updatedAt)
                ? $data->updatedAt
                : (isset($data->publishedAt) ? $data->publishedAt : null),
            // A page returned by the sync is live, so clear any previous deletion marker.
            'deleted_at'      => null,
        ];
        // phpcs:enable
    }

    /**
     * Create a new entry in the database for a raw landing page.
     *
     * @param object $data raw landing page data from foundry (classic) or Nova
     * @param string $platform 'classic' | 'nova' - selects how the raw data is prepared
     * @return int the number of rows inserted
     * @throws DatabaseError
     */
    public static function create( $data, $platform = 'classic' ) {
        global $wpdb;

        $data = 'nova' === $platform ? self::prepare_nova_data($data) : self::prepare_foundry_data($data);

        // Explicit all-%s format. WordPress's default $wpdb->field_types maps the
        // reserved column name `site_id` (a core multisite column) to %d, which
        // would intval() our string Nova site ids to 0. Every column in this table
        // round-trips safely as a string, so %s across the board is correct.
        $wpdb->insert(
            $wpdb->prefix . self::table_name,
            $data,
            array_fill(0, count($data), '%s')
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
     * @param array|object $data can be an array of values to update or raw landing page data
     * @param string $platform 'classic' | 'nova' - selects how raw object data is prepared
     * @return false|int number of rows updated or false when no rows were updated
     * @throws DatabaseError
     */
    public static function update( $identifier, $data, $platform = 'classic' ) {
        global $wpdb;

        // If data is an object it must be raw landing page data from a foundry or Nova request
        if (is_object($data)) {
            $data = 'nova' === $platform ? self::prepare_nova_data($data) : self::prepare_foundry_data($data);
        }

        // Determine the identifier type (ID or UUID)
        $identifier_type = is_int($identifier) ? 'id' : 'uuid';

        // Explicit all-%s format — see create(): WP's field_types coerces the
        // reserved `site_id` name to %d, corrupting string Nova site ids.
        $result = $wpdb->update(
            $wpdb->prefix . self::table_name,
            $data,
            [ $identifier_type => $identifier ],
            array_fill(0, count($data), '%s'),
            [ '%s' ]
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
    public static function get_many( $page, $per_page, $connected, $order_by, $order, $search, $platform = null ) {
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

        // Scope by connection state and platform. Published pages (connected = 1) are a property of
        // the WordPress site and are shown for BOTH platforms, so a customer keeps seeing every page
        // that is live regardless of which account is currently connected. The unpublished catalog
        // (connected = 0) belongs to the connected account, so it is scoped to the active platform.
        // A null $platform leaves platform scoping off (back-compat for existing callers/tests).
        if (true === $connected) {
            $page_sql .= ' AND connected = 1';
            $count_sql .= ' AND connected = 1';
        } elseif (false === $connected) {
            $page_sql .= ' AND connected = 0';
            $count_sql .= ' AND connected = 0';
            if (null !== $platform) {
                $platform_clause = $wpdb->prepare(' AND platform = %s', $platform);
                $page_sql .= $platform_clause;
                $count_sql .= $platform_clause;
            }
        } elseif (null !== $platform) {
            // status "all": every published page plus the active platform's catalog.
            $all_clause = $wpdb->prepare(' AND (connected = 1 OR platform = %s)', $platform);
            $page_sql .= $all_clause;
            $count_sql .= $all_clause;
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
     * Retrieve all Nova landing pages that have not been marked deleted. Used to reconcile
     * deletions by diffing the stored Nova rows against the ids returned from a Nova sync.
     *
     * @return array
     * @throws DatabaseError
     */
    public static function get_nova_pages() {
        global $wpdb;

        $results = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'SELECT * FROM ' . $wpdb->prefix . self::table_name . " WHERE platform = 'nova' AND deleted_at IS NULL"
        );

        self::throw_on_db_error();
        return $results;
    }

    /**
     * Delete the unpublished catalog rows (connected = 0) for a platform.
     *
     * Published rows (connected = 1) are intentionally left untouched: a page already serving at a
     * WordPress slug is a property of the site and must keep serving regardless of which account is
     * connected. Used on disconnect so a previous account's available-pages list does not linger for
     * the next connection.
     *
     * @param string $platform 'classic' | 'nova'
     * @return void
     * @throws DatabaseError
     */
    public static function delete_catalog_by_platform( $platform ) {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                'DELETE FROM ' . $wpdb->prefix . self::table_name . ' WHERE platform = %s AND connected = 0',
                $platform
            )
        );

        self::throw_on_db_error();
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
