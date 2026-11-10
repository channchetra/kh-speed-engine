import React, {useEffect, useState} from 'react';
import {Check, Code2, Pause, Play, RefreshCcw, Search, Trash2, Zap} from 'lucide-react';
import {
	Button,
	Card,
	CopyButton,
	Disclosure,
	EmptyState,
	Field,
	Pill,
	Progress,
	Select,
	Stat,
	StatusRow,
	Switch,
	Textarea,
	TextInput
} from '../components/ui.jsx';
import {formatNumber, humanLabel, lineCount, ttlLabel} from '../lib/format.js';
import {BackgroundQueue} from './background-queue.jsx';

const DB_OPTIONS = Array.from({length: 16}, (_, index) => ({value: String(index), label: `DB${index}`}));

const TTL_MIN = 300;
const TTL_MAX = 2592000;
const TTL_PRESETS = [
	{value: 3600, label: '1 hour'},
	{value: 21600, label: '6 hours'},
	{value: 86400, label: '1 day'},
	{value: 604800, label: '1 week'}
];

function driverAliases(driver) {
	if (driver === 'memcache' || driver === 'memcached') {
		return ['memcache', 'memcached'];
	}

	if (driver === 'mongo' || driver === 'mongodb') {
		return ['mongo', 'mongodb'];
	}

	return [driver];
}

function PageCachingCard({cache, driver, drivers, update, actions}) {
	const [result, setResult] = useState(null);
	const selected = drivers.find((item) => item.value === driver);
	const available = selected?.available !== false;

	useEffect(() => {
		setResult(null);
	}, [driver]);

	const runTest = () => {
		setResult({state: 'pending', message: `Testing ${selected?.label || driver}…`});
		actions.testDriver(driver)
			.then((data) => setResult({
				state: data?.passed ? 'pass' : 'fail',
				message: data?.message || 'Connection test finished.'
			}))
			.catch((error) => setResult({state: 'fail', message: error.message}));
	};

	return (
		<Card
			title="Page caching"
			description="Serve static HTML to guests instead of running PHP on every request."
			action={(
				<div className="khse-inline">
					<Pill tone={cache.cachingStatus ? 'success' : 'neutral'} dot>
						{cache.cachingStatus ? 'Caching on' : 'Caching off'}
					</Pill>
					<Pill tone={available ? 'success' : 'warning'} dot>
						{available ? 'Driver ready' : 'Extension missing'}
					</Pill>
				</div>
			)}
		>
			<div className="khse-row">
				<Switch
					label="Enable page caching"
					description="Guest-only page cache. Logged-in traffic is controlled separately under Traffic."
					checked={Boolean(cache.cachingStatus)}
					onChange={(checked) => update('cache', 'cachingStatus', checked)}
				/>
				<Button size="sm" variant="secondary" icon={RefreshCcw} busy={result?.state === 'pending'} onClick={runTest}>
					Test connection
				</Button>
			</div>
			<div role="status" aria-live="polite">
				{result ? (
					<StatusRow
						status={result.state === 'pending' ? 'note' : result.state}
						title={result.state === 'pending' ? 'Testing connection' : result.state === 'pass' ? 'Connection passed' : 'Connection failed'}
						description={result.message}
					/>
				) : null}
			</div>
		</Card>
	);
}

function FileDriverFields({current, updateAdvanced}) {
	return (
		<div className="khse-subgrid">
			<Switch
				label="File compression"
				description="Saves disk space for large HTML files. Raw files can be faster on fast SSD storage."
				checked={current.compress === 'yes'}
				onChange={(checked) => updateAdvanced('file', 'compress', checked ? 'yes' : 'no')}
			/>
			<Field label="Compression threshold" description="Bytes. Smaller files are stored uncompressed." htmlFor="khse-file-compress-threshold">
				<TextInput
					id="khse-file-compress-threshold"
					type="number"
					min="0"
					suffix="bytes"
					value={current.compress_threshold ?? 4096}
					onChange={(event) => updateAdvanced('file', 'compress_threshold', event.target.value)}
				/>
			</Field>
			<Field label="Compression level" description="From 1 (fastest) to 9 (smallest)." htmlFor="khse-file-compress-level">
				<TextInput
					id="khse-file-compress-level"
					type="number"
					min="1"
					max="9"
					value={current.compress_level ?? 1}
					onChange={(event) => updateAdvanced('file', 'compress_level', event.target.value)}
				/>
			</Field>
		</div>
	);
}

