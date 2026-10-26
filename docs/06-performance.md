# 06. Performance

Most WordPress speed problems are one of four things: assets the browser cannot
cache safely, one request that does far too much work, work that is repeated for
every visitor, and images that are bigger or earlier than they need to be. This
document covers the fixes we reach for, in the order we usually need them.

Measure before and after. A change you cannot show in numbers (response size,
time to first byte, a Lighthouse or WebPageTest run, the Network tab) is a guess.

## 1. Cache-busting assets with a content hash

Long browser caching for CSS and JS is free speed, but only if a changed file gets
a new URL. The default `?ver=` is the WordPress or theme version, which nobody
bumps on every edit, so people end up either shipping stale CSS or disabling
caching.

The common fix is `filemtime()` as the version. It has a catch: **`rsync` and
`git checkout` rewrite modification times.** After a deploy every file looks new,
so every visitor re-downloads every asset even though nothing changed, and the
cache you built does nothing across releases.

Use a hash of the file content instead. It changes exactly when the file does:

```php
/**
 * URL of a theme asset with a short content hash as its version.
 * A hash (not filemtime) because deploys rewrite mtimes.
 *
 * @param string $relative Path relative to the theme, e.g. 'css/main.css'.
 */
function mysite_asset_url( $relative ) {
	static $memo = array(); // one md5 per file per request.

	$relative = ltrim( $relative, '/' );
	if ( isset( $memo[ $relative ] ) ) {
		return $memo[ $relative ];
	}

	$url  = get_stylesheet_directory_uri() . '/' . $relative;
	$path = get_stylesheet_directory() . '/' . $relative;
	$hash = is_file( $path ) ? md5_file( $path ) : false;

	if ( false !== $hash ) {
		$url = add_query_arg( 'ver', substr( $hash, 0, 8 ), $url );
	}

	$memo[ $relative ] = $url;
	return $url;
}
```

The starter theme in `src/wp-content/themes/mytheme` versions its assets this
way; copy the helper from there rather than from this page if they differ.

Eight hex characters are plenty to tell versions of one file apart. Pass `null`
as the version to `wp_enqueue_*` so WordPress does not append its own `?ver=`:

```php
wp_enqueue_style( 'mysite-main', mysite_asset_url( 'css/main.css' ), array(), null );
```

Notes:

- `filemtime()` is fine when your deploy **preserves** mtimes (`rsync -t`, or a
  build that writes files once). If you cannot say that for certain, hash.
- The cost is one `md5_file()` per asset per request. For a handful of small
  files it is noise (the OS caches the read). With hundreds of assets, compute a
  manifest at build time instead.
- **Convention that pays off:** keep every enqueue in one file
  (`inc/assets.php`) with a short comment per asset saying what it is for and
  where it loads. Six months later that file is the only way to know which of 30
  stylesheets is safe to remove.

## 2. Long cache lifetimes for static files

With a hashed URL you can cache for a long time. Add to the root `.htaccess`:

```apache
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpeg                "access plus 1 year"
  ExpiresByType image/png                 "access plus 1 year"
  ExpiresByType image/webp                "access plus 1 year"
  ExpiresByType image/avif                "access plus 1 year"
  ExpiresByType image/svg+xml             "access plus 1 year"
  ExpiresByType font/woff2                "access plus 1 year"
  ExpiresByType text/css                  "access plus 1 year"
  ExpiresByType application/javascript    "access plus 1 year"
  ExpiresByType text/javascript           "access plus 1 year"
</IfModule>

<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/json image/svg+xml
</IfModule>
```

A year on CSS and JS is only safe **because** of section 1. If any stylesheet is
enqueued without a version, give it a hash first, or cap it at a month.
Images keep their names; if you replace one, upload it under a new file name.

## 3. AJAX endpoints that return HTML: paginate

A very common pattern: a click loads a "panel" of posts through AJAX and injects
the HTML. The lazy version returns every matching item. It works on day one and
gets slower every month the content grows.

In one real case, the first response of such a panel was about **370 KB**
(hundreds of items, most of them off-screen in a carousel). Returning only the
first page of 4 to 10 items brought it to about **5 KB**, and the other pages are
fetched on demand. Page weight and query time both fall, and the browser has
less DOM to build.

Rules we follow:

1. **Return one page, and tell the client how many there are.** Put
   `data-total` and `data-pages` on a small meta element at the top of the
   fragment.
2. **Use your own query variable name, never `page`.** `page` (and `paged`, `p`,
   `s`, `name`, `cat`, `tag`, `year`...) are WordPress query variables. A request
   with `?page=2` on a normal page is read as "page 2 of this post", which does
   not exist, and WordPress answers **404**. Use a prefix: `mysite_pg`,
   `mysite_per`, `mysite_tag`. The test in
   [`tests/eval-harness/test-example.php`](../tests/eval-harness/test-example.php)
   checks a name against `$wp->public_query_vars`.
