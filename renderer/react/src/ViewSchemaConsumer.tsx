"use client";
import React from "react";
import { useViewSchema } from "./hooks.js";
import { ListView, DetailView } from "./components.js";
import type { Reference } from "./types.js";

/**
 * ViewSchemaConsumer — fetches a ViewSchema and dispatches to a view.
 * Dispatch rule:
 *   - if data.items exists → ListView
 *   - if data.item  exists → DetailView (subject prop required)
 *   - else                 → fallback message
 */
export function ViewSchemaConsumer(props: {
  projection: string;
  subject?: Reference;
}) {
  const { schema, loading, error, refetch } = useViewSchema(props.projection, props.subject);

  if (loading) {
    return <div className="ausus-loading">Loading…</div>;
  }
  if (error) {
    return (
      <div className="ausus-error">
        <strong>Could not load {props.projection}</strong>
        <p>{error.message}</p>
        <button className="ausus-btn ausus-btn--ghost" onClick={refetch}>Retry</button>
      </div>
    );
  }
  if (!schema) return null;

  // Dispatch by data shape (RFC-004 §8.1)
  if (schema.data && "items" in schema.data) {
    return <ListView schema={schema} onRefetch={refetch} />;
  }
  if (schema.data && "item" in schema.data) {
    if (!props.subject) return <div className="ausus-error">DetailView requires a subject prop.</div>;
    return <DetailView schema={schema} subject={props.subject} onRefetch={refetch} />;
  }
  return <div className="ausus-empty">Unsupported view (no data).</div>;
}
