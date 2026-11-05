# Trickle development

- The canonical local test site is `https://trickle-main-dev.localhost:8453`, with WordPress at `/Users/jonschroeder/Cove/Sites/trickle-main-dev.localhost/public`.
- Its `wp-content/plugins/trickle` symlinks to this repository. Resolve symlinks before editing; other test sites may point at the same checkout. Fetch before development and compare the intended upstream. If incoming commits exist, explain them and ask before proceeding; never silently pull or overwrite local changes.
- The existing push/pull test remote is **Harrison dev**, `https://harrisonjetguns-dev.sitedistrict.com`. Production sites are not interchangeable with this destination. Never use Secure AI Now for testing.
- Before changing runtime files, check that no transfer, recovery or retained source packaging is active on sites using this checkout. Trickle Main Dev contains synthetic content, active/inactive plugins, a child/parent theme pair and dated media fixtures; outbound WordPress mail is blocked by a local MU plugin.
- The former `trickle-qa-a`, `trickle-qa-b`, `trickle-qa-c` and other old Trickle Cove sites were retired. Integration scripts that name them require explicitly recreated disposable peers. Do not redirect those scripts to Trickle Main Dev, Harrison dev or a customer site. Create disposable local peers only when needed.
- Ordinary UI regressions can run with `node tests/<suite>.js`; isolated PHP fixtures document their own commands. Tests requiring WordPress or applying transfers are not generic offline checks.
- Connection keys, admin login links, database dumps and test artifacts belong outside Git and outside the public document root. Native plugin requests retain the plugin's normal user agent.
