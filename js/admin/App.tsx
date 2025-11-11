import './styles.css';
import { type CSSProperties, useCallback, useEffect, useMemo, useRef, useState } from 'react';

type StatusSnapshot = {
  last_sync?: string | null;
  last_error?: string | null;
};

type BrandTheme = {
  primary?: string;
  accent?: string;
  logo?: string;
  label?: string;
};

type TenantInfo = {
  seatLimit?: number | null;
  seatsInUse?: number | null;
};

type DashboardMetric = {
  label: string;
  value: string | number;
  delta?: string;
  trend?: 'up' | 'down' | 'flat';
};

type DashboardAction = {
  key?: string;
  label: string;
  description?: string;
  href?: string;
};

type DashboardEntry = {
  slug: string;
  label: string;
  description?: string;
  category?: string;
  badges?: string[];
  metrics?: DashboardMetric[];
  icon?: string;
  updated_at?: string;
};

type DashboardDetail = DashboardEntry & {
  insights?: string[];
  actions?: DashboardAction[];
  notes?: string;
};

type ThemeStyle = CSSProperties & Record<'--ai-hub-primary' | '--ai-hub-accent', string>;

type BootPayload = {
  status?: StatusSnapshot;
  rest?: {
    dashboards?: string;
    dashboardDetail?: string;
  };
  nonce: string;
  view?: 'dashboards' | 'dashboard' | 'settings' | 'access';
  brand?: BrandTheme;
  tenant?: TenantInfo;
  portalUrl?: string | null;
  activeDashboardSlug?: string | null;
  activeDashboardLabel?: string | null;
};

type DashboardsResponse = {
  dashboards?: DashboardEntry[];
  error?: string;
};

type DashboardDetailResponse = {
  dashboard?: DashboardDetail;
  error?: string;
};

const translate = (text: string) => {
  const translator = window.wp?.i18n?.__;
  if (typeof translator === 'function') {
    return translator(text, 'ai-hub-seo');
  }
  return text;
};

