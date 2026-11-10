<?php
/**
 * Deterministic per-URL CSS rollout gate.
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

final class Rollout {
	public function allows( string $url, int $percentage ): bool {
		if ( $percentage >= 100 ) {
			return true;
		}
		if ( $percentage <= 0 ) {
			return false;
		}

		$bucket = hexdec( substr( hash( 'sha256', strtolower( $url ) ), 0, 8 ) ) % 100;

		return $bucket < $percentage;
	}
}
