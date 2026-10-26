# Magento2 MCP - Hyvä Commerce Tools

This is a sub-module for the [Magento2 MCP module](https://github.com/magebitcom/magento2-mcp-module)

----

Hyvä Commerce 1.4 MCP tools for `Magebit_Mcp`: Hyvä CMS pages, blocks, drafts, versions, components, scheduling,
translations, Page Builder migration and import/export; Menu Builder; Media Gallery and Media Optimization; Form
Builder; Email & Newsletter Templates; Store Credit; Linked Products; Category Merchandiser; read-only AI Worker,
Advanced Admin Permissions and Admin Dashboard tools; audits; and Hyvä Admin Dashboard widgets that surface MCP
activity and content health. See [Hyvä Commerce 1.4 coverage](#hyvä-commerce-14-coverage) for what each module gets.

Content writes go through the same Hyvä services the Liveview editor uses
(`Hyva\CmsLiveviewEditor\Model\Provider`), always as a **draft**. Only the publish tools (`hyva_cms.publish`,
`hyva_menu.publish`, `hyva_form.form.publish`, `hyva_email.*.publish`) make content live.

## Requirements

- `magebitcom/magento2-mcp-module` ^1.3
- Hyvä Commerce 1.4: Hyvä CMS 1.3 (`hyva-themes/commerce-module-cms`, with Media Gallery) and the Hyvä Admin Dashboard
  (`hyva-themes/commerce-module-admin-dashboard`, `hyva-themes/commerce-module-admin-dashboard-api`)
- Optional, detected at runtime (see the coverage table for the tools each one enables):
  - `hyva-themes/commerce-module-menu-builder`, `commerce-module-media-optimization`, `commerce-module-image-editor`
  - `hyva-themes/commerce-module-form-builder`, `commerce-module-email-templates`, `commerce-module-store-credit`,
    `commerce-module-linked-products`, `commerce-module-category-merchandiser`, `commerce-module-cms-ai-translations`,
    `commerce-module-ai-worker`, `commerce-module-advanced-admin-permissions`

## Install

```bash
composer require magebitcom/magento2-mcp-hyva-commerce-tools
bin/magento module:enable Magebit_McpHyvaCommerceTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Safety model

- **Write gate.** Write tools need the global `magebit_mcp/general/allow_writes` flag **and** a token with writes, and
  every write sets `requires_confirmation`.
- **Drafts only.** Page, block, menu, migration, import, image-replace and alt-text tools save drafts
  (`saveContent(..., publish: false)`) plus a version-history entry. The storefront changes only after
  `hyva_cms.publish` (or a scheduled release). `settings` arguments (title, active flag, store views, meta) are Magento
  fields and apply immediately, so a setting that changes the live storefront needs `"confirm": true`: the active flag,
  store views and URL key / identifier always, and any other setting of an active page, block or menu (a menu's
  `preview_url_key` only picks the page the editor previews it on and never needs confirm). Without `confirm` nothing is
  written (not even the draft part of the call) and the reply lists each change with its storefront effect.
  `hyva_cms.page.create` / `hyva_cms.block.create` with `is_active=true` need `"confirm": true`
  too: without it nothing is created and the reply shows what would go live. An inactive create needs no confirm,
  whatever its identifier and store views, because an inactive page or block is not served.
- **Explicit confirm.** Creating an active page or block, live `settings` changes (above), replacing unpublished draft changes, removing a menu item with
  children, a global image replacement and clearing every optimized image need `"confirm": true`; without it the tool
  returns a dry-run preview of what would change.
- **ACL (Hyvä Commerce 1.4).** A call passes three checks, all against the token's admin role:
  1. the tool's own resource under *System → MCP → MCP Tools → Hyvä Commerce Tools*;
  2. the Hyvä resource in the "Listed with" column (e.g. `Hyva_Cms::page_view`), which also hides the tool from
     `tools/list`;
  3. for tools that take `entity_type`, the per-type verb from Hyvä's `ContentTypeAuthorization` for that call
     ("Checked per call"), e.g. `hyva_cms.publish` on a page needs `Hyva_Cms::page_publish`, on a menu
     `Hyva_MenuBuilder::menu_publish`. These tools are listed for any role with Hyvä CMS access and refused per call
     with `-32004`, because `tools/list` has no arguments to decide on.
  Reading a version needs the type's `version_history` verb as well: `fields` with `versions` on the get tools,
  `version_id` on preview, publish and schedule create, `source=version` on draft create and `include_versions` on
  export. A get without `fields` returns `versions: null` to a caller without that permission.
  Hyvä 1.4 also checks permissions inside its own services (e.g. version restore); the module gives Magento
  authorization the MCP caller's role while a tool runs, so those checks see the same role.
- **Store scope (Advanced Admin Permissions).** Hyvä's scope guards run only in the admin and the web API, not on the
  `/mcp` route, so the module applies the MCP admin's website / store view restriction itself. CMS pages, blocks,
  menus and forms outside the role's store views are hidden from the lists, and every tool that reads or changes
  one refuses them (status 403). Changing one, or assigning store views, needs every store view it is assigned to;
  store view 0 ("All Store Views") needs all of them, as in the Hyvä admin. Menus and forms are scoped by their store
  views although the Hyvä admin does not scope them. Email and newsletter templates, linked product groups and
  category positions belong to no store view: a restricted role can read them but not change them.
  `image.replace` and `image.update_alt` skip content outside the role, `schedule.cancel` refuses items of content
  outside it, and store credit is checked against the customer's website. `schedule.list` and
  `schedule.detect_conflicts` (and the conflicts `schedule.create` returns) hide items and conflicts of content outside
  the role, including content that no longer exists, and count them in `items_hidden_by_permissions` /
  `conflicts_hidden_by_permissions`; `hyva_form.delivery.audit` without a form audits only the role's forms
  (`forms_hidden_by_permissions`); `migration.scan` lists only the role's pages and blocks.
  Usage lists, previews and audits (`hyva_media.image.find_usage`, `image.list`, `image.validate`, `asset.resolve`, the
  `asset.move` / `asset.trash` previews, the `usage` field of `image.get`, `cms_block.get` and `hyva_menu.get`,
  `hyva_menu.find_usage`, the `hyva_commerce.audit.*`, `hyva_media.audit*` and `hyva_menu.audit_all` audits, the forms
  lens of `audit.overview`) leave out pages, blocks, menus and widget instances outside the role's store views, products
  outside its websites and categories outside its store views' category trees, as Hyvä's guards do, and count them in
  `*_hidden_by_permissions`. An image or menu used only there still counts as used. A product assigned to no website
  stays listed, as in the Hyvä admin. The dashboard widgets apply the logged-in admin's restriction, with a separate
  widget cache entry per restriction. Not filtered by scope: Media Gallery files, folders, trash and sync status (the
  Hyvä Media Gallery has no scope), configuration values (menu placements, email assignments in
  `hyva_email.audit.coverage`, store credit websites in `audit.overview`), email and newsletter templates, and the MCP
  audit log (MCP Activity widget, which needs `Magebit_Mcp::mcp_audit`). Menu Health and Media Health show MCP activity
  and the audit log link only with that permission.
- **Refusals.** Hyvä's save refusals come back as soft errors with a `status`: 422 with `field_errors` (per component
  uid and field) when a provider refuses content or Hyvä's content guard rejects it (3 MB, 5000 components, depth 50),
  409 when the draft changed since you read it (pass `base_version_uid` to the update tools to get this check; reload
  and re-apply, never resubmit old content), 403 when Hyvä refuses a permission.

### Role setup

Give a role the Hyvä permissions that match what the agent should do; existing roles are not migrated from
`Magento_Backend::content`:

| Agent should… | Grant (besides the MCP tool resources) |
|---|---|
| read pages, blocks, menus, images | `Hyva_Cms::cms`, `Hyva_Cms::page_view`, `Hyva_Cms::block_view`, `Hyva_MenuBuilder::menu_view`, `Hyva_MediaGallery::media` + `asset_view` |
| see version history | + `Hyva_Cms::page_version_history`, `Hyva_Cms::block_version_history`, `Hyva_MenuBuilder::menu_version_history` |
| create and edit drafts | + `Hyva_Cms::page_save`, `Hyva_Cms::block_save`, `Hyva_MenuBuilder::menu_save` |
| publish | + `Hyva_Cms::page_publish`, `Hyva_Cms::block_publish`, `Hyva_MenuBuilder::menu_publish` |
| schedule | + `*_scheduling`, `*_schedule_save`, `*_schedule_cancel` |
| export / import | + `*_export` / `*_save` and `Hyva_Cms::transfer` (Transfer Center) |
| clear optimized images | + `Hyva_MediaGallery::asset_save` |
| manage Media Gallery files | + `Hyva_MediaGallery::asset_save` (move), `asset_delete` (trash, restore), `folder_save` |
| forms | `Hyva_FormBuilder::form_view`, `form_save`, `form_publish`, `form_version_history` |
| emails and newsletters | `Hyva_EmailTemplates::email_template_*` / `newsletter_template_*` (`view`, `save`, `publish`) |
| translate CMS content | + `Hyva_CmsAiTranslations::translate` (and `*_save` to write the draft) |
| store credit | `Hyva_StoreCredit::balance_view`; `balance_adjust` to credit or debit |
| linked product groups | `Hyva_LinkedProducts::group_view`; `group_save` to change them |
| reorder category products | `Magento_Catalog::categories` (not for website-restricted roles: positions are global) |
| monitor AI Worker | `Hyva_AiWorker::manage` (own jobs), `::jobs` (all jobs, usage, errors), `::config` (status) |
| read admin permissions | `Magento_User::acl_users`; `Hyva_AdvancedAdminPermissions::audit` for the audit log |
| list dashboard views and widgets | `Hyva_AdminDashboardFramework::dashboard` |

A role without `Hyva_Cms::cms` sees none of the Hyvä CMS, menu or media tools. Roles restricted to some websites by
Advanced Admin Permissions are held to them by the store credit tools too (Hyvä's own store credit services do not
check scope).

## Optional features

Tools for an optional Hyvä module stay registered when that module is missing, disabled or not allowed for the token's
admin role. They answer with a soft error (`isError: true`) instead of failing:

```json
{"available": false, "feature": "menu_builder", "reason": "Hyvä Menu Builder is installed but disabled (Hyva_MenuBuilder).", "hint": "Run bin/magento module:enable Hyva_MenuBuilder and setup:upgrade."}
```

Call `hyva_commerce.audit.overview`, `hyva_menu.status` or `hyva_media.status` first to see what the store supports.
Only `Model/Adapter/*` reference `Hyva\MenuBuilder\*` / `Hyva\MediaOptimization\*`; tools depend on the
`Api/Adapter/*GatewayInterface` contracts, wired to generated proxies so the real gateway is built only after the
feature check.

## Hyvä Commerce 1.4 coverage

Hyvä Commerce 1.4.0 (`hyva-themes/commerce` 1.4.0) and what this module offers for each part. Counts are tools.

| Module (version) | Tools | Notes |
|---|---|---|
| Hyvä CMS 1.3.1 (Liveview editor, Connect, Admin Styles) | `hyva_cms.*` (27) | Pages, blocks, drafts, versions, scheduling, components, export/import, PageBuilder migration, preview, `permission.check`; per-type verbs from `ContentTypeAuthorization` |
| CMS AI Translations 1.0.2 | `hyva_cms.translation.*` (2) | Status per store view; run returns proposals and can write the draft, never publishes |
| Menu Builder 1.1.0 | `hyva_menu.*` (20) | Includes publish, versions and schedules (1.1 per-verb permissions) |
| Media Gallery 1.0.0 | `hyva_media.asset.*`, `trash.*`, `folder.create`, `sync.status`, `audit.literal_urls` (9) | Asset ids in content (`imageOptions.id`), move, trash/restore, sync drift, literal-URL audit |
| Media Optimization 1.1.0 | `hyva_media.optimization.*` (5) | Unchanged in 1.4 |
| Image usage and audits (CMS + Media Gallery) | `hyva_media.image.*`, `hyva_media.audit_*`, `hyva_media.status` (10) | Find, replace and fix images across CMS content and menus |
| Form Builder 1.0.1 | `hyva_form.*` (9) | Forms, drafts, publish, versions, delivery audit (recipients, webhook) |
| Email & Newsletter Templates 1.0.0 | `hyva_email.*` (14) | Transactional emails and newsletters: drafts, publish, render preview, test send, coverage audit |
| Store Credit 1.0.1 | `hyva_store_credit.*` (5) | Balances, history, credit/debit with preview and idempotency key. Its GraphQL API is for shoppers and has no admin use here |
| Linked Products 1.0.0 | `hyva_linked.*` (4) | Groups, validation with storefront warnings, save with page cache refresh |
| Category Merchandiser 1.0.0 | `hyva_merch.category.*` (2) | Reorder only. The Enterprise package is not installed. Reports when another module (ElasticSuite) owns storefront sorting |
| AI Worker 1.0.0 and AI Worker CMS 1.0.0 | `hyva_ai.*` (5) | Monitoring only (status, jobs, usage, errors); AI Worker CMS jobs show in `hyva_ai.job.list`. No generation by design |
| Advanced Admin Permissions 1.0.0 | `hyva_admin_perm.*` (2) | Read only (effective permissions, audit log). Scope restrictions are applied by the module itself, see [Store scope](#safety-model) |
| Admin Dashboard 2.0.3 (+ API 3.0.1, CMS widgets, CrUX history widget) | `hyva_dashboard.*` (3) + [widgets](#dashboard-widgets) | Views and widget types as the caller sees them; MCP activity and content health widgets |
| Audits | `hyva_commerce.audit.*` (4) | `audit.overview` adds one health line per 1.4 module |

Not covered, on purpose:

| Module (version) | Why |
|---|---|
| Admin Theme (`commerce-theme-adminhtml` 1.1.1, `commerce-module-admin-theme` 1.0.1) | Visual admin theme only; nothing for an agent to read or change |
| Commerce Module 1.0.6 | Shared base and branding fixes (logo); no data of its own |
| Image Editor 1.1.0 | Interactive in-browser editor; its saved edits show in `hyva_media.image.get` |
| CMS Google Maps 1.0.0 | Only a CMS component; listed and validated by `hyva_cms.component.list` / `schema` / `validate` |
| CMS Tailwind JIT bridge 1.0.3 | Build plumbing for CMS styles; its daemon status is a dashboard widget |
| AI providers 1.3.0 (`magento2-ai-providers`: `magento2-module-ai` 1.3.1 with the Anthropic, DeepL, Gemini and OpenAI modules) | Provider credentials and model settings in the store configuration, no content of their own; the models they serve are used by `hyva_cms.translation.run`, and AI Worker usage and errors are covered by `hyva_ai.*` |

## Tool catalog

### Hyvä CMS

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_cms.block.create` | write | `Hyva_Cms::block_save` | copy_from.entity_type → view | Create Hyvä CMS Block |
| `hyva_cms.block.get` | read | `Hyva_Cms::block_view` | fields with versions → version_history | Get Hyvä CMS Block |
| `hyva_cms.block.list` | read | `Hyva_Cms::block_view` |  | List Hyvä CMS Blocks |
| `hyva_cms.block.update` | write | `Hyva_Cms::block_save` |  | Update Hyvä CMS Block |
| `hyva_cms.component.list` | read | `Hyva_Cms::cms` |  | List Hyvä CMS Components |
| `hyva_cms.component.schema` | read | `Hyva_Cms::cms` |  | Get Hyvä CMS Component Schema |
| `hyva_cms.component.validate` | read | `Hyva_Cms::cms` | entity_type → view | Validate Hyvä CMS Components |
| `hyva_cms.draft.create` | write | `Hyva_Cms::cms` | entity_type → save (+ version_history for source=version) | Create Hyvä CMS Draft |
| `hyva_cms.draft.update` | write | `Hyva_Cms::cms` | entity_type → save | Update Hyvä CMS Draft |
| `hyva_cms.export` | read | `Hyva_Cms::cms` | entity_type → export (+ version_history with include_versions) + `Hyva_Cms::transfer` | Export Hyvä CMS Content |
| `hyva_cms.import` | write | `Hyva_Cms::cms` | entity_type → save + `Hyva_Cms::transfer` | Import Hyvä CMS Content |
| `hyva_cms.migration.analyze` | read | `Hyva_Cms::cms` | entity_type → view | Analyze Legacy CMS Content |
| `hyva_cms.migration.convert_to_draft` | write | `Hyva_Cms::cms` | entity_type → save | Convert Legacy CMS Content to Draft |
| `hyva_cms.migration.scan` | read | `Hyva_Cms::cms` | entity_type → view | Scan Legacy CMS Content |
| `hyva_cms.page.create` | write | `Hyva_Cms::page_save` | copy_from.entity_type → view | Create Hyvä CMS Page |
| `hyva_cms.page.get` | read | `Hyva_Cms::page_view` | fields with versions → version_history | Get Hyvä CMS Page |
| `hyva_cms.page.list` | read | `Hyva_Cms::page_view` |  | List Hyvä CMS Pages |
| `hyva_cms.page.update` | write | `Hyva_Cms::page_save` |  | Update Hyvä CMS Page |
| `hyva_cms.permission.check` | read | `-` |  | Check Hyvä CMS Permissions |
| `hyva_cms.preview` | read | `Hyva_Cms::cms` | entity_type → view (+ version_history with version_id) | Preview Hyvä CMS Content |
| `hyva_cms.publish` | write | `Hyva_Cms::cms` | entity_type → publish (+ version_history with version_id) | Publish Hyvä CMS Content |
| `hyva_cms.schedule.cancel` | write | `Hyva_Cms::cms` | entity_type → schedule_cancel | Cancel Hyvä CMS Schedule |
| `hyva_cms.schedule.create` | write | `Hyva_Cms::cms` | entity_type → schedule_save (+ version_history with version_id) | Schedule Hyvä CMS Content |
| `hyva_cms.schedule.detect_conflicts` | read | `Hyva_Cms::cms` | entity_type → scheduling | Detect Hyvä CMS Schedule Conflicts |
| `hyva_cms.schedule.list` | read | `Hyva_Cms::cms` | entity_type → scheduling | List Hyvä CMS Schedules |
| `hyva_cms.translation.run` | write | `Hyva_Cms::cms` | entity_type → view + `Hyva_CmsAiTranslations::translate` | Run Hyvä CMS AI Translation |
| `hyva_cms.translation.status` | read | `Hyva_Cms::cms` | entity_type → view | Hyvä CMS Translation Status |
| `hyva_cms.version.list` | read | `Hyva_Cms::cms` | entity_type → version_history | List Hyvä CMS Versions |
| `hyva_cms.version.restore` | write | `Hyva_Cms::cms` | entity_type → save + version_history | Restore Hyvä CMS Version |

### Hyvä Menu Builder (optional)

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_menu.audit_all` | read | `Hyva_MenuBuilder::menu_view` |  | Audit All Hyvä Menus |
| `hyva_menu.category.import` | write | `Hyva_MenuBuilder::menu_save` |  | Import Categories into Hyvä Menu |
| `hyva_menu.category.sync_preview` | read | `Hyva_MenuBuilder::menu_view` |  | Preview Category Import into Hyvä Menu |
| `hyva_menu.create` | write | `Hyva_MenuBuilder::menu_save` |  | Create Hyvä Menu |
| `hyva_menu.find_usage` | read | `Hyva_MenuBuilder::menu_view` |  | Find Hyvä Menu Usage |
| `hyva_menu.get` | read | `Hyva_MenuBuilder::menu_view` | fields with versions → version_history | Get Hyvä Menu |
| `hyva_menu.item.add` | write | `Hyva_MenuBuilder::menu_save` |  | Add Hyvä Menu Item |
| `hyva_menu.item.remove` | write | `Hyva_MenuBuilder::menu_save` |  | Remove Hyvä Menu Item |
| `hyva_menu.item.update` | write | `Hyva_MenuBuilder::menu_save` |  | Update Hyvä Menu Item |
| `hyva_menu.list` | read | `Hyva_MenuBuilder::menu_view` |  | List Hyvä Menus |
| `hyva_menu.preview` | read | `Hyva_MenuBuilder::menu_view` | version_id → version_history | Preview Hyvä Menu |
| `hyva_menu.publish` | write | `Hyva_MenuBuilder::menu_publish` | entity_type → publish (+ version_history with version_id) | Publish Hyvä Menu |
| `hyva_menu.render_check` | read | `Hyva_MenuBuilder::menu_view` |  | Check Hyvä Menu Rendering |
| `hyva_menu.schedule.cancel` | write | `Hyva_MenuBuilder::menu_schedule_cancel` | entity_type → schedule_cancel | Cancel Hyvä Menu Schedule |
| `hyva_menu.schedule.create` | write | `Hyva_MenuBuilder::menu_schedule_save` | entity_type → schedule_save (+ version_history with version_id) | Schedule Hyvä Menu |
| `hyva_menu.status` | read | `Hyva_Cms::cms` |  | Menu Builder Status |
| `hyva_menu.update` | write | `Hyva_MenuBuilder::menu_save` |  | Update Hyvä Menu |
| `hyva_menu.validate` | read | `Hyva_MenuBuilder::menu_view` |  | Validate Hyvä Menu |
| `hyva_menu.version.list` | read | `Hyva_MenuBuilder::menu_version_history` | entity_type → version_history | List Hyvä Menu Versions |
| `hyva_menu.version.restore` | write | `Hyva_MenuBuilder::menu_save` | entity_type → save + version_history | Restore Hyvä Menu Version |

### Hyvä Media (optimization tools need Hyva_MediaOptimization)

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_media.asset.move` | write | `Hyva_MediaGallery::asset_save` |  | Move Media Gallery Assets |
| `hyva_media.asset.resolve` | read | `Hyva_MediaGallery::asset_view` |  | Resolve Media Gallery Asset |
| `hyva_media.asset.search` | read | `Hyva_MediaGallery::asset_view` |  | Search Media Gallery Assets |
| `hyva_media.asset.trash` | write | `Hyva_MediaGallery::asset_delete` |  | Trash Media Gallery Assets |
| `hyva_media.audit.literal_urls` | read | `Hyva_MediaGallery::asset_view` |  | Audit Literal Media URLs |
| `hyva_media.audit_all` | read | `Hyva_MediaGallery::asset_view` |  | Audit Storefront Media |
| `hyva_media.audit_cms` | read | `Hyva_MediaGallery::asset_view` |  | Audit Hyvä CMS Media |
| `hyva_media.audit_menu` | read | `Hyva_MediaGallery::asset_view` |  | Audit Menu Media |
| `hyva_media.folder.create` | write | `Hyva_MediaGallery::folder_save` |  | Create Media Gallery Folder |
| `hyva_media.image.find_usage` | read | `Hyva_MediaGallery::asset_view` |  | Find Media Image Usage |
| `hyva_media.image.get` | read | `Hyva_MediaGallery::asset_view` |  | Get Media Image |
| `hyva_media.image.list` | read | `Hyva_MediaGallery::asset_view` |  | List Media Images |
| `hyva_media.image.replace` | write | `Hyva_MediaGallery::asset_view` | entity_type → save | Replace Media Image |
| `hyva_media.image.update_alt` | write | `Hyva_MediaGallery::asset_view` | entity_type → save | Update Media Image Alt Text |
| `hyva_media.image.validate` | read | `Hyva_MediaGallery::asset_view` |  | Validate Media Image |
| `hyva_media.optimization.clear` | write | `Hyva_MediaGallery::asset_save` |  | Clear Optimized Images |
| `hyva_media.optimization.config_get` | read | `Magento_Cms::config_cms` |  | Get Media Optimization Config |
| `hyva_media.optimization.config_validate` | read | `Magento_Cms::config_cms` |  | Validate Media Optimization Config |
| `hyva_media.optimization.preview_picture` | read | `Hyva_MediaGallery::asset_view` |  | Preview Responsive Picture |
| `hyva_media.optimization.status` | read | `Hyva_MediaGallery::asset_view` |  | Media Optimization Status for Image |
| `hyva_media.status` | read | `Hyva_MediaGallery::asset_view` |  | Media Optimization Status |
| `hyva_media.sync.status` | read | `Hyva_MediaGallery::asset_view` |  | Media Gallery Sync Status |
| `hyva_media.trash.list` | read | `Hyva_MediaGallery::asset_view` |  | List Media Gallery Trash |
| `hyva_media.trash.restore` | write | `Hyva_MediaGallery::asset_delete` |  | Restore Media Gallery Trash |

### Hyvä Form Builder

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_form.delivery.audit` | read | `Hyva_FormBuilder::form_view` |  | Audit Hyvä Form Delivery |
| `hyva_form.form.create` | write | `Hyva_FormBuilder::form_save` |  | Create Hyvä Form |
| `hyva_form.form.get` | read | `Hyva_FormBuilder::form_view` |  | Get Hyvä Form |
| `hyva_form.form.list` | read | `Hyva_FormBuilder::form_view` |  | List Hyvä Forms |
| `hyva_form.form.publish` | write | `Hyva_FormBuilder::form_publish` |  | Publish Hyvä Form |
| `hyva_form.form.update_draft` | write | `Hyva_FormBuilder::form_save` |  | Update Hyvä Form Draft |
| `hyva_form.form.validate` | read | `Hyva_FormBuilder::form_view` |  | Validate Hyvä Form |
| `hyva_form.version.list` | read | `Hyva_FormBuilder::form_version_history` |  | List Hyvä Form Versions |
| `hyva_form.version.restore` | write | `Hyva_FormBuilder::form_save` |  | Restore Hyvä Form Version |

### Hyvä Email & Newsletter Templates

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_email.audit.coverage` | read | `Hyva_EmailTemplates::email_template_view` |  | Audit Hyvä Email Coverage |
| `hyva_email.newsletter.create_draft` | write | `Hyva_EmailTemplates::newsletter_template_save` |  | Create Hyvä Newsletter Template |
| `hyva_email.newsletter.get` | read | `Hyva_EmailTemplates::newsletter_template_view` |  | Get Hyvä Newsletter Template |
| `hyva_email.newsletter.list` | read | `Hyva_EmailTemplates::newsletter_template_view` |  | List Hyvä Newsletter Templates |
| `hyva_email.newsletter.preview` | read | `Hyva_EmailTemplates::newsletter_template_view` |  | Preview Hyvä Newsletter Template |
| `hyva_email.newsletter.publish` | write | `Hyva_EmailTemplates::newsletter_template_publish` |  | Publish Hyvä Newsletter Template |
| `hyva_email.newsletter.update_draft` | write | `Hyva_EmailTemplates::newsletter_template_save` |  | Update Hyvä Newsletter Template Draft |
| `hyva_email.template.create_draft` | write | `Hyva_EmailTemplates::email_template_save` |  | Create Hyvä Email Template |
| `hyva_email.template.get` | read | `Hyva_EmailTemplates::email_template_view` |  | Get Hyvä Email Template |
| `hyva_email.template.list` | read | `Hyva_EmailTemplates::email_template_view` |  | List Hyvä Email Templates |
| `hyva_email.template.preview` | read | `Hyva_EmailTemplates::email_template_view` |  | Preview Hyvä Email Template |
| `hyva_email.template.publish` | write | `Hyva_EmailTemplates::email_template_publish` |  | Publish Hyvä Email Template |
| `hyva_email.template.send_test` | write | `Hyva_EmailTemplates::email_template_view` |  | Send Hyvä Email Template Test |
| `hyva_email.template.update_draft` | write | `Hyva_EmailTemplates::email_template_save` |  | Update Hyvä Email Template Draft |

### Hyvä Store Credit

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_store_credit.balance.credit` | write | `Hyva_StoreCredit::balance_adjust` |  | Credit Store Credit |
| `hyva_store_credit.balance.debit` | write | `Hyva_StoreCredit::balance_adjust` |  | Debit Store Credit |
| `hyva_store_credit.balance.get` | read | `Hyva_StoreCredit::balance_view` |  | Get Store Credit Balance |
| `hyva_store_credit.history.list` | read | `Hyva_StoreCredit::balance_view` |  | List Store Credit History |
| `hyva_store_credit.status` | read | `Hyva_StoreCredit::balance_view` |  | Hyvä Store Credit Status |

### Hyvä Linked Products

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_linked.group.get` | read | `Hyva_LinkedProducts::group_view` |  | Get Linked Product Group |
| `hyva_linked.group.list` | read | `Hyva_LinkedProducts::group_view` |  | List Linked Product Groups |
| `hyva_linked.group.save` | write | `Hyva_LinkedProducts::group_save` |  | Save Linked Product Group |
| `hyva_linked.group.validate` | read | `Hyva_LinkedProducts::group_view` |  | Validate Linked Product Group |

### Hyvä Category Merchandiser

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_merch.category.positions_get` | read | `Magento_Catalog::categories` |  | Get Category Product Order |
| `hyva_merch.category.positions_set` | write | `Magento_Catalog::categories` |  | Reorder Category Products |

### Hyvä AI Worker (monitoring)

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_ai.error.list` | read | `Hyva_AiWorker::jobs` |  | List Hyvä AI Worker Errors |
| `hyva_ai.job.get` | read | `Hyva_AiWorker::manage` |  | Get Hyvä AI Worker Job |
| `hyva_ai.job.list` | read | `Hyva_AiWorker::manage` |  | List Hyvä AI Worker Jobs |
| `hyva_ai.usage.summary` | read | `Hyva_AiWorker::jobs` |  | Hyvä AI Worker Token Usage |
| `hyva_ai.worker.status` | read | `Hyva_AiWorker::config` |  | Hyvä AI Worker Status |

### Hyvä Advanced Admin Permissions (read only)

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_admin_perm.audit.list` | read | `Hyva_AdvancedAdminPermissions::audit` |  | List Admin Role Assignment Audit |
| `hyva_admin_perm.effective.get` | read | `Magento_User::acl_users` |  | Get Effective Admin Permissions |

### Audits and dashboard

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_commerce.audit.cms` | read | `Hyva_Cms::cms` | entity_type → view | Audit Hyvä CMS Content |
| `hyva_commerce.audit.media` | read | `Hyva_MediaGallery::asset_view` |  | Audit Hyvä Commerce Media |
| `hyva_commerce.audit.menu` | read | `Hyva_MenuBuilder::menu_view` |  | Audit Hyvä Commerce Menus |
| `hyva_commerce.audit.overview` | read | `-` |  | Audit Hyvä Commerce Installation |
| `hyva_dashboard.view.list` | read | `Hyva_AdminDashboardFramework::dashboard` |  | List Hyvä Dashboard Views |
| `hyva_dashboard.widget.list` | read | `Hyva_AdminDashboardFramework::dashboard` |  | List Hyvä Dashboard Widgets |
| `hyva_dashboard.widget.refresh` | write | `Hyva_AdminDashboardFramework::dashboard` |  | Refresh Dashboard Widget Data |

Tool ACL ids follow `Magebit_McpHyvaCommerceTools::tool_<name with dots replaced by underscores>`.
The MCP wire names use underscores (`hyva_cms_page_get`); `tools/call` accepts both forms.

## Prompts

| Prompt | Writes | What it does |
|---|---|---|
| `hyva_cms.landing_page_draft` | yes | Builds a campaign landing page as a draft from a short brief, validates it and returns the preview link. |
| `hyva_menu.health_check` | no | Checks every menu for broken links, disabled targets and empty sections, in plain language. |
| `hyva_media.audit_report` | no | Reports heavy images, missing alt text, missing files and WebP/AVIF setup. |

## Dashboard widgets

Category **Magebit / AI / MCP** (registered through `Hyva\AdminDashboardFramework\Model\Config\Widget\Converter`).
Each widget has its own ACL resource under *Dashboard → Magebit / AI / MCP Widgets*, caches its data for 300 s and
reuses the same services as the audit tools. Templates are static markup (no Alpine state), so they are CSP-safe.

| Widget | Shows |
|---|---|
| `magebit_mcp_activity_widget` | MCP calls, failures, writes (every registered write tool), AI-generated drafts (this module's tools that only save drafts: `create_draft`, `update_draft`, `convert_to_draft`, `update_alt`, and create/update/add/run on pages, blocks, drafts, menus, menu items, forms and translations), publish, live and destructive actions (every other write tool: publish, delete, trash, remove, clear, restore, import, replace, cancel, move, flush, reset, disable, and immediate changes such as `system.config.set`, the core CMS tools, category positions or store credit), link to the MCP audit log |
| `magebit_content_health_widget` | Missing alt text, broken images, invalid/disabled components, unpublished drafts, schedule problems, legacy pages |
| `magebit_menu_health_widget` | Menus with broken links, disabled targets, empty columns, placement, last MCP menu actions |
| `magebit_media_health_widget` | Oversized images, missing alt, broken references, missing WebP/AVIF, optimization and engine status |
| `magebit_campaign_calendar_widget` | Upcoming and recent scheduled releases, homepage/menu changes, conflicts |
| `magebit_migration_status_widget` | Hyvä vs Page Builder/WYSIWYG pages, complexity, converted drafts waiting |
| `magebit_campaign_asset_readiness_widget` | Media and link problems in the content of pending scheduled items |

`hyva_dashboard.widget.refresh` clears a widget's cached data by type or instance.

## Notes on Hyvä behaviour

- Hyvä scheduled releases are one-way: there is no end date. `hyva_cms.schedule.detect_conflicts` reports a
  `no_fallback` info when nothing restores the previous content after a campaign.
- JIT Tailwind CSS for content is compiled by the Liveview editor in the browser. Content written over MCP can use
  classes that are not compiled yet for the theme.
- Page and block saves run in emulated `adminhtml` area: in the frontend area (where `/mcp` runs) Hyvä's
  `Plugin\Model\Page::afterGetContent` fails for a page without an id.
- `hyva_media.optimization.clear` also removes conversions of resized variants (`converted/resized/<hash>`), which
  Hyvä's own cleaner leaves behind.
- Hyvä writers external to the editor must call `ImageIdBackfiller::backfill()` themselves (`Provider::saveContent`
  does not); the draft tools do, so images keep their Media Gallery asset id.
- Trashing deletes the Media Gallery asset row, so a restored file can come back with a new asset id; check content
  that used the old id with `hyva_media.audit.literal_urls` (`stale_asset_id`).
- An email template's subject and preheader, and a newsletter's sender, are not drafted: changing them affects sent
  emails at once. The email update tools say so and report `live_change`.
- Hyvä's AI translation joins texts with line breaks and splits the answer on line breaks. `hyva_cms.translation.run`
  collapses line breaks in HTML and skips plain multi-line texts so translations never land on the wrong field.
- Store credit: Hyvä's ledger returns the earlier entry when an idempotency key is reused, even for another
  customer or amount; the tools tell a replay from a clash (409). Hyvä's balance services ignore the enabled flag and
  never email; the tools refuse websites where store credit is off and can notify the customer.
- Linked Products: Hyvä's group saver cleans no cache; `hyva_linked.group.save` refreshes the product pages of old
  and new members.
- Category positions only reach the storefront when Magento's own catalog search sorts category pages. With
  ElasticSuite installed (as on the demo), it sorts by its own positions; the merchandiser tools say so.
- Several Hyvä Admin Dashboard services (view model, widget instance repository, widget auth) need the admin session or
  adminhtml-only configuration, so the dashboard tools read views and widgets through the explicit-id services, the
  widget configuration and the instance table.

## Development

```bash
vendor/bin/phpstan analyse app/code/Magebit/McpHyvaCommerceTools -c app/code/Magebit/McpHyvaCommerceTools/phpstan.neon
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Magebit/McpHyvaCommerceTools/Test/Unit
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml app/code/Magebit/McpHyvaCommerceTools
```

CI (`.github/workflows/ci.yml`) runs PHPStan and the unit tests and needs four repository secrets:
`MAGENTO_AUTH_USER` and `MAGENTO_AUTH_PASS` (repo.magento.com keys), `HYVA_PACKAGIST_URL` (the Hyvä Commerce
Private Packagist URL, `https://hyva-themes.repo.packagist.com/<project>/`) and `HYVA_PACKAGIST_TOKEN` (its token).

phpcs reports warnings only for the static value helpers (`Model/Util/Cast`, `Model/Tool/Schema/EntityTarget` named
constructors, mirroring the core's `Pagination::maxPageSize()`) and a few string/path functions
(`pathinfo`, `html_entity_decode`, `rawurldecode`, `getimagesize`, `addcslashes`).

## License

Released under the [MIT License](LICENSE).
