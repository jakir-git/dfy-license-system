=== DFY License Hub ===

Central control for the premium licenses BuddyBoss DFY provides for one year.
Install on ONE internal BuddyBoss WordPress site (https). Client sites run
"DFY License Manager" and connect to this hub.

== One-time setup ==

1. (Recommended) add to wp-config.php so encrypted keys survive a salt change:
   define( 'DFY_LICENSE_ENC_KEY', '<a long random string>' );
2. DFY Licenses → Licenses (optional): agency keys for Elementor Pro, Gravity
   Forms, LearnDash (plus its account email), WP Fusion, AutomatorWP Pro,
   WP All Import Pro. Only needed for plugins that are NOT licensed on the
   client site yet; licenses you activated by hand are taken over.
3. DFY Licenses → Settings: notification email. Test mode is ON at first:
   expired sites are only logged. Turn it off once the log looks right.

== Per DFY project ==

1. Install and activate DFY License Manager on the client site.
2. DFY Licenses → Add site: client site URL, customer name, Asana link
   (reference only) → "Add site".
   The hub asks the site to connect; the site proves it is that URL and the
   licenses are activated straight away. The 1-year period starts now.
   (If the plugin is installed after the site was added, it connects by
   itself within a few minutes, or click "Connect now".)
   - Premium plugins already licensed on the site are taken over as they are.
   - Premium plugins not licensed yet get the agency key.
3. The connection code on the site page is only a backup, for when the site
   answers on a different address than the one entered.
4. Client sites connect to the hub address in DFY License Manager's
   hub-config.php (included in the plugin zip, not in Git), or to DFY_LM_HUB
   set in the client's wp-config.php.

== What happens next ==

* 30 and 7 days before the end: email to the DFY team; the client's admins
  see a notice in wp-admin.
* After 1 year: the client site disconnects every DFY license (the premium
  plugins themselves stay active), reports back, and deletes DFY License
  Manager.
* If the client site does not do it within 2 days, the hub removes the site
  from each vendor directly.
* Customer deactivates/deletes DFY License Manager early: licenses are
  removed straight away.
* Site stops checking in (plugin deleted by FTP, site gone): alert after
  3 days, licenses removed from the vendors after 7 days.
* Customer replaced a key with their own: their license is left alone.
* Staging/local copies of a site are ignored.
* Sites tab: search (name, URL, Asana link), filter by status and by
  month/year of the start date, expiry date or date added, and sort by any
  date column. Each row has: Asana ↗ (the project link), Set start date
  (licenses then expire 1 year later; the site is told straight away) and
  Deactivate licenses (removes every DFY license now, also in test mode;
  the premium plugins stay active).
* Elementor Pro (Agency plan): no key needed. On the client site use
  Elementor → License → Connect & Activate with the DFY account; DFY
  License Manager takes it over within a minute. After the year the license
  is deactivated and the DFY Elementor account is disconnected from the site
  (the customer's own Elementor connection is kept).

All the numbers above are on the Settings tab. Each site has a full log.

== Completed sites for reference ==

Add site → "Add completed sites for reference": one per line
  Name | completion date (YYYY-MM-DD) | Asana or Pipefy link | client | site URL
They show as "Reference only" with start/end dates; the team gets an email
when a year ends so the licenses can be removed by hand. "Track with DFY
License Manager" (on the site page) switches one to normal tracking once the
plugin is installed there.

== Customer details and export ==

* Add site / site Details: customer name and email. With an Asana token
  (Settings tab, optional) they are filled from the site's Asana project:
  the "Customer First Name", "Customer Last Name" and "CONTACT EMAIL" fields
  (on "WEB PROJECT SCOPE"), or the name after " - " in the project name.
  Sites tab → "Fill customer name and email from Asana" does it for every
  site with an Asana link (15 per click).
* Sites tab → "Export these sites to Excel (CSV)": exports the sites shown
  with the current search / status / month / year filters (e.g. status
  "Active" or "Ended / expired"): name, customer, email, URL, status, dates,
  days left, needs-action note, licenses, project link.
