# wordpress-widgets

Custom WordPress sidebar widgets.

## Widgets

### [Audible Currently Reading](audible-currently-reading/)

A classic sidebar widget that shows the audiobook you're currently listening to.
Type a book title (and optionally the author) into the widget settings and it
looks up the cover image, title, and author automatically on save, via Apple's
public iTunes Search API. A separate, optional "Link URL" field lets you point
the cover/title at your Audible page — that field is only ever used as a link,
never fetched.

Install: copy the `audible-currently-reading` folder into your site's
`wp-content/plugins/` directory, then activate **Audible Currently Reading
Widget** under Plugins in wp-admin. Add it from Appearance → Widgets (it also
works as a Legacy Widget block).

Manual override fields (Book Title, Author, Series, Cover Image URL) are
always available and are what actually gets displayed — the lookup just
pre-fills them.

**Why Apple's API instead of scraping Audible:** Audible's product pages
block a large share of WordPress hosting IPs outright — bot protection keyed
on server IP reputation, unrelated to the URL, the book, or whether you're
logged into Audible. Some hosts get a clean response every time; others get
an `HTTP 503` on every attempt because their host's shared IP range is
flagged, and no amount of retrying fixes that. Apple's Search API is public,
keyless, and has no such blocking, so lookups are keyed off a typed title
instead of a scraped Audible URL.

**Search tips:** it's a full-text search, not an exact catalog lookup — type
the title plain, exactly as printed on the cover, and add the author to
disambiguate. Padding the search with extra words (series name, subtitle)
can pull back the wrong edition or the wrong book entirely. Always check the
looked-up Book Title field after saving; if it's wrong, either narrow the
search terms and re-run it, or just type the correct details into the
manual fields directly. Series is not returned by this API at all — enter
it by hand if you want it shown.

### [Local Weather](local-weather/)

A sidebar widget showing current conditions — temperature, condition icon,
"feels like", and location — for a place you type into the widget settings.
Powered by [Open-Meteo](https://open-meteo.com/), a free, keyless weather
and geocoding API.

Install: copy the `local-weather` folder into `wp-content/plugins/`, activate
**Local Weather Widget**, and add it from Appearance → Widgets.

Type a location (e.g. `Portland, OR` — include a state/country if the city
name is common) and it's geocoded to coordinates on save, same pattern as the
Audible widget's title search. Unlike a book cover, weather actually needs to
stay current, so the frontend fetches live conditions on a 15-minute cache
(`get_transient`/`set_transient`) rather than only refreshing when you edit
the widget. If a live fetch ever fails, it falls back to the last
successfully-fetched reading rather than showing nothing.
