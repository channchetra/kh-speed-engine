import React, {useEffect, useRef} from 'react';
import {
	CheckCircle2,
	Database,
	Gauge,
	Home,
	Info,
	Play,
	RefreshCcw,
	SlidersHorizontal,
	Trash2,
	WandSparkles,
	Wrench,
	X,
	AlertCircle
} from 'lucide-react';
import {Button, Pill, Segmented} from './ui.jsx';

export const AREAS = [
	{
		key: 'overview',
		label: 'Dashboard',
		icon: Gauge
	},
	{
		key: 'cache',
		label: 'Caching',
		icon: Database,
		views: [
			{value: 'cache', label: 'Cache'},
			{value: 'preload', label: 'Preload'},
			{value: 'rules', label: 'Exclusion rules'}
		]
	},
	{
		key: 'performance',
		label: 'Optimization',
		icon: WandSparkles,
		views: [
			{value: 'performance', label: 'Performance'},
			{value: 'statistics', label: 'Statistics'}
		]
	},
	{
		key: 'expert',
		label: 'Tools',
		icon: Wrench,
		views: [
			{value: 'expert', label: 'Expert mode'},
			{value: 'benchmark', label: 'Benchmark'},
			{value: 'system-debug', label: 'System & debug'}
		]
	},
	{
		key: 'about',
		label: 'About',
		icon: Info
	}
];

export const VIEW_META = {
	overview: {
		area: 'overview',
		label: 'Dashboard',
		description: 'Is caching working, what needs attention, and what to do next.'
	},
	cache: {
		area: 'cache',
		label: 'Cache',
		description: 'Driver, storage, expiry, and traffic controls.'
	},
	preload: {
		area: 'cache',
		label: 'Preload',
		description: 'Warm the cache before guests arrive.'
	},
	rules: {
		area: 'cache',
		label: 'Exclusion rules',
		description: 'Skip caching for matching paths, cookies, and variables.'
	},
	performance: {
		area: 'performance',
		label: 'Performance',
		description: 'HTML, CSS, and JavaScript optimization for cached pages.'
	},
	statistics: {
		area: 'performance',
		label: 'Statistics',
		description: 'Cache footprint and storage by cache type.'
	},
	expert: {
		area: 'expert',
		label: 'Expert mode',
		description: 'Load runtime configuration before WordPress boots.'
	},
	benchmark: {
		area: 'expert',
		label: 'Benchmark',
		description: 'Show the benchmark widget and footer on cached pages.'
	},
	'system-debug': {
		area: 'expert',
		label: 'System & debug',
		description: 'Inspect system configuration, proxy headers, and preload attempts.'
	},
	about: {
		area: 'about',
		label: 'About',
		description: 'Version, features, and environment.'
	}
};

function areaForView(view) {
	const meta = VIEW_META[view] || VIEW_META.overview;

	return AREAS.find((area) => area.key === meta.area) || AREAS[0];
}

export function PageHeader({view, actions, busy = false, cacheEnabled = false}) {
	const meta = VIEW_META[view] || VIEW_META.overview;
	const area = areaForView(view);
	const showArea = area.key !== view;

	return (
		<header className="khse-header">
			<div className="khse-header__heading">
				<nav className="khse-breadcrumb" aria-label="Breadcrumb">
					<span>KH Speed</span>
					<span className="khse-breadcrumb__sep" aria-hidden="true">/</span>
					{showArea ? (
						<>
							<span>{area.label}</span>
							<span className="khse-breadcrumb__sep" aria-hidden="true">/</span>
						</>
					) : null}
					<span aria-current="page">{meta.label}</span>
				</nav>
				<h1 className="khse-title">{meta.label}</h1>
				<p className="khse-subtitle">{meta.description}</p>
			</div>
			<div className="khse-header__actions">
				<Pill tone={cacheEnabled ? 'success' : 'neutral'} dot>{cacheEnabled ? 'Caching on' : 'Caching off'}</Pill>
				<QuickActions actions={actions} busy={busy} />
				<Button variant="secondary" icon={RefreshCcw} iconOnly busy={busy} onClick={() => actions.refresh()} title="Refresh status">
					Refresh status
				</Button>
			</div>
		</header>
	);
}

