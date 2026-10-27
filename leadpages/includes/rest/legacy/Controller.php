<?php

namespace Leadpages\rest\legacy;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\models\Options;
use Leadpages\models\Page;
use Leadpages\Proxy;
use Leadpages\providers\http\Client;
use Leadpages\providers\http\auth\OAuthAuthProvider;
use Leadpages\providers\http\exceptions\AuthException;
use Leadpages\providers\http\exceptions\HttpException;
use Leadpages\providers\config\Config;
use Leadpages\providers\Utils;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Brings over the pages a site published with the old Leadpages Connector plugin (2.x).
 *
 * The old plugin stored each page as a `leadpages_post` post whose `leadpages_slug` meta is the
 * URL path and whose `leadpages_page_id` meta is the Classic page id, plus the options
 * `leadpages_front_page_id` / `leadpages_404_page_id` naming the homepage and 404 page posts. That
 * data stays in the database after the old plugin is deleted (it has no uninstall routine), so the
 * import reads it directly and never needs the old plugin to be installed. Nova reports the Classic
 * ids each migrated page came from, which is how a legacy page is matched to its Nova page. Each
 * match is then published at the old path through the regular page update endpoint, so every
 * collision rule that applies to a manual publish applies here too.
 *
 * The import only reads the legacy data. It never edits or deletes it.
 *
 *   GET  /leadpages/v1/legacy-import - whether legacy pages exist, and the last import result
 *   POST /leadpages/v1/legacy-import - run the import
 */
class Controller {

    use Utils;

    private const LEGACY_POST_TYPE = 'leadpages_post';
    private const LEGACY_FRONT_OPTION = 'leadpages_front_page_id';
    private const LEGACY_404_OPTION = 'leadpages_404_page_id';

    // A lock older than this is treated as left behind by a request that died mid-import.
    private const LOCK_TTL = 300;

    /** @var Config */
    private $config;

    /** @var string|null the value this request stored in the lock option */
    private $lock_token = null;

    public function __construct() {
        $this->config = Config::get_instance();
    }

