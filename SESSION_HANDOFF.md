# Session Handoff — Optic-Lenses (Alwaleed Optics Products)

**Date :** 2026-08-22 (dernière mise à jour)  
**Plugin :** `wp-content/plugins/Optic-Lenses`  
**Version déclarée :** 1.3.0 (`woocommerce-optic-product.php`, `composer.json`, `CHANGELOG.md`)  
**Thème cible boutique :** Flatsome (parent ou enfant)

Ce document résume tout le travail réalisé sur le plugin (sessions Cursor cumulées), pour permettre à un autre développeur (ou une future session IA) de reprendre sans perte de contexte.

> **Convention :** à chaque prompt / livraison significative, mettre à jour ce fichier (`SESSION_HANDOFF.md`). Lors d’un bump de version, synchroniser aussi `CHANGELOG.md` et `woocommerce-optic-product.php` / `composer.json`.

---

## 1. Résumé exécutif

### Session 2026-08-22 (courante)

1. **Génération des internes par plage + pas** — identité catalogue une seule fois ; pour chaque puissance de la division : De / À / Pas (step). Valeurs manquantes créées dans le catalogue.
2. **Gabarits de plages** — option `wc_optic_power_templates`.
3. **Assistant Convert** — menu **Alwaleed Optics → Convert** : simples → `optic_product` + internes, lots AJAX.
4. **Fiche produit** — bouton Generate internals (AJAX persist + reload) ; selects identité retirés des blocs enfants.
5. **Version** — bump **1.3.0**.

### Session 2026-08-19 (précédente)

1. **Fix fatal activation** — `Class "WC_Optic_Catalog" not found` dans `class-wc-optic-divisions.php:172` : l’autoloader est enregistré dès le chargement du fichier principal, pas seulement sur `plugins_loaded`.
2. **`WC_Optic_Autoload::register()` idempotent** — évite un double enregistrement SPL.
3. **Fallback puissances** — `WC_Optic_Divisions::get_available_powers()` retourne SPH/CYL/AXIS/ADD si le catalogue n’est pas chargé.
4. **Version** — bump **1.2.5**.

### Session 2026-06-11 (précédente)

1. **Suivi inventaire — page Stock** — sous-menu **Alwaleed Optics → Stock** avec 2 onglets : gestion hiérarchique (parent + internes repliables) et alertes stock bas.
2. **Restock AJAX** — bouton Restock par interne → popup quantité → incrémente `stock_qty` via `WC_Optic_Stock::restock_child()` ; option **Reset backorder** (case décochée par défaut) remet `backorder_consumed` à 0 (libellé Global vs Custom selon l’interne).
3. **Badge low stock parent** — chaque ligne parent affiche **« N low stock »** (rouge) à côté du compteur de variantes, visible **replié ou déplié** ; mis à jour après restock via JS.
4. **Alertes stock** — seuil global configurable dans Settings (`wc_optic_stock_alert_qty`, défaut 5) ; override **Custom threshold** par interne ; bulle compteur sur menu principal et sous-menu Stock.
5. **QR alertes** — onglet alertes affiche le QR code du SKU interne (via `WC_Optic_QR`).
6. **Settings UI** — panneaux **Backorder** et **Stock alerts** côte à côte (2 colonnes).
7. **Chargement JS Stock** — scripts injectés via `admin_print_footer_scripts` (prio 20) pour fiabiliser chevrons, recherche, expand/collapse et restock.

### Session 2026-06-10 (précédente)

1. **Backorder** — paramétrage global (Settings) + override par produit interne ; stock vendable = stock physique + allowance backorder − consommé − panier.
2. **Menu admin WordPress** — menu principal **Alwaleed Optics** (sous Dashboard) ; migration **Settings** et **Import** hors du menu WooCommerce.
3. **Suppression Child selector UI** — option globale radio/dropdown retirée ; le client choisit les puissances via les sélecteurs en cascade (`render_power_selectors`).
4. **Règle Cursor** — `.cursor/rules/session-handoff.mdc` pour mise à jour automatique du handoff.
5. **UI backorder admin** — panneau Settings + carte produit interne (toggle pill, badge Global/Custom, styles `admin.css`).

### Session 2026-06-09 (précédente)

1. **Lentilles couleur** — choix **No power / Power** (défaut : No power).
2. **Prix unique** — fin des fourchettes min–max ; prix basé sur la **sélection par défaut** (option B).
3. **Panier / checkout** — total par section œil + habillage **Flatsome moderne**.
4. **Règles métier affinées** — « sans puissance » = SPH **+0.00** ; autres divisions = **prix le plus bas**.
5. **UI fiche produit** — toggle No power/Power style **Eyewa** (pill) ; division et bloc prix formulaire **masqués** (v1.2.0).
6. **Version** — bump **1.2.3** (qty centrées, labels grille).

Aucun commit git n’a été demandé ni créé pendant ces sessions.

---

## 2. Fonctionnalités livrées

### 2.1 Lentilles couleur — No power / Power

**Division concernée :** `color_lenses` uniquement (`WC_Optic_SKU::division_supports_no_power_mode()`).