function RedisDriverFields({current, connection, updateAdvanced, updateConnection}) {
	return (
		<div className="khse-subgrid">
			<Field label="Connection" htmlFor="khse-redis-connection">
				<Select
					id="khse-redis-connection"
					value={connection}
					options={[{value: 'tcp', label: 'TCP'}, {value: 'socket', label: 'Unix socket'}]}
					onChange={(event) => updateConnection('redis', event.target.value)}
				/>
			</Field>
			{connection === 'socket' ? (
				<Field label="Unix socket" htmlFor="khse-redis-socket">
					<TextInput
						id="khse-redis-socket"
						value={current.unix_socket || ''}
						onChange={(event) => updateAdvanced('redis', 'unix_socket', event.target.value)}
					/>
				</Field>
			) : (
				<>
					<Field label="Host" htmlFor="khse-redis-host">
						<TextInput
							id="khse-redis-host"
							value={current.host || '127.0.0.1'}
							onChange={(event) => updateAdvanced('redis', 'host', event.target.value)}
						/>
					</Field>
					<Field label="Port" htmlFor="khse-redis-port">
						<TextInput
							id="khse-redis-port"
							type="number"
							min="1"
							value={current.port || 6379}
							onChange={(event) => updateAdvanced('redis', 'port', event.target.value)}
						/>
					</Field>
				</>
			)}
			<Field label="User" htmlFor="khse-redis-user">
				<TextInput
					id="khse-redis-user"
					value={current.user || ''}
					onChange={(event) => updateAdvanced('redis', 'user', event.target.value)}
				/>
			</Field>
			<Field label="Password" htmlFor="khse-redis-pass">
				<TextInput
					id="khse-redis-pass"
					type="password"
					value={current.pass || ''}
					onChange={(event) => updateAdvanced('redis', 'pass', event.target.value)}
				/>
			</Field>
			<Field label="Database" htmlFor="khse-redis-database">
				<Select
					id="khse-redis-database"
					value={current.database ?? 0}
					options={DB_OPTIONS}
					onChange={(event) => updateAdvanced('redis', 'database', event.target.value)}
				/>
			</Field>
			<Switch
				label="Redis compression"
				description="Reduces Redis memory for cached full-page HTML."
				checked={(current.compress ?? 'yes') === 'yes'}
				onChange={(checked) => updateAdvanced('redis', 'compress', checked ? 'yes' : 'no')}
			/>
			<Field label="Compression threshold" description="Bytes. Smaller entries are stored raw." htmlFor="khse-redis-compress-threshold">
				<TextInput
					id="khse-redis-compress-threshold"
					type="number"
					min="0"
					suffix="bytes"
					value={current.compress_threshold ?? 1024}
					onChange={(event) => updateAdvanced('redis', 'compress_threshold', event.target.value)}
				/>
			</Field>
			<Field label="Compression level" description="From 1 (fastest) to 9 (smallest)." htmlFor="khse-redis-compress-level">
				<TextInput
					id="khse-redis-compress-level"
					type="number"
					min="1"
					max="9"
					value={current.compress_level ?? 6}
					onChange={(event) => updateAdvanced('redis', 'compress_level', event.target.value)}
				/>
			</Field>
		</div>
	);
}

function ConnectionDriverFields({driver, current, connection, updateAdvanced, updateConnection}) {
	const defaults = driver === 'mongo' ? {host: '127.0.0.1', port: 27017} : {host: '127.0.0.1', port: 11211};

	return (
		<div className="khse-subgrid">
			<Field label="Connection" htmlFor="khse-driver-connection">
				<Select
					id="khse-driver-connection"
					value={connection}
					options={[{value: 'tcp', label: 'TCP'}, {value: 'socket', label: 'Unix socket'}]}
					onChange={(event) => updateConnection(driver, event.target.value)}
				/>
			</Field>
			{connection === 'socket' ? (
				<Field label="Unix socket" htmlFor="khse-driver-socket">
					<TextInput
						id="khse-driver-socket"
						value={current.unix_socket || ''}
						onChange={(event) => updateAdvanced(driver, 'unix_socket', event.target.value)}
					/>
				</Field>
			) : (
				<>
					<Field label="Host" htmlFor="khse-driver-host">
						<TextInput
							id="khse-driver-host"
							value={current.host || defaults.host}
							onChange={(event) => updateAdvanced(driver, 'host', event.target.value)}
						/>
					</Field>
					<Field label="Port" htmlFor="khse-driver-port">
						<TextInput
							id="khse-driver-port"
							type="number"
							min="1"
							value={current.port || defaults.port}
							onChange={(event) => updateAdvanced(driver, 'port', event.target.value)}
						/>
					</Field>
				</>
			)}
			{driver === 'mongo' ? (
				<>
					<Field label="User" htmlFor="khse-mongo-user">
						<TextInput
							id="khse-mongo-user"
							value={current.user || ''}
							onChange={(event) => updateAdvanced(driver, 'user', event.target.value)}
						/>
					</Field>
					<Field label="Password" htmlFor="khse-mongo-pass">
						<TextInput
							id="khse-mongo-pass"
							type="password"
							value={current.pass || ''}
							onChange={(event) => updateAdvanced(driver, 'pass', event.target.value)}
						/>
					</Field>
					<Field label="Database" htmlFor="khse-mongo-dbname">
						<TextInput
							id="khse-mongo-dbname"
							value={current.dbname || current.database || 'test'}
							onChange={(event) => updateAdvanced(driver, 'dbname', event.target.value)}
						/>
					</Field>
					<Field label="Collection" htmlFor="khse-mongo-collection">
						<TextInput
							id="khse-mongo-collection"
							value={current.collection || 'cache_data'}
							onChange={(event) => updateAdvanced(driver, 'collection', event.target.value)}
						/>
					</Field>
				</>
			) : null}
		</div>
	);
}

