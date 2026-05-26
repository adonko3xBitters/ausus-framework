---
id: installation
title: 'Partie 1 — Installation'
sidebar_label: 1. Installation
description: Créer le répertoire du projet et installer les packages AUSUS avec Composer.
---

# Partie 1 — Installation

**Pourquoi cette étape existe :** avant de pouvoir décrire un domaine, vous avez
besoin d'un projet PHP et des packages AUSUS sur disque. AUSUS est distribué sous
forme de packages Composer ordinaires — il n'y a pas d'installateur, pas de CLI
globale, et pas de framework à amorcer.

## Vérifier vos outils {#check-your-tools}

Exécutez ces commandes et confirmez que les versions correspondent aux [prérequis](index.md#prerequisites) :

```bash
php --version
php -m | grep -E 'pdo|sqlite'
composer --version
```

Vous devriez voir PHP 8.3 ou plus récent et à la fois `pdo` et `pdo_sqlite` dans la
liste des modules. `pdo_sqlite` est ce qu'AUSUS utilise pour stocker les données dans
ce tutoriel.

## Créer le projet {#create-the-project}

Créez un répertoire vierge et placez-vous dedans :

```bash
mkdir ticket-system
cd ticket-system
```

## Ajouter le composer.json {#add-the-composer-json}

Créez un `composer.json` à la racine du projet. Il déclare une dépendance AUSUS
et configure l'autoloading du code que vous êtes sur le point d'écrire :

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

Deux choses à noter, et **pourquoi** :

- **`ausus/standard-stack`** est un package unique qui tire tout ce dont vous avez
  besoin : le kernel, le runtime, le driver de persistance SQLite, l'API HTTP,
  et la classe de haut niveau `Ausus\Application`. Installer un seul package garde
  la liste de dépendances honnête.
- La règle d'autoload **`Helpdesk\` → `src/`** signifie que toute classe que vous
  placez dans `src/` sous le namespace `Helpdesk` sera trouvée automatiquement. Votre
  plugin de domaine y vivra.

## Installer {#install}

```bash
composer install
```

Composer télécharge `ausus/standard-stack` et ses dépendances dans `vendor/`
et écrit un `vendor/autoload.php`. Chaque script de ce tutoriel commence par
requérir ce fichier.

![Sortie du terminal lors de `composer install` dans le projet du tutoriel — les cinq paquets AUSUS sont verrouillés et installés, puis l'autoloader est régénéré.](/img/tutorial/composer-install.svg)

## Vérifier l'installation {#verify-the-install}

Créez un fichier jetable `check.php` à la racine du projet :

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

echo class_exists(\Ausus\Application::class)
    ? "AUSUS is installed and autoloadable.\n"
    : "Something is wrong — Ausus\\Application was not found.\n";
```

Exécutez-le :

```bash
php check.php
```

Sortie attendue :

```
AUSUS is installed and autoloadable.
```

Si vous voyez cette ligne, l'installation est complète. Vous pouvez supprimer
`check.php` — ce n'était qu'un test de fumée.

## Ce que vous avez maintenant {#what-you-have-now}

```
ticket-system/
├── composer.json
├── composer.lock
└── vendor/          ← packages AUSUS + autoloader
```

Un projet avec AUSUS disponible, et rien d'autre. Dans la partie suivante, vous
commencez à décrire le domaine.

**Suivant : [Partie 2 — Le domaine](domain.md).**
