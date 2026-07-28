<?php

namespace Leadpages\rest\pages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use WP_REST_Response;
use Leadpages\Cache;
use Leadpages\models\Page;
use Leadpages\models\Options;
use Leadpages\models\exceptions\DatabaseError;
use Leadpages\rest\pages\Schema;
use Leadpages\providers\http\Client;
use Leadpages\providers\http\auth\OAuthAuthProvider;
use Leadpages\providers\http\exceptions\HttpException;
use Leadpages\providers\config\Config;
use Leadpages\providers\http\exceptions\AuthException;
use Leadpages\providers\Utils;

/**
 * WordPress controller class for the leadpages/v1/pages collection.
 *
 * Requests require authenticated WordPress users with the manage_options capability.
 * This is enforced via permission_callback in register_routes.
 *
 * @see Schema for the request schema
 */
class Controller extends \WP_REST_Controller {

    use Utils;

    /** @var Client */
    private $client;
    /** @var Config */
    private $config;

    public function __construct() {
        // The classic pages sync is an OAuth-authenticated backend, so the client authenticates
        // and refreshes tokens through the classic OAuth provider.
        $this->client = new Client(OAuthAuthProvider::for_classic());
        $this->config = Config::get_instance();
    }

    /**
     * Register the routes for the pages controller
     */
    public function register_routes() {
        $version = '1';
        $namespace = LEADPAGES_NS . '/v' . $version;
        $base = 'pages';

        register_rest_route(
            $namespace,
            '/' . $base,
            [
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'sync_items' ],
                    'permission_callback' => [ $this, 'sync_items_permissions_check' ],
                ],
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'get_items_permissions_check' ],
                    'args'                => Schema::get_collection_params(),
                ],
            ]
        );
        register_rest_route(
            $namespace,
            '/' . $base . '/(?P<id>\d+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'get_item' ],
                    'permission_callback' => [ $this, 'get_item_permissions_check' ],
                    'args'                => Schema::get_item_args(),
                ],
                [
                    'methods'             => 'PUT',
                    'callback'            => [ $this, 'update_item' ],
                    'permission_callback' => [ $this, 'update_item_permissions_check' ],
                    'args'                => Schema::update_item_args(),
                ],
            ]
        );
        register_rest_route(
            $namespace,
            '/' . $base . '/(?P<id>\d+)/cache',
            [
                [
                    'methods'             => 'DELETE',
                    'callback'            => [ $this, 'clear_item_cache' ],
                    'permission_callback' => [ $this, 'clear_item_cache_permissions_check' ],
                    'args'                => Schema::get_item_args(),
                ],
            ]
        );
    }

    /**
     * Check if a given request has access to get pages
     *
     * @param WP_REST_Request $request
     * @return WP_Error|bool
     */
    public function get_items_permissions_check( $request ) {
        return current_user_can('manage_options');
    }

    /**
     * Return a list of pages with pagination, ordering, filtering, etc.
     *
     * The response schema is
     *  [
     *    "data" => <list of pages>,
     *    "meta" => [
     *      "total" => int, //  total pages in the database
     *      "page" => int,
     *      "perPage" => int
     *    ]
     *  ]
     *
     * @see Page for the data to expect in the response
     * @see Schema for request schema
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function get_items( $request ) {
        $params = $request->get_query_params();
        $page = isset($params['page']) ? $params['page'] : 1;
        $per_page = isset($params['perPage']) ? $params['perPage'] : 10;
        $status = isset($params['status']) ? $params['status'] : 'all';
        $order_by = isset($params['orderBy']) ? $params['orderBy'] : 'date';
        $order = isset($params['direction']) ? $params['direction'] : 'desc';
        $search = isset($params['search']) ? $params['search'] : '';

        $connected = null;
        if ('published' === $status) {
            $connected = true;
        } elseif ('unpublished' === $status) {
            $connected = false;
        }

        try {
            [$items, $total] = Page::get_many($page, $per_page, $connected, $order_by, $order, $search);
        } catch (DatabaseError $e) {
            return new \WP_Error('lp_wp_error', 'Could not retrieve pages', [ 'status' => 500 ]);
        } catch (\Exception $e) {
            $this->debug('Unknown error when getting pages', __METHOD__);
            return new \WP_Error('lp_error', 'Something went wrong', [ 'status' => 500 ]);
        }

        $response = [
            'data' => $items,
            'meta' => [
                'total'   => $total,
                'page'    => $page,
                'perPage' => $per_page,
            ],
        ];

        return new WP_REST_Response($response, 200);
    }

    /**
     * Check if a given request has access to get a specific page
     *
     * @param WP_REST_Request $request
     * @return WP_Error|bool
     */
    public function get_item_permissions_check( $request ) {
        return $this->get_items_permissions_check($request);
    }

    /**
     * Return a specific page by its ID.
     *
     * The response schema is is just the page object
     *
     * @see Page for the data to expect in the response
     * @see Schema for request schema
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function get_item( $request ) {
        $id = $request->get_param('id');

        try {
            $item = Page::get($id);
            if (!$item) {
                return new \WP_Error('not_found', 'Page not found', [ 'status' => 404 ]);
            }
        } catch (DatabaseError $e) {
            return new \WP_Error('lp_wp_error', 'Could not retrieve page', [ 'status' => 500 ]);
        } catch (\Exception $e) {
            $this->debug('Unknown error when getting page', __METHOD__);
            return new \WP_Error('lp_error', 'Something went wrong', [ 'status' => 500 ]);
        }

        return new WP_REST_Response($item, 200);
    }

    /**
     * Check if a given request has access to update a specific page
     *
     * @param WP_REST_Request $request
     * @return WP_Error|bool
     */
    public function update_item_permissions_check( $request ) {
        return $this->get_items_permissions_check($request);
    }

    /**
     * Update a specific page's WordPress properties only. We do not expose the
     * ability to page data that is synced from Leadpages.
     *
     * Expect no response beyond a 204 status
     *
     * @see Schema for the request schema and what data can be modified
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function update_item( $request ) {
        $id = $request->get_param('id');
        $slug = $request->get_param('slug');
        $connected = $request->get_param('published');
        $type = $request->get_param('pageType');
        $replace = $request->get_param('replace');

        try {
            $page = Page::get($id);
            if (!$page) {
                return new \WP_Error('not_found', 'Page not found', [ 'status' => 404 ]);
            }

            // The page currently holding the target slug that we will hand the slug off from
            // (replace path), if any. Resolved during the collision check below.
            $handoff_page = null;

            if ($slug) {
                // if the slug is being updated we need to check for collisions with other landing pages
                // or WordPress posts. if there is a collision, we notify the user with the specific name
                // of the page or post in question
                $conflicting_post = Page::is_post_name_collision($slug);
                if (count($conflicting_post) > 0) {
                    $i = $conflicting_post[0]->ID;
                    $t = $conflicting_post[0]->post_title;
                    $message = "WordPress post or page $i titled `$t` is already published under this slug";
                    return new \WP_Error('name_conflict', $message, [ 'status' => 409 ]);
                }

                $conflicting_page = Page::get_by_slug($slug);
                if ($conflicting_page && $conflicting_page->id !== $page->id) {
                    // Another landing page (possibly on the other platform) holds this slug. Unless the
                    // caller opts to replace it, return a distinct code so the UI can offer the hand-off.
                    if (! $replace) {
                        $t = $conflicting_page->name;
                        $message = "Landing page `$t` is already published under this slug";
                        return new \WP_Error(
                            'slug_taken_by_page',
                            $message,
                            [
                                'status'          => 409,
                                'conflictingName' => $conflicting_page->name,
                                'slug'            => $slug,
                            ]
                        );
                    }

                    // Defer the actual unpublish + re-bind to the atomic block below so they commit
                    // together (the URL is preserved).
                    $handoff_page = $conflicting_page;
                }
            }

            $data = [
                'wp_slug'      => $slug,
                'connected'    => $connected,
                'wp_page_type' => $type,
            ];

            // Hand-off + (re)bind, ordered and compensated so a mid-flight failure cannot strand the
            // URL. wp_slug is UNIQUE, so the page currently at the slug must be unpublished before the
            // target can take it; if the subsequent re-bind then fails (e.g. a concurrent publish
            // claimed the slug), we restore the handed-off page so its URL is never left unbound. (An
            // explicit DB transaction is intentionally avoided: WordPress's test harness already holds
            // an open transaction and MySQL cannot nest them.)
            if ($handoff_page) {
                Page::update(
                    (int) $handoff_page->id,
                    [
                        'connected'    => false,
                        'wp_slug'      => null,
                        'wp_page_type' => null,
                    ]
                );
            }

            try {
                Page::update($id, $data);
            } catch (\Throwable $rebind_error) {
                if ($handoff_page) {
                    // Compensate: give the slug back to the page we just unpublished.
                    try {
                        Page::update(
                            (int) $handoff_page->id,
                            [
                                'connected'    => true,
                                'wp_slug'      => $slug,
                                'wp_page_type' => $handoff_page->wp_page_type,
                            ]
                        );
                    } catch (\Throwable $restore_error) {
                        $this->debug(
                            'Failed to restore handed-off page after a re-bind failure: '
                                . $restore_error->getMessage(),
                            __METHOD__
                        );
                    }
                }
                // A concurrent publish may have claimed the slug between the collision check and this
                // write. Surface the 409 the UI already understands rather than a generic 500.
                $current = Page::get_by_slug($slug);
                if ($current && (int) $current->id !== (int) $id) {
                    return new \WP_Error(
                        'slug_taken_by_page',
                        'That slug was just taken by another page',
                        [
                            'status' => 409,
                            'slug'   => $slug,
                        ]
                    );
                }
                throw $rebind_error;
            }

            // Clear the handed-off page's cache only after the re-bind succeeds, so a failed hand-off
            // never leaves its URL both uncached and unbound (serving nothing).
            if ($handoff_page) {
                Cache::delete($this->page_cache_key($handoff_page));
            }
        } catch (DatabaseError $e) {
            return new \WP_Error('lp_wp_error', 'Could not update page', [ 'status' => 500 ]);
        } catch (\Exception $e) {
            $this->debug('Unknown error when updating page', __METHOD__);
            return new \WP_Error('lp_error', 'Something went wrong', [ 'status' => 500 ]);
        }

        // When the page is being unpublished in WordPress or the slug that the page is
        // connected under is changed, any cached data for that page needs to be cleared.
        // Otherwise the page would continue to be served under the old slug.
        if (false === $connected || $slug !== $page->wp_slug) {
            Cache::delete($this->page_cache_key($page));
        }

        return new WP_REST_Response(null, 204);
    }

    /**
     * Build the serving cache key for a page, honoring its platform. Nova pages are keyed with the
     * platform and nova_page_id so the key matches the one the Proxy serves under.
     *
     * @param object $page
     * @return string
     */
    private function page_cache_key( $page ) {
        if (isset($page->platform) && 'nova' === $page->platform) {
            return Cache::page_key($page->wp_slug, 'nova', $page->nova_page_id);
        }
        return Cache::page_key($page->wp_slug);
    }

    /**
     * Check if a given request has access to sync pages. The user must be logged in with an access token
     * set in the options table to be able to sync pages and return a 401 immediately if they do not.
     *
     * @return WP_Error|bool
     */
    public function sync_items_permissions_check() {
        if (! current_user_can('manage_options')) {
            return false;
        }

        if (! $this->is_connected_to_active_platform()) {
            return new \WP_Error('no_token', 'User is not logged in?', [ 'status' => 401 ]);
        }

        return true;
    }

    /**
     * Whether the user is connected to whichever platform this install is using. Nova connections
     * are tracked by the Nova tokens; Classic by the classic tokens.
     *
     * @return bool
     */
    private function is_connected_to_active_platform() {
        if ('nova' === Options::get(Options::$platform)) {
            return ! empty(Options::get(Options::$nova_access_token))
                && ! empty(Options::get(Options::$nova_refresh_token));
        }
        return $this->is_user_logged_into_plugin();
    }

    /**
     * Sync landing page assets from Leadpages to WordPress. After the sync process, the state
     * of the users active pages locally will match that on the Leadpages platform.
     *
     * Note: To make this process as efficient as possible, the only pages that will be updated
     *  are those that have been modified on the Leadpages platform since the last time pages were
     *  sync'd (if they are a new user, all pages are sync'd). The last sync date will be updated
     *  once the process completes successfully.
     *
     * Expect no  successful response beyond a 204 status
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function sync_items() {
        if ('nova' === Options::get(Options::$platform)) {
            return $this->sync_nova_items();
        }

        $last_page_sync = Options::get(Options::$last_page_sync_date);
        $this->debug('Last page sync: ' . $last_page_sync);

        $visible_pages = [
            'order_by' => '-updated',
            'limit'    => 100,
        ];
        $hidden_pages = [
            'visible[ne]' => 1, // non-visible pages such as splittest variations
            'limit'       => 100,
        ];
        if ($last_page_sync) {
            // this is an undocumented filter that can be used to cut down on the number of pages synced
            // this is a performance optimization for users with a lot of pages
            $visible_pages['updated[gt]'] = $last_page_sync;
            $hidden_pages['updated[gt]'] = $last_page_sync;
        }

        try {
            $this->sync_updated_pages($visible_pages);
            $this->sync_updated_pages($hidden_pages);
            $this->sync_deleted_pages($last_page_sync);
            Options::set(Options::$last_page_sync_date, gmdate('Y-m-d H:i:s.uP'));
        } catch (AuthException $e) {
            return new \WP_Error('leadpages_auth_error', 'User is not logged in?', [ 'status' => 400 ]);
        } catch (HttpException $e) {
            return new \WP_Error('leadpages_error', 'Received an error from Leadpages servers', [ 'status' => 500 ]);
        } catch (DatabaseError $e) {
            return new \WP_Error('lp_wp_error', 'Could not sync pages', [ 'status' => 500 ]);
        } catch (\Throwable $e) {
            $this->debug('Error syncing pages: ' . $e->getMessage(), __METHOD__);
            return new \WP_Error('lp_sync_error', 'Unknown error syncing pages', [ 'status' => 500 ]);
        }

        return new WP_REST_Response(null, 204);
    }

    /**
     * Fetch updates to the users pages
     *
     * @param array $query an array of query params to use when fetching pages
     * @return void
     * @throws Exception
     */
    private function sync_updated_pages( $query ) {
        $cursor = 0;
        $has_more = false;
        do {
            $query['cursor'] = $cursor;
            $response = $this->fetch_pages($query);

            $data = $response['body'];
            $items = $data->_items;
            $meta = $data->_meta;
            foreach ($items as $page_raw) {
                $uuid = $page_raw->_meta->id;
                $page = Page::get($uuid);
                if (! $page) {
                    $this->debug('Creating page for uuid ' . $uuid);
                    Page::create($page_raw);
                } else {
                    $this->debug('Updating page with uuid ' . $uuid);
                    Page::update($uuid, $page_raw);

                    $is_split_test_variation = isset($page_raw->content->splitTest) &&
                        ! is_null($page_raw->content->splitTest);
                    $is_connected = $page->connected;
                    $has_slug_to_clear = ! is_null($page->wp_slug);
                    // Pages that are variations of a split test are hidden from the UI in Leadpages
                    // main application and no longer served. We copy that behavior in the plugin
                    // as well so clear the cache so pages are no longer served.
                    if ($is_split_test_variation && $is_connected && $has_slug_to_clear) {
                        $this->debug('Clearing the cache for page published at /' . $page->wp_slug);
                        Cache::delete(Cache::page_key($page->wp_slug));
                        Page::update(
                            $uuid,
                            [
                                'connected'    => false,
                                'wp_slug'      => null,
                                'wp_page_type' => null,
                            ]
                        );
                    }

                    // If the page was unpublished since the last sync, we need to update the connected status to false.
                    if (is_null($page_raw->content->currentEdition)) {
                        $this->debug('Setting page with uuid ' . $uuid . ' to unconnected.');
                        Page::update(
                            $uuid,
                            [
                                'connected'    => false,
                                'wp_slug'      => null,
                                'wp_page_type' => null,
                            ]
                        );
                        Cache::delete(Cache::page_key($page->wp_slug));
                    }
                }
            }

            $has_more = $meta->total > $meta->cursor;
            $cursor = $meta->cursor;
        } while ($has_more);
    }

    /**
     * Update the current status of the users pages that have been deleted since the last time
     * pages were sync'd. If this is the first time the process is run, no pages will be fetched
     * and the function will return immediately (don't care about their already deleted pages).
     *
     * @param string|null $last_sync_date last time pages were synced
     * @return void
     * @throws Exception
    */
    private function sync_deleted_pages( $last_sync_date ) {
        // we only need to sync deleted pages if there were pages synced in the first place
        if (! $last_sync_date) {
            return;
        }
        // this query will return all deleted pages ordered by delete date
        // we will only consider pages that are deleted after $last_sync_date
        $query = [
            'deleted' => 1,
            'limit'   => 100,
        ];

        $last_sync_time = new \DateTime($last_sync_date);
        $cursor = 0;
        $has_more = false;
        $stop_sync = false;
        do {
            $query['cursor'] = $cursor;
            $response = $this->fetch_pages($query);

            $data = $response['body'];
            $items = $data->_items;
            $meta = $data->_meta;
            foreach ($items as $page_raw) {
                $uuid = $page_raw->_meta->id;
                $delete_time = new \DateTime($page_raw->_meta->deleted);
                // pages are ordered such that once we encounter one page that was deleted
                // before our last sync date, all of the others will be too, so stop here
                if ($last_sync_time > $delete_time) {
                    $this->debug('No more pages to delete since last update');
                    $stop_sync = true;
                    break;
                }

                $this->debug('Marking page ' . $uuid . ' as deleted');
                Page::update($uuid, $page_raw);

                // If the page that is being deleted was connected in WordPress the
                // cache needs to be cleared so that no longer gets served.
                $page = Page::get($uuid);
                if ($page && $page->connected && $page->wp_slug) {
                    $this->debug('Clearing the cache for page published at /' . $page->wp_slug);
                    Cache::delete(Cache::page_key($page->wp_slug));
                    Page::update(
                        $uuid,
                        [
                            'connected'    => false,
                            'wp_slug'      => null,
                            'wp_page_type' => null,
                        ]
                    );
                }
            }

            $has_more = $meta->total > $meta->cursor;
            $cursor = $meta->cursor;
        } while ($has_more && ! $stop_sync);
    }

    /**
     * Fetch landing pages from foundry.
     *
     * See Scribe for information about what a page looks like
     *
     * @param array $query_array query parameters to be used in the request
     * @param bool $retry
     * @return object
     * @throws HttpException
     */
    private function fetch_pages( $query_array, $retry = true ) {
        try {
            // The client authenticates the request through its OAuth provider.
            $response = $this->client->get(
                $this->config->get('PAGES_API_URL'),
                [
                    'query'   => $query_array,
                    'timeout' => 10,
                ]
            );
            $response['body'] = json_decode(wp_remote_retrieve_body($response));
        } catch (HttpException $e) {
            $code = wp_remote_retrieve_response_code($e->response);
            $is_auth_error = 401 === $code || 403 === $code;
            if ($retry && ! $is_auth_error) {
                // retry one time
                $response = $this->fetch_pages($query_array, false);
            } else {
                // raise the error to be caught by caller
                throw $e;
            }
        }

        return $response;
    }

    /**
     * Sync a Nova (new Leadpages) account's published pages into the local table. Unlike the
     * Classic sync, Nova returns the full set of published pages in a single call (no cursor), so
     * deletions are reconciled by diffing the returned ids against the stored Nova rows. Analytics
     * are fetched best-effort afterwards.
     *
     * @return WP_Error|WP_REST_Response
     */
    private function sync_nova_items() {
        $client = new Client(OAuthAuthProvider::for_nova());

        try {
            $pages = $this->fetch_nova_pages($client);

            $seen_ids = [];
            foreach ($pages as $nova_page) {
                if (empty($nova_page->id)) {
                    continue;
                }
                $seen_ids[] = $nova_page->id;

                $existing = Page::get($nova_page->id);
                if (! $existing) {
                    Page::create($nova_page, 'nova');
                } else {
                    Page::update($nova_page->id, $nova_page, 'nova');
                }
            }

            // Defense-in-depth against mass-unpublish: if the sync returned zero ids while stored
            // Nova rows still exist, treat it as suspect (a genuine "unpublished everything" is rare
            // and self-heals — an unpublished page already 404s at serve time via NovaPageSource) and
            // skip deletion reconciliation rather than tombstone every live page. Reconcile only when
            // we have at least one returned id, or when there is nothing stored to lose.
            $stored_nova_pages = Page::get_nova_pages();
            if (empty($seen_ids) && ! empty($stored_nova_pages)) {
                $this->debug(
                    'Nova sync returned zero pages while ' . count($stored_nova_pages)
                        . ' Nova rows exist; skipping deletion reconcile to avoid mass-unpublish',
                    __METHOD__
                );
            } else {
                $this->reconcile_deleted_nova_pages($seen_ids);
            }
            $this->sync_nova_analytics($client);

            Options::set(Options::$last_page_sync_date, gmdate('Y-m-d H:i:s.uP'));
        } catch (AuthException $e) {
            return new \WP_Error('leadpages_auth_error', 'User is not logged in?', [ 'status' => 400 ]);
        } catch (HttpException $e) {
            return new \WP_Error('leadpages_error', 'Received an error from Leadpages servers', [ 'status' => 500 ]);
        } catch (DatabaseError $e) {
            return new \WP_Error('lp_wp_error', 'Could not sync pages', [ 'status' => 500 ]);
        } catch (\Throwable $e) {
            $this->debug('Error syncing Nova pages: ' . $e->getMessage(), __METHOD__);
            return new \WP_Error('lp_sync_error', 'Unknown error syncing pages', [ 'status' => 500 ]);
        }

        return new WP_REST_Response(null, 204);
    }

    /**
     * Fetch the published Nova pages in a single call.
     *
     * @param Client $client a Nova-authenticated client
     * @return array list of Nova page objects
     * @throws HttpException
     */
    private function fetch_nova_pages( $client ) {
        $response = $client->get(
            $this->config->get('NOVA_APP_URL') . '/api/pages',
            [
                'query'   => [ 'published' => 'true' ],
                'timeout' => 10,
            ]
        );
        $body = json_decode(wp_remote_retrieve_body($response));

        // A 200 whose body is not the expected { pages: [...] } shape (empty/truncated body, JSON
        // drift, a rate-limited 200) must NOT be treated as "zero published pages" — that would make
        // reconcile_deleted_nova_pages tombstone every live page. Treat it as an error so the sync
        // aborts and the stored rows are left untouched.
        if (! isset($body->pages) || ! is_array($body->pages)) {
            throw new \RuntimeException('Unexpected /api/pages response shape from Nova');
        }

        return $body->pages;
    }

    /**
     * Disconnect and mark deleted any stored Nova pages that were not returned by the latest sync
     * (unpublished or deleted upstream), clearing their cache so they stop being served.
     *
     * @param string[] $seen_ids the nova_page_ids returned by the latest sync
     * @return void
     * @throws DatabaseError
     */
    private function reconcile_deleted_nova_pages( $seen_ids ) {
        $stored = Page::get_nova_pages();
        foreach ($stored as $page) {
            if (in_array($page->nova_page_id, $seen_ids, true)) {
                continue;
            }

            $this->debug('Marking Nova page ' . $page->nova_page_id . ' as no longer published');
            if ($page->connected && $page->wp_slug) {
                Cache::delete($this->page_cache_key($page));
            }
            Page::update(
                $page->uuid,
                [
                    'deleted_at'   => gmdate('Y-m-d H:i:s'),
                    'connected'    => false,
                    'wp_slug'      => null,
                    'wp_page_type' => null,
                ]
            );
        }
    }

    /**
     * Populate the analytics columns for Nova pages from the org-wide analytics endpoint. This is
     * best-effort: any failure or missing pageId leaves the columns empty so the UI can hide them.
     *
     * NOTE(HP-2528): analytics contract - GET {NOVA_APP_URL}/api/analytics/pages?range=30d ->
     * { pages: [{ pageId, uniqueVisitors, conversionRate (fraction 0..1), views, ... }] }. Matched
     * by pageId === nova_page_id. Kept isolated here so the shape can be reconciled if it changes.
     *
     * @param Client $client a Nova-authenticated client
     * @return void
     */
    private function sync_nova_analytics( $client ) {
        try {
            $response = $client->get(
                $this->config->get('NOVA_APP_URL') . '/api/analytics/pages',
                [
                    'query'   => [ 'range' => '30d' ],
                    'timeout' => 10,
                ]
            );
            $body = json_decode(wp_remote_retrieve_body($response));
            $metrics = isset($body->pages) && is_array($body->pages) ? $body->pages : [];

            // The Nova analytics API returns camelCase JSON properties (pageId, uniqueVisitors,
            // conversionRate) — an external contract we read as-is and cannot rename to snake_case.
            // phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
            foreach ($metrics as $metric) {
                if (empty($metric->pageId)) {
                    continue;
                }

                $data = [];
                if (isset($metric->uniqueVisitors)) {
                    $data['visitors'] = (int) $metric->uniqueVisitors;
                }
                if (isset($metric->conversionRate)) {
                    // The Nova API returns conversionRate as a fraction (0..1). The conversion_rate
                    // column stores that fraction as-is; the frontend's formatPercentage() multiplies
                    // by 100 for display (matching the Classic column), so we must NOT pre-multiply.
                    $data['conversion_rate'] = (float) $metric->conversionRate;
                }
                if (isset($metric->views)) {
                    $data['views'] = (int) $metric->views;
                }

                if ($data) {
                    // Cast to string so Page::update always matches on the `uuid` column (which for
                    // Nova rows equals nova_page_id). If pageId ever arrived as a JSON number,
                    // is_int() would route the match to the integer primary key and write analytics
                    // onto an unrelated row (possibly a Classic page).
                    Page::update((string) $metric->pageId, $data);
                }
            }
            // phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        } catch (\Throwable $e) {
            // Analytics are non-essential; degrade gracefully so a sync still succeeds without them.
            $this->debug('Nova analytics unavailable, skipping: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Check if a given request can clear the cache for a page.
     *
     * @param WP_REST_Request $request
     * @return WP_Error|bool
     */
    public function clear_item_cache_permissions_check() {
        return current_user_can('manage_options');
    }

    /**
     * Clear the cache for a specific page by it's id. Returns a successful
     * response in all cases that a cached value no longer exists, including
     * unpublished pages that have no cached values to begin with.
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function clear_item_cache( $request ) {
        $id = $request->get_param('id');

        try {
            $item = Page::get($id);
            if (!$item) {
                // If there is no page than as far as this endpoint is concerned,
                // the cache is clear and we are good to go.
                return new WP_REST_Response(null, 204);
            }
        } catch (DatabaseError $e) {
            $this->debug($e);
            return new \WP_Error('lp_wp_error', 'Failed to clear cache. Please try again.', [ 'status' => 500 ]);
        } catch (\Exception $e) {
            $this->debug("Unknown error when getting page: $e", __METHOD__);
            return new \WP_Error('lp_error', 'Something went wrong', [ 'status' => 500 ]);
        }

        if ($item->wp_slug) {
            Cache::delete($this->page_cache_key($item));
        }

        return new WP_REST_Response(null, 204);
    }
}