export function PrimaryNav({view, onNavigate}) {
	const activeArea = areaForView(view);

	useEffect(() => {
		const active = document.querySelector('.khse-primary-nav__item.is-active');

		if (!active || typeof active.scrollIntoView !== 'function') {
			return;
		}

		active.scrollIntoView({block: 'nearest', inline: 'center'});
	}, [view]);

	return (
		<nav className="khse-primary-nav" aria-label="KH Speed Engine sections">
			<div className="khse-primary-nav__inner">
				{AREAS.map(({key, label, icon: Icon}) => {
					const active = activeArea.key === key;

					return (
						<button
							key={key}
							type="button"
							className={`khse-primary-nav__item${active ? ' is-active' : ''}`}
							aria-current={active ? 'page' : undefined}
							onClick={() => onNavigate(key)}
						>
							<Icon size={16} aria-hidden="true" />
							<span>{label}</span>
						</button>
					);
				})}
			</div>
		</nav>
	);
}

export function SubNav({view, onNavigate}) {
	const area = areaForView(view);

	if (!area.views?.length) {
		return null;
	}

	return (
		<div className="khse-subnav">
			<Segmented items={area.views} value={view} onChange={onNavigate} label={`${area.label} views`} />
		</div>
	);
}

export function QuickActions({actions, busy = false}) {
	const menu = useRef(null);

	useEffect(() => {
		const close = () => {
			if (menu.current) {
				menu.current.open = false;
			}
		};
		const onPointer = (event) => {
			if (menu.current?.open && !menu.current.contains(event.target)) {
				close();
			}
		};
		const onKeyDown = (event) => {
			if (event.key === 'Escape') {
				close();
			}
		};

		document.addEventListener('click', onPointer);
		document.addEventListener('keydown', onKeyDown);

		return () => {
			document.removeEventListener('click', onPointer);
			document.removeEventListener('keydown', onKeyDown);
		};
	}, []);

	const run = (action) => () => {
		if (menu.current) {
			menu.current.open = false;
		}

		action();
	};

	return (
		<details className="khse-quick" ref={menu}>
			<summary className="khse-btn khse-btn--secondary khse-btn--md khse-quick__summary">
				<SlidersHorizontal size={16} aria-hidden="true" />
				<span>Quick actions</span>
			</summary>
			<div className="khse-quick__popover">
				<button type="button" className="khse-quick__item" onClick={run(() => actions.runPreload())} disabled={busy}>
					<Play size={16} aria-hidden="true" />
					<span>Run preload</span>
				</button>
				<button type="button" className="khse-quick__item" onClick={run(() => actions.purgeHomepage())} disabled={busy}>
					<Home size={16} aria-hidden="true" />
					<span>Purge homepage</span>
				</button>
				<button type="button" className="khse-quick__item" onClick={run(() => actions.refresh())} disabled={busy}>
					<RefreshCcw size={16} aria-hidden="true" />
					<span>Refresh status</span>
				</button>
				<span className="khse-quick__divider" role="separator" />
				<button type="button" className="khse-quick__item khse-quick__item--danger" onClick={run(() => actions.clearAll())}>
					<Trash2 size={16} aria-hidden="true" />
					<span>Clear all cache</span>
				</button>
			</div>
		</details>
	);
}

export function SaveBar({dirty, busy, onSave, onDiscard}) {
	if (!dirty) {
		return null;
	}

	return (
		<div className="khse-savebar" role="region" aria-label="Unsaved settings">
			<div className="khse-savebar__inner">
				<span className="khse-savebar__status">
					<span className="khse-savebar__dot" aria-hidden="true" />
					Unsaved changes
				</span>
				<div className="khse-savebar__actions">
					<Button variant="ghost" onClick={onDiscard} disabled={busy}>Discard</Button>
					<Button variant="primary" onClick={onSave} busy={busy}>Save changes</Button>
				</div>
			</div>
		</div>
	);
}

export function Toast({toast, onClose}) {
	const close = useRef(onClose);

	close.current = onClose;

	useEffect(() => {
		if (!toast || toast.type === 'error') {
			return undefined;
		}

		const timer = window.setTimeout(() => close.current(), 4000);

		return () => window.clearTimeout(timer);
	}, [toast]);

	if (!toast) {
		return null;
	}

	const isError = toast.type === 'error';

	return (
		<div className={`khse-toast${isError ? ' khse-toast--error' : ''}`} role="status" aria-live="polite">
			{isError ? <AlertCircle size={18} aria-hidden="true" /> : <CheckCircle2 size={18} aria-hidden="true" />}
			<span className="khse-toast__message">{toast.message}</span>
			<button type="button" className="khse-toast__close" onClick={onClose} aria-label="Dismiss notification">
				<X size={15} aria-hidden="true" />
			</button>
		</div>
	);
}
