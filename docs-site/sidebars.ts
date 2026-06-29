import type {SidebarsConfig} from '@docusaurus/plugin-content-docs';

/**
 * AUSUS documentation sidebar.
 *
 * AUSUS 2.0 (the Entity Engine) is the primary line: its docs come from
 * `docs/v2` (mirrored under `docs/gen2/`). The earlier 1.x `standard-stack`
 * lineage is preserved, unchanged, under a single clearly-labelled
 * "AUSUS 1.x (Legacy)" category so a new visitor never lands on Legacy content.
 */
const sidebars: SidebarsConfig = {
  docs: [
    'intro',
    {
      type: 'category',
      label: 'Getting Started',
      collapsed: false,
      items: ['gen2/QUICKSTART', 'gen2/first-project', 'gen2/tutorials/hello-invoice'],
    },
    {
      type: 'category',
      label: 'Concepts',
      collapsed: false,
      items: ['gen2/introduction', 'gen2/pipeline', 'gen2/capabilities'],
    },
    {
      type: 'category',
      label: 'Architecture',
      items: ['gen2/architecture'],
    },
    {
      type: 'category',
      label: 'Tutorials',
      collapsed: false,
      items: ['gen2/tutorials/hello-invoice'],
    },
    {
      type: 'category',
      label: 'Examples',
      items: ['gen2/reference-apps'],
    },
    {
      type: 'category',
      label: 'Reference',
      items: ['gen2/projection-queries', 'gen2/known-limits'],
    },
    {
      type: 'category',
      label: 'AUSUS 1.x (Legacy)',
      collapsed: true,
      items: [
        'glossary',
        {
          type: 'category',
          label: 'Getting Started (1.x)',
          items: [
            'getting-started/installation',
            'getting-started/first-app',
            'getting-started/hello-invoice',
            'getting-started/project-structure',
            'getting-started/sample-apps',
          ],
        },
        {
          type: 'category',
          label: 'Tutorial: Ticket System (1.x)',
          items: [
            'tutorial/index',
            'tutorial/installation',
            'tutorial/domain',
            'tutorial/persistence',
            'tutorial/http-api',
            'tutorial/react-ui',
            'tutorial/troubleshooting',
          ],
        },
        {
          type: 'category',
          label: 'Core Concepts (1.x)',
          items: [
            'concepts/metadata-graph',
            'concepts/plugins',
            'concepts/entities-fields-actions',
            'concepts/workflows',
            'concepts/policies',
            'concepts/projections',
          ],
        },
        {
          type: 'category',
          label: 'Backend (1.x)',
          items: [
            'backend/php-dsl',
            'backend/runtime',
            'backend/sql-persistence',
            'backend/http-api',
          ],
        },
        {
          type: 'category',
          label: 'Frontend (1.x)',
          items: ['frontend/viewschema', 'frontend/react-renderer'],
        },
        {
          type: 'category',
          label: 'Packages (1.x)',
          items: ['packages/index'],
        },
        {
          type: 'category',
          label: 'Reference (1.x)',
          items: [
            'reference/application',
            'reference/configuration',
            'reference/dsl',
            'reference/http-routes',
            'reference/view-schema-wire',
            'reference/errors',
          ],
        },
        {
          type: 'category',
          label: 'Release Notes (1.x)',
          items: [
            'releases/v1.1.0',
            'releases/v1.0.1',
            'releases/v1.0.0',
            'releases/v0.2.0-rc.1',
            'releases/v0.2.0-beta.1',
            'releases/v0.2.0-alpha.5',
            'releases/v0.2.0-alpha.4',
            'releases/v0.1.1',
            'releases/v0.1.0',
          ],
        },
        {
          type: 'category',
          label: 'Operations (1.x)',
          items: [
            'operations/deployment',
            'operations/authenticated-gateway',
            'operations/publication-runbook',
            'operations/release-rehearsal',
            'operations/package-integrity',
          ],
        },
        {
          type: 'category',
          label: 'RFCs (1.x)',
          items: ['rfc/index', 'rfc/implemented', 'rfc/planned'],
        },
      ],
    },
  ],
};

export default sidebars;
