# Changelog

Toutes les modifications notables de **Alwaleed Optics Products** sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [1.9.5] — 2026-09-22

### Ajouté

- **Convert multi-couleurs** : dans le wizard Convert, le champ Couleur accepte plusieurs valeurs (ex. Gray, Sterling Gray, Honey, Brown). Une seule conversion crée le produit cartésien **couleurs × combinaisons de puissances** ; le compteur d’internes multiplie par le nombre de couleurs sélectionnées.

## [1.9.4] — 2026-09-22

### Corrigé

- **Toggle No power disparu après lazy matrix (1.9.3)** : restauration du build matrice via configs (chemin pré-1.9.3) pour l’AJAX ; stub SQL détecte les internos plano (`sph_id` / label / SKU) ; toggle visible dès le paint (pas masqué pendant le loading).

## [1.9.3] — 2026-09-22

### Corrigé

- **Fiche produit lente (milliers d’internes)** : plus de scans O(N) répétés au chargement. Prix = meta parent synchronisée ; stock HTML = `COUNT` SQL ; matrice storefront en AJAX différé si `> 150` internes ; réservations panier en une passe ; cache requête pour configs/matrice.
- **Matrice AJAX accélérée** : build depuis colonnes SQL + cache zero-SPH (plus de milliers de `SELECT` catalog) ; labels termes en batch.
- **Toggle No power / Power** : détection plano dans le build SQL + `supportsNoPowerMode` si `noPowerChild` ou `noPowerByColor` en stock ; UI injectée après AJAX si besoin.
- **Toggle multi-couleur / SAMA** : JS ne masque plus le toggle quand `showColorSwatches` ; `division_supports_no_power_mode` inclut toutes les divisions `show_color` (ex. `sama_color_lenses`).

## [1.9.2] — 2026-09-22

### Corrigé

- **Stock Alerts / badge** : le total n’inclut plus les copies WPML (originaux uniquement) — le compteur gonflé venait des internes dupliqués sur les traductions.
- **Restock** : met à jour le stock de l’original puis synchronise le même interne (`child_key`) vers toutes les traductions.

## [1.9.1] — 2026-09-22

### Corrigé

- **Converted / Specifics lents** : plus de SSR de tous les parents ni `get_price_html()` (qui chargeait tous les internes). Shell vide + DataTables `serverSide` (`wc_optic_convert_list_products`) ; prix admin = `wc_price` parent ; counts via `_optic_child_count` / SQL.

## [1.9.0] — 2026-09-18

### Ajouté

- **Pastilles couleur storefront** : sur les parents multi-couleurs (2+ `catalog.color` distincts), rangée de swatches ronds (image catalogue) avant No power / Power ; filtre SPH + résolution panier par couleur.
- **Image swatch** sur les termes Catalog → Colors (`image_id`, schema v5) + sélecteur média dans Optic Settings.
- **No power par couleur** : `noPowerByColor` dans la matrice storefront.

### Changé

- Unicité des internes : la clé de combinaison inclut la couleur quand la division affiche la couleur (même SPH autorisé une fois par couleur).
- Sync identité : ne réécrit plus la couleur d’un interne qui en a déjà une (préserve les parents multi-couleurs).
- Parents mono-couleur : pas de rangée swatches (comportement inchangé).

## [1.8.2] — 2026-09-18

### Corrigé

- **Prix sous le titre produit** : suit le prix réel de l’interne (regular ou sale) ; plus de barré stale après suppression du sale ; sync du prix thème depuis la sélection / défaut.

## [1.8.1] — 2026-09-18

### Ajouté

- **Sale price** sur les internes : champ optionnel Regular / Sale (wizard Convert + éditeur fiche produit).
- Affichage storefront / panier avec barré WooCommerce (`wc_format_sale_price`) quand sale < regular.
- Le prix facturé reste le prix actif (sale si défini).

## [1.8.0] — 2026-09-18

### Ajouté

