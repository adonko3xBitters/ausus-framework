// Hello Invoice — the AUSUS 2.0 React UI.
//
// The renderer speaks the api-runtime HTTP contract ONLY: give it a base URL
// and it discovers the `invoice` entity, its projections, and its actions, then
// renders navigation, tables, and forms. It knows nothing of the kernel, the
// engine, the compiler, or the repository.
import { RuntimeClient, RendererApp } from '@ausus/react-renderer';

// Point at the Hello Invoice API server (php -S … bin/server.php).
const client = new RuntimeClient({ baseUrl: 'http://127.0.0.1:8080' });

export default function App() {
  return <RendererApp client={client} entities={['invoice']} />;
}
