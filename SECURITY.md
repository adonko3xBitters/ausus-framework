# Security Policy

## Supported versions

| Version | Status |
|---|---|
| 0.1.x   | active — receives security fixes |
| < 0.1.0 | n/a (no public release) |

## Reporting a vulnerability

**Do not file public GitHub issues for security vulnerabilities.**

Instead, send a description to: **security@prodestic.net**

Include:

1. A description of the vulnerability and its impact.
2. Steps to reproduce, ideally with a minimal proof-of-concept.
3. The affected version(s) and any mitigating circumstances.
4. Your name + how you would like to be credited (optional).

### What to expect

| Stage | Target |
|---|---|
| Acknowledge receipt | within 72 h |
| Initial assessment  | within 7 days |
| Fix + coordinated disclosure | depends on severity; typically 14–60 days |

We follow **coordinated disclosure**: a fix lands first, then a public
advisory + CVE is requested through GitHub's Security Advisory tooling.

## Hall of Fame

When we credit reporters (with their consent), we list them in the
release notes of the version that includes the fix.

## Scope

In scope:

- All packages under the `ausus/*` Packagist namespace
- The `@ausus/renderer-react` npm package
- The `ausus/starter` project template

Out of scope (separate disclosure channels):

- Vulnerabilities in PHP itself, Composer, Node, npm, React
- Vulnerabilities in third-party plugins not published by this project