    public function register_routes() {
        register_rest_route(
            LEADPAGES_NS . '/v1',
            '/legacy-import',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_status' ],
                    'permission_callback' => [ $this, 'permissions_check' ],
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'run_import' ],
                    'permission_callback' => [ $this, 'permissions_check' ],
                ],
            ]
        );
    }

    public function permissions_check() {
        return current_user_can('manage_options');
    }

    /**
     * @return WP_REST_Response
     */
    public function get_status() {
        $result = Options::get(Options::$legacy_import);
        $failure = Options::get(Options::$legacy_import_failure);
        // Only offer the import when it can run: a site switched back to Classic keeps its Nova tokens,
        // and offering it there would end in a "not connected" error on every click.
        $entries = $this->is_connected_to_nova() ? $this->read_legacy_entries() : [];

        // A site already moved over by hand (e.g. republished on 1.5.0) serves these pages again, so it
        // must not be asked to republish them: count only pages none of whose old URLs is served yet.
        $served_post_ids = [];
        foreach ($entries as $entry) {
            if ($this->is_served($entry)) {
                $served_post_ids[ $entry['post_id'] ] = true;
            }
        }
        $post_ids = array_diff(array_unique(array_column($entries, 'post_id')), array_keys($served_post_ids));

        return new WP_REST_Response(
            [
                // Pages, not URLs: the homepage or 404 page usually also has its own path entry.
                'legacyPageCount' => count($post_ids),
                'result'          => $result ? $result : null,
                'failure'         => $failure ? $failure : null,
            ]
        );
    }

    /**
     * @return WP_Error|WP_REST_Response
     */
    public function run_import() {
        if (! $this->is_connected_to_nova()) {
            return new WP_Error('no_token', 'Connect the new Leadpages before importing pages', [ 'status' => 401 ]);
        }

        // The old plugin serves the homepage and 404 page before the new one gets a chance, so the
        // imported homepage and 404 page would never show while it is still active.
        if (isset($GLOBALS['leadpages_connector_plugin_version'])) {
            return new WP_Error(
                'legacy_plugin_active',
                'Deactivate and delete the old Leadpages Connector plugin first, then republish your pages.',
                [ 'status' => 409 ]
            );
        }

        if (! $this->acquire_lock()) {
            return new WP_Error(
                'import_running',
                'An import is already running on this site. Wait a few minutes, then reload this page to see the result.',
                [ 'status' => 409 ]
            );
        }

        // One internal publish per page can outlast a host's default time limit on a large site. A request
        // killed mid-import leaves the lock behind until it goes stale, so allow as long as the lock lasts.
        if (function_exists('set_time_limit')) {
            set_time_limit(self::LOCK_TTL); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        // Recorded before the import starts and cleared once it finishes, so a run that errors out, or a
        // request killed mid-import, stops the screen from starting the import again on every visit.
        $failure = [
            'attemptedAt' => gmdate('c'),
            'message'     => 'The last import did not finish.',
        ];
        update_option(Options::$legacy_import_failure, $failure, false);

        try {
            // Refresh the stored Nova pages first so every matched page has a row to publish.
            $sync = rest_do_request(new WP_REST_Request('POST', '/' . LEADPAGES_NS . '/v1/pages'));
            if ($sync->is_error()) {
                return $this->record_failure($failure, $sync->as_error());
            }

            $nova_ids_by_classic_id = $this->fetch_nova_ids_by_classic_id();
            if (is_wp_error($nova_ids_by_classic_id)) {
                return $this->record_failure($failure, $nova_ids_by_classic_id);
            }

            $pages = $this->import_entries($this->read_legacy_entries(), $nova_ids_by_classic_id);

            $result = [
                'ranAt' => gmdate('c'),
                'pages' => $pages,
            ];
            // Read on the plugin's own screen only, so it is not loaded on every request.
            update_option(Options::$legacy_import, $result, false);
            Options::delete(Options::$legacy_import_failure);

            if (in_array('imported', array_column($pages, 'status'), true)) {
                self::purge_page_caches();
            }

            return new WP_REST_Response($result);
        } finally {
            $this->release_lock();
        }
    }

    /**
     * @param array $failure the attempt recorded when the import started
     * @param WP_Error $error
     * @return WP_Error
     */
    private function record_failure( $failure, $error ) {
        update_option(Options::$legacy_import_failure, array_merge($failure, [ 'message' => $error->get_error_message() ]), false);
        return $error;
    }

    /**
     * Read the pages the old plugin served: one entry per URL path, plus the homepage and 404 page.
     *
     * Mirrors the old plugin's serving rules: a path is served by the oldest `leadpages_slug` meta
     * row holding it, a page of type `nf` (404) is never served at its path, and the homepage and
     * 404 page come from their options. Trashed posts are left out, since the site owner deleted
     * them.
     *
     * @return array[] each [ 'post_id' => int, 'path' => string, 'role' => 'home'|'404'|null, 'classic_id' => string ]
     */
    private function read_legacy_entries() {
        global $wpdb;

        $live_statuses = "p.post_status NOT IN ('trash', 'auto-draft')";

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT pm.post_id, pm.meta_value FROM $wpdb->postmeta pm
                INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
                WHERE pm.meta_key = 'leadpages_slug' AND p.post_type = %s AND $live_statuses
                ORDER BY pm.meta_id ASC",
                self::LEGACY_POST_TYPE
            )
        );
        update_meta_cache('post', array_unique(array_map('intval', array_column($rows, 'post_id'))));

        $entries = [];
        $seen_paths = [];
        foreach ($rows as $row) {
            $path = trim((string) $row->meta_value, '/');
            $key = strtolower($path);
            if ('' === $path || isset($seen_paths[ $key ])) {
                continue;
            }
            $seen_paths[ $key ] = true;
            if ('nf' === get_post_meta((int) $row->post_id, 'leadpages_post_type', true)) {
                continue;
            }
            $entries[] = $this->legacy_entry((int) $row->post_id, $path, null);
        }

        // A list of pairs, not a map: PHP would turn a '404' key into the integer 404.
        $special_pages = [ [ 'home', self::LEGACY_FRONT_OPTION ], [ '404', self::LEGACY_404_OPTION ] ];
        foreach ($special_pages as list($role, $option)) {
            $post_id = (int) get_option($option);
            $post = $post_id > 0 ? get_post($post_id) : null;
            if ($post && self::LEGACY_POST_TYPE === $post->post_type && 'trash' !== $post->post_status) {
                $entries[] = $this->legacy_entry($post_id, '', $role);
            }
        }

        return $entries;
    }

    /**
     * Whether something on the site already answers this old URL: a published landing page at its slug,
     * a WordPress post or page of that name, or a landing page holding the homepage or 404 role.
     *
     * @param array $entry
     * @return bool
     */
    private function is_served( $entry ) {
        if ('' === $entry['path']) {
            return null !== Page::get_by_page_type($entry['role']);
        }
        $slug = self::served_slug($entry['path']);
        return ( '' !== $slug && ( Page::get_by_slug($slug) || Page::is_post_name_collision($slug) ) )
            || self::wordpress_post_at($entry['path']);
    }

    /**
     * The published WordPress post at this old URL, of any public type. Catches what the slug check
     * cannot, e.g. a child page at /offers/black-friday, whose post name is only "black-friday".
     *
     * @param string $path
     * @return \WP_Post|null
     */
    private static function wordpress_post_at( $path ) {
        $post = get_post(url_to_postid(home_url('/' . $path)));
        return $post && 'publish' === $post->post_status && self::LEGACY_POST_TYPE !== $post->post_type ? $post : null;
    }

    private function legacy_entry( $post_id, $path, $role ) {
        $classic_id = (string) get_post_meta($post_id, 'leadpages_page_id', true);
        if (0 === strpos($classic_id, 'cid-')) {
            $classic_id = substr($classic_id, 4);
        }
        return [
            'post_id'    => $post_id,
            'path'       => $path,
            'role'       => $role,
            'classic_id' => $classic_id,
        ];
    }

    /**
     * Map each Classic id to the Nova pages migrated from it.
     *
     * @return array<string, string[]>|WP_Error
     */
    private function fetch_nova_ids_by_classic_id() {
        try {
            $client = new Client(OAuthAuthProvider::for_nova());
            $response = $client->get(
                $this->config->get('NOVA_APP_URL') . '/api/pages',
                [
                    'query'   => [
                        'published'  => 'true',
                        'classicIds' => 'true',
                    ],
                    'timeout' => 20,
                ]
            );
        } catch (AuthException $e) {
            return new WP_Error('leadpages_auth_error', 'User is not logged in?', [ 'status' => 401 ]);
        } catch (HttpException $e) {
            return new WP_Error('leadpages_error', 'Received an error from Leadpages servers', [ 'status' => 502 ]);
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (! isset($body->pages) || ! is_array($body->pages)) {
            return new WP_Error('leadpages_error', 'Unexpected response from Leadpages servers', [ 'status' => 502 ]);
        }

        $map = [];
        foreach ($body->pages as $nova_page) {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            if (empty($nova_page->id) || ! isset($nova_page->classicIds) || ! is_array($nova_page->classicIds)) {
                // A Nova server that predates classic id matching would otherwise report every page
                // as missing, which reads like the wrong account. Fail loudly instead.
                return new WP_Error(
                    'leadpages_error',
                    'Leadpages could not match pages yet. Try again later.',
                    [ 'status' => 502 ]
                );
            }
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            foreach ($nova_page->classicIds as $classic_id) {
                $map[ (string) $classic_id ][] = $nova_page->id;
            }
        }

        return $map;
    }

    /**
     * Publish each legacy entry's Nova page at the slug the new plugin serves for the entry's old URL.
     *
     * Entries matching the same Nova page are published once, since a page has one URL in the new
     * plugin: at the first path, as the homepage when one of them is the homepage (or else the 404
     * page). The other entries for that page are reported as skipped.
     *
     * @param array[] $entries
     * @param array<string, string[]> $nova_ids_by_classic_id
     * @return array[] one result per entry: path, role, title, status, message
     */
    private function import_entries( $entries, $nova_ids_by_classic_id ) {
        $results = [];
        $groups = [];

        // Old URLs the new plugin would serve from the same slug, e.g. /a/b and /a-b. Publishing one of
        // them would show it at the other's old URL too, so none of them is published.
        $posts_by_slug = [];
        foreach ($entries as $index => $entry) {
            $entries[ $index ]['slug'] = '' === $entry['path'] ? '' : self::served_slug($entry['path']);
            if ('' !== $entries[ $index ]['slug']) {
                $posts_by_slug[ $entries[ $index ]['slug'] ][ $entry['post_id'] ] = true;
            }
        }

        foreach ($entries as $entry) {
            $nova_ids = isset($nova_ids_by_classic_id[ $entry['classic_id'] ])
                ? array_values(array_unique($nova_ids_by_classic_id[ $entry['classic_id'] ]))
                : [];

            if ('' !== $entry['slug'] && count($posts_by_slug[ $entry['slug'] ]) > 1) {
                $results[] = $this->result(
                    $entry,
                    null,
                    'failed',
                    "Another old page has a URL that the new plugin treats as the same address (/{$entry['slug']}). Publish these pages under different URLs."
                );
            } elseif ('' === $entry['classic_id']) {
                $results[] = $this->result(
                    $entry,
                    null,
                    'not_found',
                    'The old plugin did not record which page this is'
                );
            } elseif (empty($nova_ids)) {
                $results[] = $this->result(
                    $entry,
                    null,
                    'not_found',
                    'Not found among the published pages in your Leadpages account'
                );
            } elseif (count($nova_ids) > 1) {
                $results[] = $this->result(
                    $entry,
                    null,
                    'skipped',
                    'More than one Leadpages page came from this page. Publish the right one manually.'
                );
            } else {
                $groups[ $nova_ids[0] ][] = $entry;
            }
        }

        // Regular pages first: a homepage or 404 page without an old path takes a new URL, and must not
        // take one an old page needs.
        uasort(
            $groups,
            function ( $group_a, $group_b ) {
                return $this->group_rank($group_a) - $this->group_rank($group_b);
            }
        );

        foreach ($groups as $nova_id => $group) {
            try {
                $group_results = $this->import_group((string) $nova_id, $group, $posts_by_slug);
            } catch (\Throwable $e) {
                // One page failing (e.g. a database error) must not lose the report for the others.
                $group_results = [];
                foreach ($group as $entry) {
                    $group_results[] = $this->result($entry, null, 'failed', 'Could not publish this page');
                }
            }
            foreach ($group_results as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function group_rank( $group ) {
        $roles = array_column($group, 'role');
        if (in_array('home', $roles, true)) {
            return 2;
        }
        return in_array('404', $roles, true) ? 1 : 0;
    }

    /**
     * @param string $nova_id
     * @param array[] $group legacy entries that all match this Nova page
     * @param array<string, array> $posts_by_slug the slugs old URLs are served from
     * @return array[]
     */
    private function import_group( $nova_id, $group, $posts_by_slug ) {
        $roles = array_column($group, 'role');
        $type = in_array('home', $roles, true) ? 'home' : ( in_array('404', $roles, true) ? '404' : null );

        $primary = null;
        foreach ($group as $entry) {
            if ('' !== $entry['path']) {
                $primary = $entry;
                break;
            }
        }
        if (! $primary) {
            $primary = $group[0];
            foreach ($group as $entry) {
                if ($entry['role'] === $type) {
                    $primary = $entry;
                    break;
                }
            }
        }

        $row = Page::get($nova_id);
        $title = $row ? $row->name : null;

        $results = [];
        foreach ($group as $entry) {
            // The primary entry carries the page; the homepage/404 entry it absorbed is covered too.
            if ($entry === $primary || ( '' === $entry['path'] && $entry['role'] === $type )) {
                continue;
            }
            $results[] = $this->result(
                $entry,
                $title,
                'skipped',
                'The same page is also on this site at another URL, and it can be published at one URL only'
            );
        }

        $results[] = $this->publish($primary, $type, $row, $posts_by_slug);
        return $results;
    }

    /**
     * @param string $slug
     * @param object $row the stored Nova page being published
     * @param array<string, array> $posts_by_slug the slugs old URLs are served from
     * @return bool
     */
    private function is_slug_taken( $slug, $row, $posts_by_slug ) {
        if (isset($posts_by_slug[ $slug ]) || Page::is_post_name_collision($slug)) {
            return true;
        }
        $holder = Page::get_by_slug($slug);
        return $holder && (int) $holder->id !== (int) $row->id;
    }

    /**
     * @param array $entry
     * @param string|null $type 'home' | '404' | null
     * @param object|null $row the stored Nova page
     * @param array<string, array> $posts_by_slug the slugs old URLs are served from
     * @return array
     */
    private function publish( $entry, $type, $row, $posts_by_slug ) {
        $result_entry = $type ? array_merge($entry, [ 'role' => $type ]) : $entry;

        if (! $row || $row->deleted_at) {
            return $this->result(
                $result_entry,
                null,
                'not_found',
                'Not found among the published pages in your Leadpages account'
            );
        }

        if ('' !== $entry['path']) {
            $slug = $entry['slug'];
            if ('' === $slug) {
                return $this->result(
                    $result_entry,
                    $row->name,
                    'failed',
                    'The new plugin cannot serve this URL. Publish this page under a new URL.'
                );
            }
        } else {
            // A homepage or 404 page the old plugin served at no path still needs a slug in the new one.
            // Keep it off every old URL, so it cannot show up in place of another old page, and off every
            // slug a WordPress post or another Leadpages page holds, which would refuse the publish.
            $slug = sanitize_title($row->lp_slug);
            if ('' === $slug) {
                return $this->result($result_entry, $row->name, 'failed', 'This page has no usable URL');
            }
            if ($this->is_slug_taken($slug, $row, $posts_by_slug)) {
                $slug = "$slug-$type";
            }
            if ($this->is_slug_taken($slug, $row, $posts_by_slug)) {
                return $this->result(
                    $result_entry,
                    $row->name,
                    'failed',
                    "Another page on this site already uses /$slug. Publish this page under a new URL."
                );
            }
        }
        if (strlen($slug) > 100) {
            return $this->result($result_entry, $row->name, 'failed', 'This URL is longer than 100 characters');
        }

        if ($row->connected) {
            if ($row->wp_slug === $slug && $row->wp_page_type === $type) {
                return $this->result($result_entry, $row->name, 'already_done', '');
            }
            return $this->result($result_entry, $row->name, 'skipped', "Already published at /{$row->wp_slug}");
        }

        $post = '' !== $entry['path'] ? self::wordpress_post_at($entry['path']) : null;
        if ($post) {
            return $this->result(
                $result_entry,
                $row->name,
                'skipped',
                "WordPress post or page {$post->ID} titled `{$post->post_title}` is already published at this URL"
            );
        }

        // A static WordPress front page (show_on_front) is deliberately not checked: the old plugin served its
        // homepage over it, and 1.6.0's own homepage publish ignores it too, so restoring it is the expected result.
        if ($type) {
            $holder = Page::get_by_page_type($type);
            if ($holder && (int) $holder->id !== (int) $row->id) {
                $role = 'home' === $type ? 'homepage' : '404 page';
                return $this->result(
                    $result_entry,
                    $row->name,
                    'skipped',
                    "Your site already has a Leadpages $role: {$holder->name}"
                );
            }
        }

        $request = new WP_REST_Request('PUT', '/' . LEADPAGES_NS . '/v1/pages/' . (int) $row->id);
        $params = [
            'slug'      => $slug,
            'published' => true,
        ];
        if ($type) {
            $params['pageType'] = $type;
        }
        $request->set_body_params($params);
        $response = rest_do_request($request);

        if (204 === $response->get_status()) {
            return $this->result($result_entry, $row->name, 'imported', '');
        }

        $error = $response->as_error();
        $message = is_wp_error($error) ? $error->get_error_message() : 'Could not publish this page';
        $status = 409 === $response->get_status() ? 'skipped' : 'failed';
        return $this->result($result_entry, $row->name, $status, $message);
    }

    /**
     * The slug the new plugin serves when a browser requests this old path: the path encoded the way
     * a browser sends it, then run through the Proxy's own URL handling. Empty when the new plugin
     * cannot serve the path at all (e.g. a path in a non-Latin script, whose encoded bytes the Proxy
     * drops).
     *
     * @param string $path
     * @return string
     */
    private static function served_slug( $path ) {
        // A browser treats these as the start of a query string or fragment, so it never requests them.
        if (preg_match('/[?#]/', $path)) {
            return '';
        }
        $encoded = preg_replace_callback(
            '/[^\x21-\x7E]|["<>`{}]/',
            function ( $matches ) {
                return rawurlencode($matches[0]);
            },
            $path
        );
        return Proxy::slug_for_request_path($encoded);
    }

    private function result( $entry, $title, $status, $message ) {
        return [
            'path'    => $entry['path'],
            'role'    => $entry['role'],
            'title'   => $title,
            'status'  => $status,
            'message' => $message,
        ];
    }

    private function is_connected_to_nova() {
        return 'nova' === Options::get(Options::$platform)
            && ! empty(Options::get(Options::$nova_access_token))
            && ! empty(Options::get(Options::$nova_refresh_token));
    }

    /**
     * Take the import lock with a single INSERT IGNORE, which the database makes exclusive. The stored
     * value is "<time>:<random>", so a request only ever releases its own lock. A lock older than
     * LOCK_TTL is reclaimed with a compare-and-swap on its old value, so only one request wins.
     *
     * @return bool
     */
    private function acquire_lock() {
        global $wpdb;
        $name = Options::$legacy_import_lock;
        $token = time() . ':' . wp_generate_password(12, false);

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                $token
            )
        );
        if (1 !== $inserted) {
            $held = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name)
            );
            if (time() - (int) $held < self::LOCK_TTL) {
                return false;
            }
            $updated = $wpdb->update(
                $wpdb->options,
                [ 'option_value' => $token ],
                [
                    'option_name'  => $name,
                    'option_value' => $held,
                ]
            );
            if (1 !== $updated) {
                return false;
            }
        }

        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        $this->lock_token = $token;
        return true;
    }

    private function release_lock() {
        global $wpdb;
        $wpdb->delete(
            $wpdb->options,
            [
                'option_name'  => Options::$legacy_import_lock,
                'option_value' => $this->lock_token,
            ]
        );
        wp_cache_delete(Options::$legacy_import_lock, 'options');
        wp_cache_delete('alloptions', 'options');
        $this->lock_token = null;
    }

    /**
     * Ask the common page-cache plugins and hosts to drop cached pages, so the 404s served while no
     * plugin was serving the old URLs are not kept after the pages are back.
     */
    private static function purge_page_caches() {
        try {
            $purges = [ 'wp_cache_clear_cache', 'w3tc_flush_all', 'rocket_clean_domain', 'sg_cachepress_purge_cache' ];
            foreach ($purges as $purge) {
                if (function_exists($purge)) {
                    $purge();
                }
            }
            foreach (
                [
                    'litespeed_purge_all',
                    'wpfc_clear_all_cache',
                    'cache_enabler_clear_complete_cache',
                    'breeze_clear_all_cache',
                    'wphb_clear_page_cache',
                ] as $action
            ) {
                do_action($action);
            }
            if (class_exists('WpeCommon')) {
                foreach ([ 'purge_memcached', 'purge_varnish_cache' ] as $purge) {
                    if (method_exists('WpeCommon', $purge)) {
                        call_user_func([ 'WpeCommon', $purge ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // A cache plugin failing to purge must not fail an import that already succeeded.
            unset($e);
        }
    }
}
