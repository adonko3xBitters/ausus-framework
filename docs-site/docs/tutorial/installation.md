---
id: installation
title: 'Part 1 — Installation'
sidebar_label: 1. Installation
description: Create the project directory and install the AUSUS packages with Composer.
---

# Part 1 — Installation

**Why this step exists:** before you can describe a domain, you need a PHP
project and the AUSUS packages on disk. AUSUS is distributed as ordinary
Composer packages — there is no installer, no global CLI, and no framework to
bootstrap.

## Check your tools {#check-your-tools}

Run these and confirm the versions match the [prerequisites](index.md#prerequisites):

```bash
php --version
php -m | grep -E 'pdo|sqlite'
composer --version
```

You should see PHP 8.3 or newer and both `pdo` and `pdo_sqlite` in the module
list. `pdo_sqlite` is what AUSUS uses to store data in this tutorial.

## Create the project {#create-the-project}

Make a fresh directory and move into it:

```bash
mkdir ticket-system
cd ticket-system
```

## Add the composer.json {#add-the-composer-json}

Create a `composer.json` in the project root. This declares one AUSUS
dependency and sets up autoloading for the code you are about to write:

```json
{
    "name": "tutorial/ticket-system",
    "description": "AUSUS tutorial — a minimal ticket system.",
    "type": "project",
    "require": {
        "php": ">=8.3",
        "ausus/standard-stack": "0.1.*"
    },
    "autoload": {
        "psr-4": {
            "Helpdesk\\": "src/"
        }
    },
    "minimum-stability": "stable"
}
```

Two things to note, and **why**:

- **`ausus/standard-stack`** is a single package that pulls in everything you
  need: the kernel, the runtime, the SQLite persistence driver, the HTTP API,
  and the high-level `Ausus\Application` class. Installing one package keeps
  the dependency list honest.
- The **`Helpdesk\` → `src/`** autoload rule means any class you put in
  `src/` under the `Helpdesk` namespace will be found automatically. Your
  domain plugin will live there.

## Install {#install}

```bash
composer install
```

Composer downloads `ausus/standard-stack` and its dependencies into `vendor/`
and writes a `vendor/autoload.php`. Every script in this tutorial starts by
requiring that file.

![Terminal output of running composer install in the tutorial project, showing five AUSUS packages locked and installed and the autoloader regenerated.](/img/tutorial/composer-install.svg)

## Verify the install {#verify-the-install}

Create a throwaway file `check.php` in the project root:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

echo class_exists(\Ausus\Application::class)
    ? "AUSUS is installed and autoloadable.\n"
    : "Something is wrong — Ausus\\Application was not found.\n";
```

Run it:

```bash
php check.php
```

Expected output:

```
AUSUS is installed and autoloadable.
```

If you see that line, the install is complete. You can delete `check.php` — it
was only a smoke test.

## What you have now {#what-you-have-now}

```
ticket-system/
├── composer.json
├── composer.lock
└── vendor/          ← AUSUS packages + autoloader
```

A project with AUSUS available, and nothing else. In the next part you start
describing the domain.

**Next: [Part 2 — The domain](domain.md).**