function DriverCard({cache, update, updateAdvanced, updateConnection}) {
	const driver = cache.driver || 'file';
	const drivers = cache.drivers || [];
	const selected = drivers.find((item) => item.value === driver);
	const advanced = cache.driverAdvanced || {};
	const current = advanced[driver] || {};
	const connection = (advanced.connection || {})[driver] || 'tcp';
	const isConnectionDriver = ['redis', 'memcache', 'memcached', 'mongo'].includes(driver);

	return (
		<Card title="Cache driver" description="Choose where cached pages are stored. Availability is detected from the PHP extensions on this server.">
			<div className="khse-driver-grid" role="group" aria-label="Cache driver">
				{drivers.map((item) => {
					const active = item.value === driver;

					return (
						<button
							key={item.value}
							type="button"
							className={`khse-driver${active ? ' is-active' : ''}`}
							aria-pressed={active}
							onClick={() => update('cache', 'driver', item.value)}
						>
							<span className="khse-driver__label">{item.label}</span>
							<span className={`khse-driver__dot${item.available === false ? ' is-missing' : ' is-ready'}`} aria-hidden="true" />
							<span className="screen-reader-text">
								{item.available === false ? 'Extension missing' : 'Extension available'}
							</span>
							{active ? <Check size={15} className="khse-driver__check" aria-hidden="true" /> : null}
						</button>
					);
				})}
			</div>
			<div className="khse-connection">
				<h3 className="khse-connection__title">{selected?.label || 'File'} connection settings</h3>
				{driver === 'file' ? <FileDriverFields current={current} updateAdvanced={updateAdvanced} /> : null}
				{driver === 'redis' ? (
					<RedisDriverFields
						current={current}
						connection={connection}
						updateAdvanced={updateAdvanced}
						updateConnection={updateConnection}
					/>
				) : null}
				{driver !== 'file' && driver !== 'redis' && isConnectionDriver ? (
					<ConnectionDriverFields
						driver={driver}
						current={current}
						connection={connection}
						updateAdvanced={updateAdvanced}
						updateConnection={updateConnection}
					/>
				) : null}
				{driver !== 'file' && !isConnectionDriver ? (
					<p className="khse-note">No extra settings for this driver. Use the cache key prefix to isolate sites that share the same store.</p>
				) : null}
			</div>
		</Card>
	);
}

function StorageCard({cache, update}) {
	const ttl = Number(cache.ttl) || 0;
	const [customOpen, setCustomOpen] = useState(false);
	const preset = TTL_PRESETS.find((item) => item.value === ttl);
	const showCustom = customOpen || !preset;
	const activePreset = showCustom ? 'custom' : String(preset.value);
	const choosePreset = (value) => {
		setCustomOpen(false);
		update('cache', 'ttl', String(value));
	};

	return (
		<Card title="Storage and expiry" description="How long cached pages live and how many entries are kept.">
			<div className="khse-chips">
				<h3 className="khse-chips__title">Cache lifetime</h3>
				<div className="khse-chips__list" role="group" aria-label="Cache lifetime presets">
					{TTL_PRESETS.map((item) => (
						<button
							key={item.value}
							type="button"
							className={`khse-chip${activePreset === String(item.value) ? ' is-active' : ''}`}
							aria-pressed={activePreset === String(item.value)}
							onClick={() => choosePreset(item.value)}
						>
							{item.label}
						</button>
					))}
					<button
						type="button"
						className={`khse-chip${activePreset === 'custom' ? ' is-active' : ''}`}
						aria-pressed={activePreset === 'custom'}
						onClick={() => setCustomOpen(true)}
					>
						Custom
					</button>
				</div>
			</div>
			{showCustom ? (
				<Field
					label="Custom lifetime"
					description="Seconds before a cached page expires."
					htmlFor="khse-cache-ttl"
				>
					<TextInput
						id="khse-cache-ttl"
						type="number"
						min={TTL_MIN}
						max={TTL_MAX}
						suffix="seconds"
						value={cache.ttl ?? ''}
						onChange={(event) => update('cache', 'ttl', event.target.value)}
					/>
				</Field>
			) : null}
			<p className="khse-note">
				Current lifetime: <strong>{ttl > 0 ? ttlLabel(ttl) : 'no expiry'}</strong>.
				{showCustom ? ` Allowed range: ${TTL_MIN}–${TTL_MAX} seconds.` : ''}
			</p>
			<div className="khse-divider" />
			<Switch
				label="Clear cache automatically when TTL expires"
				description="Remove expired entries in the background instead of waiting for the next request."
				checked={Boolean(cache.ttlMechanism)}
				onChange={(checked) => update('cache', 'ttlMechanism', checked)}
			/>
			<Field label="Max cache entries" description="Zero keeps entries until they expire." htmlFor="khse-cache-max-entries">
				<TextInput
					id="khse-cache-max-entries"
					type="number"
					min="0"
					value={cache.maxEntries}
					onChange={(event) => update('cache', 'maxEntries', event.target.value)}
				/>
			</Field>
		</Card>
	);
}

