# WooCommerce optic product plugin requirements
_Exported on 5/22/2026 at 11:59:01 GMT+1 from Cursor (3.5.17)_

---

**User**

vous etes un expert en wordpress et woocommerce
dans le plugin Optics Lenses, j'ai mis un système pour créer un produit de type Optic suivant ce PRD:
PRD — WooCommerce Optic Product Plugin
This document defines the Product Requirements Document (PRD) for a custom WooCommerce plugin dedicated to optical/lens products.
1. Project Overview
•	Create a custom WooCommerce product type named 'Optic Product'.
•	Support optical divisions and prescription logic.
•	Generate dynamic SKU values.
•	Support English and Arabic (WPML compatible).
•	Manage separate eye prescriptions and quantities.
2. Environment
•	WordPress latest stable version.
•	WooCommerce Legacy storage only (NO HPOS).
•	WPML compatible.
•	Languages: English (default) and Arabic RTL.
3. Product Type
•	Create a custom WooCommerce product type: optic_product.
•	Label displayed in admin: Optic Product.
4. Optical Divisions
•	Color lenses
•	SAMA Color Lenses
•	Astigmatism Toric
•	Multifocal Bifocal
5. Power Fields By Division
•	Color lenses → SPH only.
•	SAMA Color Lenses → SPH, CYL, AXIS.
•	Astigmatism Toric → SPH, CYL, AXIS.
•	Multifocal Bifocal → SPH, ADD.
6. Dynamic SKU Generation
•	SKU format: section + company + brand + timing + color + sign + rx + pack + transparency.
•	SKU updates dynamically while editing the product.
7. Global Settings Page
•	Create WooCommerce → Optic Settings admin menu.
•	Manage Sections, Companies, Brands, Timings, Colors, Signs, RX, Packs, Transparency.
8. Excel Import
•	Support XLSX and CSV import.
•	Allow column mapping.
•	Prevent duplicates.
•	Provide import logs.
9. Frontend Prescription Logic
•	Customer must select optical powers before add-to-cart.
•	Default mode uses same power for both eyes.
•	Optional toggle enables different power per eye.
10. Quantity Logic
•	Disable default WooCommerce quantity field.
•	Use custom quantity fields.
•	OFF mode = single quantity.
•	ON mode = quantity per eye.
11. Pricing Logic
•	OFF mode: Product price × quantity.
•	ON mode: Product price × (left qty + right qty).
12. Cart & Order Storage
•	Store optical configuration in cart item meta.
•	Save order data in woocommerce_order_itemmeta.
13. WooCommerce Compatibility
•	Compatible with legacy WooCommerce cart and checkout.
•	Support AJAX add to cart.
•	No HPOS support in V1.
•	No WooCommerce Blocks support in V1.
14. Database Architecture
•	Use wp_posts and wp_postmeta for products.
•	Use custom tables for optical global values.
15. WPML Compatibility
•	Support WPML and WooCommerce Multilingual.
•	All labels must be translatable.
•	Support RTL frontend and admin.
16. RTL Requirements
•	Right eye displayed first in RTL mode.
•	Optical abbreviations remain LTR (SPH, CYL, AXIS, ADD).
17. Recommended Plugin Structure
•	Use PHP OOP architecture.
•	Separate admin, frontend, pricing, AJAX and database classes.
18. Validation Rules
•	Prevent add-to-cart when prescription is incomplete.
•	Prevent invalid quantities.
•	Validate left and right eye independently.
19. Acceptance Criteria
•	Admin can create optical products.
•	Customer can configure prescriptions.
•	Pricing updates correctly.
•	Cart and order store all optical data.
20. Future Improvements
•	Stock by power.
•	ERP integration.
•	REST API.
•	Prescription PDF upload.
•	Subscriptions.
 
Technical Notes
•	Avoid WooCommerce native variations for optical powers.
•	Prefer metadata-driven architecture.
•	Use AJAX for instant admin value creation.
•	Use Select2/SelectWoo for searchable dropdowns.
•	Keep one cart line even in dual-eye mode.


---

**Cursor**

Explorant le plugin « Optics Lenses » dans votre projet pour comprendre l’implémentation et pouvoir vous aider concrètement.



Vous avez déjà une base solide qui colle au PRD. Voici ce que montre le plugin **Optic-Lenses** (`wp-content/plugins/Optic-Lenses/`) par rapport à votre document.

## Alignement avec le PRD

**Type de produit et divisions** — Le type `optic_product` est enregistré, le libellé admin est « Optic Product », et les quatre divisions avec les bons champs (SPH seul, SPH/CYL/AXIS, SPH/ADD) sont centralisées dans `WC_Optic_Plugin::get_divisions()`.

**Stockage et compatibilité WooCommerce** — Produit classique + métas ; table personnalisée `wc_optic_catalog` pour le catalogue global ; déclaration explicite **non compatible HPOS** via `FeaturesUtil::declare_compatibility( 'custom_order_tables', …, false )`, ce qui correspond au « legacy only » du PRD.

