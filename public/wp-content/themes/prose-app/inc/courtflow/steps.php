<?php
/**
 * CourtFlow workflow step catalog (UI constants).
 *
 * @package ProseApp
 */

namespace ProseApp\Courtflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<int, array<string, mixed>>
 */
function step_catalog(): array {
	return array(
		array(
			'id'          => 'intake',
			'label'       => __( 'Intake', 'prose-app' ),
			'icon'        => 'clipboard',
			'node_slugs'  => array( 'intake_basics' ),
			'description' => __( 'Tell us about your situation', 'prose-app' ),
		),
		array(
			'id'          => 'case_classification',
			'label'       => __( 'Case Classification', 'prose-app' ),
			'icon'        => 'scale',
			'node_slugs'  => array(),
			'description' => __( 'Determine your case type', 'prose-app' ),
		),
		array(
			'id'          => 'required_forms',
			'label'       => __( 'Required Forms', 'prose-app' ),
			'icon'        => 'document',
			'node_slugs'  => array(),
			'description' => __( 'Forms needed for your filing', 'prose-app' ),
		),
		array(
			'id'          => 'child_information',
			'label'       => __( 'Child Information', 'prose-app' ),
			'icon'        => 'users',
			'node_slugs'  => array( 'collect_child_information' ),
			'description' => __( 'Details about minor children', 'prose-app' ),
		),
		array(
			'id'          => 'financial_information',
			'label'       => __( 'Financial Information', 'prose-app' ),
			'icon'        => 'dollar',
			'node_slugs'  => array( 'collect_financial_information' ),
			'description' => __( 'Income and financial disclosures', 'prose-app' ),
		),
		array(
			'id'          => 'validation',
			'label'       => __( 'Validation', 'prose-app' ),
			'icon'        => 'shield-check',
			'node_slugs'  => array(),
			'description' => __( 'Review for completeness', 'prose-app' ),
		),
		array(
			'id'          => 'review_documents',
			'label'       => __( 'Review Documents', 'prose-app' ),
			'icon'        => 'eye',
			'node_slugs'  => array(),
			'description' => __( 'Preview generated forms', 'prose-app' ),
		),
		array(
			'id'          => 'generate_package',
			'label'       => __( 'Generate Filing Package', 'prose-app' ),
			'icon'        => 'package',
			'node_slugs'  => array(),
			'description' => __( 'Compile your court documents', 'prose-app' ),
		),
		array(
			'id'          => 'ready_to_file',
			'label'       => __( 'Ready To File', 'prose-app' ),
			'icon'        => 'check-circle',
			'node_slugs'  => array(),
			'description' => __( 'Download and file with the court', 'prose-app' ),
		),
	);
}

/**
 * Map workflow node slug to step index (0-based).
 */
function step_index_for_node( ?string $node_slug ): int {
	if ( ! $node_slug ) {
		return 0;
	}

	foreach ( step_catalog() as $index => $step ) {
		$slugs = $step['node_slugs'] ?? array();
		if ( in_array( $node_slug, $slugs, true ) ) {
			return $index;
		}
	}

	$slug_map = array(
		'collect_marriage_info' => 1,
	);

	return $slug_map[ $node_slug ] ?? 0;
}

/**
 * Compute step states for UI.
 *
 * @param array<string, mixed> $context
 * @return array<int, array<string, mixed>>
 */
function compute_step_states( array $context = array() ): array {
	$steps       = step_catalog();
	$current_idx = isset( $context['current_step_index'] )
		? (int) $context['current_step_index']
		: step_index_for_node( $context['current_node_slug'] ?? null );

	$has_errors   = ! empty( $context['validation']['errors'] );
	$has_warnings = ! empty( $context['validation']['warnings'] );
	$has_forms    = ! empty( $context['required_forms'] );
	$has_docs     = ! empty( $context['documents'] );
	$valid        = ! empty( $context['validation']['valid'] );

	$result = array();

	foreach ( $steps as $index => $step ) {
		$state = 'locked';

		if ( $index < $current_idx ) {
			$state = 'completed';
		} elseif ( $index === $current_idx ) {
			$state = 'current';
		} elseif ( $index === $current_idx + 1 ) {
			$state = 'upcoming';
		}

		if ( 2 === $index && $has_forms && $index <= $current_idx ) {
			$state = $index < $current_idx ? 'completed' : 'current';
		}

		if ( 5 === $index && $has_errors ) {
			$state = 'error';
		} elseif ( 5 === $index && $has_warnings && 'locked' !== $state ) {
			$state = 'warning';
		}

		if ( 5 === $index && $valid && $index <= $current_idx ) {
			$state = $index < $current_idx ? 'completed' : ( $index === $current_idx ? 'current' : $state );
		}

		if ( 7 === $index && $has_docs ) {
			$state = $index <= $current_idx ? ( $index < $current_idx ? 'completed' : 'current' ) : 'upcoming';
		}

		if ( 8 === $index && $valid && $has_docs ) {
			$state = 'completed';
		}

		$result[] = array_merge(
			$step,
			array(
				'index'  => $index,
				'state'  => $state,
				'number' => $index + 1,
			)
		);
	}

	return $result;
}

/**
 * Progress percent from step states.
 *
 * @param array<int, array<string, mixed>> $step_states
 */
function progress_percent( array $step_states ): int {
	$total     = count( $step_states );
	$completed = 0;

	foreach ( $step_states as $step ) {
		if ( in_array( $step['state'], array( 'completed' ), true ) ) {
			++$completed;
		} elseif ( 'current' === $step['state'] ) {
			$completed += 0.5;
		}
	}

	if ( $total <= 0 ) {
		return 0;
	}

	return (int) min( 100, round( ( $completed / $total ) * 100 ) );
}
