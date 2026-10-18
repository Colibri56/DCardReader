<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads data/cards-data.json once per request and hands out single card
 * records. The full 72-card set is never sent to the AI - only the record(s)
 * for cards already drawn in the current session, per agent-brain-instructions.md.
 */
class DCard_Cards {

	private static $cards = null;

	private static function load() {
		if ( null !== self::$cards ) {
			return self::$cards;
		}

		$path = DCARD_PLUGIN_DIR . 'data/cards-data.json';
		if ( ! file_exists( $path ) ) {
			self::$cards = array();
			return self::$cards;
		}

		$json  = file_get_contents( $path );
		$cards = json_decode( $json, true );

		self::$cards = array();
		if ( is_array( $cards ) ) {
			foreach ( $cards as $card ) {
				if ( isset( $card['card_id'] ) ) {
					self::$cards[ (int) $card['card_id'] ] = $card;
				}
			}
		}

		return self::$cards;
	}

	public static function exists( $card_id ) {
		$cards = self::load();
		return isset( $cards[ (int) $card_id ] );
	}

	public static function get( $card_id ) {
		$cards = self::load();
		return isset( $cards[ (int) $card_id ] ) ? $cards[ (int) $card_id ] : null;
	}

	/**
	 * The text the AI is allowed to see for a drawn card. This is a
	 * whitelist: only textual description/meaning fields pass through, so
	 * image keys, image URLs, orientation or any field added to the data
	 * file later can never end up in an API payload.
	 */
	public static function describe( $card ) {
		if ( ! $card ) {
			return null;
		}

		$allowed = array(
			'card_id',
			'primary_objects',
			'secondary_objects',
			'themes',
			'scene_context',
			'style_media',
			'dominant_colors',
			'tags',
			'notes',
			'visual_details_to_notice',
		);

		return array_intersect_key( $card, array_flip( $allowed ) );
	}

	/**
	 * Short form for cards drawn earlier in the same session. The model has
	 * no chat history, so it still needs to know what those cards showed to
	 * relate the cards to each other, but not their full record again.
	 */
	public static function summary( $card ) {
		if ( ! $card ) {
			return null;
		}

		return array_intersect_key( $card, array_flip( array( 'card_id', 'primary_objects', 'themes' ) ) );
	}

	/**
	 * Cards for the `selected_cards` field of a chat turn: the most recently
	 * drawn card in full, earlier ones as summaries.
	 */
	public static function for_turn( array $drawn_ids ) {
		$out  = array();
		$last = count( $drawn_ids ) - 1;

		foreach ( array_values( $drawn_ids ) as $i => $id ) {
			$card = self::get( $id );
			if ( $card ) {
				$out[] = ( $i === $last ) ? self::describe( $card ) : self::summary( $card );
			}
		}

		return $out;
	}

	/**
	 * Summaries of cards drawn before the current one.
	 */
	public static function summaries( array $card_ids ) {
		$out = array();
		foreach ( $card_ids as $id ) {
			$card = self::get( $id );
			if ( $card ) {
				$out[] = self::summary( $card );
			}
		}
		return $out;
	}

	public static function get_many( array $card_ids ) {
		$out = array();
		foreach ( $card_ids as $id ) {
			$card = self::get( $id );
			if ( $card ) {
				$out[] = $card;
			}
		}
		return $out;
	}
}