**Panier / commande** — Les données optiques passent par la clé `_wc_optic` dans les données de ligne panier, puis en commande (`_wc_optic_payload` + métas lisibles). Une seule ligne : en mode « quantité par œil », la quantité WooCommerce de la ligne est `left + right`. **`WC_Optic_Pricing`** gère le filtre `woocommerce_add_to_cart_quantity`, le prix unitaire et le calcul du total ligne (`unit price × qty`).

**Frontend** — Formulaire prescription, case « même puissance pour les deux yeux », bascule quantité unique / par œil, champ `quantity` masqué synchronisé en JS, validation côté serveur dans `WC_Optic_Cart::validate_add_to_cart` et `parse_eye_powers`.

**SKU dynamique, réglages admin, import XLSX** — Présents dans l’arborescence (`class-wc-optic-sku.php`, `class-wc-optic-admin-settings.php`, `class-wc-optic-admin-import.php`, PhpSpreadsheet au bootstrap).

**RTL** — Les abréviations sont en `dir="ltr"`. Pour l’affichage des deux colonnes quand les yeux diffèrent, le CSS inverse l’ordre en RTL :

```17:20:wp-content/plugins/Optic-Lenses/assets/css/frontend.css
/* RTL: OD column first in reading order (DOM is OS then OD) */
.rtl .wc-optic-eyes--stack {
	flex-direction: row-reverse;
}
```

Le texte de prescription en panier / commande met déjà **OD avant OS** dans `format_prescription_plain()`.

**WPML** — Text domain `wc-optic` et chaînes via `__()` / `esc_html_e()` : bonne base ; pour WooCommerce Multilingual, il faudra en plus traiter les chaînes dynamiques (noms issus de `wc_optic_catalog`, libellés de division, etc.) selon votre stratégie (tables traduites, filtres WPML, ou champs par langue).

## Point d’attention mineur

- En mode **quantité par œil** sur la fiche produit, les champs sont dans l’ordre OS puis OD dans le HTML ; en RTL pur sans classe `.rtl` sur un ancêtre (selon thème), l’inversement visuel peut ne pas s’appliquer. Vérifier que le body ou un conteneur reçoit bien la classe `rtl` avec votre thème / WPML.

---

En résumé : l’architecture (OOP, métadonnées, pas de variations pour les puissances, une ligne panier, legacy + pas HPOS V1) est cohérente avec le PRD.

Indiquez ce que vous voulez faire ensuite : revue de sécurité / conformité WooCommerce, compléments WPML, ajustements RTL, tests de scénarios (AJAX add to cart, emails, admin SKU), ou correction d’un bug précis — et on le traite dans le code.

---

**User**

commençant par les valeurs sur les paramètres
pour créer une nouvelle entrée, je suis obligé d'enregistré puis ajouter un par un, alors il faut ajouter un bouton "Add new" et saisir toutes les données et enregistrer une seule fois

---

**Cursor**

Examinant l'interface des paramètres Optic et le flux d'enregistrement actuel.



Constat : une seule ligne vide est rendue en PHP (`render_row(..., null, ...)`), donc une seule nouvelle entrée par enregistrement. Nous ajoutons un bouton « Add new » qui insère des lignes vides dans le tableau pour tout enregistrer d'un coup, et retirons le « Quick add (AJAX) » qui imposait une sauvegarde/rechargement par entrée.



Améliorant le traitement d'enregistrement : signaler les lignes ignorées en cas de slug dupliqué lors d'une même sauvegarde.



Nettoyant la localisation JS : l'i18n n'est plus utilisée sur la page des réglages.



Voici ce qui a été modifié.

## Comportement

- **Bouton « Add new »** au-dessus du tableau : chaque clic ajoute une **ligne vide** (nom, slug, fragment SKU, tri), comme la dernière ligne du formulaire.
- Vous remplissez **toutes** les lignes (y compris la ligne vide déjà présente en bas du tableau), puis **un seul** clic sur **« Save changes »** : tout est enregistré dans le même traitement POST (`handle_post_save`).
- **Texte d’aide** à côté du bouton pour indiquer ce flux.
- **Suppression du « Quick add (AJAX) »** qui créait une entrée à la fois avec rechargement de page — ce qui allait à l’encontre de ce que vous vouliez.

## Fichiers touchés

- `includes/admin/class-wc-optic-admin-settings.php` — barre d’outils + suppression du panneau AJAX + message d’avertissement si des **nouvelles** lignes ne sont pas créées (slug déjà existant sur l’onglet courant).
- `assets/js/admin-settings.js` — insertion des lignes avec des clés `wc_optic_row[new_…]`.
- `assets/css/admin.css` — styles pour la barre d’outils.

L’action AJAX `wc_optic_create_term` reste dans le plugin au cas où vous voudriez la réutiliser ailleurs ; elle n’est plus utilisée sur cette page.

