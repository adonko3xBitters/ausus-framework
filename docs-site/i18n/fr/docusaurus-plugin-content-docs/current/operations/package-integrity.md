---
id: package-integrity
title: Intégrité des paquets
sidebar_label: Intégrité des paquets
description: Comment les artefacts de publication d'AUSUS sont vérifiés avant la publication.
---

# Intégrité des paquets

Avant la publication d'AUSUS, ses artefacts de publication sont vérifiés — que
les paquets Composer se construisent, que le tarball npm contient ce qu'il
doit contenir, et que l'ensemble de la pile fonctionne depuis un checkout
propre. Cette page décrit ces vérifications.

:::info Source
Les vérifications décrites ici proviennent des scripts de validation du dépôt
(`scripts/ci.sh`, `scripts/clean-room.sh`, `scripts/integration-http.sh`), de
l'étape de pré-inspection des artefacts du
[Runbook de publication](publication-runbook.md), et des résultats de la
[Répétition de publication](release-rehearsal.md).
:::

## Validation des manifestes Composer {#composer-manifest-validation}

Chaque manifeste de paquet est validé :

```bash
composer validate composer.json
composer validate packages/<pkg>/composer.json
```

Le contrôle de publication valide les **11 manifestes** — la racine de
l'espace de travail plus les 10 paquets — et exige zéro échec.

## Construction des artefacts Composer {#composer-artifact-build}

Chaque paquet Composer est construit en une archive et son contenu est
inspecté :

```bash
composer archive --working-dir=packages/<pkg> --format=tar --dir=/tmp/registry
tar -tf /tmp/registry/ausus-<pkg>-0.1.0.tar
```

Cela confirme que chaque paquet produit une archive propre et autonome à la
version `0.1.0`. La répétition exécute cela pour les 10 paquets Composer.

## Inspection du tarball npm {#npm-tarball-inspection}

Le tarball npm du moteur de rendu est inspecté avec un pack en simulation
(dry-run) — **jamais** une vraie publication pendant la vérification :

```bash
cd renderer/react
npm run build
npm pack --dry-run
```

La sortie indique le nom du paquet, la version, le nombre de fichiers et la
taille empaquetée. Le runbook enregistre ces chiffres pendant les contrôles
préalables ; le vrai `npm publish` plus tard doit produire les **mêmes**
chiffres. Une divergence signifie une désynchronisation source/dist et
constitue une condition STOP.

## Contrôles de bout en bout {#end-to-end-gates}

La validité des artefacts est nécessaire mais pas suffisante — la pile doit
aussi *fonctionner*. Trois contrôles l'exercent :

| Contrôle (gate) | Ce qu'il prouve |
|---|---|
| `scripts/ci.sh` | la construction en 10 étapes : validation, installation, playground, boot, build du moteur de rendu, trace de rendu, `npm pack --dry-run`, intégration HTTP |
| `scripts/clean-room.sh` | l'ensemble de la pile se reconstruit et passe dans un **répertoire temporaire isolé** — aucune dépendance à l'état local |
| `scripts/integration-http.sh` | 12 assertions contre un serveur `php -S` **en direct** et le moteur de rendu — un véritable aller-retour HTTP |

La reconstruction en clean-room est le signal d'intégrité le plus fort : elle
copie les sources dans un emplacement neuf et prouve que les paquets
s'installent et s'exécutent sans rien de résiduel de l'environnement de
développement.

## Irréversibilité — pourquoi la vérification est stricte {#irreversibility--why-verification-is-strict}

La vérification est stricte parce que la publication est **partiellement
irréversible** :

- une version **Packagist** publiée est définitive ;
- une version **npm** publiée n'est dépubliable que dans les **72 heures**.

Il n'y a pas d'« annulation » sur laquelle se rabattre, donc chaque défaut
détectable doit être attrapé *avant* la première publication. Voir le
[Runbook de publication](publication-runbook.md).

## Reporté — attestation de la chaîne d'approvisionnement {#deferred--supply-chain-attestation}

Les contrôles de chaîne d'approvisionnement suivants ne sont **pas** dans la
v0.1.0 et sont acceptés comme risque reporté à la v0.2.0 :

- la provenance npm (`npm publish --provenance`) — attestation de build ;
- les tags git signés GPG ;
- une nomenclature logicielle (SBOM) ;
- un conteneur de build reproductible.

Tant que ceux-ci n'arrivent pas, la confiance dans les artefacts repose sur
les contrôles de validation ci-dessus et sur la sécurité du transport
GitHub/registre.

## Voir aussi {#related}

- [Runbook de publication](publication-runbook.md) · [Répétition de publication](release-rehearsal.md)
- [Paquets](../packages/index.md) — le catalogue en cours de vérification.
