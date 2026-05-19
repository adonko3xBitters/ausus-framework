// Public surface — RFC-004 §0.2 / docs/RENDERER-REACT-DESIGN.md §2.2
export { AususProvider, useAusus } from "./context.js";
export { useViewSchema, useAction } from "./hooks.js";
export { ViewSchemaConsumer } from "./ViewSchemaConsumer.js";
export { ListView, DetailView, ActionModal, WorkflowBadge, FieldDisplay } from "./components.js";
export type {
  ViewSchema, FieldDescriptor, ActionDescriptor, FilterDescriptor,
  Reference, ActionResult, Fetcher,
} from "./types.js";
