import {themes as prismThemes} from 'prism-react-renderer';
import type {Config} from '@docusaurus/types';
import type * as Preset from '@docusaurus/preset-classic';

// Node.js context — no browser/JSX code here.

const GITHUB_REPO = 'https://github.com/adonko3xBitters/ausus-framework';

const config: Config = {
  title: 'AUSUS',
  tagline: 'A metadata-first, plugin-first PHP framework for enterprise applications.',
  favicon: 'img/favicon.ico',

  future: {
    v4: true,
  },

  // Production URL — placeholder until a docs host is chosen.
  url: 'https://adonko3xbitters.github.io',
  baseUrl: '/',

  organizationName: 'adonko3xBitters',
  projectName: 'ausus-framework',

  // Broken-link detection is a build gate — keep it strict.
  onBrokenLinks: 'throw',
  onBrokenAnchors: 'warn',

  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
  },

  // Parse `.md` files as CommonMark (not MDX). The documentation is prose +
  // code blocks with no JSX, and CommonMark handles `{...}` placeholders and
  // explicit `{#anchor}` heading ids without MDX expression parsing. `.mdx`
  // files, if added later, still get full MDX.
  markdown: {
    format: 'detect',
  },

  presets: [
    [
      'classic',
      {
        docs: {
          // Docs-only mode: documentation is served from the site root.
          routeBasePath: '/',
          sidebarPath: './sidebars.ts',
          editUrl: `${GITHUB_REPO}/tree/main/docs-site/`,
          showLastUpdateTime: true,
        },
        blog: false,
        theme: {
          customCss: './src/css/custom.css',
        },
      } satisfies Preset.Options,
    ],
  ],

  themes: [
    [
      require.resolve('@easyops-cn/docusaurus-search-local'),
      {
        hashed: true,
        indexDocs: true,
        indexBlog: false,
        docsRouteBasePath: '/',
        highlightSearchTermsOnTargetPage: true,
      },
    ],
  ],

  themeConfig: {
    colorMode: {
      defaultMode: 'light',
      respectPrefersColorScheme: true,
    },
    navbar: {
      title: 'AUSUS',
      logo: {
        alt: 'AUSUS',
        src: 'img/logo.svg',
      },
      items: [
        {type: 'doc', docId: 'getting-started/installation', label: 'Getting Started', position: 'left'},
        {type: 'doc', docId: 'concepts/metadata-graph', label: 'Concepts', position: 'left'},
        {type: 'doc', docId: 'packages/index', label: 'Packages', position: 'left'},
        {type: 'doc', docId: 'releases/v0.1.0', label: 'Release Notes', position: 'left'},
        {type: 'doc', docId: 'operations/publication-runbook', label: 'Operations', position: 'left'},
        {type: 'docSidebar', sidebarId: 'docs', label: 'All Docs', position: 'left'},
        {href: GITHUB_REPO, label: 'GitHub', position: 'right'},
      ],
    },
    footer: {
      style: 'dark',
      links: [
        {
          title: 'Docs',
          items: [
            {label: 'Installation', to: '/getting-started/installation'},
            {label: 'HelloInvoice tutorial', to: '/getting-started/hello-invoice'},
            {label: 'Packages', to: '/packages/'},
            {label: 'Release Notes v0.1.0', to: '/releases/v0.1.0'},
          ],
        },
        {
          title: 'Ecosystem',
          items: [
            {label: 'GitHub', href: GITHUB_REPO},
            {label: 'Packagist (ausus/*)', href: 'https://packagist.org/search/?query=ausus'},
            {label: 'npm (@ausus/renderer-react)', href: 'https://www.npmjs.com/package/@ausus/renderer-react'},
          ],
        },
        {
          title: 'Project',
          items: [
            {label: 'RFCs', to: '/rfc/'},
            {label: 'Publication Runbook', to: '/operations/publication-runbook'},
          ],
        },
      ],
      copyright: `Copyright © ${new Date().getFullYear()} AUSUS Framework Contributors. MIT License. Built with Docusaurus.`,
    },
    prism: {
      theme: prismThemes.github,
      darkTheme: prismThemes.dracula,
      additionalLanguages: ['php', 'bash', 'json', 'diff'],
    },
  } satisfies Preset.ThemeConfig,
};

export default config;