Si vous souhaitez la même chose sur la page **Import** ou un libellé en arabe via WPML pour la phrase d’aide, on peut l’ajouter.

---

**User**

mettre le bouton au-dessous, juste avant enregistrer

---

**Cursor**

C’est fait. Le bloc **« Add new »** + le texte d’aide est maintenant placé **juste après le tableau** et **immédiatement avant** le bouton **« Save changes »**.

---

**User**

ajouter un espace entre les tabs et le tableau, ils sont collés mnt

---

**Cursor**

C’est en place dans `assets/css/admin.css` : une marge sous les onglets (`#wc-optic-settings-root .nav-tab-wrapper { margin-bottom: 1.25em; }`) pour décoller le formulaire et le tableau des tabs. Cette feuille n’est chargée que sur **Optic Settings**, donc rien d’autre n’est impacté.

---

**User**

le delete doit changer en un effacement ajax avec confirmation

---

**Cursor**

Implémentant la suppression par AJAX avec confirmation : examen du code actuel.



Ajout de l'action AJAX `wc_optic_delete_term`, remplacement de la case à cocher par un bouton, suppression de la logique POST « delete », et mise à jour du JS avec confirmation.



Corrigeant la localisation : lire l'onglet actif depuis `data-active-tab` sur `#wc-optic-settings-root` plutôt que depuis `enqueue` (évite une duplication et une incohérence).



Voici ce qui a été mis en place.

## Comportement

- La case **Delete** a été remplacée par un lien/bouton style admin WordPress (`button-link-delete`).
- Au clic : **`window.confirm`** avec le texte traduit + le **nom** courant de la ligne (lu dans le champ « Name »).
- Si l’utilisateur confirme : requête **`admin-ajax.php`** (`action: wc_optic_delete_term`) avec `id`, `term_type` (onglet actif depuis `data-active-tab` sur `#wc-optic-settings-root`) et le nonce `wc_optic_admin`.
- Côté serveur : vérification du nonce, droit `manage_woocommerce`, existence de la ligne et cohérence **`term_type`** avec l’onglet (évite de supprimer un mauvais enregistrement).
- En cas de succès : la **ligne disparaît** du tableau (léger `fadeOut` puis `remove`), **sans** recharger la page.
- En cas d’erreur : message d’alerte (corps JSON ou message générique).

## Fichiers modifiés

| Fichier | Changement |
|---------|------------|
| `includes/class-wc-optic-ajax.php` | Action `wc_optic_delete_term` + méthode `delete_term()`. |
| `includes/admin/class-wc-optic-admin-settings.php` | Bouton + `aria-label`, chaînes `i18n` pour confirm/erreur, suppression du traitement POST `delete`. |
| `assets/js/admin-settings.js` | Clic délégué sur `.wc-optic-delete-row`, confirm, AJAX, retrait de ligne. |
| `assets/css/admin.css` | Petit ajustement vertical pour le bouton Delete. |

Les produits qui référencent encore l’ID supprimé dans leurs listes déroulantes peuvent afficher une valeur vide jusqu’à rechoix / resauvegarde ; ce point reste inchangé par rapport à l’ancienne suppression au moment de « Save changes ».

---

**User**

remplacer le bouton par l'icone d'une poubelle

---

**Cursor**

Remplaçant le libellé par l'icône Dashicons `dashicons-trash` et chargeant la feuille de style `dashicons` pour garantir son affichage.



Modifications effectuées :

1. **`class-wc-optic-admin-settings.php`**  
   - Le texte du bouton est remplacé par `<span class="dashicons dashicons-trash" aria-hidden="true"></span>`.  
   - L’**`aria-label`** reste `sprintf( __( 'Delete %s', 'wc-optic' ), $row->name )` pour l’accessibilité (lecteurs d’écran, infobulle au survol selon le navigateur).

2. **Enqueue**  
   - Ajout de `wp_enqueue_style( 'dashicons' )` pour que l’icône s’affiche correctement sur cette page.

3. **`admin.css`**  
   - Ajustement de la taille (18px) et de l’alignement de l’icône dans la cellule.

Le comportement AJAX + confirmation ne change pas.

---

**User**

lors de la suppression de n'importe quelle valeur, on enregistre l'utilisateur et le temps où il est supprimé, en plus il faut localiser les produits qui ont cette valeur supprimée pour la modifier par la suite

---

**Cursor**

Examinant comment les produits référencent les entrées du catalogue et comment la suppression est gérée.



Implémentant une table de journalisation, la détection des produits concernés et un onglet d'historique dans les réglages.



Création de la table de journal, de la classe `WC_Optic_Deletion_Log`, mise à jour de l'AJAX, du schéma BDD et de l'interface admin (onglet « Journal des suppressions »).



Refactorisant `render_page` et ajoutant l'onglet « Journal des suppressions ».



Voici ce qui a été mis en place.

## 1. Table `wp_wc_optic_catalog_deletion_log` (audit)

