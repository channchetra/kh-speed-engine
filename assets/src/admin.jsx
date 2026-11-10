import './admin.css';

import React, {useCallback, useEffect, useMemo, useState} from 'react';
import {createRoot} from 'react-dom/client';
import {ConfirmDialog} from './components/ui.jsx';
import {PageHeader, PrimaryNav, SaveBar, SubNav, Toast} from './components/shell.jsx';
import {request} from './lib/api.js';
import {clone} from './lib/format.js';
import AboutView from './views/about.jsx';
import {CacheView, PreloadView, RulesView} from './views/caching.jsx';
import DashboardView from './views/dashboard.jsx';
import {PerformanceView, StatisticsView} from './views/optimization.jsx';
import {BenchmarkView, ExpertView} from './views/tools.jsx';
import {SystemDebugView} from './views/system-debug.jsx';

const boot = window.khseDashboard || {};
const i18n = boot.i18n || {};

function App() {
	const [data, setData] = useState(() => clone(boot.status));
	const [settings, setSettings] = useState(() => clone(boot.status?.settings));
	const [savedSettings, setSavedSettings] = useState(() => clone(boot.status?.settings));
	const [view, setView] = useState(boot.view || 'overview');
	const [busy, setBusy] = useState(false);
	const [toast, setToast] = useState(null);
	const [confirm, setConfirm] = useState(null);

	const dirty = useMemo(() => JSON.stringify(settings) !== JSON.stringify(savedSettings), [settings, savedSettings]);
	const dismissToast = useCallback(() => setToast(null), []);
	const notify = useCallback((type, message) => {
		setToast({id: `${type}-${Date.now()}`, type, message});
	}, []);

	useEffect(() => {
		window.scrollTo(0, 0);
	}, [view]);

	useEffect(() => {
		const onPopState = () => {
			const next = new URLSearchParams(window.location.search).get('view');
			setView(next || 'overview');
		};

		window.addEventListener('popstate', onPopState);

		return () => window.removeEventListener('popstate', onPopState);
	}, []);

	const go = (nextView) => {
		setView(nextView);

		const url = new URL(window.location.href);
		url.searchParams.set('view', nextView);
		window.history.pushState({view: nextView}, '', url.toString());
	};

	const run = useCallback((action, payload = {}, options = {}) => {
		const {silent = false, forceSettings = false} = options;

		setBusy(true);

		return request(action, payload)
			.then((result) => {
				if (result.status) {
					setData(clone(result.status));

					if (forceSettings || !dirty) {
						setSettings(clone(result.status.settings));
						setSavedSettings(clone(result.status.settings));
					}
				}

				if (!silent) {
					notify('success', result.message || i18n.saved || 'Done.');
				}

				return result;
			})
			.catch((error) => {
				notify('error', error.message);
				return null;
			})
			.finally(() => setBusy(false));
	}, [notify, dirty]);

	const syncStatus = useCallback((action, payload = {}) => request(action, payload).then((result) => {
		if (result.status) {
			setData(clone(result.status));
		}

		return result;
	}), []);

	const update = (section, key, value) => {
		setSettings((current) => ({
			...current,
			[section]: {
				...(current?.[section] || {}),
				[key]: value
			}
		}));
	};

	const updatePerformance = (key, value) => update('performance', key, value);
	const save = () => run('khse_action_dashboard_save_settings', {settings: JSON.stringify(settings)}, {forceSettings: true});
	const refresh = () => run('khse_action_dashboard_status', {}, {silent: true});
	const runPreload = () => run('khse_action_dashboard_preload');
	const purgeHomepage = () => run('khse_action_dashboard_purge_homepage');
	const discard = () => setSettings(clone(savedSettings));

	const loadReports = () => {
		const reports = data.optimization?.reports || {};

		setBusy(true);

		return request('khse_action_dashboard_reports', {offset: String(reports.loadedCount || 0)})
			.then((result) => {
				setData((current) => ({
					...current,
					optimization: {
						...current.optimization,
						reports: {
							...current.optimization?.reports,
							reports: [...(current.optimization?.reports?.reports || []), ...(result.reports || [])],
							loadedCount: result.loadedCount,
							totalReports: result.totalReports,
							hasMore: result.hasMore
						}
					}
				}));
				return result;
			})
			.catch((error) => {
				notify('error', error.message);
				return null;
			})
			.finally(() => setBusy(false));
	};

	const clearAll = () => setConfirm({
		title: 'Clear all cache?',
		description: 'Every cached page will be removed. Visitors may receive slower uncached responses until pages are cached again.',
		confirmLabel: 'Clear all cache',
		onConfirm: () => {
			setConfirm(null);
			run('khse_action_dashboard_clear_cache');
		}
	});

	const clearType = (cacheType) => {
		const label = (data.stats?.rows || []).find((row) => row.type === cacheType)?.label || cacheType;

		setConfirm({
			title: `Clear ${label} cache?`,
			description: 'Cached entries for this type will be removed. They are regenerated on the next preload run or visit.',
			confirmLabel: 'Clear cache type',
			onConfirm: () => {
				setConfirm(null);
				run('khse_action_dashboard_clear_cache_type', {cacheType});
			}
		});
	};

	const actions = {
		refresh,
		runPreload,
		pausePreloadQueue: () => run('khse_action_dashboard_preload_pause'),
		cancelPreloadJob: (jobId) => run('khse_action_dashboard_preload_cancel', {jobId}),
		retryPreloadJob: (jobId) => run('khse_action_dashboard_preload_retry', {jobId}),
		getPreloadJob: (jobId) => request('khse_action_dashboard_preload_job', {jobId}),
		purgeHomepage,
		clearAll,
		clearType,
		testDriver: (driver) => request('khse_action_dashboard_test_driver', {driver}),
		testUrl: (url) => request('khse_action_dashboard_test_url', {url}),
		getNginxSnippet: () => request('khse_action_dashboard_nginx_snippet'),
		getSystemDebug: () => request('khse_action_dashboard_system_debug'),
		toggleDebug: (enabled) => syncStatus('khse_action_dashboard_debug_toggle', {enabled: enabled ? '1' : '0'}),
		clearDebug: () => syncStatus('khse_action_dashboard_debug_clear'),
		productionDebug: () => syncStatus('khse_action_dashboard_debug_production'),
		notify,
		loadReports,
		go
	};

	return (
		<div className="khse-admin">
			<PrimaryNav view={view} onNavigate={go} />
			<main className="khse-main">
				<div className="khse-page">
					<PageHeader
						view={view}
						actions={actions}
						busy={busy}
						cacheEnabled={Boolean(settings.cache?.cachingStatus ?? data.cache?.enabled)}
					/>
					<SubNav view={view} onNavigate={go} />
					<section className="khse-view" key={view}>
						{view === 'overview' ? <DashboardView data={data} settings={settings} go={go} actions={actions} /> : null}
						{view === 'cache' ? <CacheView settings={settings} update={update} actions={actions} /> : null}
						{view === 'preload' ? <PreloadView data={data} settings={settings} update={update} actions={actions} /> : null}
						{view === 'rules' ? <RulesView settings={settings} update={update} actions={actions} /> : null}
						{view === 'performance' ? (
							<PerformanceView
								data={data}
								settings={settings}
								update={update}
								updatePerformance={updatePerformance}
								loadReports={loadReports}
								actions={actions}
							/>
						) : null}
						{view === 'statistics' ? <StatisticsView data={data} settings={settings} update={update} actions={actions} /> : null}
						{view === 'expert' ? <ExpertView settings={settings} update={update} actions={actions} /> : null}
						{view === 'benchmark' ? <BenchmarkView settings={settings} update={update} actions={actions} /> : null}
						{view === 'system-debug' ? <SystemDebugView actions={actions} /> : null}
						{view === 'about' ? <AboutView data={data} settings={settings} /> : null}
					</section>
				</div>
			</main>
			<SaveBar dirty={dirty} busy={busy} onSave={save} onDiscard={discard} />
			<Toast toast={toast} onClose={dismissToast} />
			<ConfirmDialog
				open={Boolean(confirm)}
				title={confirm?.title || ''}
				description={confirm?.description || ''}
				confirmLabel={confirm?.confirmLabel || 'Confirm'}
				tone="danger"
				onConfirm={() => confirm?.onConfirm?.()}
				onCancel={() => setConfirm(null)}
			/>
		</div>
	);
}

const root = document.getElementById('khse-admin-root');

if (root) {
	createRoot(root).render(<App />);
}
