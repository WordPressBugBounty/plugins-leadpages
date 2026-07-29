<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\models\Options;
use Leadpages\providers\config\Config;

/**
 * The single source of truth for which pop-up is embedded and where it is allowed to appear.
 *
 * Three scope modes drive both render surfaces:
 *   - MODE_WORDPRESS (default): the customer's own WordPress pages only. Proxied Leadpages pages are
 *     excluded, preserving the pre-feature behavior for installs that never configured a scope.
 *   - MODE_WORDPRESS_AND_LEADPAGES: WordPress pages plus every proxied Leadpages page the plugin
 *     serves (both Classic and new Leadpages; intentionally platform-agnostic).
 *   - MODE_SPECIFIC: only the selected slugs, matched the same way on both surfaces.
 *
 * The scope is keyed by slug so a "specific pages" selection covers WordPress pages/posts and proxied
 * Leadpages pages alike (a proxied page is matched by its request slug).
 */
class PopupScope {

    /** All WordPress pages, no proxied Leadpages pages. The default when nothing is stored. */
    public const MODE_WORDPRESS = 'wordpress';

    /** WordPress pages plus all proxied Leadpages pages (both platforms). */
    public const MODE_WORDPRESS_AND_LEADPAGES = 'wordpress_and_leadpages';

    /** Only the selected slugs. */
    public const MODE_SPECIFIC = 'specific';

    /**
     * The id of the pop-up selected to be embedded, or null when none is selected.
     *
     * @return string|null
     */
    public static function selected_popup_id(): ?string {
        $popup_id = Options::get(Options::$nova_popup_id);
        return empty($popup_id) ? null : (string) $popup_id;
    }

    /**
     * The normalized scope: [ 'mode' => string, 'slugs' => string[] ]. An unset or malformed option
     * resolves to MODE_WORDPRESS so the pop-up keeps its original WordPress-only behavior.
     *
     * @return array{mode: string, slugs: string[]}
     */
    public static function get(): array {
        $raw = Options::get(Options::$nova_popup_scope);
        if (is_array($raw) && isset($raw['mode'])) {
            if (self::MODE_SPECIFIC === $raw['mode']) {
                $slugs = isset($raw['slugs']) && is_array($raw['slugs']) ? array_values($raw['slugs']) : [];
                return [
                    'mode'  => self::MODE_SPECIFIC,
                    'slugs' => array_map('strval', $slugs),
                ];
            }
            if (self::MODE_WORDPRESS_AND_LEADPAGES === $raw['mode']) {
                return [
                    'mode'  => self::MODE_WORDPRESS_AND_LEADPAGES,
                    'slugs' => [],
                ];
            }
        }
        return [
            'mode'  => self::MODE_WORDPRESS,
            'slugs' => [],
        ];
    }

    /**
     * Whether the pop-up is allowed on the current WordPress front-end request. Both WordPress-only
     * and WordPress-plus-Leadpages modes enqueue on every WordPress page; the specific mode resolves
     * the queried object's slug and matches it, so non-singular views (archives, search, home without
     * a page) never match a specific scope.
     *
     * @return bool
     */
    public static function allows_current_request(): bool {
        $scope = self::get();
        if (self::MODE_SPECIFIC !== $scope['mode']) {
            return true;
        }

        $queried_id = get_queried_object_id();
        if (! $queried_id) {
            return false;
        }

        $slug = get_post_field('post_name', $queried_id);
        return $slug && in_array($slug, $scope['slugs'], true);
    }

    /**
     * Whether the pop-up is allowed on a proxied page served at the given slug. Only the
     * WordPress-plus-Leadpages mode injects on all proxied pages (every platform); the specific mode
     * injects on selected slugs; the default WordPress-only mode never injects on proxied pages.
     *
     * @param string $slug the slug of the proxied page being served
     * @return bool
     */
    public static function allows_slug( string $slug ): bool {
        $scope = self::get();
        if (self::MODE_WORDPRESS_AND_LEADPAGES === $scope['mode']) {
            return true;
        }
        if (self::MODE_SPECIFIC === $scope['mode']) {
            return in_array($slug, $scope['slugs'], true);
        }
        return false;
    }

    /**
     * Build the public, async pop-up embed script URL for a pop-up id. The URL is versioned upstream.
     *
     * @param string $popup_id
     * @return string
     */
    public static function embed_src( string $popup_id ): string {
        $base = untrailingslashit(Config::get_instance()->get('NOVA_APP_URL'));
        return $base . '/api/popup/' . rawurlencode($popup_id) . '/embed.js';
    }
}
