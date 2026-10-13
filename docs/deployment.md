# Deployment

The plugin is deployed by copying a tagged release from `<plugin-repo>` into the site's
`<moodle>/public/local/alborhansignup`, then running the Moodle upgrade. A human runs these commands (they need
deployment rights). Agents only prepare and verify.

## Before deploying

1. CI is green on every leg for the commit to release (`./dev/ci-status.sh <commit>`).
2. `version.php` is bumped (`version` and `release`) and `CHANGES.md` has a dated entry.
3. The release is tagged (`git tag -a vX.Y.Z`) and pushed. `<plugin-repo>` is checked out at that tag, with no local changes.
4. You know what changes for users, and the platform manager has been told (signup form, profile pages…).
5. Pick a low-traffic time. The maintenance window is under a minute.

## Steps

Replace `<web-user>` with the web server user and `<php>` with the PHP CLI binary of the version the site runs (on
some servers plain `php` is an older CLI). Don't write the real values into this repository.

1. Keep a copy of the deployed plugin, for reference only (see "Rollback" below):

```bash
sudo cp -a <moodle>/public/local/alborhansignup <backups>/alborhansignup-<old-version>-<date>
```

2. Turn maintenance mode on:

```bash
sudo -u <web-user> <php> <moodle>/admin/cli/maintenance.php --enable
```

3. Copy the release. Development-only files are excluded, and the owner and permissions match the rest of the code tree
   (use the owner, group and modes of the existing code tree for `<owner>:<group>` and `<modes>`):

```bash
sudo rsync -a --delete --exclude .git --exclude .github --exclude .gitignore --exclude .claude --exclude dev --exclude docs --exclude AGENTS.md --exclude CLAUDE.md --chown=<owner>:<group> --chmod=<modes> <plugin-repo>/ <moodle>/public/local/alborhansignup/
```

4. Upgrade **immediately**. New autoloaded classes are only found after the upgrade purges caches; until then pages
   that use them fail:

```bash
sudo -u <web-user> <php> <moodle>/admin/cli/upgrade.php --non-interactive
```

5. Turn maintenance mode off:

```bash
sudo -u <web-user> <php> <moodle>/admin/cli/maintenance.php --disable
```

## Verify

**Read-only HTTP checks** (an agent may do these, with anonymous GET requests only):
- `/login/signup.php` returns 200 for an anonymous visitor. Check the field order, that `phone1` is there with the
  «رقم الجوال» label, that the username field is hidden, and that the picker CSS link carries `?v=<new version>` (this
  proves the upgrade ran).
- The vendored files are served: `thirdparty/intl-tel-input/{js,css,img}` return 200 with the right content types.
- The AMD modules are in the RequireJS bundle (`define("local_alborhansignup/phoneinput"`).

Agents never send POST or other write requests to the live site, not even ones expected to fail.

**Manual checks** (a human):
0. The deployed files equal the tag: `git archive <tag> | tar -x -C <tmp>`, then `diff -r` against the deployed
   directory, ignoring the excluded paths.
1. Sign up end to end with a test address, all the way to "confirmation e-mail sent". Check that the number is stored
   in E.164 in the user list, then delete the test account.
2. As a student, edit your profile: the picker shows and the number is saved in E.164.
3. On your own RemUI profile, the pencil opens the edit page.
4. As a student, open a classmate's profile: core's profile page shows, with no phone or e-mail unless allowed.
5. Site administration → Reports → Security checks: «Contact details on theme_remui profile pages» (Arabic:
   «بيانات التواصل في صفحات الملف الشخصي (theme_remui)») is OK.
6. In the picker's country search, typing «الامارات» (no hamza) finds the UAE.
7. Optional: the blocked signup web service (`auth_email_signup_user`) answers `webservicesignupdisabled`. Only do
   this if the request cannot create an account on that site: send it **without** the required custom profile
   fields, so that it would fail validation before any write even if the block were missing. If you are not sure it
   fails before any write, skip this check; the PHPUnit tests cover the block.

## Rollback

Moodle refuses to downgrade a plugin version, so copying old files back breaks the site. Instead, publish a
**new** version: the previous code with a higher `version.php`. The backup from step 1 is a reference for building
that.

If only the picker misbehaves in browsers, the field still works as a plain input and the server still validates, so
signup keeps working while a fix is prepared.
