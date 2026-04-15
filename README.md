# Thready Product Customizer

WooCommerce plugin for print-on-demand product customization. Merges embroidery/print design PNGs onto base product images, with a canvas-based live preview system (in progress).

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- GD extension (for legacy image generation)
- WP-CLI (for bulk operations)

## Attributes Used

| Taxonomy | Purpose |
|---|---|
| `pa_tip` | Product type (hoodie, t-shirt, etc.) |
| `pa_boja` | Color — hex stored via Variation Swatches plugin |
| `pa_velicina` | Size |

## Architecture

### Current System (Legacy)
Server-side image merging via GD on variation save. One generated WebP/PNG per variation. Managed by `class-admin-settings.php` and `class-image-handler.php`.

### New System (Canvas — in progress)
- **Mockup Library** (`class-mockup-library.php`) — master database of blank product images per `pa_tip × pa_boja`
- **Variation Factory** (`class-variation-factory.php`) — bulk variation creation engine, ~10× faster than WC admin
- **Product Wizard** — multi-step product creation UI *(next)*
- **Live Preview** — canvas-based frontend compositing *(upcoming)*
- **Migration Engine** — per-product legacy → canvas migration *(upcoming)*
- **Tools Page** — admin UI for bulk operations with live progress *(upcoming)*

Both systems run in parallel. Products are flagged with `_thready_render_mode = canvas | legacy`.

## Commit Convention

```
feat: add Foo Bar (#N)     — new feature
fix: correct X in Y        — bug fix
refactor: simplify Z       — no behaviour change
chore: update readme       — housekeeping
```

## Development

```bash
# Install to local WP
cp -r thready-product-customizer /path/to/wp-content/plugins/

# Create DB table without reactivating
wp eval 'Thready_Mockup_Library::create_table();'

# Run variation factory test
wp eval 'var_dump( Thready_Variation_Factory::get_summary( 123 ) );'
```
