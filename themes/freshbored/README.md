# freshbored

Default frontend theme for bulletinbored. Bootstrap 5 based, sidebar driven discussion layout.

## socialbored identity

When the `socialbored` plugin adds `social-mode` to `<html>`, freshbored switches its design
tokens (purple palette, larger radii, navbar glass effect and page background) to the social
identity. The plugin still owns the component styles for the feed, cards, composer and
profiles; freshbored only supplies the visual identity, so those components also look coherent
with any theme that maps the `--fb-*` tokens.
