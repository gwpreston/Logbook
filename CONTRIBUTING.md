# Contributing to Logbook

Thank you for helping. Bug reports, ideas and code are all welcome.

## Issues

Open an issue for a bug or an idea. For a bug, say which version you run
(at the foot of the sidebar, and in Settings), how you installed it (Docker or bare PHP,
which database) and the steps that show it. Please don't post your data,
API keys or anything from `.env`.

Report a security problem privately, through GitHub's *Report a
vulnerability* on the repository's Security tab, not in a public issue.

## Code

Contributions are accepted under the project's licence, the GNU Affero
General Public License v3.0 or later ([`LICENSE`](LICENSE)). There is no
contributor licence agreement: you keep the copyright in your work and
license it to everyone under the same terms as the rest of Logbook.

### Sign off every commit

Every commit in a pull request must carry a `Signed-off-by` line with your
real name and an email address you can be reached at:

```
Signed-off-by: Your Name <you@example.com>
```

`git commit -s` adds it for you. By signing off you certify the
[Developer Certificate of Origin 1.1](https://developercertificate.org/):
in short, that you wrote the change or otherwise have the right to submit
it under the project's licence, and that you understand it and your
sign-off are recorded publicly. To sign off commits you have already
made, use `git rebase --signoff master` and force-push your branch.

### Before you open a pull request

Read [`CLAUDE.md`](CLAUDE.md) (how the code is organised and the rules every
change follows) and [`spec.md`](spec.md) (what the app does). In short:

- A feature needs a `spec.md` entry first, and larger work belongs to a
  phase in [`docs/phases/`](docs/phases/).
- Run `composer lint`, `composer analyse` and `composer test`. A change has
  to work on SQLite, PostgreSQL and MySQL/MariaDB; `bin/test-all-dbs.sh`
  runs the suite against each one.
- New text in the interface goes in `translations/`, never in a template.
- A new dependency must have a licence on the allowlist in
  `bin/third-party-notices.php`. Run `composer notices` to update
  [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md); CI checks it.

The [README](README.md#development) has the development setup.
