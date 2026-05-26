---
id: deployment
title: Deployment
sidebar_label: Deployment
description: Production deployment patterns for AUSUS v0.1.x — nginx + php-fpm, Apache, Docker, and the front-controller shape.
---

# Deployment

How to run an AUSUS application in production. v0.1.x is a plain
PHP 8.3 app — every standard PHP deployment shape works. This page
gives concrete recipes; for the authentication layer that must sit in
front, see [Authenticated gateway](authenticated-gateway.md).

The development front controller used by the tutorial,
`php -S 127.0.0.1:8787 -t public public/server.php`, is **not**
suitable for production — single-process, no worker pool, no resource
limits. Pick one of the recipes below instead.

## The front controller {#front-controller}

Every recipe shells through the same minimal front controller. Place
it at `public/server.php` of your app:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ausus\{Application, ApplicationConfig};
use Ausus\Api\Http\Emitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use App\YourPlugin;

$factory = new Psr17Factory();
$app = Application::create(
        ApplicationConfig::make()
            ->tenant(getenv('APP_TENANT') ?: 'default')
            ->sqlite(getenv('APP_DB_PATH') ?: '/var/lib/your-app/data.sqlite')
            ->psr17($factory)
    )
    ->register(new YourPlugin())
    ->boot();

$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
Emitter::emit($app->http($creator->fromGlobals()));
```

Three production-grade choices:

1. The app's web root is **`public/`** — never expose `vendor/`, the
   SQLite file, or the plugin source.
2. `boot()` is idempotent across requests and applies the schema with
   `CREATE TABLE IF NOT EXISTS`. PHP-FPM workers each boot once per
   worker lifetime.
3. The SQLite file path lives **outside** the document root; the
   webserver must not be able to serve it directly.

## nginx + php-fpm {#nginx-php-fpm}

The canonical production shape. nginx terminates TLS, fronts
[an authenticated gateway](authenticated-gateway.md) that sets
`X-Tenant-ID` / `X-Actor-*`, then forwards every request to PHP-FPM.

```nginx
server {
    listen 443 ssl http2;
    server_name app.example.com;

    # Static assets — the renderer's built UI lives elsewhere.
    root /var/www/your-app/public;
    index server.php;

    # CORS — narrow this; the framework defaults to '*'.
    add_header 'Access-Control-Allow-Origin'  'https://ui.example.com' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS'     always;
    add_header 'Access-Control-Allow-Headers'
        'Content-Type, X-Tenant-ID, X-Actor-Id, X-Actor-Roles'         always;

    # All API requests go through server.php.
    location /api/ {
        try_files $uri /server.php?$query_string;
    }

    # Health probe — no auth required.
    location = /api/_health {
        try_files $uri /server.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass             unix:/run/php/php8.3-fpm.sock;
        fastcgi_index            server.php;
        fastcgi_split_path_info  ^(.+\.php)(/.+)$;
        include                  fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME  $document_root/server.php;
        fastcgi_param  PATH_INFO        $fastcgi_path_info;

        # Sensible production limits — adjust to your workload.
        fastcgi_read_timeout     30s;
        fastcgi_send_timeout     30s;
    }

    # Block direct access to anything else.
    location ~ /\. { deny all; }
    location ~ /vendor/ { deny all; }
    location ~ /storage/ { deny all; }
}
```

php-fpm pool (`/etc/php/8.3/fpm/pool.d/your-app.conf`):

```ini
[your-app]
user                   = your-app
group                  = www-data
listen                 = /run/php/php8.3-fpm.sock
listen.owner           = www-data
listen.group           = www-data

pm                     = dynamic
pm.max_children        = 16
pm.start_servers       = 4
pm.min_spare_servers   = 2
pm.max_spare_servers   = 6
pm.max_requests        = 500     # cycle workers to release SQLite handles

request_terminate_timeout = 30s
php_admin_value[memory_limit] = 128M
php_admin_value[error_log]    = /var/log/php-fpm/your-app.log
```

### SQLite file ownership

The pool's `user` must have read+write on the SQLite directory **and**
the parent directory (SQLite creates a `-journal` or `-wal` companion
file next to the database).

```bash
install -d -m 0750 -o your-app -g your-app /var/lib/your-app
touch    /var/lib/your-app/data.sqlite
chown    your-app:your-app /var/lib/your-app/data.sqlite
chmod 0600 /var/lib/your-app/data.sqlite
```

### One worker per tenant — or one Application per tenant?

The Router reads `X-Tenant-ID` per request and resolves the tenant on
the fly. `Application::create()`'s `tenant` config sets a default for
CLI / `invoke()` paths but does **not** override the request header.
For a multi-tenant deployment, keep one `Application` instance per
worker process; the same Application serves every tenant the workers
receive.

## Apache + mod_php {#apache}

If you must use mod_php (legacy hosting):

```apache
<VirtualHost *:443>
    ServerName app.example.com
    DocumentRoot /var/www/your-app/public

    <Directory /var/www/your-app/public>
        Options -Indexes
        AllowOverride None
        Require all granted

        RewriteEngine On
        RewriteCond %{REQUEST_URI} ^/api/
        RewriteRule ^.*$ /server.php [QSA,L]
    </Directory>

    # Block PHP execution outside public/.
    <Directory /var/www/your-app/vendor>
        Require all denied
    </Directory>

    # Don't serve the SQLite file.
    <Files data.sqlite>
        Require all denied
    </Files>

    Header always set Access-Control-Allow-Origin  "https://ui.example.com"
    Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS"
    Header always set Access-Control-Allow-Headers \
        "Content-Type, X-Tenant-ID, X-Actor-Id, X-Actor-Roles"
