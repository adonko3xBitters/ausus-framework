import type {SidebarsConfig} from '@docusaurus/plugin-content-docs';

/**
 * AUSUS documentation sidebar.
 *
 * One explicit sidebar, grouped into categories that mirror the information
 * architecture (Getting Started -> Concepts -> Backend -> Frontend -> Packages
 * -> Reference -> Release Notes -> Operations -> RFCs). Explicit ordering is
 * used over autogeneration so navigation stays stable as pages are added.
 */
const sidebars: SidebarsConfig = {
  docs: [
    'intro',
    'glossary',
    {
      type: 'category',
      label: 'Getting Started',
      collapsed: false,
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
      label: 'Tutorial: Ticket System',
      collapsed: false,
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
      label: 'Core Concepts',
      collapsed: false,
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
      label: 'Backend',
      items: [
        'backend/php-dsl',
        'backend/runtime',
        'backend/sql-persistence',
        'backend/http-api',
      ],
    },
    {
      type: 'category',
      label: 'Frontend',
      items: [
        'frontend/viewschema',
        'frontend/react-renderer',
      ],
    },
    {
      type: 'category',
      label: 'Packages',
      items: [
        'packages/index',
      ],
    },
    {
      type: 'category',
      label: 'Reference',
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
      label: 'Release Notes',
      items: [
        'releases/v0.2.0-beta.1',
        'releases/v0.2.0-alpha.5',
        'releases/v0.2.0-alpha.4',
        'releases/v0.1.1',
        'releases/v0.1.0',
      ],
    },
    {
      type: 'category',
      label: 'Operations',
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
      label: 'RFCs',
      items: [
        'rfc/index',
        'rfc/implemented',
        'rfc/planned',
      ],
    },
  ],
};

export default sidebars;
