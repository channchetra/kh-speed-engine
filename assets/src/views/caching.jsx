import React, {useEffect, useState} from 'react';
import {Check, Code2, Pause, Play, RefreshCcw, RotateCcw, Search, Trash2, Zap} from 'lucide-react';
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

	if (total === 0) {
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
				<strong>{formatNumber(remaining)}</strong>
				<span>remaining of {formatNumber(total)} queued</span>
			</div>
			<div className="khse-queue__bar">
				<Progress value={progress} tone="cyan" label="Preload queue progress" />
				<span>{formatNumber(processed)} processed · {Math.round(progress)}%</span>
			</div>
		</div>
	);
}

function BackgroundQueue({preload, actions}) {
	const pending = Number(preload.queuePending) || 0;
	const running = Number(preload.queueRunning) || 0;
	const failed = Number(preload.queueFailed) || 0;
	const paused = Boolean(preload.queuePaused);
	const jobs = Array.isArray(preload.queueJobs) ? preload.queueJobs : [];
	const state = paused ? 'Paused' : 'Running';
	const statusTone = (status) => {
		if (status === 'failed') {
			return 'danger';
		}

		return status === 'processing' ? 'info' : 'neutral';
	};

	return (
		<Card
			title="Background queue"
			description="Pause optional warming and generation. Cache invalidation keeps running."
			action={(
				<Button
					variant="secondary"
					icon={paused ? Play : Pause}
					onClick={() => actions.pausePreloadQueue()}
				>
					{paused ? 'Resume optional work' : 'Pause optional work'}
				</Button>
			)}
			padded={false}
		>
			<div className="khse-queue-dashboard">
				<div className="khse-kpis">
					<Stat label="Pending" value={formatNumber(pending)} tone="blue" />
					<Stat label="Running" value={formatNumber(running)} tone="cyan" />
					<Stat label="Failed" value={formatNumber(failed)} tone={failed > 0 ? 'red' : 'green'} />
					<Stat label="State" value={state} tone={paused ? 'amber' : 'green'} />
				</div>
			</div>
			<div className="khse-table-wrap">
				<table className="khse-table khse-queue-table">
					<thead>
						<tr>
							<th>Job</th>
							<th>Status</th>
							<th>Attempts</th>
							<th>Updated</th>
							<th className="khse-table__action">Action</th>
						</tr>
					</thead>
					<tbody>
						{jobs.length ? jobs.map((job) => (
							<tr key={`${job.id}-${job.label}`}>
								<td data-label="Job"><strong>{job.label}</strong></td>
								<td data-label="Status">
									<Pill tone={statusTone(job.status)}>
										{job.status === 'processing' ? 'Running' : humanLabel(job.status)}
									</Pill>
								</td>
								<td data-label="Attempts">{formatNumber(job.attempts)}</td>
								<td data-label="Updated">{job.updated}</td>
								<td data-label="Action" className="khse-table__action">
									{job.canRetry ? (
										<Button size="sm" variant="secondary" icon={RotateCcw} onClick={() => actions.retryPreloadJob(job.id)}>
											Retry
										</Button>
									) : null}
									{job.canCancel ? (
										<Button size="sm" variant="secondary" icon={Trash2} onClick={() => actions.cancelPreloadJob(job.id)}>
											Cancel
										</Button>
									) : null}
								</td>
							</tr>
						)) : (
							<tr>
								<td colSpan={5} data-label="Queue">
									<EmptyState
										icon={Zap}
										title="No background jobs"
										description="Run preload to queue pages for warming."
									/>
								</td>
							</tr>
						)}
					</tbody>
				</table>
			</div>
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

	return (
		<div className="khse-stack">
			<Card
				className="khse-preload-hero"
				title="Preload"
				description="Warm cache-first pages before a guest requests them."
				action={<Pill tone={preload.enabled ? 'success' : 'neutral'} dot>{preload.enabled ? 'Preload on' : 'Preload off'}</Pill>}
			>
				<div className="khse-preload-hero__grid">
					<div className="khse-preload-hero__main">
						<h3 className="khse-preload-hero__title">
							{ready ? 'Warming content' : preload.enabled ? 'Preload needs page caching' : 'Preload is off'}
						</h3>
						<p className="khse-note">
							{ready
								? 'Queued pages are cached in the background, starting with the homepage.'
								: preload.enabled
									? 'Page caching is off. Enable it under Caching → Cache before preload can run.'
									: 'Turn preload on to queue and warm pages. The queue below still reflects the last run.'}
						</p>
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

			<BackgroundQueue preload={status} actions={actions} />

			<Card title="Scope" description="Choose what preload warms and how many URLs it processes per run.">
				<div className="khse-scope-top">
					<Field label="URLs per run" description="Between 1 and 1000 URLs per preload run." htmlFor="khse-preload-limit">
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
