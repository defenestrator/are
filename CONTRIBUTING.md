# Contributing to ARE

ARE follows the same development loop as Orkestera. Everything below the line
is copied verbatim from Orkestera's
[CONTRIBUTING.md](https://github.com/EDOS-Engineering/Orkestera/blob/fd50cb03/CONTRIBUTING.md)
(`fd50cb03`). This section is the only ARE-specific text: where the Orkestera
process names machinery ARE does not have yet, do this instead.

- **Where work happens.** All development *and issue tracking* happen on
  the fork, [`EDOS-Engineering/are`](https://github.com/EDOS-Engineering/are):
  file issues on its tracker, branch from its `main`, open PRs against it, and
  close issues from a PR with `Closes #N`. Until 2026-10-04 issues lived on
  `defenestrator/are`; those were moved here, and each old issue links to its
  new number. Do not file new issues on `defenestrator/are`.
- **How it deploys.** `defenestrator/are` is the deployment repository:
  Forge deploys its `main`. When the fork's `main` passes CI, the
  `deploy-sync` job fast-forwards `defenestrator/are` `main` to the tested
  commit. Nobody develops on it or merges feature PRs there, and agents
  never push to it. If it ever gains a commit the fork lacks, the sync fails
  rather than force-pushing; bring that commit into the fork through a PR.
  Agents never push branches there either, not even documentation branches.
- **CI.** CI is developed on, run on and written for the fork. That is
  where suites gate PRs. CI on `defenestrator/are` exists mainly for
  deployment. A workflow that should only run in one of the two repositories
  must say so with `if: github.repository == '<owner>/are'`, because a sync
  copies every workflow across.
- **Milestone and labels.** Issues go on the `Streaming launch` milestone.
  Claim them with `ready-for-agent` → `assigned-to-agent`, exactly as in §3.
  `phase:1`/`phase:2`/`phase:3` order the backlog; `blocked` means a
  prerequisite has not merged yet (the issue's Triage section names it).
  Issues labelled `skeptic` come from code review; together with
  `needs-triage` they wait for the lead to triage before anyone pulls them.
- **Framework docs and conventions.** [AGENTS.md](AGENTS.md) describes the
  Laravel conventions this repo follows and the framework docs vendored under
  `docs/vendor/`. Read those before searching the web.
- **Tests and the gate.** ARE's suite is Pest on in-memory SQLite, with no
  MongoDB. Run `./vendor/bin/pest` and `./vendor/bin/phpstan analyse`, and
  report the real counts in the PR. The fork carries Orkestera's rulesets:
  `ci-gate` is the single required check, and PRs merge through a rebase
  merge queue.
- **Not here yet:** changelog assembly, the commit-trailer guard, an ADR
  directory and ECR deploys. A human still reviews and merges every PR. Skip
  `changelog.d/` fragments until the assembly script exists. Where the text
  below says merging to `main` triggers a deploy, read it as the sync into
  `defenestrator/are` described above.

---

# Contributing to Orkestera

The development workflow below is the preferred process for **both humans and
agents**. It is the loop this repository is actually built with — follow it
rather than inventing a variant, and propose changes to it as a PR against
this file.

## The loop

```
Plan/Analyze from prompt
      │
      ▼
File GitHub Issues + ADRs (milestone + labels)
      │
      ▼
Pull a ready issue from the milestone backlog
      │
      ▼
Feature branch off main → build + test the solution
      │
      ▼
Push branch → open PR (Closes #N)
      │
      ▼
Human review → merge queue → origin/main
      │              (merge to main triggers deployment)
      ▼
Sync main locally, prune the merged branch → repeat
```

### 1. Plan / analyze

Work starts from a prompt or a gap analysis, not from code. Produce a plan
that names the seams it touches, then decompose it into **independently
mergeable, PR-sized issues**. Decisions that constrain future work get an
ADR (`docs/adr/NNNN-*.md`, numbered sequentially, `Status: Accepted` when
agreed). Research that precedes a decision is a **spike** issue whose
deliverable is a written analysis (issue comment or ADR draft) — not code.

### 2. File issues

- Every issue goes on the appropriate **milestone** (e.g. `Ork MVP`) with
  labels for phase/area where useful. Milestones + labels are the tracker —
  there is no separate project board to maintain.
- Multi-issue initiatives ("epics") are a numbered series of ordered issues
  that reference each other (`part 2/5, depends on #NNN`) — not a special
  object.
- Issue bodies state **scope, approach, and acceptance criteria**. An issue
  a stranger can't start from is not ready.

### 3. Pull ready work

Take the next unblocked issue from the milestone. Work is claimed through
labels so parallel agents never collide on the same ticket:

- **Only pull an issue that carries `ready-for-agent`.** No `ready-for-agent`
  label means the issue is not staged for autonomous work — leave it. An
  issue already carrying `assigned-to-agent` is claimed; do not pull it.
- **Claim before you build.** The moment you start an issue, atomically
  swap the labels — remove `ready-for-agent` and add `assigned-to-agent` —
  then post a claim comment naming the claimant (agent name or run id) and
  the time. The label swap is the mutex; the comment is the audit trail.

  ```sh
  gh issue edit <N> --remove-label ready-for-agent --add-label assigned-to-agent
  gh issue comment <N> --body "Claimed by <agent-name/run-id> at <UTC timestamp>."
  ```

  For extra specificity a persistent, named agent MAY instead use a
  per-agent claim label (`assigned-to-<name>`) in place of the generic
  `assigned-to-agent`. Ephemeral workers should prefer the generic label so
  the tracker doesn't accumulate one-off labels needing cleanup.
- **The `Closes #N` linkage in the PR remains the durable claim** — still
  check for an open PR referencing the issue before starting, in case a
  claim comment was missed.
- **Release the claim if you abandon the work.** If you stop without opening
  a PR, restore `ready-for-agent` and remove `assigned-to-agent` so the next
  agent can pick it up. When the PR merges and closes the issue, the
  `assigned-to-agent` label retires with it.

### 4. Branch and build

- Branch **off up-to-date `main`**, named `feat/…`, `fix/…`, `docs/…`, or
  `chore/…`. **Never commit to main.**
- **Sequential single-base PRs only — no stacked PRs.** Stacking onto a
  branch that later squash-merges has stranded commits twice; the ban is
  earned. If work depends on an open PR, wait for its merge, sync, then
  branch.
- Build the solution **with tests in the same change**: meaningful tests
  that pin the new behavior (and the bug, for fixes). Run the affected
  service suites locally — a PR with a red or unrun suite is not ready.
  Platform and Watch suites require a local MongoDB (see README).
- Keep the blast radius honest: update README/CONTEXT.md/CHANGELOG when the
  change makes them lie. `CONTEXT.md` is the domain vocabulary — use its
  terms, and update it when a change renames or retires a concept.
- Secrets never enter the repo: `.env` is gitignored, tokens are scrubbed
  from error paths, encrypted at rest via each service's Vault.
- **Commit as yourself.** Use your own GitHub-verified address — your
  `@users.noreply.github.com` address is fine and is what most people should
  use:

  ```sh
  git config user.email 'YOUR-ID+YOUR-HANDLE@users.noreply.github.com'
  ```

  This section used to require one specific person's address, because a squash
  merge writes a `Co-authored-by:` line for every branch-commit author that
  differs from the identity enqueueing the merge. That made the repository
  single-author in practice: on 2026-09-08 a colleague's sixteen pull requests
  all failed CI before review. The merge queue rebases now, which preserves
  each commit's own author and synthesises nothing, so the requirement is gone
  (ADR-0029). Your commits land on `main` under your name.

- **No co-authorship lines in commits or code.** The log must name one
  accountable author; a trailer naming a model, or repeating the author, makes
  every later "who wrote this" ask which trailers are real.

  CI enforces both halves of this in the **`commit-trailers`** job, via
  `.github/scripts/check-commit-trailers.sh`. The job is one of `ci-gate`'s
  `needs`, and `ci-gate` is the single required status check, so a red guard
  blocks the merge rather than reporting into an empty room. Its own tests
  (`check-commit-trailers_test.sh`) run in the same job, because a guard whose
  tests do not run is the thing it exists to catch (#1340). It named
  `workflow-guards` until #1651; that job was removed by #1569 and the guard
  went months invoked by nobody while this paragraph claimed otherwise.

  - On the **pull request**, it fails any branch commit carrying a
    `Co-authored-by:` line. It does not inspect authorship — anyone on the team
    commits as themselves (ADR-0029).
  - In the **merge queue**, it re-checks the commits about to become `main` and
    refuses any trailer. Because rebase never synthesises one, a trailer
    appearing there means the queue's merge method has drifted back to squash,
    and the failure says so and prints the query to confirm it.

  Do not rewrite `main` to strip the instances already there. The audit trail
  of what actually happened is worth more than a clean log, and force-pushing
  `main` is far more dangerous than the trailers are.

### 5. Push and open the PR

- Push the branch (repo rule: **≤ 5 refs per push**) and open a PR against
  `main` with `Closes #N` so the merge closes the issue.
- The PR body states what changed, why, the test evidence (suite counts),
  and any operator action required (new env vars, secrets, migrations) —
  flagged loudly, not buried.
- CI must be green: suites gate builds; builds gate image pushes; pushes
  gate deploy.

### 6. Review and merge

- **A human reviews and merges.** Agents never merge, never self-approve,
  and never push to a branch that has entered the merge queue.
- The repo uses a **merge queue** — do not delete branches with
  `--delete-branch` at merge time; prune afterwards instead.
- Merging to `origin/main` **is** the deploy trigger: CI on main runs
  suites → builds images → pushes to ECR → deploys the dev environment.
  There is no separate release step.

### 7. Sync and repeat

After merge:

```sh
git checkout main && git pull --prune
git push origin --delete <merged-branch>   # then delete it locally
```

Then pull the next ready issue.

## Sprints and the changelog

A **sprint** is a named wave of merged issues, not a time box.

### In your PR: write a fragment

Write your entry as **`changelog.d/<issue-number>-<slug>.md`** — name it after
the issue you close, which you know before the PR exists. Write the entry there
rather than in `CHANGELOG.md`, which assembly rewrites.

A fragment is **optional**: #1522 repealed the requirement that every source
change bring one, because the guard was blocking pull requests over prose. What
is not optional is that a fragment you *do* write be well-formed — see below.

```
Sprint: Merge-queue hygiene
Issues: #1370

One or two sentences on what this change does.

### Issues completed
- **#1370 (repo)** What landed.

### Key decisions
- The decision, and why the alternative was rejected.

### Breaking changes
- None.
```

`Sprint:`, `Issues:` and all three headings are required — the same four things
CLAUDE.md has always asked every entry to state. Write `- None.` under a heading
that does not apply.

That requirement is checked, not trusted: `assemble-changelog.py --check` runs
in **`ci.yml`'s `counters` job** on every pull request, and `counters` is one of
`ci-gate`'s `needs`, so a malformed fragment blocks the merge. It validates a
fragment that exists and never demands one — which is exactly the half #1522
kept. The check went unwired between #1522 and #1672, and sixteen malformed
fragments reached `main` in the gap (#1653).

One file per PR means two PRs never touch the same lines. Prepending to a
shared file meant every pair of in-flight PRs conflicted, and at one point 6 of
12 unmergeable PRs were stuck on that collision alone (#1370). It also closes a
sharper failure: a rebase once carried a hunk that would have silently
reinstated ~752 lines of history a previous PR had deliberately truncated.

If a change genuinely warrants no entry — a revert, a typo, a test repair —
simply write no fragment. The `no-changelog` label survives as a **convention**
for saying so on the PR; no workflow reads it, and since a fragment is not
required it waives nothing.

### At sprint close: assemble

When a wave completes, **the person or agent declaring the sprint done** runs:

```sh
python3 .github/scripts/assemble-changelog.py
```

Every fragment naming the same sprint is merged into one `CHANGELOG.md` entry —
issues, key decisions and breaking changes gathered under a single heading —
and the fragments are deleted. Land it as its own `chore/changelog-<sprint>`
PR. By convention it is the only PR that modifies `CHANGELOG.md` — a convention
this repo keeps by agreement, not by a guard: no workflow inspects who edits
`CHANGELOG.md`, so an unrelated PR that touches it will not be stopped. Label a
PR that only truncates stale entries `changelog-assembly`, again as a visible,
attributable convention rather than something CI reads.

Truncate stale entries in that same PR — `CHANGELOG.md` describes the current
system, not an archive. Doing it at assembly makes truncation the point of the
commit rather than a passenger on an unrelated one, which is how ~752 lines of
history nearly came back once.

Assembly is not left to good intentions: the same `--check` step warns, without
failing, once `changelog.d/` holds 20 fragments or more. Refusing everyone's PR
because a sprint has not been closed would punish the wrong person, so it is
loud rather than fatal. `promote.yml` has no changelog check, so that warning is
the only thing standing between a backlog and a directory that has quietly
become the changelog — it once reached 88. `platform/CHANGELOG.md` is frozen
history by convention; nothing assembles into it, and no guard enforces that.

## Notes for agents specifically

- The human in the loop is the reviewer and merger. Announce PRs and stop;
  do not poll-and-merge.
- Claim an issue with the label swap before building (§3): pull only
  `ready-for-agent`, then remove it and add `assigned-to-agent`. Never work
  an unclaimed or already-claimed ticket.
- One issue, one branch, one PR. If review or a merge invalidates your
  base, rebase onto fresh `main` — never merge main into the feature
  branch.
- When a bug is found mid-task, diagnose from **logs and stored data
  first**, then fix on its own branch with a regression test.
- Verify edits after making them (a replace that "succeeded" without
  matching has bitten before); never fabricate suite results — report the
  real counts.
