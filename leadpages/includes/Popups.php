<?php

namespace Leadpages;

defined('ABSPATH') || die('No script kiddies please!'); // Avoid direct file request

use Leadpages\providers\Utils;
use Leadpages\PopupScope;

/**
 * Injects a selected Nova (new Leadpages) pop-up embed script on the front end.
 *
 * The embed script is public and loads asynchronously. It is enqueued on normal WordPress front-end
 * requests via wp_enqueue_scripts, gated by the configured page scope (all pages, or only the
 * selected pages/posts). Proxied landing pages exit() on init (long before the enqueue hook runs),
 * so the Proxy injects the same embed for those pages, reading the same scope from PopupScope.
 */
class Popups {

    use Utils;

    /** @var string script handle for the pop-up embed */
    private const HANDLE = 'leadpages-nova-popup';

    /**
     * Enqueue the selected pop-up's embed script when one is chosen and the current front-end page is
     * within the configured scope. No output otherwise. Enqueued (not printed directly) so it goes
     * through the standard WordPress script pipeline and passes plugin-check's NonEnqueuedScript rule.
     *
     * @return void
     */
    public function inject_embed() {
        $popup_id = PopupScope::selected_popup_id();
        if (empty($popup_id)) {
            return;
        }

        if (! PopupScope::allows_current_request()) {
            return;
        }

        $src = PopupScope::embed_src($popup_id);

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
