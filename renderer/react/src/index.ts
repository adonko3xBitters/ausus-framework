// Public surface — RFC-004 §0.2 / docs/RENDERER-REACT-DESIGN.md §2.2
export { AususProvider, useAusus } from "./context.js";
export { useViewSchema, useAction } from "./hooks.js";
export { ViewSchemaConsumer } from "./ViewSchemaConsumer.js";
export { ListView, DetailView, ActionModal, WorkflowBadge, FieldDisplay } from "./components.js";
// Form helpers — pure functions reused by ActionModal, exported so consumers
// can build custom action UIs while staying on the same payload contract.
export {
  inputDefault, isRequired, shapeValue, validateInputs,
  // Added in v0.2 for `Action::update(...)` support (ADR-0002).
  initialFor, isUnchanged, buildCreatePayload, buildUpdatePayload,
} from "./components.js";
export type {
  ViewSchema, FieldDescriptor, ActionDescriptor, FilterDescriptor,
  Reference, ActionResult, Fetcher,
} from "./types.js";
