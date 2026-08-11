---
schema: ai6.ticket.v1
id: AI6-099
title: "Detailprofil prüfen"
status: todo
depends_on: [AI6-001]
kind: feature
milestone: M1
risk: medium
files:
  - "app/AI6/Tickets/"
spec_refs:
  - "docs/AI6_IMPLEMENTATION_PLAN.md — TKT-002"
---

# AI6-099 — Detailprofil prüfen

## Goal

Das Detailprofil vollständig prüfen.

Ein Hinweis auf Plan §5.2 ist Prosa und keine zusätzliche Referenz.

## Context

Der Test verwendet ausschließlich lokale Fixturedaten.

## Tasks

1. Den Parser prüfen.

## Acceptance Criteria

- [ ] **AC-01** Das Dokument wird akzeptiert.

## Test Cases

- **TC-01** Der Unit-Test prüft das Dokument.

## AC Coverage

| AC | Evidence |
|---|---|
| AC-01 | TC-01 |

## Initial Scope and Sensitive Paths

**Expected initial scope:**

- `app/AI6/Tickets/` — existing

**Sensitive paths:**

None.

## Do Not Change

None.

## Out of Scope

- Schreibende Ticketänderungen.

## Manual and External Gates

None.

## Review Focus

- Deterministische Validierung.

## Notes

- Definition of Done: `docs/AI6_IMPLEMENTATION_PLAN.md` §12.2.
- Ticket status, approval and run metadata are owned by AI6. The implementation agent never changes them.
- Necessary deviations are reported as `needs_human` or as a scope/contract request, never applied silently.
