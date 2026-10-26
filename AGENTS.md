# AGENTS.md — Development Guidelines & Permanent Rules

## 1. Project Overview
* **Project**: Local-first WhatsApp AI sales CRM (Desktop App, TypeScript).
* **Core Philosophy**: Absolute data privacy, deterministic transaction safety, and resilient local-first operations.

---

## 2. Technology Stack & Justification

| Layer | Technology | Purpose & Rationale |
| :--- | :--- | :--- |
| **Desktop Shell** | **Electron** *(TypeScript)* | Required for native ecosystem compatibility with headless Chromium instances (`whatsapp-web.js` / Puppeteer), local native Node.js addons (`better-sqlite3`, `keytar`), and cross-platform desktop UI. *(Tauri requires a separate Node runtime sidecar for WhatsApp browser emulation, adding IPC latency and bundle complexity).* |
| **Local API Server** | **Fastify** | High-performance, low-overhead local HTTP & WebSocket server for background services, local IPC, and webhooks. |
| **Local Storage** | **better-sqlite3** with **SQLCipher** | Zero-network, strictly encrypted at-rest local database storing chats, contacts, tags, and settings. Synchronous, high-throughput execution. |
| **State & Flow Control** | **XState (v5)** | Deterministic finite state machines managing sales pipelines, human handover states, order workflows, and chat locks. |
| **Testing** | **Vitest** | Ultra-fast unit, state-machine, and integration testing with native TypeScript and ESM support. |
| **Validation** | **Zod** | Runtime schema enforcement and strict input/output validation for all LLM outputs, API payloads, and config values. |

---

## 3. Permanent Hard Rules

Every developer and AI agent working on this codebase must strictly adhere to these 10 non-negotiable rules:

1. **No Cloud Database**:
   * All customer data, chat histories, contact tags, and local CRM configurations **must** reside in the locally encrypted SQLite database (`SQLCipher`).
   * Store catalog data (products, inventory, order statuses) is fetched live from the external store API (e.g., Shopify, WooCommerce). Only short-lived in-memory caching (max TTL 60–300s) is allowed.

2. **Deterministic Action Execution (LLM Never Modifies Orders)**:
   * The LLM **never** creates, cancels, edits, or commits orders or discounts.
   * All business logic and state transitions are exclusively owned and driven by **XState state machines**.
   * The LLM has strictly two bounded roles:
     1. Extracting customer intent and entities as strictly typed, Zod-validated JSON.
     2. Phrasing customer replies strictly from facts and context passed directly into its prompt.

3. **Zero Hallucination on Store Realities**:
   * The LLM must **never** extrapolate, estimate, or hallucinate prices, stock levels, delivery fees, delivery times, or store policies.
   * Every quote, stock confirmation, or policy statement in an outgoing message must originate from verified store data explicitly injected into the prompt context.

4. **Audience Filtering & Tag Protection**:
   * Bot automated responses are restricted to:
     * Contacts tagged `Customer`
     * Untagged / unknown inbound numbers
   * If a contact is tagged `Family`, `Friends`, `Team`, or `Blocked`, bot automation is **permanently disabled (`bot-off`)**. Inbound messages from these tags are stored locally for CRM viewing only.

5. **Kill Switch & Pause Enforcement**:
   * Outgoing dispatch pipelines must verify:
     1. **Global Kill Switch** (emergency desktop toggle).
     2. **Per-Chat Pause Status** (temporary human takeover / manual conversation hold).
   * If either switch is triggered, outgoing automation must halt immediately and yield to manual input.

6. **Anti-Spam & Organic Cadence**:
   * **No bulk broadcasts** or unprompted promotional spamming.
   * Outgoing messages must pass through a jitter queue implementing human-like typing delays (e.g., dynamic delays scaled by message length: 25–45ms per character + randomized 1.5–3.5s pause) and realistic rate limits.

7. **Zero Plaintext Credentials in Storage**:
   * API keys, LLM tokens, store secrets, and database encryption keys must be kept in the **OS Keychain** (via `keytar` / safeStorage).
   * Secrets must never be stored in SQLite tables, configuration JSON files, debug logs, or committed to version control.

8. **Strict Architectural Decoupling (Interface-First)**:
   * Core domain modules must interact solely through standard TypeScript interfaces:
     * `WhatsAppProvider` (e.g., Baileys, WhatsApp-Web.js, Cloud API wrapper)
     * `StoreConnector` (e.g., Shopify, WooCommerce, Custom REST)
     * `LLMProvider` (e.g., Gemini, OpenAI, Claude, Local Ollama)
     * `Storage` (e.g., SQLite via SQLCipher repositories)

9. **Language & Routing Protocol**:
   * **English**: Processed through deterministic rules, intent regex, and pre-compiled response templates whenever possible.
   * **Roman Urdu & Urdu Script**: Routed through the LLM Intent Router for phonetic and dialect entity extraction, normalized into structured Zod schemas.