function TrafficCard({cache, update}) {
	return (
		<Card title="Traffic" description="Who receives cached HTML.">
			<Switch
				label="Cache pages for guests"
				description="Anonymous visitors get static cached pages."
				checked={cache.visibilityGuest !== false}
				onChange={(checked) => update('cache', 'visibilityGuest', checked)}
			/>
			<Switch
				label="Cache pages for logged-in users"
				description="Off by default. Signed-in users can see personalized admin bars and carts that a shared cache cannot reflect."
				checked={cache.visibilityLoginUser === true}
				onChange={(checked) => update('cache', 'visibilityLoginUser', checked)}
			/>
			<Switch
				label="Add debug comment to cached HTML"
				description="Appends an HTML comment with cache status, useful while verifying a setup."
				checked={Boolean(cache.debugComment)}
				onChange={(checked) => update('cache', 'debugComment', checked)}
			/>
		</Card>
	);
}

function NginxSnippet({snippet}) {
	return (
		<div className="khse-snippet" aria-live="polite">
			<div className="khse-snippet__head">
				<strong>Nginx snippet</strong>
				<CopyButton value={snippet.snippet || ''} label="Copy snippet" />
			</div>
			{snippet.enabled === false ? (
				<p className="khse-note">Nginx direct cache is currently off. Save the switch to activate this block.</p>
			) : null}
			{snippet.directory ? <p className="khse-note">Place this in {snippet.directory}.</p> : null}
			<pre className="khse-code"><code>{snippet.snippet || ''}</code></pre>
		</div>
	);
}

function AdvancedRow({title, description, htmlFor, wide = false, children}) {
	return (
		<div className={`khse-adv-row${wide ? ' khse-adv-row--wide' : ''}`}>
			<div className="khse-adv-row__text">
				{htmlFor ? <label className="khse-adv-row__title" htmlFor={htmlFor}>{title}</label> : <strong className="khse-adv-row__title">{title}</strong>}
				<span>{description}</span>
			</div>
			<div className="khse-adv-row__control">{children}</div>
		</div>
	);
}

function AdvancedCard({cache, update, actions}) {
	const [snippet, setSnippet] = useState(null);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState('');

	const loadSnippet = () => {
		setLoading(true);
		setSnippet(null);
		setError('');
		actions.getNginxSnippet()
			.then((data) => setSnippet(data))
			.catch((requestError) => setError(requestError.message))
			.finally(() => setLoading(false));
	};

	return (
		<Disclosure title="Advanced" description="Cache key prefix, Expert mode, and Nginx direct cache.">
			<div className="khse-adv">
				<AdvancedRow
					title="Cache key prefix"
					description="Isolate sites that share the same cache store."
					htmlFor="khse-cache-key-prefix"
					wide
				>
					<div className="khse-copy-field">
						<TextInput
							id="khse-cache-key-prefix"
							value={cache.cacheKeyPrefix ?? ''}
							onChange={(event) => update('cache', 'cacheKeyPrefix', event.target.value)}
						/>
						<CopyButton value={cache.cacheKeyPrefix || ''} />
					</div>
				</AdvancedRow>
				<AdvancedRow
					title="Expert mode"
					description="Read runtime configuration early from wp-config.php. Optional."
				>
					<Switch
						ariaLabel="Expert mode"
						checked={Boolean(cache.expertModeStatus)}
						onChange={(checked) => update('cache', 'expertModeStatus', checked)}
					/>
					<Button size="sm" variant="ghost" icon={Code2} onClick={() => actions.go('expert')}>Open Expert mode</Button>
				</AdvancedRow>
				<AdvancedRow
					title="Nginx direct cache"
					description="Expose the raw file cache for a server-level FastCGI bypass."
				>
					<Switch
						ariaLabel="Nginx direct cache"
						checked={Boolean(cache.nginxDirect)}
						onChange={(checked) => update('cache', 'nginxDirect', checked)}
					/>
					<Button size="sm" variant="secondary" busy={loading} onClick={loadSnippet}>
						{loading ? 'Loading…' : 'Copy Nginx snippet'}
					</Button>
				</AdvancedRow>
			</div>
			{error ? <p className="khse-error" role="alert">{error}</p> : null}
			{snippet ? <NginxSnippet snippet={snippet} /> : null}
		</Disclosure>
	);
}

export function CacheView({settings, update, actions}) {
	const cache = settings.cache || {};
	const driver = cache.driver || 'file';
	const advanced = cache.driverAdvanced || {};
	const updateAdvanced = (target, key, value) => {
		if (target === '__cache') {
			update('cache', key, value);
			return;
		}

		const next = {...advanced};

		driverAliases(target).forEach((alias) => {
			next[alias] = {...(advanced[alias] || {}), [key]: value};
		});
		update('cache', 'driverAdvanced', next);
	};
	const updateConnection = (target, value) => {
		const nextConnection = {...(advanced.connection || {})};

		driverAliases(target).forEach((alias) => {
			nextConnection[alias] = value;
		});
		update('cache', 'driverAdvanced', {...advanced, connection: nextConnection});
	};

	return (
		<div className="khse-stack">
			<PageCachingCard
				cache={cache}
				driver={driver}
				drivers={cache.drivers || []}
				update={update}
				actions={actions}
			/>
			<DriverCard
				cache={cache}
				update={update}
				updateAdvanced={updateAdvanced}
				updateConnection={updateConnection}
			/>
			<div className="khse-grid khse-grid--2">
				<StorageCard cache={cache} update={update} />
				<TrafficCard cache={cache} update={update} />
			</div>
			<AdvancedCard cache={cache} update={update} actions={actions} />
		</div>
	);
}

