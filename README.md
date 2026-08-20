# wordpress-widgets

Custom WordPress sidebar widgets.

## Widgets

### [Audible Currently Reading](audible-currently-reading/)

A classic sidebar widget that shows the audiobook you're currently listening to.
Paste an Audible product page URL into the widget settings and it fetches the
cover image, title, author(s), and series info automatically on save.

Install: copy the `audible-currently-reading` folder into your site's
`wp-content/plugins/` directory, then activate **Audible Currently Reading
Widget** under Plugins in wp-admin. Add it from Appearance → Widgets (it also
works as a Legacy Widget block).

Manual override fields are available in case the automatic fetch ever breaks
(Audible could change their page markup at any time — this isn't an official
API, just parsing of publicly embedded page data).
