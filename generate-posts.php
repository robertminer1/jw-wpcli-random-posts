<?php
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class JW_Random_Posts extends WP_CLI_Command {

		private $args, $assoc_args;

		/**
		 * Generates a Random set of posts
		 *
		 * ## OPTIONS
		 *
		 * [--type=<posttype>]
		 * : The post type
		 * ---
		 * default: post
		 * ---
		 *
		 * [--n=<int>]
		 * : The number of posts to generate
		 * ---
		 * default: 1
		 * ---
		 *
		 * [--tax=<taxonomy>]
		 * : The taxonomies to tie to the post.
		 * ---
		 * default: none
		 * ---
		 *
		 * [--tax-n=<int>]
		 * : The amount of terms to insert per taxonomy.
		 * ---
		 * default: 3
		 * ---
		 *
		 * [--featured-image]
		 * : Sets a featured image for the post.
		 *
		 * [--image-size=<width,height>]
		 * : Sets the featured image size during download - CAUTION: This downloads the images, so expect a bit of time.
		 * ---
		 * default: 1024,768
		 * ---
		 *
		 * [--img-type=<providerslug>]
		 * : Sets the image provider
		 * ---
		 * default: none
		 * options:
		 *  - abstract
		 *  - sports
		 *  - city
		 *  - people
		 *  - transport
		 *  - animals
		 *  - food
		 *  - nature
		 *  - business
		 *  - cats
		 *  - fashion
		 *  - nightlife
		 *  - fashion
		 *  - technics
		 * ---
		 *
		 * [--author=<id>]
		 * : The post author id
		 * ---
		 * default: 1
		 * ---
		 *
		 * [--site=<site_id>]
		 * : If multisite is enabled, you can specify a site id
		 * ---
		 * default: false
		 * ---
		 */
		public function posts( $args, $assoc_args ) {

			$this->args = $args;
			$this->assoc_args = $assoc_args;

			$post_type = isset( $assoc_args['type'] ) ? $assoc_args['type'] : 'post';
			if ( 'post' !== $post_type && ! post_type_exists( $post_type ) ) {
				WP_CLI::error( sprintf( 'The %s post type does not exist, make sure it is registered properly.', $post_type ) );
			}

			$featured_image = isset( $assoc_args['featured-image'] ) ? true : false;
			$number_posts   = isset( $assoc_args['n'] ) ? intval( $assoc_args['n'] ) : 1;
			$taxonomies     = isset( $assoc_args['tax'] ) ? explode( ',', $assoc_args['tax'] ) : array();
			$term_count     = isset( $assoc_args['tax-n'] ) ? intval( $assoc_args['tax-n'] ) : 3;
			$post_author    = isset( $assoc_args['author'] ) ? intval( $assoc_args['author'] ) : 1;
			$blog_id        = isset( $assoc_args['site'] ) ? intval( $assoc_args['site'] ) : false;

			if ( isset( $assoc_args['img-type'] ) && ! in_array( $assoc_args['img-type'], $this->get_image_types() ) ) {
				WP_CLI::error( sprintf( 'The image provider %s is not available, you may only use "lorempixel" or "placekitten".', $assoc_args['img-type'] ) );
			}

			if ( $blog_id && is_multisite() ) {
				switch_to_blog( $blog_id );
			}

			// Validate the author exists
			$user_exists = get_user_by( 'ID', $post_author );
			if ( ! $user_exists ) {
				WP_CLI::error( sprintf( 'User ID %d does not exist within the WordPress database, cannot continue.', $post_author ) );
			}

			$image_size_arr = isset( $assoc_args['image-size'] ) ? explode( ',', $assoc_args['image-size'] ) : array( 1024, 768 );
			if ( 2 !== count( $image_size_arr ) ) {
				WP_CLI::error( "You either have too many, or too little attributes for image size. Ensure you're using a comma delimited string like 1024,768" );
			}

			if ( ! empty( $taxonomies ) ) {
				$taxonomies = array_filter( $taxonomies );
			}

			// Setup terms
			$term_data = array();
			if ( ! empty( $taxonomies ) && 0 < $term_count ) {
				WP_CLI::line( sprintf( 'Generating %1$d separate terms for %2$d taxonomies, this may take awhile.', $term_count, count( $taxonomies ) ) );
				foreach ( $taxonomies as $taxonomy ) {
					$term_names = array();
					for ( $n = 0; $n < $term_count; $n ++ ) {
						$term = $this->get_term();
						if ( empty( $term ) ) {
							continue;
						}
						$term_names[] = ucfirst( $term );
					}

					foreach ( $term_names as $name ) {
						$term_result = wp_insert_term( $name, $taxonomy );
						if ( is_wp_error( $term_result ) ) {
							WP_CLI::warning( sprintf( 'Received an error inserting %1$s term into the %2$s taxonomy: %3$s', $name, $taxonomy, $term_result->get_error_message() ) );
							continue;
						}

						if ( ! isset( $term_result['term_id'] ) ) {
							WP_CLI::warning( sprintf( 'For some reason the term_id key is not set for %1$s term after inserting, instead we got: %2$s', $name, print_r( $term_result, 1 ) ) );
							continue;
						}

						if ( ! isset( $term_data[ $taxonomy ] ) ) {
							$term_data[ $taxonomy ] = array();
						}

						$term_data[ $taxonomy ][] = $term_result['term_id'];
						WP_CLI::success( sprintf( 'Successfully inserted the %1$s term into the %2$s taxonomy.', $name, $taxonomy ) );
					}
				}
			}

			// Now make some posts shall we?
			for ( $i = 0; $i < $number_posts; $i++ ) {
				$post_content = $this->get_post_content();
				if ( empty( $post_content ) ) {
					continue;
				}

				$post_title = $this->get_title_from_text( $post_content );
				if ( empty( $post_title ) ) {
					continue;
				}

				$post_result = wp_insert_post( $post_insert_args = array(
					'post_type'    => $post_type,
					'post_title'   => $post_title,
					'post_content' => $post_content,
					'post_status'  => 'publish',
					'post_author'  => $post_author,
				), true );

				if ( is_wp_error( $post_result ) ) {
					WP_CLI::warning( sprintf( 'Received an error when trying to insert a post, got: %s', $post_result->get_error_message() ) );
					continue;
				}

				if ( isset( $term_data ) && ! empty( $term_data ) ) {
					WP_CLI::line( sprintf( 'Now setting terms for post %d', $post_result ) );
					foreach ( $term_data as $taxonomy => $terms ) {
						shuffle( $terms );
						$random_terms = array_slice( $terms, 0, mt_rand( 1, count( $terms ) ) );
						$is_set = wp_set_object_terms( $post_result, $random_terms, $taxonomy );
						if ( false === $is_set ) {
							WP_CLI::warning( sprintf( 'Apparently the post_id of %d is not actually an integer.', $post_result ) );
							continue;
						}

						if ( is_wp_error( $is_set ) ) {
							WP_CLI::warning( sprintf( 'Got an error when attempting to assign terms to post id %d: %s', $post_result, $is_set->get_error_message() ) );
							continue;
						}

						WP_CLI::success( sprintf( 'Successfully set %s terms for post %d', $taxonomy, $post_result ) );
					}
				}

				if ( $featured_image ) {
					$image_id = $this->download_image( $image_size_arr, $post_result );
					if ( empty( $image_id ) ) {
						continue;
					}

					set_post_thumbnail( $post_result, $image_id );
				}

				WP_CLI::success( sprintf( 'Finally imported post id %d', $post_result ) );
			}

			if ( $blog_id && is_multisite() ) {
				restore_current_blog();
			}

		}

		/**
		 * Generates a randomly sized title from a block of text.
		 * @param $text
		 *
		 * @author JayWood
		 * @return string
		 */
		private function get_title_from_text( $text ) {
			$title = array_values( array_filter( explode( "\n", $text ) ) );

			if ( empty( $title ) || ! is_array( $title ) ) {
				WP_CLI::warning( sprintf( 'Got an error when working with title, we got: %s', $title ) );
				return '';
			}

			$offset = isset( $title[1] ) ? $title[1] : $title[0];
			return wp_trim_words( $offset, mt_rand( 1, 12 ), '' );
		}

		/**
		 * Gets the post content text, if possible.
		 *
		 * @author JayWood
		 * @return string
		 */
		private function get_post_content() {
			$paragraphs = mt_rand( 1, 10 );
			$request = wp_safe_remote_get( sprintf( 'https://baconipsum.com/api/?type=meat-and-filler&paras=%d&format=text', $paragraphs ) );
			if ( is_wp_error( $request ) ) {
				WP_CLI::warning( sprintf( 'Received an error when trying to make bacon: %s', $request->get_error_message() ) );
				return '';
			}

			return wp_remote_retrieve_body( $request );
		}

		/**
		 * Contacts a random word generator for terms.
		 * @author JayWood
		 * @return string
		 */
		private function get_term() {
			$request = wp_safe_remote_get( 'http://randomword.setgetgo.com/get.php' );
			if ( is_wp_error( $request ) ) {
				WP_CLI::warning( sprintf( 'Received an error when trying to make bacon: %s', $request->get_error_message() ) );
				return '';
			}

			return wp_remote_retrieve_body( $request );
		}

		/**
		 * Downloads the images from placekitten.com or lorempixel.com
		 * @param array $sizes
		 * @param int $post_id
		 *
		 * @author JayWood
		 *
		 * @return int|null The new attachment ID
		 */
		private function download_image( $sizes, $post_id = 0 ) {
			$sizes = implode( '/', array_filter( $sizes ) );

			$img_type = isset( $this->assoc_args['img-type'] ) ? $this->assoc_args['img-type'] : '';

			$url = 'http://lorempixel.com/' . $sizes;
			if ( ! empty( $img_type ) ) {
				$url .= '/' . $img_type;
			}
			WP_CLI::line( sprintf( 'Downloading an image with the size of %s, please wait...', str_replace( '/', 'x', $sizes ) ) );

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$tmp        = download_url( $url );
			$type       = image_type_to_extension( exif_imagetype( $tmp ) );
			$file_array = array(
				'name'     => 'placeholderImage_' . mt_rand( 30948, 40982 ) . '_' . str_replace( '/', 'x', $sizes ) . $type,
				'tmp_name' => $tmp,
			);

			if ( is_wp_error( $tmp ) ) {
				@unlink( $tmp );
				WP_CLI::warning( sprintf( 'Got an error with tmp: %s', $tmp->get_error_message() ) );
				return null;
			}

			$id = media_handle_sideload( $file_array, $post_id );
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp );
				WP_CLI::warning( sprintf( 'Got an error with id: %s', $id->get_error_message() ) );
				return null;
			}

			WP_CLI::success( 'Successfully downloaded image and attached to post.' );
			return $id;
		}

		private function get_image_types() {
			return array(
				'abstract',
				'sports',
				'city',
				'people',
				'transport',
				'animals',
				'food',
				'nature',
				'business',
				'cats',
				'fashion',
				'nightlife',
				'fashion',
				'technics',
			);
		}
	}

	WP_CLI::add_command( 'jw-random', 'JW_Random_Posts' );
}