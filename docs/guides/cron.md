# Running backups with a real cron job

WordPress runs its scheduled tasks (WP-Cron) only when someone visits the site. The plugin
helps itself: while a job runs, it starts the next slice with a request to its own
`wp-cron.php`, and every five minutes a heartbeat checks whether a backup is due. Two things
can stop that:

- **Few visitors**: no visit, no heartbeat, so a scheduled backup starts late.
- **No loopback**: some hosts block requests from the site to itself. Each slice of a job then
  waits for the next visit.

**Backups → Environment** tells you whether the site can call itself ("Background processing
works") or not. In both cases a cron job in your hosting control panel fixes it: it requests
`wp-cron.php` every five minutes, whether anyone visits or not.

## cPanel

1. Open **Cron Jobs** (section *Advanced*).
2. Under *Common Settings* choose **Once Per Five Minutes** (`*/5 * * * *`).
3. As the command, enter (with your site's address):

   ```bash
   curl -s -o /dev/null "https://example.com/wp-cron.php?doing_wp_cron"
   ```

   If `curl` is not available, `wget -q -O /dev/null "https://example.com/wp-cron.php?doing_wp_cron"`
   does the same.
4. Click **Add New Cron Job**.

## Plesk

1. Open **Websites & Domains → Scheduled Tasks (Cron Jobs) → Add Task**.
2. Task type: **Fetch a URL**; URL: `https://example.com/wp-cron.php?doing_wp_cron`.
3. Run: **Cron style**, `*/5 * * * *`.
4. Save, and use **Run Now** once to see that it works.

## Other control panels and external services

DirectAdmin (*Advanced Features → Cron Jobs*), ISPConfig and most hosting panels have the same
feature under a similar name; use the command from the cPanel section. If your host offers
no cron at all, an external cron service can request the address every five minutes.

Where the panel can only run PHP files, run WordPress' cron script directly, with the path of
your WordPress installation:

```bash
php /home/USER/public_html/wp-cron.php
```

## Optional: turn off WP-Cron on visits

Once the cron job runs, visitors no longer need to trigger WP-Cron. Add this line to
`wp-config.php`, above the line that says "That's all, stop editing!":

```php
define( 'DISABLE_WP_CRON', true );
```

This only stops WordPress from starting WP-Cron on page views; the cron job and the plugin's
own requests to `wp-cron.php` keep working. **Backups → Environment** then reports
"WP-Cron is disabled" as a reminder that the cron job must keep running.

## Check that it works

After ten minutes, **Backups → Dashboard** should show the next scheduled backup at the
expected time, and a backup started with **Back up now** keeps running after you close the
tab. If a job stays at the same step for longer than five minutes, the cron job is not
reaching the site: run the command once by hand in a terminal or with the panel's
*Run Now* button and look at the answer.
