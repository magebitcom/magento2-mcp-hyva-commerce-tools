# Magebit_McpHyvaCommerceTools

Hyvä Commerce 1.4 MCP tools for [`Magebit_Mcp`](https://github.com/magebitcom/magento2-mcp-module). Exposes Hyvä CMS
content and the other Hyvä Commerce modules as MCP tools, and adds Hyvä Admin Dashboard widgets that show MCP activity
and content health.

## Contents

- [What this module gives you](#what-this-module-gives-you)
- [Requirements](#requirements)
- [Install](#install)
- [Stability](#stability)
- [Concepts](#concepts)
- [Safety model](#safety-model)
- [ACL](#acl)
- [Store scope](#store-scope)
- [Delete tools](#delete-tools)
- [Replies](#replies)
- [Optional features](#optional-features)
- [Hyvä Commerce 1.4 coverage](#hyvä-commerce-14-coverage)
- [Tool catalog](#tool-catalog)
- [Prompts](#prompts)
- [Dashboard widgets](#dashboard-widgets)
- [Notes on Hyvä behaviour](#notes-on-hyvä-behaviour)
- [Development](#development)
- [License](#license)

## What this module gives you

- Hyvä CMS pages and blocks: drafts, versions, components, scheduling, AI translations, Page Builder migration, import
  and export
- Menu Builder menus and menu items
- Media Gallery assets, image usage and Media Optimization
- Form Builder forms
- Email and newsletter templates
- Store Credit balances
- Linked Products groups
- Category Merchandiser product positions
- Read-only tools for AI Worker and Advanced Admin Permissions
- Admin Dashboard views and widgets
- Content audits, three MCP prompts and seven dashboard widgets

See [Hyvä Commerce 1.4 coverage](#hyvä-commerce-14-coverage) for the tools each Hyvä module gets.

**Content writes are drafts.** They go through the same Hyvä service the Liveview editor uses
(`Hyva\CmsLiveviewEditor\Model\Provider`). Only the publish tools make content live: `hyva_cms.publish`,
`hyva_menu.publish`, `hyva_form.form.publish` and `hyva_email.*.publish`.

## Requirements

- `magebitcom/magento2-mcp-module` (`Magebit_Mcp`) `^1.4` (its `ForbiddenException` carries the refusals, see
  [Refusals](#refusals))
- Hyvä Commerce 1.4:
  - Hyvä CMS 1.3 with Media Gallery (`hyva-themes/commerce-module-cms`)
  - Hyvä Admin Dashboard (`hyva-themes/commerce-module-admin-dashboard`,
    `hyva-themes/commerce-module-admin-dashboard-api`)

These Hyvä modules are optional and detected at runtime. Each one enables the tools listed in
[Hyvä Commerce 1.4 coverage](#hyvä-commerce-14-coverage). [Optional features](#optional-features) explains what
happens when one is missing.

- `hyva-themes/commerce-module-menu-builder`
- `hyva-themes/commerce-module-media-optimization`
- `hyva-themes/commerce-module-image-editor`
- `hyva-themes/commerce-module-form-builder`
- `hyva-themes/commerce-module-email-templates`
- `hyva-themes/commerce-module-store-credit`
- `hyva-themes/commerce-module-linked-products`
- `hyva-themes/commerce-module-category-merchandiser`
- `hyva-themes/commerce-module-cms-ai-translations`
- `hyva-themes/commerce-module-ai-worker`
- `hyva-themes/commerce-module-advanced-admin-permissions`

**Removing Menu Builder also removes Hyvä's CMS dashboard widgets.**
`hyva-themes/commerce-module-admin-dashboard-cms-widgets` requires `commerce-module-menu-builder`, both in composer and
in a constructor (`RecentlyEditedHyvaCmsMenus`). Remove the widgets package too, or `setup:di:compile` fails. This
module requires neither package: Menu Builder is only a composer suggestion.

## Install

```bash
composer require magebitcom/magento2-mcp-hyva-commerce-tools
bin/magento module:enable Magebit_McpHyvaCommerceTools
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Stability

From 1.0.0 the MCP tool contract is the stable API: tool names, input schemas, reply shapes, error codes and ACL
resources. Breaking changes to it come only in a major release. The PHP classes and the `Api\*` interfaces are
internal: they may gain methods in minor releases, so a module that implements or extends them may need changes on a
minor upgrade.

## Concepts

| Term | What it is |
|---|---|
| **Draft** | Unpublished Hyvä content. The storefront keeps showing the published version until a publish tool or a scheduled release makes the draft live. |
| **Settings** | Magento fields of a page, block or menu (title, active flag, store views, meta), passed as `settings`. They are not drafted and apply immediately. |
| **Content type** | The `entity_type` argument of the shared CMS tools: `cms_page`, `cms_block` or `menu`. |
| **Hyvä verb** | A per-type permission from Hyvä's `ContentTypeAuthorization`: `view`, `save`, `publish`, `delete`, `version_history`, `export`, `scheduling`, `schedule_save`, `schedule_cancel`. For example, `publish` on a page is `Hyva_Cms::page_publish`. |
| **Preview** | The dry-run reply of a call that needs `"confirm": true` and did not pass it. Nothing is written. |

## Safety model

- **Write gate.** Write tools need the global write switch and a token with writes.
- **Drafts first.** Content tools save drafts; only publish tools and scheduled releases make a draft live.
- **Explicit confirm.** The calls in the [confirm table](#confirm-and-previews) return a preview until called with
  `"confirm": true`. Publish tools need no confirm: publishing is their purpose, and they go live on the first call.
- **Three ACL checks.** The tool resource, the Hyvä resource and, for tools with `entity_type`, the Hyvä verb for that
  type. See [ACL](#acl).
- **Store scope.** A role restricted by Hyvä Advanced Admin Permissions is held to its websites and store views. See
  [Store scope](#store-scope).

### Write gate

Every write tool needs both switches:

- the global *Allow Write Tools* setting (`magebit_mcp/general/allow_writes`);
- the write flag on the calling token.

Every write tool also sets `confirmationRequired`. `tools/list` sends it to clients as the `destructiveHint`
annotation.

### Drafts and live settings

These tools save a draft (`saveContent(..., publish: false)`) and a version-history entry:

- page, block and menu tools;
- migration and import tools;
- image replace and alt-text tools.

The storefront changes only after `hyva_cms.publish` or a scheduled release.

Publishing a menu also activates it: Hyvä Menu Builder sets `is_active` when it publishes
(`MenuProvider::saveContent()`), and the publish reply reports the flag after publishing. A scheduled menu release
takes the same path, so it also activates an inactive menu when it fires; the schedule reply's `warnings` list says
so when the menu is inactive. Activating a menu that is not published yet (`hyva_menu.create` or
`hyva_menu.update` with `is_active: true`) shows nothing until it is published and placed, and the confirm preview
says so. A published, active menu also renders nowhere until it is placed: in the header or footer menu of the design
configuration, or through a menu widget (CMS content or a widget instance). The tools do not look the placement up,
so their replies still say the menu goes live; `hyva_menu.find_usage` shows where it is placed. Publishing a page or
block leaves its active flag as it is; an inactive one stays hidden, and the reply's `note` names the update call.

`settings` are different: they are Magento fields and apply at once. A `settings` change that affects the live
storefront therefore needs `"confirm": true` (see the next section).

### Confirm and previews

Without `"confirm": true` these calls write nothing and return a preview instead.

| Call | Needs `"confirm": true` |
|---|---|
| `hyva_cms.page.create` / `hyva_cms.block.create` / `hyva_menu.create` with `is_active=true` | Always (`action: "create_active"`). The preview shows what would go live. An inactive create never needs it, whatever its identifier and store views: an inactive page, block or menu is not served. |
| `settings`: active flag, store views, URL key or identifier | Always. |
| `hyva_menu.publish` / `hyva_cms.publish` of an inactive menu | Always (`action: "publish_activate"`): Hyvä Menu Builder activates a menu when it publishes it. Publishing an active menu, a page or a block never needs it. |
| `settings`: any other field | Only on an active page, block or menu. A menu's `preview_url_key` never needs it: it only picks the page the editor previews the menu on. |
| Replacing a draft | `hyva_cms.draft.create` and `hyva_cms.import` when the current draft has unpublished changes; `hyva_cms.migration.convert_to_draft` when a Hyvä draft already exists. |
| Removing a menu item that has children | Always, also through `hyva_menu.update` / `hyva_cms.draft.update` operations that remove or replace one. The preview lists the children and `children_count`. |
| `hyva_media.image.replace` | Always (`action: "replace_image_globally"` for `scope=global`, else `"replace_image"`). |
| `hyva_media.optimization.clear` | Only with `all_images=true` (clears every optimized image). |
| `hyva_media.asset.move`, `hyva_media.asset.trash` | Always. |
| `hyva_store_credit.balance.credit`, `hyva_store_credit.balance.debit` | Always. |
| `hyva_linked.group.save` | Always. Without it the tool only validates and shows the result. |
| `hyva_merch.category.positions_set` | Always. |
| `hyva_cms.page.delete`, `hyva_cms.block.delete`, `hyva_menu.delete`, `hyva_email.template.delete`, `hyva_email.newsletter.delete` | Always; an entity in use also needs `"force": true`. See [Delete tools](#delete-tools). |

A call that changes `settings` writes nothing without confirm, not even the draft part of the call. Its preview lists
each change with its storefront effect.

Every preview has the same keys:

| Key | Content |
|---|---|
| `dry_run`, `confirmation_required` | Both `true`. |
| `action` | The action the call would take, e.g. `delete_cms_page`. |
| `affected` | Always a list: one entry per item that would change, or sample paths when clearing every optimized image. |
| `total` | Every item that would change. |
| `details` | Only when there is context that is not an item, e.g. `in_use` (always a boolean; trash adds `in_use_count`) for trash and the delete tools, `target_folder` for move, `menu_id` and `children_count` for a menu item removal, `not_editable_usages` for a global image replace (an affected entity's own usages sit on its `affected` entry, so each entity is listed once). |
| `hint` | What confirming does, e.g. that a replaced image is a draft until `hyva_cms.publish`. |

The rule behind the table: `confirm` guards what changes the live storefront at once, what cannot be undone, and
what overwrites unpublished work. A call that only writes a draft, or removes generated files that are rebuilt on
the next request, runs on the first call. That covers `hyva_cms.page.update` / `hyva_cms.block.update` without a
live `settings` change, `hyva_menu.update` outside the table (e.g. the title of an inactive menu, `preview_url_key`
or a draft structure edit), `hyva_media.image.update_alt`, `hyva_media.optimization.clear` with one `path` and
`hyva_cms.migration.convert_to_draft` with no Hyvä draft yet. `hyva_media.image.replace` only writes drafts too, but
needs `confirm` for every scope: callers run it once to see what it would change, and a write on that first call left
a duplicate draft per entity. To tell a preview from a write without knowing the table, read `dry_run`: `true` in a
preview (nothing written), `false` in the reply of these calls, which wrote. `hyva_media.image.update_alt` accepts `confirm` but ignores it.

### Refusals

**Scope and permission refusals** refuse the whole call with the JSON-RPC error `-32004`, the same code as the core's
own ACL gate, so a client handles one shape. Nothing was written. `error.data.reason` says why:

| `reason` | When | Other `error.data` keys | What to do |
|---|---|---|---|
| `scope` | The role's websites or store views (Hyvä Advanced Admin Permissions) exclude the entity, store view or website. | `denied_store_ids` (page and block create), `allowed_website_ids` (`hyva_store_credit.history.list` without `website_id`) | Work in the role's own store views or websites (see [Store scope](#store-scope)). |
| `acl` | The role lacks a permission: a Hyvä verb, an admin delete permission, another admin's AI Worker job, saving drafts. | `resource`, the missing ACL resource, when the tool knows it; `resources` when several are missing (e.g. `hyva_media.image.replace` with `scope: global`) | Grant the permission (see [Role setup](#role-setup)). |

```json
{"jsonrpc": "2.0", "id": 7, "error": {"code": -32004, "message": "Your admin role may not delete CMS pages: it needs the Magento_Cms::page_delete permission.", "data": {"reason": "acl", "resource": "Magento_Cms::page_delete"}}}
```

Lists are not refused: they leave out what the role may not see and count it (`items_hidden_by_permissions`,
`conflicts_hidden_by_permissions`), and `hyva_media.image.replace` / `update_alt` skip out-of-scope entities
(`skipped_out_of_scope`). See [Tools that skip or hide content](#tools-that-skip-or-hide-content-outside-the-role).

**Partial writes stay soft.** When an update tool already wrote part of the call (the settings, or the draft) and
the rest is refused, the reply is a soft error that says what was written (`settings_applied`, `draft_saved`).
`hyva_media.image.replace` and `update_alt` do the same when a later entity fails after earlier drafts were saved
(`drafts_saved`, `failed_at`). When the cause is a scope or permission refusal it carries the same `reason`.

**Other refusals** are soft errors (`isError: true`) with a `status`, not JSON-RPC errors. They come from Hyvä (a save
it rejects) or from the module's own checks (in-use deletes, mail transport):

| Status | When | What to do |
|---|---|---|
| 422 | A provider refuses content, or Hyvä's content guard rejects it (3 MB, 5000 components, depth 50). `field_errors` lists the problems per component uid and field. | Fix the fields and call again. |
| 409 | The draft changed since you read it. Pass `base_version_uid` to the update tools to get this check. Also a confirmed delete of an entity still in use (`in_use`), and a `hyva_linked.group.save` that Hyvä refuses after the check passed (another save took the name or the products; `issues` code `save_refused`, Hyvä's message). | Reload and re-apply your change; never resubmit old content. For a delete, see [Confirm and force](#confirm-and-force). For a linked group, validate again and save again. |
| `transport_error` | `hyva_email.template.send_test` could not hand the mail to Magento's mail transport. The reply has `sent: false`, `message`, `id` and `recipient`. | Check the store's mail settings. Nothing was sent. |

## ACL

Every tool has its own resource under *System → MCP → MCP Tools → Hyvä Commerce Tools*, named
`Magebit_McpHyvaCommerceTools::tool_<tool_name_with_underscores>`.

A call passes three checks, all against the token's admin role:

| # | Check | Applies to | Catalog column |
|---|---|---|---|
| 1 | The tool's own resource. | `tools/list` and `tools/call` | — |
| 2 | The Hyvä resource the tool is listed with, e.g. `Hyva_Cms::page_view`. A role without it does not see the tool. | `tools/list` and `tools/call` | Listed with |
| 3 | For tools that take `entity_type`: the Hyvä verb for that type. For example, `hyva_cms.publish` on a page needs `Hyva_Cms::page_publish`, on a menu `Hyva_MenuBuilder::menu_publish`. | `tools/call` | Checked per call |

**Tools with `entity_type` in `tools/list`.** `tools/list` has no arguments, so it cannot check the verb per type.
These tools are listed for a role that holds their verb on at least one content type ("the verb on any type" in the
catalog). A call on a type the role lacks is refused with `-32004`. A role with only the view verbs therefore does not
see the draft, publish, import, restore and schedule tools, or `hyva_cms.migration.convert_to_draft`. The menu
variants (`hyva_menu.publish`, `hyva_menu.schedule.*`, `hyva_menu.version.*`) follow the same rule on menus: they are
listed only when the role holds every verb they need there, e.g. `hyva_menu.schedule.create` needs
`menu_schedule_save` and `menu_view`.

**Refusals at run time use the same code.** A permission the tool itself checks while it runs (delete permissions,
draft saves, Hyvä's own checks) is refused with the same `-32004` as the core's gate, with `error.data.reason: "acl"`
(see [Refusals](#refusals)).

**Scheduling needs view.** The schedule tools need the content type's view verb next to its scheduling verb
(`Hyva_Cms::page_view`, `Hyva_Cms::block_view`, `Hyva_MenuBuilder::menu_view`): Hyvä keeps them as siblings, so a
role can schedule a type it may not read. A call on such a type is refused (`-32004`); `hyva_cms.schedule.list` and
the conflict lists hide its items (`items_hidden_by_permissions`, `conflicts_hidden_by_permissions`), and
`hyva_cms.schedule.cancel` refuses them per item.

**Version history needs its own verb.** Reading a version also needs the type's `version_history` verb:

| Argument | Tools |
|---|---|
| `fields` with `versions` | `hyva_cms.page.get`, `hyva_cms.block.get`, `hyva_menu.get` |
| `version_id` | Preview, publish and schedule create (`hyva_cms.*` and `hyva_menu.*`) |
| `source=version` | `hyva_cms.draft.create` |
| `include_versions` | `hyva_cms.export` |

A get without `fields` returns `versions: null` to a caller without that permission.

**Hyvä's own checks see the MCP caller.** Hyvä 1.4 also checks permissions inside its own services, e.g. version
restore. While a tool runs, the module gives Magento authorization the MCP caller's role, so those checks see the same
role.

### Role setup

Give a role the Hyvä permissions that match what the agent should do. Existing roles are not migrated from
`Magento_Backend::content`.

| Agent should… | Grant (besides the MCP tool resources) |
|---|---|
| read pages, blocks, menus, images | `Hyva_Cms::cms`, `Hyva_Cms::page_view`, `Hyva_Cms::block_view`, `Hyva_MenuBuilder::menu_view`, `Hyva_MediaGallery::media` + `asset_view` |
| see version history | + `Hyva_Cms::page_version_history`, `Hyva_Cms::block_version_history`, `Hyva_MenuBuilder::menu_version_history` |
| create and edit drafts | + `Hyva_Cms::page_save`, `Hyva_Cms::block_save`, `Hyva_MenuBuilder::menu_save` |
| publish | + `Hyva_Cms::page_publish`, `Hyva_Cms::block_publish`, `Hyva_MenuBuilder::menu_publish` |
| schedule | + `*_scheduling`, `*_schedule_save`, `*_schedule_cancel` |
| export / import | + `*_export` / `*_save` and `Hyva_Cms::transfer` (Transfer Center) |
| delete pages, blocks, menus | see [Delete tools](#delete-tools) |
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
| list dashboard views and widgets | `Hyva_AdminDashboardFramework::dashboard`; `dashboard_views_save` to refresh widget data |

A role without `Hyva_Cms::cms` sees none of the Hyvä CMS, menu or media tools.

## Store scope

Hyvä Advanced Admin Permissions restricts an admin role to some websites or store views. Hyvä's scope guards run only
in the admin and the web API, not on the `/mcp` route. The module therefore applies the MCP admin's restriction itself.

### Rules per entity

| Entity | Read | Change |
|---|---|---|
| CMS pages, blocks, menus, forms | Outside the role's store views: hidden from the lists, and refused by every tool that reads one (`-32004`). | Outside the role's store views: refused (`-32004`). Changing one, or its store views, needs every store view it is assigned to. |
| Email and newsletter templates, linked product groups, category positions | Allowed. They belong to no store view. | Refused for a restricted role. |
| Store credit | Checked against the customer's website. | Checked against the customer's website. Hyvä's own store credit services do not check scope. |
| Media Gallery files, folders, trash, sync status | Not scoped. | Not scoped. |

Menus and forms are scoped by their store views although the Hyvä admin does not scope them.

### Store view 0 (All Store Views)

- **Changing or creating needs every store view.** Store view 0 counts as all of them, as in the Hyvä admin. A
  restricted role can read an "All Store Views" page, block or menu, but can neither change it there nor create one
  there.
- **Re-assigning is judged by the store views it enters.** This covers `settings.store_ids` on `hyva_cms.page.update`,
  `hyva_cms.block.update` and `hyva_menu.update`, as Hyvä Advanced Admin Permissions judges the admin save:
  - moving the entity into the role's own store views is allowed (giving up store view 0 is not giving up a foreign
    store view);
  - dropping a store view outside the role is refused.

### Tools that skip or hide content outside the role

| Tool | Behaviour | Hidden count |
|---|---|---|
| `hyva_media.image.replace`, `hyva_media.image.update_alt` | Skip content outside the role. | — |
| `hyva_cms.schedule.cancel`, `hyva_menu.schedule.cancel` | Refuse items of content outside the role. | — |
| `hyva_cms.schedule.list`, `hyva_cms.schedule.detect_conflicts`, the conflicts `hyva_cms.schedule.create` and `hyva_menu.schedule.create` return | Hide items and conflicts of content outside the role, including content that no longer exists. | `items_hidden_by_permissions`, `conflicts_hidden_by_permissions` |
| `hyva_form.delivery.audit` without a form | Audits only the role's forms. | `forms_hidden_by_permissions` |
| `hyva_cms.migration.scan` | Lists only the role's pages and blocks. | — |
| `hyva_menu.find_usage` | Leaves out the design configuration scopes the role may not open: Default, websites it does not fully hold, other store views. | `usages_hidden_by_permissions` |
| `hyva_menu.category.import` | Keeps to the role's category roots. Without a `store_id` it reads in the menu's first store view the role may read. | — |

### Usage lists, previews and audits

These leave out content outside the role, as Hyvä's guards do:

- `hyva_media.image.find_usage`, `hyva_media.image.list`, `hyva_media.image.validate`, `hyva_media.asset.resolve`;
- the `hyva_media.asset.move` and `hyva_media.asset.trash` previews;
- the `usage` field of `hyva_media.image.get`, `hyva_cms.block.get` and `hyva_menu.get`;
- `hyva_menu.find_usage`;
- the `hyva_commerce.audit.*`, `hyva_media.audit*` and `hyva_menu.audit_all` audits;
- the forms lens of `hyva_commerce.audit.overview`.

What is left out, counted in `*_hidden_by_permissions`:

- pages, blocks, menus and widget instances outside the role's store views;
- products outside the role's websites;
- categories outside the category trees of the role's store views.

Exceptions:

- An image or menu used only outside the role still counts as used.
- A product assigned to no website stays listed, as in the Hyvä admin.

`published_urls` and the store rows of previews (preview links, `is_assigned`, e.g. in `hyva_menu.create` and
`hyva_cms.preview`) list only the role's store views, and count the rest in `stores_hidden_by_permissions`.

### Not filtered by scope

- **Media Gallery files, folders, trash and sync status.** The Hyvä Media Gallery has no scope, and the core MCP module
  leaves data without a store assignment unscoped too. The media tool descriptions and, for a restricted admin, the
  Media Health notice say that media files are shared by all websites.
- **Configuration values:** menu placements, email assignments in `hyva_email.audit.coverage`, store credit websites in
  `hyva_commerce.audit.overview`.
- **Email and newsletter templates.**
- **The MCP audit log.** See [Store scope in widgets](#store-scope-in-widgets) for what the MCP Activity widget shows a
  restricted admin.

## Delete tools

`hyva_cms.page.delete`, `hyva_cms.block.delete`, `hyva_menu.delete`, `hyva_email.template.delete` and
`hyva_email.newsletter.delete` each delete one page, block, menu, email template or newsletter template.

- **Same path as the admin.** They use the repository the admin delete action uses: Magento's page or block repository
  in the admin area, Hyvä's menu repository for menus, Magento's template models for email and newsletter templates.
- **Hyvä data goes with it.** Hyvä drafts, version history and Tailwind CSS are deleted too, as in the admin (foreign
  keys with `ON DELETE CASCADE`).
- **Pending scheduled items are cancelled.** `hyva_cms.page.delete`, `hyva_cms.block.delete` and `hyva_menu.delete`
  list the entity's pending scheduled items in the preview (`details.scheduled_items`) and cancel them with the delete
  (a release left without pending items is cancelled too), since an item whose entity is gone can never publish. The
  admin delete leaves them pending.

To remove a single menu item use `hyva_menu.item.remove`. To remove media files, `hyva_media.asset.trash` moves them to
the Media Gallery trash.

### Permissions

| Tool | Tool resource | Admin delete permissions |
|---|---|---|
| `hyva_cms.page.delete` | *Tool: Delete CMS Pages via MCP* | `Magento_Cms::page_delete` (the admin page Delete action) and `Hyva_Cms::page_delete` |
| `hyva_cms.block.delete` | *Tool: Delete CMS Blocks via MCP* | `Magento_Cms::block` (Magento has no separate block delete permission) and `Hyva_Cms::block_delete` |
| `hyva_menu.delete` | *Tool: Delete Hyvä Menus via MCP* | `Hyva_MenuBuilder::menu_delete` |
| `hyva_email.template.delete` | *Tool: Delete Email Templates via MCP* | `Magento_Email::template` (the admin Delete action) and `Hyva_EmailTemplates::email_template_delete` |
| `hyva_email.newsletter.delete` | *Tool: Delete Newsletter Templates via MCP* | `Magento_Newsletter::template` (Hyvä has no newsletter delete permission) |

- The tool resources sit under *MCP Tools → Hyvä Commerce Tools*. The module grants them to no role. Only a role with
  *All* resources (Administrators) has them without being given them.
- The core checks the tool resource and the "Listed with" resource on `tools/list` and `tools/call`.
- The page and block tools check both admin delete permissions again and refuse with `-32004` (`reason: acl`,
  `resource` names the missing one).
- The email tools are hidden without Hyvä Email Templates. Email and newsletter templates belong to no store view, so
  a role restricted to some websites is refused (`-32004`, `reason: scope`).
- They are write tools, so the [write gate](#write-gate) applies.
- Store scope is as for any change: every store view the entity is assigned to must be the role's, and store view 0
  needs all of them.

### Confirm and force

| Call | Entity not in use | Entity in use (`in_use: true`) |
|---|---|---|
| No `confirm` (with or without `force`) | Preview | Preview that says `force` is needed (`details.force_required`, `hint`) |
| `"confirm": true` | Deleted | Refused: soft error, status 409, naming the usages and returning them in `usage` |
| `"confirm": true, "force": true` | Deleted | Deleted |

The preview is the standard one:

- `action`: `delete_cms_page`, `delete_cms_block`, `delete_menu`, `delete_email_template` or
  `delete_newsletter_template`;
- `affected`: the entity with its store views and `in_use`;
- `details.usage`: where it is still used (see [Usage keys](#usage-keys)).

A forced delete answers `deleted: true` with:

- `forced: true`;
- the usages that broke, in `usage` (the preview's report);
- a hint that those references are now broken.

The audit summary records `forced` too.

### Usage keys

`details.usage` has `used`, `total` and one list per place. The list keys differ per type: they are the keys the
find-usage tools already return, so they are not unified.

| Tool | List keys in `details.usage` |
|---|---|
| `hyva_cms.page.delete` | `store_config` (home / 404 / cookies page configuration), `links` (links in Hyvä CMS content and menu items) |
| `hyva_cms.block.delete` | `hyva_content` (Hyvä CMS components), `magento_content` (widget directives in Magento content), `widget_instances` |
| `hyva_menu.delete` | `design_config` (header/footer design configuration), `hyva_cms_content` and `magento_cms_content` (menu widgets in content), `widget_instances`, `layout_and_templates`; also `identifier`, as `hyva_menu.find_usage` |
| `hyva_email.template.delete` | `store_config`: configuration values at any scope on a path ending in `/template` or `_template` (e.g. `sales_email/order/template`) |
| `hyva_email.newsletter.delete` | `queue`: newsletter queues not sent yet, sending or paused. Sent and cancelled queues are history: counted in `queue_history`, not in `used`. Magento deletes every queue of the template with it, history included. |

For a role restricted to some websites, `usages_hidden_by_permissions` counts the usages it may not see.

The page and block tools also check menus when the role may not view them (`Hyva_MenuBuilder::menu_view`). Those
usages are not listed; `usages_hidden_by_permissions` counts them, and they still set `used`, so `force` is needed.

## Replies

### Draft status

`hyva_cms.page.get`, `hyva_cms.block.get` and `hyva_menu.get` report three flags. Request them in `fields` on their own
(`"fields": ["has_draft"]`) or with their group (`status` for pages and blocks, `settings` for menus).

| Flag | Meaning |
|---|---|
| `has_draft` | A Hyvä draft row with content exists. Hyvä keeps that row after publishing. |
| `is_published` | A published Hyvä version exists. |
| `draft_differs` | The draft differs from the published version. |

Right after a publish, `has_draft: true` with `draft_differs: false` is normal: the draft equals the live version. Only
`draft_differs: true` means unpublished changes.

### Preview and editor links

Preview replies (`hyva_cms.preview`, `hyva_menu.preview`, and the `preview` of create and get replies) have one row per
store view and these links:

| Key | What it is |
|---|---|
| `preview_url` | The current draft (or the given version) on the storefront. An HMAC link: no admin login, valid up to 7 days and only while the draft is unchanged. `null` for a store view a menu is not assigned to. |
| `published_url` | The live address in that store view. `null` for an inactive entity or a store view it is not assigned to. A menu has no URL of its own: this is the page set as its `preview_url_key` (`null` when none is set). |
| `admin_editor_url` | The Hyvä editor in the admin, on the route Hyvä registers for the type (see below). |
| `note` | What the three links give for this type. |

`admin_editor_url` routes:

| Type | Route |
|---|---|
| Pages, blocks, menus, forms | `liveview/editor/index` |
| Email templates | `hyva_cms_email/liveview/editor` |
| Newsletter templates | `hyva_cms_email/liveview/newslettereditor` |

The link has no admin secret key: a key minted for an MCP request cannot match the browser's admin session. With
*Add Secret Key to URLs* on (*Stores → Configuration → Advanced → Admin → Security*), the link opens the dashboard;
open the editor from the grid instead.

A page or block on store view 0 (All Store Views) lists a `published_urls` entry for every store view, as it is live in
each of them.

### Other reply details

| Tool | Detail |
|---|---|
| `hyva_menu.create` | `preview_url_key` is the URL key of a page that shows the menu; the editor previews the menu on it, and `published_url` points to it. |
| `hyva_menu.render_check` | When a store view renders empty HTML and Hyvä gives no message, `message` names the reason: inactive, not published, not assigned to that store view, or no content. |
| `hyva_email.template.list`, `hyva_email.newsletter.list` | Reply with `total`, `page`, `page_size` and `items`, as `hyva_cms.page.list`. |
| `hyva_email.newsletter.create_draft` | Always saves a versioned draft (Hyvä's blank `email_wrapper` when no components are given) and returns `draft` with `version_id`, `version_uid` and `warnings`. |
| `hyva_cms.tailwind.status` | `reason` is `daemon_not_responding` when `healthy` is false, else `null`. `hint` names the next step for the compilation strategy and daemon management mode. `daemon_management` honours Hyvä's legacy env.php flag `hyva_cms_tailwind/cron_daemon_manager_enabled`, as Hyvä does. |
| `hyva_cms.component.schema` | Field `options` from a source model (e.g. the menu widget's `menu_identifier`) are read at request time, so a menu created after Hyvä cached its components is listed. The menu widget's options list only menus the role may view (`Hyva_MenuBuilder::menu_view`) in store views it may read, as `hyva_menu.list` does; `options_hidden_by_permissions` counts the rest. |
| Menu tools that take an `identifier` | With `store_id`, the identifier finds the menu assigned to that store view, else the one for all store views, active or not; `store_id: 0` matches only a menu for all store views. Without `store_id`, an identifier that several store views share is refused with the candidate ids and store views (`store_id: 0` is named "all store views"). The match is case-insensitive. |
| `hyva_media.image.list` | `folder` limits the Media Gallery images to that folder and its subfolders, and the scan to that folder. An unknown folder adds a `hint`. Without `folder`, the list is every gallery image plus a scan of `wysiwyg`. |
| `hyva_email.template.send_test` | With Email & Newsletter Templates 1.0.1+ the mail is rendered inside Hyvä's test-send context, so order components show the preview fixture (items, totals, addresses) as the admin "Send test" does, and the fallbacks are Hyvä's `FixtureDefaults`. On 1.0.0 the render has no fixture. |
| `hyva_media.trash.list`, `hyva_media.asset.search` | `trashed_at` and `updated_at` are UTC `Y-m-d H:i:s`, read from the database (seconds), as in every other media reply. |
| Media audits (`hyva_media.audit_*`, `hyva_commerce.audit.media`) | `counts` is a JSON object, `{}` when no image has a problem. |

## Optional features

Tools for an optional Hyvä module stay registered when that module is missing, disabled, or not allowed for the
token's admin role. What an agent sees depends on the module:

| Hyvä module missing or disabled | Effect |
|---|---|
| Menu Builder, Media Optimization, Media Gallery, Form Builder, Email Templates, Store Credit, Linked Products, AI Worker | Their tools are hidden from `tools/list`. A call to a hidden tool is refused by the core (`-32004`). The status tools stay listed (below). Without Media Gallery this includes the image and media audit tools (`hyva_media.image.*`, `hyva_media.audit_*`): they are listed with a Media Gallery resource. |
| Category Merchandiser, CMS AI Translations, Advanced Admin Permissions, the AI providers, the Tailwind compiler | Their tools stay listed and answer "not installed" per call. |

The status tools stay listed:

- `hyva_menu.status` and `hyva_media.status` report the feature with `available: false` and `acl_allowed: null` (no
  ACL verdict for a feature that is not there). `unavailable_reason` says why: `not_installed`, `disabled`, `acl`
  (installed, but the role may not use it), or `null` when `available` is true.
- `hyva_media.status` is listed with `Hyva_Cms::cms` while Media Gallery is missing or disabled.
- `hyva_store_credit.status` and `hyva_ai.worker.status` answer that the module is not installed.
- `hyva_commerce.audit.overview` lists every module.

A call that reaches such a tool answers with a soft error (`isError: true`) instead of failing:

```json
{"available": false, "feature": "menu_builder", "reason": "Hyvä Menu Builder is installed but disabled (Hyva_MenuBuilder).", "hint": "Run bin/magento module:enable Hyva_MenuBuilder and setup:upgrade."}
```

Call `hyva_commerce.audit.overview`, `hyva_menu.status` or `hyva_media.status` first to see what the store supports.

## Hyvä Commerce 1.4 coverage

Hyvä Commerce 1.4.1 (`hyva-themes/commerce` 1.4.1) and what this module offers for each part. Counts are tools.
Hyvä Commerce 1.4.0 (Store Credit 1.0.1, Linked Products 1.0.0) works as well.

| Module (version) | Tools | Notes |
|---|---|---|
| Hyvä CMS 1.3.3 (Liveview editor, Connect, Admin Styles) | `hyva_cms.*` (29, without `translation.*` and `tailwind.status`) | Pages, blocks, drafts, versions, scheduling, components, export/import, PageBuilder migration, preview, `permission.check`; per-type verbs from `ContentTypeAuthorization` |
| CMS AI Translations 1.0.2 | `hyva_cms.translation.*` (2) | Status per store view; run returns proposals and can write the draft, never publishes |
| Menu Builder 1.1.1 | `hyva_menu.*` (21) | Includes publish, delete, versions and schedules (1.1 per-verb permissions) |
| Media Gallery 1.0.0 | `hyva_media.asset.*`, `trash.*`, `folder.create`, `sync.status`, `audit.literal_urls` (9) | Asset ids in content (`imageOptions.id`), move, trash/restore, sync drift, literal-URL audit |
| Media Optimization 1.1.0 | `hyva_media.optimization.*` (5) | Unchanged in 1.4 |
| Image usage and audits (CMS + Media Gallery) | `hyva_media.image.*`, `hyva_media.audit_*`, `hyva_media.status` (10) | Find, replace and fix images across CMS content and menus |
| Form Builder 1.0.2 | `hyva_form.*` (9) | Forms, drafts, publish, versions, delivery audit (recipients, webhook) |
| Email & Newsletter Templates 1.0.1 | `hyva_email.*` (16) | Transactional emails and newsletters: drafts, publish, render preview, test send, delete, coverage audit |
| Store Credit 2.0.0 (1.0.1 works too) | `hyva_store_credit.*` (5) | Balances, history, credit/debit with preview and idempotency key. Its GraphQL API is for shoppers and has no admin use here. 2.0.0 only moves the storefront route to `store-credit/`; no tool builds a Store Credit URL |
| Linked Products 1.0.2 | `hyva_linked.*` (4) | Groups, validation with storefront warnings, save with page cache refresh. See [Linked Products 1.0.1+ in the admin](#linked-products-101-in-the-admin) |
| Category Merchandiser 1.0.0 | `hyva_merch.category.*` (2) | Reorder only. The Enterprise package is not installed. Reports when another module (ElasticSuite) owns storefront sorting |
| AI Worker 1.0.0 and AI Worker CMS 1.0.0 | `hyva_ai.*` (5, without `provider.status`) | Monitoring only (status, jobs, usage, errors); AI Worker CMS jobs show in `hyva_ai.job.list`. No generation by design |
| AI providers 1.3.0 (`magento2-module-ai` 1.3.1 with the Anthropic, DeepL, Gemini and OpenAI modules) | `hyva_ai.provider.status` (1) | Which providers are configured and their models, plus whether CMS AI Translation is ready to run. API keys are never read or shown |
| CMS Tailwind compiler 1.0.x and JIT bridge 1.0.3 | `hyva_cms.tailwind.status` (1) | Compiler daemon health, daemon management, compilation strategy and compiler version, with a hint when new CMS content is not being compiled. Read only: no start, stop or restart |
| Advanced Admin Permissions 1.0.1 | `hyva_admin_perm.*` (2) | Read only (effective permissions, audit log). Scope restrictions are applied by the module itself, see [Store scope](#store-scope) |
| Admin Dashboard 2.0.3 (+ API 3.0.1, CMS widgets, CrUX history widget) | `hyva_dashboard.*` (3) + [widgets](#dashboard-widgets) | Views and widget types as the caller sees them; MCP activity and content health widgets |
| Audits | `hyva_commerce.audit.*` (4) | `audit.overview` adds one health line per 1.4 module |

### Linked Products 1.0.1+ in the admin

Linked Products 1.0.1 gates the *Linked Products* section of the admin Product Edit page by the same resources the
MCP tools use: with `Hyva_LinkedProducts::group_save` it is editable, with only `group_view` it is read only, and
without either it is hidden; a product save changes groups only with `group_save`. Admin users of an MCP role
therefore see that section differently than before 1.0.1, where it did not check them. The MCP tools themselves
are unchanged: `group_view` lists and reads, `group_save` saves (see [Role setup](#role-setup)).

### Not covered, on purpose

| Module (version) | Why |
|---|---|
| Admin Theme (`commerce-theme-adminhtml` 1.1.1, `commerce-module-admin-theme` 1.0.1) | Visual admin theme only; nothing for an agent to read or change |
| Commerce Module 1.0.6 | Shared base and branding fixes (logo); no data of its own |
| Image Editor 1.1.0 | Interactive in-browser editor; its saved edits show in `hyva_media.image.get` |
| CMS Google Maps 1.0.0 | Only a CMS component; listed and validated by `hyva_cms.component.list` / `schema` / `validate` |

## Tool catalog

**Tool names.** The tables use the canonical dotted names (`hyva_cms.page.get`). On the wire, `tools/list` shows them
with underscores (`hyva_cms_page_get`); `tools/call` accepts both forms.

**Columns.**

- **Mode**: `read` or `write`. Write tools need the [write gate](#write-gate).
- **Listed with**: the Hyvä or Magento resource the role needs to see and call the tool ([ACL](#acl) check 2). `-`
  means none.
- **Checked per call**: the Hyvä verb checked against the call's arguments ([ACL](#acl) check 3).
  `entity_type → publish` means the `publish` verb for the content type in `entity_type`. "The verb on any type" in
  *Listed with* means the role needs that verb on at least one content type.

### Hyvä CMS

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_cms.block.create` | write | `Hyva_Cms::block_save` | copy_from.entity_type → view | Create Hyvä CMS Block |
| `hyva_cms.block.get` | read | `Hyva_Cms::block_view` | fields with versions → version_history | Get Hyvä CMS Block |
| `hyva_cms.block.list` | read | `Hyva_Cms::block_view` |  | List Hyvä CMS Blocks |
| `hyva_cms.block.delete` | write | `Hyva_Cms::block_delete` + `Magento_Cms::block` |  | Delete Hyvä CMS Block (own resource *Delete CMS Blocks via MCP*; confirm-gated) |
| `hyva_cms.block.update` | write | `Hyva_Cms::block_save` |  | Update Hyvä CMS Block |
| `hyva_cms.component.list` | read | `Hyva_Cms::cms` |  | List Hyvä CMS Components |
| `hyva_cms.component.schema` | read | `Hyva_Cms::cms` |  | Get Hyvä CMS Component Schema |
| `hyva_cms.component.validate` | read | the verb on any type | entity_type → view | Validate Hyvä CMS Components |
| `hyva_cms.draft.create` | write | the verb on any type | entity_type → save (+ version_history for source=version) | Create Hyvä CMS Draft |
| `hyva_cms.draft.update` | write | the verb on any type | entity_type → save | Update Hyvä CMS Draft |
| `hyva_cms.export` | read | the verb on any type | entity_type → export (+ version_history with include_versions) + `Hyva_Cms::transfer` | Export Hyvä CMS Content |
| `hyva_cms.import` | write | the verb on any type | entity_type → save + `Hyva_Cms::transfer` | Import Hyvä CMS Content |
| `hyva_cms.migration.analyze` | read | the verb on pages or blocks | entity_type → view | Analyze Legacy CMS Content |
| `hyva_cms.migration.convert_to_draft` | write | the verb on pages or blocks | entity_type → save | Convert Legacy CMS Content to Draft |
| `hyva_cms.migration.scan` | read | the verb on pages or blocks | entity_type → view | Scan Legacy CMS Content (at most `limit` items, default 50, max 500; summary.total counts all) |
| `hyva_cms.page.create` | write | `Hyva_Cms::page_save` | copy_from.entity_type → view | Create Hyvä CMS Page |
| `hyva_cms.page.get` | read | `Hyva_Cms::page_view` | fields with versions → version_history | Get Hyvä CMS Page |
| `hyva_cms.page.list` | read | `Hyva_Cms::page_view` |  | List Hyvä CMS Pages |
| `hyva_cms.page.delete` | write | `Hyva_Cms::page_delete` + `Magento_Cms::page_delete` |  | Delete Hyvä CMS Page (own resource *Delete CMS Pages via MCP*; confirm-gated) |
| `hyva_cms.page.update` | write | `Hyva_Cms::page_save` |  | Update Hyvä CMS Page |
| `hyva_cms.permission.check` | read | `-` |  | Check Hyvä CMS Permissions |
| `hyva_cms.preview` | read | the verb on any type | entity_type → view (+ version_history with version_id) | Preview Hyvä CMS Content |
| `hyva_cms.publish` | write | the verb on any type | entity_type → publish (+ version_history with version_id) | Publish Hyvä CMS Content |
| `hyva_cms.schedule.cancel` | write | the verbs on any type | per item: schedule_cancel + view | Cancel Hyvä CMS Schedule |
| `hyva_cms.schedule.create` | write | the verbs on any type | entity_type → schedule_save + view (+ version_history with version_id) | Schedule Hyvä CMS Content |
| `hyva_cms.schedule.detect_conflicts` | read | the verbs on any type | entity_type → scheduling + view | Detect Hyvä CMS Schedule Conflicts |
| `hyva_cms.schedule.list` | read | the verbs on any type | entity_type → scheduling + view | List Hyvä CMS Schedules |
| `hyva_cms.tailwind.status` | read | `Magento_Config::config_system` |  | Hyvä CMS Tailwind Status (compiler daemon health, strategy, `reason`; hint per strategy) |
| `hyva_cms.translation.run` | write | the verb on any type | entity_type → view + `Hyva_CmsAiTranslations::translate` | Run Hyvä CMS AI Translation |
| `hyva_cms.translation.status` | read | the verb on any type | entity_type → view | Hyvä CMS Translation Status |
| `hyva_cms.version.list` | read | the verb on any type | entity_type → version_history | List Hyvä CMS Versions |
| `hyva_cms.version.restore` | write | the verb on any type | entity_type → save + version_history | Restore Hyvä CMS Version |

### Hyvä Menu Builder (optional)

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_menu.audit_all` | read | `Hyva_MenuBuilder::menu_view` |  | Audit All Hyvä Menus |
| `hyva_menu.category.import` | write | `Hyva_MenuBuilder::menu_save` |  | Import Categories into Hyvä Menu |
| `hyva_menu.category.sync_preview` | read | `Hyva_MenuBuilder::menu_view` |  | Preview Category Import into Hyvä Menu |
| `hyva_menu.create` | write | `Hyva_MenuBuilder::menu_save` |  | Create Hyvä Menu (confirm-gated when `is_active=true`) |
| `hyva_menu.find_usage` | read | `Hyva_MenuBuilder::menu_view` |  | Find Hyvä Menu Usage |
| `hyva_menu.get` | read | `Hyva_MenuBuilder::menu_view` | fields with versions → version_history | Get Hyvä Menu |
| `hyva_menu.item.add` | write | `Hyva_MenuBuilder::menu_save` |  | Add Hyvä Menu Item |
| `hyva_menu.item.remove` | write | `Hyva_MenuBuilder::menu_save` |  | Remove Hyvä Menu Item |
| `hyva_menu.item.update` | write | `Hyva_MenuBuilder::menu_save` |  | Update Hyvä Menu Item |
| `hyva_menu.list` | read | `Hyva_MenuBuilder::menu_view` |  | List Hyvä Menus |
| `hyva_menu.preview` | read | `Hyva_MenuBuilder::menu_view` | version_id → version_history | Preview Hyvä Menu |
| `hyva_menu.publish` | write | `Hyva_MenuBuilder::menu_publish` | entity_type → publish (+ version_history with version_id) | Publish Hyvä Menu |
| `hyva_menu.render_check` | read | `Hyva_MenuBuilder::menu_view` |  | Check Hyvä Menu Rendering |
| `hyva_menu.schedule.cancel` | write | `Hyva_MenuBuilder::menu_schedule_cancel` + `menu_view` | entity_type → schedule_cancel + view | Cancel Hyvä Menu Schedule |
| `hyva_menu.schedule.create` | write | `Hyva_MenuBuilder::menu_schedule_save` + `menu_view` | entity_type → schedule_save + view (+ version_history with version_id) | Schedule Hyvä Menu |
| `hyva_menu.status` | read | `Hyva_Cms::cms` |  | Menu Builder Status |
| `hyva_menu.delete` | write | `Hyva_MenuBuilder::menu_delete` |  | Delete Hyvä Menu (own resource *Delete Hyvä Menus via MCP*; confirm-gated) |
| `hyva_menu.update` | write | `Hyva_MenuBuilder::menu_save` |  | Update Hyvä Menu |
| `hyva_menu.validate` | read | `Hyva_MenuBuilder::menu_view` |  | Validate Hyvä Menu |
| `hyva_menu.version.list` | read | `Hyva_MenuBuilder::menu_version_history` | entity_type → version_history | List Hyvä Menu Versions |
| `hyva_menu.version.restore` | write | `Hyva_MenuBuilder::menu_save` + `menu_version_history` | entity_type → save + version_history | Restore Hyvä Menu Version |

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
| `hyva_media.image.replace` | write | `Hyva_MediaGallery::asset_view` + save on any type | entity_type → save | Replace Media Image |
| `hyva_media.image.update_alt` | write | `Hyva_MediaGallery::asset_view` + save on any type | entity_type → save | Update Media Image Alt Text |
| `hyva_media.image.validate` | read | `Hyva_MediaGallery::asset_view` |  | Validate Media Image |
| `hyva_media.optimization.clear` | write | `Hyva_MediaGallery::asset_save` |  | Clear Optimized Images |
| `hyva_media.optimization.config_get` | read | `Magento_Cms::config_cms` |  | Get Media Optimization Config |
| `hyva_media.optimization.config_validate` | read | `Magento_Cms::config_cms` |  | Validate Media Optimization Config |
| `hyva_media.optimization.preview_picture` | read | `Hyva_MediaGallery::asset_view` |  | Preview Responsive Picture |
| `hyva_media.optimization.status` | read | `Hyva_MediaGallery::asset_view` |  | Media Optimization Status for Image |
| `hyva_media.status` | read | `Hyva_MediaGallery::asset_view` (`Hyva_Cms::cms` without Media Gallery) |  | Media Optimization Status |
| `hyva_media.sync.status` | read | `Hyva_MediaGallery::asset_view` |  | Media Gallery Sync Status |
| `hyva_media.trash.list` | read | `Hyva_MediaGallery::asset_view` |  | List Media Gallery Trash |
| `hyva_media.trash.restore` | write | `Hyva_MediaGallery::asset_delete` |  | Restore Media Gallery Trash |

### Hyvä Form Builder

| Tool | Mode | Listed with | What it does |
|---|---|---|---|
| `hyva_form.delivery.audit` | read | `Hyva_FormBuilder::form_view` | Audit Hyvä Form Delivery |
| `hyva_form.form.create` | write | `Hyva_FormBuilder::form_save` | Create Hyvä Form |
| `hyva_form.form.get` | read | `Hyva_FormBuilder::form_view` | Get Hyvä Form |
| `hyva_form.form.list` | read | `Hyva_FormBuilder::form_view` | List Hyvä Forms |
| `hyva_form.form.publish` | write | `Hyva_FormBuilder::form_publish` | Publish Hyvä Form |
| `hyva_form.form.update_draft` | write | `Hyva_FormBuilder::form_save` | Update Hyvä Form Draft |
| `hyva_form.form.validate` | read | `Hyva_FormBuilder::form_view` | Validate Hyvä Form |
| `hyva_form.version.list` | read | `Hyva_FormBuilder::form_version_history` | List Hyvä Form Versions |
| `hyva_form.version.restore` | write | `Hyva_FormBuilder::form_save` | Restore Hyvä Form Version |

### Hyvä Email & Newsletter Templates

| Tool | Mode | Listed with | What it does |
|---|---|---|---|
| `hyva_email.audit.coverage` | read | `Hyva_EmailTemplates::email_template_view` | Audit Hyvä Email Coverage |
| `hyva_email.newsletter.create_draft` | write | `Hyva_EmailTemplates::newsletter_template_save` | Create Hyvä Newsletter Template |
| `hyva_email.newsletter.delete` | write | `Magento_Newsletter::template` | Delete Hyvä Newsletter Template (own resource *Delete Newsletter Templates via MCP*; confirm-gated) |
| `hyva_email.newsletter.get` | read | `Hyva_EmailTemplates::newsletter_template_view` | Get Hyvä Newsletter Template |
| `hyva_email.newsletter.list` | read | `Hyva_EmailTemplates::newsletter_template_view` | List Hyvä Newsletter Templates |
| `hyva_email.newsletter.preview` | read | `Hyva_EmailTemplates::newsletter_template_view` | Preview Hyvä Newsletter Template |
| `hyva_email.newsletter.publish` | write | `Hyva_EmailTemplates::newsletter_template_publish` | Publish Hyvä Newsletter Template |
| `hyva_email.newsletter.update_draft` | write | `Hyva_EmailTemplates::newsletter_template_save` | Update Hyvä Newsletter Template Draft |
| `hyva_email.template.create_draft` | write | `Hyva_EmailTemplates::email_template_save` | Create Hyvä Email Template |
| `hyva_email.template.delete` | write | `Hyva_EmailTemplates::email_template_delete` + `Magento_Email::template` | Delete Hyvä Email Template (own resource *Delete Email Templates via MCP*; confirm-gated) |
| `hyva_email.template.get` | read | `Hyva_EmailTemplates::email_template_view` | Get Hyvä Email Template |
| `hyva_email.template.list` | read | `Hyva_EmailTemplates::email_template_view` | List Hyvä Email Templates |
| `hyva_email.template.preview` | read | `Hyva_EmailTemplates::email_template_view` | Preview Hyvä Email Template |
| `hyva_email.template.publish` | write | `Hyva_EmailTemplates::email_template_publish` | Publish Hyvä Email Template |
| `hyva_email.template.send_test` | write | `Hyva_EmailTemplates::email_template_save` | Send Hyvä Email Template Test (an outbound write: needs Save draft, although the Hyvä admin button needs only View) |
| `hyva_email.template.update_draft` | write | `Hyva_EmailTemplates::email_template_save` | Update Hyvä Email Template Draft |

### Hyvä Store Credit

| Tool | Mode | Listed with | What it does |
|---|---|---|---|
| `hyva_store_credit.balance.credit` | write | `Hyva_StoreCredit::balance_adjust` | Credit Store Credit |
| `hyva_store_credit.balance.debit` | write | `Hyva_StoreCredit::balance_adjust` | Debit Store Credit |
| `hyva_store_credit.balance.get` | read | `Hyva_StoreCredit::balance_view` | Get Store Credit Balance |
| `hyva_store_credit.history.list` | read | `Hyva_StoreCredit::balance_view` | List Store Credit History |
| `hyva_store_credit.status` | read | `Hyva_StoreCredit::balance_view` | Hyvä Store Credit Status |

### Hyvä Linked Products

| Tool | Mode | Listed with | What it does |
|---|---|---|---|
| `hyva_linked.group.get` | read | `Hyva_LinkedProducts::group_view` | Get Linked Product Group |
| `hyva_linked.group.list` | read | `Hyva_LinkedProducts::group_view` | List Linked Product Groups |
| `hyva_linked.group.save` | write | `Hyva_LinkedProducts::group_save` | Save Linked Product Group |
| `hyva_linked.group.validate` | read | `Hyva_LinkedProducts::group_view` | Validate Linked Product Group |

### Hyvä Category Merchandiser

| Tool | Mode | Listed with | What it does |
|---|---|---|---|
| `hyva_merch.category.positions_get` | read | `Magento_Catalog::categories` | Get Category Product Order |
| `hyva_merch.category.positions_set` | write | `Magento_Catalog::categories` | Reorder Category Products |

### Hyvä AI (monitoring)

| Tool | Mode | Listed with | What it does |
|---|---|---|---|
| `hyva_ai.error.list` | read | `Hyva_AiWorker::jobs` | List Hyvä AI Worker Errors |
| `hyva_ai.job.get` | read | `Hyva_AiWorker::manage` | Get Hyvä AI Worker Job |
| `hyva_ai.job.list` | read | `Hyva_AiWorker::manage` | List Hyvä AI Worker Jobs |
| `hyva_ai.provider.status` | read | `Hyva_Ai::config` | Hyvä AI Provider Status (providers configured or not, CMS translation readiness; API keys never shown) |
| `hyva_ai.usage.summary` | read | `Hyva_AiWorker::jobs` | Hyvä AI Worker Token Usage |
| `hyva_ai.worker.status` | read | `Hyva_AiWorker::config` | Hyvä AI Worker Status |

### Hyvä Advanced Admin Permissions (read only)

| Tool | Mode | Listed with | What it does |
|---|---|---|---|
| `hyva_admin_perm.audit.list` | read | `Hyva_AdvancedAdminPermissions::audit` | List Admin Role Assignment Audit |
| `hyva_admin_perm.effective.get` | read | `Magento_User::acl_users` | Get Effective Admin Permissions |

### Audits and dashboard

| Tool | Mode | Listed with | Checked per call (Hyvä verb) | What it does |
|---|---|---|---|---|
| `hyva_commerce.audit.cms` | read | the verb on any type | entity_type → view | Audit Hyvä CMS Content |
| `hyva_commerce.audit.media` | read | `Hyva_MediaGallery::asset_view` |  | Audit Hyvä Commerce Media |
| `hyva_commerce.audit.menu` | read | `Hyva_MenuBuilder::menu_view` |  | Audit Hyvä Commerce Menus (accepts `max_kb` and `limit`) |
| `hyva_commerce.audit.overview` | read | `-` |  | Audit Hyvä Commerce Installation |
| `hyva_dashboard.view.list` | read | `Hyva_AdminDashboardFramework::dashboard` |  | List Hyvä Dashboard Views |
| `hyva_dashboard.widget.list` | read | `Hyva_AdminDashboardFramework::dashboard` |  | List Hyvä Dashboard Widgets |
| `hyva_dashboard.widget.refresh` | write | `Hyva_AdminDashboardFramework::dashboard_views_save` |  | Refresh Dashboard Widget Data |

## Prompts

| Prompt | Writes | What it does |
|---|---|---|
| `hyva_cms.landing_page_draft` | yes | Builds a campaign landing page as a draft from a short brief, validates it and returns the preview link. |
| `hyva_menu.health_check` | no | Checks every menu for broken links, disabled targets and empty sections, in plain language. |
| `hyva_media.audit_report` | no | Reports heavy images, missing alt text, missing files and WebP/AVIF setup. |

## Dashboard widgets

The widgets are in the category **Magebit / AI / MCP**, registered through
`Hyva\AdminDashboardFramework\Model\Config\Widget\Converter`.

- **ACL.** Each widget has its own resource under *Dashboard → Magebit / AI / MCP Widgets*.
- **Cache.** Each widget caches its data for 300 s and reuses the same services as the audit tools. The cached data
  holds routes, not URLs: links are built when the widget renders, so their secret key is the viewing admin's.
- **CSP.** Templates are static markup with no Alpine state, so they are CSP-safe.

| Widget | Shows |
|---|---|
| `magebit_mcp_activity_widget` | MCP calls, failures, writes, AI-generated drafts, publish/live/destructive actions, and a link to the MCP audit log. See [How MCP Activity counts calls](#how-mcp-activity-counts-calls). |
| `magebit_content_health_widget` | Missing alt text, broken images, invalid/disabled components, unpublished drafts, schedule problems, legacy pages |
| `magebit_menu_health_widget` | Menus with broken links, disabled targets, empty columns, placement, last MCP menu actions |
| `magebit_media_health_widget` | Oversized images, missing alt (all image usages: Hyvä CMS, Magento CMS, menus, products and categories; Hyvä's Content Health "Missing alt text" counts Hyvä CMS images only), broken references, missing WebP/AVIF, optimization and engine status |
| `magebit_campaign_calendar_widget` | Upcoming and recent scheduled releases, homepage/menu changes, conflicts |
| `magebit_migration_status_widget` | Hyvä vs Page Builder/WYSIWYG pages, complexity, converted drafts waiting |
| `magebit_campaign_asset_readiness_widget` | Media and link problems in the content of pending scheduled items |

`hyva_dashboard.widget.refresh` clears a widget's cached data by type or instance, for every viewer of the instance.
It needs `Hyva_AdminDashboardFramework::dashboard_views_save`, the permission Hyvä checks when a widget instance is
saved (which refreshes its data in the admin); a role that can only view the dashboard cannot call it.

### How MCP Activity counts calls

- **Writes**: every registered write tool.
- **AI-generated drafts**: this module's tools that only save drafts. These are `create_draft`, `update_draft`,
  `convert_to_draft`, `update_alt`, and create/update/add/run on pages, blocks, drafts, menus, menu items, forms and
  translations.
- **Publish, live and destructive actions**: every other write tool. This covers publish, delete, trash, remove, clear,
  restore, import, replace, cancel, move, flush, reset, disable, and immediate changes such as `system.config.set`, the
  core CMS tools, category positions or store credit.
- Dry runs and confirmation previews are not counted.

### Store scope in widgets

- The widgets apply the logged-in admin's [store scope](#store-scope), with a separate widget cache entry per
  restriction.
- The MCP audit log has no store scope. The MCP Activity widget (which needs `Magebit_Mcp::mcp_audit`) therefore shows
  a restricted admin only their own MCP calls, and an unrestricted admin every admin's calls.
- Menu Health and Media Health show MCP activity and the audit log link only with `Magebit_Mcp::mcp_audit`.

## Notes on Hyvä behaviour

- **Scheduling.** Hyvä scheduled releases are one-way: there is no end date. `hyva_cms.schedule.detect_conflicts`
  reports a `no_fallback` info when nothing restores the previous content after a campaign.
- **Cancelled releases stay.** `hyva_cms.schedule.cancel` sets items and releases to `cancelled`; Hyvä keeps them as
  history and has no delete, so they stay in `hyva_cms.schedule.list` (with a status filter other than `pending`) and
  in the admin release list. The cancel reply says so (`note`).
- **Schedule list cap.** `hyva_cms.schedule.list` with a status other than `pending` lists the newest releases first
  and reads at most the newest 1000 items. It reports `truncated: true` when it cut; the release cut by the cap is left
  out, so no release is partial. `total_releases` counts releases within the items read. Pending is never capped.
- **Tailwind CSS.** With the in-browser strategy, JIT Tailwind CSS for content is compiled by the Liveview editor in
  the browser, not by the daemon. Content written over MCP can use classes that are not compiled yet for the theme.
  `hyva_cms.tailwind.status` reports `healthy` as Hyvä does (the daemon on this host answers `/health`), whatever the
  strategy.
- **Component cache.** Hyvä cleans its `hyva_cms` component cache only on CMS block save and delete, so its editor can
  list stale menus in the menu widget until then. `hyva_cms.component.schema` reads source-model options live.
- **Admin area for saves.** Page and block saves run in an emulated `adminhtml` area. In the frontend area, where
  `/mcp` runs, Hyvä's `Plugin\Model\Page::afterGetContent` fails for a page without an id.
- **Published URLs.** Hyvä's page provider reports a page's address as `published_url` in every store view, active
  or not, and its menu provider marks a menu assigned (`is_assigned: true`) in every store view. `published_urls`
  leaves out, and preview store rows give `published_url: null` for, an inactive entity and a store view it is not
  assigned to; for a menu, that is decided by the menu's own store views.
- **Component links.** `hyva_cms.component.validate` warns (`invalid_link`) about link field values with neither `type`
  and `value` nor a non-empty `src`, such as `{"url": "..."}`. They render an empty href.
- **Image ids.** Hyvä writers outside the editor must call `ImageIdBackfiller::backfill()` themselves
  (`Provider::saveContent` does not). The draft tools do, so images keep their Media Gallery asset id.
- **Trash and asset ids.** Trashing deletes the Media Gallery asset row and Hyvä's trash keeps no asset id, so a
  restored file always comes back with a new asset id. References by the old id are stale: they still render (Hyvä
  falls back to the stored path) but no longer follow the asset when it moves. The `hyva_media.trash.restore` reply
  gives each restored asset's new `asset_id` and does not scan the content (that would read every document on each
  restore). Find the image fields still holding an old id with `hyva_media.audit.literal_urls` (category
  `stale_asset_id`, the old id in `asset_id`), then re-link them with `hyva_media.image.replace` using the same path
  as `old_path` and `new_path` and `"confirm": true`: it saves drafts that carry the new id.
- **Symbolic links in pub/media.** Magento's media path validator checks paths lexically and does not resolve symbolic
  links, so a link under `pub/media` that points elsewhere passes it. `hyva_media.asset.move`,
  `hyva_media.asset.trash` and `hyva_media.folder.create` resolve links and refuse a target folder, an asset file or
  a new folder whose real path (or that of its nearest existing parent) lies outside `pub/media` (soft error; the
  paths in `paths_outside_media`). Other media tools follow Magento's rule.
- **Links in the literal-URL audit.** Link field values (`{type, value, src, ...}`) are not images: their `src` is the
  link target of a `custom_url` or `cms_page` link. `hyva_media.audit.literal_urls` skips them, so they are never
  counted as `literal_not_in_gallery`.
- **Optimized image cleanup.** `hyva_media.optimization.clear` also removes conversions of resized variants
  (`converted/resized/<hash>`), which Hyvä's own cleaner leaves behind.
- **Category import.** `hyva_menu.category.import` leaves out new subcategories of a category whose menu item lies
  outside the targeted roots, and lists them in `skipped_outside_targets` (`id`, `name`).
- **Email fields that are not drafted.** An email template's subject and preheader, and a newsletter's sender, change
  sent emails at once. The email update tools say so and report `live_change`.
- **AI translation.** Hyvä's AI translation joins texts with line breaks and splits the answer on line breaks.
  `hyva_cms.translation.run` collapses line breaks in HTML and skips plain multi-line texts, so translations never land
  on the wrong field.
- **Store credit idempotency.** Hyvä's ledger returns the earlier entry when an idempotency key is reused, even for
  another customer or amount. The tools tell a replay from a clash (409).
- **Store credit switches.** Hyvä's balance services ignore the enabled flag and never email. The tools refuse websites
  where store credit is off, and can notify the customer.
- **Linked Products cache.** Hyvä's group saver cleans no cache. `hyva_linked.group.save` refreshes the product pages
  of old and new members.
- **Category positions.** Positions only reach the storefront when Magento's own catalog search sorts category pages.
  With ElasticSuite installed (as on the demo), it sorts by its own positions; the merchandiser tools say so.
- **Dashboard services.** Several Hyvä Admin Dashboard services (view model, widget instance repository, widget auth)
  need the admin session or adminhtml-only configuration. The dashboard tools therefore read views and widgets through
  the explicit-id services, the widget configuration and the instance table.

## Development

```bash
vendor/bin/phpstan analyse app/code/Magebit/McpHyvaCommerceTools -c app/code/Magebit/McpHyvaCommerceTools/phpstan.neon
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Magebit/McpHyvaCommerceTools/Test/Unit
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml app/code/Magebit/McpHyvaCommerceTools
```

**Optional Hyvä code stays behind adapters.** Only `Model/Adapter/*` reference `Hyva\MenuBuilder\*` /
`Hyva\MediaOptimization\*`. Tools depend on the `Api/Adapter/*GatewayInterface` contracts, wired to generated proxies,
so the real gateway is built only after the feature check.

**CI** (`.github/workflows/ci.yml`) runs PHPStan and the unit tests, each twice: with Store Credit 1.0.1 (Hyvä
Commerce 1.4.0) and 2.0.0 (1.4.1). It needs four repository secrets:

| Secret | Value |
|---|---|
| `MAGENTO_AUTH_USER`, `MAGENTO_AUTH_PASS` | repo.magento.com keys |
| `HYVA_PACKAGIST_URL` | The Hyvä Commerce Private Packagist URL, `https://hyva-themes.repo.packagist.com/<project>/` |
| `HYVA_PACKAGIST_TOKEN` | Its token |

**phpcs warnings.** phpcs reports warnings only for:

- the static value helpers (`Model/Util/Cast`, `Model/Tool/Schema/EntityTarget` named constructors, mirroring the
  core's `Pagination::maxPageSize()`);
- a few string and path functions (`pathinfo`, `html_entity_decode`, `rawurldecode`, `getimagesize`, `addcslashes`).

## License

Released under the [MIT License](LICENSE).

---

![Magebit](https://github.com/user-attachments/assets/cdc904ce-e839-40a0-a86f-792f7ab7961f)

Magebit - Full-service e-commerce agency

[magebit.com](https://magebit.com)