10. **Test-Driven Delivery**:
    * Every new feature, state transition, and tool parser must ship with corresponding Vitest tests.
    * No pull request or task is marked complete without clean runs of:
      ```bash
      npm run lint
      npm run test
      ```

---

## 4. Folder Structure Conventions

```
desktop-app/
├── src/
│   ├── main/                       # Electron main process entry & native bindings
│   │   ├── index.ts
│   │   ├── keychain.ts             # OS Keychain (keytar/safeStorage) wrapper
│   │   └── menu.ts
│   ├── preload/                    # Context bridge & secure IPC isolation
│   │   └── index.ts
│   ├── renderer/                   # Desktop UI (React / Vue / Svelte / Vanilla TS)
│   ├── server/                     # Local Fastify backend service
│   │   ├── app.ts
│   │   ├── routes/
│   │   └── plugins/
│   ├── core/                       # Pure business domain logic (framework-agnostic)
│   │   ├── interfaces/             # Core contracts
│   │   │   ├── whatsapp-provider.interface.ts
│   │   │   ├── store-connector.interface.ts
│   │   │   ├── llm-provider.interface.ts
│   │   │   └── storage.interface.ts
│   │   ├── machines/               # XState state machines & actors
│   │   │   ├── sales-conversation.machine.ts
│   │   │   └── order-guard.machine.ts
│   │   ├── llm/                    # Prompt builders, routers, Zod entity schemas
│   │   │   ├── intent-router.ts
│   │   │   ├── schemas/
│   │   │   └── prompts/
│   │   └── services/               # Outbox rate-limiter, typing delays, filters
│   │       ├── audience-filter.ts
│   │       ├── rate-limiter.ts
│   │       └── kill-switch.ts
│   └── infrastructure/             # Concrete implementations
│       ├── storage/                # SQLCipher SQLite repositories & migrations
│       │   ├── connection.ts
│       │   ├── schema.sql
│       │   └── repositories/
│       ├── whatsapp/               # WhatsApp provider adapters
│       ├── store/                  # Shopify / WooCommerce connectors
│       └── llm/                    # Gemini / OpenAI adapter implementations
└── tests/
    ├── unit/
    ├── machines/                   # XState transition & actor tests
    └── mocks/                      # Mock store, provider, and storage stubs
```

---

## 5. Naming Conventions

* **Files & Directories**: `kebab-case` (e.g., `whatsapp-provider.interface.ts`, `sales-conversation.machine.ts`).
* **Interfaces & Types**: `PascalCase` prefixed with descriptive semantic roles (e.g., `WhatsAppProvider`, `StoreConnector`, `CustomerEntity`).
* **Zod Schemas**: `PascalCase` suffixed with `Schema` (e.g., `IntentExtractionSchema`, `CustomerProfileSchema`).
* **State Machines**: `camelCase` suffixed with `Machine` (e.g., `salesConversationMachine`, `checkoutGuardMachine`).
* **Functions & Methods**: `camelCase` starting with action verbs (e.g., `extractIntentFromMessage()`, `verifyKillSwitch()`, `maskPhoneNumber()`).
* **Constants & Environment Keys**: `UPPER_SNAKE_CASE` (e.g., `MAX_TYPING_DELAY_MS`, `DEFAULT_CACHE_TTL_SEC`).

---

## 6. Error Handling Standards

* **Domain Errors**: Use strongly typed custom error classes extending a base `AppError`:
  ```typescript
  export class AppError extends Error {
    constructor(
      public readonly code: string,
      message: string,
      public readonly context?: Record<string, unknown>
    ) {
      super(message);
      this.name = this.constructor.name;
    }
  }
  ```
* **Graceful Degradation**: If the LLM provider fails, times out, or returns invalid JSON schema, fallback to:
  1. Triggering an alert for human agent takeover.
  2. Transitioning the conversation state machine to `AWAITING_MANUAL_INTERVENTION`.
  3. Never sending raw error traces to the customer.
* **Database Transactions**: Any multi-step write (e.g., storing a message and updating contact state) must run inside an explicit SQLite transaction (`db.transaction(...)`).

---

## 7. Logging & Privacy Standards

* **Structured JSON Logging**: Use Fastify's built-in Pino logger or a dedicated structured logger with timestamps and log levels (`trace`, `debug`, `info`, `warn`, `error`).
* **PII & Phone Number Masking (Mandatory)**:
  * Customer phone numbers **must never** appear in plaintext in logs, debug dumps, or console output.
  * Apply masking utility to all logged identifiers:
    * Format: Keep country code and last 3 digits: `+92-300-***-1234` or `E164: +92300*****12`.
  * Strip message bodies containing credentials, payment details, or personal addresses from production debug logs.
* **No Secret Spillage**:
  * Logging request headers, authorization tokens, or raw keychain lookups is strictly prohibited.
