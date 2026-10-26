# 09 — Working method

How a change goes from request to production. The method is the same whether a person or an AI coding agent does the work. Agents just make the steps cheaper, so there is no excuse to skip them.

```
1 EXPLORE → 2 PLAN (+ review) → [go] → 3 IMPLEMENT → 4 VERIFY → 5 REPORT
                                                     4a lint + static checks (always)
                                                     4b does it work? (runtime, real browser)
                                                     4c security review of the final diff
                                                                └ closing question: tests?
```

## 1. Explore — never assume

- Before planning, check whether what was asked **already exists**. If it does, say so in one or two lines and stop.
- Read the code. Don't guess from file names. Anything not verified is an assumption, and it gets labelled as one.
- On a live WordPress site, much of the truth lives in the **database or the dashboard**, not in the repo: Elementor templates, ACF field groups, form IDs, cache exclusions, options. Ask, or read it with WP-CLI. Don't assert it.
- SSH to staging or production is **read-only** during exploration.

## 2. Plan — then wait for "go"

A plan has eight parts:

1. Goal.
2. What was verified, with `file:line`.
3. Assumptions, marked `[verified]` or `[NOT VERIFIED]`.
4. Files to touch.
5. Steps.
6. Tests that *would* be written.
7. Docs to update.
8. Questions, blocking and non-blocking.

An adversarial reviewer reads the plan **before** the owner does. Its job is to find why the plan fails, not to approve it. Its verdict is attached to the plan.

Then nothing happens until the owner says **"go"**. Feedback changes the plan, not the code.

The only exception is a change declared trivial *before* touching anything: one file, fewer than 10 lines, no business logic.

## 3. Implement — only what was asked

- **Scope is sacred.** Dead code, a neighbouring bug or a broken convention found on the way gets *mentioned*, not fixed, even if it's a one-liner. If it blocks the task, say so and wait.
- Code goes in `src/`. Never in the generated `wordpress/` directory, and never in host-managed folders.
- **Conventions that keep WordPress code reviewable:**
  - a thin plugin bootstrap that loads `includes/class-*.php`;
  - `functions.php` as a list of `require inc/*.php`, one self-contained module per file;
  - named callables for hooks, not anonymous closures, so they can be unhooked and found;
  - one prefix per project for functions, options and constants;
  - one place that enqueues assets, with a comment per asset;
  - data migrations in their own plugin that can be deactivated afterwards.
- No tests are written or run during implementation. That belongs to the verify phase.

## 4. Verify — in this order

**4a. Lint and static checks**: always, without asking. `php -l`, the coding standard, JSON and YAML validity.

**4b. Does it work?** Check it at runtime, not by reading the code:
- load the page;
- click through the flow in a real browser, logged in where needed;
- read the console and the network tab;
- compare before and after numbers when the change is about performance.

**4c. Security review** of the final diff:
- authorization;
- data isolation;
- escaping and sanitizing;
- secrets;
- what else breaks if this goes wrong.

If 4b or 4c change code, go back to 4a.

**Existing tests that turn red are never edited to pass.** They get reported, and the owner decides.

## 5. Report

Never just "done". A report says:
- which files changed;
- how it was verified to work, with the evidence: numbers, status codes, what was clicked;
- what the security review covered;
- what was skipped and why;
- what is still pending.

If something failed, the report says so and shows the output. It closes by asking whether tests should be written now.

## Ask before

These are never done without explicit approval, every time:
- pushing;
- deploying, or working on a branch that auto-deploys;
- rewriting history;
- writing to a database or running migrations;
- deleting files;
- adding or upgrading dependencies;
- touching `.env` or credentials.

Credentials never go through chat or logs. When a secret has to reach a server, the owner runs a script that reads it locally.

## Agent roles

With an AI coding agent, the work is split into roles. Each role is a narrowly scoped sub-agent with only the tools it needs.

| Role | Phase | What it does |
|---|---|---|
| **Analyst** | Explore | Works out what was *actually* asked, and how comparable systems solve it. |
| **Code scout** | Explore | Surveys the codebase: does it already exist, what is the established pattern, what can be reused, who consumes what would change. |
| **Plan reviewer** | Plan | Adversarial. Tries to break the plan before anyone sees it. |
| **Implementer** | Implement | Writes the code from a precise brief: the goal, the exact files, the sibling file to copy, the decisions already made, and what not to touch. A cheaper model is fine here, because the thinking was done in the plan. |
| **Verifier** | Verify 4b | Proves the change works at runtime, driving the real UI in the browser. |
| **Security auditor** | Verify 4c | Reviews the final diff for authorization, data isolation, credentials, injection and blast radius. |
| **Log reader** | Any | Reads long CI logs, test output or dumps, and returns only the conclusion and the few lines that prove it. |
| **Tester** | After the report | Writes tests, but only once the owner has approved them. |
| **Strong QA** | On demand | A full acceptance pass with a test matrix, negative checks and screenshots. It runs only when explicitly requested. |

The main session explores, plans, writes the implementer's brief, reviews the diff and decides. If the implementer's quality drops, the work goes back to the main session, and every diff that had to be redone is noted in the report.

## Agent instructions live in the repo

- Each repository has an `AGENTS.md` with its rules: what not to touch, where things live, the conventions above, and what has to be asked. Tool-specific files such as `CLAUDE.md` only import it, so every agent tool reads the same rules.
- When several sites share the same rules, the instructions are generated from one shared toolkit. Fixes go into the toolkit, never into a copy.
- Guard hooks block the dangerous actions (push, DB writes, `.env`) mechanically. The hook is the safety net, not the rule.
- Scratch work goes in a git-ignored folder.

## Git

- One branch per ticket.
- Commit messages say *why*, in English.
- Commits carry only the human author: no AI attribution lines.
- `main` is protected against force pushes and deletion.