function ToggleChips({title, items = [], onToggle}) {
	return (
		<div className="khse-chips">
			<h3 className="khse-chips__title">{title}</h3>
			<div className="khse-chips__list">
				{items.map((item) => {
					const enabled = item.enabled === 'yes';

					return (
						<button
							key={item.value}
							type="button"
							className={`khse-chip${enabled ? ' is-active' : ''}`}
							aria-pressed={enabled}
							onClick={() => onToggle(item.value, !enabled)}
						>
							{enabled ? <Check size={13} aria-hidden="true" /> : null}
							<span>{item.label}</span>
						</button>
					);
				})}
			</div>
		</div>
	);
}

function PreloadQueue({status}) {
	const total = Number(status.queueTotal) || 0;
	const processed = Number(status.queueProcessed) || 0;
	const remaining = Number(status.queueRemaining) || 0;
	const progress = Math.max(0, Math.min(100, Number(status.progress) || 0));
	const lastRun = status.queueLastRun || {};
	const completed = status.queueState === 'completed' && remaining === 0;
	const nonBlocking = lastRun.mode === 'non-blocking';

	if (total === 0) {
		if (Number(lastRun.total) > 0) {
			return (
				<div className="khse-queue">
					<div className="khse-queue__number">
						<strong>{formatNumber(Number(lastRun.success) || 0)}</strong>
						<span>{formatNumber(Number(lastRun.total) || 0)} pages {nonBlocking ? 'dispatched' : 'preloaded'} in the last run</span>
					</div>
					<div className="khse-queue__bar">
						<Progress value={100} tone="green" label="Last preload run" />
						<span>Completed · {Number(lastRun.failed) > 0 ? `${formatNumber(lastRun.failed)} failed` : 'no failures'}</span>
					</div>
				</div>
			);
		}

		return (
			<EmptyState
				icon={Zap}
				title="Nothing queued"
				description="Run preload to warm the cache before guests arrive."
			/>
		);
	}

	return (
		<div className="khse-queue">
			<div className="khse-queue__number">
				<strong>{formatNumber(completed ? (Number(lastRun.success) || processed) : remaining)}</strong>
				<span>
					{completed
						? `${formatNumber(total)} pages ${nonBlocking ? 'dispatched' : 'preloaded'} in the last run`
						: `remaining of ${formatNumber(total)} queued`}
				</span>
			</div>
			<div className="khse-queue__bar">
				<Progress value={completed ? 100 : progress} tone={completed ? 'green' : 'cyan'} label="Preload queue progress" />
				<span>
					{completed
						? `Completed · ${Number(lastRun.failed) > 0 ? `${formatNumber(lastRun.failed)} failed` : 'no failures'}`
						: `${formatNumber(processed)} processed · ${Math.round(progress)}%`}
				</span>
			</div>
		</div>
	);
}

function intervalLabel(seconds) {
	const value = Math.max(0, Number(seconds) || 0);

	if (value < 60) {
		return `${value}s`;
	}

	if (value < 3600) {
		return `${Math.round(value / 60)} min`;
	}

	return `${Math.round(value / 3600)} h`;
}

