// IMPLEMENTATION-003 — public surface of the AUSUS React Renderer (L5).
// Consumes the api-runtime HTTP contract only.

export { RuntimeClient } from './api/RuntimeClient.ts';
export type { FetchLike, RuntimeClientOptions } from './api/RuntimeClient.ts';
export { buildProjectionParams } from './api/projectionQuery.ts';
export type {
  FilterCondition,
  FilterOperator,
  ProjectionQuerySpec,
  SortSpec,
} from './api/projectionQuery.ts';
export { EntityRegistry } from './discovery/EntityRegistry.ts';
export type { NavigationEntry } from './discovery/EntityRegistry.ts';

export { buildProjectionTable, cellText } from './view/projectionModel.ts';
export type { TableModel } from './view/projectionModel.ts';
export { buildActionForm, buildInputs, validate } from './view/actionModel.ts';
export type { FormField, FormModel } from './view/actionModel.ts';

export { ProjectionTable } from './components/ProjectionTable.tsx';
export { ActionForm } from './components/ActionForm.tsx';
export { ProjectionPage } from './pages/ProjectionPage.tsx';
export { EntityPage } from './pages/EntityPage.tsx';
export { RendererApp } from './app/RendererApp.tsx';

export type * from './types.ts';