- **Table SQL `wc_optic_children`** (schema v4) : source de vérité pour les internes (SKU, stock, powers, `config_json`, `is_low_stock`).
- **Migration** meta `_optic_child_configs` → SQL (batches reprises via `admin_init`).
- **Stock Management** : parents au premier paint ; enfants en AJAX paginé (`wc_optic_stock_list_children`, 50/page) + recherche.
- **Stock Alerts** : DataTables server-side (`wc_optic_stock_list_alerts`) ; QR générés uniquement pour la page visible.
- **Badge alertes** : `COUNT(*)` SQL indexé sur `is_low_stock`.

### Changé

- `persist_child_data` / `get_child_configs` / restock unitaire écrivent et lisent SQL (plus d’écriture du gros blob postmeta).
- WPML sync copie les children SQL vers les traductions (`copy_product_children`).

## [1.7.4] — 2026-09-15

### Corrigé

- **Convert / admin perf** : meta légère `_optic_child_count` (plus de `normalize` des milliers d’internes pour les listes/stats) ; cache requête des stats Converted ; badge alertes Stock en transient (sans générer les QR).

## [1.7.3] — 2026-09-15

### Ajouté

- **Settings → Convert** : option **Maximum internal products** (`wc_optic_max_synthetic_children`, défaut 6000) pour Convert / Rebuild / Specifics.

## [1.7.2] — 2026-09-15

### Changé

- **Convert** : plafond d’internes `MAX_LEGACY_SYNTHETIC_CHILDREN` porté à **6000**.

## [1.7.1] — 2026-09-13

### Changé

- **Add template** : une seule plage From/To/Step (plus de bouton Add range).
- **Wizard** : listes de gabarits toujours actives (plus de case à cocher / disabled).

## [1.7.0] — 2026-09-13

### Changé

- **Range templates — globaux** : un gabarit = **Name + From/To/Step** seulement (plus de puissance à la création). Le même gabarit remplit SPH, CYL, AXIS ou ADD dans le wizard. Migration `wc_optic_power_templates_v3` (déduplique les anciens gabarits multi-puissances).

## [1.6.1] — 2026-09-13

### Changé

- **Add template** : cases à cocher multi-puissances + une seule plage From/To/Step partagée. Ex. SPH + CYL avec −0.25→−5.00 / 0.25 crée **deux** gabarits (un SPH, un CYL) avec les mêmes valeurs.

## [1.6.0] — 2026-09-12

### Changé

- **Range templates — par puissance** : chaque gabarit = une puissance (SPH/CYL/AXIS/ADD) + From/To/Step (segments multiples autorisés). Plus lié à une division. Migration auto des anciens gabarits division → un gabarit par puissance (`wc_optic_power_templates_v2`).
- **Convert / Rebuild / Specifics** : cases + listes par puissance ; appliquer un gabarit **ajoute** les segments (ne remplace plus). Les plages postées côté client font foi (plus d’écrasement serveur via `template_id`).

## [1.5.0] — 2026-09-12

### Ajouté

- **Convert / Templates — plages multiples** : plusieurs From/To/Step par puissance (ex. SPH −0.50→−6.00 / 0.25 + +0.50→+5.00 / 0.50 + +5.75→+8.75 / 1). Les valeurs sont unies (dédupliquées) avant la génération cartésienne des internes. Compatibilité avec l’ancien format à une seule plage. Bouton **Add range** / suppression de segment.

## [1.4.12] — 2026-09-12

### Ajouté

- **Convert — wizard** : preloader (spinner) pendant le chargement AJAX du produit ; division / étapes / boutons Next-Back masqués ou désactivés jusqu’à l’arrivée des données.

## [1.4.11] — 2026-09-12

### Corrigé

- **Convert — wizard AJAX** : `getAjaxUrl()` utilise le `ajaxurl` global WordPress admin (chemin relatif, comme le heartbeat) au lieu de l’URL absolue `admin_url()` localisée dans `wcOpticConvert.ajaxUrl`.
- **Staging** : corrige `ERR_TOO_MANY_REDIRECTS` / « Could not load the product » au démarrage du wizard (validé sur `staging.alwaleedoptics.com`).

## [1.4.10] — 2026-09-02

### Corrigé

- **Panier — No power** : fusion des lignes identiques (recherche par prescription + identité `child_id` dédiée au mode no_power).

## [1.4.9] — 2026-09-02

### Corrigé

- **Panier** : lignes identiques (même interne / No power) fusionnées — `woocommerce_cart_id` ignore qty et totaux.

