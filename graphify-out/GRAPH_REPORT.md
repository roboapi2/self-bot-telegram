# Graph Report - self  (2026-10-10)

## Corpus Check
- Corpus is ~28,987 words - fits in a single context window. You may not need a graph.

## Summary
- 341 nodes · 836 edges · 26 communities (6 shown, 20 thin omitted)
- Extraction: 93% EXTRACTED · 7% INFERRED · 0% AMBIGUOUS · INFERRED: 58 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Account lifecycle and security
- Persian panel interactions
- Application flow
- Telegram event processing
- Composer
- Unit
- Install
- Run
- Backup
- Install-Vps
- Deployment and account usage
- Control
- Update
- Deployment and account usage

## God Nodes (most connected - your core abstractions)
1. `UserBot` - 96 edges
2. `el()` - 24 edges
3. `account_paths()` - 22 edges
4. `renderAccount()` - 19 edges
5. `api()` - 18 edges
6. `renderDashboard()` - 18 edges
7. `flash()` - 15 edges
8. `icon()` - 14 edges
9. `openQr()` - 12 edges
10. `read_json_file()` - 12 edges

## Surprising Connections (you probably didn't know these)
- `make_api()` --calls--> `cfg()`  [INFERRED]
  ../madeline_tools.php → ../lib.php
- `fetch_chats()` --calls--> `json_write_atomic()`  [INFERRED]
  ../madeline_tools.php → ../lib.php
- `write_qr_state()` --calls--> `json_write_atomic()`  [INFERRED]
  ../qr_worker.php → ../lib.php
- `fetch_chats()` --calls--> `read_json_file()`  [INFERRED]
  ../madeline_tools.php → ../lib.php
- `{closure#3}()` --calls--> `read_json_file()`  [INFERRED]
  ../tests/unit.php → ../lib.php

## Import Cycles
- None detected.

## Communities (26 total, 20 thin omitted)

### Community 0 - "Account lifecycle and security"
Cohesion: 0.06
Nodes (60): purge_account_dir(), req_account(), account_dir(), account_paths(), account_status(), accounts_dir(), accounts_file(), base_dir() (+52 more)

### Community 1 - "Persian panel interactions"
Cohesion: 0.15
Nodes (49): accountCard(), accounts, act(), api(), archCard(), chatState, closeModal(), confirmDialog() (+41 more)

### Community 2 - "Application flow"
Cohesion: 0.06
Nodes (17): analyze_media(), encode_peer(), ensure_chat_media(), fetch_chats(), fetch_history(), find_value(), make_api(), message_media_kind() (+9 more)

### Community 9 - "Composer"
Cohesion: 0.17
Nodes (11): description, minimum-stability, name, prefer-stable, require, amphp/file, danog/madelineproto, ext-json (+3 more)

### Community 10 - "Unit"
Cohesion: 0.31
Nodes (6): bot_json_snapshot(), persist_bot_json(), {closure#1}(), {closure#2}(), {closure#3}(), remove_fixture()

### Community 16 - "Deployment and account usage"
Cohesion: 0.67
Nodes (3): Per-account state storage, QR login and two-factor authentication, Persian administration panel

## Knowledge Gaps
- **4 isolated node(s):** `PHP_CLI_SERVER_WORKERS`, `Per-account state storage`, `QR login and two-factor authentication`, `Persistent service lifecycle`
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 89 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **20 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `UserBot` connect `Telegram event processing` to `Application flow`, `Telegram event processing`, `Telegram event processing`, `Telegram event processing`, `Telegram event processing`, `Telegram event processing`?**
  _High betweenness centrality (0.245) - this node is a cross-community bridge._
- **Are the 7 inferred relationships involving `account_paths()` (e.g. with `fetch_chats()` and `fetch_history()`) actually correct?**
  _`account_paths()` has 7 INFERRED edges - model-reasoned connections that need verification._
- **What connects `PHP_CLI_SERVER_WORKERS`, `Per-account state storage`, `QR login and two-factor authentication` to the rest of the system?**
  _4 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Account lifecycle and security` be split into smaller, more focused modules?**
  _Cohesion score 0.061668289516390785 - nodes in this community are weakly interconnected._
- **Why does `read_json_file()` connect `Account lifecycle and security` to `Application flow`, `Unit`, `Telegram event processing`?**
  _High betweenness centrality (0.097) - this node is a cross-community bridge._
- **Should `Persian panel interactions` be split into smaller, more focused modules?**
  _Cohesion score 0.14745098039215687 - nodes in this community are weakly interconnected._
- **Why does `cfg()` connect `Account lifecycle and security` to `Application flow`, `Telegram event processing`?**
  _High betweenness centrality (0.067) - this node is a cross-community bridge._

## Scope and limitations

First-party code and documentation only. Vendor, real configuration, account records, sessions, logs, backups, .claude, and audit preservation hashes were excluded before reading content. PHP structural extraction and inline semantic documentation extraction used no external LLM API. Token counters record API billing (zero); host-session reasoning is not measured. The graph is a navigation aid; it does not prove operational correctness or replace the security/deployment audit.
