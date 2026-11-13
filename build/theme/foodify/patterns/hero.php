<?php
/**
 * Title: Hero — proposition
 * Slug: foodify/hero
 * Categories: foodify
 * Description: Homepage hero. Carries the only H1 on the page and the target keyword.
 *
 * "isDark":false / .is-light IS LOAD-BEARING. WordPress's own stylesheet sets a
 * cover block's text to WHITE unless the block says its overlay is light. Without
 * it the H1 and the outline button were white on kraft-pale — invisible — on
 * every real install, while the prototype (which does not load WordPress's block
 * CSS) showed them dark. Found on a blank install, 13 Nov 2026.
 */
?>
<!-- wp:cover {"overlayColor":"kraft-pale","isDark":false,"minHeight":420,"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}}} -->
<div class="wp-block-cover alignfull is-light" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70);min-height:420px">
<span aria-hidden="true" class="wp-block-cover__background has-kraft-pale-background-color has-background-dim-0 has-background-dim"></span>
<div class="wp-block-cover__inner-container">
<!-- wp:columns {"verticalAlignment":"center","align":"wide"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center">
<!-- wp:column {"verticalAlignment":"center","width":"55%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:55%">
<!-- wp:heading {"level":1,"fontSize":"4xl"} -->
<h1 class="wp-block-heading has-4-xl-font-size"><!--FOODIFY_TAGLINE--></h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"fontSize":"md","textColor":"mute"} -->
<p class="has-mute-color has-text-color has-md-font-size">Home-style Indian meals — no preservatives, 9–12 month shelf life. Most need only hot water.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/shop/">Shop all meals</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#how-it-works">How it works</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
<!-- wp:column {"verticalAlignment":"center","width":"45%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:45%">
<!-- wp:group {"className":"fd-hero-image fd-photo-placeholder","style":{"spacing":{"padding":{"top":"var:preset|spacing|90","bottom":"var:preset|spacing|90"}}},"backgroundColor":"kraft-pale","layout":{"type":"constrained"}} -->
<div class="wp-block-group fd-hero-image fd-photo-placeholder has-kraft-pale-background-color has-background" style="padding-top:var(--wp--preset--spacing--90);padding-bottom:var(--wp--preset--spacing--90)">
<!-- wp:paragraph {"align":"center","textColor":"mute","fontSize":"base"} -->
<p class="has-text-align-center has-mute-color has-text-color has-base-font-size">Photography placeholder — prepared Dal Makhni, steam rising</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div></div>
<!-- /wp:cover -->