const resolveSlug = (entry: Partial<DashboardEntry>): string => {
  const provided = typeof entry.slug === 'string' ? entry.slug.trim() : '';
  if (provided) {
    return provided;
  }
  return String(entry.label ?? entry.description ?? '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '-');
};

const formatDateTime = (value?: string | null): string => {
  if (!value) {
    return '—';
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(parsed);
};

const formatRelative = (value?: string | null): string => {
  if (!value) {
    return translate('Not available');
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }
  const diff = Date.now() - parsed.getTime();
  const minutes = Math.round(diff / 60000);
  if (minutes < 1) {
    return translate('Just now');
  }
  if (minutes < 60) {
    return translate('%d minutes ago').replace('%d', String(minutes));
  }
  const hours = Math.round(minutes / 60);
  if (hours < 24) {
    return translate('%d hours ago').replace('%d', String(hours));
  }
  const days = Math.round(hours / 24);
  return translate('%d days ago').replace('%d', String(days));
};

const normaliseDashboard = (entry: Partial<DashboardEntry>): DashboardEntry => {
  const slug = resolveSlug(entry);
  return {
    slug,
    label: entry.label ?? entry.slug ?? translate('Untitled dashboard'),
    description: entry.description ?? '',
    category: entry.category,
    badges: entry.badges ?? (entry.category ? [entry.category] : []),
    metrics: entry.metrics ?? [],
    icon: entry.icon,
    updated_at: entry.updated_at,
  };
};

export default function App({ data }: { data: BootPayload }): JSX.Element | null {
  const view = data.view ?? 'dashboards';
  const singleDashboardMode = view === 'dashboard' && Boolean(data.activeDashboardSlug);
  if (view === 'access') {
    return null;
  }

  const [dashboards, setDashboards] = useState<DashboardEntry[]>([]);
  const [selectedSlug, setSelectedSlug] = useState<string | null>(data.activeDashboardSlug ?? null);
  const [detailMap, setDetailMap] = useState<Record<string, DashboardDetail>>({});
  const [manifestLoading, setManifestLoading] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const [manifestError, setManifestError] = useState<string | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const autoLoadRef = useRef(false);

  const themeStyle = useMemo<ThemeStyle>(() => {
    const primary = data.brand?.primary ?? '#0ABAB5';
    const accent = data.brand?.accent ?? '#FFC845';
    return {
      '--ai-hub-primary': primary,
      '--ai-hub-accent': accent,
    };
  }, [data.brand]);

  const loadDashboards = useCallback(async () => {
    if (!data.rest?.dashboards) {
      return;
    }
    setManifestLoading(true);
    setManifestError(null);
    try {
      const response = await fetch(data.rest.dashboards, {
        headers: {
          'X-WP-Nonce': data.nonce,
          Accept: 'application/json',
        },
        credentials: 'same-origin',
      });
      const payload = (await response.json()) as DashboardsResponse;
      if (!response.ok || payload.error) {
        throw new Error(payload.error ?? translate('Unable to load dashboards.'));
      }
      const items = Array.isArray(payload.dashboards) ? payload.dashboards : [];
      const normalised = items
        .map((item) => normaliseDashboard(item))
        .filter((item) => item.slug);
      setDashboards(normalised);
      if (normalised.length > 0) {
        setSelectedSlug((previous) => previous ?? normalised[0].slug);
      } else {
        setSelectedSlug(null);
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : translate('Unexpected error occurred.');
      setDashboards([]);
      setManifestError(message);
    } finally {
      setManifestLoading(false);
    }
  }, [data.nonce, data.rest?.dashboards]);

  const loadDashboardDetail = useCallback(
    async (slug: string) => {
      if (!data.rest?.dashboardDetail) {
        return;
      }
      setDetailError(null);
      setDetailLoading(true);
      try {
        const response = await fetch(`${data.rest.dashboardDetail}${encodeURIComponent(slug)}`, {
          headers: {
            'X-WP-Nonce': data.nonce,
            Accept: 'application/json',
          },
          credentials: 'same-origin',
        });
        const payload = (await response.json()) as DashboardDetailResponse;
        if (!response.ok || payload.error || !payload.dashboard) {
          throw new Error(payload.error ?? translate('Unable to load dashboard details.'));
        }
        const detail = {
          ...normaliseDashboard(payload.dashboard),
          insights: payload.dashboard.insights ?? [],
          actions: payload.dashboard.actions ?? [],
          notes: payload.dashboard.notes,
        };
        setDetailMap((prev) => ({ ...prev, [slug]: detail }));
      } catch (error) {
        const message = error instanceof Error ? error.message : translate('Unable to load dashboard details.');
        setDetailError(message);
      } finally {
        setDetailLoading(false);
      }
    },
    [data.nonce, data.rest?.dashboardDetail]
  );

  const shouldLoadManifest = view === 'dashboards' || view === 'settings';

  useEffect(() => {
    if (shouldLoadManifest && !autoLoadRef.current) {
      autoLoadRef.current = true;
      void loadDashboards();
    }
  }, [shouldLoadManifest, loadDashboards]);

  useEffect(() => {
    if (dashboards.length === 0) {
      return;
    }
    if (!selectedSlug) {
      setSelectedSlug(dashboards[0].slug);
      return;
    }
    const stillExists = dashboards.some((entry) => entry.slug === selectedSlug);
    if (!stillExists) {
      setSelectedSlug(dashboards[0].slug);
    }
  }, [dashboards, selectedSlug]);

  const filteredDashboards = useMemo(() => {
    if (!query.trim()) {
      return dashboards;
    }
    const needle = query.trim().toLowerCase();
    return dashboards.filter((entry) => {
      const haystack = [entry.label, entry.description, entry.category].filter(Boolean).join(' ').toLowerCase();
      return haystack.includes(needle);
    });
  }, [dashboards, query]);

  const activeDetail = selectedSlug ? detailMap[selectedSlug] : null;

  return (
    <div className="ai-hub-app" style={themeStyle} data-view={view}>
      <BrandHeader brand={data.brand} tenant={data.tenant} portalUrl={data.portalUrl} />
      <StatusPanel status={data.status} tenant={data.tenant} />

      {manifestError && (
        <div className="ai-hub-error-banner" role="alert">
          <strong>{translate('Dashboard manifest error')}</strong>
          <p>{manifestError}</p>
        </div>
      )}

      {view === 'dashboards' || view === 'dashboard' ? (
        <DashboardWorkspace
          dashboards={filteredDashboards}
          selectedSlug={selectedSlug}
          onSelect={(slug) => {
            setSelectedSlug(slug);
            if (!detailMap[slug]) {
              void loadDashboardDetail(slug);
            }
          }}
          query={query}
          onQueryChange={setQuery}
          loading={manifestLoading}
          detail={activeDetail}
          detailLoading={detailLoading}
          detailError={detailError}
          onRefresh={loadDashboards}
          onLoadDetail={loadDashboardDetail}
          portalUrl={data.portalUrl}
          singleDashboardMode={singleDashboardMode}
          fallbackLabel={data.activeDashboardLabel}
        />
      ) : (
        <SettingsPreview
          dashboards={dashboards}
          loading={manifestLoading}
          onLoad={loadDashboards}
        />
      )}
    </div>
  );
}

function BrandHeader({
  brand,
  tenant,
  portalUrl,
}: {
  brand?: BrandTheme;
  tenant?: TenantInfo;
  portalUrl?: string | null;
}) {
  return (
    <header className="ai-hub-header">
      <div className="brand-mark">
        {brand?.logo ? (
          <img src={brand.logo} alt={brand.label ?? translate('Tenant logo')} />
        ) : (
          <div className="ai-hub-badge">{translate('AIMXB')}</div>
        )}
        <div>
          <h2 style={{ margin: 0 }}>{brand?.label ?? translate('AIMXB Dashboards')}</h2>
          <p style={{ margin: '0.2rem 0 0', opacity: 0.85 }}>
            {translate('Live metrics and actions streamed from AIMXB into WordPress.')}
          </p>
        </div>
      </div>
      {typeof tenant?.seatsInUse === 'number' && (
        <div className="ai-hub-seat-pill">
          {tenant.seatLimit
            ? `${tenant.seatsInUse}/${tenant.seatLimit} ${translate('seats used')}`
            : `${tenant.seatsInUse} ${translate('active users')}`}
        </div>
      )}
      {portalUrl && (
        <button
          type="button"
          className="ai-hub-action-button secondary"
          onClick={() => window.open(portalUrl, '_blank', 'noopener')}
        >
          {translate('Open AIMXB')}
        </button>
      )}
    </header>
  );
}

function StatusPanel({ status, tenant }: { status?: StatusSnapshot; tenant?: TenantInfo }) {
  return (
    <section className="ai-hub-status-grid" aria-label={translate('Automation status')}>
      <div className="ai-hub-status-card">
        <h3>{translate('Last Sync')}</h3>
        <strong>{formatDateTime(status?.last_sync)}</strong>
        <span>{formatRelative(status?.last_sync)}</span>
      </div>
      <div className="ai-hub-status-card">
        <h3>{translate('Last Error')}</h3>
        <strong>{status?.last_error ? translate('Needs attention') : translate('Healthy')}</strong>
        <span>{status?.last_error ?? translate('No protocol errors reported')}</span>
      </div>
      <div className="ai-hub-status-card">
        <h3>{translate('Seat Usage')}</h3>
        <strong>
          {typeof tenant?.seatsInUse === 'number'
            ? tenant.seatLimit
              ? `${tenant.seatsInUse}/${tenant.seatLimit}`
              : tenant.seatsInUse
            : '—'}
        </strong>
        <span>{translate('WordPress users with AIMXB access')}</span>
      </div>
    </section>
  );
}

function DashboardWorkspace({
  dashboards,
  selectedSlug,
  onSelect,
  query,
  onQueryChange,
  loading,
  detail,
  detailLoading,
  detailError,
  onRefresh,
  onLoadDetail,
  portalUrl,
  singleDashboardMode,
  fallbackLabel,
}: {
  dashboards: DashboardEntry[];
  selectedSlug: string | null;
  onSelect: (slug: string) => void;
  query: string;
  onQueryChange: (value: string) => void;
  loading: boolean;
  detail: DashboardDetail | null;
  detailLoading: boolean;
  detailError: string | null;
  onRefresh: () => Promise<void>;
  onLoadDetail: (slug: string) => Promise<void>;
  portalUrl?: string | null;
  singleDashboardMode: boolean;
  fallbackLabel?: string | null;
}) {
  useEffect(() => {
    if (selectedSlug && !detail) {
      void onLoadDetail(selectedSlug);
    }
  }, [selectedSlug, detail, onLoadDetail]);

  return (
    <section className={`ai-hub-dashboard-layout ${singleDashboardMode ? 'single-mode' : ''}`}>
      {!singleDashboardMode && (
        <aside className="ai-hub-dashboard-sidebar">
          <div className="ai-hub-search">
            <span role="img" aria-hidden="true">
              🔍
            </span>
            <input
              type="search"
              placeholder={translate('Search dashboards')}
              value={query}
              onChange={(event) => onQueryChange(event.target.value)}
              aria-label={translate('Search dashboards')}
            />
          </div>
          <button
            type="button"
            className="ai-hub-action-button secondary"
            onClick={() => void onRefresh()}
            disabled={loading}
          >
            {loading ? translate('Refreshing...') : translate('Refresh manifest')}
          </button>
          <div className="ai-hub-dashboard-list">
            {loading && dashboards.length === 0 && (
              <div className="ai-hub-loading">
                <div className="ai-hub-spinner" />
                {translate('Loading dashboards...')}
              </div>
            )}
            {!loading && dashboards.length === 0 && (
              <p className="description">{translate('No dashboards available for this tenant yet.')}</p>
            )}
            {dashboards.map((entry) => (
              <button
                type="button"
                key={entry.slug}
                className={`ai-hub-dashboard-card ${selectedSlug === entry.slug ? 'active' : ''}`}
                onClick={() => onSelect(entry.slug)}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <h4>{entry.label}</h4>
                  {entry.badges?.[0] && <span className="ai-hub-badge">{entry.badges[0]}</span>}
                </div>
                {entry.description && <p>{entry.description}</p>}
                {entry.metrics && entry.metrics.length > 0 && (
                  <div className="ai-hub-metric-row">
                    {entry.metrics.slice(0, 2).map((metric) => (
                      <span className="ai-hub-metric-chip" key={metric.label}>
                        {metric.label}: {metric.value}
                      </span>
                    ))}
                  </div>
                )}
              </button>
            ))}
          </div>
        </aside>
      )}

      <div className="ai-hub-detail-panel">
        {selectedSlug === null && <p>{translate('Select a dashboard to view details.')}</p>}
        {detailError && (
          <div className="ai-hub-error-banner" role="alert">
            {detailError}
          </div>
        )}
        {detailLoading && (
          <div className="ai-hub-loading">
            <div className="ai-hub-spinner" />
            {translate('Fetching dashboard...')}
          </div>
        )}
        {!detailLoading && detail && (
          <>
            <div>
              <h2>{detail.label}</h2>
              {detail.description && <p>{detail.description}</p>}
              {detail.updated_at && (
                <small style={{ color: 'var(--ai-hub-muted)' }}>
                  {translate('Updated')} {formatRelative(detail.updated_at)}
                </small>
              )}
            </div>

            {detail.metrics && detail.metrics.length > 0 && (
              <div>
                <h3>{translate('Key metrics')}</h3>
                <div className="ai-hub-metric-grid">
                  {detail.metrics.map((metric) => (
                    <div className="ai-hub-metric-card" key={metric.label}>
                      <span>{metric.label}</span>
                      <strong>{metric.value}</strong>
                      {metric.delta && (
                        <small style={{ color: metric.trend === 'down' ? '#ef4444' : '#10b981' }}>{metric.delta}</small>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}

            {detail.insights && detail.insights.length > 0 && (
              <div>
                <h3>{translate('Insights')}</h3>
                <ul>
                  {detail.insights.map((insight, index) => (
                    <li key={`${detail.slug}-insight-${index}`}>{insight}</li>
                  ))}
                </ul>
              </div>
            )}

            <div className="ai-hub-actions">
              {portalUrl && (
                <button
                  type="button"
                  className="ai-hub-action-button primary"
                  onClick={() => window.open(`${portalUrl}/dashboards/${encodeURIComponent(detail.slug)}`, '_blank', 'noopener')}
                >
                  {translate('Open in AIMXB')}
                </button>
              )}
              {detail.actions?.map((action) => (
                <button
                  key={action.key ?? action.label}
                  type="button"
                  className="ai-hub-action-button secondary"
                  onClick={() => action.href && window.open(action.href, '_blank', 'noopener')}
                  disabled={!action.href}
                  title={action.description}
                >
                  {action.label}
                </button>
              ))}
            </div>
          </>
        )}
        {!detailLoading && !detail && selectedSlug && (
          <p className="description">
            {(fallbackLabel ?? translate('This dashboard')) + ' ' + translate('is loading details...')}
          </p>
        )}
      </div>
    </section>
  );
}

function SettingsPreview({
  dashboards,
  loading,
  onLoad,
}: {
  dashboards: DashboardEntry[];
  loading: boolean;
  onLoad: () => Promise<void>;
}) {
  return (
    <section className="ai-hub-card">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
        <div>
          <h3 style={{ margin: 0 }}>{translate('Dashboard preview')}</h3>
          <p className="description" style={{ margin: '0.3rem 0 0' }}>
            {translate('Load the AIMXB manifest to preview what your team will see in the Dashboards view.')}
          </p>
        </div>
        <button
          type="button"
          className="ai-hub-action-button primary"
          onClick={() => void onLoad()}
          disabled={loading}
        >
          {loading ? translate('Loading...') : translate('Load manifest')}
        </button>
      </div>
      {loading && (
        <div className="ai-hub-loading">
          <div className="ai-hub-spinner" />
          {translate('Fetching dashboards...')}
        </div>
      )}
      {!loading && dashboards.length === 0 && (
        <p className="description">{translate('No dashboards fetched yet. Click “Load manifest” to see them here.')}</p>
      )}
      {!loading && dashboards.length > 0 && (
        <div className="ai-hub-dashboard-list">
          {dashboards.slice(0, 3).map((entry) => (
            <div className="ai-hub-dashboard-card" key={entry.slug}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <strong>{entry.label}</strong>
                {entry.badges?.[0] && <span className="ai-hub-badge">{entry.badges[0]}</span>}
              </div>
              {entry.description && <p>{entry.description}</p>}
            </div>
          ))}
          {dashboards.length > 3 && (
            <p className="description">
              {translate('...and %d more dashboards inside the dedicated Dashboards screen.').replace(
                '%d',
                String(dashboards.length - 3)
              )}
            </p>
          )}
        </div>
      )}
    </section>
  );
}
