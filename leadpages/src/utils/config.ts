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
const NOVA_DASHBOARD_URL = LEADPAGES_DATA.novaDashboardUrl ?? 'https://leadpages.com';
const NOVA_APP_URL = LEADPAGES_DATA.novaAppUrl ?? 'https://leadpages.com';

export { HOME_URL, LEADPAGES_URL, BUILDER_URL, NOVA_DASHBOARD_URL, NOVA_APP_URL };
