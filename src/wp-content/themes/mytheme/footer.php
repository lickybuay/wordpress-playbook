<?php
/**
 * Footer template.
 *
 * @package mytheme
 */

defined( 'ABSPATH' ) || exit;
?>
<footer class="site-footer">
	<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
</footer>
<?php wp_footer(); ?>
</body>
</html>
