=== Backups for restic ===
Contributors: cunaz
Tags: backup, restic, encryption, database backup, restore
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: trunk
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encrypted, deduplicated backups of files and database in the restic format. Pure PHP: no shell, no programs to install.

== Description ==

Backups for restic backs up your whole site, files and database, into an end-to-end encrypted [restic](https://restic.net) repository on a restic REST server over HTTPS. It implements restic's repository format in PHP, so it runs on ordinary shared hosting: no restic program, no shell access and no server configuration are needed.

What it writes is a normal restic repository. You can list, check and restore every backup with the `restic` command line on any computer, without WordPress and without this plugin.

= Features =

* **Encrypted before it leaves the server.** The repository password stays on your site; the storage provider sees only encrypted data.
* **Deduplicated.** Only changed data is uploaded. Daily backups of a site whose media rarely change take seconds to minutes.
* **Files and database.** The database is streamed table by table into the snapshot as plain SQL that imports with the `mysql` or `mariadb` client.
* **Built for shared hosting.** Every job runs in short, resumable slices that fit PHP time and memory limits. A request that is killed loses at most one slice.
* **Retention and maintenance.** Old snapshots are removed by a restic-compatible policy, data that no snapshot uses any more is deleted weekly, and a monthly check downloads and verifies a share of the stored data. Data in packs that still hold used data stays until a full `restic prune` from another computer; the dashboard shows how much that is.
* **Append-only aware.** If your server is append-only, nobody who takes over the website can delete your backups. The plugin notices it, stops deleting, and tells you how to clean up from another computer.
* **E-mail notifications** when a backup or a maintenance job (removing old snapshots, deleting unused data, the check) fails, when no backup succeeded for too long, when it works again, when a check finds damage, and when the plugin stops removing old snapshots because the server became append-only.
* **Recovery kit.** One page with everything needed to restore without the website: repository address, passwords, the CA certificate of a server with its own certificate authority, and the exact restic commands.
* **WP-CLI** commands that check the server, start backups and maintenance jobs, run their work in the terminal, show the status and list snapshots (`doctor`, `backup`, `forget`, `prune`, `check`, `tick`, `status`, `snapshots`). Setup and settings are done in the admin area.
* **Site Health** checks and debug information.

= Requirements =

* PHP 8.3 or newer with the OpenSSL, JSON and mbstring extensions (standard on hosting), and a PHP memory limit of at least 96 MB.
* At least 128 MB of free disk space for the plugin's working files.
* Outbound HTTPS to the repository server.
* For an existing repository in restic's format 2 with compressed data, the zstd PHP extension. Repositories that the plugin creates do not need it.
* A restic REST server: your own [rest-server](https://github.com/restic/rest-server), or a hosted one.
* Multisite networks are not supported yet.

= Your data, your storage =

The plugin talks only to the repository server you configure. It has no account system, no telemetry and no service of its own.

The project is independent and not affiliated with restic or BorgBase. Both names are marks of their respective owners and are used here only to say what the plugin works with.

== Installation ==

1. Install and activate the plugin.
2. Open **Backups** in the admin menu. The setup wizard checks the server environment first.
3. Choose your repository server: your own restic REST server, or a hosted service such as BorgBase, for which the wizard has a preset. Enter the repository address and credentials, and for a server with a self-signed certificate its CA certificate. The wizard tests the connection and whether the server is append-only.
4. Create a new repository (the plugin generates a strong password) or connect to an existing one with its password.
5. Store the recovery kit outside the website, for example in a password manager.
6. Choose the schedule and run the first backup.

On hosts where WordPress cannot call itself, or that disable WP-Cron, set up a cron job in the hosting control panel that requests `wp-cron.php` every five minutes: see [Running backups with a real cron job](https://github.com/cunaz/backups-for-restic/blob/main/docs/guides/cron.md).

== Frequently Asked Questions ==

= Do I need the restic program or shell access? =

No. The plugin implements restic's repository format in PHP. You need the restic program only on the computer where you restore without WordPress.

= Can I restore without WordPress and without this plugin? =

Yes. Every backup is a normal restic repository. The recovery kit lists the commands, and the [disaster-recovery guide](https://github.com/cunaz/backups-for-restic/blob/main/docs/06-disaster-recovery.md) walks through each case. You need restic 0.16.1 or newer for them.

= Can I restore from the WordPress admin area? =

Not yet. This version lists your snapshots under **Backups > Snapshots**; restoring is done with the restic command line and the recovery kit, on any computer. Restore from the admin area is planned for a later version.

= Why does this plugin contain encryption code? =

It implements restic's published repository format. AES and SHA-256 come from PHP's OpenSSL and hash extensions. Three algorithms that PHP does not offer (Poly1305, scrypt and restic's content-defined chunker) are textbook implementations, tested against reference vectors and the restic program itself.

= What is append-only mode, and should I use it? =

An append-only server accepts new backups but refuses to delete them. Then nobody who takes over the website can destroy the backups, which is the main protection against ransomware. It is recommended. The price: old snapshots must be removed from another computer, which the [disaster-recovery guide](https://github.com/cunaz/backups-for-restic/blob/main/docs/06-disaster-recovery.md) explains. The plugin detects append-only servers by itself.

= My site has few visitors, or the host disables WP-Cron. Do backups still run? =

Yes, if a cron job of your host requests `wp-cron.php` every few minutes. [Running backups with a real cron job](https://github.com/cunaz/backups-for-restic/blob/main/docs/guides/cron.md) shows how in cPanel, Plesk and similar panels. While the dashboard is open in a browser tab, jobs also advance from there.

= How do I run my own restic REST server? =

See [Your own restic REST server](https://github.com/cunaz/backups-for-restic/blob/main/docs/guides/rest-server.md): installation, TLS, users, append-only mode and cleaning up old snapshots.

= My host limits CPU time. What can I do? =

Set **Settings > Advanced > Processor use** to "Gentle". Jobs then work in shorter slices with short rests, so they use less CPU time at once and take longer.

= Where are the passwords stored? =

Encrypted in the database with a key derived from the security keys in `wp-config.php`, or, if you prefer, as constants in `wp-config.php`. They never appear in logs or URLs. The setup wizard shows the recovery kit once, right after it creates or connects the repository; later it is shown only after you re-enter your WordPress password.

= What happens to my backups when I uninstall the plugin? =

Nothing. Uninstalling removes the plugin's settings, passwords and working files from the website, also from a working directory that was chosen with the `BACKUPS_FOR_RESTIC_PRIVATE_DIR` constant or the `backups_for_restic_private_dir` filter. The repository on the server is never touched and stays restorable with the recovery kit.

= Is multisite supported? =

Not yet. Network support is planned for version 1.1.

= My hosting runs an older PHP version. How do I switch? =

The plugin needs PHP 8.3 or newer, and WordPress does not install it on older versions. Most hosts let you choose the PHP version per site in the control panel: in cPanel under **MultiPHP Manager** or **Select PHP Version**, in Plesk under **PHP Settings** of the domain. Other panels have a similar page, and your host's support can switch it for you. **Tools > Site Health > Info > Server** shows the version your site runs now.

= Where do I get help, and where do I report a bug? =

Ask questions about using the plugin in its support forum on WordPress.org. Report bugs and propose changes on [GitHub](https://github.com/cunaz/backups-for-restic/issues). Please add the list from **Backups > Environment** to a bug report, and never post your recovery kit or a password.

= How do I report a security problem? =

Privately, as the [security policy](https://github.com/cunaz/backups-for-restic/blob/main/SECURITY.md) describes: with **Report a vulnerability** on the Security tab of the GitHub repository. Please do not use the support forum or a public issue for it.

== External services ==

This plugin connects to a restic REST server that you configure, to store and read your backups. It is required for the plugin to work. Nothing is sent before you enter a server address in the setup wizard.

What is sent and when: encrypted repository files (file contents, directory listings, the database dump, snapshot records, index files and lock files) when backups and maintenance jobs run, and requests to list, read and delete those files. All file contents are encrypted on your site before they are sent; the server sees the file sizes, the times of the requests and your site's IP address. The REST username and password are sent to authenticate each request.

**Your own restic REST server** (for example [rest-server](https://github.com/restic/rest-server)): the data goes only to the server you run.

**BorgBase** (a hosted repository service, offered as a preset in the setup wizard): if you choose it, the data described above goes to your BorgBase repository at an address of the form `xxxxxxxx.repo.borgbase.com`. BorgBase is operated by Peakford Ltd; see the [terms of service](https://www.borgbase.com/tos/) and the [privacy policy](https://www.borgbase.com/privacy/).

== Changelog ==

= Unreleased =

* Not released yet. The [changelog on GitHub](https://github.com/cunaz/backups-for-restic/blob/main/CHANGELOG.md) lists the work in progress.
