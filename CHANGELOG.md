# Changelog

Toutes les modifications notables de **Alwaleed Optics Products** sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [1.3.4] — 2026-08-26

### Corrigé

- **Identité optique → tous les internes** : changer Color / Brand / Pack / etc. (ou la division) applique immédiatement le catalogue + **regénère les SKU** de chaque interne (AJAX `wc_optic_sync_identity`). L’Update produit fait de même.
- La synchro d’identité n’est **plus bloquée** par l’avertissement anti-doublon (ex. passage Toric → Color Lenses où plusieurs SPH se croisent).
- Si la division masque la couleur, `color` est remis à 0 sur l’identité et tous les internes.

## [1.3.3] — 2026-08-26

### Ajouté

- **Fiche produit admin — internes à la demande** : liste compacte (label, puissances, prix, stock, statut) + panneau d’édition AJAX (Edit / Add / Remove / Save). Plus de rendu des N blocs Select2 au chargement.
- **Anti-doublon prescriptions** : sauvegarde d’un interne refusée si SPH/CYL/AXIS/ADD (selon division) existe déjà sur un autre interne (même désactivé). Message avec le label conflictuel.

### Modifié

- Sauvegarde produit WooCommerce : division + identité uniquement ; les internes sont persistés via AJAX (`wc_optic_load_child`, `wc_optic_save_child`, `wc_optic_remove_child`, `wc_optic_list_children`).

## [1.3.2] — 2026-08-23

### Corrigé

- **+0.00** : si 0 est dans la plage (From ≤ 0 ≤ To), la puissance est toujours générée, même si le pas ne tombe pas dessus. Les champs From/To/Step acceptent `0` / `0.00`.
- **WPML / WCML** : Convert liste uniquement les originaux (langue par défaut) ; les internes (`_optic_child_configs` et metas liées) sont copiés vers les traductions. `wpml-config.xml` passe `_optic_child_configs` en `copy`.

## [1.3.1] — 2026-08-23

### Modifié

- **Convert** : plus de formulaire en masse. Assistant **wizard** dans une modal Bootstrap 5 (`data-bs-backdrop="static"`) : un produit à la fois, étapes Produit → Identité → Puissances, bouton **Next**.
- Fiche produit : génération par plage retirée (uniquement l’identité + internes manuels). La génération se fait via le wizard Convert.

## [1.3.0] — 2026-08-22

### Ajouté

- **Génération des internes par plage** : identité catalogue une seule fois (section, company, brand, timing, color, pack, transparency) + **De / À / Pas** pour chaque puissance de la division. Les valeurs catalogue manquantes (SPH, CYL, AXIS, ADD) sont créées automatiquement.
- **Gabarits de plages** réutilisables (`Alwaleed Optics → Convert → Range templates`).
- **Assistant Convert** : convertir des produits simples en `optic_product` et générer les internes par lots AJAX.
- Fiche produit : bouton **Generate internals** (persist + rechargement).
- Classes `WC_Optic_Catalog::resolve_power_range()`, `WC_Optic_SKU::build_children_from_ranges()`, `WC_Optic_Power_Template`, `WC_Optic_Converter`, `WC_Optic_Admin_Convert`.

### Modifié

- Les 7 champs identité ne se répètent plus dans chaque bloc interne (recopiés à la sauvegarde / génération).

## [1.2.5] — 2026-08-19

### Corrigé

- **Fatal `Class "WC_Optic_Catalog" not found`** à l’activation : l’autoloader est désormais enregistré dès le chargement du plugin (plus seulement sur `plugins_loaded`), car `WC_Optic_Divisions::maybe_seed_defaults()` appelle le catalogue avant ce hook.
- `WC_Optic_Autoload::register()` est idempotent ; `get_available_powers()` a un fallback SPH/CYL/AXIS/ADD si le catalogue n’est pas encore chargé.

## [1.2.4] — 2026-06-11

### Ajouté

- **Backorder** : paramétrage global (Settings) et override **Custom** par produit interne ; stock vendable = stock physique + allowance − consommé − panier.
- **Menu admin Alwaleed Optics** : menu principal sous Dashboard (Settings, Stock, Import) — hors menu WooCommerce.
- **Page Stock** (`Alwaleed Optics → Stock`) :
  - onglet **Stock management** : tableau hiérarchique parent / internes (repliable, recherche, Expand/Collapse all) ;
  - onglet **Stock alerts** : DataTables avec QR code SKU interne, seuil global + override **Custom threshold** par interne ;
  - **Restock** AJAX par produit interne (modal quantité) ;
  - case optionnelle **Reset backorder** au restock (décochée par défaut ; libellé global vs custom) ;
  - badge **N low stock** sur chaque ligne parent (visible replié ou déplié).
- Bulle compteur d’alertes sur le menu **Alwaleed Optics** et le sous-menu **Stock**.
- Classe **`WC_Optic_Stock`** (inventaire, alertes, `restock_child()`).
- Classe **`WC_Optic_Admin_Stock`** + assets `admin-stock.js`, styles stock dans `admin.css`.
- Endpoint AJAX **`wc_optic_restock_child`**.

