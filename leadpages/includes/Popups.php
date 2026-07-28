<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\Utils;
use Leadpages\providers\config\Config;
use Leadpages\models\Options;

/**
 * Injects a selected Nova (new Leadpages) pop-up embed script site-wide on the front end.
 *
 * The embed script is public and loads asynchronously. It is enqueued on normal WordPress front-end
 * requests via wp_enqueue_scripts. Proxied landing pages exit() on init (long before the enqueue
 * hook runs), so this only affects the customer's own WordPress pages, which is the intended surface
 * for a site-wide pop-up.
 */
class Popups {

    use Utils;

    /** @var string script handle for the pop-up embed */
    private const HANDLE = 'leadpages-nova-popup';

    /** @var Config */
    private $config;

    public function __construct() {
        $this->config = Config::get_instance();
    }

    /**
     * Enqueue the selected pop-up's embed script if one has been chosen. No output otherwise.
     * Enqueued (not printed directly) so it goes through the standard WordPress script pipeline
     * and passes plugin-check's NonEnqueuedScript rule.
     *
     * @return void
     */
    public function inject_embed() {
        $popup_id = Options::get(Options::$nova_popup_id);
        if (empty($popup_id)) {
            return;
        }

        $src = untrailingslashit($this->config->get('NOVA_APP_URL'))
            . '/api/popup/' . rawurlencode($popup_id) . '/embed.js';

        // The embed URL is versioned by Nova, so no WordPress ?ver should be appended (null version).
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Nova versions the URL.
        wp_enqueue_script(self::HANDLE, $src, [], null, true);
    }

    /**
     * Add the `async` attribute to the pop-up embed <script> tag (wp_enqueue_script does not emit it
     * natively on WordPress 6.0). Filters script_loader_tag and only touches our handle.
     *
     * @param string $tag
     * @param string $handle
     * @return string
     */
    public function add_async_attribute( $tag, $handle ) {
        if (self::HANDLE === $handle && false === strpos($tag, ' async')) {
            $tag = str_replace(' src=', ' async src=', $tag);
        }
        return $tag;
    }
}
