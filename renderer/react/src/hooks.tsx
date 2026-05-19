"use client";
import React from "react";
import { useCallback, useEffect, useState } from "react";
import { useAusus } from "./context.js";
import type { Reference, ViewSchema, ActionResult } from "./types.js";

interface SchemaState {
  loading: boolean;
  schema: ViewSchema | null;
  error: { message: string } | null;
}

/**
 * useViewSchema — fetch a Projection's ViewSchema from the L4 API.
 * RFC-004 §3.1 + §11 transformation contract on the server side.
 */
export function useViewSchema(projection: string, subject?: Reference) {
  const { apiBaseUrl, tenant, fetcher } = useAusus();
  const [state, setState] = useState<SchemaState>({ loading: true, schema: null, error: null });
  const [tick, setTick] = useState(0);

  const refetch = useCallback(() => setTick(t => t + 1), []);

  useEffect(() => {
    let cancelled = false;
    setState({ loading: true, schema: null, error: null });

    const params = new URLSearchParams({
      locale: "en-US",
      renderer: "react.web.v1",
      acceptSchemaVersions: "1.0.0",
    });
    if (subject) params.set("subject", subject.identityHandle);

    const url = `${apiBaseUrl}/projections/${projection}?${params}`;
    fetcher(url, { headers: { "X-Tenant-ID": tenant } })
      .then(async r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
      })
      .then((schema: ViewSchema) => {
        if (cancelled) return;
        if (schema.schemaVersion && !schema.schemaVersion.startsWith("1.0")) {
          setState({ loading: false, schema: null, error: { message: `incompatible schemaVersion=${schema.schemaVersion}` } });
          return;
        }
        setState({ loading: false, schema, error: null });
      })
      .catch((e: Error) => {
        if (cancelled) return;
        setState({ loading: false, schema: null, error: { message: e.message } });
      });

    return () => { cancelled = true; };
  }, [projection, subject?.identityHandle, tick, apiBaseUrl, tenant, fetcher]);

  return { ...state, refetch };
}

interface ActionState {
  pending: boolean;
  lastError: { kind: string; message: string } | null;
}

/**
 * useAction — invoke an Action against the L4 API.
 * No optimistic UI in V0 (renderer-design §10): every call awaits server response.
 */
export function useAction(actionFqn: string) {
  const { apiBaseUrl, tenant, fetcher } = useAusus();
  const [s, setS] = useState<ActionState>({ pending: false, lastError: null });

  const invoke = useCallback(
    async (args: { subject?: Reference; inputs: Record<string, unknown> }): Promise<ActionResult> => {
      setS({ pending: true, lastError: null });
      try {
        const r = await fetcher(`${apiBaseUrl}/actions/${actionFqn}`, {
          method: "POST",
          headers: { "X-Tenant-ID": tenant, "Content-Type": "application/json" },
          body: JSON.stringify({ subject: args.subject ?? null, inputs: args.inputs }),
        });
        const result = (await r.json()) as ActionResult;
        setS({
          pending: false,
          lastError: result.ok ? null : result.error,
        });
        return result;
      } catch (e: any) {
        const err = { kind: "NetworkError", message: e?.message ?? "unknown" };
        setS({ pending: false, lastError: err });
        return { ok: false, error: err };
      }
    },
    [actionFqn, apiBaseUrl, tenant, fetcher],
  );

  return { invoke, pending: s.pending, lastError: s.lastError };
}
