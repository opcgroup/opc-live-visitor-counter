OPC LIVE VISITOR COUNTER — v1.0.0
================================

WHAT IT SHOWS
- Online Now: Unique browser IDs active within the last 5 minutes.
- Today: Today's unique browser IDs, plus today's optional starting value.
- Yesterday: Yesterday's unique browser IDs, plus yesterday's optional starting value.
- Total Visitors: All-time unique browser IDs, plus your optional starting total.

GET STARTED
1. WordPress dashboard > Plugins > Add New Plugin > Upload Plugin.
2. Upload opc-live-visitor-counter.zip; click Install Now and Activate.
3. Go to Settings > OPC Visitor Counter.
4. Set Starting Total (e.g., 50000), optionally Today's / Yesterday's starting numbers, and click Save.
5. Paste [opc_visitor_counter] in any Shortcode block or Elementor Shortcode widget.
   Or enable Automatically Display Above Footer in Settings.

Optional custom title: [opc_visitor_counter title="Our Website Visitors"]

HOW IT WORKS
- Uses a first-party random visitor ID stored in browser localStorage (cookie fallback).
- Only a salted SHA-256 hash of the ID, visit dates, and times are stored in two WordPress DB tables.
- No visitor IP address, geolocation, name, or email is stored by this plugin.
- Tracks from the moment of activation. Does not import Google Analytics history.
- Counts unique browsers, not verified humans. Incognito windows / cleared storage may count again.
- Searches and PHP-only bots are generally not counted; detection is not perfect.
- All public website pages are tracked, even when the counter section is only on one page.
- A background request keeps online status fresh while tabs are visible, approximately once per minute.
- Day boundaries follow Settings > General > Timezone in WordPress.
- Starting numbers are cosmetic baselines; they aren't historical measured statistics.
- Today's configured starting number follows that calendar day into Yesterday after midnight.
- Yesterday's configured starting number is only for the date specified when saved.
- Logged-in administrators may be excluded from visits in plugin settings.
- Full-page cache/CDN works because counting occurs via uncached WordPress AJAX calls.

IMPORTANT
- If there is a consent requirement in your jurisdiction, obtain appropriate consent before
  running analytics tracking; document visitor-ID storage in your privacy/cookie policy.
- Most cache optimizers are compatible, but do not block /wp-admin/admin-ajax.php.
- Please test the plugin in staging / with a WordPress backup before using in production.
- This release is source-reviewed and syntax-checked, but not live-tested on your WordPress host.
- Deactivation does not erase visitor stats; uninstalling the plugin also intentionally
  leaves the database tables and settings intact, to avoid accidental data loss.
