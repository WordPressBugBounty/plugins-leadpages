=== Leadpages ===
Tags: landing page, lead generation, leadpages, form builder, sales page
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Easily publish your Leadpages landing pages to your WordPress site. Promote your lead magnets, events, promotions, and more.

== Description ==
Leadpages is a complete lead generation platform that allows you to build high-converting landing pages. Easily create your pages in your Leadpages account with the no-code builder, then use the Leadpages plugin to seamlessly publish your assets to your WordPress site.

Along with lead management and analytics, Leadpages gives you all the tools you need to turn your website into a conversion machine.

[Sign up for a Leadpages free trial](https://lp.leadpages.com/free-trial/) to get started.

https://youtu.be/c7oMTLuNFKU

= Lead-generating landing pages =

Choose from 250+ conversion-optimized templates, then customize your look with the drag-and-drop builder. No coding or web design experience is required—change colors, add text, and upload images with just a few clicks of your mouse.

These pages aren’t just easy to build… they work. In fact, Leadpages landing pages convert 5x better than the industry average.

Here are a few more reasons to build your pages with Leadpages:
* Get real-time conversion tips as you build.
* Enjoy blazingly fast page speeds.
* Use AI copywriting and image generation tools to quickly create content.

= Conversion tools =

Turning website visitors into customers just got a whole lot easier. Bring attention to your lead magnets, sales, and giveaways with pop-ups and alert bars, and then lock in the conversion with high-converting forms.

Have an offer you want to draw attention to? Create a timed or exit intent pop-up to capture users’ attention and ensure they don’t leave your site empty-handed. If you’re looking for something a little less intrusive than an alert bar (a small colored bar that appears at the top of the screen) is another option. You can also create a sense of urgency for your limited-time offers with countdown timers.

When visitors are ready to take action, capture their information with a lead gen form. Collect email addresses and automatically deliver lead magnets—or integrate your forms with your email marketing platform for even more options.

Finally, when visitors are ready to make a purchase they can do so right from your website. Add checkout forms to any page without having to send visitors to third-party sites.

= Manage your leads =

Leadpages doesn’t just help you acquire leads—it also helps you to make the most of your leads once you get them.

Do away with clunky spreadsheets. With the Leadpages, you can search, filter, and manage your leads right inside the Leads Library. Need even more lead management tools? Seamlessly integrate your Leadpages account with your favorite CRM platform.

Leadpages also notifies you as soon as you get a lead so you can reach out to them while your brand is still top of mind.

= Track your progress =

Stay in the know about how your pages are performing. See how many visitors are landing on your pages, track your conversion rates and leads, and make adjustments based on your findings.

To maximize your results, use Leadpages’ A/B testing tool. Create multiple versions of your landing page to test different text, image, and CTA button variations. See which version produces the most conversions and keep testing until your page is completely optimized.


== External services ==

This plugin connects to Leadpages so it can publish and serve your Leadpages content on your WordPress site. It supports two Leadpages platforms: Classic Leadpages and the new Leadpages ("Nova"). You choose which account to connect. No data is sent to Leadpages until you connect an account.

Classic Leadpages (leadpages.com / leadpages.io):
- When you connect, the plugin runs an OAuth 2.0 sign-in and stores the resulting access and refresh tokens in your WordPress options table. The tokens are never displayed and are erased when you sign out, deactivate, or uninstall the plugin.
- When you sync, the plugin requests your landing page list and analytics from the Leadpages content API using your token.
- When a visitor loads a connected page, the plugin fetches that page's published HTML from Leadpages and serves it at your WordPress URL.

New Leadpages / Nova (leadpages.com):
- When you connect, the plugin runs an OAuth 2.0 (PKCE) sign-in against the new Leadpages authorize and token endpoints and stores the resulting access and refresh tokens in your WordPress options table (never displayed; erased on sign out, deactivate, or uninstall).
- When you sync, the plugin requests your published pages from `/api/pages`, per-page metrics from `/api/analytics/pages`, and your pop-ups from `/api/popups`, using your token.
- In the plugin's page list, each new-Leadpages page shows a small preview thumbnail loaded from the public, cached `/api/thumbnails/{id}` endpoint (a placeholder icon is shown when a page has no thumbnail).
- When a visitor loads a connected page, the plugin fetches that page's HTML from `/api/pages/{slug}/raw` (a public endpoint for published pages) and serves it at your WordPress URL. The visitor's cookies and IP are forwarded so per-visitor experiments and personalization work; response cookies are passed back to the visitor.
- If you choose to show a pop-up, the plugin adds the public embed script `/api/popup/{id}/embed.js` to your site.

Your use of Leadpages is governed by the Leadpages Terms of Service (https://leadpages.com/legal/terms) and Privacy Policy (https://leadpages.com/legal/privacy).

== Frequently Asked Questions ==

= How do I connect the new Leadpages platform? =

On the Leadpages plugin screen, choose "Connect the new Leadpages" and complete the secure sign-in popup. Once connected, your published new-Leadpages pages appear in the plugin so you can publish them to a slug on your WordPress site, just like Classic pages.

= Can I use both Classic and the new Leadpages? =

Each WordPress site connects to one Leadpages account (Classic or the new Leadpages) at a time. If you are migrating from Classic to the new Leadpages, you can publish a new-Leadpages page to a slug currently used by a Classic page and choose to replace it, keeping the same URL.

= Why should I use Leadpages? =

If you find that your website is driving a lot of traffic but not a lot of conversions, Leadpages can fix that. By adding optimized landing pages you can promote specific offers, grow your email list, and maximize your Return on Ad Spend.

= Do I need a Leadpages account to use the WordPress plugin? =

Yes, you’ll need to sign up for an account to publish Leadpages landing pages to your WordPress site. Plans start at just $37 per month and you can try it free for 14 days.

= Do I need to know how to code to create landing pages with Leadpages? =

No. Leadpages is designed to be used by both new and experienced marketers. Our drag-and-drop interface means building landing pages is quick and easy, even if you’re a complete beginner.

= Do I need to have experience with web design? =

No. Our pre-designed templates are already conversion-optimized and built with all the latest best practices in mind. Just drop in your text and images and you\'re good to go. That being said, all our templates are fully customizable so feel free to experiment with different looks.

= How do I edit landing pages once they’re published to my WordPress site? =

You can make changes to your landing pages and other marketing assets in your Leadpages account. As soon as an asset is updated inside your account it will automatically be updated on your WordPress site.

= Is there any limit on the number of leads I can collect with Leadpages? =

No! Leadpages does not put a limit on your leads so collect as many as you like.

== Screenshots ==

1. Choose from 250+ conversion-optimized landing page templates. Leadpages templates convert 5x better than the industry average.
2. The no-code landing page builder lets you easily edit text, upload images, and drag and drop elements into place, such as forms and countdown timers.
3. Leadpages integrates with all your favorite marketing tools, including Mailchimp, Google Analytics, Stripe, Zapier, Convert Kit, Hotjar, and more.
4. Connect the new Leadpages platform with a secure one-click sign-in.
5. Publish any page to a clean URL on your WordPress site.
6. Migrate from Classic to the new Leadpages while keeping the same URL.

== Changelog ==

= 1.2.3 =
* Release Date: 07/29/2026
- Fixed: Syncing no longer fails when a new-Leadpages page has a long title or slug. Pages with titles over 255 characters previously caused a "Could not sync pages" error and stopped the whole sync; the page fields are now stored in full.
- Improved: If a single page cannot be stored during a sync, it is skipped and logged instead of stopping the entire sync.

= 1.2.2 =
* Release Date: 07/29/2026
- Fixed: The page list now respects which Leadpages account you are connected to. Your unpublished pages come only from the account you are signed into, while pages already published to your WordPress site keep showing (and serving) for both Classic and the new Leadpages, no matter which account you are currently signed into.
- Fixed: Signing into Classic after using the new Leadpages now loads your Classic pages instead of staying on the new Leadpages.
- Fixed: Signing out now clears the signed-out account's unpublished pages so they no longer appear for the next person who connects on the same site. Published pages are preserved and keep serving.

= 1.2.1 =
* Release Date: 07/29/2026
- Improved: Refreshed the plugin's admin design, including a cleaner connect screen and a new card-based page manager.
- Improved: Per-page actions (edit, publish, view, and more) are now grouped into a single menu on each page for a tidier layout.
- Tested up to WordPress 7.0.
- No change to publishing, syncing, or serving behavior.

= 1.2.0 =
* Release Date: 07/28/2026
- New: Connect the new Leadpages ("Nova") platform over OAuth and publish your new-Leadpages pages to your WordPress site, alongside the existing Classic support.
- New: Sync published new-Leadpages pages with visitor and conversion-rate analytics columns.
- New: Serve new-Leadpages pages at a WordPress slug via server-side reverse proxy, with per-visitor experiments/personalization (cookie and visitor-IP passthrough) and cache handling that honors the page's cache settings.
- New: Embed a new-Leadpages pop-up across your site from the Settings screen.
- New: Classic to new-Leadpages migration hand-off - publish a new-Leadpages page to a slug held by a Classic page and choose to replace it, preserving the same URL.
- New: Warns and stands down if a second Leadpages plugin is active so the two never conflict when serving pages.
- Classic Leadpages behavior is unchanged.

= 1.1.4 =
* Release Date: 03/10/2026
- Security: REST API endpoints now require the manage_options capability, preventing unauthorized access by low-privilege WordPress users. Affects versions 1.0.0–1.1.3. Severity: High. Update to 1.1.4 immediately.
- Tested up to WordPress 6.9.

= 1.1.3 =
* Release Date: 06/14/2024
- Sign-up maintenance update

= 1.1.2 =
* Release Date: 06/10/2024
- Fix: Clear cache for page when it is deleted on leadpages.com
- Hide split test variations in WP to match experience at leadpages.com
- Pages published to WP but unpublished at leadpages.com will not automatically republish in WP after republishing at leadpages.com

= 1.1.1 =
* Release Date: 05/30/2024
- Cache served pages for improved performance of connected pages.
- Clear the cache of specific pages make changes to your assets available to users sooner.
- Fix: Plugin conflicts.
- Modify page meta tags for better social media sharing.

= 1.1.0 =
* Release Date: 04/30/2024
- Implemented OAuth2 login flow for enhanced security and user convenience.

= 1.0.0 =
* Release Date: 04/30/2024
- Initial Release: Introducing our new and improved plugin!

