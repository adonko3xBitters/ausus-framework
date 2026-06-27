// IMPLEMENTATION-003 — discovers entity capabilities from the API alone.
// The set of entity names is configuration (data), not code: a newly compiled
// entity becomes visible by adding its name here — no component changes.

import type { RuntimeClient } from '../api/RuntimeClient.ts';
import type { EntitySchemaResponse } from '../types.ts';

export interface NavigationEntry {
  entity: string;
  actions: string[];
  projections: string[];
}

export class EntityRegistry {
  private readonly client: RuntimeClient;
  private readonly entities: string[];

  constructor(client: RuntimeClient, entities: string[]) {
    this.client = client;
    this.entities = entities;
  }

  listEntities(): string[] {
    return [...this.entities];
  }

  async discover(entity: string): Promise<EntitySchemaResponse> {
    const res = await this.client.getEntitySchema(entity);
    if (res.status !== 200) {
      throw new Error(`discover '${entity}': HTTP ${res.status}`);
    }
    return res.body;
  }

  async navigation(): Promise<NavigationEntry[]> {
    const entries: NavigationEntry[] = [];
    for (const entity of this.entities) {
      const schema = await this.discover(entity);
      entries.push({
        entity,
        actions: schema.actions.map((a) => a.name),
        projections: schema.projections.map((p) => p.name),
      });
    }
    return entries;
  }
}