Colonnes : `catalog_term_id`, `term_type`, `term_name`, `term_slug` (copie au moment de la suppression), `deleted_by` (ID utilisateur), `deleted_at`, `affected_products` (JSON : `[{"id":…,"name":…}, …]`).

- Création à l’**activation** du plugin et pour les sites déjà installés via **`WC_Optic_Database::maybe_upgrade_schema()`** (option `wc_optic_db_schema` = **2**).

## 2. Classe `WC_Optic_Deletion_Log`

- **`find_products_using_term( $term_type, $catalog_term_id )`** — recherche dans `postmeta` la méta SKU correspondant à ce type (`WC_Optic_SKU::META_KEYS`) pour les types de publication `product` et `product_variation`, avec titre + lien d’édition.
- **`record( $row, $user_id, $affected_products )`** — écrit une ligne dans le journal **après** la suppression en base catalogue.
- **`get_entries( $limit )`** — lecture pour l’admin.

## 3. AJAX `wc_optic_delete_term`

Ordre : détection des produits → suppression du terme catalogue → enregistrement du journal. La réponse JSON inclut `affected_products`, `log_id`, `deleted_term_name`. Si l’insert du journal échoue, une ligne est écrite dans le log PHP (`error_log`).

## 4. Interface

- Onglet **« Deletion log »** (journal des suppressions) dans **WooCommerce → Optic Settings**, avec tableau : date, utilisateur (nom + login), liste (Sections, Brands, …), entrée supprimée, ID catalogue, liste des produits avec liens d’édition (ou libellé « removed or no access » si le produit n’existe plus / pas de droit).
- Sous les onglets, zone **`#wc-optic-inline-messages`** : après une suppression AJAX, **avis orange** avec la liste des produits encore concernés, ou **avis info** s’il n’y en a aucun.

## 5. Fichiers touchés

- `includes/class-wc-optic-database.php` — table + migration.
- `includes/class-wc-optic-deletion-log.php` — **nouveau**.
- `includes/class-wc-optic-autoload.php` — mapping de la classe.
- `includes/class-wc-optic-plugin.php` — appel à `maybe_upgrade_schema()`.
- `includes/class-wc-optic-ajax.php` — logique de suppression enrichie.
- `includes/admin/class-wc-optic-admin-settings.php` — onglet journal + rendu.
- `assets/js/admin-settings.js` — message après suppression.
- `assets/css/admin.css` — styles des listes / messages.

Les métas produit **ne sont pas modifiées automatiquement** : les IDs supprimés restent dans les fiches produit jusqu’à ce que vous choisissiez une autre valeur dans **Optic configuration** ; le journal et l’avis après suppression indiquent **quels** produits mettre à jour.

---

**User**

dans le tab sign, j'ai soit + ou - mais il n'accept pas le +

---

**Cursor**

Examinant comment les slugs sont validés et enregistrés pour le catalogue « sign ».