3. **Validate every parameter.** The page number must be digits only and short;
   the page size must be one of a fixed list (so nobody asks for `per=100000`);
   the tag goes through `sanitize_title()`, and that is also the value
   `WP_Query` needs (an accented tag name is not its slug).
4. **Anything invalid falls back to the old behavior or an error**, it never
   becomes a cache key.

```php
/**
 * Validated parameters for the paged fragment, or null if the request is not one.
 */
function mysite_fragment_params() {
	$raw = array();
	foreach ( array( 'mysite_tag', 'mysite_pg', 'mysite_per' ) as $k ) {
		if ( ! isset( $_GET[ $k ] ) || ! is_string( $_GET[ $k ] ) ) {
			return null;
		}
		$raw[ $k ] = wp_unslash( $_GET[ $k ] );
	}

	$tag = sanitize_title( $raw['mysite_tag'] );
	if ( '' === $tag || ! ctype_digit( $raw['mysite_pg'] ) || strlen( $raw['mysite_pg'] ) > 6 ) {
		return null;
	}
	$pg  = (int) $raw['mysite_pg'];
	$per = ctype_digit( $raw['mysite_per'] ) ? (int) $raw['mysite_per'] : 0;

	if ( $pg < 1 || ! in_array( $per, array( 4, 8, 12 ), true ) ) {
		return null;
	}
	return array( 'tag' => $tag, 'pg' => $pg, 'per' => $per );
}

function mysite_render_fragment( array $p ) {
	$q = new WP_Query( array(
		'post_type'           => 'post',
		'tag'                 => $p['tag'],
		'posts_per_page'      => $p['per'],
		'paged'               => $p['pg'],
		'ignore_sticky_posts' => true,
	) );

	ob_start();
	printf(
		'<div class="mysite-meta" data-total="%d" data-pages="%d" hidden></div>',
		(int) $q->found_posts,
		(int) $q->max_num_pages
	);
	while ( $q->have_posts() ) {
		$q->the_post();
		printf( '<article data-id="%d"><a href="%s">%s</a></article>', get_the_ID(), esc_url( get_permalink() ), esc_html( get_the_title() ) );
	}
	wp_reset_postdata();
	return ob_get_clean();
}
```

## 4. Cache the fragment, invalidate with a version number

Even a paginated query is wasted work when 1,000 visitors ask for the same page.
Cache the finished HTML.

**Where.** `set_transient()` goes to the object cache when one is configured
(Redis, Memcached) and to the `wp_options` table otherwise. With Redis it is a
memory lookup; without it, it is still cheaper than `WP_Query`, but watch the
table size.

**Page cache plugins do not help here.** Full-page cache plugins (WP Super Cache
and similar) generally do not cache URLs that have a query string, which is
exactly what these endpoints use. The fragment cache fills that gap.

**Invalidation without scanning keys.** Deleting "all keys that start with X" is
slow or impossible on most cache backends. Instead, put a **content version**
into every key, and bump the version whenever content changes. Old keys are
never read again and expire on their own.

```php
const MYSITE_CONTENT_VERSION_OPTION = 'mysite_content_version';

function mysite_content_version() {
	$v = get_option( MYSITE_CONTENT_VERSION_OPTION );
	if ( false === $v ) {
		$v = 1;
		add_option( MYSITE_CONTENT_VERSION_OPTION, $v, '', 'yes' );
	}
	return (int) $v;
}

function mysite_bump_content_version( $post_id = 0 ) {
	if ( $post_id && ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) ) {
		return;
	}
	update_option( MYSITE_CONTENT_VERSION_OPTION, mysite_content_version() + 1, 'yes' );
}

// Anything that can change what a fragment shows.
foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'edited_term', 'created_term', 'delete_term' ) as $hook ) {
	add_action( $hook, 'mysite_bump_content_version', 999 );
}
add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( $new !== $old ) {
		mysite_bump_content_version( $post->ID );
	}
}, 999, 3 );

function mysite_fragment( array $p ) {
	$key  = 'mysite_frag_' . md5( wp_json_encode( $p ) ) . '_v' . mysite_content_version();
	$html = get_transient( $key );
	if ( false === $html ) {
		$html = mysite_render_fragment( $p );
		set_transient( $key, $html, 12 * HOUR_IN_SECONDS ); // TTL is only a safety net.
	}
	return $html;
}
```

Details that matter:

- The TTL (12 hours above) is a safety net for forgotten cases. The version bump
  is what makes edits appear **instantly**.
- Add every hook that changes the output. If a fragment shows custom fields, hook
  the field plugin's save action too (for ACF, `acf/save_post`).
- Do not cache an empty or error response (check the length before
  `set_transient`), or you will serve a blank panel for 12 hours.
- Serve the cache only to logged-out visitors, so editors see their own drafts
  and previews.
- Expose a hit/miss header (`X-MySite-Fragment: HIT`) while developing. It makes
  the cache testable with `curl -I`.

## 5. Browser side: sessionStorage, de-duplication, prefetch, race guard

The server cache makes the response cheap. The browser can make it free.

