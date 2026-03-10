/*
 * The leadpagesData variable is injected into this script by the WordPress wp_localize_script
 * function in the Assets.php class. We provide production default values here in case
 * that process fails.
 */

// @ts-ignore
const LEADPAGES_DATA = typeof leadpagesData !== 'undefined' ? leadpagesData : {};

const HOME_URL = LEADPAGES_DATA.homeUrl ?? 'your.domain.com';
const LEADPAGES_URL = LEADPAGES_DATA.leadpagesUrl ?? 'https://my.leadpages.com/';
const BUILDER_URL = LEADPAGES_DATA.builderUrl ?? 'https://pages.leadpages.com/';

export { HOME_URL, LEADPAGES_URL, BUILDER_URL };