### Modifié

- Settings : panneaux **Backorder** et **Stock alerts** côte à côte (2 colonnes).
- Chargement JS page Stock via `admin_print_footer_scripts` (fiabilise chevrons, recherche, restock).
- Suppression de l’option globale **Child selector** (radio/dropdown) ; sélection par puissances en cascade uniquement.
- UI backorder admin : carte produit interne (toggle pill, badges Global/Custom).

### Technique

- Méta enfants : `backorder_custom`, `backorder_qty`, `backorder_consumed`, `alert_custom`, `alert_qty`.
- `WC_Optic_SKU::get_remaining_child_stock()`, `apply_child_stock_delta()`, `preserve_child_backorder_consumed()`.
- `SESSION_HANDOFF.md` synchronisé.

## [1.2.3] — 2026-06-09

### Modifié

- Champs quantité fiche produit (`#wc_optic_qty`, `#wc_optic_qty_left`, `#wc_optic_qty_right`) : texte centré.
- Retrait du `padding-top` sur `.wc-optic-config-table__label` (alignement grille formulaire).

## [1.2.2] — 2026-06-09

### Modifié

- Ajustements CSS du toggle **No power / Power** (pill Eyewa, focus visible, grille pleine largeur).
- Mise à jour `SESSION_HANDOFF.md`.

## [1.2.1] — 2026-06-09

### Modifié

- Toggle **No power / Power** en style pill (réf. Eyewa) : onglets arrondis, transition 350ms, pleine largeur.
- `SESSION_HANDOFF.md` complété (UI, masquage division/prix, tests).

## [1.2.0] — 2026-06-09

### Ajouté

- **Lentilles couleur** : choix **No power** / **Power** (défaut No power) ; no power = SPH catalogue **+0.00**.
- **Prix unique** en boutique (plus de fourchette min–max) : no power pour color lenses, prix le plus bas pour les autres divisions.
- **Total par section** œil dans le résumé panier/checkout (`wc-optic-line-summary`).
- Module **Flatsome** : panier et paiement en cartes (`class-wc-optic-flatsome.php`, `flatsome-cart-checkout.css`).
- Détection SPH plano : `WC_Optic_Catalog::sph_term_is_zero_power()`.
- Document **`SESSION_HANDOFF.md`**.

### Modifié

- Fiche produit : division optique et bloc prix du formulaire **masqués** (prix WooCommerce / Flatsome inchangé ; sync JS via éléments cachés).
- `format_display_price_html()` remplace l’affichage par fourchette ; prix parent synchronisé sur le produit interne par défaut.

## [1.1.0] — 2026-06-03

### Ajouté

- **QR codes** pour les SKU internes (préparation des commandes), visibles **uniquement en admin** :
  - fiche produit : aperçu sous chaque produit interne, mis à jour en AJAX avec l’aperçu SKU ;
  - commandes : bloc de préparation par ligne optique.
- Dépendance Composer **`chillerlan/php-qrcode`** (PNG si GD, sinon SVG).
- Classe **`WC_Optic_QR`** pour la génération des codes.
- **Résumé panier / checkout** (client) sur le modèle admin, **sans SKU ni QR** :
  - colonnes OS / OD (ou un bloc « même puissance ») ;
  - puissances sélectionnées, prix unitaire, quantité.
- Feuilles de style dédiées : **`assets/css/admin-order.css`**, styles panier dans **`frontend.css`**.
- Template WooCommerce **`templates/cart/cart-item-data.php`** (pas de libellé `optic-line`, pas de `wpautop` sur le résumé optique).

### Modifié

- **Page commande (admin)** :
  - résumé en **2 colonnes** (œil gauche OS à gauche, œil droit OD à droite) ;
  - mode **same power** : **une seule colonne** et quantité partagée (ex. 5, pas 5 + 5) ;
  - QR plus grands et espacés pour le scan ;
  - tableau articles : masquage miniature, **prix unitaire**, **quantité**, taxes (lignes 100 % optiques) — colonnes **Article** et **Total** uniquement.
- Normalisation du payload **`same_power`** à l’enregistrement commande et à l’affichage (quantités `qty_left` / `qty_right` miroir).
- Panier : plus d’affichage texte « Internal SKUs » / « Eye quantities » sous le produit.
- CSS chargé sur **panier et checkout** (pas seulement le panier).
- Compatibilité **Flatsome** : pleine largeur des colonnes œil dans `product-name`, grille `dl.variation` neutralisée pour le résumé optique.

### Technique

- Hooks : `admin_body_class`, template `cart/cart-item-data.php` via `WC_Optic_Frontend::locate_template`.
- Réponse AJAX `wc_optic_preview_sku` enrichie avec `qr_html`.

## [1.0.0] — version initiale

- Type de produit WooCommerce **Optic Product**.
- Catalogue global (sections, marques, puissances SPH/CYL/AXIS/ADD, etc.).
- Produits internes par combinaison de puissances, SKU dynamique, stock par enfant.
- Prescription client, panier bi-œil, tarification par œil, import XLSX, WPML.
