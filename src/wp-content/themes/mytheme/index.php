<?php
/**
 * Fallback template: posts, pages, archives and search all render here.
 *
 * @package mytheme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="site-main">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : ?>
			<?php the_post(); ?>
			<article <?php post_class(); ?>>
				<h1>
					<?php if ( is_singular() ) : ?>
						<?php the_title(); ?>
					<?php else : ?>
						<a href="<?php echo esc_url( get_permalink() ); ?>"><?php the_title(); ?></a>
					<?php endif; ?>
				</h1>
				<?php the_content(); ?>
			</article>
		<?php endwhile; ?>
		<?php the_posts_navigation(); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing found.', 'mytheme' ); ?></p>
	<?php endif; ?>
</main>
<?php
get_footer();