function ScheduledPreload({status, settings, update, actions}) {
	const scheduled = status.scheduled || {};
	const preloadSettings = settings.preload || {};
	const enabled = Boolean(scheduled.enabled);
	const total = Number(scheduled.total) || 0;
	const processed = Number(scheduled.processed) || 0;
	const remaining = Number(scheduled.remaining) || 0;
	const failed = Number(scheduled.failed) || 0;
	const percent = Math.max(0, Math.min(100, Number(scheduled.percent) || 0));
	const state = scheduled.state || 'idle';
	const stateLabel = state === 'running' ? 'Running' : state === 'completed' ? 'Completed' : state === 'paused' ? 'Paused' : 'Idle';
	const stateTone = state === 'running' ? 'info' : state === 'completed' ? 'success' : state === 'paused' ? 'warning' : 'neutral';
	const runSize = Number(scheduled.runSize) || 50;
	const interval = Number(scheduled.interval) || 60;
	const keepWarmHours = Math.max(1, Math.round((Number(scheduled.keepWarmInterval) || 21600) / 3600));
	const maxEntries = Number(scheduled.maxEntries) || 0;
	const warnings = Array.isArray(scheduled.warnings) ? scheduled.warnings : [];
	const target = Number(scheduled.target) || Number(scheduled.available) || 0;
	const intervalMinutes = Math.max(1, Math.round((Number(preloadSettings.interval) || 60) / 60));
	const keepWarmIntervalHours = Math.max(1, Math.round((Number(preloadSettings.keepWarmInterval) || 21600) / 3600));

	return (
		<Card
			title="Scheduled preload"
			description="Warm the site in batches until the cache limit or every published URL is covered."
			action={<Pill tone={stateTone} dot>{stateLabel}</Pill>}
		>
			<div className="khse-kpis">
				<Stat label="Target" value={formatNumber(target)} detail={maxEntries > 0 ? 'cache limit' : 'all published'} tone="violet" />
				<Stat label="Processed" value={formatNumber(processed)} tone="green" />
				<Stat label="Remaining" value={formatNumber(remaining)} tone="blue" />
				<Stat label={state === 'completed' ? 'Keep-warm' : 'Next run'} value={state === 'completed' ? `${keepWarmHours}h` : (scheduled.nextRun || '—')} tone="cyan" />
			</div>
			<div className="khse-schedule-progress">
				<Progress value={percent} tone="cyan" label="Scheduled preload progress" />
				<span>{formatNumber(processed)} of {formatNumber(total || target)} pages · {failed > 0 ? `${formatNumber(failed)} failed` : 'no failures'}</span>
			</div>
			<p className="khse-note">
				{enabled
					? `Processes ${formatNumber(runSize)} URLs per run and continues with the next run while work remains. ${scheduled.keepWarm ? `Keep-warm audit every ${keepWarmHours} hours.` : 'Keep-warm audit is off.'}`
					: 'Enable scheduled preload under Scope and save to start the automatic cycle.'}
			</p>
			{total > 0 && scheduled.lastTick === 'Never' ? (
				<p className="khse-preload-warning" role="status">The background runner has not reported a run yet. Admin page loads process a run now; enable Process on page requests for front-end runs.</p>
			) : null}
			{scheduled.needsRebuild ? <p className="khse-preload-warning" role="status">Rebuild pending. It starts on the next admin request, or click Build scheduled queue now.</p> : null}
			{enabled && total > 0 && status.queueMode !== 'scheduled' ? (
				<p className="khse-preload-warning" role="status">The active queue is a quick preload, not the scheduled full-site queue. Click Rebuild scheduled queue to replace it.</p>
			) : null}
			{warnings.map((warning) => <p className="khse-preload-warning" role="status" key={warning}>{warning}</p>)}
			<div className="khse-inline">
				<Button variant="primary" icon={Zap} onClick={() => actions.buildScheduledPreload()} disabled={!enabled}>
					{total > 0 ? 'Rebuild scheduled queue' : 'Build scheduled queue'}
				</Button>
				{remaining > 0 ? (
					<Button variant="secondary" icon={state === 'paused' ? Play : Pause} onClick={() => actions.pausePreloadQueue()}>
						{state === 'paused' ? 'Resume schedule' : 'Pause schedule'}
					</Button>
				) : null}
				{total > 0 ? (
					<Button variant="danger" icon={Trash2} onClick={() => actions.cancelScheduledPreload()}>
						Cancel
					</Button>
				) : null}
			</div>
			{total === 0 && Number(scheduled.available) > 0 ? (
				<p className="khse-note">{formatNumber(scheduled.available)} published URLs are available. Build the queue to start warming.</p>
			) : null}

			<div className="khse-divider" />
			<div className="khse-grid khse-grid--2">
				<div className="khse-stack">
					<Switch
						label="Run preload automatically"
						description="Process a batch on a schedule until the cache limit or all published URLs."
						checked={Boolean(preloadSettings.schedule)}
						onChange={(checked) => update('preload', 'schedule', checked)}
					/>
					<Switch
						label="Keep cache warm"
						description="Audit the cache and re-warm only missing or expired pages."
						checked={Boolean(preloadSettings.keepWarm)}
						onChange={(checked) => update('preload', 'keepWarm', checked)}
					/>
					<Switch
						label="Process on page requests"
						description="Off: page requests trigger the background runner. On: admin and front-end requests process a full run inline."
						checked={Boolean(preloadSettings.frontendRun)}
						onChange={(checked) => update('preload', 'frontendRun', checked)}
					/>
				</div>
				<div className="khse-stack">
					<Field label="Fallback delay" description="Used only when the background runner cannot continue immediately." htmlFor="khse-preload-interval">
						<TextInput
							id="khse-preload-interval"
							type="number"
							min="1"
							max="60"
							suffix="min"
							value={intervalMinutes}
							onChange={(event) => update('preload', 'interval', String(Math.max(1, Math.min(60, parseInt(event.target.value, 10) || 1)) * 60))}
						/>
					</Field>
					<Field label="Keep-warm audit every" description="Only missing or expired pages are re-queued." htmlFor="khse-preload-keep-warm-interval">
						<TextInput
							id="khse-preload-keep-warm-interval"
							type="number"
							min="1"
							max="168"
							suffix="hours"
							value={keepWarmIntervalHours}
							onChange={(event) => update('preload', 'keepWarmInterval', String(Math.max(1, Math.min(168, parseInt(event.target.value, 10) || 6)) * 3600))}
						/>
					</Field>
				</div>
			</div>
			<p className="khse-note">Saving settings clears the cache and rebuilds the scheduled queue from the priority URLs.</p>
		</Card>
	);
}

