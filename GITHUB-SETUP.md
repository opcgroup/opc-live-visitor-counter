# OPC Live Visitor Counter — GitHub update setup

Repository: https://github.com/opcgroup/opc-live-visitor-counter

## Upload source files to your repository

1. Extract the WordPress ZIP `opc-live-visitor-counter.zip` locally.
2. Open the extracted `opc-live-visitor-counter` folder and select its contents (`opc-live-visitor-counter.php`, `README.txt`, `GITHUB-SETUP.md`, `assets/`, and `includes/`).
3. On GitHub repository page choose **Add file → Upload files** and upload these contents (not the outer plugin folder). Commit the files.

## Publish first release

1. On GitHub click **Releases → Create a new release**.
2. Create a tag `v1.1.0` targeting `main`, title `OPC Live Visitor Counter v1.1.0`.
3. Attach the original **WordPress installable ZIP** `opc-live-visitor-counter.zip` as a release asset. The asset name must be exactly `opc-live-visitor-counter.zip`.
4. Publish as a full public release (not a draft or prerelease).

## Install the updater

Install this v1.1.0 ZIP on each WordPress site. The plugin uses the WordPress `Update URI` mechanism to check public GitHub Releases. As of v1.1.0, it is configured for `opcgroup/opc-live-visitor-counter`.

## Later releases

- Make and test code changes, then increment the `Version:` header and `const VERSION` in the main plugin file.
- Upload updated source to the same repository.
- Package an installable ZIP with one top-level folder `opc-live-visitor-counter/`.
- Create a *new* GitHub Release, e.g., `v1.1.1`, and attach the ZIP with the exact same asset filename (`opc-live-visitor-counter.zip`).
- WordPress sites on older versions will discover the new release on their normal update checks. You can choose **Update Now** or enable plugin auto-updates.

PHP version changes do not automatically create or fix plugin updates. Compatibility must be tested and code changes released as needed. Back up the website/database before updates; counter data lives in your WordPress database and is not packaged in this ZIP.

Requires a public repo/release asset. Private GitHub repositories need a separate authenticated updater and should not put a GitHub personal access token into publicly distributed plugin PHP.
