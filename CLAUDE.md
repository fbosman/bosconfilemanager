# BosConfilemanager (Dolibarr module) — working notes

## Conventions
- Display name "BosConfilemanager"; technical `bosconfilemanager`: dir `custom/bosconfilemanager`, class
  `modBosConfilemanager`, rights_class `bosconfilemanager`, constants `BOSCONFILEMANAGER_*`, module number 450170
  (450160 is used by bosconwhatsapp).
- Rights: `bosconfilemanager->read`, `->write`, `->delete` (ids 45017001..03), only effective for `$user->admin`
  (`bosconfilemanagerCan()`).
- Root is always `DOL_DOCUMENT_ROOT`. Every path from the request goes through `bosconfilemanagerResolve()` /
  `bosconfilemanagerResolveNew()`; never build absolute paths from request data otherwise.
- Every state changing action: right check + `bosconfilemanagerCheckToken()` + backup via `bosconfilemanagerBackup()`
  before overwrite/delete + `bosconfilemanagerLog()`.
- Parameters of `$langs->trans()` go through `bosconfilemanagerTransParam()` (trans() html-encodes them itself);
  other output through `dol_escape_htmltag()`.
- Code + comments in English; UI strings in `langs/nl_NL` (leading) and `langs/en_US`.
- Target Dolibarr 18–22, PHP 7.4+ (no PHP 8-only syntax or functions).

## Testing
- Tested against Dolibarr 21.0.4 (PHP 8.3, MariaDB) with Playwright: tree, mkdir/mkfile, edit + PHP syntax check,
  conflict, backups/restore, upload/overwrite, image preview, rename, delete, CSRF and non-admin access.
- `php -l` on all changed files.