export function PreloadView({data, settings, update, actions}) {
	const preload = settings.preload || {};
	const status = data.preload || {};
	const toggleList = (field, value, checked) => {
		update('preload', field, (preload[field] || []).map((item) => (
			item.value === value ? {...item, enabled: checked ? 'yes' : 'no'} : item
		)));
	};
	const postTypeCount = (preload.postTypes || []).filter((item) => item.enabled === 'yes').length;
	const archiveCount = (preload.archives || []).filter((item) => item.enabled === 'yes').length;
	const limit = Number(preload.limit) || 0;
	const cachingEnabled = Boolean(settings.cache?.cachingStatus);
	const ready = Boolean(preload.enabled) && cachingEnabled;
	const lastRun = status.queueLastRun || {};
	const completed = status.queueState === 'completed' && Number(status.queueRemaining || 0) === 0;
	const nonBlocking = lastRun.mode === 'non-blocking';
	const heroTitle = !ready
		? (preload.enabled ? 'Preload needs page caching' : 'Preload is off')
		: (completed ? 'Preload complete' : 'Warming content');
	const heroNote = !ready
		? (preload.enabled
			? 'Page caching is off. Enable it under Caching → Cache before preload can run.'
			: 'Turn preload on to queue and warm pages. The queue below still reflects the last run.')
		: (completed
			? `${formatNumber(Number(lastRun.success) || Number(status.queueProcessed) || 0)} of ${formatNumber(Number(lastRun.total) || Number(status.queueTotal) || 0)} pages ${nonBlocking ? 'dispatched' : 'preloaded'} in the last run${Number(lastRun.failed) > 0 ? `, ${formatNumber(lastRun.failed)} failed` : ''}.${nonBlocking ? ' The server finishes warming the cache after dispatch.' : ''}`
			: 'Queued pages are cached in the background, starting with the homepage.');

	return (
		<div className="khse-stack">
			<Card
				className="khse-preload-hero"
				title="Preload"
				description="Warm cache-first pages before a guest requests them."
				action={(
					<div className="khse-inline">
						<Pill tone={preload.enabled ? 'success' : 'neutral'} dot>{preload.enabled ? 'Preload on' : 'Preload off'}</Pill>
						{completed ? <Pill tone="success" dot>Last run complete</Pill> : null}
					</div>
				)}
			>
				<div className="khse-preload-hero__grid">
					<div className="khse-preload-hero__main">
						<h3 className="khse-preload-hero__title">{heroTitle}</h3>
						<p className="khse-note">{heroNote}</p>
						{!cachingEnabled && preload.enabled ? (
							<p className="khse-preload-warning" role="status">Caching is paused, so the queued count may be stale until preload is run with caching enabled.</p>
						) : null}
						<PreloadQueue status={status} />
						<div className="khse-inline">
							<Button variant="primary" icon={Play} disabled={!cachingEnabled} onClick={() => actions.runPreload()}>Run preload</Button>
							<Button variant="secondary" icon={Trash2} onClick={() => actions.purgeHomepage()}>Purge homepage</Button>
						</div>
					</div>
					<div className="khse-preload-hero__side">
						<Switch
							label="Enable preload"
							description="Queue pages and warm them in the background."
							checked={Boolean(preload.enabled)}
							onChange={(checked) => update('preload', 'enabled', checked)}
						/>
						<dl className="khse-facts">
							<div><dt>Last run</dt><dd>{status.lastRun || 'Never'}</dd></div>
							<div><dt>Last priority run</dt><dd>{status.lastPriority || 'Never'}</dd></div>
							<div><dt>Failed URLs</dt><dd>{formatNumber(status.queueFailed)}</dd></div>
						</dl>
					</div>
				</div>
			</Card>

			<ScheduledPreload status={status} settings={settings} update={update} actions={actions} />

			<BackgroundQueue preload={status} actions={actions} />

			<Card title="Scope" description="Choose what preload warms and how many URLs it processes per run.">
				<div className="khse-scope-top">
					<Field label="URLs per run" description="Maximum URLs per run, up to 1000. Preload warms the homepage, discovered links, latest published content, and selected archives — not every post." htmlFor="khse-preload-limit">
						<TextInput
							id="khse-preload-limit"
							type="number"
							min="1"
							max="1000"
							suffix="URLs"
							value={preload.limit}
							onChange={(event) => update('preload', 'limit', event.target.value)}
						/>
					</Field>
					<div className="khse-scope-switches">
						<Switch
							label="Crawl homepage links"
							description="Homepage links become priority cache-first URLs."
							checked={Boolean(preload.crawlHomepage)}
							onChange={(checked) => update('preload', 'crawlHomepage', checked)}
						/>
						<Switch
							label="Include the homepage"
							description="Place the homepage at the front of the queue."
							checked={Boolean(preload.homepage)}
							onChange={(checked) => update('preload', 'homepage', checked)}
						/>
					</div>
				</div>
				<div className="khse-grid khse-grid--2">
					<ToggleChips title="Post types" items={preload.postTypes || []} onToggle={(value, checked) => toggleList('postTypes', value, checked)} />
					<ToggleChips title="Archive pages" items={preload.archives || []} onToggle={(value, checked) => toggleList('archives', value, checked)} />
				</div>
				<p className="khse-scope-summary" aria-live="polite">
					Next run: {preload.homepage ? 'homepage' : 'no homepage'} + {postTypeCount} post type{postTypeCount === 1 ? '' : 's'} + {archiveCount} archive{archiveCount === 1 ? '' : 's'}, up to {formatNumber(limit)} URLs.
				</p>
			</Card>
		</div>
	);
}

