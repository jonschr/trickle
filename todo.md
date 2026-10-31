# Deferred work

## GitHub repository replacement during pulls

Status: deferred at Jon’s request. Do not implement this as part of the current release.

Goal: make a fresh local site ready for development without manually replacing migrated plugins/themes with Git checkouts.

- On pulls only, compare incoming plugin/theme folder names with repositories and branches in explicitly connected GitHub accounts/organizations.
- Suggest **Use Git repository instead** only for an exact, unique match. Show the owner/repository and branch; require an explicit choice. Ambiguous matches need a picker. A repository-name match can suggest its default branch but cannot prove which branch is deployed live.
- Clone into private staging and check out a local tracking branch with a clean origin URL. Validate plugin/theme layout and include replacement in the existing backup/recovery workflow. Preserve existing destination Git protections; never overwrite local development work implicitly.
- Keep ordinary live-file pulls available. Warn about differences from deployed code and missing generated assets; do not run repository build scripts automatically. Account for repositories with monorepo/subdirectory layouts.
- Start with an easy local-only GitHub connection: a fine-grained personal access token restricted to selected repositories, with Contents read permission (metadata read is also needed). Private repositories must be supported. Do not require GitHub write access.
- Later consider a **Connect GitHub** device-authorization flow; that needs a registered application. Reusing existing local GitHub CLI authentication could further simplify setup where available.
- Credentials must remain on the controlling site/machine, excluded from migration streams, logs, command arguments, and Git remote URLs. Local Git and process execution are required for a real checkout; expose a clear prerequisite check.
- Cache repository/branch discovery with pagination and rate-limit handling. Branch names such as main or staging are common and must not be treated as unique matches without checking the full connected scope.
- Expose suggestions and explicit repo/branch selections to CLI/AI plans alongside the UI; ordinary `--yes` must not silently opt into Git replacement.

References:

- https://docs.github.com/en/rest/repos/repos#list-repositories-for-the-authenticated-user
- https://docs.github.com/en/rest/branches/branches#list-branches
- https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens
- https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps#device-flow