## [1.4.8] — 2026-09-02

### Corrigé

- **Panier — No power** : `build_eye_payload_from_child()` utilisait `child_is_no_power()` (limité à `color_lenses`) et exigeait CYL/AXIS sur les autres divisions. Corrigé via `config_has_zero_sph()`.

## [1.4.7] — 2026-09-02

### Corrigé

- **Specifics — SPH +0.00** : pas de champ Step requis (From/To = 0 suffit) ; step masqué dans le wizard.
- **Storefront — No power / Power** : toggle affiché seulement si un interne plano (+0.00) est en stock ; détection plano renforcée ; +0.00 retiré du dropdown Power.

## [1.4.6] — 2026-09-02

### Modifié

- **Convert — Specifics** : le wizard saute l’étape Identité (comme la division verrouillée) ; Produit → Puissances. L’identité produit existante est conservée côté UI et backend.

## [1.4.5] — 2026-09-02

### Corrigé

- **Convert — wizard Select2** : filtrage clavier rétabli dans le modal (`data-bs-focus="false"`, contournement du focus trap Bootstrap pour les dropdowns sur `<body>`).

## [1.4.4] — 2026-09-02

### Corrigé

- **Convert — Reset all** : le parent repasse bien en **simple** après reset. La sauvegarde sur `WC_Product_Optic_Product` (type codé en dur) ne réécrivait plus le terme `optic_product` ; ordre corrigé (terme `simple` → rechargement → strip meta → save).

## [1.4.3] — 2026-09-02

### Corrigé

- **Convert — Reset all** : supprime intégralement les internes **et** leurs identités au niveau produit (division, meta optique) ; repasse en simple. **Settings** (catalogue global, divisions, gabarits, options) inchangés.

## [1.4.2] — 2026-09-02

### Corrigé

- **Convert — Reset all** : ne supprime plus l’identité produit (section, brand, pack, …), la division ni les plages enregistrées — seulement les **internes** ; le parent repasse en **simple** pour reconstruire à zéro.

## [1.4.1] — 2026-09-02

### Modifié

- **Convert — Reset all** : supprime tous les internes **et** repasse chaque parent `optic_product` en **produit simple** (meta optique produit effacée). Les **Settings** du plugin (catalogue, divisions, gabarits, options globales) ne sont pas modifiés.

## [1.4.0] — 2026-09-02

### Ajouté

- **Convert — Danger zone** : bouton **Reset all internal products** (administrateurs WordPress uniquement, `manage_options`). Confirmation par mot de passe du compte + case à cocher. Supprime tous les internes des `optic_product` convertis ; division, identité et plages conservées. Sync WPML.

## [1.3.9] — 2026-08-26

### Modifié

- **SPH +0.00 = lentille sans puissance** : ne croise plus CYL / AXIS / ADD à la génération (Convert, Rebuild, Specifics). Un seul interne plano ; les autres puissances sont ignorées / vidées. Compteur et wizard masquent CYL/AXIS/ADD si SPH From=To=0.

## [1.3.8] — 2026-08-26

### Ajouté

- **Convert → onglet Specifics** : ajouter des puissances supplémentaires aux produits déjà convertis (ex. SPH +0.00 × CYL/AXIS existants) **sans rebuild**. Les combinaisons déjà présentes sont ignorées ; message `ajoutés / doublons ignorés / total`.

## [1.3.7] — 2026-08-26

### Modifié

- **Wizard Convert / Converted** : modal simple sans `pane-scroll` / `pane-fixed` — un seul flux (plages + prix + stock), scroll naturel du modal.

## [1.3.6] — 2026-08-26

### Corrigé

- **Wizard Convert — double scrollbar** : un seul scroll sur les plages (étape Powers). `modal` / `modal-body` / `modal-content` en `overflow: hidden` + hauteur bornée au viewport ; cache CSS bustée via `filemtime`.

## [1.3.5] — 2026-08-26

### Ajouté

- **Convert → onglet Converted** : liste des `optic_product` déjà convertis (division, nombre d’internes). Wizard **Rebuild** : changer division / identité / plages et **remplacer** tous les internes (ex. Color Lenses → Astigmatism Toric avec CYL+AXIS).

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
