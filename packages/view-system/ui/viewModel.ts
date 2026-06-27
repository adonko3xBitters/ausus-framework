// IMPLEMENTATION-004 — pure consumption of a ViewDefinition (the JSON produced
// by Ausus\View::toArray). Flattens a view into a render plan the React Renderer
// can drive. No React, no business knowledge — purely structural.

export interface SectionJson {
  title: string;
  entity: string;
  kind: 'projection' | 'action';
  projection: string | null;
  action: string | null;
}

export interface PageJson {
  identity: string;
  title: string;
  sections: SectionJson[];
}

export interface ViewJson {
  identity: string;
  title: string;
  pages: PageJson[];
}

export interface FlatSection {
  pageIdentity: string;
  pageTitle: string;
  title: string;
  entity: string;
  kind: 'projection' | 'action';
  name: string;
}

export interface FlatPage {
  identity: string;
  title: string;
  sections: FlatSection[];
}

export interface FlatView {
  identity: string;
  title: string;
  pages: FlatPage[];
}

export function flattenView(view: ViewJson): FlatView {
  return {
    identity: view.identity,
    title: view.title,
    pages: view.pages.map((page) => ({
      identity: page.identity,
      title: page.title,
      sections: page.sections.map((section) => ({
        pageIdentity: page.identity,
        pageTitle: page.title,
        title: section.title,
        entity: section.entity,
        kind: section.kind,
        name: section.kind === 'projection' ? (section.projection as string) : (section.action as string),
      })),
    })),
  };
}

/** Page-level navigation derived from a view (no backend call). */
export function viewNavigation(view: ViewJson): Array<{ identity: string; title: string }> {
  return view.pages.map((page) => ({ identity: page.identity, title: page.title }));
}
