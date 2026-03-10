<?php

namespace Leadpages\rest\pages;

defined('ABSPATH') || die('No script kiddies please!');

/**
 * Request schema definition for the leadpages/v1/pages controller REST API
 */
class Schema {
    /**
     * The schema for getting a specific page from the leadpages collection by its id.
     * This schema is also used to validate the request to clear the page cache.
     *
     * @return array
     */
    public static function get_item_args() {
        return [
            'id' => [
                'description'       => 'The id of the page. Not the Leadpages uuid of the landing page.',
                'type'              => 'integer',
                'validate_callback' => 'rest_validate_request_arg',
                'required'          => true,
            ],
        ];
    }

    /**
     * The schema for the query params for the pages collection
     *
     * Replicates WP REST api pagination using "page" and "perPage".
     *
     * @see https://developer.wordpress.org/rest-api/using-the-rest-api/pagination/
     * @return array
     */
    public static function get_collection_params() {
        return [
            'page'      => [
                'description'       => 'Current page of the landing page collection.',
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'perPage'   => [
                'description'       => 'Maximum number of items to be returned in a result set.',
                'type'              => 'integer',
                'default'           => 10,
                'minimum'           => 1,
                'maximum'           => 100,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'status'    => [
                'description'       => 'Filter by published status. Either "all", "published" or "unpublished".',
                'type'              => 'string',
                'enum'              => [ 'all', 'published', 'unpublished' ],
                'default'           => 'all',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'orderBy'   => [
                'description'       => 'Order pages by date or name.',
                'type'              => 'string',
                'enum'              => [ 'date', 'name' ],
                'default'           => 'date',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'direction' => [
                'description'       => 'Specify the direction of the order. Either "asc" or "desc".',
                'type'              => 'string',
                'enum'              => [ 'asc', 'desc' ],
                'default'           => 'desc',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'search'    => [
                'description'       => 'Limit results to exact matches by page name.',
                'type'              => 'string',
                'maxLength'         => 200,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * The schema for updating a specific page in the pages collection.
     * Only the slug the page is connected under, the page type and published status can be updated through
     * our endpoints. All other page data should be considered read only.
     *
     * @return array
     */
    public static function update_item_args() {
        return [
            'id'        => [
                'description'       => 'The unique identifier for the page within WordPress.',
                'type'              => 'integer',
                'validate_callback' => 'rest_validate_request_arg',
                'required'          => true,
            ],
            'slug'      => [
                'description'       => 'The slug that the page should be served at within WordPress.',
                'type'              => [ 'string', 'null' ],
                'validate_callback' => function ( $param ) {
                    if (is_null($param)) {
                        return true;
                    } elseif (!is_string($param)) {
                        return new \WP_Error(
                            'rest_invalid_param',
                            /* translators: %s: slug */
                            sprintf(esc_html__('%s is not a string'), $param),
                            [ 'status' => 400 ]
                        );
                    } elseif (strlen($param) === 0 || strlen($param) > 100) {
                        return new \WP_Error(
                            'rest_invalid_param',
                            'Provided slug must not be empty or more than 100 characters long',
                            [ 'status' => 400 ]
                        );
                    } elseif (preg_match('/[:"?#\[\]@!$&\'()*+,;= ]/', $param)) {
                        return new \WP_Error(
                            'rest_invalid_param',
                            'Provided slug contains invalid characters',
                            [ 'status' => 400 ]
                        );
                    } else {
                        return true;
                    }
                },
                'sanitize_callback' => function ( $param ) {
                    if (is_null($param)) {
                        return $param;
                    }
                    return sanitize_title($param);
                },
            ],
            'pageType'  => [
                'description'       => 'A special type that the page should be served as. Either 404 | home | welcome',
                'type'              => [ 'string', 'null' ],
                'validate_callback' => function ( $param ) {
                    return in_array($param, [ '404', 'home', 'welcome', null ], true);
                },
            ],
            'published' => [
                'description'       => 'Whether the page should be published.',
                'type'              => 'boolean',
                'validate_callback' => 'rest_validate_request_arg',
                'required'          => true,
            ],
        ];
    }
}
