import React from "react";
import { useState } from "react";
import { AususProvider, ViewSchemaConsumer } from "@ausus/renderer-react";
import type { Reference, Fetcher } from "@ausus/renderer-react/types";

/**
 * Demo root — hosts a tiny routing state (list ↔ detail).
 * Consumers in production use React Router / Next.js; the renderer ships no router.
 */
export function App(props: { fetcher: Fetcher; initialView?: View }) {
  const [view, setView] = useState<View>(props.initialView ?? { kind: "list" });

  return (
    <AususProvider apiBaseUrl="/api" tenant="acme" fetcher={props.fetcher}>
      <header className="ausus-header">
        <strong>AUSUS demo</strong> · tenant <code>acme</code>
        {view.kind === "detail" && (
          <button
            type="button"
            className="ausus-btn ausus-btn--ghost"
            onClick={() => setView({ kind: "list" })}
          >
            ← Back to list
          </button>
        )}
      </header>

      {view.kind === "list" && (
        <ViewSchemaConsumer projection="billing.invoice.summary" />
      )}

      {view.kind === "detail" && (
        <ViewSchemaConsumer projection="billing.invoice.detail" subject={view.subject} />
      )}
    </AususProvider>
  );
}

export type View =
  | { kind: "list" }
  | { kind: "detail"; subject: Reference };
