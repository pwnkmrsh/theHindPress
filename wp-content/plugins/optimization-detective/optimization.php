<?php
/**
 * Optimizing for Optimization Detective.
 *
 * @package optimization-detective
 * @since 0.1.0
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
// @codeCoverageIgnoreEnd

/**
 * Starts output buffering at the end of the 'template_include' filter.
 *
 * This is to implement #43258 in core.
 *
 * This is a hack which would eventually be replaced with something like this in wp-includes/template-loader.php:
 *
 *          $template = apply_filters( 'template_include', $template );
 *     +    ob_start( 'wp_template_output_buffer_callback' );
 *          if ( $template ) {
 *              include $template;
 *          } elseif ( current_user_can( 'switch_themes' ) ) {
 *
 * @since 0.1.0
 * @access private
 * @link https://core.trac.wordpress.org/ticket/43258
 *
 * @param string|mixed $passthrough Value for the template_include filter which is passed through.
 * @return string|mixed Unmodified value of $passthrough.
 */
function od_buffer_output( $passthrough ) {
	/*
	 * Instead of the default PHP_OUTPUT_HANDLER_STDFLAGS (cleanable, flushable, and removable) being used for flags,
	 * we need to omit PHP_OUTPUT_HANDLER_FLUSHABLE. If the buffer were flushable, then each time that ob_flush() is
	 * called, it would send a fragment of the output into the output buffer callback. When buffering the entire
	 * response as an HTML document, this would result in broken HTML processing.
	 *
	 * If this ends up being problematic, then PHP_OUTPUT_HANDLER_FLUSHABLE could be added to the $flags and the
	 * output buffer callback could check if the phase is PHP_OUTPUT_HANDLER_FLUSH and abort any subsequent
	 * processing while also emitting a _doing_it_wrong().
	 *
	 * The output buffer needs to be removable because WordPress calls wp_ob_end_flush_all() and then calls
	 * wp_cache_close(). If the buffers are not all flushed before wp_cache_close() is closed, then some output buffer
	 * handlers (e.g. for caching plugins) may fail to be able to store the page output in the object cache.
	 * See <https://github.com/WordPress/performance/pull/1317#issuecomment-2271955356>.
	 */
	$flags = PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE;

	ob_start(
		static function ( string $output, ?int $phase ): string {
			// When the output is being cleaned (e.g. pending template is replaced with error page), do not send it through the filter.
			if ( ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) !== 0 ) {
				return $output;
			}

			/**
			 * Filters the template output buffer prior to sending to the client.
			 *
			 * @since 0.1.0
			 *
			 * @param string $output Output buffer.
			 * @return string Filtered output buffer.
			 */
			return (string) apply_filters( 'od_template_output_buffer', $output );
		},
		0, // Unlimited buffer size.
		$flags
	);
	return $passthrough;
}

/**
 * Adds template output buffer filter for optimization if eligible.
 *
 * @since 0.1.0
 * @access private
 */
