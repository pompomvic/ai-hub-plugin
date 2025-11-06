import { useMemo, useState } from 'react';

type StatusSnapshot = {
  last_sync?: string | null;
  last_error?: string | null;
};

type DashboardEntry = {
  slug?: string;
  label?: string;
  name?: string;
  description?: string;
};

type RestManifestResponse = {
  dashboards?: DashboardEntry[];
};

export type BootPayload = {
  status?: StatusSnapshot;
  restUrl: string;
  nonce: string;
};

const formatDate = (value: string | null | undefined): string => {
  if (!value) {
    return '—';
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }
  return parsed.toLocaleString();
};

export default function App({ data }: { data: BootPayload }): JSX.Element {
  const status = data.status ?? {};
  const [dashboards, setDashboards] = useState<DashboardEntry[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const lastSync = useMemo(() => formatDate(status.last_sync), [status.last_sync]);
  const lastError = status.last_error ?? '—';

  const handleLoadDashboards = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(data.restUrl, {
        headers: {
          'X-WP-Nonce': data.nonce,
          Accept: 'application/json',
        },
        credentials: 'same-origin',
      });
      if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
      }
      const payload: RestManifestResponse = await response.json();
      setDashboards(Array.isArray(payload.dashboards) ? payload.dashboards : []);
    } catch (requestError) {
      const message = requestError instanceof Error ? requestError.message : 'Unable to load dashboards.';
      setError(message);
      setDashboards([]);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="ai-hub-admin">
      <section className="card">
        <h2>{window.wp?.i18n?.__?.('Automation Status', 'ai-hub-seo') ?? 'Automation Status'}</h2>
        <table className="widefat striped">
          <tbody>
            <tr>
              <th scope="row">Last sync</th>
              <td>{lastSync}</td>
            </tr>
            <tr>
              <th scope="row">Last error</th>
              <td>{lastError || '—'}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section className="card" style={{ marginTop: '1.5rem' }}>
        <h2>{window.wp?.i18n?.__?.('Dashboard Manifest', 'ai-hub-seo') ?? 'Dashboard Manifest'}</h2>
        <p>Fetches the dashboard manifest exposed by the AI Hub tenant using the configured automation token.</p>
        <button
          type="button"
          className="button button-primary"
          onClick={() => void handleLoadDashboards()}
          disabled={loading}
        >
          {loading ? 'Loading…' : 'Load dashboards'}
        </button>
        {error && (
          <p className="notice notice-error" style={{ marginTop: '1rem' }}>
            {error}
          </p>
        )}
        {!error && dashboards.length === 0 && !loading && (
          <p className="description" style={{ marginTop: '1rem' }}>
            No dashboards were returned for this tenant.
          </p>
        )}
        {dashboards.length > 0 && (
          <ul style={{ marginTop: '1rem', listStyle: 'disc', paddingLeft: '1.5rem' }}>
            {dashboards.map((entry, index) => (
              <li key={entry.slug ?? entry.name ?? `${index}`}>
                <strong>{entry.label ?? entry.name ?? entry.slug ?? 'Untitled dashboard'}</strong>
                {entry.description ? (
                  <span style={{ display: 'block' }}>{entry.description}</span>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