</VirtualHost>
```

`php.ini` (`/etc/php/8.3/apache2/php.ini`):

```ini
memory_limit          = 128M
max_execution_time    = 30
expose_php            = Off
opcache.enable        = 1
opcache.memory_consumption    = 128
opcache.max_accelerated_files = 4000
opcache.validate_timestamps   = 0     ; production — bust cache on deploy instead
```

## Docker {#docker}

A self-contained image. The Dockerfile assumes your composer install is
checked in or produced in a multi-stage build.

```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor sqlite \
 && docker-php-ext-install pdo pdo_sqlite

# App
WORKDIR /var/www/app
COPY . /var/www/app
RUN composer install --no-dev --optimize-autoloader \
 && install -d -m 0750 /var/lib/your-app \
 && chown -R www-data:www-data /var/lib/your-app

# nginx + php-fpm + supervisor
COPY deploy/nginx.conf       /etc/nginx/http.d/default.conf
COPY deploy/php-fpm.conf     /usr/local/etc/php-fpm.d/zz-your-app.conf
COPY deploy/supervisord.conf /etc/supervisord.conf

ENV APP_DB_PATH=/var/lib/your-app/data.sqlite \
    APP_TENANT=default

EXPOSE 8080
VOLUME ["/var/lib/your-app"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
```

Keep the SQLite directory on a **persistent volume** — every restart
loses non-volume state. WAL mode (`PRAGMA journal_mode=WAL;`) is
worth enabling in production; the framework does not set it for you.

```php
// Enable WAL at the PDO seam.
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL;');
$pdo->exec('PRAGMA synchronous=NORMAL;');
$pdo->exec('PRAGMA busy_timeout=5000;');     // ms

$app = Application::create(
        ApplicationConfig::make()->tenant('acme')->pdo($pdo)->psr17($factory)
    )
    ->register(new YourPlugin())
    ->boot();
```

## Frontend hosting {#frontend}

`@ausus/renderer-react` is just a React library. Build the consumer
app with Vite (or any modern bundler) and serve the static output from
nginx, S3, CloudFront, Netlify, Vercel, etc. The two pieces only meet
at runtime over `fetch` — there is no shared deployment unit.

Two practical points:

- The renderer's `AususProvider apiBaseUrl` must point at the same
  origin (or a CORS-narrowed one) the gateway exposes.
- See [Authenticated gateway](authenticated-gateway.md) — the renderer
  does **not** set `X-Actor-*` on its own; a wrapping `fetcher` does,
  driven by the user's session.

## Production checklist {#checklist}

Before flipping DNS:

- [ ] PHP 8.3+ with `ext-pdo`, `ext-pdo_sqlite`, OpCache enabled and `validate_timestamps=0`.
- [ ] SQLite file on a persistent volume, `0600` mode, **outside** the document root.
- [ ] WAL mode + busy_timeout on the PDO (see snippet above).
- [ ] An authenticated gateway in front of the Router, setting `X-Tenant-ID` / `X-Actor-Id` / `X-Actor-Roles`. **Without this, every protected action returns 403.** See [Authenticated gateway](authenticated-gateway.md).
- [ ] CORS narrowed at the webserver — the framework defaults to `*`.
- [ ] `/api/_health` exposed to your orchestrator's liveness probe (no auth required).
- [ ] Backup pipeline for the SQLite file (file-level snapshot **while the WAL is checkpointed**, or `sqlite3 .backup`).
- [ ] `kernel_audit_log` retention policy (the framework does not prune).
- [ ] PHP-FPM `pm.max_requests` set so workers cycle (releases SQLite handles).

## What v0.1.x does not provide {#not-provided}

- No worker queues / background jobs / cron scheduling.
- No built-in metrics or tracing — wire your own around
  `$app->http()`.
- No built-in rate limiter; configure at the webserver level.
- No multi-database routing — one SQLite file per `Application`.
- Validated drivers: SQLite only. MySQL / PostgreSQL are design goals,
  not v0.1.x capabilities — write a custom `PersistenceDriver` if you
  need them.

## Related {#related}

- [Authenticated gateway](authenticated-gateway.md) — the X-Actor-* injection pattern.
- [HTTP routes reference](../reference/http-routes.md) — what the Router serves.
- [Configuration reference](../reference/configuration.md) — every config key + env var.
- [Application & ApplicationConfig](../reference/application.md) — the bootstrap centerpiece.
