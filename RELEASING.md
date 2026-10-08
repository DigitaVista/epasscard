# Releasing EpassCard

Releases to WordPress.org are automatic. Pushing a version tag runs
`.github/workflows/deploy.yml`, which commits the plugin to SVN trunk,
creates `tags/<version>`, and attaches the zip to a GitHub release.

## Steps

1. On the release branch, set the same version in three places:
   - `epasscard.php` header `Version:`
   - `epasscard.php` constant `EPC_VERSION`
   - `readme.txt` `Stable tag:`, plus a changelog entry
2. Test, then merge the release branch into `main`.
3. Tag `main` and push the tag:

       git checkout main && git pull
       git tag v1.2.3
       git push origin v1.2.3

4. Watch the run under GitHub > Actions. It stops before touching SVN if the
   tag, header Version and Stable tag differ, or if the tag is not on `main`.

## Notes

- The package uses `.distignore`. `vendor/` is committed on purpose (Appsero).
- Plugin page assets (banner, icon, screenshots): if a `.wordpress-org/` folder
  is added, the deploy replaces SVN `/assets` with its contents. Put every
  current asset in it first, or existing ones will be removed.
- A version that already exists in SVN `tags/` cannot be re-released. Bump the
  version instead.
- One-time setup: repository secrets `SVN_USERNAME` and `SVN_PASSWORD`.
