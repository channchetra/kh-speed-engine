<?php
/**
 * Stylesheet value object.
 *
 * Adapted from GT Performance 1.3.6 (GPL-2.0-or-later).
 *
 * @package KH Speed Engine
 * @license GPLv3 (or later)
 */

declare(strict_types=1);

namespace KHSE\Optimization\Css;

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

final class Stylesheet {
	public function __construct(
		public readonly string $source,
		public readonly string $css,
		public readonly string $media = 'all',
	) {
	}
}
