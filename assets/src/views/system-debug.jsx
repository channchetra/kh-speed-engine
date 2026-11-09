import React, {useEffect, useState} from 'react';
import {AlertTriangle, Download, RefreshCcw, Server, Trash2} from 'lucide-react';
import {
	Button,
	Card,
	EmptyState,
	Pill,
	Stat,
	StatusRow,
	Switch
} from '../components/ui.jsx';
import {humanLabel} from '../lib/format.js';

const STATUS_TONE = {
	pass: 'success',
	warning: 'warning',
	fail: 'danger',
	info: 'neutral'
};

function statusTone(status) {
	return STATUS_TONE[status] || 'neutral';
}

function SystemGroups({groups}) {
	return (
		<div className="khse-debug-groups">
			{groups.map((group) => (
				<section className="khse-debug-group" key={group.label}>
					<h3 className="khse-debug-group__title">{group.label}</h3>
					<div className="khse-table-wrap">
						<table className="khse-table">
							<thead>
								<tr>
									<th>Setting</th>
									<th>Value</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody>
								{group.rows.map((row) => (
									<tr key={`${group.label}-${row.label}`}>
										<td data-label="Setting">
											<strong>{row.label}</strong>
											{row.detail ? <span className="khse-table__key">{row.detail}</span> : null}
										</td>
										<td data-label="Value">{row.value || '—'}</td>
										<td data-label="Status">
											<Pill tone={statusTone(row.status)}>{humanLabel(row.status)}</Pill>
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				</section>
			))}
		</div>
	);
}

function ProxyPanel({proxy}) {
	const rows = [
		['Remote address', proxy.remoteAddr],
		['Server address', proxy.serverAddr],
		['Home host', proxy.homeHost],
		['Home IP', proxy.homeIp],
		['Home IP scope', proxy.homePrivate ? 'Private / internal' : 'Public'],
		['WordPress HTTPS', proxy.isSsl ? 'Yes' : 'No'],
		['Forwarded proto', proxy.forwardedProto || '—'],
		['Server software', proxy.serverSoftware || '—'],
		['Container', proxy.containerized ? 'Yes' : 'No']
	];

	return (
		<Card
			title="Proxy & request topology"
			description="Forwarded headers and the address WordPress sees."
			action={<Pill tone={proxy.behindProxy ? 'warning' : 'success'} dot>{proxy.behindProxy ? 'Behind proxy' : 'Direct'}</Pill>}
		>
			<div className="khse-kpis khse-kpis--3">
				<Stat label="Behind proxy" value={proxy.behindProxy ? 'Yes' : 'No'} tone={proxy.behindProxy ? 'amber' : 'green'} />
				<Stat label="Confidence" value={humanLabel(proxy.confidence)} tone="blue" />
				<Stat label="Provider" value={proxy.provider || 'Unknown'} tone="violet" />
			</div>

			<div className="khse-grid khse-grid--2">
				<dl className="khse-facts">
					{rows.map(([label, value]) => (
						<div key={label}>
							<dt>{label}</dt>
							<dd>{value || '—'}</dd>
						</div>
					))}
				</dl>
				<div className="khse-status-list">
					{proxy.warnings.length ? proxy.warnings.map((warning) => (
						<StatusRow key={warning} status="note" title="Check this" description={warning} />
					)) : (
						<StatusRow status="pass" title="No topology warnings" description="Forwarded headers and WordPress agree." />
					)}
				</div>
			</div>

			<div className="khse-table-wrap">
				<table className="khse-table">
					<thead>
						<tr>
							<th>Header</th>
							<th>Value</th>
							<th>Present</th>
						</tr>
					</thead>
					<tbody>
						{proxy.headers.map((header) => (
							<tr key={header.name}>
								<td data-label="Header"><strong>{header.name}</strong></td>
								<td data-label="Value">{header.value}</td>
								<td data-label="Present">
									<Pill tone={header.present ? 'info' : 'neutral'}>{header.present ? 'Yes' : 'No'}</Pill>
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>
		</Card>
	);
}

export function SystemDebugView({actions, initialData = null}) {
	const [data, setData] = useState(initialData);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');

	const download = () => {
		if (!data) {
			return;
		}

		const payload = {
			...data,
			downloadedAt: new Date().toISOString()
		};
		const blob = new Blob([JSON.stringify(payload, null, 2)], {type: 'application/json'});
		const url = URL.createObjectURL(blob);
		const link = document.createElement('a');
		const stamp = new Date().toISOString().replace(/[:.]/g, '-');

		link.href = url;
		link.download = `khse-system-debug-${stamp}.json`;
		document.body.appendChild(link);
		link.click();
		link.remove();
		URL.revokeObjectURL(url);
	};

	const load = () => {
		setBusy(true);
		setError('');

		actions.getSystemDebug()
			.then((result) => setData(result))
			.catch((requestError) => {
				setError(requestError.message);
				actions.notify?.('error', requestError.message);
			})
			.finally(() => setBusy(false));
	};

	useEffect(() => {
		if (!initialData) {
			load();
		}
	}, []);

	const run = (promise, message) => {
		setBusy(true);
		setError('');

		promise
			.then((result) => {
				if (result.debug) {
					setData(result.debug);
				}
				if (message || result.message) {
					actions.notify?.('success', message || result.message);
				}
			})
			.catch((requestError) => {
				setError(requestError.message);
				actions.notify?.('error', requestError.message);
			})
			.finally(() => setBusy(false));
	};

	if (!data) {
		return (
			<Card title="System & debug" description="Inspect system configuration, proxy headers, and preload attempts.">
				{error ? <StatusRow status="fail" title="Could not load diagnostics" description={error} /> : <EmptyState icon={Server} title="Loading system diagnostics…" description="Reading the local configuration." />}
			</Card>
		);
	}

	const enabled = Boolean(data.debugEnabled);
	const proxy = data.proxy || {headers: [], warnings: []};

	return (
		<div className="khse-stack">
			<Card
				title="Debug mode"
				description="Record detailed preload attempts and inspect the hosting stack. Debug data stays local and can be cleared at any time."
				action={<Pill tone={enabled ? 'warning' : 'success'} dot>{enabled ? 'Debug on' : 'Debug off'}</Pill>}
			>
				<Switch
					label="Enable debug mode"
					description="Adds per-attempt preload details. It does not change caching or preload behavior."
					checked={enabled}
					disabled={busy}
					onChange={(checked) => run(actions.toggleDebug(checked))}
				/>
				<div className="khse-inline khse-debug-actions">
					<Button variant="secondary" icon={Download} onClick={download}>Download diagnostics</Button>
					<Button variant="secondary" icon={RefreshCcw} busy={busy} onClick={load}>Refresh diagnostics</Button>
					<Button variant="secondary" icon={Trash2} busy={busy} onClick={() => run(actions.clearDebug())}>Clear debug data</Button>
					<Button variant="danger" icon={AlertTriangle} busy={busy} onClick={() => run(actions.productionDebug())}>Disable &amp; clear for production</Button>
				</div>
				<p className="khse-note">
					Per-attempt preload history is shown in <strong>Caching → Preload → Background queue</strong> while debug mode is on. Use <strong>Download diagnostics</strong> to save the full report for support.
				</p>
				<p className="khse-note">
					Use <strong>Disable &amp; clear for production</strong> before handing the site to normal traffic. It turns debug off and removes the stored attempts.
				</p>
				{error ? <StatusRow status="fail" title="Debug request failed" description={error} /> : null}
			</Card>

			<ProxyPanel proxy={proxy} />

			<Card title="System configuration" description="WordPress, PHP, server, cache, and preload values.">
				<SystemGroups groups={data.system || []} />
			</Card>

		</div>
	);
}
