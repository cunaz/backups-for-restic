# Your own restic REST server

[rest-server](https://github.com/restic/rest-server) is the restic project's small HTTP
server for repositories. One instance can hold the backups of many sites, each in its own
repository, and its append-only mode keeps backups safe from anyone who takes over a website.

## What you need

- A Linux server that the web host can reach over HTTPS, with disk space for the backups. A
  small virtual server is enough; deduplication keeps repositories small.
- A host name with a TLS certificate, for example from Let's Encrypt. A self-signed
  certificate works too, see below.
- The rest-server binary from its [releases](https://github.com/restic/rest-server/releases),
  or the Docker image `restic/rest-server`.

## Users

rest-server runs as its own system user, which owns the data directory. Each site gets its
own login; rest-server reads them from `.htpasswd` in the data directory. Create both with
`htpasswd` (package `apache2-utils` on Debian and Ubuntu):

```bash
sudo useradd --system --home-dir /srv/restic --shell /usr/sbin/nologin restic
sudo mkdir -p /srv/restic
sudo htpasswd -B -c /srv/restic/.htpasswd mysite
sudo chown -R restic:restic /srv/restic
sudo chmod 600 /srv/restic/.htpasswd
```

Leave out `-c` when you add more logins later, and run the `chown` again afterwards. Start
rest-server as the user `restic`, never as root.

## Certificate and key

The user `restic` must be able to read the certificate and its key. Let's Encrypt keeps
both below `/etc/letsencrypt/`, which only root can read, so give rest-server copies of its
own:

```bash
sudo install -d -o restic -g restic -m 700 /etc/rest-server
sudo install -o restic -g restic -m 600 \
  /etc/letsencrypt/live/backup.example.net/fullchain.pem \
  /etc/letsencrypt/live/backup.example.net/privkey.pem /etc/rest-server/
```

rest-server reads the certificate only when it starts. After every renewal the new files
must therefore be copied and rest-server restarted, or it keeps serving the old certificate
until that expires. With certbot, save the following as an executable file in
`/etc/letsencrypt/renewal-hooks/deploy/`; certbot runs it after each successful renewal:

```bash
#!/bin/sh
install -o restic -g restic -m 600 \
  /etc/letsencrypt/live/backup.example.net/fullchain.pem \
  /etc/letsencrypt/live/backup.example.net/privkey.pem /etc/rest-server/
systemctl restart rest-server
```

A job of the plugin that is running during the restart tries again by itself.

## Start the server

```bash
rest-server --path /srv/restic --listen :8000 \
  --append-only --private-repos \
  --tls --tls-cert /etc/rest-server/fullchain.pem \
  --tls-key /etc/rest-server/privkey.pem
```

- `--private-repos` lets each user reach only the repositories below `/<user>/`.
- `--append-only` is recommended: the server stores new backups but refuses to delete
  anything except lock files. The plugin detects this and leaves the cleanup to you.
- Run it as a service under the user `restic`; the rest-server repository has an example
  systemd unit. The restart in the renewal hook above assumes that the service is called
  `rest-server`.
- If your firewall allows it, open port 8000 only to the outgoing address of your web host.

## Connect the site

In the plugin's setup wizard choose **Own restic REST server** and enter the repository
address with the user name as the first part of the path, for example
`https://backup.example.net:8000/mysite/wordpress/`, together with the user name and password
from `.htpasswd`. The wizard tests the connection, reports append-only mode and creates the
repository.

With a **self-signed certificate**, paste its CA certificate (PEM, starting with
`-----BEGIN CERTIFICATE-----`) into the wizard's optional field. The plugin then trusts that
certificate for this server.

## Cleaning up in append-only mode

An append-only server never deletes, so old snapshots and the data only they use stay until
you remove them. On the server itself, restic can work on the data directory directly,
without going through rest-server and its append-only flag. `restic forget` deletes for
good, so try every new or changed set of options with `--dry-run` in place of `--prune`
first: restic then only prints which snapshots it would keep and which it would remove.
With the repository password from the site's recovery kit, run as the user that owns
`/srv/restic`:

```bash
restic -r /srv/restic/mysite/wordpress forget \
  --host example.com --tag backups-for-restic \
  --keep-within 2d --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --prune
```

Use the site's host name and your own retention numbers. `--host` and `--tag` make sure that
only this site's snapshots are considered. If the plugin is running a job at that moment,
restic waits for its lock or stops with a message; run it again later. Once a month is plenty.

Everything else about restoring is in the [disaster-recovery guide](../06-disaster-recovery.md).