function UrlCheckerCard({actions}) {
	const [url, setUrl] = useState('');
	const [state, setState] = useState({pending: false, result: null, error: ''});

	const checkUrl = (event) => {
		event.preventDefault();

		const value = url.trim();

		if (!value) {
			return;
		}

		setState({pending: true, result: null, error: ''});
		actions.testUrl(value)
			.then((result) => setState({pending: false, result, error: ''}))
			.catch((error) => setState({pending: false, result: null, error: error.message}));
	};

	return (
		<Card
			title="URL checker"
			description="Check whether a path or full URL would be served from cache."
			action={<Pill tone="info" dot>Read only</Pill>}
		>
			<form className="khse-url-form" onSubmit={checkUrl}>
				<Field label="Path or URL" htmlFor="khse-url-checker">
					<TextInput
						id="khse-url-checker"
						value={url}
						placeholder="/sample-page or full URL"
						onChange={(event) => setUrl(event.target.value)}
					/>
				</Field>
				<Button type="submit" variant="primary" icon={Search} busy={state.pending} disabled={!url.trim()}>
					Check URL
				</Button>
			</form>
			{state.error ? <p className="khse-error" role="alert">{state.error}</p> : null}
			{state.result ? (
				<div className="khse-check-result" aria-live="polite">
					<div className="khse-inline">
						<Pill tone={state.result.cacheable ? 'success' : 'danger'} dot>
							{state.result.cacheable ? 'Will be cached' : 'Bypassed'}
						</Pill>
						<code className="khse-check-result__path">{state.result.path || state.result.url || url}</code>
					</div>
					{state.result.summary ? <p className="khse-check-result__summary">{state.result.summary}</p> : null}
					<div className="khse-status-list">
						{(state.result.checks || []).map((check) => (
							<StatusRow
								key={check.key}
								status={check.status}
								title={check.label}
								description={check.detail}
							/>
						))}
					</div>
				</div>
			) : null}
		</Card>
	);
}

function RuleCounts({rules}) {
	const counts = [
		{label: 'Excluded paths', value: lineCount(rules.excludedList)},
		{label: 'GET variables', value: lineCount(rules.getVars)},
		{label: 'POST variables', value: lineCount(rules.postVars)},
		{label: 'Cookie variables', value: lineCount(rules.cookieVars)}
	];

	return (
		<dl className="khse-facts khse-facts--grid khse-rule-counts">
			{counts.map((count) => (
				<div key={count.label}>
					<dt>{count.label}</dt>
					<dd>{formatNumber(count.value)}</dd>
				</div>
			))}
		</dl>
	);
}

export function RulesView({settings, update, actions}) {
	const rules = settings.rules || {};
	const pathCount = lineCount(rules.excludedList);

	return (
		<div className="khse-stack">
			<Card
				title="Exclusion rules"
				description="Skip caching for matching paths and request variables."
				action={<Pill tone={rules.enabled ? 'success' : 'neutral'} dot>{rules.enabled ? 'Rules on' : 'Rules off'}</Pill>}
			>
				<Switch
					label="Enable exclusion rules"
					description="When off, the lists below are saved but inactive."
					checked={Boolean(rules.enabled)}
					onChange={(checked) => update('rules', 'enabled', checked)}
				/>
				<RuleCounts rules={rules} />
				{!rules.enabled ? (
					<StatusRow
						status="note"
						title="Rules are paused"
						description="Paths and variables are saved, but nothing is bypassed while this switch is off."
					/>
				) : null}
			</Card>

			<UrlCheckerCard actions={actions} />

			<Card title="Excluded paths" description="Requests matching these paths bypass the page cache.">
				<Field
					label="Paths"
					htmlFor="khse-excluded-paths"
					hint={`${pathCount} path${pathCount === 1 ? '' : 's'} configured. One path per line.`}
				>
					<Textarea
						id="khse-excluded-paths"
						rows={8}
						value={rules.excludedList}
						placeholder={'/checkout/\n/cart/\n/my-account/'}
						onChange={(event) => update('rules', 'excludedList', event.target.value)}
					/>
				</Field>
				<p className="khse-examples">
					<span>Examples</span>
					<code>/checkout/</code>
					<code>/cart/</code>
					<code>/my-account/</code>
				</p>
			</Card>

			<Card title="Request variables" description="Requests carrying any of these variables bypass the page cache.">
				<div className="khse-grid khse-grid--3">
					<Field label="GET variables" description="Query parameters such as campaign or cart flags." htmlFor="khse-rules-get">
						<Textarea
							id="khse-rules-get"
							rows={6}
							value={rules.getVars}
							placeholder={'s\nadd-to-cart'}
							onChange={(event) => update('rules', 'getVars', event.target.value)}
						/>
					</Field>
					<Field label="POST variables" description="Body fields that change the response." htmlFor="khse-rules-post">
						<Textarea
							id="khse-rules-post"
							rows={6}
							value={rules.postVars}
							placeholder="comment_post_ID"
							onChange={(event) => update('rules', 'postVars', event.target.value)}
						/>
					</Field>
					<Field label="Cookie variables" description="Cookies that indicate a personalized session." htmlFor="khse-rules-cookie">
						<Textarea
							id="khse-rules-cookie"
							rows={6}
							value={rules.cookieVars}
							placeholder="wp-postpass"
							onChange={(event) => update('rules', 'cookieVars', event.target.value)}
						/>
					</Field>
				</div>
			</Card>
		</div>
	);
}
