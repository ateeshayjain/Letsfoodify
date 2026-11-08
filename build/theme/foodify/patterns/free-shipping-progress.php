<?php
/**
 * Title: Free-shipping progress
 * Slug: foodify/free-shipping-progress
 * Categories: foodify
 * Description: Cart progress toward the free-shipping threshold. Every charge disclosed here, not at checkout.
 */
?>
<!-- The green band is drawn by the shortcode's OWN wrapper (.fd-shipping-progress
     in style.css), not by this group: the shortcode prints nothing for an empty
     cart, and a styled group around nothing was an empty green box (UX review
     8 Nov, BUY-17). The group only carries the spacing, and collapses when it
     holds no progress bar. -->
<!-- wp:group {"align":"wide","className":"fd-ship-band","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide fd-ship-band">
<!-- wp:shortcode -->
[foodify_free_shipping_progress]
<!-- /wp:shortcode -->
</div>
<!-- /wp:group -->
