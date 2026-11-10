import React, {useState} from 'react';
import {ChevronDown, ChevronRight, Pause, Play, RotateCcw, Trash2, Zap} from 'lucide-react';
import {Button, Card, EmptyState, Pill, Stat} from '../components/ui.jsx';
import {formatNumber, humanLabel} from '../lib/format.js';

function statusTone(status) {
	if (status === 'failed') {
		return 'danger';
	}

	return status === 'processing' ? 'info' : 'neutral';
}

function FailureDetails({job, details, loading}) {
	const debugEnabled = Boolean(details?.debugEnabled);
	const attempts = Array.isArray(details?.attempts) ? details.attempts : [];

	return (
		<div className="khse-queue-expanded__body">
			<div className="khse-queue-failure">
				<h4 className="khse-queue-failure__title">{job.lastError || job.lastHttpStatus ? 'Why it failed' : 'Job details'}</h4>
				<dl className="khse-queue-failure__facts">
					<div><dt>Last error</dt><dd>{job.lastError || '—'}</dd></div>
					<div><dt>HTTP status</dt><dd>{job.lastHttpStatus || '—'}</dd></div>
					<div><dt>Error code</dt><dd>{job.lastErrorCode || '—'}</dd></div>
					<div><dt>Transport</dt><dd>{job.lastMode ? humanLabel(job.lastMode) : '—'}</dd></div>
					<div><dt>Last attempt</dt><dd>{job.lastAttempt || '—'}</dd></div>
					<div><dt>Attempts</dt><dd>{formatNumber(job.attempts)}</dd></div>
				</dl>
			</div>

			{loading ? <p className="khse-note">Loading attempt history…</p> : null}

			{!loading && debugEnabled ? (
				attempts.length ? (
					<div className="khse-table-wrap khse-queue-attempts">
						<table className="khse-table">
							<thead>
								<tr>
									<th>Time</th>
									<th>Attempt</th>
									<th>Status</th>
									<th>HTTP</th>
									<th>Transport</th>
									<th>Duration</th>
									<th>Error</th>
								</tr>
							</thead>
							<tbody>
								{attempts.map((attempt) => (
									<tr key={attempt.id}>
										<td data-label="Time">{attempt.createdAt}</td>
										<td data-label="Attempt">{formatNumber(attempt.attempt)}</td>
										<td data-label="Status">
											<Pill tone={attempt.status === 'failed' ? 'danger' : attempt.status === 'success' ? 'success' : attempt.status === 'retry' ? 'warning' : 'neutral'}>
												{humanLabel(attempt.status)}
											</Pill>
										</td>
										<td data-label="HTTP">{attempt.httpStatus || '—'}</td>
										<td data-label="Transport">{attempt.mode ? humanLabel(attempt.mode) : '—'}</td>
										<td data-label="Duration">{attempt.durationMs ? `${formatNumber(attempt.durationMs)} ms` : '—'}</td>
										<td data-label="Error">
											{attempt.errorCode ? <strong>{attempt.errorCode}</strong> : null}
											{attempt.errorCode && attempt.errorMessage ? ' · ' : null}
											{attempt.errorMessage || '—'}
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				) : (
					<p className="khse-note">No attempt history yet. Run preload to record another attempt.</p>
				)
			) : null}
		</div>
	);
}

export function BackgroundQueue({preload, actions}) {
	const pending = Number(preload.queuePending) || 0;
	const running = Number(preload.queueRunning) || 0;
	const failed = Number(preload.queueFailed) || 0;
	const paused = Boolean(preload.queuePaused);
	const jobs = Array.isArray(preload.queueJobs) ? preload.queueJobs : [];
	const successLog = Array.isArray(preload.successLog) ? preload.successLog : [];
	const successCount = Number(preload.successCount) || 0;
	const jobCount = Number(preload.queueJobCount) || jobs.length;
	const lastRun = preload.queueLastRun || {};
	const lastTotal = Number(lastRun.total) || 0;
	const lastFailed = Number(lastRun.failed) || 0;
	const nonBlocking = lastRun.mode === 'non-blocking';
	const state = paused
		? 'Paused'
		: preload.queueState === 'completed'
			? 'Completed'
			: preload.queueState === 'idle'
				? 'Idle'
				: 'Running';
	const [openId, setOpenId] = useState(0);
	const [details, setDetails] = useState({});
	const [loadingId, setLoadingId] = useState(0);

	const toggle = (job) => {
		if (!job.id) {
			return;
		}

		if (openId === job.id) {
			setOpenId(0);
			return;
		}

		setOpenId(job.id);

		if (details[job.id] || !actions.getPreloadJob) {
			return;
		}

		setLoadingId(job.id);

		actions.getPreloadJob(job.id)
			.then((result) => setDetails((current) => ({...current, [job.id]: result})))
			.catch((error) => actions.notify?.('error', error.message))
			.finally(() => setLoadingId(0));
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
			{lastTotal > 0 ? (
				<p className="khse-queue-summary">
					<strong>Last run:</strong>{' '}
					{formatNumber(Number(lastRun.success) || 0)} of {formatNumber(lastTotal)} pages {nonBlocking ? 'dispatched' : 'preloaded'}
					{lastFailed > 0 ? ` · ${formatNumber(lastFailed)} failed` : ' · no failures'}
					{' · '}{lastRun.finished || 'recently'}
					{nonBlocking ? '. The server finishes warming non-blocking requests after dispatch.' : '.'}
				</p>
			) : null}
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
						{jobs.length ? jobs.map((job) => {
							const open = openId === job.id;

							return (
								<React.Fragment key={`${job.id}-${job.label}`}>
									<tr
										className={`khse-queue-row${open ? ' is-open' : ''}${job.id ? ' is-expandable' : ''}`}
										onClick={() => toggle(job)}
										onKeyDown={(event) => {
											if (job.id && (event.key === 'Enter' || event.key === ' ')) {
												event.preventDefault();
												toggle(job);
											}
										}}
										tabIndex={job.id ? 0 : undefined}
										role={job.id ? 'button' : undefined}
										aria-expanded={job.id ? open : undefined}
									>
										<td data-label="Job">
											<span className="khse-queue-row__title">
												{job.id ? (open ? <ChevronDown size={14} aria-hidden="true" /> : <ChevronRight size={14} aria-hidden="true" />) : null}
												<strong>{job.label}</strong>
											</span>
										</td>
										<td data-label="Status">
											<Pill tone={statusTone(job.status)}>
												{job.status === 'processing' ? 'Running' : humanLabel(job.status)}
											</Pill>
										</td>
										<td data-label="Attempts">{formatNumber(job.attempts)}</td>
										<td data-label="Updated">{job.updated}</td>
										<td data-label="Action" className="khse-table__action" onClick={(event) => event.stopPropagation()}>
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
									{open ? (
										<tr className="khse-queue-expanded">
											<td colSpan={5}>
												<FailureDetails job={job} details={details[job.id]} loading={loadingId === job.id} />
											</td>
										</tr>
									) : null}
								</React.Fragment>
							);
						}) : (
							<tr>
								<td colSpan={5} data-label="Queue">
									<EmptyState
										icon={Zap}
										title={lastTotal > 0 ? 'Last preload run finished' : 'No background jobs'}
										description={lastTotal > 0
											? 'The queue is empty. Pending retries and new jobs will appear here.'
											: 'Run preload to queue pages for warming.'}
									/>
								</td>
							</tr>
						)}
					</tbody>
				</table>
				{jobs.length < jobCount ? (
					<p className="khse-queue-showing">Showing {formatNumber(jobs.length)} of {formatNumber(jobCount)} current jobs.</p>
				) : null}
			</div>
			{preload.debugEnabled ? (
				<div className="khse-queue-success">
					<div className="khse-queue-success__head">
						<h3 className="khse-queue-success__title">Preloaded pages</h3>
						<Pill tone="success">{formatNumber(successCount)} logged</Pill>
					</div>
					{successLog.length ? (
						<div className="khse-table-wrap">
							<table className="khse-table">
								<thead>
									<tr>
										<th>Time</th>
										<th>Page</th>
										<th>HTTP</th>
										<th>Transport</th>
										<th>Duration</th>
									</tr>
								</thead>
								<tbody>
									{successLog.map((log) => (
										<tr key={log.id}>
											<td data-label="Time">{log.createdAt}</td>
											<td data-label="Page"><strong title={log.url || log.label}>{log.label}</strong></td>
											<td data-label="HTTP">{log.httpStatus || '—'}</td>
											<td data-label="Transport">{log.mode ? humanLabel(log.mode) : '—'}</td>
											<td data-label="Duration">{log.durationMs ? `${formatNumber(log.durationMs)} ms` : '—'}</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>
					) : (
						<p className="khse-note">No successful preload attempts recorded yet. Run preload while debug mode is on.</p>
					)}
					{successCount > successLog.length ? (
						<p className="khse-queue-showing">Showing the {formatNumber(successLog.length)} most recent of {formatNumber(successCount)} logged pages.</p>
					) : null}
				</div>
			) : null}
		</Card>
	);
}
