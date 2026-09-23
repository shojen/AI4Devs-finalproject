# Products & Media Routes

Part of [Routes](routes.md) — see [routes.md](routes.md#why-this-file-exists) for the full app-owned route table and the shared module-gate pattern. This file covers every Products-domain route and the two routeless, gated Products/Media shared components they embed.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [product-categories.index](products/product-categories.md) | the task touches the product categories screen. | `product-categories.index` |
| [products.index / .create / .edit](products/products-index-and-editor.md) | the task touches the products list or the product create/edit editor. | `products.index`, `products.create` and `products.edit` |
| [product-attribute-types.index](products/product-attribute-types.md) | the task touches the product attribute types screen. | `product-attribute-types.index` |
| [Media Gallery and WYSIWYG editor (routeless components)](products/routeless-components.md) | you embed the shared media gallery modal or the WYSIWYG editor in another screen. | `App\Livewire\Media\Gallery`; `App\Livewire\Components\WysiwygEditor` |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
