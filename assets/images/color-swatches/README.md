# Color lens swatches (Air Optix Colors)

Round product-page pastilles (style Opticone / Alwaleed eye close-ups).

## Files

| Catalog color name | Generated swatch | Source photo (Alwaleed CDN) |
|--------------------|------------------|-----------------------------|
| Gray | `swatch-gray.png` | `source-gray.png` |
| Sterling Gray | `swatch-sterling-gray.png` | `source-sterling-gray.png` |
| Honey | `swatch-honey.png` | `source-honey.png` |
| Brown | `swatch-brown.png` | `source-brown.png` |
| Blue | `swatch-blue.png` | `source-blue.png` |
| Brilliant Blue | `swatch-brilliant-blue.png` | `source-brilliant-blue.png` |
| Green | `swatch-green.png` | `source-green.png` |
| Gemstone Green | `swatch-gemstone-green.png` | `source-gemstone-green.png` |
| Pure Hazel | `swatch-pure-hazel.png` | `source-pure-hazel.png` |

Prefer **`source-*.png`** if you want the exact photos already used on [alwaleedoptics.com](https://alwaleedoptics.com/product/air-optics-color-lenses/) (same look as Opticone-style eye swatches). Use **`swatch-*.png`** for the newly generated set.

## How to attach in WordPress

1. **Médias → Ajouter** — upload the 9 PNGs you choose.
2. **Alwaleed Optics → Settings → Colors** — for each color row, set **swatch image** to the matching media.
3. Hard-refresh a multi-color product page; pastilles use `background-image` in a circle.

If a catalog color name differs slightly (e.g. `Grey` vs `Gray`), map by the closest shade.
