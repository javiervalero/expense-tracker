# CLAUDE.md

## Project
Expense tracker web application. Requirements are in `docs/BRIEF.md`.

## Constraints (fixed by the project owner)
- Modern web application with a clear separation between front-end and back-end.
- Front-end: Vue.
- Back-end: vanilla PHP, no framework. This is a standing non-functional requirement.
- Deployed on AWS; AWS-specific infrastructure is welcome in the architecture.
- Development process: use ECC's workflow (its commands, agents and skills) wherever it applies.
- Infrastructure is defined as code.

Everything else (architecture, API design, data model, libraries, testing strategy, project layout, security approach, CI/CD, conventions, how to organize your own plans and notes) is your decision. Record significant decisions and their rationale in the repo.

## Guardrails
- Ask before any destructive or costly action (deleting files, dropping databases, force pushes, deploying to or destroying AWS resources).
- Never commit or print secrets.
- Keep the owner informed of what you are doing and why, in plain language, because the owner is learning from the process.