Remplaçant `sanitize_title()` : WordPress supprime le `+` (caractère non autorisé dans les titres d'URL), ce qui produit un slug vide ou invalide. Nous ajoutons un sanitizer de slug dédié au catalogue qui conserve `+` et `-`.



Voici ce qui se passait : partout on utilisait **`sanitize_title()`**, qui est pensé pour les URLs WordPress. Il **supprime le `+`** (et beaucoup d’autres caractères), donc pour une entrée « sign » `+` le slug devenait **vide**, ce qui bloquait l’insertion ou créait un conflit avec la contrainte unique `(term_type, slug)`.

## Correctif

Une méthode **`WC_Optic_Catalog::sanitize_slug()`** a été ajoutée : elle conserve **`+`**, **`-`**, lettres (y compris Unicode, ex. arabe), chiffres et **`_`**, remplace les espaces par des tirets, et normalise en minuscules.

Elle est utilisée pour :

- **`insert()`** (slug dérivé du nom ou du champ slug),
- **`get_by_slug()`** (recherche cohérente),
- **Réglages Optic** (sauvegarde en masse),
- **AJAX** `wc_optic_create_term` (contrôle des doublons + insertion),
- **Import** CSV/XLSX.

Tu peux donc enregistrer des signes **`+`** et **`-`** dans l’onglet **Signs** (nom et/ou slug).

**Note :** si une ancienne ligne « + » avait été enregistrée avec un slug vide ou incorrect, il peut rester une ligne bizarre en base ; tu peux la supprimer dans l’admin et la recréer proprement.

---

**User**

pour l'admin, je n'ai pas besoin de voir les slugs, je veux voir les nom et leurs fragments, aussi lorsque j'écris une valeur dans le nom, elle doit etre copier directement vers le fragment, sinon meme le fragment j'en ai pas besoin de le voir, je veux que les noms écrites soit exactement celle utilisée dans la génération du sku

---

**Cursor**

Examinant comment le SKU est construit à partir du catalogue et comment l'admin affiche les colonnes.



Masquant les colonnes Slug et Fragment dans l’admin, en dérivant le slug en interne et en enregistrant systématiquement `sku_fragment` = nom (texte affiché) pour que le SKU utilise exactement ce libellé.



---

**User**

continue 

---

**Cursor**

Poursuivant les modifications restantes : vérification de l'état actuel et finalisation.



Ajout d'une colonne « fragment SKU » en lecture seule (miroir du nom), finalisation de l'import et consolidation de la logique d'enregistrement.



Voici la suite des changements, finalisée.

## Interface admin (Optic Settings)

| Avant | Maintenant |
|--------|------------|
| Name, Slug, SKU fragment, Sort | **Name**, **SKU fragment** (aperçu), **Sort**, Actions |

- **Slug** : plus affiché ; généré en interne à l’enregistrement (clé base de données, ex. `+` → `+`, `-` → `-`).
- **SKU fragment** : colonne **en lecture seule** ; elle reflète en direct ce que vous tapez dans **Name** (JavaScript).
- À l’enregistrement : `sku_fragment` = **exactement** le nom saisi ; le SKU produit utilise ce libellé via `WC_Optic_SKU::catalog_term_sku_part()` (priorité au **name**).

## Génération SKU

Le segment SKU d’une valeur catalogue est le **nom** tel qu’enregistré (ex. `+`, `-`, texte arabe), pas le slug interne.

## Import

- Le **nom** importé sert aussi de fragment SKU.
- Colonne « SKU fragment » retirée du mapping d’import.
- Slug optionnel en import (sinon dérivé du nom).

## Fichiers modifiés

- `class-wc-optic-admin-settings.php` — tableau + sauvegarde  
- `assets/js/admin-settings.js` — synchronisation nom → aperçu fragment  
- `assets/css/admin.css` — style de l’aperçu  
- `class-wc-optic-catalog.php` — fragment par défaut = nom à l’insertion  
- `class-wc-optic-admin-import.php` — logique d’import  
- `class-wc-optic-sku.php` / `class-wc-optic-ajax.php` — déjà alignés sur le nom

**À faire de votre côté :** recharger la page **Optic Settings**, puis **Save changes** sur les listes existantes (ex. Signs `+` / `-`) pour que le fragment en base corresponde au nom affiché. Les anciennes lignes peuvent encore avoir un fragment vide ou différent jusqu’à cette sauvegarde.

---

**User**

pour tous les éléments, le fragment SKU est à saisir

---

**Cursor**

Rendant le fragment SKU saisissable pour chaque entrée du catalogue, indépendamment du nom.



Rendant le fragment SKU éditable et obligatoire ; le SKU produit utilisera la valeur saisie dans ce champ.



Le fragment SKU est maintenant **saisissable** pour chaque entrée, sur tous les onglets.

## Interface admin

| Colonne | Rôle |
|--------|------|
| **Name** | Libellé affiché (listes produit, admin) |
| **SKU fragment** | Champ texte **éditable** — valeur utilisée dans le SKU produit |
| **Sort** | Ordre d’affichage |
| **Actions** | Suppression |

- Plus de copie automatique nom → fragment.
- Les deux champs sont **obligatoires** (`required`) pour enregistrer une ligne.
- Si une ligne n’a que le nom ou que le fragment, elle est ignorée avec un avertissement.

## Génération SKU

`WC_Optic_SKU::catalog_term_sku_part()` utilise en priorité le **fragment saisi** (ex. `+`, `-`, codes courts), puis le nom en secours pour les anciennes données.

## Import

La colonne **SKU fragment** est de nouveau disponible dans le mapping ; les lignes sans fragment sont ignorées.

## Signs `+` / `-`

Saisir par exemple :
- **Name** : `Plus` / **SKU fragment** : `+`
- **Name** : `Moins` / **SKU fragment** : `-`

Pensez à **Save changes** sur chaque onglet pour mettre à jour les entrées existantes.

---

**User**

let's work on importation
i want to make import to each tab, for example, in import page, i want tabs and in each tab, file upload excel div and download template
the template should have header (Name, Sku Fragment)
and the data should start from line 2

---

**Cursor**

Examinant la page d'import actuelle pour la restructurer avec des onglets par type de catalogue.



Refondant la page d'import : onglets par type de catalogue, modèle Excel/CSV (Name, SKU Fragment) et import à partir de la ligne 2.



La page **Optic Import** a été refaite selon votre demande.

## Nouvelle page d’import

**WooCommerce → Optic Import** avec **un onglet par liste** (Sections, Companies, Brands, Timings, Colors, Signs, RX, Packs, Transparency).

Sur chaque onglet :

1. **Download template** — fichier modèle avec :
   - **Ligne 1** : `Name` | `SKU Fragment`
   - **Ligne 2+** : vos données (le modèle ne contient que l’en-tête)
2. **Upload** — `.xlsx` ou `.csv`
3. **Import into this list** — import uniquement dans l’onglet actif (plus de mapping de colonnes)

## Format attendu

| Colonne A | Colonne B |
|-----------|-----------|
| Name | SKU Fragment |
| Valeur 1 | Fragment 1 |
| … | … |

La **ligne 1 est ignorée** (en-têtes). L’import commence à la **ligne 2**.

## Modèle Excel

- Si **PhpSpreadsheet** est installé (`composer install` dans le plugin) → téléchargement **`.xlsx`**
- Sinon → téléchargement **`.csv`** avec les mêmes en-têtes

## Autres détails

- Lien **Import** dans Optic Settings ouvre l’import sur **l’onglet courant**
- Journal des imports en bas de page (inchangé)
- Doublons (même clé interne dérivée du nom) → ligne ignorée
- Nom ou fragment vide → ligne ignorée

Fichier principal : `includes/admin/class-wc-optic-admin-import.php`

---

**User**

pour les puissances, ils sont comme suit:
SPH, CYL, AXE, ADD
alors dissocier RX en ces valeurs, aussi en tabs et imports séparés

---

**Cursor**

Examinant l'utilisation de « RX » dans le catalogue et sur les produits pour le remplacer par SPH, CYL, AXE et ADD.



Remplaçant RX par quatre listes catalogue (SPH, CYL, AXE, ADD) et centralisant les libellés.



Voici ce qui a été modifié.

## RX remplacé par 4 listes de puissances

L’ancien onglet **RX** est remplacé par **4 types catalogue** distincts, chacun avec son onglet dans **Optic Settings** et **Optic Import** :

| Type | Libellé |
|------|---------|
| `sph` | SPH |
| `cyl` | CYL |
| `axe` | AXE |
| `add` | ADD |

Ordre des onglets : … Signs → **SPH → CYL → AXE → ADD** → Packs → Transparency.

## SKU produit

Le SKU dynamique inclut maintenant **4 segments** à la place d’un seul RX :

`section + company + brand + timing + color + sign + **sph + cyl + axe + add** + pack + transparency`

Sur la fiche produit (**Optic configuration**), vous avez **4 listes déroulantes** (SPH, CYL, AXE, ADD) au lieu d’une seule RX.

## Prescription client (divisions)

Pour **SAMA Color** et **Astigmatism Toric**, le champ affiché/saisi est **AXE** (plus `axis` en interne), aligné avec le catalogue.

## Fichiers touchés

- `class-wc-optic-catalog.php` — types + libellés centralisés (`get_type_label`, `get_power_types`)
- `class-wc-optic-sku.php` — métas `_optic_cat_sph`, `_cyl`, `_axe`, `_add`
- `class-wc-optic-plugin.php` — divisions avec `axe`
- `class-wc-optic-admin-settings.php` / `class-wc-optic-admin-import.php` — onglets via le catalogue
- `class-wc-optic-admin-product.php` — libellés produit

## Import

Chaque puissance a son **template** et son **import** dédiés (Name + SKU Fragment, données à partir de la ligne 2), comme pour Sections ou Brands.

## Données existantes

- Les entrées encore en base avec `term_type = rx` **n’apparaissent plus** dans l’admin (onglet supprimé). Il faudra les recréer dans SPH / CYL / AXE / ADD ou les migrer manuellement en SQL si besoin.
- Les produits qui utilisaient `_optic_cat_rx` doivent être **reconfigurés** avec les 4 nouveaux champs SKU.

Souhaitez-vous un script de migration automatique des anciennes lignes `rx` vers l’une des 4 listes ?

---

**User**

c'est AXIS et non pas AXE

---

**Cursor**

Remplaçant « AXE » par « AXIS » partout dans le plugin.



Corrigé : partout c’est **AXIS** (et le type interne `axis`), plus **AXE**.

## Modifications

- **Catalogue** : type `axis`, libellé **AXIS**
- **SKU produit** : méta `_optic_cat_axis`
- **Divisions** (SAMA Color, Astigmatism Toric) : champ prescription `axis` → affiché **AXIS** sur la fiche produit
- **Onglets** Réglages / Import : onglet **AXIS** (templates `optic-import-axis.xlsx`)

## Migration automatique

Au prochain chargement admin, si vous aviez déjà des données en `axe` :

- lignes catalogue `term_type = 'axe'` → `axis`
- méta produit `_optic_cat_axe` → `_optic_cat_axis`

Rechargez une page WordPress admin pour déclencher la mise à jour (schéma v3).

---

**User**

pour le sign, il n'est pas nécessaire, je vais entrer les puissances avec leurs signes respectives

---

**Cursor**

Suppression du type catalogue « Sign » : les signes seront inclus directement dans les valeurs SPH, CYL, AXIS et ADD.



Corrigé : le type **Sign** a été retiré du catalogue.

## Ce qui change

- Plus d’onglet **Signs** dans **Optic Settings** ni **Optic Import**
- Plus de liste **Sign** dans la configuration SKU du produit
- Le SKU est construit ainsi :  
  `section + company + brand + timing + color + sph + cyl + axis + add + pack + transparency`

Les signes **+** / **−** se gèrent dans les listes **SPH, CYL, AXIS, ADD** (nom et fragment SKU), par exemple fragment `+2.50` ou nom `+2.50`.

## Données déjà en base

Les anciennes entrées `sign` et la méta produit `_optic_cat_sign` restent en base mais ne sont plus utilisées. Vous pouvez les supprimer manuellement ou les laisser ; elles n’impactent plus le plugin.

Pensez à **réenregistrer** les produits optiques dont le SKU incluait un signe catalogue, pour recalculer le SKU sans ce segment.

---

**User**

@wp-content/plugins/Optic-Lenses/cursor_woocommerce_optic_product_plugin.md take this transcript to continue plugin construction

---

**Cursor**

Reprise du développement à partir du transcript. Côté boutique, le formulaire prescription utilisait déjà `get_powers_for_division()` ; le point restant était l’**admin produit**, où les 4 champs puissance SKU (SPH, CYL, AXIS, ADD) s’affichaient tous en permanence.

---

**User**

pour les divisions, afficher uniquement les champs puissance correspondants dans l’admin produit

---

**Cursor**

Implémentation de l’affichage conditionnel des champs SPH, CYL, AXIS et ADD dans l’admin produit selon la division sélectionnée.

Voici ce qui a été modifié.

## Admin produit (Optic configuration)

Quand vous changez **Optical division**, seuls les champs puissance SKU correspondants restent visibles :

| Division | Champs visibles |
|----------|-----------------|
| Color lenses | SPH |
| SAMA Color Lenses | SPH, CYL, AXIS |
| Astigmatism Toric | SPH, CYL, AXIS |
| Multifocal Bifocal | SPH, ADD |

- Les autres champs (CYL, AXIS, ADD, etc.) sont **masqués** et leur valeur est **vidée** automatiquement.
- À l’enregistrement, les métas puissance non utilisées sont remises à **0**.
- L’aperçu SKU et le SKU enregistré **ignorent** les puissances hors division.

## Génération SKU

`WC_Optic_SKU::build_for_product()` et `build_from_catalog_ids()` (preview AJAX) ne concatènent que les segments puissance autorisés pour la division du produit.

## Fichiers modifiés

- `includes/admin/class-wc-optic-admin-product.php` — classes CSS sur les champs puissance, texte d’aide, sauvegarde filtrée
- `assets/js/admin-product.js` — affichage dynamique au changement de division
- `includes/class-wc-optic-sku.php` — génération SKU selon la division
- `includes/class-wc-optic-ajax.php` — preview SKU avec la division (`optic_division` en POST)
- `assets/css/admin.css` — masquage par défaut des champs puissance (`.wc-optic-sku-power { display: none; }`)

## Boutique

Le formulaire prescription utilisait déjà `get_powers_for_division()` : le comportement côté client était déjà conforme au PRD.

---

**User**

stretch selects to full width section, now they are smal

---

**Cursor**

Les listes du panneau **Optic configuration** passent en pleine largeur.

## Modifications

- **`form-field-wide`** sur la division et tous les champs SKU (comportement WooCommerce : label au-dessus, select à 100 %).
- **CSS** : conteneurs Select2 forcés à `width: 100%` dans `#optic_product_data_panel`.
- **JS** : initialisation SelectWoo sur tous les `select.wc-enhanced-select` du panneau, y compris la division.

## Fichiers modifiés

- `includes/admin/class-wc-optic-admin-product.php` — `wrapper_class => form-field-wide`
- `assets/css/admin.css` — styles pleine largeur Select2
- `assets/js/admin-product.js` — `initSelectWoo()` + recalcul largeur après changement de division

---

**User**

make them select2

---

**Cursor**

Les listes du panneau **Optic configuration** utilisent maintenant **Select2** (SelectWoo WooCommerce) avec recherche.

## Comportement

- Champs en **Select2** : division + tous les composants SKU
- **Recherche** toujours disponible (`minimumResultsForSearch: 0`) — utile pour les longues listes SPH/CYL/AXIS/ADD
- **Placeholder** « — Select — » et bouton pour effacer la sélection (`allowClear: true`)
- **Réinitialisation** quand vous ouvrez l’onglet Optic ou changez le type de produit (le panneau est masqué au chargement, ce qui bloquait l’init WooCommerce par défaut)
- Champs puissance masqués : Select2 est détruit puis recréé quand la division les réaffiche

## Fichiers modifiés

- `includes/admin/class-wc-optic-admin-product.php` — classe `wc-optic-select2`, `data-placeholder`, script `wc-enhanced-select`
- `assets/js/admin-product.js` — initialisation SelectWoo complète (`destroySelect2`, `initSelect2`, `getSelect2Language`)
- `assets/css/admin.css` — style Select2 dans le panneau

---

**User**

now let's add its singular price

---

**Cursor**

Ajout du **prix unitaire** dans l’admin Optic, synchronisation avec le prix WooCommerce, et affichage du total estimé sur la fiche produit.

## Admin — Optic configuration

- Champ **Unit price** (prix unitaire / lentille), champ `_optic_unit_price`
- Synchronisé avec le prix WooCommerce (`_regular_price` ; `_price` si pas de prix promo)
- Les prix promo de l’onglet Général restent possibles ; le prix actif affiché au client inclut la promo

## Boutique

- Affichage **Unit price** et **Estimated total** dans le bloc `.wc-optic-pricing`
- Le total se met à jour en JS quand la quantité change (simple ou par œil : L + R)
- Paramètres de formatage passés via `wcOpticFront` (symbole devise, séparateurs, décimales)

## Panier / commande

- Total ligne = **prix unitaire × quantité** (WooCommerce standard)
- `WC_Optic_Pricing::add_to_cart_quantity` — quantité ligne = quantité simple **ou** `OS + OD` en mode « quantité par œil »
- Blocage add-to-cart si aucun prix unitaire n’est défini
- Cache de parsing dans `WC_Optic_Cart::$parse_cache` + méthode publique `parse_request_for_product()` pour le pricing

## Classe pricing

`class-wc-optic-pricing.php` n’est plus vide :

- `get_unit_price()` — prix actif (incl. promo)
- `calculate_line_total()` — unit price × qty
- `add_to_cart_quantity()` — filtre WooCommerce

## Fichiers modifiés

- `includes/admin/class-wc-optic-admin-product.php`
- `includes/class-wc-optic-pricing.php`
- `includes/class-wc-optic-cart.php`
- `includes/class-wc-optic-frontend.php` — localisation `wcOpticFront`
- `templates/single-product/add-to-cart/optic_product.php`
- `assets/js/frontend.js` — `formatPrice()`, `updateLineTotal()`
- `assets/css/frontend.css` — styles `.wc-optic-pricing`

---

**User**

PHP Fatal error: class "WC_Optic_Database" not found on plugin activation

---

**Cursor**

## Cause

`register_activation_hook()` était configuré sur `array( 'WC_Optic_Database', 'activate' )`, mais **`WC_Optic_Database` n’était chargée que via l’autoloader**, enregistré dans `wc_optic_product_init()` sur `plugins_loaded`.

À l’activation, WordPress exécute le callback **avant** `plugins_loaded` → la classe n’existait pas → callback invalide.

## Correctif

Chargement explicite de `includes/class-wc-optic-database.php` **avant** `register_activation_hook()` dans `woocommerce-optic-product.php` :

```php
require_once WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-autoload.php';
// Activation runs before plugins_loaded; autoloader is not registered yet.
require_once WC_OPTIC_PLUGIN_DIR . 'includes/class-wc-optic-database.php';

register_activation_hook( __FILE__, array( 'WC_Optic_Database', 'activate' ) );
```

---

## État actuel du plugin (synthèse post-modifications)

### Catalogue global (`wc_optic_catalog`)

Types actifs : **Sections, Companies, Brands, Timings, Colors, SPH, CYL, AXIS, ADD, Packs, Transparency** (plus de **Sign** ni **RX**).

Chaque entrée : **Name**, **SKU fragment** (saisissable), **Sort**. Slug interne uniquement (via `WC_Optic_Catalog::sanitize_slug()`, conserve `+` et `-`).

### SKU produit

Format : `section + company + brand + timing + color + sph + cyl + axis + add + pack + transparency`

Les segments puissance hors division sont vides. Fragment SKU prioritaire sur le nom (`catalog_term_sku_part()`).

### Admin

- **Optic Settings** : onglets par type, bouton **Add new**, sauvegarde en masse, suppression AJAX (icône poubelle) + journal `wp_wc_optic_catalog_deletion_log`
- **Optic Import** : un onglet par type, template Name + SKU Fragment (ligne 1 = en-têtes, données à partir de la ligne 2)
- **Optic configuration** (fiche produit) : division, prix unitaire, qty par œil par défaut, composants SKU en Select2 pleine largeur, champs puissance conditionnels, preview SKU live

### Schéma BDD

`wc_optic_db_schema` = **3** — migrations : v2 journal suppressions, v3 `axe` → `axis` (catalogue + `_optic_cat_axis`).

### Boutique (frontend)

- Prescription : champs texte par puissance de division (SPH, CYL, AXIS, ADD selon division)
- Helper `WC_Optic_Frontend::render_power_select()` présent (listes catalogue SelectWoo) — **pas encore branché** dans `optic_product.php` ; les puissances client restent en saisie libre pour l’instant
- Prix unitaire + total estimé dynamique
- Quantité WooCommerce masquée, synchronisée en JS (`#wc_optic_line_quantity`)

### À faire (prochaines étapes possibles)

- Brancher les listes catalogue SPH/CYL/AXIS/ADD sur le formulaire prescription client (`render_power_select` + validation `get_valid_term` côté panier)
- WPML : traduction des noms catalogue et libellés dynamiques
- Migration automatique des anciennes données `term_type = rx` / `sign` si encore en base

