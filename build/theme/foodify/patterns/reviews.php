<?php
/**
 * Title: Reviews — real ones
 * Slug: foodify/reviews
 * Categories: foodify
 * Description: Google Business Profile reviews. Replaces three testimonials attributed to the same name.
 */
?>
<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"backgroundColor":"kraft-pale","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-kraft-pale-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">
<!-- wp:heading {"fontSize":"2xl"} -->
<h2 class="wp-block-heading has-2-xl-font-size">What customers say</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"fontSize":"sm","textColor":"mute","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|50"}}}} -->
<p class="has-mute-color has-text-color has-sm-font-size" style="margin-bottom:var(--wp--preset--spacing--50)">Pulled live from our Google Business Profile. Real names, real counts — nothing written in-house.</p>
<!-- /wp:paragraph -->
<!-- wp:shortcode -->
[foodify_google_reviews limit="3"]
<!-- /wp:shortcode -->
</div>
<!-- /wp:group -->