- **sessionStorage cache keyed with the same content version.** Print the
  version into the page, include it in the storage key, and delete keys from
  older versions on load. When an editor publishes, the version changes and every
  browser asks again. No stale panels.
- **De-duplicate in-flight requests.** If a hover prefetch and a click ask for
  the same URL at the same moment, share one promise.
- **Prefetch the next page** after the current one renders, and **warm the
  images** it contains (`new Image().src = ...`), so "next" feels instant.
- **Guard against races with a request token.** If the user switches category
  while page 1 of the previous category is still loading, the late response must
  not paint over the new one.

```js
// Printed in <head>: window.MYSITE_VER = <?php echo (int) mysite_content_version(); ?>;
const PREFIX = 'mysite_frag_v' + window.MYSITE_VER + '::';
const inFlight = {};
let token = 0;

// Drop entries written by older content versions.
try {
  for (let i = sessionStorage.length - 1; i >= 0; i--) {
    const k = sessionStorage.key(i);
    if (k && k.startsWith('mysite_frag_v') && !k.startsWith(PREFIX)) sessionStorage.removeItem(k);
  }
} catch (e) {}

function fetchFragment(url) {
  const key = PREFIX + url;
  try {
    const hit = sessionStorage.getItem(key);
    if (hit !== null) return Promise.resolve(hit);
  } catch (e) {}
  if (inFlight[url]) return inFlight[url];

  inFlight[url] = fetch(url, { credentials: 'same-origin' })
    .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
    .then((html) => {
      try { sessionStorage.setItem(key, html); } catch (e) {}
      return html;
    })
    .finally(() => { delete inFlight[url]; });
  return inFlight[url];
}

function prefetch(url) {
  fetchFragment(url).then((html) => {
    for (const m of html.matchAll(/<img\b[^>]*?\bsrc=["']([^"']+)["']/gi)) new Image().src = m[1];
  }).catch(() => {});
}

function load(tag, render) {
  const mine = ++token;                    // the newest call wins
  fetchFragment(urlFor(tag, 1)).then((html) => {
    if (mine !== token) return;            // a newer load started: ignore this answer
    render(html);
    prefetch(urlFor(tag, 2));
  });
}
```

Wrap every `sessionStorage` call in `try/catch`: it throws in private windows
and when storage is full, and the feature must still work without it.

## 6. Images

Images are usually most of the page weight.

**Serve WebP/AVIF by `Accept` negotiation.** A converter plugin writes a
`.webp`/`.avif` file next to (or mirroring) each upload; the server hands it out
when the browser says it can read it. The URL in the HTML stays `.jpg`, so
nothing in your content changes:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTP_ACCEPT} image/webp
  RewriteCond %{REQUEST_FILENAME} -f
  RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads-webp/$1.webp -f
  RewriteRule ^wp-content/uploads/(.+)\.(jpe?g|png)$ /wp-content/uploads-webp/$1.webp [T=image/webp,L]
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "(?i)\.(jpe?g|png|webp|avif)$">
    Header append Vary "Accept"
  </FilesMatch>
</IfModule>
```

`Vary: Accept` is not optional. Without it a CDN or proxy can cache the WebP
and send it to a browser that cannot display it (or the reverse). Add an
`image/avif` block above the WebP one if your converter makes AVIF; the first
matching rule wins.

**Lazy loading.** Add `loading="lazy"` and `decoding="async"` to images below the
fold, and **do not add it to the first few images**: delaying the image that is
your Largest Contentful Paint makes LCP worse. Always set `width` and `height`
so the browser reserves the space (no layout shift).

WordPress does all of this for images that go through its API
(`wp_get_attachment_image()`, post content). It does nothing for `<img>` tags
written by hand in theme templates. For those, either fix the templates or add a
small output filter that patches the tags, skipping the first three:

```php
add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || wp_doing_ajax() ) {
		return;
	}
	ob_start( function ( $html ) {
		$n = 0;
		return preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) use ( &$n ) {
			++$n;
			$tag = $m[0];
			if ( $n > 3 && false === stripos( $tag, 'loading=' ) ) {
				$tag = preg_replace( '/<img\b/i', '<img loading="lazy" decoding="async"', $tag, 1 );
			}
			return $tag;
		}, $html );
	} );
}, 1 );
```

Run the filter before the page-cache plugin writes its file, so the result is
what gets cached. Skip it for AJAX fragments (they are usually the next slide the
user is about to see), and test pages where an image-heavy template is the first
thing on screen.

## Checklist

- [ ] Enqueued assets use a content-hash version and `null` for WordPress's own
- [ ] One `inc/assets.php` with a comment per asset
- [ ] Static files have a long `Expires`; compression is on
- [ ] AJAX HTML endpoints return one validated page, with a prefixed query var
- [ ] Fragment cache key = params + content version; version bumped on every
      write hook that matters
- [ ] Client caches by version, shares in-flight requests, prefetches, guards races
- [ ] WebP/AVIF negotiation with `Vary: Accept`; below-the-fold images lazy,
      first ones not
- [ ] Numbers measured before and after