function od_maybe_add_template_output_buffer_filter(): void {
	$conditions = array(
		array(
			'test'   => od_can_optimize_response(),
			'reason' => __( 'Page is not optimized because od_can_optimize_response() returned false. This can be overridden with the od_can_optimize_response filter.', 'optimization-detective' ),
		),
		array(
			'test'   => ! od_is_rest_api_unavailable() || ( wp_get_environment_type() === 'local' && ! function_exists( 'tests_add_filter' ) ),
			'reason' => __( 'Page is not optimized because the REST API for storing URL Metrics is not available.', 'optimization-detective' ),
		),
		array(
			'test'   => ! isset( $_GET['optimization_detective_disabled'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'reason' => __( 'Page is not optimized because the URL has the optimization_detective_disabled query parameter.', 'optimization-detective' ),
		),
	);
	$reasons    = array();
	foreach ( $conditions as $condition ) {
		if ( ! $condition['test'] ) {
			$reasons[] = $condition['reason'];
		}
	}
	if ( count( $reasons ) > 0 ) {
		if ( WP_DEBUG ) {
			add_action(
				'wp_print_footer_scripts',
				static function () use ( $reasons ): void {
					od_print_disabled_reasons( $reasons );
				}
			);
		}
		return;
	}

	$callback = 'od_optimize_template_output_buffer';
	if (
		function_exists( 'perflab_wrap_server_timing' )
		&&
		function_exists( 'perflab_server_timing_use_output_buffer' )
		&&
		perflab_server_timing_use_output_buffer()
	) {
		$callback = perflab_wrap_server_timing( $callback, 'optimization-detective', 'exist' );
	}
	add_filter( 'od_template_output_buffer', $callback );
}

/**
 * Prints the reasons why Optimization Detective is not optimizing the current page.
 *
 * This is only used when WP_DEBUG is enabled.
 *
 * @since 1.0.0
 * @access private
 *
 * @param string[] $reasons Reason messages.
 */
function od_print_disabled_reasons( array $reasons ): void {
	foreach ( $reasons as $reason ) {
		wp_print_inline_script_tag(
			sprintf(
				'console.info( %s );',
				(string) wp_json_encode( '[Optimization Detective] ' . $reason )
			),
			array( 'type' => 'module' )
		);
	}
}

/**
 * Determines whether the current response can be optimized.
 *
 * @since 0.1.0
 * @since 0.9.0 Response is optimized for admin users as well when in 'plugin' development mode.
 *
 * @access private
 *
 * @return bool Whether response can be optimized.
 */
function od_can_optimize_response(): bool {
	$able = ! (
		// Since there is no predictability in whether posts in the loop will have featured images assigned or not. If a
		// theme template for search results doesn't even show featured images, then this wouldn't be an issue.
		is_search() ||
		// Avoid optimizing embed responses because the Post Embed iframes include a sandbox attribute with the value of
		// "allow-scripts" but without "allow-same-origin". This can result in an error in the console:
		// > Access to script at '.../detect.js?ver=0.4.1' from origin 'null' has been blocked by CORS policy: No 'Access-Control-Allow-Origin' header is present on the requested resource.
		// So it's better to just avoid attempting to optimize Post Embed responses (which don't need optimization anyway).
		is_embed() ||
		// Skip posts that aren't published yet.
		is_preview() ||
		// Since injection of inline-editing controls interfere with breadcrumbs, while also just not necessary in this context.
		is_customize_preview() ||
		// Since the images detected in the response body of a POST request cannot, by definition, be cached.
		( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) ||
		// Page caching plugins can only reliably be told to invalidate a cached page when a post is available to trigger
		// the relevant actions on.
		null === od_get_cache_purge_post_id()
	);

	/**
	 * Filters whether the current response can be optimized.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $able Whether response can be optimized.
	 */
	return (bool) apply_filters( 'od_can_optimize_response', $able );
}

/**
 * Determines whether the response has an HTML Content-Type.
 *
 * @since 0.2.0
 * @private
 *
 * @return bool Whether Content-Type is HTML.
 */
function od_is_response_html_content_type(): bool {
	$is_html_content_type = false;

	$headers_list = array_merge(
		array( 'Content-Type: ' . ini_get( 'default_mimetype' ) ),
		headers_list()
	);
	foreach ( $headers_list as $header ) {
		$header_parts = preg_split( '/\s*[:;]\s*/', strtolower( $header ) );
		if ( is_array( $header_parts ) && count( $header_parts ) >= 2 && 'content-type' === $header_parts[0] ) {
			$is_html_content_type = in_array( $header_parts[1], array( 'text/html', 'application/xhtml+xml' ), true );
		}
	}

	return $is_html_content_type;
}

/**
 * Optimizes template output buffer.
 *
 * @since 0.1.0
 * @access private
 *
 * @global WP_Query $wp_the_query WP_Query object.
 *
 * @param string $buffer Template output buffer.
 * @return string Filtered template output buffer.
 */
function od_optimize_template_output_buffer( string $buffer ): string {
	global $wp_the_query;

	// If the content-type is not HTML or the output does not start with '<', then abort since the buffer is definitely not HTML.
	if (
		! od_is_response_html_content_type() ||
		! str_starts_with( ltrim( $buffer ), '<' )
	) {
		return $buffer;
	}

	// If the initial tag is not an open HTML tag, then abort since the buffer is not a complete HTML document.
	$processor = new OD_HTML_Tag_Processor( $buffer );
	if ( ! (
		$processor->next_tag() &&
		! $processor->is_tag_closer() &&
		'HTML' === $processor->get_tag()
	) ) {
		return $buffer;
	}

	$slug = od_get_url_metrics_slug( od_get_normalized_query_vars() );
	$post = OD_URL_Metrics_Post_Type::get_post( $slug );

	$tag_visitor_registry = new OD_Tag_Visitor_Registry();

	/**
	 * Fires to register tag visitors before walking over the document to perform optimizations.
	 *
	 * Once a page has finished rendering and the output buffer is processed, the page contents are loaded into
	 * an HTML Tag Processor instance. It then iterates over each tag in the document, and at each open tag it will
	 * invoke all registered tag visitors. A tag visitor is simply a callable (such as a regular function, closure,
	 * or even a class with an `__invoke` method defined). The tag visitor callback is invoked by passing an instance
	 * of the `OD_Tag_Visitor_Context` object which includes the following read-only properties:
	 *
	 * - `$processor` (`OD_HTML_Tag_Processor`): The processor with the cursor at the current open tag.
	 * - `$url_metric_group_collection` (`OD_URL_Metric_Group_Collection`): The URL Metrics which may include information about the current tag to inform what optimizations the callback performs.
	 * - `$link_collection` (`OD_Link_Collection`): Collection of links which will be added to the `HEAD` when the page is served. This allows you to add preload links and preconnect links as needed.
	 * - `$url_metrics_id` (`positive-int|null`): The post ID for the `od_url_metrics` post from which the URL Metrics were loaded (if any). For advanced usage.
	 *
	 * Note that you are free to call `$processor->next_tag()` in the callback (such as to walk over any child elements)
	 * since the tag processor's cursor will be reset to the tag after the callback finishes.
	 *
	 * When a tag visitor sees it is at a relevant open tag (e.g. by checking `$processor->get_tag()`), it can call the
	 * `$context->track_tag()` method to indicate that the tag should be measured during detection. This will cause the
	 * tag to be included among the `elements` in the stored URL Metrics. The element data includes properties such
	 * as `intersectionRatio`, `intersectionRect`, and `boundingClientRect` (provided by an `IntersectionObserver`) as
	 * well as whether the tag is the LCP element (`isLCP`) or LCP element candidate (`isLCPCandidate`). This method
	 * should not be called if the current tag is not relevant for the tag visitor or if the tag visitor callback does
	 * not need to query the provided `OD_URL_Metric_Group_Collection` instance to apply the desired optimizations. (In
	 * addition to calling the `$context->track_tag()`, a callback may also return `true` to indicate the tag should be
	 * tracked.)
	 *
	 * Here's an example tag visitor that depends on URL Metrics data:
	 *
	 *     $tag_visitor_registry->register(
	 *         'lcp-img-fetchpriority-high',
	 *         static function ( OD_Tag_Visitor_Context $context ): void {
	 *             if ( $context->processor->get_tag() !== 'IMG' ) {
	 *                 return; // Tag is not relevant for this tag visitor.
	 *             }
	 *
	 *             // Mark the tag for measurement during detection so it is included among the elements stored in URL Metrics.
	 *             $context->track_tag();
	 *
	 *             // Make sure fetchpriority=high is added to LCP IMG elements based on the captured URL Metrics.
	 *             $common_lcp_element = $context->url_metric_group_collection->get_common_lcp_element();
	 *             if (
	 *                 null !== $common_lcp_element
	 *                 &&
	 *                 $common_lcp_element->get_xpath() === $context->processor->get_xpath()
	 *             ) {
	 *                 $context->processor->set_attribute( 'fetchpriority', 'high' );
	 *             }
	 *         }
	 *     );
	 *
	 * Please note this implementation of setting `fetchpriority=high` on the LCP `IMG` element is simplified. Please
	 * see the Image Prioritizer extension for a more robust implementation.
	 *
	 * Here's an example tag visitor that does not depend on any URL Metrics data:
	 *
	 *     $tag_visitor_registry->register(
	 *         'img-decoding-async',
	 *         static function ( OD_Tag_Visitor_Context $context ): bool {
	 *             if ( $context->processor->get_tag() !== 'IMG' ) {
	 *                 return; // Tag is not relevant for this tag visitor.
	 *             }
	 *
	 *             // Set the decoding attribute if it is absent.
	 *             if ( null === $context->processor->get_attribute( 'decoding' ) ) {
	 *                 $context->processor->set_attribute( 'decoding', 'async' );
	 *             }
	 *         }
	 *     );
	 *
	 * Refer to [Image Prioritizer](https://github.com/WordPress/performance/tree/trunk/plugins/image-prioritizer) and
	 * [Embed Optimizer](https://github.com/WordPress/performance/tree/trunk/plugins/embed-optimizer) for additional
	 * examples of how tag visitors are used.
	 *
	 * @since 0.3.0
	 *
	 * @param OD_Tag_Visitor_Registry $tag_visitor_registry Tag visitor registry.
	 */
	do_action( 'od_register_tag_visitors', $tag_visitor_registry );

	$current_etag         = od_get_current_url_metrics_etag( $tag_visitor_registry, $wp_the_query, od_get_current_theme_template() );
	$group_collection     = new OD_URL_Metric_Group_Collection(
		$post instanceof WP_Post ? OD_URL_Metrics_Post_Type::get_url_metrics_from_post( $post ) : array(),
		$current_etag,
		od_get_breakpoint_max_widths(),
		od_get_url_metrics_breakpoint_sample_size(),
		od_get_url_metric_freshness_ttl()
	);
	$link_collection      = new OD_Link_Collection();
	$visited_tag_state    = new OD_Visited_Tag_State();
	$tag_visitor_context  = new OD_Tag_Visitor_Context(
		$processor,
		$group_collection,
		$link_collection,
		$visited_tag_state,
		$post instanceof WP_Post && $post->ID > 0 ? $post->ID : null
	);
	$current_tag_bookmark = 'optimization_detective_current_tag';
	$visitors             = iterator_to_array( $tag_visitor_registry );

	// Whether we need to add the data-od-xpath attribute to elements and whether the detection script should be injected.
	$needs_detection = ! $group_collection->is_every_group_complete();

	do {
		// Never process anything inside NOSCRIPT since it will never show up in the DOM when scripting is enabled, and thus it can never be detected nor measured.
		if ( in_array( 'NOSCRIPT', $processor->get_breadcrumbs(), true ) ) {
			continue;
		}

		$tracked_in_url_metrics = false;
		$processor->set_bookmark( $current_tag_bookmark ); // TODO: Should we break if this returns false?

		foreach ( $visitors as $visitor ) {
			$cursor_move_count    = $processor->get_cursor_move_count();
			$visitor_return_value = $visitor( $tag_visitor_context );
			if ( true === $visitor_return_value ) {
				$tracked_in_url_metrics = true;
			}

			// If the visitor traversed HTML tags, we need to go back to this tag so that in the next iteration any
			// relevant tag visitors may apply, in addition to properly setting the data-od-xpath on this tag below.
			if ( $cursor_move_count !== $processor->get_cursor_move_count() ) {
				$processor->seek( $current_tag_bookmark ); // TODO: Should this break out of the optimization loop if it returns false?
			}
		}
		$processor->release_bookmark( $current_tag_bookmark );

		if ( $visited_tag_state->is_tag_tracked() ) {
			$tracked_in_url_metrics = true;
		}

		if ( $tracked_in_url_metrics && $needs_detection ) {
			// TODO: Replace get_stored_xpath with get_xpath once the transitional period is over.
			$xpath = $processor->get_stored_xpath();
			$processor->set_meta_attribute( 'xpath', $xpath );
		}

		$visited_tag_state->reset();
	} while ( $processor->next_open_tag() );

	// Send any preload links in a Link response header and in a LINK tag injected at the end of the HEAD.
	if ( count( $link_collection ) > 0 ) {
		$response_header_links = $link_collection->get_response_header();
		if ( ! is_null( $response_header_links ) && ! headers_sent() ) {
			header( $response_header_links, false );
		}
		$processor->append_head_html( $link_collection->get_html() );
	}

	// Inject detection script.
	// TODO: When optimizing above, if we find that there is a stored LCP element but it fails to match, it should perhaps set $needs_detection to true and send the request with an override nonce. However, this would require backtracking and adding the data-od-xpath attributes.
	if ( $needs_detection ) {
		$processor->append_body_html( od_get_detection_script( $slug, $group_collection ) );
	}

	return $processor->get_updated_html();
}
