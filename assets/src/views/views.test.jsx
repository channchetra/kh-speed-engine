import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {describe, expect, it} from 'vitest';
import {PageHeader, PrimaryNav, SaveBar} from '../components/shell.jsx';
import AboutView from './about.jsx';
import {CacheView, PreloadView, RulesView} from './caching.jsx';
import DashboardView from './dashboard.jsx';
import {PerformanceView, StatisticsView} from './optimization.jsx';
import {BenchmarkView, ExpertView} from './tools.jsx';

const noop = () => {};
const actions = {
	refresh: noop,
	runPreload: noop,
	pausePreloadQueue: noop,
	cancelPreloadJob: noop,
	retryPreloadJob: noop,
	purgeHomepage: noop,
	clearAll: noop,
	clearType: noop,
	testDriver: () => Promise.resolve({passed: true, message: 'ok'}),
	testUrl: () => Promise.resolve({cacheable: true, checks: []}),
	getNginxSnippet: () => Promise.resolve({snippet: '', enabled: false}),
	loadReports: () => Promise.resolve(),
	go: noop
};

const settings = {
	cache: {
		cachingStatus: false,
		driver: 'file',
		drivers: [{value: 'file', label: 'File', available: true}],
		ttl: 86400,
		ttlMechanism: true,
		cacheKeyPrefix: 'khse_1_test_',
		maxEntries: 0,
		expertModeStatus: false,
		nginxDirect: false,
		debugComment: true,
		visibilityGuest: true,
		visibilityLoginUser: false,
		driverAdvanced: {},
		expertModeCode: 'khse_run_expert_mode( array() );',
		expertModeCustomValue: 'KHSE_CONFIG',
		expertModeReady: true
	},
	preload: {
		enabled: false,
		limit: 50,
		crawlHomepage: true,
		homepage: true,
		postTypes: [{value: 'post', label: 'Post', enabled: 'yes'}],
		archives: [{value: 'category', label: 'Category', enabled: 'yes'}]
	},
	performance: {
		status: false,
		minify_html: true,
		remove_comments: true,
		minify_inline_css: true,
		lazy_media: true,
		critical_images: true,
		preconnect_fonts: true,
		defer_js: true,
		external_ucss: false,
		local_ucss: false,
		js_analysis: false,
		critical_image_count: 1,
		external_ucss_max_file_size: 307200,
		local_ucss_max_file_size: 524288,
		js_analysis_max_script_bytes: 262144,
		js_analysis_max_total_bytes: 1048576,
		ucss_safelist: '',
		js_exclusions: '',
		media_exclusions: ''
	},
	rules: {enabled: false, excludedList: '', getVars: '', postVars: '', cookieVars: ''},
	statistics: {enabled: false},
	benchmark: {widget: false, widgetDisplay: 'both', footer: false, footerDisplay: 'text'}
};

const data = {
	cache: {
		enabled: false,
		driver: 'file',
		ttl: 86400,
		permalinkReady: true,
		progress: 60
	},
	optimization: {
		enabled: false,
		enabledCount: 0,
		totalCount: 10,
		progress: 0,
		reqPassed: 3,
		reqTotal: 4,
		reqProgress: 75,
		requirements: [{key: 'cache_status', label: 'Page cache enabled', passed: false, detail: 'disable'}],
		reports: {reports: [], totalReports: 0, appliedPages: 0, savedLabel: '0 B', hasMore: false}
	},
	preload: {
		enabled: false,
		progress: 0,
		queueTotal: 0,
		queueProcessed: 0,
		queueRemaining: 0,
		lastRun: 'Never',
		lastPriorityRun: 'Never',
		priorityCount: 0,
		criticalCount: 0
	},
	stats: {
		rows: [{type: 'post', label: 'Post', rows: 0, size: 0, sizeLabel: '0 B'}],
		totalRows: 0,
		totalSize: 0,
		totalSizeLabel: '0 B'
	},
	environment: {phpVersion: '8.2', wpVersion: '6.8', memoryLimit: '256M'}
};

function text(node) {
	return renderToStaticMarkup(node);
}

describe('admin views render server-side', () => {
	it('renders the dashboard and shell', () => {
		const markup = text(
			<>
				<PrimaryNav view="overview" onNavigate={noop} />
				<PageHeader view="overview" actions={actions} cacheEnabled={false} />
				<DashboardView data={data} settings={settings} go={noop} actions={actions} />
				<SaveBar dirty busy={false} onSave={noop} onDiscard={noop} />
			</>
		);

		expect(markup).toContain('Page caching is off');
		expect(markup).toContain('Needs attention');
		expect(markup).toContain('Unsaved changes');
	});

	it('explains that preload needs page caching', () => {
		const preloadSettings = {
			...settings,
			preload: {...settings.preload, enabled: true}
		};

		const markup = text(
			<PreloadView data={data} settings={preloadSettings} update={noop} actions={actions} />
		);

		expect(markup).toContain('Preload needs page caching');
		expect(markup).toContain('Enable it under Caching');
		expect(markup).toMatch(/Run preload/);
	});

	it('renders the background queue controls and jobs', () => {
		const queueData = {
			...data,
			preload: {
				...data.preload,
				queuePending: 1,
				queueRunning: 0,
				queueFailed: 1,
				queuePaused: false,
				queueJobs: [
					{id: 4, label: '#4 · /about/', status: 'failed', attempts: 3, updated: '2026-11-06 15:26:09', canRetry: true, canCancel: true}
				]
			}
		};

		const markup = text(
			<PreloadView data={queueData} settings={settings} update={noop} actions={actions} />
		);

		expect(markup).toContain('Background queue');
		expect(markup).toContain('Pause optional work');
		expect(markup).toContain('#4 · /about/');
		expect(markup).toContain('Retry');
		expect(markup).toContain('Cancel');
	});

	it('renders every settings view', () => {
		const views = [
			['Cache', <CacheView settings={settings} update={noop} actions={actions} />, 'Page caching'],
			['Preload', <PreloadView data={data} settings={settings} update={noop} actions={actions} />, 'Preload is off'],
			['Rules', <RulesView settings={settings} update={noop} actions={actions} />, 'Exclusion rules'],
			['Performance', <PerformanceView data={data} settings={settings} update={noop} updatePerformance={noop} loadReports={noop} actions={actions} />, 'Requirements'],
			['Statistics', <StatisticsView data={data} settings={settings} update={noop} actions={actions} />, 'Cache statistics'],
			['Expert', <ExpertView settings={settings} update={noop} actions={actions} />, 'Expert mode'],
			['Benchmark', <BenchmarkView settings={settings} update={noop} actions={actions} />, 'Benchmark settings'],
			['About', <AboutView data={data} settings={settings} />, 'new in 4.1.0']
		];

		for (const [label, node, expected] of views) {
			expect(text(node), label).toContain(expected);
		}
	});
});
