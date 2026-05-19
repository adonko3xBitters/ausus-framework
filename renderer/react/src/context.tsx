import React from "react";
import { createContext, useContext, ReactNode } from "react";
import type { Fetcher } from "./types.js";

/** AususContext value — injected once at the root via <AususProvider>. */
export interface AususContextValue {
  apiBaseUrl: string;
  tenant: string;
  fetcher: Fetcher;
}

const AususContext = createContext<AususContextValue | null>(null);

export interface AususProviderProps {
  apiBaseUrl: string;
  tenant: string;
  fetcher?: Fetcher;
  children: ReactNode;
}

export function AususProvider(props: AususProviderProps) {
  const value: AususContextValue = {
    apiBaseUrl: props.apiBaseUrl,
    tenant: props.tenant,
    fetcher: props.fetcher ?? ((url, init) => fetch(url, init)),
  };
  return <AususContext.Provider value={value}>{props.children}</AususContext.Provider>;
}

export function useAusus(): AususContextValue {
  const v = useContext(AususContext);
  if (!v) throw new Error("useAusus() must be called inside <AususProvider>");
  return v;
}