| Mode | Comportement client |
|------|---------------------|
| **No power** (défaut) | Radio sélectionné à l’ouverture. Masque prescription SPH et « 2 puissances différentes ». Quantité seule. Résout automatiquement le produit interne **+0.00**. |
| **Power** | Affiche sélecteur SPH (+ quantité, option bi-œil si plusieurs enfants avec puissances). Exclut +0.00 de la cascade JS. |

**Définition « No power » côté données :**  
Ce n’est **pas** un SPH vide. Un produit interne est « no power » si son terme catalogue SPH est reconnu comme **plano / +0.00** :

- `WC_Optic_Catalog::sph_term_is_zero_power( $row )`
- `WC_Optic_Catalog::sph_value_is_zero_power( $value )` — reconnaît `+0.00`, `0.00`, `0`, `plano`, etc.
- Filtre WP : `wc_optic_sph_is_zero_power`

**Admin :** chaque variante no-power doit avoir un SPH **+0.00** explicite dans le catalogue (prix + stock obligatoires comme les autres enfants).

**UI toggle (style [Eyewa](https://eyewa.com/ae-en/diva-color-contact-lenses-pack-of-2.html)) :**

- Conteneur `.wc-optic-power-mode` : fond gris `#f4f4f5`, hauteur 53px, `border-radius: 60px`, padding 4px.
- Onglets `.wc-optic-power-mode__tab` : `flex: 1`, transition 350ms.
- Actif (`input:checked + label`) : fond blanc, texte `#111827`, `font-weight: 400`.
- Inactif : texte `#6b7280`, `font-weight: 600`.
- `data-testid="tab_no_power"` / `tab_power` ; IDs `#wc_optic_tab_no_power`, `#wc_optic_tab_power`.
- Pas de libellé visible « Power type » (legend / `aria-label` uniquement).

**Fichiers clés :**

- `templates/single-product/add-to-cart/optic_product.php` — structure pill `input` + `label`
- `assets/css/frontend.css` — styles `.wc-optic-power-mode*`
- `assets/js/frontend.js` — `togglePowerMode()`, `isNoPowerMode()`, `syncNoPowerChildFields()`
- `includes/class-wc-optic-cart.php` — parse `wc_optic_power_mode`, payload `power_mode`
- `includes/class-wc-optic-sku.php` — `child_is_no_power()`, `find_no_power_child()`, matrice `noPowerChild` / `children` séparés

---

### 2.5 Fiche produit — éléments masqués (v1.2.0)

| Élément | Statut |
|---------|--------|
| `.wc-optic-division` | **Supprimé** du template (plus d’affichage « Optical division »). |
| `.wc-optic-pricing` visible | **Supprimé** — bloc conservé en `hidden` pour sync JS vers le prix WooCommerce/Flatsome (`.summary > .price`). |

Le client voit le prix uniquement via le bloc prix standard du thème ; le formulaire optique ne duplique plus prix ni division.

---

### 2.6 Fiche produit — ajustements CSS (v1.2.3)

| Élément | Changement |
|---------|------------|
| `#wc_optic_qty`, `#wc_optic_qty_left`, `#wc_optic_qty_right` | `text-align: center` |
| `.wc-optic-config-table__label` | `padding-top` retiré (règle commentée dans `frontend.css`) |

Fichier : `assets/css/frontend.css`.

---

### 2.2 Affichage prix — option B (prix unique)

**Avant :** fourchette `min – max` via `format_price_range_html()` / `wc_format_price_range()`.

**Après :** un seul prix via `WC_Optic_Pricing::format_display_price_html()`.

| Contexte | Règle |
|----------|--------|
| **Lentilles couleur** | Prix du produit interne **No power** (+0.00) |
| **Autres divisions** | Prix du produit interne **le plus bas** (parmi enfants actifs et complets) |
| **Fiche produit — sélection validée** | Prix exact de la configuration (JS) |
| **Mode Power sans SPH choisi** | Prix unitaire vide jusqu’à sélection complète |
| **Listes / grilles / `get_price_html`** | Prix par défaut (même règle) |

**Méthodes centrales :**

```text
WC_Optic_SKU::get_default_display_child( $product )
WC_Optic_SKU::get_default_display_price( $product )
WC_Optic_Pricing::format_display_price_html( $product )
WC_Optic_Pricing::get_unit_price( $product )  → utilise le prix par défaut
```

**Sauvegarde admin :** `WC_Optic_SKU::persist_child_data()` synchronise le prix parent WooCommerce sur `get_default_display_price()` (plus le minimum global).

**JS fiche produit :** `wcOpticFront.defaultPrice` / `defaultPriceHtml` (remplace `priceRange` / `priceRangeHtml`).

**Alias déprécié :** `format_price_range_html()` délègue à `format_display_price_html()`.

> **Note post-session :** resauvegarder les fiches produits existantes si le prix parent WooCommerce doit être resynchronisé.

---

### 2.3 Panier / checkout — total par section œil

Dans le résumé HTML client (`WC_Optic_Cart::render_line_eye_column()`), chaque colonne OS / OD (ou bloc « même puissance ») affiche en bas :

- **Total** = prix unitaire × quantité de la section
- Classe CSS : `wc-optic-line-summary__meta-row--total`

`get_eye_admin_summary()` retourne désormais `line_total`.

---

### 2.4 Flatsome — panier & paiement modernes

**Nouveau module :** `includes/class-wc-optic-flatsome.php`  
Enregistré dans `class-wc-optic-plugin.php` et `class-wc-optic-autoload.php`.

**Activation :** détection thème dont le slug contient `flatsome` (parent ou enfant).

**Body classes :**

- `wc-optic-flatsome-cart` — page panier
- `wc-optic-flatsome-checkout` — page paiement

**Assets :** `assets/css/flatsome-cart-checkout.css` (+ `frontend.css`, `cart.js` sur panier).

**Effets visuels :**

- Lignes panier en cartes (coins arrondis, ombre)
- Totaux panier en carte sticky (desktop)
- Formulaire checkout en sections cartes
- Moyens de paiement en cartes
- Boutons arrondis pleine largeur
- Quantités optiques restructurées (`wc-optic-cart-qty__field`)

**Délégation assets :** `WC_Optic_Cart::enqueue_cart_scripts()` ne charge rien si Flatsome actif (évite doublon ; Flatsome module gère tout).

---

### 2.7 Backorder — achat au-delà du stock (session 2026-06-10)

**Objectif :** autoriser la vente au-delà du stock physique, avec une allowance configurable globalement et optionnellement par produit interne.

#### Settings globaux (`Alwaleed Optics → Settings`)

| Option | Clé WP | Comportement |
|--------|--------|--------------|
| **Allow backorder** | `wc_optic_backorder_enabled` (`yes`/`no`) | Active le backorder sur toute la boutique |
| **Backorder quantity** | `wc_optic_backorder_qty` | Unités **supplémentaires** vendables par produit interne (ex. stock 5 + backorder 5 → **max 10**) |

UI : case à cocher + champ numérique (masqué si backorder désactivé). JS : `assets/js/admin-settings.js`.

#### Fiche produit — produit interne

Sous **Stock quantity** :

| Élément | Rôle |
|---------|------|
| **Backorder allowed** | Champ `disabled` — valeur effective affichée |
| **Custom** | Case à cocher : override du backorder global pour cet interne |
| Champ qty custom | Éditable si Custom coché ; sinon règle globale |
| Note consommé | Affiche `N backorder unit(s) already sold` si `backorder_consumed > 0` |

Méta enfant : `backorder_custom`, `backorder_qty`, `backorder_consumed` (dans `_optic_child_configs`).

#### Formule stock disponible

```text
sellable = stock_physique + backorder_allowance − backorder_consumed
remaining = sellable − quantité_réservée_panier
```

Exemple : stock 5, backorder 5, panier 3 → **7** disponibles.

#### Méthodes PHP centrales

```text
WC_Optic_SKU::is_backorder_enabled()
WC_Optic_SKU::get_global_backorder_qty()
WC_Optic_SKU::get_child_backorder_qty( $config )
WC_Optic_SKU::get_child_sellable_qty( $config )
WC_Optic_SKU::apply_child_stock_delta( &$config, $delta )  // commande : stock d’abord, puis backorder
WC_Optic_SKU::preserve_child_backorder_consumed( $product, $children )  // sauvegarde admin
WC_Optic_Cart::get_remaining_child_stock( $product, $config )  // utilise sellable qty
```

**Boutique :** matrice JS (`stock`, `inStock`) et validation panier/checkout utilisent `get_remaining_child_stock()` — pas de changement JS frontend dédié requis.

**Fichiers :** `class-wc-optic-sku.php`, `class-wc-optic-cart.php`, `class-wc-optic-admin-settings.php`, `class-wc-optic-admin-product.php`, `admin-settings.js`, `admin-product.js`, `admin.css`.

---

### 2.8 Menu admin — Alwaleed Optics (session 2026-06-10, Stock 2026-06-11)

**Avant :** Settings et Import étaient des sous-menus **WooCommerce**.

**Après :** menu principal WordPress :

```text
Dashboard
Alwaleed Optics          ← position 3, dashicons-visibility (+ bulle alertes si stock bas)
  ├── Settings           page=wc-optic-settings
  ├── Stock              page=wc-optic-stock
  ├── Convert            page=wc-optic-convert
  └── Import             page=wc-optic-import
```

**Classe :** `includes/admin/class-wc-optic-admin-menu.php` (`WC_Optic_Admin_Menu`).

| Constante | Valeur | Usage |
|-----------|--------|--------|
| `PARENT_SLUG` | `wc-optic-settings` | Slug menu parent |
| `MENU_POSITION` | `3` | Juste sous Dashboard |
| `SETTINGS_SCREEN` | `toplevel_page_wc-optic-settings` | Hook `admin_enqueue_scripts` Settings |
| `STOCK_SCREEN` | `wc-optic-settings_page_wc-optic-stock` | Hook `admin_enqueue_scripts` Stock |
| `IMPORT_SCREEN` | `wc-optic-settings_page_wc-optic-import` | Hook `admin_enqueue_scripts` Import |

Les URLs admin (`admin.php?page=wc-optic-settings` / `wc-optic-import`) sont **inchangées** — liens internes et favoris restent valides.

Enregistrement : `WC_Optic_Admin_Menu::hooks()` dans `class-wc-optic-plugin.php` ; map autoload dans `class-wc-optic-autoload.php`.

---

### 2.9 Suppression Child selector UI (session 2026-06-10)

**Contexte :** l’ancien réglage global **Child selector UI** (radio vs dropdown pour choisir un produit interne d’un bloc) n’est plus pertinent : la boutique utilise uniquement les **sélecteurs de puissance en cascade** par œil (`WC_Optic_Frontend::render_power_selectors()` + résolution JS via `wcOpticFront.matrix`).

**Retiré :**

| Élément | Détail |
|---------|--------|
| Settings admin | Champ `<select>` Child selector UI |
| Options WP | `wc_optic_selector_ui` (orpheline, plus lue) |
| Méta produit | `_optic_selector_ui` — supprimée à la sauvegarde via `persist_child_data()` |
| PHP | `get_selector_ui_options()`, `get_selector_ui()`, `set_selector_ui()`, constantes associées |
| Code mort | `render_child_stock_badge()`, `render_child_choice_powers()` dans `class-wc-optic-frontend.php` |
| CSS | `.wc-optic-child-selector`, `.wc-optic-child-choice*` dans `frontend.css` |
| WPML | Entrées `wc_optic_selector_ui` et `_optic_selector_ui` dans `wpml-config.xml` / `class-wc-optic-wpml.php` |

**Conservé :** sélection par puissances (SPH, CYL, etc.) — inchangée.

---

### 2.10 Suivi inventaire — Stock (session 2026-06-11)

**Objectif :** page admin centralisée pour consulter le stock physique des produits internes, réapprovisionner, et lister les alertes.

#### Settings globaux (`Alwaleed Optics → Settings`)

| Option | Clé WP | Comportement |
|--------|--------|--------------|
| **Enable stock alerts** | `wc_optic_stock_alert_enabled` (`yes`/`no`) | Active les alertes stock (défaut : oui) |
| **Alert threshold** | `wc_optic_stock_alert_qty` | Seuil global sur le **stock physique** (défaut : **5**) |

Un produit interne est en alerte si alertes activées et `stock_qty ≤ seuil effectif`.

**Override par interne (fiche produit) :** case **Custom threshold** + champ numérique — même UI que backorder (`alert_custom`, `alert_qty` dans `_optic_child_configs`).

#### Page Stock (`Alwaleed Optics → Stock`)

| Onglet | URL | Contenu |
|--------|-----|---------|
| **Stock management** | `tab=management` (défaut) | Table hiérarchique : lignes parent (nom, SKU, **N variants**, badge **N low stock**) repliables via chevron ; internes : puissance, SKU, stock actuel (+ badge Low), backorder units (+ « N sold »), Custom backorder, seuil alerte, Custom alert, prix, Restock |
| **Stock alerts** | `tab=alerts` | DataTables : QR code SKU interne, SKU, puissance, produit parent, quantité actuelle, Restock |

**Ligne parent — compteur low stock :** `get_inventory_tree()` calcule `low_count` par produit parent. Badge `.wc-optic-stock-parent__low-count` affiché si `low_count > 0` (replié ou déplié). `admin-stock.js` recalcule après restock (`updateParentLowCount()`).

**Restock :** modal JS (`#wc-optic-restock-modal`) → AJAX `wc_optic_restock_child` → `WC_Optic_Stock::restock_child( $product_id, $child_id, $qty, $reset_backorder )` :

- Ajoute la quantité au `stock_qty` et sauvegarde via `WC_Optic_SKU::persist_child_data()`.
- Si `reset_backorder` coché : `backorder_consumed = '0'` (ne modifie pas `backorder_custom` / `backorder_qty`).
- Case visible si backorder global activé et `backorder_units > 0` sur l’interne ; libellé **Reset global/custom backorder allowance (N unit(s) consumed)**.
- Lignes internes et alertes portent `data-backorder-custom`, `data-backorder-consumed`, `data-backorder-units` pour le modal.

**UI Stock management :** tableau hiérarchique repliable (chevron / clic ligne parent) + recherche + Expand/Collapse all ; JS vanilla sur `#wc-optic-stock-root` + jQuery pour AJAX/DataTables. **Stock alerts** : DataTables.net uniquement sur cet onglet (`assets/vendor/datatables/*`).

**Chargement assets :** `WC_Optic_Admin_Stock::enqueue_assets()` (styles) + `print_scripts()` en footer injecte `wcOpticStock` (JSON) puis `admin-stock.js` (cache-bust `filemtime`).

**Badge menu :** `WC_Optic_Admin_Menu::add_alert_badges()` affiche le nombre d’alertes sur le menu **Alwaleed Optics** et le sous-menu **Stock** (style WordPress `awaiting-mod`).

#### Méthodes PHP centrales

```text
WC_Optic_Stock::is_alert_enabled()
WC_Optic_Stock::get_alert_qty()
WC_Optic_Stock::get_child_alert_qty( $config )
WC_Optic_Stock::child_is_low_stock( $config )
WC_Optic_Stock::get_inventory_tree()   // inclut low_count par parent
WC_Optic_Stock::format_child_row()
WC_Optic_Stock::get_alerts()
WC_Optic_Stock::get_alert_count()
WC_Optic_Stock::restock_child( $product_id, $child_id, $qty, $reset_backorder = false )
// Retour restock : stock, is_low, alert_count, backorder_consumed, backorder_reset
```

**Fichiers :** `class-wc-optic-stock.php`, `class-wc-optic-admin-stock.php`, `admin-stock.js`, `admin.css`, `class-wc-optic-ajax.php`, `class-wc-optic-admin-menu.php`, `class-wc-optic-admin-settings.php`.

### 2.12 Génération des internes — plage + pas (session 2026-08-22)

**Modèle inchangé :** parent `optic_product` + internes `_optic_child_configs` (pas de variantes WooCommerce).

**Flux (ex. Amara One Day Amber, Color lenses = SPH) :**

1. Identité une fois : section, company, brand, timing, color, pack, transparency (`_optic_identity_catalog`).
2. Plage SPH : De / À / **Pas** (step, ex. −8.00 → −6.00 / 0.25).
3. Generate : crée les termes catalogue manquants puis les internes (même `normalize_child_config` que le bouton +).

**Méthodes :**

```text
WC_Optic_Catalog::format_power_value()
WC_Optic_Catalog::get_or_create_power_term()
WC_Optic_Catalog::resolve_power_range()
WC_Optic_SKU::build_children_from_ranges()
WC_Optic_SKU::apply_identity_to_children()
WC_Optic_Power_Template::save() / get_all()
WC_Optic_Converter::convert_product() / preview()
```

**Admin :** `Alwaleed Optics → Convert` (onglets Convert + Range templates). Fiche produit : identité + plages + Generate internals.

**Plafond :** `MAX_LEGACY_SYNTHETIC_CHILDREN` (200) par produit.

**Fichiers :** `class-wc-optic-catalog.php`, `class-wc-optic-sku.php`, `class-wc-optic-power-template.php`, `class-wc-optic-converter.php`, `admin/class-wc-optic-admin-convert.php`, `admin-product.php`, `ajax.php`, `admin-menu.php`, `admin-convert.js`, `admin-product.js`, `admin.css`.

### 2.11 Autoload à l’activation (session 2026-08-19)

- **Problème :** `register_activation_hook` s’exécute avant `plugins_loaded`. `maybe_seed_defaults()` → `get_default_divisions()` → `sanitize_powers()` → `get_available_powers()` → `WC_Optic_Catalog::get_power_types()` alors que l’autoloader n’était pas encore enregistré.
- **Correctif :** `WC_Optic_Autoload::register()` appelé immédiatement après `require` du fichier autoload dans `woocommerce-optic-product.php`.
- **Méthodes :** `WC_Optic_Autoload::register()`, `WC_Optic_Divisions::get_available_powers()`, `WC_Optic_Divisions::maybe_seed_defaults()`.
- **Fichiers :** `woocommerce-optic-product.php`, `includes/class-wc-optic-autoload.php`, `includes/class-wc-optic-divisions.php`.

---

## 3. Architecture & fichiers modifiés

### PHP — includes

| Fichier | Rôle |
|---------|------|
| `admin/class-wc-optic-admin-menu.php` | Menu principal Alwaleed Optics + badge alertes stock + Convert |
| `admin/class-wc-optic-admin-convert.php` | **Nouveau (1.3.0)** — gabarits de plages + conversion simples |
| `class-wc-optic-power-template.php` | **Nouveau** — option `wc_optic_power_templates` |
| `class-wc-optic-converter.php` | **Nouveau** — type simple → optic + génération internes |
| `admin/class-wc-optic-admin-stock.php` | **Nouveau (2026-06-11)** — page Stock (gestion + alertes) |
| `admin/class-wc-optic-admin-settings.php` | Settings globaux (backorder, seuil alerte stock) |
| `class-wc-optic-stock.php` | **Nouveau (2026-06-11)** — inventaire, alertes, restock |
| `class-wc-optic-ajax.php` | + `wc_optic_restock_child` |
| `admin/class-wc-optic-admin-import.php` | Import catalogue ; hook screen sous menu Alwaleed Optics |
| `admin/class-wc-optic-admin-product.php` | Champs backorder par produit interne |
| `class-wc-optic-catalog.php` | `sph_term_is_zero_power()`, `sph_value_is_zero_power()` |
| `class-wc-optic-sku.php` | No-power, prix défaut, **backorder**, matrice storefront, `persist_child_data` |
| `class-wc-optic-pricing.php` | `format_display_price_html()`, filtre `get_price_html` |
| `class-wc-optic-cart.php` | Panier, **stock sellable/backorder**, `apply_child_stock_delta` |
| `class-wc-optic-frontend.php` | Puissance en cascade, stock HTML ; code child-choice retiré |
| `class-wc-optic-flatsome.php` | Détection Flatsome + assets panier/checkout |
| `class-wc-optic-plugin.php` | `WC_Optic_Admin_Menu::hooks()`, Flatsome, etc. |
| `class-wc-optic-autoload.php` | Map classes ; `register()` immédiat + idempotent (fix 1.2.5) |
| `class-wc-optic-divisions.php` | Divisions ; `get_available_powers()` fallback si catalogue absent |
| `woocommerce-optic-product.php` | Bootstrap : autoload dès le chargement (plus de require manuel Database/Divisions à l’activation) |

### Templates

| Fichier | Changements |
|---------|---------------|
| `templates/single-product/add-to-cart/optic_product.php` | Pill No power/Power ; pas de `.wc-optic-division` ; `.wc-optic-pricing` **hidden** (sync JS) |

### Assets

| Fichier | Changements |
|---------|---------------|
| `assets/js/frontend.js` | Power mode, prix défaut, pas de range |
| `assets/js/cart.js` | Inchangé (sync qty) |
| `assets/js/admin-settings.js` | Toggle visibilité champ backorder qty global |
| `assets/js/admin-product.js` | Toggle Custom backorder, identité, plages, Generate internals |
| `assets/js/admin-convert.js` | **Nouveau** — gabarits + preview/run Convert |
| `assets/js/admin-stock.js` | Collapsible parent rows, recherche, expand/collapse, modal restock (+ reset backorder), badge low stock parent, DataTables (onglet alertes) |
| `assets/vendor/datatables/*` | DataTables.net + RowGroup (bundled) |
| `assets/css/frontend.css` | Pill Eyewa power mode, line-summary total, qty centrées, labels grille |
| `assets/css/admin.css` | Backorder admin, Settings 2 colonnes, **stock tables + modal restock + badge low stock**, `.wc-optic-is-hidden` |
| `assets/css/flatsome-cart-checkout.css` | Styles panier/checkout Flatsome |

---

## 4. Flux données — ajout au panier (lentilles couleur)

```text
POST wc_optic_power_mode = no_power | power
POST wc_optic_qty / wc_optic_qty_left / wc_optic_qty_right
POST wc_optic_{left|right}_sph (si mode Power)
POST wc_optic_different_power (si applicable)

→ WC_Optic_Cart::parse_request()
  → power_mode dans payload
  → no_power : find_no_power_child() + build_eye_payload_from_child()
  → power   : parse_eye_child() classique

→ Payload stocké sous clé _wc_optic (CART_KEY)
```

**Payload champs notables :**

```php
[
  'power_mode' => 'no_power' | 'power',
  'same_power' => bool,
  'qty_mode'   => 'single' | 'dual',
  'left'       => [ child_id, unit_price, powers[], ... ],
  'right'      => ...,
  'line_qty'   => int,
  'line_total' => float,
]
```

---

## 5. Matrice storefront JS (`wcOpticFront.matrix`)

Produite par `WC_Optic_SKU::get_storefront_matrix()` :

```javascript
{
  division: 'color_lenses',
  supportsNoPowerMode: true,
  noPowerChild: { id, price, stock, inStock },  // +0.00
  powers: ['sph'],
  children: [ ... ],  // enfants AVEC puissance uniquement
  terms: { sph: { id: label } },
  labels: { sph: 'SPH' }
}
```

Le JS résout l’enfant via cascade SPH (`childrenMatching`, `resolveChildForEye`) ou directement `noPowerChild` en mode No power.

---

## 6. Configuration admin requise

### Produit type Optic — division Color lenses

1. Créer au moins un **produit interne No power** : SPH = **+0.00** (terme catalogue), autres champs catalogue remplis, prix + stock.
2. Créer les produits internes **avec puissance** (autres valeurs SPH) pour le mode Power.
3. Vérifier qu’aucune combinaison de puissances n’est dupliquée (`validate_unique_power_combinations`).

### Catalogue SPH

S’assurer qu’une entrée **+0.00** existe et est reconnaissable (`name`, `slug` ou `sku_fragment`). Sinon utiliser le filtre `wc_optic_sph_is_zero_power`.

### Autres divisions (toric, multifocal, etc.)

- Pas de toggle No power/Power.
- Prix affiché en boutique = **minimum** des produits internes actifs.
- Client doit sélectionner toutes les puissances de la division avant add-to-cart.

### Backorder

1. **Alwaleed Optics → Settings** : cocher **Allow backorder**, définir **Backorder quantity** (ex. 5).
2. Par défaut, tous les produits internes héritent de cette allowance.
3. Sur la fiche produit optique : cocher **Custom** sur un interne pour un backorder spécifique.
4. Vérifier en boutique : quantité max = stock + backorder (moins panier).

### Navigation admin

- **Alwaleed Optics → Settings** — catalogue, divisions, paramètres globaux (backorder, seuil alerte stock).
- **Alwaleed Optics → Stock** — inventaire hiérarchique, alertes, restock.
- **Alwaleed Optics → Convert** — gabarits de plages + conversion des produits simples.
- **Alwaleed Optics → Import** — import Excel/CSV par onglet catalogue.

---

## 7. i18n ajoutés (`wc-optic`)

| Clé / texte | Usage |
|-------------|--------|
| No power | Radio + résumé panier |
| Power | Radio |
| Power type | `aria-label` / legend (non visible à l’écran) |
| This product is not available without power. | Erreur no-power indisponible |
| Total | Total par section œil (panier) |
| Qty | Quantité panier (mode single) |
| Allow backorder | Settings globaux |
| Backorder quantity | Settings globaux |
| Backorder allowed | Produit interne (admin) |
| Custom | Override backorder par interne |
| N backorder unit(s) already sold | Note admin consommation backorder |
| Settings / Stock / Import | Sous-menus Alwaleed Optics |
| Stock management / Stock alerts | Onglets page Stock |
| Restock / Quantity to add / Add stock | Modal réapprovisionnement |
| Reset global/custom backorder allowance (N unit(s) consumed) | Case optionnelle modal restock |
| N low stock | Badge compteur sur ligne parent (onglet gestion) |
| Alert threshold / Custom alert | Colonnes tableau internes + override fiche produit |
| Alert threshold / Stock alerts | Settings + onglet alertes |
| Low | Badge stock bas (onglet gestion) |

Domaine : `wc-optic` — traduction WPML via String Translation si actif.

---

## 8. Tests manuels recommandés

### Lentilles couleur

- [ ] Ouverture fiche : **No power** sélectionné, pas de SPH, quantité seule, prix = +0.00
- [ ] Passage en **Power** : SPH visible, +0.00 absent des options
- [ ] Add-to-cart No power → panier : `Power type: No power`, pas de puissances listées
- [ ] Add-to-cart Power → panier : SPH affiché, total section correct
- [ ] Stock rupture no-power → bouton désactivé / message
- [ ] Toggle pill : onglet actif fond blanc, transition fluide, pleine largeur

### Fiche produit — UI

- [ ] Pas de ligne « Optical division »
- [ ] Pas de bloc prix dans le formulaire (prix thème uniquement)
- [ ] Prix thème se met à jour au changement No power / Power / SPH

### Prix

- [ ] Grille boutique : **un seul prix** (pas de `X – Y`)
- [ ] Color lenses : prix = no-power
- [ ] Toric / multifocal : prix = **plus bas** des internes
- [ ] Fiche : changement de sélection met à jour prix + total estimé
- [ ] Mode Power sans SPH : prix unitaire vide, total masqué

### Panier / checkout

- [ ] Total par colonne OS/OD (unitaire × qty)
- [ ] Flatsome : cartes panier, totaux sticky, checkout modernisé
- [ ] Quantités OS/OD optiques synchronisent `line_qty` WooCommerce

### Admin

- [ ] Sauvegarde produit : prix parent = prix par défaut (no-power ou min)
- [ ] Produit interne +0.00 sans doublon catalogue
- [ ] Menu **Alwaleed Optics** visible sous Dashboard (pas sous WooCommerce)
- [ ] Settings et Import accessibles depuis le nouveau menu

### Stock inventaire

- [ ] Menu **Stock** visible sous Alwaleed Optics
- [ ] Onglet gestion : chevron déplie les internes (puissance, SKU, stock, backorder, seuil alerte, prix)
- [ ] Ligne parent : badge **N low stock** visible replié et déplié si au moins un interne en alerte
- [ ] Restock : popup → ajout qty → stock mis à jour sans rechargement
- [ ] Restock : case **Reset backorder** (décochée par défaut) → si cochée, `backorder_consumed` remis à 0 ; libellé global vs custom
- [ ] Settings : seuil alerte (ex. 5) → internes ≤ 5 listés dans onglet alertes
- [ ] Bulle compteur sur menu Alwaleed Optics et sous-menu Stock
- [ ] Onglet alertes : QR code + SKU + quantité actuelle

### Backorder

- [ ] Settings : activer backorder + qty 5 → sauvegarde OK
- [ ] Interne stock 5 + backorder global 5 → fiche client max qty **10**
- [ ] 3 en panier → max qty **7** sur la fiche
- [ ] Custom sur un interne (ex. backorder 2) → seul cet interne utilise 2
- [ ] Commande qui dépasse le stock physique → `backorder_consumed` incrémenté, stock physique à 0
- [ ] Annulation commande → restauration stock + backorder_consumed

### Génération internes (1.3.0)

- [ ] Color lenses : identité 7 champs une fois ; SPH −8.00 → −6.00 pas 0.25 → 9 internes
- [ ] Settings → SPH contient les valeurs créées (ex. −7.25)
- [ ] Relancer Generate sans replace → skip ; avec replace → régénère
- [ ] +0.00 dans une plage → interne no-power
- [ ] Toric : 3 plages ; > 200 combinaisons → refus
- [ ] Convert : preview puis run sur 2–3 simples
- [ ] Changer la couleur identité + Update → SKU de tous les internes mis à jour
- [ ] Fiche boutique : cascade inchangée

### Activation plugin (1.2.5)

- [ ] Désactiver puis réactiver le plugin : pas d’erreur fatale `WC_Optic_Catalog not found`
- [ ] Après activation : option `wc_optic_divisions` créée (4 divisions par défaut)
- [ ] Site (front + admin) charge sans fatal ; Settings → Divisions affiche SPH/CYL/AXIS/ADD

---

## 9. Points d’attention / limites connues

1. **`find_no_power_child()`** retourne le **premier** enfant +0.00 trouvé — si plusieurs variantes no-power (packs différents), seul le premier est utilisé en mode No power.
2. **Flatsome** : styles basés sur la structure WooCommerce standard ; un override template Flatsome très custom peut nécessiter des ajustements CSS.
3. **CHANGELOG.md** mis à jour à chaque bump — dernière entrée **[1.3.0] — 2026-08-22**.
4. **Version plugin** : **1.3.0** (`woocommerce-optic-product.php`, `composer.json`). Convention : toujours synchroniser `CHANGELOG.md` + `SESSION_HANDOFF.md` lors d’un changement de version.
5. **`format_price_range_html()`** conservé en alias déprécié ; aucun appel interne ne produit plus de fourchette.
6. Thème Flatsome **non présent** dans le workspace local au moment du dev — tests visuels à faire sur l’environnement WAMP réel.
7. Couleurs du toggle Eyewa sont des **approximations** (#f4f4f5, #111827) — ajuster si charte Alwaleed différente.
8. **Backorder + menu admin + Stock (2026-06-10/11)** : livré en **1.2.4** (`CHANGELOG.md`).
9. **Backorder désactivé globalement** : champs Custom masqués en admin produit ; `get_child_backorder_qty()` retourne 0.
10. **`backorder_consumed`** est conservé à la sauvegarde produit via `preserve_child_backorder_consumed()` — ne pas supprimer le hidden field admin.
11. **Autoload** : ne plus reporter `WC_Optic_Autoload::register()` après `plugins_loaded` — l’activation (et tout code avant ce hook) en a besoin.

---

## 10. Pistes non traitées (hors scope session)

- Masquer l’option **Power** si aucun enfant avec puissance n’existe.
- Afficher « À partir de » au lieu du prix sec (débattu, non retenu).
- Commit git.
- Tests automatisés PHPUnit / E2E.
- Traductions WPML des nouvelles chaînes.
- Admin : indication visuelle « variante No power » sur les produits internes.
- Commit git.

---

## 11. Reprise rapide — commandes utiles

```bash
# Depuis la racine du plugin
cd wp-content/plugins/Optic-Lenses

# Vérifier syntaxe PHP
php -l woocommerce-optic-product.php
php -l includes/class-wc-optic-autoload.php
php -l includes/class-wc-optic-divisions.php
php -l includes/class-wc-optic-catalog.php
php -l includes/class-wc-optic-sku.php
php -l includes/class-wc-optic-cart.php
php -l includes/class-wc-optic-pricing.php
php -l includes/class-wc-optic-flatsome.php
php -l includes/admin/class-wc-optic-admin-menu.php
php -l includes/admin/class-wc-optic-admin-settings.php
```

**Fichiers à lire en premier pour reprendre :**

1. `includes/class-wc-optic-sku.php` — enfants, no-power, prix défaut, **backorder**
2. `includes/class-wc-optic-cart.php` — panier, **stock sellable**, commande
3. `includes/admin/class-wc-optic-admin-menu.php` — menu admin Alwaleed Optics
4. `includes/admin/class-wc-optic-admin-settings.php` — settings globaux + backorder
5. `assets/js/frontend.js` — UX fiche produit boutique
6. `includes/class-wc-optic-flatsome.php` + `assets/css/flatsome-cart-checkout.css` — habillage Flatsome

---

## 12. Historique décisions produit (session)

| Sujet | Décision |
|-------|----------|
| No power = SPH vide ? | **Non** — SPH catalogue **+0.00** |
| Prix boutique | **Option B** — prix sélection par défaut, pas de range |
| Autres divisions — prix défaut | **Prix le plus bas** (pas le premier enfant) |
| Total panier par œil | **Oui** — fin de chaque section `wc-optic-line-summary__eye` |
| Panier Flatsome | Module CSS dédié, cartes + sticky |
| Division sur fiche | **Masquée** (info admin uniquement) |
| Prix dans formulaire | **Masqué** — prix via thème + sync JS cachée |
| Toggle No power/Power | Style **pill Eyewa** (réf. eyewa.com) |
| Backorder | Stock vendable = physique + allowance − consommé − panier |
| Backorder par interne | **Custom** checkbox ; sinon règle globale Settings |
| Menu admin | **Alwaleed Optics** top-level (pos. 3), plus sous WooCommerce |
| Page Stock | Gestion hiérarchique + alertes + restock AJAX ; badge menu si alertes ; badge **N low stock** par parent |
| Restock backorder | Case optionnelle reset `backorder_consumed` ; libellé selon règle global/custom |
| Seuil alerte stock | Global Settings ; compare **stock physique** uniquement ; override Custom par interne |
| Settings layout | Panneaux Backorder + Stock alerts en **2 colonnes** |
| Child selector UI | **Supprimé** — sélection par puissances en cascade uniquement |
| Mise à jour handoff | **À chaque prompt** significatif — mettre à jour `SESSION_HANDOFF.md` |
| Règle Cursor | `.cursor/rules/session-handoff.mdc` (`alwaysApply: true`) — impose la mise à jour du handoff |
| Autoload | Enregistré **immédiatement** (activation avant `plugins_loaded`) |
| Génération puissances | Plage De/À + **Pas** (step) ; identité catalogue une fois ; auto-création termes |
| Convert 1.3.0 | Produits **simples** seulement ; variables plus tard |

---

*Dernière mise à jour : 2026-08-22 — version **1.3.0** (génération internes par plage + Convert).*
