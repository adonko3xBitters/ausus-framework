// IMPLEMENTATION-003 — the ONLY door to the backend: the api-runtime HTTP API.
// No direct schema reads, no compiler, no repository, no runtime entity.

import type {
  ApiResponse,
  EntitySchemaResponse,
  InvokeResponse,
  ProjectionResponse,
} from '../types.ts';

export interface FetchResponse {
  status: number;
  json: () => Promise<unknown>;
}

export type FetchLike = (
  url: string,
  init?: { method?: string; headers?: Record<string, string>; body?: string },
) => Promise<FetchResponse>;

export interface RuntimeClientOptions {
  baseUrl?: string;
  headers?: Record<string, string>;
  fetchFn?: FetchLike;
}

export class RuntimeClient {
  private readonly base: string;
  private readonly headers: Record<string, string>;
  private readonly fetchFn: FetchLike;

  constructor(options: RuntimeClientOptions = {}) {
    this.base = (options.baseUrl ?? '').replace(/\/+$/, '');
    this.headers = options.headers ?? {};
    this.fetchFn = options.fetchFn ?? (globalThis.fetch as unknown as FetchLike);
  }

  async getEntitySchema(entity: string): Promise<ApiResponse<EntitySchemaResponse>> {
    const res = await this.fetchFn(`${this.base}/api/entities/${encodeURIComponent(entity)}`, {
      method: 'GET',
      headers: this.headers,
    });
    return { status: res.status, body: (await res.json()) as EntitySchemaResponse };
  }

  async readProjection(
    entity: string,
    projection: string,
    params: Record<string, string> = {},
  ): Promise<ApiResponse<ProjectionResponse>> {
    const qs = Object.keys(params).length
      ? '?' + new URLSearchParams(params).toString()
      : '';
    const res = await this.fetchFn(
      `${this.base}/api/entities/${encodeURIComponent(entity)}/projections/${encodeURIComponent(projection)}${qs}`,
      { method: 'GET', headers: this.headers },
    );
    return { status: res.status, body: (await res.json()) as ProjectionResponse };
  }

  async invokeAction(
    entity: string,
    action: string,
    inputs: Record<string, unknown>,
  ): Promise<ApiResponse<InvokeResponse>> {
    const res = await this.fetchFn(
      `${this.base}/api/entities/${encodeURIComponent(entity)}/actions/${encodeURIComponent(action)}`,
      {
        method: 'POST',
        headers: { ...this.headers, 'content-type': 'application/json' },
        body: JSON.stringify({ inputs }),
      },
    );
    return { status: res.status, body: (await res.json()) as InvokeResponse };
  }
}
