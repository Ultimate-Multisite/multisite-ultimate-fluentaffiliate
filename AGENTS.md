# AGENTS.md — multisite-ultimate-fluentaffiliate

WordPress plugin (addon for Ultimate Multisite).

## Local Development Environment

The shared WordPress dev install for testing this plugin is at `../../wordpress` (relative to this addon subdir).

- **URL**: http://wordpress.local:8080
- **Admin**: http://wordpress.local:8080/wp-admin — `admin` / `admin`
- **WordPress version**: 7.0-RC2
- **This plugin**: symlinked into `../../wordpress/wp-content/plugins/$(basename $PWD)`
- **Reset to clean state**: `cd ../../wordpress && ./reset.sh`

WP-CLI is configured via `wp-cli.yml` in this addon subdir — run `wp` commands directly from here without specifying `--path`.

```bash
wp plugin activate $(basename $PWD)   # activate this plugin
wp plugin deactivate $(basename $PWD) # deactivate
```
