<?php
/**
 * Schema integration for PRC Revisions.
 *
 * Hooks into prc-schema-seo filters to inject version/revision metadata
 * into the structured data output.
 *
 * @package PRC\Platform\Revisions
 */

namespace PRC\Platform\Revisions;

/**
 * Schema class.
 */
class Schema {

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( 'prc_schema_seo_article_schema', $this, 'add_version_to_article_schema', 10, 4 );
		$loader->add_filter( 'prc_schema_seo_schema_data', $this, 'add_versions_to_schema_data', 10, 3 );
	}

	/**
	 * Add version and isBasedOn properties when viewing a public revision.
	 *
	 * @hook prc_schema_seo_article_schema
	 *
	 * @param object $article     The article schema object (Spatie).
	 * @param int    $post_id     The post ID.
	 * @param array  $seo_data    SEO data array.
	 * @param string $schema_type The schema type.
	 * @return object Modified article schema.
	 */
	public function add_version_to_article_schema( $article, $post_id, $seo_data, $schema_type ) {
		$context = Public_Revisions::get_current_revision_context();

		if ( null === $context || (int) $context['parent_id'] !== (int) $post_id ) {
			return $article;
		}

		$version_label = strtoupper( $context['version'] );
		$parent_url    = get_permalink( $post_id );

		if ( method_exists( $article, 'setProperty' ) ) {
			$article->setProperty( 'version', $version_label );
			$article->setProperty( 'isBasedOn', $parent_url );
		}

		return $article;
	}

	/**
	 * Add a hasPart array to the parent post schema listing all public versions.
	 *
	 * @hook prc_schema_seo_schema_data
	 *
	 * @param array $schemas  The schemas array.
	 * @param int   $post_id  The post ID.
	 * @param array $seo_data SEO data array.
	 * @return array Modified schemas array.
	 */
	public function add_versions_to_schema_data( $schemas, $post_id, $seo_data ) {
		$context = Public_Revisions::get_current_revision_context();
		if ( null !== $context ) {
			return $schemas;
		}

		$public_revisions = Public_Revisions::get_public_revisions( $post_id );
		if ( empty( $public_revisions ) ) {
			return $schemas;
		}

		$parent_url = get_permalink( $post_id );
		$parts      = array();

		foreach ( $public_revisions as $entry ) {
			$revision = get_post( $entry['revision_id'] );
			if ( ! $revision ) {
				continue;
			}

			$version_url = trailingslashit( $parent_url ) . Rewrite::ENDPOINT . '/' . $entry['version'];
			$parts[]     = array(
				'@type'       => 'WebPage',
				'name'        => get_the_title( $post_id ) . ' (Version ' . strtoupper( $entry['version'] ) . ')',
				'url'         => $version_url,
				'dateCreated' => $revision->post_date,
				'version'     => strtoupper( $entry['version'] ),
			);
		}

		if ( ! empty( $parts ) ) {
			foreach ( $schemas as &$schema ) {
				if ( is_object( $schema ) && method_exists( $schema, 'setProperty' ) ) {
					$schema->setProperty( 'hasPart', $parts );
					break;
				}
			}
		}

		return $schemas;
	}
}
