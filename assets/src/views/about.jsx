import React from 'react';
import {Code2, Database, Globe, HardDrive, Server, WandSparkles, Zap} from 'lucide-react';
import {Card, Pill} from '../components/ui.jsx';

const VERSION = window.khseDashboard?.version || '4.4.1';

const FEATURES = [
	{
		icon: Database,
		tone: 'blue',
		title: 'Cache-first pages',
		description: 'Static HTML is served to guests instead of running PHP on every request.'
	},
	{
		icon: Zap,
		tone: 'amber',
		title: 'Preload',
		description: 'Homepage links, post types, and archives are queued and warmed before visitors arrive.'
	},
	{
		icon: WandSparkles,
		tone: 'violet',
		title: 'Page optimization',
		description: 'HTML, CSS, and JavaScript transforms reduce bytes without changing how pages look.'
	},
	{
		icon: Code2,
		tone: 'cyan',
		title: 'Expert mode',
		description: 'Optional early runtime configuration for hosts with custom wp-config setups.'
	}
];

const CHANGES = [
	'The scheduled runner no longer stalls on a zero next-run timestamp.',
	'Scheduled mode replaces a stale quick-preload queue with the full-site queue.',
	'Legacy quick-preload cron events are cleared in scheduled mode.',
	'The Scheduled preload card warns when a quick queue is active.'
];

export default function AboutView({data}) {
	const environment = data.environment || {};
	const cache = data.cache || {};
	const cachingOn = Boolean(cache.enabled);

	return (
		<div className="khse-about">
			<section className="khse-about-hero">
				<div className="khse-about-hero__mark" aria-hidden="true"><Zap size={24} /></div>
				<div className="khse-about-hero__text">
					<div className="khse-about-hero__title">
						<h2>KH Speed Engine</h2>
						<Pill tone="info">v{VERSION}</Pill>
					</div>
					<p>A cache-first performance layer for WordPress: static page caching, background preload, and conservative page optimization, with every control in one native admin workspace.</p>
				</div>
			</section>

			<Card title="What's new in 4.4.1" description="Highlights from this release.">
				<ul className="khse-bullets">
					{CHANGES.map((change) => (
						<li key={change}>{change}</li>
					))}
				</ul>
			</Card>

			<div className="khse-feature-grid">
				{FEATURES.map(({icon: Icon, tone, title, description}) => (
					<Card key={title} className="khse-feature">
						<span className={`khse-feature__icon khse-feature__icon--${tone}`} aria-hidden="true"><Icon size={19} /></span>
						<h3 className="khse-feature__title">{title}</h3>
						<p className="khse-feature__description">{description}</p>
					</Card>
				))}
			</div>

			<Card title="Environment" description="Detected on this server.">
				<dl className="khse-facts khse-facts--grid">
					<div>
						<dt><Server size={14} aria-hidden="true" /> PHP</dt>
						<dd>{environment.phpVersion || '—'}</dd>
					</div>
					<div>
						<dt><Globe size={14} aria-hidden="true" /> WordPress</dt>
						<dd>{environment.wpVersion || '—'}</dd>
					</div>
					<div>
						<dt><HardDrive size={14} aria-hidden="true" /> Memory limit</dt>
						<dd>{environment.memoryLimit || '—'}</dd>
					</div>
					<div>
						<dt><Database size={14} aria-hidden="true" /> Cache driver</dt>
						<dd>{cache.driver || 'file'}</dd>
					</div>
				</dl>
				<div className="khse-about-status">
					<span>Page caching</span>
					<Pill tone={cachingOn ? 'success' : 'neutral'} dot>{cachingOn ? 'Enabled' : 'Disabled'}</Pill>
				</div>
			</Card>

			<p className="khse-about__footer">KH Speed Engine {VERSION}. Cache-first, guest-only page caching for WordPress.</p>
		</div>
	);
}
