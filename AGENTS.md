# CourtFlow AI

Read this file first.

## Project Type

CourtFlow AI is NOT a chatbot.

CourtFlow AI is:

- Procedural Workflow Platform
- Court Navigation Platform
- Intake Automation Platform
- Forms Automation Platform
- Filing Package Generator

## Core Rule

AI NEVER determines legal workflows.

Rules Engine determines:

- Court
- Workflow
- Required Forms
- Next Steps

AI only:

- Explains
- Collects information
- Summarizes
- Assists user

## Project Structure

Main business code:

public/wp-content/plugins/prose-core

public/wp-content/themes/prose-app

## Ignore

public/wp-admin
public/wp-includes
logs
sql
vendor
node_modules

## Architecture Principles

- Database-driven workflows
- Deterministic rules
- Modular architecture
- Production-ready SaaS patterns

Never hardcode workflow logic.

## Architecture documentation

| Document | Path |
|----------|------|
| Platform architecture | `docs/architecture/platform-architecture.md` |
| Guiding principles | `docs/architecture/guiding-principles.md` |
| Architecture decisions (ADR) | `docs/adr/` |
| RFC process | `docs/rfc/README.md` |
| Implementation plans | `docs/plans/README.md` |
| Reference specs | `docs/reference/README.md` |