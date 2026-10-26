# Review notes

- `Model/Adapter/OptionalServiceFactory`: object manager by class name, called after the feature check, so `di:compile` passes without optional Hyvä packages.
- Constructors name only hard requirements (Hyvä CMS 1.3 incl. Media Gallery, Admin Dashboard); optional Hyvä services come from that factory.
- `Model/Auth/CallerContext` is the one adapter over the core's non-API caller classes; a public core API is proposed upstream.
- `hyva_ai.*` is monitoring only and `hyva_admin_perm.*` read only, by design; no generation or permission writes through MCP.
- Not covered on purpose (README "Not covered"): Image Editor, Admin Theme, Commerce Module, CMS Google Maps, Tailwind JIT bridge, AI providers.
- Tools with `entity_type` are listed for any Hyvä CMS role and refused per call (`-32004`): `tools/list` has no arguments to decide on.
- Store scope: menus and forms are scoped by their store views although the Hyvä admin does not scope them; stricter on purpose.
- Store scope: email/newsletter templates, linked groups and category positions have no store view; restricted roles read them, never write.
- `hyva_media.trash.restore` has no confirm: it never overwrites (skips taken paths, as Hyvä's `TrashRepository` does).
- CI runs Magento 2.4.7-p3, PHP 8.3, PHPUnit 9.5 like the sibling Magebit MCP modules; local runs use 2.4.8 / PHPUnit 10.
- Dev helpers (smoke script, test roles, absent-package env) live outside the repo on purpose; the module ships no test fixtures.
- Store scope: a product in no website stays visible to restricted roles; mirrors Hyvä CatalogProductEntityGuard.
- Store scope: catalog usages are judged by product website / category tree, not the description's store view (the usage index has no store_id).
- Media files, folders, trash and sync status are not store-scoped; the Hyvä Media Gallery has no website or store view scope.
