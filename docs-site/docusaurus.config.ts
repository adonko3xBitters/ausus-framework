import {themes as prismThemes} from 'prism-react-renderer';
import type {Config} from '@docusaurus/types';
import type * as Preset from '@docusaurus/preset-classic';

// Node.js context — no browser/JSX code here.

const GITHUB_REPO = 'https://github.com/adonko3xBitters/ausus-framework';

const config: Config = {
  title: 'AUSUS',
  tagline: 'Compile immutable metadata into running applications.',
  favicon: 'img/favicon.ico',

  future: {
    v4: true,
  },

  // Production URL — placeholder until a docs host is chosen.
  url: 'https://ausus-framework.pages.dev',
  baseUrl: '/',

  organizationName: 'adonko3xBitters',
  projectName: 'ausus-framework',

  // Broken-link detection is a build gate — keep it strict.
  onBrokenLinks: 'throw',
  onBrokenAnchors: 'warn',

  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'fr'],
  },

  // Parse `.md` files as CommonMark (not MDX). The documentation is prose +
  // code blocks with no JSX, and CommonMark handles `{...}` placeholders and
  // explicit `{#anchor}` heading ids without MDX expression parsing. `.mdx`
  // files, if added later, still get full MDX.
  markdown: {
    format: 'detect',
    mermaid: true,
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
    '@docusaurus/theme-mermaid',
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
        {type: 'doc', docId: 'gen2/QUICKSTART', label: 'Quick Start', position: 'left'},
        {type: 'doc', docId: 'gen2/introduction', label: 'Concepts', position: 'left'},
        {type: 'doc', docId: 'gen2/architecture', label: 'Architecture', position: 'left'},
        {type: 'docSidebar', sidebarId: 'docs', label: 'All Docs', position: 'left'},
        {href: GITHUB_REPO, label: 'GitHub', position: 'right'},
        {
          type: 'localeDropdown',
          position: 'right',
        },
      ],
    },
    footer: {
      style: 'dark',
      links: [
        {
          title: 'Docs',
          items: [
            {label: 'Quick Start', to: '/gen2/QUICKSTART'},
            {label: 'Architecture', to: '/gen2/architecture'},
            {label: 'Capabilities', to: '/gen2/capabilities'},
            {label: 'Known limits', to: '/gen2/known-limits'},
          ],
        },
        {
          title: 'Ecosystem',
          items: [
            {label: 'GitHub', href: GITHUB_REPO},
            {label: 'Packagist (ausus/*)', href: 'https://packagist.org/search/?query=ausus'},
            {label: 'npm (@ausus/react-renderer)', href: 'https://www.npmjs.com/package/@ausus/react-renderer'},
          ],
        },
        {
          title: 'Project',
          items: [
            {label: 'Roadmap & limits', to: '/gen2/known-limits'},
            {label: 'Contributing', href: `${GITHUB_REPO}/blob/main/CONTRIBUTING.md`},
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
